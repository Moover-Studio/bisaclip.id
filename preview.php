<?php
// Preview untuk hasil analisa: frame gambar + video pendek resolusi rendah (dipanggil dari api.php)

define('PREVIEW_DIR', APP_DIR . '/previews');
define('STREAM_CACHE_DIR', STORAGE_DIR . '/streams');

function preview_dir(string $id): string
{
    $dir = PREVIEW_DIR . '/' . $id;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * URL stream ringan (≤480p) untuk preview, di-cache sampai mendekati waktu kedaluwarsa.
 * Dikunci dengan flock supaya banyak request gambar sekaligus hanya memanggil yt-dlp sekali.
 */
function preview_streams(string $id, string $url, bool $refresh = false): array
{
    if (!is_dir(STREAM_CACHE_DIR)) {
        @mkdir(STREAM_CACHE_DIR, 0777, true);
    }
    $cache = STREAM_CACHE_DIR . "/$id.json";
    $lock = fopen("$cache.lock", 'c');
    @chmod("$cache.lock", 0666);
    flock($lock, LOCK_EX);
    try {
        $data = json_decode((string) @file_get_contents($cache), true);
        if (!$refresh && is_array($data) && ($data['expire'] ?? 0) > time() + 600) {
            return $data;
        }
        $cmd = escapeshellarg(YTDLP) . ' -j --no-playlist --no-warnings'
            . ' -f ' . escapeshellarg('bv*[height<=480][protocol=https]+ba[protocol=https]/b[height<=480][protocol=https]/bv*[height<=480]+ba/b')
            . ' -S ' . escapeshellarg('hdr:SDR,res,fps') . ' --socket-timeout 20 -- ' . escapeshellarg($url);
        [, $raw] = run_with_timeout($cmd, 60);
        $info = null;
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '{')) {
                $info = json_decode($line, true);
            }
        }
        if (!is_array($info)) {
            throw new RuntimeException('Gagal mengambil stream preview.');
        }
        $data = ['video' => null, 'audio' => null];
        $expires = [];
        foreach ($info['requested_formats'] ?? [$info] as $f) {
            $entry = ['url' => $f['url'], 'headers' => $f['http_headers'] ?? []];
            if (($f['vcodec'] ?? 'none') !== 'none' && !$data['video']) {
                $data['video'] = $entry;
            }
            if (($f['acodec'] ?? 'none') !== 'none' && !$data['audio']) {
                $data['audio'] = $entry;
            }
            parse_str((string) parse_url($f['url'], PHP_URL_QUERY), $q);
            if (!empty($q['expire'])) {
                $expires[] = (int) $q['expire'];
            }
        }
        $data['expire'] = $expires ? min($expires) : time() + 3600;
        if (!$data['video']) {
            throw new RuntimeException('Stream video preview tidak ditemukan.');
        }
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_SLASHES));
        @chmod($cache, 0666);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ff_input(array $stream, float $t): string
{
    $headers = '';
    foreach ($stream['headers'] as $k => $v) {
        $headers .= "$k: $v\r\n";
    }
    return ' -ss ' . number_format($t, 2, '.', '') . ($headers ? ' -headers ' . escapeshellarg($headers) : '')
        . ' -rw_timeout 20000000 -i ' . escapeshellarg($stream['url']);
}

/** Jalankan perintah ffmpeg yang memakai URL stream; kalau gagal (URL kedaluwarsa/403), ambil URL baru sekali. */
function with_streams(string $id, string $url, string $out, callable $build): bool
{
    foreach ([false, true] as $refresh) {
        $streams = preview_streams($id, $url, $refresh);
        foreach ($build($streams) as $cmd) {
            exec($cmd . ' ' . escapeshellarg($out) . ' 2>&1', $o, $code);
            clearstatcache();
            if ($code === 0 && is_file($out) && filesize($out) > 0) {
                @chmod($out, 0666);
                return true;
            }
            @unlink($out);
        }
    }
    return false;
}

function make_frame(string $id, string $url, float $t): string
{
    $out = preview_dir($id) . '/f_' . number_format($t, 1, '.', '') . '.jpg';
    if (is_file($out)) {
        return $out;
    }
    $tmp = $out . '.' . getmypid() . '.jpg';
    // Video sumber lokal sudah ada: ambil frame langsung (±0,1 detik)
    if ($src = local_source($id, $t, $t + 0.1)) {
        exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . number_format($t - $src['shift'], 2, '.', '')
            . ' -i ' . escapeshellarg($src['path']) . ' -frames:v 1 -vf scale=480:-2 -q:v 4 ' . escapeshellarg($tmp), $o, $code);
        if ($code === 0 && is_file($tmp)) {
            @chmod($tmp, 0666);
            rename($tmp, $out);
            return $out;
        }
    }
    $ok = with_streams($id, $url, $tmp, fn($s) => [
        escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . ff_input($s['video'], $t)
        . ' -frames:v 1 -vf scale=480:-2 -q:v 4',
    ]);
    if (!$ok) {
        throw new RuntimeException('Gagal membuat frame.');
    }
    rename($tmp, $out);
    return $out;
}

function make_preview_video(string $id, string $url, float $s, float $e): string
{
    $name = 'v_' . number_format($s, 1, '.', '') . '_' . number_format($e, 1, '.', '') . '.mp4';
    $out = preview_dir($id) . '/' . $name;
    if (is_file($out)) {
        return $out;
    }
    $dur = number_format($e - $s, 2, '.', '');
    $tmp = $out . '.' . getmypid() . '.mp4';
    if ($src = local_source($id, $s, $e)) {
        foreach (venc_list(['-c:v h264_videotoolbox -b:v 1500k', '-c:v libx264 -preset veryfast -crf 26']) as $enc) {
            exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . number_format($s - $src['shift'], 2, '.', '')
                . ' -i ' . escapeshellarg($src['path']) . " -t $dur -map 0:v:0 -map '0:a:0?' -vf scale=-2:480 -pix_fmt yuv420p"
                . " -c:a aac -b:a 128k $enc -movflags +faststart " . escapeshellarg($tmp), $o, $code);
            if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
                @chmod($tmp, 0666);
                rename($tmp, $out);
                return $out;
            }
        }
    }
    $ok = with_streams($id, $url, $tmp, function ($st) use ($s, $dur) {
        $in = ff_input($st['video'], $s);
        $map = '-map 0:v:0';
        if ($st['audio'] && $st['audio']['url'] !== $st['video']['url']) {
            $in .= ff_input($st['audio'], $s);
            $map .= ' -map 1:a:0';
        } else {
            $map .= " -map '0:a:0?'";
        }
        $base = escapeshellarg(FFMPEG) . " -hide_banner -nostdin -y -loglevel error$in -t $dur $map"
            . ' -vf scale=-2:480 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart';
        return array_map(fn($e) => "$base $e", venc_list(['-c:v h264_videotoolbox -b:v 1500k', '-c:v libx264 -preset veryfast -crf 26']));
    });
    if (!$ok) {
        throw new RuntimeException('Gagal membuat preview video.');
    }
    rename($tmp, $out);
    return $out;
}

function delete_previews(string $id): void
{
    rrmdir(PREVIEW_DIR . '/' . $id);
    @unlink(STREAM_CACHE_DIR . "/$id.json");
    @unlink(STREAM_CACHE_DIR . "/$id.json.lock");
}
