<?php
// Konfigurasi utama bisaclip (local only)

define('APP_DIR', __DIR__);
define('DOWNLOAD_DIR', APP_DIR . '/downloads');
define('STORAGE_DIR', APP_DIR . '/storage');
define('JOBS_DIR', STORAGE_DIR . '/jobs');
define('TMP_DIR', STORAGE_DIR . '/tmp');

/*
 * Platform: macOS (XAMPP + Homebrew) atau Linux / Windows lewat WSL2 (Apache + tools di /usr/local/bin).
 * Apache berjalan sebagai user lain ("daemon" di Mac, "www-data" di Linux), jadi semua binary pakai path absolut.
 * Path bisa ditimpa lewat storage/tools.json, mis. {"ffmpeg": "/opt/ffmpeg/bin/ffmpeg"}.
 */
define('IS_MAC', PHP_OS_FAMILY === 'Darwin');
define('IS_WSL', PHP_OS_FAMILY === 'Linux' && stripos((string) @file_get_contents('/proc/version'), 'microsoft') !== false);
define('BIN_DIR', IS_MAC ? '/opt/homebrew/bin' : '/usr/local/bin');

/** Path absolut sebuah tool: storage/tools.json → kandidat per platform → BIN_DIR. */
function tool_path(string $name, array $candidates = []): string
{
    static $over = null;
    $over ??= (array) json_decode((string) @file_get_contents(__DIR__ . '/storage/tools.json'), true);
    if (!empty($over[$name])) {
        return $over[$name];
    }
    foreach (array_merge($candidates, [BIN_DIR . "/$name", "/usr/local/bin/$name", "/usr/bin/$name"]) as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return BIN_DIR . "/$name";
}

define('YTDLP', tool_path('yt-dlp'));
define('FFMPEG', tool_path('ffmpeg'));
define('FFPROBE', tool_path('ffprobe'));
// Mac: ffmpeg-full (keg-only) khusus untuk membakar subtitle ASS, karena ffmpeg Homebrew biasa tidak punya libass.
// Linux: build statis ffmpeg sudah lengkap (libass, NVENC), jadi sama dengan FFMPEG.
define('FFMPEG_FULL', IS_MAC ? tool_path('ffmpeg-full', ['/opt/homebrew/opt/ffmpeg-full/bin/ffmpeg']) : FFMPEG);
// Whisper lokal untuk transkrip per kata (subtitle animasi)
define('WHISPER_CLI', tool_path('whisper-cli'));
define('WHISPER_MODEL', STORAGE_DIR . '/models/ggml-large-v3-turbo-q5_0.bin');
// Python + OpenCV untuk deteksi wajah pembicara (crop vertikal otomatis)
define('PYTHON', STORAGE_DIR . '/venv/bin/python');
define('FACE_MODEL', STORAGE_DIR . '/models/face_detection_yunet.onnx');
define('PHP_CLI', tool_path('php', IS_MAC ? ['/Applications/XAMPP/xamppfiles/bin/php'] : [PHP_BINDIR . '/php']));

/*
 * Encoder hardware. Kode memakai argumen VideoToolbox (Mac) sebagai acuan; di Linux/WSL diterjemahkan ke NVENC
 * (GPU NVIDIA) dengan setelan kualitas tertinggi. Tanpa GPU yang didukung, encoder hardware dilewati dan
 * cadangan software (x264/x265, kualitas sama atau lebih tinggi, hanya lebih lambat) yang dipakai.
 */
function hw_encoders(): array
{
    static $hw = null;
    if ($hw !== null) {
        return $hw;
    }
    if (IS_MAC) {
        return $hw = ['kind' => 'videotoolbox'];
    }
    $cache = STORAGE_DIR . '/hwenc.json';
    $key = FFMPEG . '|' . @filemtime(FFMPEG);
    $c = json_decode((string) @file_get_contents($cache), true);
    if (is_array($c) && ($c['key'] ?? '') === $key && time() - ($c['at'] ?? 0) < 7 * 86400) {
        return $hw = $c;
    }
    $test = function (string $args): bool {
        exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -f lavfi -i testsrc2=s=1280x720:r=30:d=0.3 -pix_fmt yuv420p '
            . $args . ' -f null - 2>&1', $o, $code);
        return $code === 0;
    };
    $hq = '-preset p7 -tune hq -rc vbr -multipass fullres -spatial-aq 1 -rc-lookahead 32';
    $hw = ['kind' => 'none', 'key' => $key, 'at' => time()];
    if ($test("-c:v h264_nvenc $hq")) {
        $hw = ['kind' => 'nvenc', 'hq' => $hq, 'hevc' => $test("-c:v hevc_nvenc $hq")] + $hw;
    } elseif ($test('-c:v h264_nvenc -preset p7 -rc vbr')) {
        $hw = ['kind' => 'nvenc', 'hq' => '-preset p7 -rc vbr', 'hevc' => $test('-c:v hevc_nvenc -preset p7 -rc vbr')] + $hw;
    }
    @file_put_contents($cache, json_encode($hw));
    @chmod($cache, 0666);
    return $hw;
}

/**
 * Argumen encoder hardware untuk platform ini dari argumen VideoToolbox; null = tidak ada (pakai cadangan software).
 * Bitrate sama (VBR, puncak 1,5x), kualitas konstan "-q:v 65" HEVC → CQ 19 (setara atau lebih tinggi).
 */
function venc(string $vt): ?string
{
    $hw = hw_encoders();
    if ($hw['kind'] === 'videotoolbox') {
        return $vt;
    }
    if ($hw['kind'] !== 'nvenc' || str_contains($vt, 'prores_videotoolbox')) {
        return null;
    }
    $hevc = str_contains($vt, 'hevc_videotoolbox');
    if ($hevc && empty($hw['hevc'])) {
        return null;
    }
    $args = '-c:v ' . ($hevc ? 'hevc_nvenc' : 'h264_nvenc') . ' ' . $hw['hq'];
    if (preg_match('/-b:v (\d+)([kM])/', $vt, $m)) {
        $v = (int) $m[1];
        $args .= " -b:v {$v}{$m[2]} -maxrate " . (int) ceil($v * 1.5) . $m[2] . ' -bufsize ' . ($v * 2) . $m[2];
    } else {
        $args .= ' -cq 19 -b:v 0';
    }
    if (str_contains($vt, '-profile:v high') && !$hevc) {
        $args .= ' -profile:v high';
    }
    if (str_contains($vt, '-tag:v hvc1')) {
        $args .= ' -tag:v hvc1';
    }
    return $args;
}

/** Daftar encoder [hardware, software...]: entri VideoToolbox diterjemahkan ke platform ini atau dilewati. */
function venc_list(array $list): array
{
    return array_values(array_filter(array_map(fn($e) => str_contains($e, '_videotoolbox') ? venc($e) : $e, $list)));
}

// Target durasi clip rekomendasi AI (detik)
const CLIP_MIN_SEC = 45;
const CLIP_MAX_SEC = 75;

// Mode output yang tersedia
const MODES = [
    'edit_h264' => ['label' => 'Siap Edit: H.264 MP4', 'ext' => 'mp4', 'type' => 'video'],
    'edit_prores' => ['label' => 'Siap Edit: ProRes 422 MOV', 'ext' => 'mov', 'type' => 'video'],
    'original' => ['label' => 'Original (tanpa konversi)', 'ext' => null, 'type' => 'video'],
    'audio_wav' => ['label' => 'Audio WAV', 'ext' => 'wav', 'type' => 'audio'],
    'audio_mp3' => ['label' => 'Audio MP3 320kbps', 'ext' => 'mp3', 'type' => 'audio'],
];

function setup_env(): void
{
    // Environment untuk yt-dlp / deno / ffmpeg saat dijalankan sebagai user daemon
    putenv('PATH=' . BIN_DIR . ':/usr/local/bin:/usr/bin:/bin' . (IS_MAC ? '' : ':/usr/lib/wsl/lib'));
    putenv('HOME=' . STORAGE_DIR);
    putenv('XDG_CACHE_HOME=' . STORAGE_DIR . '/cache');
    putenv('DENO_DIR=' . STORAGE_DIR . '/deno');
    putenv('LANG=en_US.UTF-8');
    putenv('LC_ALL=en_US.UTF-8');
}

function ensure_dirs(): void
{
    foreach ([DOWNLOAD_DIR, STORAGE_DIR, JOBS_DIR, TMP_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}

function require_local(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true) && !(IS_WSL && $ip === wsl_host_ip())) {
        http_response_code(403);
        exit('Akses hanya dari laptop ini.');
    }
}

/** WSL2: IP Windows (gateway default) — browser di Windows bisa tampil dengan IP ini, bukan 127.0.0.1. */
function wsl_host_ip(): string
{
    foreach (file('/proc/net/route') ?: [] as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (($f[1] ?? '') === '00000000' && isset($f[2]) && $f[2] !== '00000000') {
            return implode('.', array_reverse(array_map('hexdec', str_split($f[2], 2))));
        }
    }
    return '';
}

/**
 * Rapikan link jadi link satu video. Link YouTube pencarian/playlist/channel ditolak, karena
 * yt-dlp akan mencoba membaca semua isinya dan prosesnya bisa tidak pernah selesai.
 * Mengembalikan [url_bersih, null] atau [null, pesan_error].
 */
function normalize_video_url(string $url): array
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return [null, 'Link tidak valid.'];
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^(www|m|music)\./', '', $host);
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    $isYoutube = in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true);
    if (!$isYoutube) {
        return [$url, null]; // situs lain yang didukung yt-dlp
    }

    $id = null;
    if ($host === 'youtu.be') {
        $id = trim($path, '/');
    } elseif (!empty($q['v'])) {
        $id = (string) $q['v'];
    } elseif (preg_match('#^/(shorts|live|embed|v)/([^/?]+)#', $path, $m)) {
        $id = $m[2];
    }
    if ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
        return ["https://www.youtube.com/watch?v=$id", null];
    }

    if (str_starts_with($path, '/results')) {
        return [null, 'Ini link halaman pencarian YouTube. Buka salah satu videonya dulu, lalu salin link video tersebut.'];
    }
    if (str_starts_with($path, '/playlist') || !empty($q['list'])) {
        return [null, 'Ini link playlist. Buka salah satu videonya dulu, lalu salin link video tersebut.'];
    }
    if (preg_match('#^/(@|channel/|c/|user/)#', $path)) {
        return [null, 'Ini link channel. Buka salah satu videonya dulu, lalu salin link video tersebut.'];
    }
    return [null, 'Link YouTube ini bukan link video. Gunakan link seperti youtube.com/watch?v=… atau youtu.be/…'];
}

/**
 * Jalankan perintah dengan batas waktu. Kalau lewat batas, proses dihentikan paksa.
 * Mengembalikan [exit_code, output, timed_out].
 */
function run_with_timeout(string $cmd, int $seconds): array
{
    $proc = proc_open('exec ' . $cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [1, 'Gagal menjalankan proses', false];
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $deadline = microtime(true) + $seconds;
    $timedOut = false;
    while (true) {
        $read = [$pipes[1]];
        $w = $e = null;
        if (stream_select($read, $w, $e, 0, 300000) > 0) {
            $chunk = fread($pipes[1], 1 << 20);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
        }
        if (feof($pipes[1])) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }
    }
    fclose($pipes[1]);
    $code = proc_close($proc);
    return [$timedOut ? 124 : $code, $out, $timedOut];
}

function job_path(string $id): string
{
    return JOBS_DIR . '/' . $id . '.json';
}

function valid_job_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

function read_job(string $id): ?array
{
    $path = job_path($id);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function write_job(array $job): void
{
    // Tulis atomik supaya polling tidak membaca file setengah jadi
    $path = job_path($job['id']);
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0666);
    rename($tmp, $path);
}

function update_job(string $id, array $changes): array
{
    $job = read_job($id) ?? ['id' => $id];
    $job = array_merge($job, $changes, ['updated_at' => time()]);
    write_job($job);
    return $job;
}

function safe_filename(string $name): string
{
    $name = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name), " .");
    if (mb_strlen($name) > 150) {
        $name = trim(mb_substr($name, 0, 150));
    }
    return $name !== '' ? $name : 'video';
}

function unique_path(string $dir, string $base, string $ext): string
{
    // Nama file langsung "dipesan" (dibuat kosong) supaya dua job paralel tidak dapat nama yang sama
    for ($i = 1; ; $i++) {
        $path = $i === 1 ? "$dir/$base.$ext" : "$dir/$base ($i).$ext";
        $fh = @fopen($path, 'x');
        if ($fh) {
            fclose($fh);
            @chmod($path, 0666);
            return $path;
        }
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** Ikon tombol dari sprite assets/icons.svg (pasangan PHP untuk ic() di common.js). */
function icon(string $name): string
{
    return '<svg class="i" aria-hidden="true"><use href="assets/icons.svg?v=' . filemtime(APP_DIR . '/assets/icons.svg') . '#' . $name . '"/></svg>';
}

/* ---------- Pengaturan aplikasi (storage/settings.json) ---------- */

/** Pilihan kompresi hasil clip. Ukuran = perkiraan per menit clip 2K 60fps (hasil tes VMAF). */
const CLIP_CODECS = [
    'hevc' => ['name' => 'Hemat maksimal · HEVC', 'tag' => 'Rekomendasi untuk Mac',
        'desc' => 'File sekitar 9x lebih kecil, ketajaman tetap sama di mata. Lancar di Mac, iPhone, CapCut dan upload TikTok/IG/YouTube. Sebagian PC Windows butuh codec HEVC tambahan.',
        'size' => '± 40 MB / menit'],
    'h264' => ['name' => 'Seimbang · H.264', 'tag' => 'Rekomendasi untuk Windows',
        'desc' => 'File sekitar 3x lebih kecil, ketajaman tetap sama di mata. Bisa diputar dan diedit di semua perangkat, termasuk Windows.',
        'size' => '± 120 MB / menit'],
    'max' => ['name' => 'Kualitas maksimal · H.264', 'tag' => 'Untuk editing lanjut',
        'desc' => 'Bitrate sangat tinggi, cocok kalau clip masih diolah lagi di After Effects atau Premiere. File paling besar.',
        'size' => '± 390 MB / menit'],
];

function read_settings(): array
{
    $s = json_decode((string) @file_get_contents(STORAGE_DIR . '/settings.json'), true);
    return (is_array($s) ? $s : []) + ['clip_codec' => 'hevc'];
}

function write_settings(array $changes): array
{
    $s = array_merge(read_settings(), $changes);
    file_put_contents(STORAGE_DIR . '/settings.json', json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $s;
}

/**
 * Encoder hasil akhir clip sesuai pengaturan kompresi: [hardware (VideoToolbox / NVENC), cadangan software].
 * $mbps = bitrate kualitas maksimal dari video_bitrate(); mode lain memakai kualitas konstan / bitrate hemat.
 */
function clip_encoders(int $mbps, ?string $codec = null): array
{
    $codec ??= read_settings()['clip_codec'];
    if ($codec === 'hevc') {
        return venc_list(['-c:v hevc_videotoolbox -tag:v hvc1 -q:v 65', '-c:v libx265 -crf 20 -preset fast -tag:v hvc1']);
    }
    if ($codec === 'h264') {
        $lean = max(4, (int) round($mbps * 0.3)); // 2K 60fps: 53 -> 16 Mbps (VMAF 97,7)
        return venc_list(["-c:v h264_videotoolbox -profile:v high -b:v {$lean}M", '-c:v libx264 -crf 18 -preset medium -profile:v high']);
    }
    return venc_list(["-c:v h264_videotoolbox -profile:v high -b:v {$mbps}M", '-c:v libx264 -crf 16 -preset medium -profile:v high']);
}

/* ---------- Konsep clip (modal "Atur clip"): fokus momen yang dicari AI, boleh pilih lebih dari satu ---------- */
// key => [label, penjelasan untuk user, instruksi untuk AI]
const CLIP_CONCEPTS = [
    'podcast' => [
        'bijak' => ['Bijak & quotable', 'Kata-kata bermakna yang enak dikutip',
            'kalimat bijak, nasihat hidup, atau quote yang sangat quotable dan enak dibagikan ulang'],
        'viral' => ['Statement viral', 'Pendapat berani, kontroversial, bikin debat',
            'pernyataan tegas, hot take, opini kontroversial, atau fakta mengejutkan yang memancing komentar & debat'],
        'lucu' => ['Momen lucu', 'Candaan, celetukan, tawa',
            'momen lucu: candaan, celetukan spontan, roasting, atau saat pembicara & host tertawa lepas'],
        'emosional' => ['Emosional', 'Cerita menyentuh, haru, jujur',
            'momen emosional dan menyentuh: cerita personal yang jujur, perjuangan, kehilangan, atau momen haru'],
        'edukasi' => ['Insight & edukasi', 'Tips, fakta, cara, data',
            'insight praktis dan edukatif: tips, langkah-langkah, data/fakta, atau penjelasan yang membuka wawasan'],
        'kisah' => ['Kisah & pengalaman', 'Storytelling yang bikin penasaran',
            'kisah atau pengalaman pribadi dengan alur jelas (awal-konflik-akhir) yang bikin penasaran sampai akhir'],
        'motivasi' => ['Motivasi', 'Membakar semangat & mindset',
            'momen motivasi yang membakar semangat, mengubah mindset, atau mendorong penonton bertindak'],
    ],
    'gaming' => [
        'lucu' => ['Lucu & ngakak', 'Tawa pecah, celetukan, absurd',
            'momen lucu dan ngakak: tawa lepas, celetukan, kejadian absurd, bug/glitch konyol'],
        'tegang' => ['Tegang & jumpscare', 'Deg-degan, kabur, kaget',
            'momen menegangkan: dikejar, darah tipis, detik-detik terakhir, jumpscare, panik'],
        'clutch' => ['Skill & clutch', 'Permainan jago, comeback',
            'momen skill tinggi: clutch, comeback, outplay, keputusan nekat yang berhasil'],
        'fail' => ['Fail & apes', 'Gagal konyol, nasib sial',
            'momen fail: gagal konyol, nasib sial berturut-turut, salah langkah yang fatal tapi kocak'],
        'reaksi' => ['Reaksi heboh', 'Streamer teriak, rage, kaget',
            'reaksi streamer yang heboh: teriak, rage, kaget berlebihan, selebrasi besar'],
        'cerita' => ['Plot twist & cerita', 'Momen cerita game yang mengejutkan',
            'momen alur cerita game: plot twist, adegan dramatis, keputusan penting, ending mengejutkan'],
    ],
];

/** Konsep yang valid untuk style tertentu (urutan sesuai daftar). */
function clip_concepts_pick(string $style, array $keys): array
{
    $all = CLIP_CONCEPTS[$style === 'gaming' ? 'gaming' : 'podcast'];
    return array_values(array_filter(array_keys($all), fn($k) => in_array($k, $keys, true)));
}

/* ---------- Gaya caption (modal "Atur clip"): satu gaya untuk judul, hook layar & caption semua clip ---------- */
// key => [label, penjelasan untuk user, instruksi untuk AI]. 'auto' = nada mengikuti jenis tiap clip.
const CAPTION_STYLES = [
    'auto' => ['Otomatis', 'Ikuti jenis tiap clip', ''],
    'entertain' => ['Entertain', 'Kocak, playful, ajak tag teman',
        'menghibur dan playful: bercanda, relate, sindiran ringan, emoji 😂🤣😭, ajak tag teman. Hindari nada menggurui atau terlalu serius'],
    'bijak' => ['Bijak', 'Reflektif, quote, menenangkan',
        'bijak dan reflektif: tenang, dalam, seperti kutipan yang enak disimpan; kalimat singkat yang bikin merenung'],
    'provokatif' => ['Provokatif', 'Pancing debat & komentar',
        'provokatif tapi tidak menghina: pernyataan tegas yang memancing pro-kontra, selalu akhiri caption dengan pertanyaan ke penonton'],
    'story' => ['Storytelling', 'Bikin penasaran sampai akhir',
        'storytelling: buka dengan situasi atau konflik singkat yang bikin penasaran, jangan bocorkan akhirnya, ajak nonton sampai habis'],
    'edukatif' => ['Edukatif', 'Manfaat konkret, ajak simpan',
        'edukatif: janjikan manfaat atau insight konkret, bahasa jelas dan to the point, ajak simpan / share ke teman yang butuh'],
    'hype' => ['Hype', 'Energik, heboh, penuh semangat',
        'hype dan energik: kalimat pendek bertenaga, ekspresif, emoji 🔥😱, terasa heboh dan seru'],
    'santai' => ['Santai gaul', 'Bahasa tongkrongan, akrab',
        'santai dan gaul: seperti ngobrol dengan teman (pakai "gue/lo" atau "aku/kamu" yang akrab), tidak baku, natural'],
    'formal' => ['Profesional', 'Rapi, kredibel, tanpa slang',
        'profesional: bahasa rapi dan kredibel, tanpa slang berlebihan, emoji seperlunya; cocok untuk akun edukasi / bisnis'],
];

/* ---------- Clip campaign brand (modal "Atur clip"): minta AI menyertakan clip yang membahas brand / topik terkaitnya ---------- */
// key => [label, topik terkait bawaan (bisa diubah user di modal)]
const BRAND_CATEGORIES = [
    'skincare' => ['Skincare & kecantikan', ['kulit', 'jerawat', 'wajah', 'glowing', 'penampilan', 'percaya diri', 'self-care', 'rutinitas pagi/malam', 'stres & kurang tidur', 'penuaan']],
    'food' => ['Makanan & minuman', ['makanan', 'kuliner', 'masak', 'lapar', 'kopi', 'jajan', 'diet', 'makan bareng keluarga', 'nongkrong']],
    'finance' => ['Keuangan & investasi', ['uang', 'gaji', 'nabung', 'investasi', 'utang', 'cicilan', 'bisnis', 'pensiun', 'gaya hidup', 'kebebasan finansial']],
    'health' => ['Kesehatan & olahraga', ['kesehatan', 'olahraga', 'gym', 'tidur', 'pola makan', 'berat badan', 'stres', 'energi', 'kesehatan mental']],
    'tech' => ['Gadget & aplikasi', ['HP', 'gadget', 'aplikasi', 'internet', 'media sosial', 'kerja remote', 'produktivitas', 'AI', 'foto & video']],
    'fashion' => ['Fashion', ['pakaian', 'outfit', 'gaya', 'penampilan', 'belanja', 'brand lokal', 'percaya diri', 'first impression']],
    'education' => ['Edukasi & karier', ['belajar', 'kuliah', 'skill', 'karier', 'kerja', 'interview', 'bahasa Inggris', 'sertifikat', 'pindah kerja']],
    'travel' => ['Travel & transportasi', ['liburan', 'jalan-jalan', 'mudik', 'perjalanan', 'hotel', 'pesawat', 'macet', 'kendaraan']],
    'other' => ['Lainnya', []],
];
const BRAND_MODES = ['hard' => 'Sebut brand langsung (hard sell)', 'soft' => 'Bahas topik terkait (soft sell)'];

/** Bersihkan pengaturan campaign brand dari modal; null = tidak aktif. */
function brand_clean(array $in): ?array
{
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    if (empty($in['enabled']) || $name === '') {
        return null;
    }
    $topics = [];
    foreach (array_slice((array) ($in['topics'] ?? []), 0, 20) as $t) {
        $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t)), 0, 40);
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $topics), true)) {
            $topics[] = $t;
        }
    }
    $modes = array_values(array_intersect(array_keys(BRAND_MODES), (array) ($in['modes'] ?? [])));
    preg_match_all('/[#@][\p{L}\p{N}_.]+/u', (string) ($in['tags'] ?? ''), $m);
    return [
        'name' => $name,
        'category' => isset(BRAND_CATEGORIES[$in['category'] ?? '']) ? $in['category'] : 'other',
        'topics' => $topics,
        'modes' => $modes ?: array_keys(BRAND_MODES),
        'all' => !empty($in['all']), // true = semua clip harus terkait brand
        'min' => max(1, min(20, (int) ($in['min'] ?? 2))),
        'brief' => mb_substr(trim((string) ($in['brief'] ?? '')), 0, 1500),
        'tags' => array_slice(array_values(array_unique(array_map(fn($t) => rtrim($t, '.'), $m[0]))), 0, 10),
    ];
}
