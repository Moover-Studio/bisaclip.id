<?php
// Klien Gemini API (dipakai oleh analyzer.php)

const GEMINI_BASE = 'https://generativelanguage.googleapis.com';
// Dicoba berurutan: kalau model pertama sibuk / kuota habis, pakai berikutnya.
// Semua bisa dipakai gratis dengan API key free tier (dicek 13 Okt 2026).
const GEMINI_MODELS = [
    'gemini-3.8-flash',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
    'gemini-3.5-flash',
    'gemini-flash-latest',
    'gemini-3-flash-preview',
    'gemini-3.1-flash-lite',
];

function gemini_key(): string
{
    $key = gemini_api_key();
    if ($key === '') {
        throw new RuntimeException('API key Gemini belum diisi. Buka Pengaturan → API key.');
    }
    return $key;
}

/**
 * Request HTTP ke Gemini. $cancelFlag: kalau file ini muncul, request dihentikan.
 * Mengembalikan [status_code, body, headers].
 */
/*
 * Kuota harian free tier (per model): model yang kuotanya habis dicatat sampai waktu reset,
 * jadi permintaan berikutnya langsung memakai model lain tanpa menunggu.
 */
const GEMINI_QUOTA_FILE = STORAGE_DIR . '/gemini_quota.json';

/** Model yang kuota hariannya habis: [model => waktu reset (unix)] */
function gemini_quota_blocked(): array
{
    $q = json_decode((string) @file_get_contents(GEMINI_QUOTA_FILE), true);
    return array_filter(is_array($q) ? $q : [], fn($until) => $until > time());
}

function gemini_quota_block(string $model, int $until): void
{
    $q = gemini_quota_blocked();
    $q[$model] = $until;
    @file_put_contents(GEMINI_QUOTA_FILE, json_encode($q), LOCK_EX);
    @chmod(GEMINI_QUOTA_FILE, 0666);
}

/** Info error 429: [kuota harian?, detik tunggu dari server] */
function gemini_429_info(?array $res): array
{
    $delay = 0;
    $daily = false;
    foreach ($res['error']['details'] ?? [] as $d) {
        if (!empty($d['retryDelay'])) {
            $delay = (int) ceil((float) $d['retryDelay']);
        }
        foreach ($d['violations'] ?? [] as $v) {
            if (stripos((string) ($v['quotaId'] ?? ''), 'PerDay') !== false) {
                $daily = true;
            }
        }
    }
    return [$daily || $delay > 300, $delay];
}

function gemini_http(string $method, string $url, array $headers, ?string $body, string $cancelFlag = '', int $timeout = 600): array
{
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['x-goog-api-key: ' . gemini_key()], $headers),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($cancelFlag !== '') {
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, fn() => file_exists($cancelFlag) ? 1 : 0);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($cancelFlag !== '' && file_exists($cancelFlag)) {
        throw new Cancelled();
    }
    if ($res === false) {
        throw new RuntimeException('Koneksi ke layanan AI gagal. Periksa internet lalu coba lagi.');
    }
    return [$code, (string) $res, $respHeaders];
}

/**
 * Panggil generateContent dengan output JSON terstruktur.
 * Server sibuk (503/500) atau limit per menit (429): tunggu lalu coba lagi, kemudian pindah ke model berikutnya.
 * $onStatus menerima teks status untuk ditampilkan di UI.
 * Mengembalikan [data_array, model_yang_dipakai, usage].
 */
function gemini_generate(array $parts, array $schema, string $cancelFlag, ?callable $onStatus = null): array
{
    $payload = json_encode([
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
            'temperature' => 0.7,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $status = $onStatus ?? fn() => null;
    $errors = []; // kode error per model, untuk pesan akhir
    $blocked = gemini_quota_blocked();
    foreach (GEMINI_MODELS as $model) {
        if (isset($blocked[$model])) {
            $errors[$model] = 'daily'; // kuota harian habis (tercatat sebelumnya): lewati tanpa menunggu
            continue;
        }
        $next = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $status($attempt === 1 ? "Menganalisa dengan {$model}…" : ($next ?: "Server {$model} sibuk, mencoba lagi ($attempt/3)…"));
            [$code, $body] = gemini_http('POST', GEMINI_BASE . "/v1beta/models/$model:generateContent",
                ['Content-Type: application/json'], $payload, $cancelFlag);
            $res = json_decode($body, true);
            if ($code === 200) {
                $text = '';
                foreach ($res['candidates'][0]['content']['parts'] ?? [] as $p) {
                    if (empty($p['thought'])) {
                        $text .= $p['text'] ?? '';
                    }
                }
                $data = json_decode($text, true);
                if (is_array($data)) {
                    return [$data, $res['modelVersion'] ?? $model, $res['usageMetadata'] ?? []];
                }
                $errors[$model] = 'invalid';
                continue; // jawaban terpotong/tidak valid: ulangi
            }
            $errors[$model] = $code;
            if (!in_array($code, [429, 500, 503, 504], true)) {
                break; // 400/403/404: model tidak bisa dipakai, langsung ke model berikutnya
            }
            $delay = $attempt === 1 ? 4 : 10;
            $next = '';
            if ($code === 429) {
                [$daily, $wait] = gemini_429_info($res);
                if ($daily) {
                    // Kuota harian habis: catat sampai reset, langsung pindah model (menunggu tidak ada gunanya)
                    gemini_quota_block($model, time() + max(3600, $wait ?: 86400));
                    $errors[$model] = 'daily';
                    $status("Kuota harian {$model} habis, pindah ke model lain…");
                    break;
                }
                // Batas per menit: tunggu sesuai permintaan server
                $delay = min(60, max($delay, $wait));
                $next = "Batas per menit {$model} tercapai, menunggu {$delay} dtk…";
            }
            if ($attempt < 3) {
                for ($i = 0; $i < $delay; $i++) {
                    if (file_exists($cancelFlag)) {
                        throw new Cancelled();
                    }
                    sleep(1);
                }
            }
        }
    }

    $codes = array_values($errors);
    if ($codes && count(array_diff($codes, ['daily'])) === 0) {
        $reset = min(gemini_quota_blocked() ?: [time() + 86400]);
        $h = max(1, (int) round(($reset - time()) / 3600));
        $at = (new DateTime('@' . $reset))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i');
        throw new RuntimeException('Kuota harian Gemini (gratis) untuk semua model sudah habis. Bisa dipakai lagi sekitar pukul '
            . $at . " WIB (±$h jam lagi). Supaya tidak terbatas, aktifkan billing di Google AI Studio atau ganti API key di BisaClip.");
    }
    if ($codes && count(array_diff($codes, [500, 503, 504])) === 0) {
        throw new RuntimeException('Layanan AI sedang sibuk. Tunggu beberapa menit lalu klik "Coba lagi".');
    }
    if (in_array(429, $codes, true)) {
        throw new RuntimeException('Batas pemakaian AI sementara tercapai. Coba lagi beberapa saat lagi.');
    }
    $detail = implode(', ', array_map(fn($m, $c) => "$m: $c", array_keys($errors), $codes));
    throw new RuntimeException('Layanan AI gagal memproses video ini. Coba lagi nanti.');
}

/**
 * Upload file (audio) ke Gemini Files API, tunggu sampai siap dipakai.
 * Mengembalikan file uri.
 */
function gemini_upload(string $path, string $mime, string $cancelFlag): string
{
    $size = filesize($path);
    [$code, $body, $headers] = gemini_http('POST', GEMINI_BASE . '/upload/v1beta/files', [
        'X-Goog-Upload-Protocol: resumable',
        'X-Goog-Upload-Command: start',
        "X-Goog-Upload-Header-Content-Length: $size",
        "X-Goog-Upload-Header-Content-Type: $mime",
        'Content-Type: application/json',
    ], json_encode(['file' => ['display_name' => basename($path)]]), $cancelFlag);
    $uploadUrl = $headers['x-goog-upload-url'] ?? '';
    if ($code !== 200 || $uploadUrl === '') {
        throw new RuntimeException('Gagal mengirim audio ke layanan AI (' . $code . ').');
    }

    [$code, $body] = gemini_http('POST', $uploadUrl, [
        "Content-Length: $size",
        'X-Goog-Upload-Offset: 0',
        'X-Goog-Upload-Command: upload, finalize',
    ], file_get_contents($path), $cancelFlag);
    $file = json_decode($body, true)['file'] ?? null;
    if ($code !== 200 || !$file) {
        throw new RuntimeException('Gagal mengirim audio ke layanan AI (' . $code . ').');
    }

    // Tunggu file selesai diproses
    for ($i = 0; $i < 60 && ($file['state'] ?? '') === 'PROCESSING'; $i++) {
        sleep(3);
        [, $body] = gemini_http('GET', GEMINI_BASE . '/v1beta/' . $file['name'], [], null, $cancelFlag);
        $file = json_decode($body, true) ?: $file;
    }
    if (($file['state'] ?? '') !== 'ACTIVE') {
        throw new RuntimeException('Audio gagal diproses layanan AI.');
    }
    return $file['uri'];
}

/**
 * Permintaan Gemini singkat yang ditunggu user (mis. merapikan naskah suara): model lite dulu (paling cepat),
 * tiap model dibatasi 20 dtk, total ±40 dtk. Mengembalikan JSON hasil, atau null kalau semua gagal (pemanggil memakai cadangan).
 */
function gemini_quick(string $prompt, array $schema): ?array
{
    $payload = json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => [
        'responseMimeType' => 'application/json', 'responseSchema' => $schema, 'temperature' => 0.4,
    ]], JSON_UNESCAPED_UNICODE);
    $started = time();
    $blocked = gemini_quota_blocked();
    foreach (array_unique(['gemini-3.1-flash-lite', ...GEMINI_MODELS]) as $model) {
        if (isset($blocked[$model]) || time() - $started > 40) {
            continue;
        }
        try {
            [$code, $body] = gemini_http('POST', GEMINI_BASE . "/v1beta/models/$model:generateContent", ['Content-Type: application/json'], $payload, '', 20);
        } catch (Throwable $e) {
            continue;
        }
        $text = '';
        foreach (json_decode($body, true)['candidates'][0]['content']['parts'] ?? [] as $p) {
            $text .= empty($p['thought']) ? ($p['text'] ?? '') : '';
        }
        if ($code === 200 && is_array($res = json_decode($text, true))) {
            return $res;
        }
    }
    return null;
}
