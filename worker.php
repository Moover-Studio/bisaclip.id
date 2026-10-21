<?php
// Proses latar belakang: unduh dengan yt-dlp, lalu konversi dengan ffmpeg.
// Dipanggil oleh api.php: php worker.php <job_id>

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/config.php';

umask(0000);
set_time_limit(0);
setup_env();
ensure_dirs();

$id = $argv[1] ?? '';
if (!valid_job_id($id) || !($job = read_job($id))) {
    exit("Job tidak valid\n");
}

$tmpDir = TMP_DIR . '/' . $id;
$cancelFlag = JOBS_DIR . "/$id.cancel";

class Cancelled extends Exception {}

/**
 * Jalankan perintah, panggil $onLine untuk tiap baris output.
 * $timeout (detik, 0 = tanpa batas): kalau lewat, proses dihentikan supaya job tidak macet selamanya.
 * Mengembalikan [exit_code, 30 baris terakhir output].
 */
function run(string $cmd, callable $onLine, string $cancelFlag, int $timeout = 0): array
{
    $deadline = $timeout > 0 ? microtime(true) + $timeout : INF;
    $proc = proc_open('exec ' . $cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [1, ['Gagal menjalankan proses']];
    }
    $out = $pipes[1];
    stream_set_blocking($out, false);
    $tail = [];
    $buffer = '';

    while (true) {
        if (file_exists($cancelFlag)) {
            proc_terminate($proc, 15);
            fclose($out);
            proc_close($proc);
            throw new Cancelled();
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($out);
            proc_close($proc);
            throw new RuntimeException("Waktu habis ($timeout detik). Periksa koneksi internet lalu coba lagi.");
        }
        $read = [$out];
        $w = $e = null;
        if (stream_select($read, $w, $e, 0, 500000) > 0) {
            $chunk = fread($out, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($out)) {
                    break;
                }
                continue;
            }
            $buffer .= $chunk;
            // yt-dlp/ffmpeg kadang memakai \r untuk update progress
            $lines = preg_split('/\r\n|\r|\n/', $buffer);
            $buffer = array_pop($lines);
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $onLine($line);
                $tail[] = $line;
                if (count($tail) > 30) {
                    array_shift($tail);
                }
            }
        } elseif (feof($out)) {
            break;
        }
    }
    if ($buffer !== '') {
        $onLine($buffer);
        $tail[] = $buffer;
    }
    fclose($out);
    $code = proc_close($proc);
    return [$code, $tail];
}

function error_from(array $tail): string
{
    foreach (array_reverse($tail) as $line) {
        if (stripos($line, 'ERROR') !== false) {
            return trim(preg_replace('/^.*ERROR:\s*/', '', $line));
        }
    }
    return trim((string) end($tail)) ?: 'Terjadi kesalahan tidak diketahui.';
}

function probe(string $file): array
{
    $cmd = escapeshellarg(FFPROBE) . ' -v error -print_format json -show_streams -show_format '
        . escapeshellarg($file);
    $data = json_decode((string) shell_exec($cmd), true) ?: [];
    $info = ['vcodec' => null, 'acodec' => null, 'width' => 0, 'height' => 0, 'fps' => 0,
        'duration' => (float) ($data['format']['duration'] ?? 0), 'hdr' => false];
    foreach ($data['streams'] ?? [] as $s) {
        if ($s['codec_type'] === 'video' && !$info['vcodec']) {
            $info['vcodec'] = $s['codec_name'];
            $info['width'] = (int) $s['width'];
            $info['height'] = (int) $s['height'];
            [$n, $d] = array_map('floatval', explode('/', $s['avg_frame_rate'] ?? '0/1') + [1 => 1]);
            $info['fps'] = $d > 0 ? round($n / $d, 2) : 0;
            $info['hdr'] = in_array($s['color_transfer'] ?? '', ['smpte2084', 'arib-std-b67'], true);
        } elseif ($s['codec_type'] === 'audio' && !$info['acodec']) {
            $info['acodec'] = $s['codec_name'];
        }
    }
    return $info;
}

function quality_tag(array $p): string
{
    // Label resolusi berdasarkan sisi pendek, supaya video vertikal (Shorts) tetap benar
    $short = min($p['width'], $p['height']) ?: $p['height'];
    $fps = (int) round($p['fps']);
    return $short . 'p' . ($fps > 30 ? $fps : '');
}

function video_bitrate(array $p): int
{
    // Bitrate target (Mbps) untuk encode H.264/HEVC; dibuat tinggi supaya tidak ada penurunan kualitas terlihat
    $short = min($p['width'], $p['height']) ?: $p['height'];
    $mbps = match (true) {
        $short >= 4320 => 160,
        $short >= 2160 => 60,
        $short >= 1440 => 35,
        $short >= 1080 => 20,
        $short >= 720 => 10,
        default => 5,
    };
    if ($p['fps'] > 30) {
        $mbps = (int) round($mbps * 1.5);
    }
    return $mbps;
}

/**
 * Ambil slot persiapan editor. Job prioritas (clip yang sedang dibuka di editor) langsung jalan;
 * job di belakang layar berjalan satu per satu dan menahan diri selama ada job prioritas yang menunggu/berjalan.
 */
function acquire_prepare_slot(string $id, string $cancelFlag)
{
    update_job($id, ['stage' => 'Menunggu giliran persiapan…']);
    while (true) {
        if (file_exists($cancelFlag)) {
            throw new Cancelled();
        }
        $me = read_job($id) ?? [];
        // Clip yang sedang dibuka di editor langsung jalan tanpa antre slot
        if (!empty($me['priority'])) {
            $path = JOBS_DIR . "/$id.slot";
            $lock = fopen($path, 'c');
            flock($lock, LOCK_EX);
            update_job($id, ['status' => 'converting']);
            return $lock;
        }
        $priorityWaiting = false;
        if (empty($me['priority'])) {
            foreach (glob(JOBS_DIR . '/*.json') as $f) {
                $j = json_decode((string) @file_get_contents($f), true);
                if (($j['mode'] ?? '') === 'prepare' && !empty($j['priority']) && $j['id'] !== $id
                    && in_array($j['status'] ?? '', ['queued', 'converting'], true)) {
                    $priorityWaiting = true;
                    break;
                }
            }
        }
        if (!$priorityWaiting) {
            foreach ([0] as $slot) { // persiapan di belakang layar: satu per satu
                $path = STORAGE_DIR . "/prepare-$slot.lock";
                $lock = fopen($path, 'c');
                @chmod($path, 0666);
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    update_job($id, ['status' => 'converting']);
                    return $lock;
                }
                fclose($lock);
            }
        }
        sleep(1);
    }
}

// Campaign dari footage lokal: analisa adegan tiap bahan & rakit susunan clip
if (in_array($job['kind'] ?? '', ['campaign_file', 'campaign_build', 'campaign_plan'], true)) {
    require_once __DIR__ . '/editor_lib.php';
    require_once __DIR__ . '/source.php';
    require_once __DIR__ . '/campaign_lib.php';
    try {
        rrmdir($tmpDir);
        mkdir($tmpDir, 0777, true);
        if ($job['kind'] === 'campaign_file') {
            run_campaign_file($id, $job, $tmpDir, $cancelFlag);
        } elseif ($job['kind'] === 'campaign_plan') {
            run_campaign_plan($id, $job, $tmpDir, $cancelFlag);
        } else {
            // Merakit = encode berat: antre bersama render clip
            $lock = fopen(STORAGE_DIR . '/render.lock', 'c');
            @chmod(STORAGE_DIR . '/render.lock', 0666);
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (file_exists($cancelFlag)) {
                    throw new Cancelled();
                }
                update_job($id, ['stage' => 'Menunggu giliran render…']);
                sleep(1);
            }
            try {
                run_campaign_build($id, $job, $tmpDir, $cancelFlag);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    } catch (Cancelled $e) {
        update_job($id, ['status' => 'cancelled', 'stage' => 'Dibatalkan']);
    } catch (Throwable $e) {
        update_job($id, ['status' => 'error', 'stage' => 'Gagal', 'error' => $e->getMessage()]);
        if ($job['kind'] === 'campaign_file') {
            update_cfile($job['campaign_id'], $job['file_id'], ['status' => 'error', 'error' => $e->getMessage(), 'stage' => '']);
        } elseif ($job['kind'] === 'campaign_plan') {
            // Error tetap tampil di halaman campaign (bukan hanya notifikasi sesaat)
            update_campaign($job['campaign_id'], function ($c) use ($e) {
                $c['plan_error'] = $e->getMessage();
                return $c;
            });
        }
    } finally {
        rrmdir($tmpDir);
        @unlink($cancelFlag);
    }
    exit;
}

// Job analisa AI dan potong clip ditangani file terpisah
if (in_array($job['kind'] ?? '', ['analyze', 'analyze_more', 'source'], true) || in_array($job['mode'] ?? '', ['clip', 'prepare'], true)) {
    require_once __DIR__ . '/analyzer.php';
    require_once __DIR__ . '/clip.php';
    require_once __DIR__ . '/source.php';
    try {
        rrmdir($tmpDir);
        mkdir($tmpDir, 0777, true);
        if (in_array($job['kind'] ?? '', ['analyze', 'analyze_more'], true)) {
            run_analysis($id, $job, $tmpDir, $cancelFlag);
        } elseif (($job['kind'] ?? '') === 'source') {
            run_source_download($id, $job, $tmpDir, $cancelFlag);
        } elseif (($job['mode'] ?? '') === 'prepare') {
            // Persiapan editor punya jalur sendiri (2 slot), terpisah dari antrean render
            $lock = acquire_prepare_slot($id, $cancelFlag);
            try {
                ensure_edit($id, $job, $cancelFlag);
                update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink(JOBS_DIR . "/$id.slot");
            }
        } else {
            // Render clip berat (Whisper + deteksi wajah + encode): jalankan satu per satu
            $lock = fopen(STORAGE_DIR . '/render.lock', 'c');
            @chmod(STORAGE_DIR . '/render.lock', 0666);
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (file_exists($cancelFlag)) {
                    throw new Cancelled();
                }
                if (($job['stage'] ?? '') !== 'Menunggu giliran render…') {
                    $job = update_job($id, ['stage' => 'Menunggu giliran render…']);
                }
                sleep(1);
            }
            try {
                run_clip($id, $job, $cancelFlag);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    } catch (Cancelled $e) {
        update_job($id, ['status' => 'cancelled', 'stage' => 'Dibatalkan', 'speed' => '', 'eta' => '']);
    } catch (Throwable $e) {
        update_job($id, ['status' => 'error', 'stage' => 'Gagal', 'error' => $e->getMessage(), 'speed' => '', 'eta' => '']);
    } finally {
        rrmdir($tmpDir);
        @unlink($cancelFlag);
    }
    exit;
}

try {
    $mode = $job['mode'];
    $height = (int) $job['height'];
    $isAudio = MODES[$mode]['type'] === 'audio';
    rrmdir($tmpDir);
    mkdir($tmpDir, 0777, true);

    // ---------- 1. Unduh ----------
    $args = [
        escapeshellarg(YTDLP),
        '--no-playlist', '--newline', '--no-warnings', '--no-mtime',
        '--concurrent-fragments', '4',
        // Unduh per potongan 10 MB + coba ulang: YouTube lebih jarang menolak (403) permintaan kecil
        '--http-chunk-size', '10M', '--retries', '5', '--fragment-retries', '5',
        '--progress-template', escapeshellarg('download:[prog]%(progress._percent_str)s|%(progress._speed_str)s|%(progress._eta_str)s'),
        '-o', escapeshellarg($tmpDir . '/source.%(ext)s'),
    ];
    if ($isAudio) {
        $args[] = '-f ' . escapeshellarg('ba/b');
    } else {
        $limit = $height > 0 ? ":$height" : '';
        // Mode edit: utamakan SDR (HDR jadi pucat setelah dikonversi ke 8-bit), lalu resolusi, fps, bitrate tertinggi
        $sort = $mode === 'original' ? "res$limit,fps" : "hdr:SDR,res$limit,fps,vbr";
        $args[] = '-f ' . escapeshellarg('bv*+ba/b');
        $args[] = '-S ' . escapeshellarg($sort);
        $args[] = '--merge-output-format ' . escapeshellarg('mp4/mkv');
    }
    $args[] = '--';
    $args[] = escapeshellarg($job['url']);

    $part = 0;
    $parts = $isAudio ? 1 : 2;
    $lastWrite = 0;
    update_job($id, ['status' => 'downloading', 'stage' => 'Menyiapkan unduhan…', 'progress' => 0]);

    // YouTube kadang menolak sementara (HTTP 403 / link kedaluwarsa): ulangi dengan link baru, maksimal 3 kali
    for ($attempt = 1; ; $attempt++) {
    $part = 0;
    [$code, $tail] = run(implode(' ', $args), function (string $line) use ($id, &$part, $parts, &$lastWrite, $isAudio) {
        if (str_contains($line, '[download] Destination:')) {
            $part++;
        } elseif (str_contains($line, '[Merger]')) {
            update_job($id, ['stage' => 'Menggabungkan video + audio…', 'progress' => 100, 'speed' => '', 'eta' => '']);
        } elseif (str_starts_with($line, '[prog]') && microtime(true) - $lastWrite > 0.7) {
            [$pct, $speed, $eta] = array_map('trim', explode('|', substr($line, 6)) + [1 => '', 2 => '']);
            $pct = (float) $pct;
            $p = max(1, $part);
            $label = $isAudio ? 'Mengunduh audio' : ($p === 1 ? 'Mengunduh video' : 'Mengunduh audio');
            if ($parts > 1 && $part > $parts) {
                $label = 'Mengunduh';
            }
            update_job($id, [
                'stage' => $label . ($parts > 1 && !$isAudio ? " ($p/$parts)" : ''),
                'progress' => round($pct, 1),
                'speed' => $speed === 'Unknown B/s' ? '' : $speed,
                'eta' => $eta === 'Unknown' ? '' : $eta,
            ]);
            $lastWrite = microtime(true);
        }
    }, $cancelFlag);

    if ($code === 0) {
        break;
    }
    $error = error_from($tail);
    $blocked = (bool) preg_match('/HTTP Error (403|429|5\d\d)|unable to download video data|Got error|timed out|Connection reset/i', $error);
    if (!$blocked || $attempt >= 3) {
        throw new RuntimeException($blocked
            ? 'YouTube menolak unduhan (' . (preg_match('/HTTP Error \d+/', $error, $m) ? $m[0] : 'koneksi terputus') . ') setelah 3 kali percobaan. Tunggu beberapa menit lalu klik Coba lagi.'
            : $error);
    }
    foreach (glob($tmpDir . '/source.*') as $f) {
        @unlink($f); // sisa unduhan setengah jadi dibuang, mulai bersih dengan link baru
    }
    update_job($id, ['status' => 'downloading', 'stage' => "YouTube menolak sementara, mencoba lagi ($attempt/2)…", 'progress' => 0, 'speed' => '', 'eta' => '']);
    sleep(3 * $attempt);
    }

    $files = array_filter(glob($tmpDir . '/source.*'), fn($f) => !preg_match('/\.(part|ytdl|temp)$/', $f));
    usort($files, fn($a, $b) => filesize($b) <=> filesize($a));
    $source = $files[0] ?? null;
    if (!$source) {
        throw new RuntimeException('File hasil unduhan tidak ditemukan.');
    }

    // ---------- 2. Konversi ----------
    $p = probe($source);
    $title = safe_filename($job['title'] ?: 'video');
    $tag = $isAudio ? '' : quality_tag($p);
    $srcExt = pathinfo($source, PATHINFO_EXTENSION);

    $ffBase = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -i '
        . escapeshellarg($source);
    $commands = []; // daftar alternatif: kalau yang pertama gagal, coba berikutnya
    $note = '';

    switch ($mode) {
        case 'original':
            $output = unique_path(DOWNLOAD_DIR, "$title [$tag]", $srcExt);
            break;

        case 'edit_h264':
            $output = unique_path(DOWNLOAD_DIR, "$title [$tag]", 'mp4');
            $audio = match ($p['acodec']) {
                null => '-an',
                'aac' => '-c:a copy',
                default => '-c:a aac -b:a 320k',
            };
            if ($p['vcodec'] === 'h264') {
                // Video sudah H.264: salin apa adanya, cukup audio yang disesuaikan
                $commands[] = "$ffBase -map 0:v:0 -map '0:a:0?' -c:v copy $audio -movflags +faststart";
                $note = 'Sumber sudah H.264, video disalin tanpa encode ulang (kualitas 100% asli).';
                break;
            }
            $mbps = video_bitrate($p);
            $hevc = min($p['width'], $p['height']) > 2160; // H.264 hardware tidak mendukung 8K
            $hw = $hevc
                ? "-c:v hevc_videotoolbox -tag:v hvc1 -b:v {$mbps}M"
                : "-c:v h264_videotoolbox -profile:v high -b:v {$mbps}M";
            $sw = $hevc
                ? '-c:v libx265 -crf 16 -preset medium -tag:v hvc1'
                : '-c:v libx264 -crf 16 -preset medium -profile:v high';
            $common = "-map 0:v:0 -map '0:a:0?' -pix_fmt yuv420p " . $audio . ' -movflags +faststart';
            if ($hw = venc($hw)) {
                $commands[] = "$ffBase $hw $common";
            }
            $commands[] = "$ffBase $sw $common";
            $note = ($hevc ? 'HEVC' : 'H.264') . " {$mbps} Mbps dari sumber " . strtoupper((string) $p['vcodec']) . '.';
            break;

        case 'edit_prores':
            $output = unique_path(DOWNLOAD_DIR, "$title [$tag ProRes]", 'mov');
            $audio = $p['acodec'] ? '-c:a pcm_s16le' : '-an';
            $common = "-map 0:v:0 -map '0:a:0?' $audio";
            if (IS_MAC) {
                $commands[] = "$ffBase -c:v prores_videotoolbox -allow_sw 1 -profile:v standard $common";
            }
            $commands[] = "$ffBase -c:v prores_ks -profile:v 2 -vendor apl0 -pix_fmt yuv422p10le $common";
            $note = 'ProRes 422 dari sumber ' . strtoupper((string) $p['vcodec']) . '.';
            break;

        case 'audio_wav':
            $output = unique_path(DOWNLOAD_DIR, $title, 'wav');
            $commands[] = "$ffBase -vn -c:a pcm_s16le";
            break;

        case 'audio_mp3':
            $output = unique_path(DOWNLOAD_DIR, $title, 'mp3');
            $commands[] = "$ffBase -vn -c:a libmp3lame -b:a 320k";
            break;
    }

    if ($p['hdr'] && !$isAudio && $mode !== 'original') {
        $note .= ' Perhatian: video ini hanya tersedia dalam HDR, warna bisa terlihat berbeda.';
    }

    if (!$commands) {
        rename($source, $output);
    } else {
        $duration = max(0.1, $p['duration']);
        $ok = false;
        foreach ($commands as $i => $cmd) {
            update_job($id, [
                'status' => 'converting',
                'stage' => $i === 0 ? 'Mengonversi untuk editing…' : 'Mengonversi (mode software, lebih lambat)…',
                'progress' => 0, 'speed' => '', 'eta' => '',
            ]);
            $lastWrite = 0;
            [$code, $tail] = run($cmd . ' ' . escapeshellarg($output), function (string $line) use ($id, $duration, &$lastWrite) {
                static $speed = '';
                if (str_starts_with($line, 'speed=')) {
                    $speed = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'out_time_us=') && microtime(true) - $lastWrite > 0.7) {
                    $sec = ((int) substr($line, 12)) / 1e6;
                    $pct = min(99.9, max(0, $sec / $duration * 100));
                    $x = (float) $speed;
                    $eta = $x > 0 ? gmdate($duration > 3600 ? 'H:i:s' : 'i:s', (int) (($duration - $sec) / $x)) : '';
                    update_job($id, ['progress' => round($pct, 1), 'speed' => $speed, 'eta' => $eta]);
                    $lastWrite = microtime(true);
                }
            }, $cancelFlag);
            if ($code === 0 && is_file($output) && filesize($output) > 0) {
                $ok = true;
                break;
            }
            @unlink($output);
        }
        if (!$ok) {
            throw new RuntimeException('Konversi gagal: ' . error_from($tail));
        }
    }

    @chmod($output, 0666);
    $final = probe($output);
    rrmdir($tmpDir);
    // Unduhan format musik otomatis masuk pustaka musik editor (gagal di sini tidak menggagalkan unduhan)
    $inLibrary = false;
    if ($isAudio) {
        try {
            require_once __DIR__ . '/editor_lib.php';
            music_library_import($output, (string) ($job['title'] ?? pathinfo($output, PATHINFO_FILENAME)));
            $inLibrary = true;
            $note = trim($note . ' Tersimpan di library musik editor.');
        } catch (Throwable $e) {
            error_log('BisaClip: gagal menambahkan ke library musik: ' . $e->getMessage());
        }
    }
    update_job($id, [
        'music_lib' => $inLibrary,
        'status' => 'done',
        'stage' => 'Selesai',
        'progress' => 100,
        'speed' => '',
        'eta' => '',
        'file' => basename($output),
        'path' => $output,
        'size' => filesize($output),
        'result' => [
            'resolution' => $final['width'] ? "{$final['width']}×{$final['height']}" : null,
            'fps' => $final['fps'] ?: null,
            'vcodec' => $final['vcodec'],
            'acodec' => $final['acodec'],
            'duration' => $final['duration'],
        ],
        'note' => trim($note),
    ]);
} catch (Cancelled $e) {
    rrmdir($tmpDir);
    if (isset($output)) {
        @unlink($output);
    }
    update_job($id, ['status' => 'cancelled', 'stage' => 'Dibatalkan', 'speed' => '', 'eta' => '']);
} catch (Throwable $e) {
    rrmdir($tmpDir);
    if (isset($output)) {
        @unlink($output);
    }
    update_job($id, ['status' => 'error', 'stage' => 'Gagal', 'error' => $e->getMessage(), 'speed' => '', 'eta' => '']);
} finally {
    @unlink($cancelFlag);
}
