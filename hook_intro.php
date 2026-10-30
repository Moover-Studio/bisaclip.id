<?php
// Bahan intro video (menu Intro di editor): suara AI Gemini TTS yang membacakan teks hook dan aset
// transisi kertas robek (tools/paper_tear.py). Intro-nya sendiri berupa footage gambar di awal clip;
// suaranya dipasang & transisinya dirakit oleh footage_concat() di clip.php.
require_once __DIR__ . '/gemini.php';

// Model TTS dicoba berurutan; kuota gratis ±10 suara per model per hari, jadi model berikutnya jadi cadangan.
const HOOK_TTS_MODELS = ['gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts', 'gemini-3.1-flash-tts-preview', 'gemini-2.5-flash-preview-tts'];
// Suara bawaan Gemini: key => [jenis, karakter]
const HOOK_VOICES = [
    'Puck' => ['Pria', 'ceria'], 'Fenrir' => ['Pria', 'bersemangat'], 'Achird' => ['Pria', 'ramah'], 'Zubenelgenubi' => ['Pria', 'santai'],
    'Sadachbia' => ['Pria', 'hidup'], 'Umbriel' => ['Pria', 'santai'], 'Algieba' => ['Pria', 'halus'], 'Iapetus' => ['Pria', 'jelas'],
    'Orus' => ['Pria', 'tegas'], 'Alnilam' => ['Pria', 'tegas'], 'Charon' => ['Pria', 'informatif'], 'Rasalgethi' => ['Pria', 'informatif'],
    'Sadaltager' => ['Pria', 'berwawasan'], 'Schedar' => ['Pria', 'stabil'], 'Enceladus' => ['Pria', 'berbisik'], 'Algenib' => ['Pria', 'serak'],
    'Zephyr' => ['Wanita', 'cerah'], 'Leda' => ['Wanita', 'muda'], 'Laomedeia' => ['Wanita', 'ceria'], 'Autonoe' => ['Wanita', 'cerah'],
    'Aoede' => ['Wanita', 'santai'], 'Callirrhoe' => ['Wanita', 'santai'], 'Kore' => ['Wanita', 'tegas'], 'Pulcherrima' => ['Wanita', 'lugas'],
    'Despina' => ['Wanita', 'halus'], 'Erinome' => ['Wanita', 'jelas'], 'Sulafat' => ['Wanita', 'hangat'], 'Vindemiatrix' => ['Wanita', 'lembut'],
    'Achernar' => ['Wanita', 'lembut'], 'Gacrux' => ['Wanita', 'dewasa'],
];

/** Suara yang boleh dipakai: nama suara Gemini. */
function hook_voice_ok($v): bool
{
    return is_string($v) && isset(HOOK_VOICES[$v]);
}

/** Daftar suara untuk editor: [{id, name, desc, gender}] */
function tts_voices(): array
{
    $list = [];
    foreach (HOOK_VOICES as $k => [$gender, $desc]) {
        $list[] = ['id' => $k, 'name' => $k, 'desc' => "$gender · $desc", 'gender' => $gender];
    }
    return ['voices' => $list];
}

// Gaya baca hook: key => [label, arahan gaya untuk Gemini TTS (tidak ikut dibacakan)]
const TTS_STYLES = [
    'auto' => ['Otomatis (AI pilih)', ''],
    'curious' => ['Penasaran', 'penasaran dan memancing rasa ingin tahu, seperti membuka rahasia'],
    'excited' => ['Antusias', 'antusias dan bersemangat, energi tinggi tapi tetap jelas'],
    'surprised' => ['Kaget', 'kaget dan tidak percaya, seperti baru tahu fakta mengejutkan'],
    'serious' => ['Serius', 'serius dan tegas, meyakinkan, tanpa dibuat-buat'],
    'playful' => ['Santai / bercanda', 'santai dan jenaka, seperti ngobrol dengan teman'],
    'dramatic' => ['Dramatis', 'dramatis dan menegangkan, penekanan kuat di bagian kunci'],
    'whispers' => ['Berbisik / misterius', 'setengah berbisik dan misterius, seperti membagikan rahasia'],
];

/** Jumlah kata baru di naskah AI yang tidak ada di teks hook (bentuk ucapan angka, singkatan & simbol tidak dihitung). */
function tts_added_words(string $text, string $script): int
{
    $words = fn($s) => preg_split('/[^\p{L}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
    $have = array_flip($words($text));
    $spoken = array_flip(['nol', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas', 'belas',
        'puluh', 'seratus', 'ratus', 'seribu', 'ribu', 'juta', 'miliar', 'triliun', 'persen', 'koma', 'dan', 'yang', 'dengan', 'untuk', 'tidak', 'nggak',
        'gak', 'enggak', 'sudah', 'udah', 'rupiah', 'dolar', 'tahun', 'jam', 'menit', 'detik', 'kilo', 'meter', 'atau', 'aja', 'saja', 'banget', 'kalau', 'kalo']);
    $n = 0;
    foreach ($words($script) as $w) {
        $n += !isset($have[$w]) && !isset($spoken[$w]) ? 1 : 0;
    }
    return $n;
}

/** Rapikan teks hook secara aturan (cadangan kalau AI tidak tersedia): tanpa emoji/tagar/kutip, huruf tidak kapital semua, ada tanda baca akhir. */
function tts_clean(string $text): string
{
    $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]|#\w+|["“”«»*_]/u', '', $text);
    $t = trim(preg_replace(['/\s+/u', '/([!?])[!?]+/u', '/\.{4,}/u'], [' ', '$1', '...'], $t)); // "??" / "!!!" terbaca janggal
    if ($t !== '' && mb_strtoupper($t) === $t && preg_match('/\p{L}{4}/u', $t)) {
        $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_strtolower(mb_substr($t, 1)); // HURUF KAPITAL SEMUA terbaca seperti teriakan
    }
    return $t !== '' && !preg_match('/[.!?…]$/u', $t) ? $t . '.' : $t;
}

/**
 * Naskah yang dibacakan suara AI + arahan gaya baca. Kata-katanya tetap teks hook; AI hanya merapikan supaya intonasinya natural:
 * tanda baca untuk jeda & penekanan, singkatan & angka ditulis seperti diucapkan, lalu memilih gaya baca yang cocok.
 * $script diisi user = dipakai apa adanya. Hasil AI disimpan di $cacheDir supaya tidak dipanggil ulang. [naskah, arahan gaya]
 */
function tts_script(string $text, string $style, string $script, string $cacheDir): array
{
    $tag = TTS_STYLES[$style][1] ?? '';
    $tone = fn($k) => TTS_STYLES[$k][1] ?? TTS_STYLES['excited'][1];
    if (trim($script) !== '') {
        return [trim($script), $tag ?: $tone(str_ends_with(trim($script), '?') ? 'curious' : 'excited')];
    }
    $cache = $cacheDir . '/script_' . md5($style . '|' . $text) . '.json';
    $hit = json_decode((string) @file_get_contents($cache), true);
    if (is_array($hit) && !empty($hit['script']) && tts_added_words($text, $hit['script']) <= 1) {
        return [$hit['script'], $tag ?: $tone($hit['tone'] ?? '')];
    }
    $tones = implode('|', array_diff(array_keys(TTS_STYLES), ['auto']));
    $prompt = "Kamu menyiapkan naskah voice over untuk hook pembuka video pendek (TikTok/Reels) berbahasa Indonesia. Naskah ini dibacakan AI text-to-speech,\n"
        . "jadi tulis supaya intonasinya natural seperti kreator konten yang sedang bercerita, bukan robot.\n\n"
        . "Teks hook: \"$text\"\n\n"
        . "Aturan:\n"
        . "- Pertahankan kata-kata & maknanya. JANGAN menambah kata, kalimat, atau pertanyaan baru (mis. \"bener nggak sih?\"), jangan menerjemahkan.\n"
        . "- Ubah singkatan, angka, & simbol jadi bentuk ucapan (yg → yang, 10jt → sepuluh juta, % → persen, & → dan). Bahasa gaul boleh tetap.\n"
        . "- Atur tanda baca untuk intonasi: koma untuk jeda napas di batas frasa, \"...\" untuk SATU jeda dramatis tepat sebelum kata/bagian kunci\n"
        . "  yang paling bikin penasaran (jangan setelah kata pertama, jangan memotong frasa), tanda tanya untuk pertanyaan, tanda seru untuk penekanan.\n"
        . "  Kalimat pendek (kurang dari 6 kata) tidak perlu jeda dramatis.\n"
        . "- Huruf normal (bukan kapital semua). Tanpa emoji, tagar, atau tanda kutip.\n"
        . "- Pilih nada baca yang paling cocok: $tones." . ($tag ? " (User sudah memilih nada, cukup rapikan naskahnya.)" : '');
    $schema = ['type' => 'OBJECT', 'properties' => ['script' => ['type' => 'STRING'], 'tone' => ['type' => 'STRING', 'enum' => array_values(array_diff(array_keys(TTS_STYLES), ['auto']))]],
        'required' => ['script', 'tone']];
    $res = gemini_quick($prompt, $schema);
    $out = trim((string) ($res['script'] ?? ''));
    // Jaga-jaga: naskah AI yang melenceng dari teks hook (mis. menambah "bener nggak sih?") tidak dipakai
    similar_text(mb_strtolower($text), mb_strtolower($out), $pct);
    if ($out === '' || $pct < 55 || mb_strlen($out) > mb_strlen($text) * 1.6 + 20 || tts_added_words($text, $out) > 1) {
        return [tts_clean($text), $tag ?: $tone(str_ends_with(trim($text), '?') ? 'curious' : 'excited')];
    }
    @mkdir($cacheDir, 0777, true);
    @file_put_contents($cache, json_encode(['script' => $out, 'tone' => $res['tone'] ?? ''], JSON_UNESCAPED_UNICODE));
    @chmod($cache, 0666);
    return [$out, $tag ?: $tone($res['tone'] ?? '')];
}

define('PAPER_DIR', STORAGE_DIR . '/paper_v1');
const PAPER_FPS = 30;

/** Folder cache voice over intro hook per proyek. */
function hook_voice_dir(string $pid): string
{
    return edit_dir($pid) . '/voice';
}

/** Nama file cache voice over untuk teks + suara + gaya baca + naskah tertentu (v3 = Gemini dengan catatan sutradara). */
function hook_voice_name(string $text, string $voice, string $style = 'auto', string $script = ''): string
{
    return 'hookvo_' . md5("v3|$voice|$style|$script|$text") . '.wav';
}

/**
 * Voice over hook (WAV 48 kHz stereo, hening di awal/akhir dibuang). Disimpan di $cacheDir per teks + suara,
 * jadi dengar ulang di editor & render tidak memanggil AI lagi. Mengembalikan [path, durasi detik].
 * Tidak memakai run()/probe() worker supaya bisa dipanggil dari api.php.
 */
function hook_tts(string $text, string $voice, string $cacheDir, string $cancelFlag, string $style = 'auto', string $script = ''): array
{
    @mkdir($cacheDir, 0777, true);
    @chmod($cacheDir, 0777);
    $style = isset(TTS_STYLES[$style]) ? $style : 'auto';
    $out = $cacheDir . '/' . hook_voice_name($text, $voice, $style, $script);
    $meta = $out . '.txt'; // naskah yang dibacakan (ditampilkan di editor)
    $said = (string) @file_get_contents($meta);
    if (!is_file($out)) {
        [$said, $tag] = tts_script($text, $style, $script, $cacheDir);
    }
    if (!is_file($out)) {
        // Arahan gaya ditulis sebagai catatan sutradara; yang dibacakan hanya bagian TRANSCRIPT
        // (instruksi berupa kalimat biasa atau tag bisa ikut terbaca oleh model TTS)
        $prompt = "# AUDIO PROFILE: Kreator konten Indonesia yang membuka video pendek TikTok/Reels\n\n"
            . "### DIRECTOR'S NOTES\nStyle: $tag. Intonasi natural bahasa Indonesia sehari-hari seperti orang sungguhan bercerita, tidak kaku dan tidak seperti membaca.\n"
            . "Pace: cepat dan mengalir, jeda singkat di koma, jeda dramatis di \"...\", nada naik di akhir pertanyaan.\n"
            . "Accent: Indonesia (Jakarta), pelafalan kata Indonesia yang benar.\n"
            . "Ucapkan hanya teks transkrip, tanpa tambahan kata, tawa, atau suara lain.\n\n"
            . "#### TRANSCRIPT\n$said";
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['AUDIO'],
                'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]],
        ], JSON_UNESCAPED_UNICODE);
        $audio = null;
        $quota = false;
        $blocked = gemini_quota_blocked();
        foreach (HOOK_TTS_MODELS as $model) {
            if (isset($blocked[$model])) {
                $quota = true;
                continue;
            }
            try {
                [$code, $body] = gemini_http('POST', GEMINI_BASE . "/v1beta/models/$model:generateContent", ['Content-Type: application/json'], $payload, $cancelFlag, 60);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                continue;
            }
            $res = json_decode($body, true);
            $part = $res['candidates'][0]['content']['parts'][0]['inlineData'] ?? null;
            if ($code === 200 && !empty($part['data'])) {
                $audio = [base64_decode($part['data']), (string) ($part['mimeType'] ?? '')];
                break;
            }
            if ($code === 429) {
                [$daily, $wait] = gemini_429_info($res);
                if ($daily) { // kuota harian model ini habis: langsung lewati sampai reset
                    gemini_quota_block($model, time() + max(3600, $wait ?: 86400));
                    $quota = true;
                }
            }
        }
        if (!$audio) {
            throw new RuntimeException($quota
                ? 'Kuota suara AI Gemini gratis hari ini sudah habis (±10 suara per model per hari). Coba lagi besok; suara yang sudah pernah dibuat tetap bisa dipakai.'
                : 'Voice over AI gagal dibuat (layanan TTS sedang sibuk). Coba lagi sebentar lagi.');
        }
    }
    if (!is_file($out)) {
        // Gemini lama mengirim PCM mentah (audio/L16); Gemini baru WAV
        $raw = $out . '.src';
        file_put_contents($raw, $audio[0]);
        // (tipe ditulis 'audio/L16' atau 'audio/l16; rate=24000')
        $in = stripos($audio[1], 'l16') !== false
            ? '-f s16le -ar ' . (preg_match('/rate=(\d+)/', $audio[1], $r) ? $r[1] : 24000) . ' -ac 1 -i ' : '-i ';
        $trim = 'silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse';
        exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error ' . $in . escapeshellarg($raw)
            . " -af '$trim,aformat=sample_rates=48000:channel_layouts=stereo' " . escapeshellarg($out) . ' 2>&1', $tail, $code);
        @unlink($raw);
        if ($code !== 0 || !is_file($out)) {
            @unlink($out);
            throw new RuntimeException('Gagal menyiapkan voice over: ' . trim(implode(' ', array_slice($tail, -2))));
        }
        @chmod($out, 0666);
        @file_put_contents($meta, $said);
        @chmod($meta, 0666);
    }
    $dur = (float) trim((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($out)));
    return [$out, $dur, $said];
}

/** Frame transisi kertas robek; dibuat sekali (±15 dtk) lalu dipakai ulang. [folder, jumlah frame, frame fase gumpalan] */
function paper_assets(string $cancelFlag): array
{
    $meta = PAPER_DIR . '/meta.txt';
    if (!is_file($meta)) {
        [$code, $tail] = run(escapeshellarg(PYTHON) . ' ' . escapeshellarg(APP_DIR . '/tools/paper_tear.py') . ' --out ' . escapeshellarg(PAPER_DIR),
            fn() => null, $cancelFlag, 300);
        if ($code !== 0 || !is_file($meta)) {
            throw new RuntimeException('Gagal membuat aset transisi kertas: ' . error_from($tail));
        }
    }
    [$n, , $ball] = array_map('intval', explode(' ', trim((string) file_get_contents($meta))));
    return [PAPER_DIR, $n, $ball];
}
