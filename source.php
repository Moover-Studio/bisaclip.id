<?php
// Video sumber lokal per room analisa: diunduh sekali (1080p), lalu dipakai editor, preview & render
// supaya tidak perlu mengambil potongan video dari YouTube berulang kali.

define('SOURCES_DIR', STORAGE_DIR . '/sources');

function source_file(string $analysisId): string
{
    return SOURCES_DIR . "/$analysisId.mp4";
}

/** Job unduhan video sumber milik sebuah room analisa (atau null). */
function source_job(string $analysisId): ?array
{
    $room = valid_job_id($analysisId) ? read_job($analysisId) : null;
    return !empty($room['source_job']) ? read_job($room['source_job']) : null;
}

/*
 * Potongan sumber: setelah video sumber lengkap dihapus untuk menghemat disk, bagian di sekitar tiap clip
 * (rentang editor ±10 dtk) tetap disimpan sebagai salinan persis (tanpa encode ulang) di storage/sources/<room>/.
 * Edit & render clip room itu tetap memakai sumber lokal 1080p yang sama. 'from' = detik video asli di awal potongan.
 */
function source_parts_dir(string $analysisId): string
{
    return SOURCES_DIR . "/$analysisId";
}

/** ['w','h','fps','duration','parts' => [['file','from','to','bytes']]] atau [] kalau tidak ada. */
function source_parts(string $analysisId): array
{
    $d = json_decode((string) @file_get_contents(source_parts_dir($analysisId) . '/parts.json'), true);
    return is_array($d) && !empty($d['parts']) ? $d : [];
}

/**
 * Info video sumber lokal: [path, w, h, fps, duration, shift]; null kalau tidak ada.
 * Video lengkap dipakai kalau masih ada. Kalau sudah dihapus, potongan yang mencakup rentang [from, to] (detik video asli);
 * 'shift' = detik video asli di awal file (dikurangkan dari posisi -ss).
 */
function local_source(string $analysisId, ?float $from = null, ?float $to = null): ?array
{
    $job = source_job($analysisId);
    $path = source_file($analysisId);
    if ($job && $job['status'] === 'done' && is_file($path)) {
        return ['path' => $path, 'w' => (int) ($job['width'] ?? 1920), 'h' => (int) ($job['height'] ?? 1080),
            'fps' => (float) ($job['fps'] ?? 30), 'duration' => (float) ($job['duration'] ?? 0), 'shift' => 0.0];
    }
    $meta = $from !== null ? source_parts($analysisId) : [];
    $to = $to ?? $from;
    foreach ($meta['parts'] ?? [] as $p) {
        $file = source_parts_dir($analysisId) . '/' . $p['file'];
        if ($from >= $p['from'] - 0.001 && $to <= $p['to'] + 0.05 && is_file($file)) {
            return ['path' => $file, 'w' => (int) $meta['w'], 'h' => (int) $meta['h'], 'fps' => (float) $meta['fps'],
                'duration' => (float) $meta['duration'], 'shift' => (float) $p['from']];
        }
    }
    return null;
}

/** Format stream "lokal" yang bisa langsung dipakai fungsi clip_inputs() di clip.php. */
function local_format(array $src): array
{
    return ['url' => $src['path'], 'format_id' => 'local', 'width' => $src['w'], 'height' => $src['h'],
        'fps' => $src['fps'], 'vcodec' => 'h264', 'acodec' => 'aac', 'http_headers' => [], 'shift' => (float) ($src['shift'] ?? 0)];
}

/**
 * Simpan potongan sumber untuk rentang [[from, to], ...] (detik video asli) dari video sumber lengkap.
 * Rentang yang berdekatan digabung. Tiap potongan dimulai tepat di keyframe sebelum 'from' lalu disalin tanpa encode ulang,
 * jadi kualitasnya sama persis. Melempar exception kalau gagal (video sumber jangan dihapus).
 */
function make_source_parts(string $analysisId, array $ranges, ?array $src = null): array
{
    // $src: sumber yang dipotong (bawaan: video sumber lengkap; untuk kemas ulang: potongan lama yang dimulai dari detik 0)
    $src ??= local_source($analysisId);
    if (!$src || $src['shift'] > 0) {
        throw new RuntimeException('Video sumber lengkap tidak ada.');
    }
    $total = $src['duration'] ?: 1e9;
    $ranges = array_values(array_filter(array_map(fn($r) => [max(0.0, (float) $r[0]), min($total, (float) $r[1])], $ranges), fn($r) => $r[1] - $r[0] > 0.5));
    usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($ranges as $r) {
        if ($merged && $r[0] <= $merged[count($merged) - 1][1] + 3) {
            $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $r[1]);
        } else {
            $merged[] = $r;
        }
    }
    if (!$merged) {
        throw new RuntimeException('Tidak ada rentang clip yang perlu disimpan.');
    }
    $dir = source_parts_dir($analysisId);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
    }
    $parts = [];
    foreach ($merged as [$a, $b]) {
        // Keyframe terakhir sebelum awal rentang: potongan tanpa encode ulang harus dimulai di keyframe
        $kfs = (string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams v:0 -skip_frame nokey -show_entries frame=pts_time -of csv=p=0'
            . ' -read_intervals ' . escapeshellarg(sprintf('%.3f%%%.3f', max(0, $a - 30), $a + 0.05)) . ' ' . escapeshellarg($src['path']));
        // Baris ffprobe bisa diakhiri koma ("495.916667,"), jadi ambil angkanya saja
        $kf = null;
        preg_match_all('/\d+(?:\.\d+)?/', $kfs, $mm);
        foreach ($mm[0] as $v) {
            if ((float) $v <= $a + 0.001) {
                $kf = max($kf ?? 0, (float) $v);
            }
        }
        $kf = $kf ?? 0.0;
        $file = sprintf('p_%d.mp4', round($kf * 1000));
        // Ditulis ke file sementara dulu: sumber yang dipotong bisa berupa potongan lama dengan nama yang sama
        $out = "$dir/$file.new";
        exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . sprintf('%.3f', $kf + 0.001) . ' -i ' . escapeshellarg($src['path'])
            . ' -t ' . sprintf('%.3f', $b - $kf) . " -map 0:v:0 -map '0:a:0?' -c copy -avoid_negative_ts make_zero -movflags +faststart -f mp4 " . escapeshellarg($out), $o, $code);
        $dur = (float) shell_exec(escapeshellarg(FFPROBE) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($out));
        if ($code !== 0 || !is_file($out) || $dur < min($b - $kf, $total - $kf) - 1) {
            throw new RuntimeException('Gagal menyimpan potongan video sumber.');
        }
        @chmod($out, 0666);
        $parts[] = ['file' => $file, 'from' => round($kf, 3), 'to' => round($kf + $dur, 3), 'bytes' => filesize($out)];
    }
    // Potongan lama yang tidak dipakai lagi dibuang, lalu potongan baru menggantikan
    foreach (glob("$dir/p_*.mp4") ?: [] as $f) {
        if (!in_array(basename($f), array_column($parts, 'file'), true)) {
            @unlink($f);
        }
    }
    foreach ($parts as $p) {
        rename("$dir/{$p['file']}.new", "$dir/{$p['file']}");
    }
    $meta = ['w' => $src['w'], 'h' => $src['h'], 'fps' => $src['fps'], 'duration' => $src['duration'], 'parts' => $parts];
    file_put_contents("$dir/parts.json", json_encode($meta), LOCK_EX);
    @chmod("$dir/parts.json", 0666);
    return $meta;
}

/**
 * Kemas ulang potongan sumber yang terlalu besar (versi lama: satu file p_0 dari detik 0 sampai clip terakhir)
 * menjadi potongan kecil di sekitar tiap clip. Mengembalikan [byte sebelum, byte sesudah] atau null kalau tidak perlu.
 */
function repack_source_parts(string $analysisId, array $ranges): ?array
{
    $meta = source_parts($analysisId);
    $files = array_values(array_unique(array_column($meta['parts'] ?? [], 'file')));
    if (count($files) !== 1 || ($meta['parts'][0]['from'] ?? 1) > 0 || is_file(source_file($analysisId))) {
        return null;
    }
    $path = source_parts_dir($analysisId) . '/' . $files[0];
    if (!is_file($path)) {
        return null;
    }
    $before = filesize($path);
    $src = ['path' => $path, 'w' => (int) $meta['w'], 'h' => (int) $meta['h'], 'fps' => (float) $meta['fps'],
        'duration' => max(array_column($meta['parts'], 'to')), 'shift' => 0.0];
    $new = make_source_parts($analysisId, $ranges, $src);
    $new['duration'] = (float) $meta['duration'];
    file_put_contents(source_parts_dir($analysisId) . '/parts.json', json_encode($new), LOCK_EX);
    clearstatcache();
    return [$before, array_sum(array_column($new['parts'], 'bytes'))];
}

/**
 * Tunggu video sumber selesai diunduh (dipakai worker persiapan/render).
 * Mengembalikan info sumber lokal, atau null kalau tidak ada / gagal (pakai link sebagai cadangan).
 */
function wait_local_source(string $analysisId, string $jobId, string $cancelFlag, ?float $from = null, ?float $to = null): ?array
{
    $shown = -1;
    while (true) {
        if ($src = local_source($analysisId, $from, $to)) {
            return $src;
        }
        $job = source_job($analysisId);
        if (!$job || !in_array($job['status'], ['queued', 'downloading', 'converting'], true)) {
            return null;
        }
        if (file_exists($cancelFlag)) {
            throw new Cancelled();
        }
        $pct = (int) ($job['progress'] ?? 0);
        if ($pct !== $shown) {
            update_job($jobId, ['stage' => "Menunggu video sumber diunduh… $pct%", 'progress' => $pct]);
            $shown = $pct;
        }
        sleep(1);
    }
}

/** Worker: unduh video sumber 1080p (H.264 kalau tersedia, supaya cepat diproses). */
function run_source_download(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    if (!is_dir(SOURCES_DIR)) {
        @mkdir(SOURCES_DIR, 0777, true);
        @chmod(SOURCES_DIR, 0777);
    }
    // Percobaan 1: aria2 (8 koneksi paralel, lebih tahan pembatasan kecepatan per koneksi dari YouTube).
    // Percobaan berikutnya: pengunduh bawaan yt-dlp, dengan jeda supaya tidak memperparah pembatasan.
    $aria = is_file(BIN_DIR . '/aria2c');
    $attempts = $aria ? ['aria2', 'native', 'native'] : ['native', 'native'];
    $code = 1;
    $tail = [];
    foreach ($attempts as $n => $how) {
        if ($n > 0) {
            update_job($id, ['stage' => 'Unduhan terputus, mencoba lagi (' . ($n + 1) . '/' . count($attempts) . ')…']);
            for ($i = 0; $i < 10 * $n; $i++) {
                if (file_exists($cancelFlag)) {
                    throw new Cancelled();
                }
                sleep(1);
            }
            foreach (glob("$tmpDir/source*") as $f) {
                @unlink($f);
            }
        }
        update_job($id, ['status' => 'downloading', 'stage' => 'Mengunduh video sumber…', 'progress' => 0]);
        $downloader = $how === 'aria2'
            ? ' --downloader aria2c --downloader-args ' . escapeshellarg('aria2c:-x 8 -s 8 -k 2M --summary-interval=1 --console-log-level=warn')
            : ' --http-chunk-size 10M --retries 5';
        $part = 0;
        $lastWrite = 0;
        [$code, $tail] = run(escapeshellarg(YTDLP) . ' --no-playlist --newline --no-warnings --no-mtime --concurrent-fragments 4'
            . ' -f ' . escapeshellarg('bv*[height<=1080][vcodec^=avc1]+ba[ext=m4a]/bv*[height<=1080]+ba/b[height<=1080]/b')
            . ' --merge-output-format mp4' . $downloader
            . ' --progress-template ' . escapeshellarg('download:[prog]%(progress._percent_str)s')
            . ' -o ' . escapeshellarg("$tmpDir/source.%(ext)s") . ' -- ' . escapeshellarg($job['url']),
            function (string $line) use ($id, &$part, &$lastWrite) {
                if (str_contains($line, '[download] Destination:')) {
                    $part++;
                    return;
                }
                if (str_contains($line, '[Merger]')) {
                    update_job($id, ['stage' => 'Menggabungkan video & audio…', 'progress' => 99]);
                    return;
                }
                // Progres dari yt-dlp ("[prog] 45.2%") atau dari aria2 ("[#abc 120MiB/340MiB(35%) ...]")
                $pct = null;
                if (str_starts_with($line, '[prog]')) {
                    $pct = (float) substr($line, 6);
                } elseif (preg_match('/\((\d+)%\)/', $line, $m)) {
                    $pct = (float) $m[1];
                }
                if ($pct !== null && microtime(true) - $lastWrite > 1) {
                    // Video ±90% ukuran, audio ±10%: gabungkan jadi satu angka progres
                    $total = $part <= 1 ? $pct * 0.9 : 90 + $pct * 0.1;
                    update_job($id, ['progress' => round(min(99, $total), 1)]);
                    $lastWrite = microtime(true);
                }
            }, $cancelFlag, 3600);
        if ($code === 0) {
            break;
        }
    }
    $files = array_filter(glob("$tmpDir/source.*"), fn($f) => !preg_match('/\.(part|ytdl|temp)$|\.f\d+\./', $f));
    $file = reset($files);
    if ($code !== 0 || !$file) {
        throw new RuntimeException(error_from($tail));
    }
    $dest = source_file($job['analysis_id']);
    rename($file, $dest);
    @chmod($dest, 0666);
    $p = probe($dest);
    update_job($id, [
        'status' => 'done', 'stage' => 'Selesai', 'progress' => 100,
        'size' => filesize($dest), 'width' => $p['width'], 'height' => $p['height'], 'fps' => $p['fps'],
        'duration' => $p['duration'], 'vcodec' => $p['vcodec'],
    ]);
}
