<?php
// Analisa video untuk rekomendasi clip (dipanggil dari worker.php untuk job kind=analyze)

require_once __DIR__ . '/gemini.php';

define('ANALYSES_DIR', STORAGE_DIR . '/analyses');

function fmt_ts(float $s): string
{
    $s = max(0, $s);
    $h = intdiv((int) $s, 3600);
    $m = intdiv((int) $s % 3600, 60);
    $sec = $s - $h * 3600 - $m * 60;
    return ($h ? $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : $m) . ':' . str_pad(number_format($sec, 1), 4, '0', STR_PAD_LEFT);
}

/** Pilih track subtitle: manual (bahasa asli) untuk teks, otomatis (-orig) untuk timing per kata. */
function pick_sub_tracks(array $info): array
{
    $manual = array_diff(array_keys($info['subtitles'] ?? []), ['live_chat']);
    $auto = array_keys($info['automatic_captions'] ?? []);
    $lang = (string) ($info['language'] ?? '');

    $autoKey = null;
    foreach ($auto as $k) {
        if (str_ends_with($k, '-orig')) {
            $autoKey = $k;
            break;
        }
    }
    if ($lang === '' && $autoKey) {
        $lang = substr($autoKey, 0, -5);
    }
    if (!$autoKey && $lang !== '' && in_array($lang, $auto, true)) {
        $autoKey = $lang;
    }

    $manualKey = null;
    if ($lang !== '') {
        $base = explode('-', $lang)[0];
        foreach ($manual as $k) {
            if ($k === $lang || explode('-', $k)[0] === $base) {
                $manualKey = $k;
                break;
            }
        }
    } elseif (count($manual) === 1) {
        $manualKey = reset($manual);
    }
    return [$manualKey, $autoKey, $lang];
}

/** Ubah file json3 YouTube jadi daftar unit [start, end, text] (per kata untuk auto, per baris untuk manual). */
function parse_json3(string $file): array
{
    $data = json_decode((string) @file_get_contents($file), true);
    $units = [];
    foreach ($data['events'] ?? [] as $ev) {
        if (empty($ev['segs'])) {
            continue;
        }
        $base = ($ev['tStartMs'] ?? 0) / 1000;
        $evEnd = $base + ($ev['dDurationMs'] ?? 0) / 1000;
        $segs = $ev['segs'];
        $wordLevel = count($segs) > 1 || isset($segs[0]['tOffsetMs']);
        if (!$wordLevel) {
            $text = trim(preg_replace('/\s+/u', ' ', $segs[0]['utf8'] ?? ''));
            if ($text !== '') {
                $units[] = [$base, $evEnd, $text];
            }
            continue;
        }
        foreach ($segs as $i => $seg) {
            $text = trim($seg['utf8'] ?? '');
            if ($text === '') {
                continue;
            }
            $start = $base + ($seg['tOffsetMs'] ?? 0) / 1000;
            $next = isset($segs[$i + 1]) ? $base + ($segs[$i + 1]['tOffsetMs'] ?? 0) / 1000 : $evEnd;
            $units[] = [$start, max($start + 0.05, $next), $text];
        }
    }
    usort($units, fn($a, $b) => $a[0] <=> $b[0]);
    // Akhir unit tidak boleh melewati awal unit berikutnya
    for ($i = 0, $n = count($units); $i < $n - 1; $i++) {
        $units[$i][1] = min($units[$i][1], max($units[$i][0] + 0.05, $units[$i + 1][0]));
    }
    return $units;
}

/** Gabungkan unit menjadi baris transkrip bertimestamp untuk prompt. */
function transcript_lines(array $units): array
{
    $lines = [];
    $cur = null;
    foreach ($units as $u) {
        $gap = $cur ? $u[0] - $cur['end'] : 0;
        if ($cur && ($gap > 0.8 || $u[1] - $cur['start'] > 9 || $cur['words'] >= 20
                || (preg_match('/[.!?…]["”]?$/u', $cur['text']) && $cur['words'] >= 4))) {
            $lines[] = $cur;
            $cur = null;
        }
        if (!$cur) {
            $cur = ['start' => $u[0], 'end' => $u[1], 'text' => $u[2], 'words' => 1];
        } else {
            $cur['end'] = $u[1];
            $cur['text'] .= ' ' . $u[2];
            $cur['words'] += 1 + substr_count($u[2], ' ');
        }
    }
    if ($cur) {
        $lines[] = $cur;
    }
    return $lines;
}

/** Titik potong kandidat: [waktu, panjang jeda] dari unit transkrip. */
function boundaries_from_units(array $units): array
{
    $starts = $ends = [];
    $n = count($units);
    foreach ($units as $i => $u) {
        $starts[] = [$u[0], $i > 0 ? max(0, $u[0] - $units[$i - 1][1]) : 1.0];
        $ends[] = [$u[1], $i < $n - 1 ? max(0, $units[$i + 1][0] - $u[1]) : 1.0];
    }
    return [$starts, $ends];
}

/** Titik potong kandidat dari deteksi hening (mode audio). */
function boundaries_from_silence(string $audio): array
{
    $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -i ' . escapeshellarg($audio)
        . ' -af silencedetect=noise=-32dB:d=0.25 -f null - 2>&1';
    $out = (string) shell_exec($cmd);
    $starts = $ends = [];
    preg_match_all('/silence_start: ([\d.]+)/', $out, $ss);
    preg_match_all('/silence_end: ([\d.]+) \| silence_duration: ([\d.]+)/', $out, $se);
    foreach ($ss[1] as $i => $t) {
        $dur = (float) ($se[2][$i] ?? 1);
        $ends[] = [(float) $t, $dur];                 // bicara berhenti
    }
    foreach ($se[1] as $i => $t) {
        $starts[] = [(float) $t, (float) $se[2][$i]]; // bicara mulai lagi
    }
    return [$starts, $ends];
}

/** Geser waktu ke titik potong terdekat, utamakan yang ada jedanya (awal/akhir kalimat). */
function snap(float $t, array $cands, float $window = 2.0): array
{
    $best = null;
    $bestScore = INF;
    foreach ($cands as [$time, $gap]) {
        $d = abs($time - $t);
        if ($d > $window) {
            continue;
        }
        $score = $d - min($gap, 1.0) * 1.2;
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = [$time, $gap];
        }
    }
    return $best ?? [$t, 0];
}

/** Kata pembuka yang menandakan clip butuh konteks dari kalimat sebelumnya (kata penghubung/rujukan). */
const CONTEXT_OPENERS = ['jadi', 'nah', 'terus', 'trus', 'tros', 'makanya', 'karena', 'soalnya', 'tapi', 'tetapi', 'dan',
    'atau', 'dia', 'mereka', 'tadi', 'juga', 'yang', 'bahwa', 'sehingga', 'lalu', 'kemudian', 'abis', 'habis',
    'padahal', 'gitu', 'begitu', 'kayak', 'kaya', 'sama', 'itulah', 'pokoknya', 'and', 'so', 'but', 'because',
    'cause', "'cause", 'which', 'then', 'also', 'or', 'he', 'she', 'they', 'it', "it's", "that's", 'that', 'like'];

/**
 * Mundurkan awal clip ke awal kalimat sebelumnya bila clip dimulai di tengah alur bicara
 * (jeda sebelum awal clip sangat pendek) atau diawali kata penghubung/rujukan.
 * $starts = titik awal bicara [waktu, panjang jeda sebelumnya].
 */
function context_start(float $t, float $gap, string $firstText, array $starts, float $maxBack = 8.0): float
{
    $first = mb_strtolower(preg_replace('/^[^\p{L}\']+/u', '', $firstText));
    $word = preg_split('/[^\p{L}\']+/u', $first, 2)[0] ?? '';
    if ($gap >= 0.25 && !in_array($word, CONTEXT_OPENERS, true)) {
        return $t;
    }
    $best = null;
    $fallback = null;
    foreach ($starts as [$time, $g]) {
        if ($time < $t - $maxBack || $time > $t - 0.5) {
            continue;
        }
        // Jeda jelas = batas kalimat; ambil yang paling dekat dengan awal clip
        if ($g >= 0.6 && ($best === null || $time > $best)) {
            $best = $time;
        }
        if ($g >= 0.35 && ($fallback === null || $g > $fallback[1])) {
            $fallback = [$time, $g];
        }
    }
    return $best ?? $fallback[0] ?? $t;
}

function heatmap_score(array $heatmap, float $start, float $end): ?float
{
    if (!$heatmap) {
        return null;
    }
    $sum = $w = 0;
    foreach ($heatmap as $h) {
        $overlap = min($end, $h['end_time']) - max($start, $h['start_time']);
        if ($overlap > 0) {
            $sum += $h['value'] * $overlap;
            $w += $overlap;
        }
    }
    return $w > 0 ? $sum / $w : null;
}

/**
 * Bahasa judul, hook, caption & hashtag: 'id' | 'en' | 'auto' (bahasa video belum diketahui, AI yang menentukan).
 * Pilihan user 'auto' mengikuti bahasa video: video berbahasa Inggris -> 'en', bahasa lain -> 'id'.
 */
function resolve_out_lang(string $pick, string $videoLang): string
{
    if (in_array($pick, ['id', 'en'], true)) {
        return $pick;
    }
    $v = strtolower(explode('-', $videoLang)[0]);
    return $v === '' ? 'auto' : ($v === 'en' ? 'en' : 'id');
}

function clip_schema(string $style = 'podcast', string $outLang = 'id', array $concepts = [], ?array $brand = null): array
{
    // Bahasa field yang tampil ke penonton (judul, hook layar, caption) mengikuti $outLang
    $L = ['id' => 'Bahasa Indonesia', 'en' => 'Bahasa Inggris (English)', 'auto' => 'bahasa sesuai aturan BAHASA'][$outLang] ?? 'Bahasa Indonesia';
    $int = ['type' => 'INTEGER'];
    $gaming = $style === 'gaming';
    $categories = $gaming
        ? ['Momen lucu', 'Momen menegangkan', 'Momen ngakak']
        : ['Motivasi', 'Self-development', 'Edukasi', 'Kata bermakna', 'Mindset', 'Kisah inspiratif', 'Momen lucu', 'Statement viral', 'Emosional', 'Lainnya'];
    return [
        'type' => 'OBJECT',
        'properties' => [
            'video_summary' => ['type' => 'STRING', 'description' => 'Ringkasan isi video 2-3 kalimat, Bahasa Indonesia'],
            'niche_fit' => ['type' => 'STRING', 'description' => $gaming
                ? 'Seberapa cocok video ini untuk clip highlight streaming game, 1 kalimat'
                : 'Seberapa cocok video ini untuk niche clipper motivasi/self-development/edukasi, 1 kalimat'],
            'clips' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'start_seconds' => ['type' => 'NUMBER'],
                        'end_seconds' => ['type' => 'NUMBER'],
                        'title' => ['type' => 'STRING', 'description' => "Judul clip yang menarik, $L, maks 70 karakter"],
                        'hook' => ['type' => 'STRING', 'description' => 'Kalimat paling memancing di awal clip (dalam 5 detik pertama), persis seperti di transkrip/audio'],
                        'quote' => ['type' => 'STRING', 'description' => 'Kalimat paling kuat/quotable di clip, persis seperti diucapkan'],
                        'quote_translation' => ['type' => 'STRING', 'description' => $outLang === 'en'
                            ? 'String kosong (konten ditulis dalam bahasa Inggris)'
                            : 'Terjemahan quote ke Bahasa Indonesia jika video bukan Bahasa Indonesia dan output berbahasa Indonesia, selain itu string kosong'],
                        'transcript' => ['type' => 'STRING', 'description' => 'Isi ucapan lengkap di clip (hanya untuk mode audio, selain itu string kosong)'],
                        'category' => ['type' => 'STRING', 'enum' => $categories],
                    ] + ($concepts ? [
                        'concept' => ['type' => 'STRING', 'enum' => array_merge(array_map(fn($k) => CLIP_CONCEPTS[$style === 'gaming' ? 'gaming' : 'podcast'][$k][0], $concepts), ['Lainnya']),
                            'description' => 'Konsep clip pilihan user yang paling cocok dengan momen ini'],
                    ] : []) + ($brand ? [
                        'brand_fit' => ['type' => 'STRING', 'enum' => ['hard', 'soft', 'none'],
                            'description' => 'hard = brand/produknya disebut langsung, soft = membahas topik terkait brand, none = tidak terkait'],
                        'brand_angle' => ['type' => 'STRING', 'description' => 'Kenapa clip ini nyambung ke brand, Bahasa Indonesia, 1 kalimat (string kosong kalau brand_fit none)'],
                    ] : []) + [
                        'why' => ['type' => 'STRING', 'description' => 'Alasan clip ini layak diposting, Bahasa Indonesia, 1-2 kalimat'],
                        'on_screen_text' => ['type' => 'STRING', 'description' => "Teks hook untuk overlay di awal video, $L, maks 8 kata"],
                        'caption' => ['type' => 'STRING', 'description' => "Caption postingan, $L, maks 150 karakter, TANPA hashtag (hashtag hanya di field hashtags)"],
                        'hashtags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'score_hook' => $int,
                        'score_insight' => $int,
                        'score_standalone' => $int,
                        'score_emotion' => $int,
                        'score_quotability' => $int,
                        'overall_score' => $int,
                    ],
                    'required' => [...($concepts ? ['concept'] : []), ...($brand ? ['brand_fit', 'brand_angle'] : []), 'start_seconds', 'end_seconds', 'title', 'hook', 'quote', 'category', 'why',
                        'on_screen_text', 'caption', 'hashtags', 'score_hook', 'score_insight', 'score_standalone',
                        'score_emotion', 'score_quotability', 'overall_score'],
                ],
            ],
        ],
        'required' => ['video_summary', 'niche_fit', 'clips'],
    ];
}

/** Aturan prompt untuk clip campaign brand: momen yang menyebut brand (hard) atau membahas topik terkait (soft). */
function brand_rule(array $b, int $maxClips): string
{
    $cat = BRAND_CATEGORIES[$b['category']][0] ?? 'Lainnya';
    $topics = $b['topics'] ? implode(', ', $b['topics']) : '(tidak ditentukan — simpulkan sendiri dari brand & kategorinya)';
    $modes = implode("\n", array_map(fn($k) => '- ' . ['hard' => 'HARD SELL (brand_fit "hard"): pembicara menyebut brand/produk ini secara langsung.',
        'soft' => 'SOFT SELL (brand_fit "soft"): pembicara membahas topik yang relevan dengan brand/kategorinya, walau brand tidak disebut.'][$k], $b['modes']));
    $porsi = $b['all']
        ? "SEMUA clip harus terkait brand (hard/soft). Lebih baik sedikit clip daripada memasukkan clip yang tidak terkait."
        : 'Usahakan MINIMAL ' . min($b['min'], $maxClips) . " clip terkait brand (hard/soft); sisanya boleh momen terbaik biasa (brand_fit \"none\").";
    $brief = $b['brief'] !== '' ? "Brief campaign dari brand (WAJIB dipatuhi untuk clip terkait brand):\n\"\"\"\n{$b['brief']}\n\"\"\"\n" : '';
    $tags = $b['tags'] ? 'Hashtag/mention wajib (' . implode(' ', $b['tags']) . ") ditambahkan otomatis oleh sistem, jangan ditulis ulang di caption.\n" : '';
    return <<<TXT
=== CAMPAIGN BRAND (PRIORITAS TINGGI) ===
User sedang ikut campaign clip untuk brand "{$b['name']}" (kategori: {$cat}).
Topik yang dianggap terkait brand: {$topics}.
Jenis clip brand yang diterima:
{$modes}
{$porsi}
ATURAN:
- Clip brand tetap harus memenuhi semua kriteria kualitas di atas (utuh, berdiri sendiri, ada hook & payoff).
- Pilih momen yang topiknya memang dibahas cukup panjang, bukan sekadar satu kata yang lewat.
- JANGAN memaksakan relevansi. Kalau video tidak membahas brand/topik terkait, isi brand_fit "none" dan jujur;
  jangan mengarang hubungan yang tidak ada.
- Untuk clip brand: title, on_screen_text, dan caption mengarah ke topik terkait secara natural (soft) atau
  menyebut brand dengan positif (hard), tanpa terdengar seperti iklan kaku. Isi brand_angle dengan alasannya.
- Jangan menyebut atau memuji brand pesaing di clip brand.
{$brief}{$tags}
TXT;
}

function build_prompt(array $info, string $source, int $maxClips, string $transcript, array $heatmap, int $min = CLIP_MIN_SEC, int $max = CLIP_MAX_SEC, string $style = 'podcast', array $taken = [], string $outLang = 'id', array $concepts = [], string $note = '', string $capStyle = 'auto', ?array $brand = null): string
{
    // Konsep clip pilihan user di modal "Atur clip": jadi prioritas utama pencarian momen
    $conceptRule = '';
    if ($concepts || $note !== '') {
        $all = CLIP_CONCEPTS[$style === 'gaming' ? 'gaming' : 'podcast'];
        $lines = array_map(fn($k) => "- \"{$all[$k][0]}\": {$all[$k][2]}", $concepts);
        $conceptRule = "=== KONSEP CLIP PILIHAN USER (PRIORITAS UTAMA) ===\n"
            . ($concepts ? "User hanya ingin clip dengan konsep berikut (boleh campuran):\n" . implode("\n", $lines) . "\n"
                . "Pilih HANYA momen yang benar-benar cocok dengan salah satu konsep di atas; aturan fokus/kategori di atas tetap\n"
                . "dipakai untuk kualitas, tapi kalau bertentangan, konsep pilihan user yang menang. Isi field concept dengan\n"
                . "konsep yang paling cocok. Usahakan tiap konsep terwakili kalau videonya punya momen yang layak.\n"
                . "Sesuaikan title, on_screen_text, dan caption dengan konsep clip-nya.\n" : '')
            . ($note !== '' ? "Konteks tambahan dari user (wajib diperhatikan): \"$note\"\n" : '')
            . "Kalau momen yang cocok sedikit, berikan lebih sedikit clip daripada memasukkan momen yang tidak sesuai.\n";
    }
    $brandRule = $brand ? brand_rule($brand, $maxClips) : '';
    $duration = (int) ($info['duration'] ?? 0);
    $chapters = '';
    foreach ($info['chapters'] ?? [] as $c) {
        $chapters .= '- ' . fmt_ts($c['start_time']) . ' ' . $c['title'] . "\n";
    }
    $heat = '';
    foreach ($heatmap as $h) {
        $heat .= (int) $h['start_time'] . '-' . (int) $h['end_time'] . 's: ' . round($h['value'], 2) . "\n";
    }
    $desc = mb_substr((string) ($info['description'] ?? ''), 0, 1500);

    // Toleransi di luar rentang yang dipilih user: sekitar 10% (minimal 3 dtk, maksimal 10 dtk)
    $hardMin = max(5, $min - max(3, min(5, (int) round($min * .1))));
    $hardMax = $max + max(3, min(10, (int) round($max * .12)));

    $sourceNote = $source === 'audio'
        ? 'Video ini tidak punya subtitle. Dengarkan AUDIO terlampir dengan teliti dan tentukan timestamp (detik) seakurat mungkin. Isi field "transcript" dengan ucapan lengkap di tiap clip.'
        : 'Gunakan TRANSKRIP di bawah. Format tiap baris: [detik_mulai] teks. Field "transcript" isi string kosong.';

    $brief = $style === 'gaming' ? <<<TXT_GAMING
Kamu adalah editor clip short-form (TikTok, Reels, Shorts) yang sangat berpengalaman di niche
GAMING, khususnya HIGHLIGHT LIVE STREAMING. Tugasmu: temukan momen yang paling bikin penonton berhenti
scroll dari rekaman streaming game yang panjang. Clip akan ditampilkan dengan gameplay di atas dan webcam
streamer di bawah.

FOKUS NICHE — setiap clip WAJIB masuk salah satu dari tiga jenis momen ini (isi field category):
1. "Momen lucu": kocak dan menghibur — celetukan/roasting spontan, interaksi absurd dengan teman main atau
   chat, bug/glitch aneh, salah paham, tingkah karakter game yang konyol, komentar streamer yang witty.
2. "Momen menegangkan": bikin deg-degan — clutch 1 lawan banyak, kabur dengan darah tipis, detik-detik
   terakhir sebelum menang/kalah, dikejar musuh/monster, jumpscare, keputusan nekat, comeback tak terduga.
   Puncaknya jelas: berhasil (selebrasi) atau gagal (frustrasi/teriak).
3. "Momen ngakak": lebih dari sekadar lucu — streamer (atau teman mainnya) sampai tertawa lepas tak
   terkendali, fail super konyol, kejadian absurd yang bikin semua pecah, rage yang justru kocak.
Momen yang tidak masuk ketiganya (tutorial, obrolan santai, gameplay datar) JANGAN dipilih.

CARA MEMBACA DATA: transkrip/audio hanya berisi suara streamer (komentar, teriakan, tawa, umpatan, obrolan
dengan chat atau teman main). Aksi di game harus kamu simpulkan dari REAKSI dan ucapan streamer:
- Tanda lucu/ngakak: tawa ("wkwk", "hahaha", tertawa sampai tidak bisa bicara), celetukan, ejekan, "apaan sih",
  "kok bisa", suara makin kacau. Makin lama & lepas tawanya, makin mungkin "Momen ngakak".
- Tanda menegangkan: bicara cepat/berbisik, panik ("bentar bentar", "tolong", "awas", "lari"), hitungan,
  diam tegang lalu meledak jadi teriak, "GG", "no way", "anjir", "gila", selebrasi atau frustrasi.
Data MOST REPLAYED sangat penting: puncaknya sering menandai momen terbaik.

KRITERIA CLIP YANG BAGUS (wajib diperhatikan semuanya):
1. DURASI {$min}–{$max} detik. Jangan di bawah {$hardMin} atau di atas {$hardMax} detik.
2. MOMEN UTUH: build-up singkat → puncak → reaksi streamer setelahnya. Jangan potong sebelum reaksi
   (tawa/teriak/selebrasi) selesai, karena reaksi adalah inti clip streaming.
3. HOOK KUAT di 3–5 detik pertama: langsung masuk ke situasi tegang/lucu/kacau. Jangan mulai dari menu,
   loading, atau obrolan basa-basi.
4. BERDIRI SENDIRI: penonton yang tidak menonton stream-nya tetap paham apa yang terjadi dan kenapa seru.
   Sertakan build-up secukupnya (ucapan streamer yang menjelaskan situasinya), jangan mulai di tengah kalimat.
5. PAYOFF: berakhir tepat setelah puncak (punchline, tawa pecah, clutch berhasil/gagal), jangan dibiarkan
   menggantung terlalu lama sesudahnya.
6. start_seconds tepat di awal build-up, end_seconds tepat setelah reaksi/kalimat penutup selesai.

HINDARI: intro/pembukaan stream, layar menunggu/"starting soon", loading & menu, AFK/diam lama, membaca
donasi/sponsor/iklan, ajakan subscribe/follow, obrolan panjang tanpa aksi, dan clip yang saling tumpang tindih.
Usahakan hasil bervariasi antara ketiga jenis momen kalau videonya memang punya, tapi jangan memaksakan
momen yang lemah hanya supaya variasinya lengkap.

DATA PENDUKUNG "MOST REPLAYED": nilai 0–1 per segmen = seberapa sering bagian itu diputar ulang penonton.
Untuk streaming game ini sinyal kuat momen seru. Abaikan puncak di beberapa detik paling awal video (biasanya bias).

PENILAIAN: score_* skala 1–10, overall_score skala 0–100. Arti skor untuk gaming: score_hook = seberapa cepat
dan kuat 3 detik pertama; score_insight = seberapa kuat momennya (selucu / setegang / sengakak apa);
score_standalone = mudah dipahami tanpa konteks; score_emotion = kuatnya reaksi streamer (tawa, panik, teriak,
selebrasi); score_quotability = ada ucapan/teriakan khas yang layak jadi teks atau meme. Nilai dengan jujur
dan ketat. Urutkan dari overall_score tertinggi.

JUMLAH: maksimal {$maxClips} clip. Kualitas lebih penting dari jumlah — hanya masukkan momen yang benar-benar
lucu, menegangkan, atau ngakak. Jika video kurang cocok (misalnya bukan streaming game), tetap berikan clip
terbaik dan jelaskan di niche_fit.

GAYA OUTPUT (bahasa tongkrongan gamer Indonesia, santai, tidak baku):
- title: menggambarkan momennya dan memancing klik, sesuai jenisnya. Contoh: "Clutch 1v4 Pas Darah Tinggal
  Sekali Pukul", "Niatnya Nolong Temen Malah Ikut Mati", "Ketawa Sampe Lupa Lagi Dikejar Boss".
- on_screen_text: hook pendek yang bikin penasaran sebelum puncaknya, maks 8 kata, jangan spoiler hasilnya.
  Contoh: "Detik-detik paling tegang hari ini", "Gak nyangka endingnya begini", "Tunggu sampai akhir 😭".
- caption: singkat dan relate dengan gamer, boleh emoji (😂😭🔥), tanpa hashtag.
- hashtags: campuran nama game (kalau bisa disimpulkan dari judul/deskripsi/ucapan), nama streamer bila jelas,
  dan tag gaming umum (#gaming #gamingclips #streamer #fyp); tambahkan #lucu/#ngakak atau #clutch/#tegang
  sesuai jenis momennya.

TXT_GAMING : <<<TXT_PODCAST
Kamu adalah editor clip short-form (TikTok, Reels, Shorts) yang sangat berpengalaman di niche
SELF-DEVELOPMENT, MOTIVASI, EDUKASI, MINDSET, dan KATA-KATA BERMAKNA. Tugasmu: temukan potongan
video yang paling layak dijadikan clip viral dari video panjang (podcast, talk, ceramah, wawancara).

KRITERIA CLIP YANG BAGUS (wajib diperhatikan semuanya):
1. DURASI {$min}–{$max} detik. Jangan di bawah {$hardMin} atau di atas {$hardMax} detik. Lebih baik sedikit
   lebih panjang daripada memotong percakapan yang belum selesai.
2. PERCAKAPAN UTUH: satu clip = satu gagasan/cerita/jawaban yang LENGKAP dari awal sampai tuntas.
   Kalau inti clip adalah jawaban narasumber, sertakan juga pertanyaan host yang memicunya bila perlu
   untuk konteks. Jangan memotong saat pembicara masih menjelaskan, memberi contoh, atau saat lawan
   bicara sedang menanggapi.
3. HOOK KUAT di 3–5 detik pertama: pernyataan tegas, pertanyaan yang memancing, fakta mengejutkan,
   atau kalimat yang langsung bikin penasaran. Jangan mulai dengan basa-basi. TAPI hook tidak harus kalimat
   pertama: kalau kalimat hook merujuk ke hal yang disebut sebelumnya, mulai clip 1–2 kalimat lebih awal
   (misalnya dari pertanyaan host atau kalimat yang menyebut topiknya) supaya penonton langsung paham.
4. BERDIRI SENDIRI: penonton yang tidak menonton video aslinya tetap paham. Hindari clip yang
   dimulai dengan kata penghubung/rujukan ("jadi", "nah itu", "seperti tadi", "dia", "itu", "and", "so",
   "that's why") atau yang bergantung pada konteks sebelumnya. Tes: kalau kalimat PERTAMA clip dibaca sendirian,
   penonton harus tahu siapa/apa yang dibicarakan. Kalau tidak, majukan start_seconds ke kalimat yang
   memperkenalkan topiknya.
5. PAYOFF/PENUTUP: clip berakhir pada kesimpulan, punchline, atau kalimat yang "kena" — setelah
   pembicara benar-benar selesai menyampaikan poinnya. Jangan berhenti di tengah kalimat.
6. NILAI: ada pelajaran, insight, sudut pandang baru, atau kalimat yang layak dijadikan quote.
7. EMOSI & RELATABLE: menyentuh, menampar, menginspirasi, atau sangat relate dengan kehidupan sehari-hari.
8. start_seconds tepat di AWAL kalimat pembuka (bukan di tengah kalimat atau di tengah baris transkrip —
   kalau kalimat dimulai di baris sebelumnya, pakai detik baris itu), end_seconds tepat SETELAH kalimat
   penutup selesai diucapkan.

HINDARI: intro/pembukaan acara, sponsor/iklan, ajakan subscribe/follow, bagian yang hanya bisa dipahami
dengan melihat visual, obrolan ringan tanpa isi, dan clip yang saling tumpang tindih.

DATA PENDUKUNG "MOST REPLAYED": nilai 0–1 per segmen = seberapa sering bagian itu diputar ulang penonton.
Pakai sebagai sinyal tambahan (nilai tinggi = penonton tertarik), tapi tetap nilai dari ISI ucapan.
Abaikan puncak di beberapa detik paling awal video (biasanya bias).

PENILAIAN: score_* skala 1–10, overall_score skala 0–100. Nilai dengan jujur dan ketat; clip biasa saja
jangan diberi nilai tinggi. Urutkan dari overall_score tertinggi.

JUMLAH: maksimal {$maxClips} clip. Kualitas lebih penting dari jumlah — hanya masukkan clip yang benar-benar
layak diposting. Jika video kurang cocok dengan niche ini, tetap berikan clip terbaik yang ada dan jelaskan
di niche_fit.

TXT_PODCAST;

    // Tambah clip: video sudah pernah dianalisa, AI harus mencari momen baru di luar clip yang sudah ada
    if ($taken) {
        $list = implode("\n", array_map(fn($c) => '- ' . number_format($c['start'], 1, '.', '') . '–'
            . number_format($c['end'], 1, '.', '') . ' dtk: ' . $c['title'], $taken));
        $brief .= <<<TXT_TAKEN

=== CLIP YANG SUDAH DIBUAT (JANGAN DIULANG) ===
Video ini sudah pernah dianalisa dan clip berikut sudah dibuat. Pengguna ingin clip TAMBAHAN, jadi cari
momen BARU yang berbeda: jangan pilih rentang waktu yang tumpang tindih dengan daftar ini, dan jangan
mengulang momen/topik yang sama. Standar boleh sedikit dilonggarkan dibanding analisa pertama, tapi
setiap clip tetap harus layak diposting dan memenuhi kriteria di atas.
{$list}

TXT_TAKEN;
    }

    // Aturan bahasa output. Contoh gaya di atas berbahasa Indonesia; untuk konten English gayanya diterapkan dalam English.
    $english = <<<TXT_EN
BAHASA (KONTEN BERBAHASA INGGRIS): title, on_screen_text, caption, dan hashtags ditulis dalam Bahasa Inggris
yang natural seperti kreator native English (gaya TikTok/Reels/Shorts, bukan terjemahan kaku). Contoh gaya di atas
berbahasa Indonesia; terapkan gaya, energi, dan pola hook-nya dalam English. hashtags 3–6 buah berbahasa Inggris,
diawali #, relevan dengan niche (mis. #podcast #mindset #selfimprovement / #gaming #clutch #funny).
why, video_summary, dan niche_fit tetap dalam Bahasa Indonesia (dibaca oleh editor). hook dan quote ditulis persis
dalam bahasa asli video. quote_translation diisi string kosong.
TXT_EN;
    $indo = <<<TXT_ID
BAHASA: title, why, on_screen_text, caption, video_summary, niche_fit dalam Bahasa Indonesia yang natural
(gaya konten sosial media, tidak kaku). hook dan quote ditulis persis dalam bahasa asli video.
hashtags 3–6 buah, diawali #, relevan dengan niche.
TXT_ID;
    $langRule = match ($outLang) {
        'en' => $english,
        'auto' => "Tentukan dulu bahasa utama yang diucapkan di video.\nJIKA bahasa utamanya Inggris, ikuti aturan ini:\n$english\n\nSELAIN ITU ikuti aturan ini:\n$indo",
        default => $indo,
    };

    // Nada judul, hook & caption mengikuti jenis tiap clip (bukan niche video secara umum)
    $toneRule = <<<TXT_TONE
=== POLA JUDUL, HOOK & CAPTION: WAJIB SESUAI JENIS CLIP ===
Tentukan nada title, on_screen_text, caption, dan hashtags dari jenis clip itu sendiri (field concept bila ada,
selain itu category), BUKAN dari niche video secara umum. Clip lucu dari podcast motivasi tetap ditulis lucu.
- LUCU / NGAKAK / FAIL: menghibur & playful. Nada bercanda, relate, boleh sindiran ringan, emoji 😂🤣😭,
  ajak tag teman. DILARANG memberi pelajaran hidup, moral, atau nada bijak/menggurui.
  Contoh caption: "Pak, kami cuma nanya 😭", "Plis ini aku banget 🤣 tag temen kamu yang gini".
- BIJAK / KATA BERMAKNA / MINDSET: reflektif dan singkat, boleh kutip inti kalimatnya, ajak simpan/renungkan.
  Contoh: "Kalimat ini nampar banget. Simpan buat diingat besok pagi."
- STATEMENT VIRAL / KONTROVERSIAL: tegas dan memancing diskusi tanpa menghina, akhiri dengan pertanyaan
  ("Setuju atau nggak?", "Tim mana kamu?").
- EMOSIONAL / KISAH: empatik dan personal, bangun rasa penasaran atau haru, ajak berbagi pengalaman.
- EDUKASI / INSIGHT: janjikan manfaat konkret ("Ternyata ini alasannya…", "Cara simpel biar…"), ajak simpan.
- MOTIVASI: energik, dorongan langsung ke penonton, kata kerja aktif.
- TEGANG / JUMPSCARE / CLUTCH (game): dramatis dan bikin deg-degan, ajak penonton menebak atau membayangkan
  ("Kalau kamu, lari atau lawan?").
- REAKSI HEBOH / PLOT TWIST (game): hype dan kaget ("Nggak ada yang nyangka endingnya…").
Hashtags juga mengikuti jenis clip (mis. #lucu #ngakak untuk clip lucu, #quotes #renungan untuk clip bijak).
TXT_TONE;
    // Gaya caption pilihan user: satu "suara" untuk semua clip, menggantikan pola nada per jenis clip
    if ($capStyle !== 'auto' && isset(CAPTION_STYLES[$capStyle])) {
        [$capName, , $capRule] = CAPTION_STYLES[$capStyle];
        $toneRule = <<<TXT_CAP
=== GAYA CAPTION PILIHAN USER: "{$capName}" (WAJIB) ===
Tulis title, on_screen_text, dan caption SEMUA clip dengan gaya: {$capRule}.
Gaya ini menggantikan pola nada per jenis clip, tapi isi tetap harus relevan dengan momen di masing-masing clip
(jangan mengarang kejadian yang tidak ada). Hashtags tetap relevan dengan topik & jenis clip.
TXT_CAP;
    }

    return <<<PROMPT
{$brief}
{$conceptRule}
{$brandRule}
{$toneRule}

{$langRule}

{$sourceNote}

=== INFO VIDEO ===
Judul: {$info['title']}
Channel: {$info['uploader']}
Durasi: {$duration} detik
Deskripsi:
{$desc}

=== CHAPTER ===
{$chapters}
=== MOST REPLAYED (detik: nilai) ===
{$heat}
=== TRANSKRIP ===
{$transcript}
PROMPT;
}

function run_analysis(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    if (!is_dir(ANALYSES_DIR)) {
        mkdir(ANALYSES_DIR, 0777, true);
    }

    // ---------- 1. Info video ----------
    update_job($id, ['status' => 'analyzing', 'stage' => 'Membaca info video…', 'progress' => 5]);
    $raw = '';
    [$code, $tail] = run(escapeshellarg(YTDLP) . ' --dump-single-json --no-playlist --no-warnings --socket-timeout 20 -- '
        . escapeshellarg($job['url']), function ($line) use (&$raw) {
            if ($line !== '' && $line[0] === '{') {
                $raw = $line;
            }
        }, $cancelFlag, 90);
    $info = json_decode($raw, true);
    if ($code !== 0 || !is_array($info)) {
        throw new RuntimeException(error_from($tail));
    }
    $duration = (float) ($info['duration'] ?? 0);
    $info['uploader'] = $info['uploader'] ?? $info['channel'] ?? '';
    // Judul & thumbnail selalu dari data YouTube supaya kartu room di beranda lengkap
    update_job($id, ['title' => $info['title'] ?? $job['title'], 'thumbnail' => $info['thumbnail'] ?? $job['thumbnail']]);
    $heatmap = $info['heatmap'] ?? [];
    // Rentang durasi & jumlah clip dipilih user di modal "Buat Clip"; job lama memakai nilai bawaan
    $clipMin = (int) ($job['clip_min'] ?? CLIP_MIN_SEC);
    $clipMax = (int) ($job['clip_max'] ?? CLIP_MAX_SEC);
    $maxClips = !empty($job['max_clips']) ? (int) $job['max_clips'] : (int) max(3, min(12, ceil($duration / 180)));
    // Tambah clip (job analyze_more): clip yang sudah ada di analisa awal tidak boleh diulang
    $moreOf = ($job['kind'] ?? '') === 'analyze_more' ? (string) ($job['analysis_id'] ?? '') : '';
    $prev = $moreOf ? json_decode((string) @file_get_contents(ANALYSES_DIR . "/$moreOf.json"), true) : null;
    if ($moreOf && !is_array($prev)) {
        throw new RuntimeException('Hasil analisa awal tidak ditemukan.');
    }
    $taken = $prev['clips'] ?? [];

    // ---------- 2. Transkrip ----------
    update_job($id, ['stage' => 'Mengambil transkrip…', 'progress' => 15]);
    [$manualKey, $autoKey, $lang] = pick_sub_tracks($info);
    $textUnits = $timingUnits = [];
    $sourceLabel = '';
    if ($manualKey || $autoKey) {
        $langs = implode(',', array_filter([$manualKey, $autoKey]));
        run(escapeshellarg(YTDLP) . ' --skip-download --no-warnings --write-subs --write-auto-subs --sub-format json3'
            . ' --sub-langs ' . escapeshellarg($langs) . ' -o ' . escapeshellarg("$tmpDir/sub") . ' -- '
            . escapeshellarg($job['url']), fn() => null, $cancelFlag, 120);
        $manualUnits = $manualKey ? parse_json3("$tmpDir/sub.$manualKey.json3") : [];
        $autoUnits = $autoKey ? parse_json3("$tmpDir/sub.$autoKey.json3") : [];
        $textUnits = $manualUnits ?: $autoUnits;
        $timingUnits = $autoUnits ?: $manualUnits;
        $sourceLabel = ($manualUnits ? 'Subtitle manual' : 'Subtitle otomatis') . ($lang ? ' (' . strtoupper($lang) . ')' : '');
    }

    $style = ($job['style'] ?? '') === 'gaming' ? 'gaming' : 'podcast';
    // Bahasa judul/caption: pilihan user di modal (Otomatis / Indonesia / English); otomatis = ikuti bahasa video
    $outLang = resolve_out_lang((string) ($job['out_lang'] ?? 'auto'), $lang ?: (string) ($info['language'] ?? ''));
    update_job($id, ['out_lang_used' => $outLang]);
    $concepts = clip_concepts_pick($style, (array) ($job['concepts'] ?? []));
    $note = mb_substr(trim((string) ($job['concept_note'] ?? '')), 0, 200);
    $capStyle = isset(CAPTION_STYLES[$job['caption_style'] ?? '']) ? $job['caption_style'] : 'auto';
    $brand = !empty($job['brand']) ? $job['brand'] : null;
    $schema = clip_schema($style, $outLang, $concepts, $brand);
    $onModel = fn($text) => update_job($id, ['stage' => $text]);

    if ($textUnits) {
        // ---------- 3a. Analisa transkrip ----------
        $transcript = '';
        foreach (transcript_lines($textUnits) as $l) {
            $transcript .= '[' . number_format($l['start'], 1, '.', '') . '] ' . $l['text'] . "\n";
        }
        update_job($id, ['stage' => 'AI sedang mencari momen terbaik…', 'progress' => 35]);
        $prompt = build_prompt($info, 'subtitle', $maxClips, $transcript, $heatmap, $clipMin, $clipMax, $style, $taken, $outLang, $concepts, $note, $capStyle, $brand);
        [$result, $model, $usage] = gemini_generate([['text' => $prompt]], $schema, $cancelFlag, $onModel);
        [$starts, $ends] = boundaries_from_units($timingUnits);
        $source = 'subtitle';
    } else {
        // ---------- 3b. Tidak ada subtitle: Gemini mendengarkan audio ----------
        update_job($id, ['stage' => 'Tidak ada subtitle, mengunduh audio…', 'progress' => 20]);
        [$code, $tail] = run(escapeshellarg(YTDLP) . ' --no-warnings --no-playlist -f ' . escapeshellarg('ba[abr<=96]/ba/b')
            . ' -o ' . escapeshellarg("$tmpDir/audio_src.%(ext)s") . ' -- ' . escapeshellarg($job['url']), fn() => null, $cancelFlag);
        $src = glob("$tmpDir/audio_src.*")[0] ?? null;
        if ($code !== 0 || !$src) {
            throw new RuntimeException(error_from($tail));
        }
        $audio = "$tmpDir/audio.mp3";
        run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($src)
            . ' -vn -ac 1 -ar 16000 -c:a libmp3lame -b:a 32k ' . escapeshellarg($audio), fn() => null, $cancelFlag);
        if (!is_file($audio)) {
            throw new RuntimeException('Gagal menyiapkan audio.');
        }
        update_job($id, ['stage' => 'Menyiapkan audio untuk AI…', 'progress' => 30]);
        $uri = gemini_upload($audio, 'audio/mpeg', $cancelFlag);
        update_job($id, ['stage' => 'AI sedang mendengarkan audio…', 'progress' => 40]);
        $prompt = build_prompt($info, 'audio', $maxClips, '(tidak ada — gunakan audio terlampir)', $heatmap, $clipMin, $clipMax, $style, $taken, $outLang, $concepts, $note, $capStyle, $brand);
        [$result, $model, $usage] = gemini_generate([
            ['file_data' => ['mime_type' => 'audio/mpeg', 'file_uri' => $uri]],
            ['text' => $prompt],
        ], $schema, $cancelFlag, $onModel);
        [$starts, $ends] = boundaries_from_silence($audio);
        $source = 'audio';
        $sourceLabel = 'Audio (tanpa subtitle, timestamp perkiraan)';
    }

    // ---------- 4. Rapikan hasil ----------
    update_job($id, ['stage' => 'Merapikan timestamp…', 'progress' => 95]);
    $values = array_column(array_slice($heatmap, 2), 'value');
    $heatMean = $values ? array_sum($values) / count($values) : null;

    $clips = [];
    foreach ($result['clips'] ?? [] as $c) {
        $s = max(0, (float) ($c['start_seconds'] ?? 0));
        $e = min($duration ?: INF, (float) ($c['end_seconds'] ?? 0));
        if ($e - $s < 5) {
            continue;
        }
        [$ss, $sGap] = snap($s, $starts);
        [$ee, $eGap] = snap($e, $ends);
        if ($ee - $ss < $clipMin / 2) {
            [$ss, $ee, $sGap, $eGap] = [$s, $e, 0, 0];
        }
        // Clip yang dimulai di tengah alur bicara dimundurkan ke awal kalimat supaya penonton tidak bingung
        $opening = $source === 'subtitle'
            ? implode(' ', array_column(array_slice(array_values(array_filter($textUnits, fn($u) => $u[0] >= $ss - 0.3)), 0, 3), 2))
            : (($c['transcript'] ?? '') ?: ($c['hook'] ?? ''));
        $cs = context_start($ss, $sGap, $opening, $starts);
        if ($cs < $ss && $ee - $cs <= $clipMax + 10) {
            $sGap = (float) (array_values(array_filter($starts, fn($b) => $b[0] === $cs))[0][1] ?? 0);
            $ss = $cs;
        }
        // Sedikit ruang napas di awal dan akhir clip
        $ss = max(0, $ss - min(0.15, $sGap / 2));
        $ee = min($duration ?: INF, $ee + min(0.35, $eGap / 2));

        $text = $c['transcript'] ?? '';
        if ($source === 'subtitle') {
            $words = array_filter($textUnits, fn($u) => $u[0] >= $ss - 0.3 && $u[0] < $ee);
            $text = implode(' ', array_column($words, 2));
        }
        $heat = heatmap_score($heatmap, $ss, $ee);
        $fit = $brand && in_array($c['brand_fit'] ?? '', $brand['modes'], true) ? $c['brand_fit'] : '';
        $tags = array_values(array_filter((array) ($c['hashtags'] ?? [])));
        if ($fit) {
            // Hashtag/mention wajib campaign hanya di clip yang terkait brand
            $tags = array_values(array_unique(array_merge($brand['tags'], $tags)));
        }
        $clips[] = [
            'start' => round($ss, 2),
            'end' => round($ee, 2),
            'title' => $c['title'] ?? '',
            'hook' => $c['hook'] ?? '',
            'quote' => $c['quote'] ?? '',
            'quote_translation' => $c['quote_translation'] ?? '',
            'transcript' => $text,
            'category' => $c['category'] ?? 'Lainnya',
            'concept' => (string) ($c['concept'] ?? ''),
            'why' => $c['why'] ?? '',
            'on_screen_text' => $c['on_screen_text'] ?? '',
            'caption' => trim(preg_replace('/(\s*#[\p{L}\p{N}_]+)+\s*$/u', '', (string) ($c['caption'] ?? ''))),
            'hashtags' => $tags,
            'brand_fit' => $fit,
            'brand_angle' => $fit ? (string) ($c['brand_angle'] ?? '') : '',
            'scores' => [
                'hook' => (int) ($c['score_hook'] ?? 0),
                'insight' => (int) ($c['score_insight'] ?? 0),
                'standalone' => (int) ($c['score_standalone'] ?? 0),
                'emotion' => (int) ($c['score_emotion'] ?? 0),
                'quotability' => (int) ($c['score_quotability'] ?? 0),
            ],
            'overall' => (int) ($c['overall_score'] ?? 0),
            'replay' => $heat !== null && $heatMean ? round($heat / $heatMean, 2) : null,
        ];
    }

    // Buang clip yang tumpang tindih >40% dengan clip yang skornya lebih tinggi.
    // Mode campaign: clip brand didahulukan supaya tidak terbuang oleh clip biasa yang tumpang tindih.
    usort($clips, fn($a, $b) => [$b['brand_fit'] !== '', $b['overall']] <=> [$a['brand_fit'] !== '', $a['overall']]);
    $kept = [];
    foreach ($clips as $c) {
        foreach ($kept as $k) {
            $overlap = min($c['end'], $k['end']) - max($c['start'], $k['start']);
            if ($overlap > 0.4 * ($c['end'] - $c['start'])) {
                continue 2;
            }
        }
        $kept[] = $c;
    }
    usort($kept, fn($a, $b) => $b['overall'] <=> $a['overall']);
    $brandInfo = null;
    if ($brand) {
        $found = count(array_filter($kept, fn($c) => $c['brand_fit'] !== ''));
        // "Semua clip terkait brand": clip biasa dibuang, kecuali tidak ada satu pun clip brand
        if ($brand['all'] && $found) {
            $kept = array_values(array_filter($kept, fn($c) => $c['brand_fit'] !== ''));
        }
        $want = $brand['all'] ? count($kept) : min($brand['min'], $maxClips);
        $brandInfo = ['name' => $brand['name'], 'found' => $found, 'want' => $brand['all'] ? max(1, $want) : $want];
    }

    if ($moreOf) {
        // Buang yang masih tumpang tindih (>15%) dengan clip lama, lalu tambahkan ke analisa awal.
        // Clip baru dimasukkan ke proyek oleh api.php (aksi studio) memakai indeks new_scenes.
        $kept = array_values(array_filter($kept, function ($c) use ($taken) {
            foreach ($taken as $t) {
                if (min($c['end'], $t['end']) - max($c['start'], $t['start']) > 0.15 * ($c['end'] - $c['start'])) {
                    return false;
                }
            }
            return true;
        }));
        $prev = json_decode((string) @file_get_contents(ANALYSES_DIR . "/$moreOf.json"), true) ?: $prev;
        $first = count($prev['clips']);
        $prev['clips'] = array_merge($prev['clips'], $kept);
        file_put_contents(ANALYSES_DIR . "/$moreOf.json", json_encode($prev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_job($moreOf, ['clip_count' => count($prev['clips'])]);
        update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100, 'clip_count' => count($kept), 'brand_result' => $brandInfo,
            'new_scenes' => $kept ? range($first, $first + count($kept) - 1) : []]);
        return;
    }

    $analysis = [
        'id' => $id,
        'video' => [
            'id' => $info['id'] ?? '',
            'url' => $info['webpage_url'] ?? $job['url'],
            'title' => $info['title'] ?? '',
            'uploader' => $info['uploader'],
            'duration' => $duration,
            'thumbnail' => $info['thumbnail'] ?? '',
        ],
        'source' => $source,
        'style' => $style,
        'source_label' => $sourceLabel,
        'language' => strtolower(explode('-', $lang ?: (string) ($info['language'] ?? ''))[0]),
        'model' => $model,
        'tokens' => $usage['totalTokenCount'] ?? null,
        'summary' => $result['video_summary'] ?? '',
        'niche_fit' => $result['niche_fit'] ?? '',
        'heatmap' => array_map(fn($h) => [round($h['start_time'], 2), round($h['end_time'], 2), round($h['value'], 3)], $heatmap),
        'clips' => $kept,
        'created_at' => time(),
    ];
    file_put_contents(ANALYSES_DIR . "/$id.json", json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod(ANALYSES_DIR . "/$id.json", 0666);

    update_job($id, [
        'status' => 'done',
        'stage' => 'Selesai',
        'progress' => 100,
        'clip_count' => count($kept),
        'source_label' => $sourceLabel,
        'brand_result' => $brandInfo, // jumlah clip terkait brand yang ditemukan vs diminta
    ]);
}
