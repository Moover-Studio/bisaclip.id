<?php
// Clip campaign dari file lokal: user mengunggah bahan footage, AI memecah tiap footage jadi adegan,
// lalu menyusun clip sesuai angle konten (mis. "persahabatan") lengkap dengan hook.
// Hasil susunan dirakit jadi satu video dan dijadikan room BisaClip biasa, jadi Studio, editor, render, & kalender langsung bisa dipakai.

define('CAMPAIGN_DIR', STORAGE_DIR . '/campaigns');
define('CAMPAIGN_PUB', APP_DIR . '/previews/campaign'); // thumbnail (bisa dibuka browser)

// Niche campaign; sementara baru film yang aktif (bertahap)
const CAMPAIGN_NICHES = [
    'film' => ['label' => 'Film', 'on' => true],
    'podcast' => ['label' => 'Podcast', 'on' => false],
    'game' => ['label' => 'Game', 'on' => false],
    'musik' => ['label' => 'Musik', 'on' => false],
];
const CAMPAIGN_EXTS = ['mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi'];
const CAMPAIGN_TARGETS = [30, 45, 60, 90];
const CAMPAIGN_AI_SLOTS = 3; // footage yang dianalisa AI bersamaan

function campaign_path(string $cid): string
{
    return CAMPAIGN_DIR . "/$cid.json";
}

function campaign_dir(string $cid): string
{
    return CAMPAIGN_DIR . "/$cid";
}

function read_campaign(string $cid): ?array
{
    if (!valid_job_id($cid)) {
        return null;
    }
    $c = json_decode((string) @file_get_contents(campaign_path($cid)), true);
    return is_array($c) ? $c : null;
}

function write_campaign(array $c): void
{
    if (!is_dir(CAMPAIGN_DIR)) {
        @mkdir(CAMPAIGN_DIR, 0777, true);
        @chmod(CAMPAIGN_DIR, 0777);
    }
    $c['updated_at'] = time();
    file_put_contents(campaign_path($c['id']), json_encode($c, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(campaign_path($c['id']), 0666);
}

/** Ubah data campaign secara aman (beberapa proses bisa menulis bersamaan). */
function update_campaign(string $cid, callable $fn): ?array
{
    $lock = fopen(CAMPAIGN_DIR . "/$cid.lock", 'c');
    @chmod(CAMPAIGN_DIR . "/$cid.lock", 0666);
    flock($lock, LOCK_EX);
    try {
        $c = read_campaign($cid);
        if (!$c) {
            return null;
        }
        $c = $fn($c) ?? $c;
        write_campaign($c);
        return $c;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Data satu bahan footage: storage/campaigns/<cid>/<fid>.json */
function read_cfile(string $cid, string $fid): ?array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $fid)) {
        return null;
    }
    $f = json_decode((string) @file_get_contents(campaign_dir($cid) . "/$fid.json"), true);
    return is_array($f) ? $f : null;
}

function write_cfile(string $cid, array $f): void
{
    $path = campaign_dir($cid) . "/{$f['fid']}.json";
    file_put_contents($path, json_encode($f, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($path, 0666);
}

function update_cfile(string $cid, string $fid, array $patch): ?array
{
    $f = read_cfile($cid, $fid);
    if (!$f) {
        return null;
    }
    $f = array_merge($f, $patch);
    write_cfile($cid, $f);
    return $f;
}

/** File video asli sebuah bahan */
function cfile_media(string $cid, array $f): string
{
    return campaign_dir($cid) . "/{$f['fid']}.{$f['ext']}";
}

function campaign_pub_dir(string $cid): string
{
    $d = CAMPAIGN_PUB . "/$cid";
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
        @chmod(CAMPAIGN_PUB, 0777);
        @chmod($d, 0777);
    }
    return $d;
}

/** Info video: durasi, ukuran, fps, ada audio. */
function cprobe(string $file): array
{
    $d = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -print_format json -show_streams -show_format ' . escapeshellarg($file)), true) ?: [];
    $info = ['duration' => (float) ($d['format']['duration'] ?? 0), 'width' => 0, 'height' => 0, 'fps' => 0, 'audio' => false];
    foreach ($d['streams'] ?? [] as $s) {
        if ($s['codec_type'] === 'video' && !$info['width']) {
            [$n, $dn] = array_map('floatval', explode('/', $s['avg_frame_rate'] ?? '0/1') + [1 => 1]);
            $info = array_merge($info, ['width' => (int) $s['width'], 'height' => (int) $s['height'], 'fps' => $dn > 0 ? round($n / $dn, 3) : 0]);
            // Video diputar (metadata rotate) → lebar & tinggi tertukar
            $rot = abs((int) ($s['tags']['rotate'] ?? ($s['side_data_list'][0]['rotation'] ?? 0)));
            if ($rot === 90 || $rot === 270) {
                [$info['width'], $info['height']] = [$info['height'], $info['width']];
            }
        } elseif ($s['codec_type'] === 'audio') {
            $info['audio'] = true;
        }
    }
    return $info;
}

/** Thumbnail JPG dari detik $t ke $out (lebar $w). */
function cthumb(string $src, float $t, string $out, int $w = 320): bool
{
    exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . sprintf('%.2f', max(0, $t)) . ' -i ' . escapeshellarg($src)
        . " -frames:v 1 -vf scale=$w:-2 -q:v 4 " . escapeshellarg($out), $o, $code);
    @chmod($out, 0666);
    return $code === 0 && is_file($out);
}

/* ---------- Ucapan & pergantian shot (lokal, tanpa kuota AI): dasar titik potong yang rapi ---------- */

/** Detik pergantian shot (deteksi scene ffmpeg). Ambang 0,15: tetap menangkap cut di adegan gelap (film horor / malam). */
function campaign_shot_cuts(string $video): array
{
    $out = (string) shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -i ' . escapeshellarg($video)
        . " -an -vf \"scale=192:-2,select='gt(scene,0.15)',metadata=print:file=-\" -f null - 2>/dev/null");
    preg_match_all('/pts_time:([\d.]+)/', $out, $m);
    $cuts = [];
    foreach (array_map('floatval', $m[1]) as $t) {
        if (!$cuts || $t - end($cuts) > 0.6) {
            $cuts[] = round($t, 3);
        }
    }
    return $cuts;
}

/** Kata Whisper → kalimat/ucapan: putus di tanda baca akhir, jeda > 0,6 dtk, 16 kata, atau 6 detik (nyanyian/suara latar tidak jadi "kalimat" panjang). */
function campaign_lines(array $words): array
{
    $lines = [];
    $cur = null;
    foreach ($words as $k => [$ws, $we, $wt]) {
        if ($cur && ($ws - $cur['e'] > 0.6 || $we - $cur['s'] > 6)) {
            $lines[] = $cur;
            $cur = null;
        }
        $cur ??= ['s' => $ws, 'e' => $we, 't' => '', 'n' => 0];
        $cur['e'] = $we;
        $cur['t'] .= (preg_match('/^[\s,.?!…]/u', $wt) || $cur['t'] === '' ? '' : ' ') . $wt;
        $cur['n']++;
        if (preg_match('/[.?!…]["\']?$/u', trim($wt)) || $cur['n'] >= 16) {
            $lines[] = $cur;
            $cur = null;
        }
    }
    if ($cur) {
        $lines[] = $cur;
    }
    return array_map(fn($l) => ['s' => round($l['s'], 2), 'e' => round($l['e'], 2), 't' => mb_substr(preg_replace('/\s+/u', ' ', trim($l['t'])), 0, 240)], $lines);
}

/** Transkrip ucapan + pergantian shot sebuah bahan; disimpan di data bahan. */
function campaign_speech(string $cid, array $f, string $tmpDir, string $cancelFlag): array
{
    $src = !empty($f['preview']) && is_file(APP_DIR . '/' . $f['preview']) ? APP_DIR . '/' . $f['preview'] : cfile_media($cid, $f);
    $lines = [];
    if (!empty($f['audio'])) {
        $wav = "$tmpDir/speech_{$f['fid']}.wav";
        run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($src) . ' -vn -ac 1 -ar 16000 -c:a pcm_s16le '
            . escapeshellarg($wav), fn() => null, $cancelFlag, 300);
        if (is_file($wav)) {
            try {
                $lines = campaign_lines(whisper_words($wav, 'auto', $tmpDir, $cancelFlag));
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $lines = []; // tanpa transkrip: titik potong tetap dirapikan ke pergantian shot
            }
            @unlink($wav);
        }
    }
    $cuts = campaign_shot_cuts($src);
    update_cfile($cid, $f['fid'], ['lines' => $lines, 'cuts' => $cuts]);
    return ['lines' => $lines, 'cuts' => $cuts];
}

/** Ucapan yang sedang berlangsung di detik $t (bukan di ujungnya), atau null. */
function campaign_inside(array $lines, float $t, float $m = 0.08): ?array
{
    foreach ($lines as $l) {
        if ($t > $l['s'] + $m && $t < $l['e'] - $m) {
            return $l;
        }
    }
    return null;
}

/** Pergantian shot terdekat dalam [t-$back, t+$fwd] yang tidak memotong ucapan, atau null. */
function campaign_near_cut(array $cuts, array $lines, float $t, float $back, float $fwd): ?float
{
    $best = null;
    foreach ($cuts as $c) {
        if ($c >= $t - $back && $c <= $t + $fwd && !campaign_inside($lines, $c) && ($best === null || abs($c - $t) < abs($best - $t))) {
            $best = $c;
        }
    }
    return $best;
}

/** Rapikan batas antar adegan hasil AI: pindah ke pergantian shot terdekat, dan tidak pernah di tengah kalimat. */
function campaign_snap_scenes(array $scenes, array $lines, array $cuts, float $dur): array
{
    for ($i = 0; $i < count($scenes) - 1; $i++) {
        $t = $scenes[$i]['end'];
        $c = campaign_near_cut($cuts, $lines, $t, 1.2, 1.2);
        if ($c !== null) {
            $t = $c;
        } elseif ($l = campaign_inside($lines, $t)) {
            $t = $t - $l['s'] < $l['e'] - $t ? $l['s'] : $l['e'];
        }
        if ($t - $scenes[$i]['start'] >= 0.5 && $scenes[$i + 1]['end'] - $t >= 0.5) {
            $scenes[$i]['end'] = $scenes[$i + 1]['start'] = round($t, 2);
        }
    }
    return $scenes;
}

/**
 * Titik potong satu segmen dirapikan: mulai di awal kalimat (atau di pergantian shot saat hening),
 * selesai setelah kalimat tuntas — kalimat berikutnya yang langsung menyambung ikut (maks +4 dtk).
 */
function campaign_snap_seg(array $f, float $a, float $b): array
{
    $L = $f['lines'] ?? [];
    $C = $f['cuts'] ?? [];
    $D = (float) $f['duration'];
    // Geseran dibatasi: awal maks. 2,5 dtk lebih awal, akhir maks. +4 dtk (transkrip bisa keliru di musik / teriakan)
    if (($l = campaign_inside($L, $a, 0.05)) && $a - $l['s'] <= 2.5) {
        $a = $l['s'] - 0.12;
    } elseif (($c = campaign_near_cut($C, $L, $a, 0.6, 0.6)) !== null) {
        $a = $c;
    }
    $limit = $b + 4;
    if (($l = campaign_inside($L, $b, 0.05)) && $l['e'] + 0.25 <= $limit) {
        $b = $l['e'] + 0.25;
        // Kalimat berikutnya yang menyambung tanpa jeda: ikut supaya tidak terpotong
        foreach ($L as $n) {
            if ($n['s'] > $l['s'] && $n['s'] - $b < 0.1 && $n['e'] + 0.25 <= $limit) {
                $b = $n['e'] + 0.25;
            }
        }
    } elseif (($c = campaign_near_cut($C, $L, $b, 0.4, 0.6)) !== null) {
        $b = $c;
    }
    // Jeda napas: waktu kata di transkrip sering sedikit lebih cepat dari suara aslinya, jadi potongan yang jatuh
    // tepat di ujung/awal kalimat diberi ruang (0,35 dtk sesudah, 0,15 dtk sebelum) tanpa memakan kalimat tetangga.
    foreach ($L as $k => $l) {
        if ($l['e'] <= $b + 0.05 && $b - $l['e'] < 0.35) {
            $next = $L[$k + 1]['s'] ?? INF;
            $b = max($b, min($l['e'] + 0.35, ($l['e'] + $next) / 2));
        }
        if ($l['s'] >= $a - 0.05 && $l['s'] - $a < 0.25) {
            $prev = $L[$k - 1]['e'] ?? -INF;
            $a = min($a, max($l['s'] - 0.15, ($prev + $l['s']) / 2));
        }
    }
    $a = max(0, $a);
    $b = min($D, max($b, $a + 1));
    return [round($a, 2), round($b, 2)];
}

/** Rapikan semua segmen; segmen berurutan dari footage yang sama dan bersambung digabung (tidak ada ucapan dobel). */
function campaign_fix_segments(array $segs, array $byFid): array
{
    $out = [];
    foreach ($segs as $s) {
        $f = $byFid[$s['fid']] ?? null;
        if ($f) {
            [$s['start'], $s['end']] = campaign_snap_seg($f, $s['start'], $s['end']);
        }
        $prev = $out ? $out[count($out) - 1] : null;
        if ($prev && $prev['fid'] === $s['fid'] && $s['start'] >= $prev['start'] && $s['start'] <= $prev['end'] + 0.3) {
            $out[count($out) - 1]['end'] = max($prev['end'], $s['end']);
            $out[count($out) - 1]['reason'] = trim($prev['reason'] . ' ' . $s['reason']);
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/* ---------- Worker: analisa adegan satu footage ---------- */

/** Slot analisa AI (maks. CAMPAIGN_AI_SLOTS footage bersamaan, sisanya menunggu). */
function campaign_ai_slot(string $id, string $cancelFlag)
{
    while (true) {
        for ($i = 0; $i < CAMPAIGN_AI_SLOTS; $i++) {
            $path = STORAGE_DIR . "/campaign-ai-$i.lock";
            $h = fopen($path, 'c');
            @chmod($path, 0666);
            if (flock($h, LOCK_EX | LOCK_NB)) {
                return $h;
            }
            fclose($h);
        }
        if (file_exists($cancelFlag)) {
            throw new Cancelled();
        }
        update_job($id, ['stage' => 'Menunggu giliran analisa AI…']);
        sleep(2);
    }
}

function campaign_scene_schema(): array
{
    return [
        'type' => 'OBJECT',
        'properties' => [
            'summary' => ['type' => 'STRING'],
            'characters' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'scenes' => ['type' => 'ARRAY', 'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'start' => ['type' => 'NUMBER'],
                    'end' => ['type' => 'NUMBER'],
                    'description' => ['type' => 'STRING'],
                    'dialogue' => ['type' => 'STRING'],
                    'emotion' => ['type' => 'STRING'],
                    'themes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'shot' => ['type' => 'STRING'],
                    'quality' => ['type' => 'INTEGER'],
                    'usable' => ['type' => 'BOOLEAN'],
                    'note' => ['type' => 'STRING'],
                ],
                'required' => ['start', 'end', 'description', 'emotion', 'themes', 'quality', 'usable'],
            ]],
        ],
        'required' => ['summary', 'scenes'],
    ];
}

function run_campaign_file(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    require_once __DIR__ . '/gemini.php';
    [$cid, $fid] = [$job['campaign_id'], $job['file_id']];
    $f = read_cfile($cid, $fid);
    $src = $f ? cfile_media($cid, $f) : '';
    if (!$f || !is_file($src)) {
        throw new RuntimeException('File bahan tidak ditemukan.');
    }
    $stage = function (string $s, int $p) use ($id, $cid, $fid) {
        update_job($id, ['status' => 'converting', 'stage' => $s, 'progress' => $p]);
        update_cfile($cid, $fid, ['status' => 'analyzing', 'stage' => $s]);
    };
    $stage('Membaca video…', 5);
    $info = cprobe($src);
    if ($info['duration'] <= 0 || !$info['width']) {
        throw new RuntimeException('File ini bukan video yang bisa dibaca.');
    }
    $pub = campaign_pub_dir($cid);
    cthumb($src, min($info['duration'] * 0.15, 30), "$pub/$fid.jpg", 480);
    update_cfile($cid, $fid, ['duration' => round($info['duration'], 3), 'width' => $info['width'], 'height' => $info['height'],
        'fps' => $info['fps'], 'audio' => $info['audio'], 'thumb' => "previews/campaign/$cid/$fid.jpg"]);

    // Versi ringan untuk AI (360p): cepat diunggah, AI tetap melihat gambar & mendengar dialog
    $stage('Menyiapkan video untuk AI…', 15);
    $ai = "$tmpDir/ai.mp4";
    [$code, $tail] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($src)
        . " -map 0:v:0 -map '0:a:0?' -vf scale=-2:360,fps=24 -c:v libx264 -preset veryfast -crf 32 -pix_fmt yuv420p"
        . ' -c:a aac -b:a 48k -ac 1 -movflags +faststart ' . escapeshellarg($ai), fn() => null, $cancelFlag, 900);
    if ($code !== 0 || !is_file($ai)) {
        throw new RuntimeException('Gagal menyiapkan video: ' . error_from($tail));
    }

    // Versi ringan ini juga dipakai untuk memutar potongan di halaman campaign
    if (copy($ai, "$pub/$fid.mp4")) {
        @chmod("$pub/$fid.mp4", 0666);
        update_cfile($cid, $fid, ['preview' => "previews/campaign/$cid/$fid.mp4"]);
    }
    // Transkrip ucapan & pergantian shot (lokal): batas adegan & titik potong tidak memotong kalimat
    $stage('Mentranskrip dialog & mendeteksi pergantian shot…', 22);
    $sp = campaign_speech($cid, read_cfile($cid, $fid), $tmpDir, $cancelFlag);
    $slot = campaign_ai_slot($id, $cancelFlag);
    try {
        $stage('Mengirim video ke AI…', 30);
        $uri = gemini_upload($ai, 'video/mp4', $cancelFlag);
        $stage('AI sedang menonton & memecah adegan…', 45);
        $dur = round($info['duration'], 2);
        $prompt = <<<TXT
Kamu adalah editor video film untuk konten short-form (TikTok, Reels, Shorts) campaign clipper.
Tonton seluruh footage terlampir (durasi {$dur} detik) dan pecah menjadi adegan / shot yang bermakna.

Aturan:
- Setiap adegan 1,5–15 detik, berurutan, tidak tumpang tindih, dan bersama-sama mencakup seluruh durasi.
  Potong di pergantian shot, perubahan aksi, atau jeda dialog — jangan memotong di tengah kalimat.
- start & end: detik dari awal file (angka desimal, mis. 12.4). Jangan melebihi {$dur}.
- description: apa yang terlihat & terjadi secara spesifik (siapa, aksi, ekspresi, latar). Bahasa Indonesia.
- dialogue: kutipan dialog / narasi penting di adegan itu dalam bahasa aslinya (kosong kalau tidak ada).
- emotion: emosi utama adegan (mis. haru, tegang, lucu, marah, hangat, sedih, epik).
- themes: 2–5 tema yang bisa dipakai sebagai angle konten (mis. persahabatan, pengkhianatan, cinta, keluarga,
  pengorbanan, balas dendam, komedi, aksi, motivasi, kesepian, kekuasaan).
- shot: jenis shot (close-up, medium, wide, over-the-shoulder, montage, dll).
- quality: kelayakan visual untuk short-form 1–5 (5 = sangat kuat & menarik).
- usable: false kalau adegan berisi layar hitam, logo / watermark besar, kredit / teks panjang, gambar rusak,
  atau terlalu gelap/blur sehingga tidak layak dipakai; note: alasannya.
- summary: ringkasan isi footage (2–3 kalimat). characters: tokoh yang muncul (nama kalau diketahui, selain itu deskripsi).
TXT;
        [$res] = gemini_generate([
            ['file_data' => ['mime_type' => 'video/mp4', 'file_uri' => $uri]],
            ['text' => $prompt],
        ], campaign_scene_schema(), $cancelFlag, fn($s) => update_job($id, ['stage' => $s]));
    } finally {
        flock($slot, LOCK_UN);
        fclose($slot);
    }

    // Rapikan adegan: urut, dalam durasi, minimal 0,5 detik
    $stage('Merapikan adegan…', 85);
    $scenes = [];
    $D = $info['duration'];
    $list = (array) ($res['scenes'] ?? []);
    usort($list, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));
    foreach ($list as $s) {
        $a = max(0, min($D, (float) ($s['start'] ?? 0)));
        $b = max(0, min($D, (float) ($s['end'] ?? 0)));
        if ($scenes) {
            $a = max($a, end($scenes)['end']);
        }
        if ($b - $a < 0.5) {
            continue;
        }
        $scenes[] = [
            'sid' => 'S' . (count($scenes) + 1), 'start' => round($a, 2), 'end' => round($b, 2),
            'description' => mb_substr(trim((string) ($s['description'] ?? '')), 0, 400),
            'dialogue' => mb_substr(trim((string) ($s['dialogue'] ?? '')), 0, 300),
            'emotion' => mb_substr(trim((string) ($s['emotion'] ?? '')), 0, 40),
            'themes' => array_slice(array_map(fn($t) => mb_substr(trim((string) $t), 0, 30), (array) ($s['themes'] ?? [])), 0, 5),
            'shot' => mb_substr(trim((string) ($s['shot'] ?? '')), 0, 40),
            'quality' => max(1, min(5, (int) ($s['quality'] ?? 3))),
            'usable' => (bool) ($s['usable'] ?? true),
            'note' => mb_substr(trim((string) ($s['note'] ?? '')), 0, 160),
        ];
    }
    if (!$scenes) {
        throw new RuntimeException('AI tidak menemukan adegan di video ini.');
    }
    $scenes = campaign_snap_scenes($scenes, $sp['lines'], $sp['cuts'], $D);
    // Thumbnail tiap adegan (dipakai di susunan clip)
    foreach ($scenes as $k => $s) {
        $name = "{$fid}_{$s['sid']}.jpg";
        if (cthumb($src, ($s['start'] + $s['end']) / 2, "$pub/$name", 240)) {
            $scenes[$k]['thumb'] = "previews/campaign/$cid/$name";
        }
    }
    update_cfile($cid, $fid, ['status' => 'done', 'stage' => '', 'error' => '', 'summary' => mb_substr((string) ($res['summary'] ?? ''), 0, 600),
        'characters' => array_slice(array_map('strval', (array) ($res['characters'] ?? [])), 0, 10), 'scenes' => $scenes, 'analyzed_at' => time()]);
    update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100]);
}

/* ---------- Susun clip sesuai angle (AI) ---------- */

function campaign_plan_schema(): array
{
    return [
        'type' => 'OBJECT',
        'properties' => [
            'story' => ['type' => 'STRING'],
            'title' => ['type' => 'STRING'],
            'hook_text' => ['type' => 'STRING'],
            'caption' => ['type' => 'STRING'],
            'hashtags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'segments' => ['type' => 'ARRAY', 'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'scene' => ['type' => 'STRING'],
                    'start' => ['type' => 'NUMBER'],
                    'end' => ['type' => 'NUMBER'],
                    'reason' => ['type' => 'STRING'],
                ],
                'required' => ['scene', 'start', 'end', 'reason'],
            ]],
            'unused' => ['type' => 'ARRAY', 'items' => [
                'type' => 'OBJECT',
                'properties' => ['footage' => ['type' => 'STRING'], 'reason' => ['type' => 'STRING']],
                'required' => ['footage', 'reason'],
            ]],
        ],
        'required' => ['story', 'title', 'hook_text', 'caption', 'hashtags', 'segments'],
    ];
}

/** Bahan yang sudah dianalisa, dengan kode F1, F2, … sesuai urutan unggah. */
function campaign_ready_files(array $c): array
{
    $out = [];
    foreach ($c['files'] as $k => $fid) {
        $f = read_cfile($c['id'], $fid);
        if ($f && ($f['status'] ?? '') === 'done' && !empty($f['scenes'])) {
            $f['code'] = 'F' . ($k + 1);
            $out[$f['code']] = $f;
        }
    }
    return $out;
}

/**
 * Minta AI menyusun clip: pilih & urutkan adegan sesuai angle, boleh memotong adegan, boleh tidak memakai footage.
 * Mengembalikan rencana: title, hook_text, caption, hashtags, segments [{fid, start, end, reason}], unused.
 */
function campaign_make_plan(array $c, ?callable $onStatus = null, string $cancelFlag = ''): array
{
    require_once __DIR__ . '/gemini.php';
    $files = campaign_ready_files($c);
    if (!$files) {
        throw new RuntimeException('Belum ada footage yang selesai dianalisa.');
    }
    $target = (int) $c['target'];
    $lang = $c['lang'] === 'en' ? 'English' : 'Bahasa Indonesia';
    $lines = [];
    foreach ($files as $code => $f) {
        $lines[] = "\n### $code — \"{$f['name']}\" (" . round($f['duration'], 1) . " dtk). Ringkasan: {$f['summary']}"
            . ($f['characters'] ? ' Tokoh: ' . implode(', ', $f['characters']) . '.' : '');
        foreach ($f['scenes'] as $s) {
            $lines[] = sprintf('[%s-%s] %.1f–%.1f (%.1f dtk) | %s | emosi: %s | tema: %s | shot: %s | kualitas %d%s',
                $code, $s['sid'], $s['start'], $s['end'], $s['end'] - $s['start'], $s['description'],
                $s['emotion'], implode(', ', $s['themes']), $s['shot'] ?: '-', $s['quality'], $s['usable'] ? '' : ' | TIDAK LAYAK: ' . $s['note']);
            // Ucapan persis (transkrip) dengan waktunya: dasar memilih titik mulai & selesai
            foreach ($f['lines'] ?? [] as $l) {
                if ($l['e'] > $s['start'] + 0.05 && $l['s'] < $s['end'] - 0.05) {
                    $lines[] = sprintf('    • %.1f–%.1f "%s"', $l['s'], $l['e'], $l['t']);
                }
            }
            if (empty($f['lines']) && $s['dialogue'] !== '') {
                $lines[] = '    • dialog: "' . $s['dialogue'] . '"';
            }
        }
    }
    $footage = implode("\n", $lines);
    $angle = trim($c['angle']);
    $prompt = <<<TXT
Kamu adalah editor short-form profesional untuk campaign clipper niche FILM (TikTok, Reels, Shorts).
Tugasmu menyusun SATU clip vertikal berdurasi sekitar {$target} detik (boleh ±15%) dari bahan footage di bawah,
dengan angle / konsep konten: "{$angle}".

Bahan (kode adegan [Fx-Sy], waktu dalam detik dari awal file footage):
{$footage}

Baris "•" di bawah adegan adalah ucapan persis (transkrip) dengan waktunya — pakai ini untuk menentukan titik potong.

Penonton TIDAK mengenal film ini. Hasil clip harus bisa dipahami utuh tanpa konteks lain: siapa tokohnya, apa yang terjadi,
dan kenapa momen itu penting untuk angle "{$angle}".

Cara menyusun:
1. Pilih HANYA adegan yang mendukung angle "{$angle}". Footage atau adegan yang tidak relevan, berkualitas rendah,
   atau ditandai TIDAK LAYAK jangan dipakai — sebutkan footage yang tidak dipakai sama sekali di "unused" beserta alasannya.
2. Lebih baik SEDIKIT segmen yang panjang & utuh daripada banyak potongan pendek: pakai sekitar 3–7 segmen,
   tiap segmen minimal 3 detik (idealnya 5–15 detik). Satu segmen boleh mencakup beberapa adegan berurutan dari footage yang
   sama — "scene" diisi kode adegan tempat segmen itu dimulai, start & end boleh melewati batas adegan asal masih di footage yang sama.
3. JANGAN memotong ucapan: start tepat sebelum awal kalimat (atau saat hening), end setelah kalimat selesai. Kalau sebuah
   percakapan/ jawaban menjadi inti, ambil sampai tuntas. Jangan ambil ujung kalimat tanpa awalnya.
4. Urutan segmen = urutan tayang. Segmen pertama (hook, 3–6 detik) boleh momen paling memancing dari bagian mana pun.
   Setelah itu ikuti urutan kejadian asli (kronologis) supaya alurnya masuk akal: perkenalan situasi/tokoh → konflik/ujian →
   puncak emosi → penutup yang membekas. Setiap perpindahan segmen harus terasa nyambung secara cerita.
5. Hindari dua segmen yang isinya hampir sama atau tumpang tindih.
6. Total durasi semua segmen mendekati {$target} detik (boleh ±15%).
7. story: 1–2 kalimat alur cerita clip ini (untuk dicek editor).
8. hook_text: teks hook di layar untuk awal video, maksimal 10 kata, {$lang}, membuat orang berhenti scroll dan langsung
   menyinggung angle "{$angle}" (jangan spoiler berlebihan, tanpa emoji, tanpa tanda kutip).
9. title: judul clip (maks 80 karakter, {$lang}). caption: caption gaya clipper film yang mengajak berinteraksi, sesuai angle
   ({$lang}, 1–3 kalimat). hashtags: 4–6 hashtag relevan (tanpa spasi, diawali #).
10. reason (tiap segmen): singkat kenapa segmen itu dipakai di posisi itu.
TXT;
    [$res] = gemini_generate([['text' => $prompt]], campaign_plan_schema(), $cancelFlag, $onStatus);
    // Jawaban mentah terakhir disimpan untuk pengecekan
    @file_put_contents(campaign_dir($c['id']) . '/plan_raw.json', json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // Petakan & validasi segmen ke file + rentang adegan. Kode adegan ditulis AI dengan variasi ("[F1-S3]", "F1 - S3", "f1_s3")
    $segs = [];
    foreach ((array) ($res['segments'] ?? []) as $s) {
        if (!preg_match('/F\s*(\d+)\s*[-_.:\/\s]*S\s*(\d+)/i', (string) ($s['scene'] ?? ''), $m) || !isset($files['F' . $m[1]])) {
            continue;
        }
        $f = $files['F' . $m[1]];
        $sc = current(array_filter($f['scenes'], fn($x) => $x['sid'] === 'S' . $m[2]));
        if (!$sc) {
            continue;
        }
        // Segmen boleh melewati batas adegan (masih di footage yang sama)
        $a = max(0, min($f['duration'], (float) ($s['start'] ?? $sc['start'])));
        $b = max(0, min($f['duration'], (float) ($s['end'] ?? $sc['end'])));
        if ($b - $a < 0.8 || $b < $sc['start'] - 1 || $a > $sc['end'] + 1) {
            [$a, $b] = [$sc['start'], $sc['end']];
        }
        $segs[] = ['id' => bin2hex(random_bytes(4)), 'fid' => $f['fid'], 'scene' => $sc['sid'], 'start' => round($a, 2), 'end' => round($b, 2),
            'reason' => mb_substr(trim((string) ($s['reason'] ?? '')), 0, 200)];
    }
    if (!$segs) {
        throw new RuntimeException('AI tidak menemukan adegan yang cocok dengan angle ini. Coba angle lain atau tambah footage.');
    }
    // Titik potong dirapikan ke awal/akhir kalimat & pergantian shot
    $byFid = [];
    foreach ($files as $f) {
        $byFid[$f['fid']] = $f;
    }
    $segs = campaign_fix_segments($segs, $byFid);
    $unused = [];
    foreach ((array) ($res['unused'] ?? []) as $u) {
        $code = preg_match('/F\s*(\d+)/i', (string) ($u['footage'] ?? ''), $um) ? 'F' . $um[1] : '';
        if (isset($files[$code])) {
            $unused[] = ['fid' => $files[$code]['fid'], 'reason' => mb_substr(trim((string) ($u['reason'] ?? '')), 0, 200)];
        }
    }
    return [
        'title' => mb_substr(trim((string) ($res['title'] ?? '')), 0, 120),
        'hook_text' => mb_substr(trim((string) ($res['hook_text'] ?? ''), " \"'"), 0, 120),
        'caption' => mb_substr(trim((string) ($res['caption'] ?? '')), 0, 600),
        'hashtags' => campaign_tags((array) ($res['hashtags'] ?? [])),
        'story' => mb_substr(trim((string) ($res['story'] ?? '')), 0, 400),
        'segments' => $segs,
        'unused' => $unused,
        'angle' => $angle,
        'made_at' => time(),
    ];
}

/** Hashtag rapi: AI kadang menggabung beberapa hashtag dalam satu teks ("#a #b"), jadi dipecah per spasi / koma. */
function campaign_tags(array $list): array
{
    $out = [];
    foreach ($list as $h) {
        foreach (preg_split('/[\s,]+/u', (string) $h) as $t) {
            $t = '#' . ltrim($t, '#');
            if ($t !== '#' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
    }
    return array_slice($out, 0, 8);
}

/** Worker: susun clip di latar belakang (bisa lama kalau server AI sibuk), hasil & error disimpan di campaign. */
function run_campaign_plan(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    $cid = $job['campaign_id'];
    $c = read_campaign($cid);
    if (!$c) {
        throw new RuntimeException('Campaign tidak ditemukan.');
    }
    // Bahan yang dianalisa sebelum ada transkrip ucapan: lengkapi dulu (lokal, tanpa kuota AI)
    foreach (campaign_ready_files($c) as $code => $f) {
        if (!isset($f['lines'])) {
            update_job($id, ['status' => 'converting', 'stage' => "Mentranskrip dialog {$code}…", 'progress' => 5]);
            $sp = campaign_speech($cid, $f, $tmpDir, $cancelFlag);
            update_cfile($cid, $f['fid'], ['scenes' => campaign_snap_scenes($f['scenes'], $sp['lines'], $sp['cuts'], (float) $f['duration'])]);
        }
    }
    update_job($id, ['status' => 'converting', 'stage' => 'AI membaca ' . count($c['files']) . ' footage…', 'progress' => 10]);
    $plan = campaign_make_plan($c, fn($s) => update_job($id, ['stage' => $s]), $cancelFlag);
    update_campaign($cid, function ($c) use ($plan) {
        $c['plan'] = $plan;
        $c['plan_error'] = '';
        return $c;
    });
    update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100]);
}

/** Rapikan susunan dari editor susunan (urutan & potongan dari user). */
function campaign_clean_plan(array $c, array $in): array
{
    $segs = [];
    foreach (array_slice((array) ($in['segments'] ?? []), 0, 80) as $s) {
        $f = read_cfile($c['id'], (string) ($s['fid'] ?? ''));
        if (!$f || ($f['status'] ?? '') !== 'done' || !in_array($f['fid'], $c['files'], true)) {
            continue;
        }
        $a = max(0, min($f['duration'], (float) ($s['start'] ?? 0)));
        $b = max(0, min($f['duration'], (float) ($s['end'] ?? 0)));
        if ($b - $a < 0.3) {
            continue;
        }
        $segs[] = ['id' => preg_match('/^[a-f0-9]{8}$/', (string) ($s['id'] ?? '')) ? $s['id'] : bin2hex(random_bytes(4)),
            'fid' => $f['fid'], 'scene' => (string) ($s['scene'] ?? ''), 'start' => round($a, 2), 'end' => round($b, 2),
            'reason' => mb_substr((string) ($s['reason'] ?? ''), 0, 200)];
    }
    return [
        'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 120),
        'hook_text' => mb_substr(trim((string) ($in['hook_text'] ?? '')), 0, 120),
        'caption' => mb_substr(trim((string) ($in['caption'] ?? '')), 0, 600),
        'hashtags' => array_values(array_slice(array_filter(array_map('strval', (array) ($in['hashtags'] ?? []))), 0, 8)),
        'story' => mb_substr((string) ($in['story'] ?? ''), 0, 400),
        'segments' => $segs,
        'unused' => array_values(array_filter((array) ($in['unused'] ?? []), fn($u) => is_array($u) && isset($u['fid']))),
        'angle' => mb_substr((string) ($in['angle'] ?? $c['angle']), 0, 300),
        'made_at' => (int) ($in['made_at'] ?? time()),
    ];
}

/* ---------- Worker: rakit susunan jadi satu video, jadikan room ---------- */

/**
 * Semua segmen disamakan ke 1920x1080 (footage lebar diisi penuh, footage sempit/vertikal di tengah dengan latar blur),
 * fps footage utama, lalu disambung dengan potongan langsung (cut) — kualitas tinggi supaya render akhir tetap tajam.
 * Hasilnya disimpan sebagai video sumber room baru (analysis + source job), proyeknya dibuat oleh API.
 */
function run_campaign_build(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    $c = read_campaign($job['campaign_id']);
    $plan = $job['plan'] ?? null;
    if (!$c || !$plan || empty($plan['segments'])) {
        throw new RuntimeException('Susunan clip kosong.');
    }
    update_job($id, ['status' => 'converting', 'stage' => 'Menyiapkan footage…', 'progress' => 0]);
    $files = [];
    foreach ($plan['segments'] as $s) {
        $files[$s['fid']] ??= read_cfile($c['id'], $s['fid']);
        if (!$files[$s['fid']] || !is_file(cfile_media($c['id'], $files[$s['fid']]))) {
            throw new RuntimeException('Ada footage di susunan yang sudah dihapus.');
        }
    }
    // fps footage yang paling banyak dipakai
    $byFps = [];
    foreach ($plan['segments'] as $s) {
        $fps = (float) ($files[$s['fid']]['fps'] ?: 30);
        $byFps[(string) $fps] = ($byFps[(string) $fps] ?? 0) + ($s['end'] - $s['start']);
    }
    arsort($byFps);
    $fps = (float) array_key_first($byFps) ?: 30;
    $fps = $fps > 61 ? 60 : ($fps < 15 ? 30 : $fps);
    [$W, $H] = [1920, 1080];

    $inputs = '';
    $parts = [];
    $cat = '';
    $total = 0;
    foreach ($plan['segments'] as $n => $s) {
        $f = $files[$s['fid']];
        $len = round($s['end'] - $s['start'], 3);
        $total += $len;
        $inputs .= sprintf(' -ss %.3f -t %.3f -i %s', $s['start'], $len, escapeshellarg(cfile_media($c['id'], $f)));
        $wide = $f['width'] / max(1, $f['height']) >= 1.6;
        $fit = $wide
            ? "scale=$W:$H:force_original_aspect_ratio=increase:flags=lanczos,crop=$W:$H"
            : "split[ca$n][cb$n];[ca$n]scale=$W:$H:force_original_aspect_ratio=increase,crop=$W:$H,boxblur=30:2[cg$n];"
                . "[cb$n]scale=$W:$H:force_original_aspect_ratio=decrease:flags=lanczos[cf$n];[cg$n][cf$n]overlay=(W-w)/2:(H-h)/2";
        $parts[] = "[$n:v]$fit,setsar=1,fps=$fps,format=yuv420p,trim=0:$len,setpts=PTS-STARTPTS[v$n]";
        // Fade audio sangat singkat di tiap sambungan supaya tidak ada bunyi "klik"
        $fadeOut = max(0, $len - 0.08);
        $parts[] = !empty($f['audio'])
            ? "[$n:a]aformat=sample_rates=48000:channel_layouts=stereo,apad,atrim=0:$len,asetpts=PTS-STARTPTS,afade=t=in:d=0.04,afade=t=out:st=$fadeOut:d=0.08[a$n]"
            : "anullsrc=r=48000:cl=stereo,atrim=0:$len,asetpts=PTS-STARTPTS[a$n]";
        $cat .= "[v$n][a$n]";
    }
    $parts[] = $cat . 'concat=n=' . count($plan['segments']) . ':v=1:a=1[v][a]';
    $graph = "$tmpDir/graph.txt";
    file_put_contents($graph, implode(";\n", $parts));

    $aid = bin2hex(random_bytes(8));
    if (!is_dir(SOURCES_DIR)) {
        @mkdir(SOURCES_DIR, 0777, true);
        @chmod(SOURCES_DIR, 0777);
    }
    $out = "$tmpDir/assembled.mp4";
    $lastError = '';
    $ok = false;
    foreach (clip_encoders(24, 'max') as $enc) {
        update_job($id, ['stage' => 'Merakit ' . count($plan['segments']) . ' potongan footage…', 'progress' => 0]);
        [$code, $tail] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1' . $inputs
            . ' -/filter_complex ' . escapeshellarg($graph) . " -map '[v]' -map '[a]' $enc -c:a aac -b:a 320k -movflags +faststart " . escapeshellarg($out),
            function (string $line) use ($id, $total) {
                if (str_starts_with($line, 'out_time_us=')) {
                    update_job($id, ['progress' => round(min(99, ((int) substr($line, 12)) / 1e6 / max(0.1, $total) * 100), 1)]);
                }
            }, $cancelFlag);
        clearstatcache();
        if ($ok = $code === 0 && is_file($out) && filesize($out) > 0) {
            break;
        }
        $lastError = error_from($tail);
    }
    if (!$ok) {
        throw new RuntimeException('Gagal merakit footage: ' . $lastError);
    }
    $src = source_file($aid);
    rename($out, $src);
    @chmod($src, 0666);
    $info = cprobe($src);

    // Room baru: data analisa + video sumber yang sudah "selesai diunduh"
    $title = $plan['title'] ?: $c['title'];
    $first = $plan['segments'][0];
    $thumbRel = "previews/campaign/{$c['id']}/room_$aid.jpg";
    cthumb($src, min(1.5, $info['duration'] / 2), APP_DIR . '/' . $thumbRel, 640);
    $sid = bin2hex(random_bytes(8));
    write_job(['id' => $sid, 'kind' => 'source', 'analysis_id' => $aid, 'url' => '', 'title' => $title, 'thumbnail' => $thumbRel,
        'status' => 'done', 'stage' => 'Selesai', 'progress' => 100, 'created_at' => time(), 'updated_at' => time(), 'size' => filesize($src),
        'width' => $info['width'], 'height' => $info['height'], 'fps' => $info['fps'], 'duration' => $info['duration'], 'vcodec' => 'h264']);
    $an = [
        'id' => $aid,
        'video' => ['id' => $aid, 'url' => '', 'title' => $title, 'uploader' => 'Campaign · ' . $c['title'], 'duration' => round($info['duration'], 3),
            'thumbnail' => $thumbRel],
        'source' => 'campaign', 'style' => 'film', 'source_label' => 'Campaign dari footage lokal', 'language' => $c['lang'] === 'en' ? 'en' : 'id',
        'summary' => 'Angle: ' . $plan['angle'], 'campaign_id' => $c['id'],
        'clips' => [[
            'start' => 0, 'end' => round($info['duration'], 3), 'title' => $title, 'hook' => $plan['hook_text'], 'quote' => '', 'quote_translation' => '',
            'transcript' => '', 'category' => 'Campaign', 'concept' => '', 'why' => 'Disusun AI dari ' . count($plan['segments']) . ' potongan footage sesuai angle.',
            'on_screen_text' => $plan['hook_text'], 'caption' => $plan['caption'], 'hashtags' => $plan['hashtags'], 'scores' => [], 'overall' => 0, 'replay' => 0,
        ]],
        'created_at' => time(),
    ];
    @mkdir(STORAGE_DIR . '/analyses', 0777, true);
    file_put_contents(STORAGE_DIR . "/analyses/$aid.json", json_encode($an, JSON_UNESCAPED_UNICODE));
    @chmod(STORAGE_DIR . "/analyses/$aid.json", 0666);
    write_job(['id' => $aid, 'kind' => 'analyze', 'url' => '', 'style' => 'film', 'title' => $title, 'thumbnail' => $thumbRel,
        'status' => 'done', 'stage' => 'Selesai', 'progress' => 100, 'created_at' => time(), 'updated_at' => time(), 'source_job' => $sid,
        'clip_count' => 1, 'out_lang_used' => $an['language'], 'campaign_id' => $c['id'], 'account_id' => (string) ($c['account_id'] ?? ''),
        'source_label' => $an['source_label']]);
    update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100, 'analysis_id' => $aid, 'duration' => $info['duration']]);
}
