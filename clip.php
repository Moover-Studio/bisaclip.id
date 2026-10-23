<?php
// Potong satu clip langsung dari stream YouTube (dipanggil dari worker.php untuk mode=clip)

require_once __DIR__ . '/subtitles.php';
require_once __DIR__ . '/editor_lib.php';
require_once __DIR__ . '/source.php';

const CLIP_LAYOUTS = [
    'speaker' => '9:16 ikuti pembicara',
    'split' => '9:16 split screen',
    'gaming' => '9:16 streaming game',
    'landscape' => '16:9 (asli)',
    'crop_center' => '9:16 crop tengah',
    'crop_left' => '9:16 crop kiri',
    'crop_right' => '9:16 crop kanan',
    'blur' => '9:16 background blur',
    'letterbox' => '9:16 letterbox 3:4',
];

function clip_filter(string $layout): string
{
    // Output vertikal standar platform: 1080x1920
    return match ($layout) {
        'crop_center' => 'crop=trunc(ih*9/16/2)*2:ih:(iw-ow)/2:0,scale=1080:1920:flags=lanczos',
        // Video 3:4 di tengah layar hitam (bar 228 px atas & bawah)
        'letterbox' => 'scale=1080:1464:force_original_aspect_ratio=increase:flags=lanczos,crop=1080:1464,pad=1080:1920:0:228:black',
        'crop_left' => 'crop=trunc(ih*9/16/2)*2:ih:iw*0.06:0,scale=1080:1920:flags=lanczos',
        'crop_right' => 'crop=trunc(ih*9/16/2)*2:ih:iw*0.94-ow:0,scale=1080:1920:flags=lanczos',
        'blur' => 'split[a][b];[a]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,'
            . 'boxblur=25:3,eq=brightness=-0.08[bg];[b]scale=1080:-2:flags=lanczos[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2',
        default => '',
    };
}

/** Ambil URL stream video + audio kualitas tertinggi (DASH https supaya bisa langsung seek). */
function clip_streams(string $url, string $cancelFlag): array
{
    $raw = '';
    [$code, $tail] = run(escapeshellarg(YTDLP) . ' -j --no-playlist --no-warnings'
        . ' -f ' . escapeshellarg('bv*[protocol=https]+ba[protocol=https]/b[protocol=https]/bv*+ba/b')
        . ' -S ' . escapeshellarg('hdr:SDR,res,fps,vbr') . ' --socket-timeout 20 -- ' . escapeshellarg($url),
        function ($line) use (&$raw) {
            if ($line !== '' && $line[0] === '{') {
                $raw = $line;
            }
        }, $cancelFlag, 90);
    $info = json_decode($raw, true);
    if ($code !== 0 || !is_array($info)) {
        throw new RuntimeException(error_from($tail));
    }
    $video = $audio = null;
    foreach ($info['requested_formats'] ?? [$info] as $f) {
        if (($f['vcodec'] ?? 'none') !== 'none' && !$video) {
            $video = $f;
        }
        if (($f['acodec'] ?? 'none') !== 'none' && !$audio) {
            $audio = $f;
        }
    }
    if (!$video) {
        throw new RuntimeException('Stream video tidak ditemukan.');
    }
    return [$video, $audio];
}

function clip_inputs(array $video, ?array $audio, float $start): array
{
    $inputs = '';
    $index = [];
    $n = 0;
    foreach (array_unique(array_filter([$video, $audio]), SORT_REGULAR) as $f) {
        $headers = '';
        foreach ($f['http_headers'] ?? [] as $k => $v) {
            $headers .= "$k: $v\r\n";
        }
        $local = str_starts_with($f['url'], '/');
        // Potongan sumber lokal: posisi dihitung dari awal potongan
        $inputs .= ' -ss ' . number_format(max(0, $start - (float) ($f['shift'] ?? 0)), 3, '.', '')
            . ($headers && !$local ? ' -headers ' . escapeshellarg($headers) : '')
            . ($local ? '' : ' -reconnect 1 -reconnect_streamed 1') . ' -i ' . escapeshellarg($f['url']);
        $index[$f['format_id']] = $n++;
    }
    return [$inputs, $index[$video['format_id']], $audio ? $index[$audio['format_id']] : null];
}

/**
 * Siapkan file ASS untuk clip: subtitle animasi (Whisper) dan/atau teks hook.
 * Mengembalikan [path_ass|null, catatan].
 */
function clip_subtitles(string $id, array $job, ?array $audio, float $start, float $dur, string $layout, string $cancelFlag): array
{
    $wantSubs = !empty($job['subtitles']);
    $hook = trim((string) ($job['hook_text'] ?? ''));
    if (!$wantSubs && $hook === '') {
        return [null, ''];
    }
    $tmpDir = TMP_DIR . '/' . $id;
    $words = [];
    $note = '';
    if ($wantSubs && $audio) {
        $wav = "$tmpDir/audio.wav";
        // Stream audio kadang ditolak (403) atau macet: coba sampai 3 kali dengan URL baru
        for ($try = 1; $try <= 3; $try++) {
            update_job($id, ['status' => 'converting', 'progress' => 0,
                'stage' => $try === 1 ? 'Mengambil audio untuk subtitle…' : "Mengambil audio (percobaan $try/3)…"]);
            if ($try > 1) {
                sleep(2);
                try {
                    [, $fresh] = clip_streams($job['url'], $cancelFlag);
                    $audio = $fresh ?: $audio;
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    continue;
                }
            }
            @unlink($wav);
            [$inputs] = clip_inputs($audio, null, $start);
            try {
                [$code] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . $inputs
                    . ' -t ' . number_format($dur, 3, '.', '') . ' -vn -ac 1 -ar 16000 -c:a pcm_s16le ' . escapeshellarg($wav),
                    fn() => null, $cancelFlag, 120);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $code = 1; // waktu habis
            }
            clearstatcache();
            // Audio dianggap lengkap kalau durasinya mendekati durasi clip (16 kHz mono 16-bit = 32000 byte/detik)
            if ($code === 0 && is_file($wav) && filesize($wav) > $dur * 32000 * 0.9) {
                break;
            }
        }
        if (is_file($wav) && filesize($wav) > $dur * 32000 * 0.9) {
            update_job($id, ['stage' => 'Membuat subtitle dengan Whisper…']);
            try {
                $words = whisper_words($wav, (string) ($job['language'] ?? ''), $tmpDir, $cancelFlag);
                $note = count($words) . ' kata subtitle.';
                file_put_contents("$tmpDir/words_clip.json", json_encode($words));
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $note = 'Subtitle gagal dibuat (' . $e->getMessage() . '), clip dirender tanpa subtitle.';
            }
        } else {
            $note = 'Audio untuk subtitle gagal diambil, clip dirender tanpa subtitle.';
        }
    }
    if (!$words && $hook === '') {
        return [null, $note];
    }
    $path = "$tmpDir/sub.ass";
    file_put_contents($path, build_ass($words, $layout, $hook, $dur));
    return [$path, $note];
}

function wait_prepare_job(string $prepId, string $jobId, string $cancelFlag): void
{
    while ($prepId && ($prep = read_job($prepId)) && in_array($prep['status'], ['queued', 'downloading', 'converting'], true)) {
        if (file_exists($cancelFlag)) {
            throw new Cancelled();
        }
        update_job($jobId, ['stage' => 'Menunggu persiapan editor selesai…']);
        sleep(1);
    }
}

/**
 * Jalankan pelacak pembicara (tools/speaker_track.py) pada stream resolusi rendah.
 * Mengembalikan daftar segmen kamera, atau [] kalau gagal / tidak ada wajah.
 */
function speaker_segments(string $id, array $job, float $start, float $dur, string $cancelFlag): array
{
    $tmpDir = TMP_DIR . '/' . $id;
    update_job($id, ['status' => 'converting', 'stage' => 'Mendeteksi wajah & pembicara…', 'progress' => 0]);
    $raw = '';
    run(escapeshellarg(YTDLP) . ' -j --no-playlist --no-warnings'
        . ' -f ' . escapeshellarg('bv*[height<=480][protocol=https]/bv*[height<=480]/bv*')
        . ' -S ' . escapeshellarg('res,fps') . ' --socket-timeout 20 -- ' . escapeshellarg($job['url']),
        function ($line) use (&$raw) {
            if ($line !== '' && $line[0] === '{') {
                $raw = $line;
            }
        }, $cancelFlag, 90);
    $f = json_decode($raw, true);
    if (!is_array($f) || empty($f['url']) || empty($f['width']) || empty($f['height'])) {
        return [];
    }
    $w = 640;
    $h = (int) (round(640 * $f['height'] / $f['width'] / 2) * 2);
    [$inputs] = clip_inputs($f, null, $start);
    $words = is_file("$tmpDir/words_clip.json") ? ' --words ' . escapeshellarg("$tmpDir/words_clip.json") : '';
    $out = "$tmpDir/track.json";
    $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error' . $inputs . ' -t ' . number_format($dur, 3, '.', '')
        . " -an -vf fps=10,scale=$w:$h -f rawvideo -pix_fmt bgr24 - | " . escapeshellarg(PYTHON) . ' '
        . escapeshellarg(APP_DIR . '/tools/speaker_track.py') . " --width $w --height $h --fps 10"
        . ' --model ' . escapeshellarg(FACE_MODEL) . $words . ' --out ' . escapeshellarg($out);
    run($cmd, fn() => null, $cancelFlag, 300);
    $data = json_decode((string) @file_get_contents($out), true);
    if (!is_array($data) || empty($data['segments']) || empty($data['faces'])) {
        return [];
    }
    return $data['segments'];
}

/** Hitung area crop 9:16 untuk satu segmen: wajah di tengah, tinggi wajah ±24% layar. */
function speaker_crop(array $seg, int $W, int $H, float $aspect = 9 / 16): array
{
    $minH = min($H, max($H * 0.5, 640)); // batasi zoom supaya gambar tidak pecah
    $ch = $seg['fh'] > 0 ? $seg['fh'] * $H / 0.24 : $H;
    $ch = max($minH, min($H, $ch));
    $cw = $ch * $aspect;
    if ($cw > $W) {
        $cw = $W;
        $ch = min($H, $W / $aspect);
    }
    $cw = (int) (floor($cw / 2) * 2);
    $ch = (int) (floor($ch / 2) * 2);
    $x = (int) (floor(max(0, min($W - $cw, $seg['cx'] * $W - $cw / 2)) / 2) * 2);
    $y = (int) (floor(max(0, min($H - $ch, $seg['cy'] * $H - $ch * 0.36)) / 2) * 2);
    return [$cw, $ch, $x, $y];
}

/** Area crop untuk separuh layar split screen (1080x960, rasio 9:8): wajah di tengah, tinggi wajah ±32% separuh layar. */
function half_crop(array $p, int $W, int $H): array
{
    $minH = min($H, max($H * 0.35, 400));
    $ch = $p['fh'] > 0 ? $p['fh'] * $H / 0.32 : $H;
    $ch = max($minH, min($H, $ch));
    $cw = $ch * 9 / 8;
    if ($cw > $W) {
        $cw = $W;
        $ch = min($H, $W * 8 / 9);
    }
    $cw = (int) (floor($cw / 2) * 2);
    $ch = (int) (floor($ch / 2) * 2);
    $x = (int) (floor(max(0, min($W - $cw, $p['cx'] * $W - $cw / 2)) / 2) * 2);
    $y = (int) (floor(max(0, min($H - $ch, $p['cy'] * $H - $ch * 0.45)) / 2) * 2);
    return [$cw, $ch, $x, $y];
}

/** Tinggi area game di layout streaming game (70% layar); 30% sisanya untuk webcam streamer. */
const GAMING_TOP_H = 1344;

/**
 * Area crop layout streaming game: [game, webcam], masing-masing [w, h, x, y] dalam piksel sumber.
 * Game = crop tengah setinggi video (rasio 1080x1344). Webcam = area di sekitar wajah streamer
 * (rasio 1080x576, tinggi wajah ±35% panel) supaya sebisa mungkin tetap di dalam kotak webcam.
 */
function gaming_crops(array $cam, int $W, int $H): array
{
    $even = fn($v) => (int) (floor($v / 2) * 2);
    $ga = 1080 / GAMING_TOP_H;
    $gh = $H;
    $gw = $gh * $ga;
    if ($gw > $W) {
        [$gw, $gh] = [$W, $W / $ga];
    }
    $game = [$even($gw), $even($gh), $even(($W - $gw) / 2), $even(($H - $gh) / 2)];
    $ca = 1080 / (1920 - GAMING_TOP_H);
    $ch = max($cam['fh'] * $H / 0.35, $H * 0.12);
    $cw = $ch * $ca;
    if ($cw > $W) {
        [$cw, $ch] = [$W, $W / $ca];
    }
    if ($ch > $H) {
        [$cw, $ch] = [$H * $ca, $H];
    }
    $x = max(0, min($W - $cw, $cam['cx'] * $W - $cw / 2));
    $y = max(0, min($H - $ch, $cam['cy'] * $H - $ch * 0.45));
    return [$game, [$even($cw), $even($ch), $even($x), $even($y)]];
}

/** Filter layout streaming game: game di atas (70%), webcam di bawah (30%), ditumpuk jadi 1080x1920. */
function gaming_filter(array $cam, int $W, int $H): string
{
    [[$gw, $gh, $gx, $gy], [$cw, $ch, $cx, $cy]] = gaming_crops($cam, $W, $H);
    $camH = 1920 - GAMING_TOP_H;
    return "split[gmA][gmB];[gmA]crop=$gw:$gh:$gx:$gy,scale=1080:" . GAMING_TOP_H . ':flags=lanczos,setsar=1[gmT];'
        . "[gmB]crop=$cw:$ch:$cx:$cy,scale=1080:$camH:flags=lanczos,setsar=1[gmW];[gmT][gmW]vstack=inputs=2";
}

/**
 * Filtergraph split screen: per potongan, shot 2 orang = atas/bawah (vstack), selain itu satu orang penuh.
 * Semua potongan digabung lagi → [cv].
 */
function split_graph(array $items, int $W, int $H, int $vi, float $dur): string
{
    $n = count($items);
    $fmt = fn($t) => number_format(max(0, $t), 3, '.', '');
    $parts = ["[$vi:v]split=$n" . implode('', array_map(fn($i) => "[s$i]", range(0, $n - 1)))];
    foreach (array_values($items) as $i => $it) {
        $a = $i === 0 ? 0 : $it['start'];
        $b = $i === $n - 1 ? $dur + 1 : $it['end'];
        $cut = "[s$i]trim=start={$fmt($a)}:end={$fmt($b)},setpts=PTS-STARTPTS";
        if ($it['mode'] === 'split') {
            [$aw, $ah, $ax, $ay] = half_crop($it['a'], $W, $H);
            [$bw, $bh, $bx, $by] = half_crop($it['b'], $W, $H);
            $parts[] = "$cut,split[u$i][d$i]";
            $parts[] = "[u$i]crop=$aw:$ah:$ax:$ay,scale=1080:960:flags=lanczos,setsar=1[ta$i]";
            $parts[] = "[d$i]crop=$bw:$bh:$bx:$by,scale=1080:960:flags=lanczos,setsar=1[tb$i]";
            $parts[] = "[ta$i][tb$i]vstack=inputs=2[c$i]";
        } elseif ($it['mode'] === 'blur') {
            $parts[] = "$cut,split[bg$i][fg$i]";
            $parts[] = "[bg$i]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=25:3,eq=brightness=-0.08[bb$i]";
            $parts[] = "[fg$i]scale=1080:-2:flags=lanczos[ff$i]";
            $parts[] = "[bb$i][ff$i]overlay=(W-w)/2:(H-h)/2,setsar=1[c$i]";
        } else {
            // single = ikuti pembicara (segmen), crop_center = crop tengah tanpa data wajah
            [$cw, $ch, $x, $y] = speaker_crop($it['mode'] === 'single' ? $it : ['cx' => 0.5, 'cy' => 0.5, 'fh' => 0], $W, $H);
            if ($it['mode'] !== 'single') {
                $y = (int) (floor(($H - $ch) / 4) * 2);
            }
            $parts[] = "$cut,crop=$cw:$ch:$x:$y,scale=1080:1920:flags=lanczos,setsar=1[c$i]";
        }
    }
    $parts[] = implode('', array_map(fn($i) => "[c$i]", range(0, $n - 1))) . "concat=n=$n:v=1:a=0[cv]";
    return implode(";\n", $parts);
}

/** Filtergraph: potong per segmen, crop ke pembicara, skala 1080x1920, lalu gabungkan lagi → [cv]. */
const CAM_PAN = 0.9;        // durasi kamera bergeser halus untuk perpindahan kecil (detik)
const CAM_DEADZONE = 0.02;  // pergeseran lebih kecil dari 2% lebar/tinggi video diabaikan (tidak bergetar)
const CAM_HOLD = 1.0;       // pembicara yang tampil kurang dari ini tidak membuat kamera berpindah
// Perpindahan lebih jauh dari 18% lebar/tinggi video (mis. dari pembicara kiri ke kanan di shot lebar) dibuat
// cut langsung seperti ganti kamera — geseran kilat sejauh itu terlihat seperti glitch.
const CAM_JUMP = 0.18;

/** Posisi crop (x, y) untuk wajah di segmen dengan ukuran crop tetap cw x ch (sama dengan speaker_crop). */
function speaker_xy(array $seg, int $cw, int $ch, int $W, int $H): array
{
    $x = max(0, min($W - $cw, $seg['cx'] * $W - $cw / 2));
    $y = $seg['fh'] > 0 ? max(0, min($H - $ch, $seg['cy'] * $H - $ch * 0.36)) : ($H - $ch) / 2;
    return [round($x, 1), round($y, 1)];
}

/**
 * Gerak kamera "ikuti pembicara": segmen dikelompokkan jadi run per shot kamera sumber.
 * Di dalam satu run ukuran crop tetap (zoom terbesar yang memuat semua wajah) dan kamera bergeser halus (ease-in-out)
 * ke pembicara berikutnya; di antara run (video sumber memang pindah kamera / cut) kamera langsung pindah.
 * $cuts = waktu cut di video sumber (relatif); null = tidak diketahui, dipakai perbedaan ukuran wajah sebagai tanda cut.
 * Mengembalikan [['start','end','cw','ch','keys' => [[t, x, y], ...]], ...]. Sama dengan cameraRuns() di editor.
 */
function camera_runs(array $segs, ?array $cuts, int $W, int $H, float $aspect): array
{
    $runs = [];
    $prev = null;
    foreach (array_values($segs) as $i => $seg) {
        $crop = speaker_crop($seg, $W, $H, $aspect);
        $hard = $i === 0;
        if (!$hard) {
            if ($cuts !== null) {
                foreach ($cuts as $c) {
                    if (abs($c - $seg['start']) < 0.15) {
                        $hard = true;
                        break;
                    }
                }
            } else {
                $hard = abs($crop[1] - $prev[1]) / max(1, $prev[1]) > 0.25;
            }
        }
        if ($hard) {
            $runs[] = ['start' => $seg['start'], 'end' => $seg['end'], 'segs' => [$seg], 'ch' => $crop[1]];
        } else {
            $r = &$runs[count($runs) - 1];
            $r['segs'][] = $seg;
            $r['end'] = $seg['end'];
            $r['ch'] = max($r['ch'], $crop[1]);
            unset($r);
        }
        $prev = $crop;
    }
    foreach ($runs as &$r) {
        $ch = $r['ch'];
        $cw = $ch * $aspect;
        if ($cw > $W) {
            $cw = $W;
            $ch = min($H, $W / $aspect);
        }
        $r['cw'] = (int) (floor($cw / 2) * 2);
        $r['ch'] = (int) (floor($ch / 2) * 2);
        $keys = [];
        foreach ($r['segs'] as $k => $seg) {
            [$x, $y] = speaker_xy($seg, $r['cw'], $r['ch'], $W, $H);
            $last = end($keys);
            if ($k > 0 && abs($x - $last[1]) < $W * CAM_DEADZONE && abs($y - $last[2]) < $H * CAM_DEADZONE) {
                continue; // pembicara hampir di posisi yang sama: kamera diam
            }
            $jump = $k > 0 && (abs($x - $last[1]) > $W * CAM_JUMP || abs($y - $last[2]) > $H * CAM_JUMP);
            $keys[] = [$k === 0 ? $r['start'] : $seg['start'], $x, $y, $jump];
        }
        // Selaan singkat (< CAM_HOLD detik) tidak menggerakkan kamera bolak-balik
        $keys = array_values(array_filter($keys, fn($kv, $k) => $k === 0 || (($keys[$k + 1][0] ?? $r['end']) - $kv[0]) >= CAM_HOLD,
            ARRAY_FILTER_USE_BOTH));
        // Setelah selaan dibuang, jenis perpindahan dihitung ulang dari posisi kamera sebelumnya
        foreach ($keys as $k => $kv) {
            $keys[$k][3] = $k > 0 && (abs($kv[1] - $keys[$k - 1][1]) > $W * CAM_JUMP || abs($kv[2] - $keys[$k - 1][2]) > $H * CAM_JUMP);
        }
        $r['keys'] = $keys;
        unset($r['segs']);
    }
    unset($r);
    return $runs;
}

/**
 * Ekspresi ffmpeg posisi kamera (x atau y) di dalam satu run; t lokal = 0 di awal potongan run.
 * Perpindahan jauh = cut langsung; perpindahan kecil = geser halus (smootherstep, mulai & berhenti sangat lembut).
 */
function camera_expr(array $keys, int $axis, float $t0): string
{
    $e = (string) $keys[0][$axis];
    for ($k = 1; $k < count($keys); $k++) {
        $d = $keys[$k][$axis] - $keys[$k - 1][$axis];
        if (abs($d) < 0.5) {
            continue;
        }
        $tk = $keys[$k][0] - $t0;
        if (!empty($keys[$k][3])) {
            $e .= sprintf('+%.1f*gte(t,%.3f)', $d, $tk);
            continue;
        }
        $p = sprintf('clip((t-%.3f)/%.3f,0,1)', $tk, CAM_PAN);
        $e .= sprintf('+%.1f*pow(%2$s,3)*(%2$s*(6*%2$s-15)+10)', $d, $p);
    }
    return $e;
}

function speaker_graph(array $segs, int $W, int $H, int $vi, float $dur, float $aspect = 9 / 16, int $outH = 1920, ?array $cuts = null): string
{
    $runs = camera_runs($segs, $cuts, $W, $H, $aspect);
    $n = count($runs);
    $fmt = fn($t) => number_format(max(0, $t), 3, '.', '');
    $parts = ["[$vi:v]split=$n" . implode('', array_map(fn($i) => "[s$i]", range(0, $n - 1)))];
    foreach ($runs as $i => $r) {
        $a = $i === 0 ? 0 : $r['start'];
        $b = $i === $n - 1 ? $dur + 1 : $r['end'];
        $x = camera_expr($r['keys'], 1, $a);
        $y = camera_expr($r['keys'], 2, $a);
        $parts[] = "[s$i]trim=start={$fmt($a)}:end={$fmt($b)},setpts=PTS-STARTPTS,crop={$r['cw']}:{$r['ch']}:x='$x':y='$y',"
            . "scale=1080:$outH:flags=lanczos,setsar=1[c$i]";
    }
    $cat = implode('', array_map(fn($i) => "[c$i]", range(0, $n - 1))) . "concat=n=$n:v=1:a=0";
    // Layout bingkai: area video di tengah layar hitam
    $parts[] = $outH < 1920 ? "$cat,pad=1080:1920:0:" . (int) ((1920 - $outH) / 2) . ':black[cv]' : "{$cat}[cv]";
    return implode(";\n", $parts);
}

/**
 * Sambung footage (gambar / video) sebelum & sesudah clip hasil render, menimpa $output.
 * Footage dipaskan ke ukuran clip (utuh di tengah, sisa layar diisi versi blur-nya); gambar & video tanpa suara diberi audio hening.
 * Transisi (xfade) berpusat di sambungan: kedua sisi diperpanjang setengah durasi transisi dengan frame diam,
 * jadi total durasi tetap sama dengan jumlah durasi semua potongan (sama seperti timeline editor).
 */
function footage_concat(string $id, string $output, array $seq, array $encoders, string $cancelFlag, array $trans = [], array $tracks = [], ?array $opening = null): void
{
    $hideVideo = !empty($tracks['video']['hide']);
    $mute = !empty($tracks['video']['mute']);
    update_job($id, ['status' => 'converting', 'stage' => 'Menyambung footage tambahan…', 'progress' => 0]);
    $main = probe($output);
    $W = $main['width'] ?: 1080;
    $H = $main['height'] ?: 1920;
    $fps = $main['fps'] ?: 30;
    $inputs = '';
    $lens = $fits = $hasAudio = [];
    foreach ($seq as $n => $it) {
        $isMain = $it === 'main';
        $file = $isMain ? $output : OVERLAY_DIR . '/' . $it['file'];
        $isImg = !$isMain && $it['kind'] === 'image';
        $info = $isImg ? null : ($isMain ? $main : probe($file));
        $d = round($isMain ? (float) $main['duration'] : min((float) $it['dur'], $isImg ? 60 : ((float) $info['duration'] ?: $it['dur'])), 3);
        $inputs .= ($isImg ? " -loop 1 -framerate $fps" : '') . " -t $d -i " . escapeshellarg($file);
        $fits[$n] = $isMain ? "scale=$W:$H"
            : "split[fa$n][fb$n];[fa$n]scale=$W:$H:force_original_aspect_ratio=increase,crop=$W:$H,boxblur=40:2[bg$n];"
            . "[fb$n]scale=$W:$H:force_original_aspect_ratio=decrease[fg$n];[bg$n][fg$n]overlay=(W-w)/2:(H-h)/2"
            . ($hideVideo && !$isMain ? ',drawbox=c=black:t=fill' : '');
        $lens[$n] = $d;
        $hasAudio[$n] = !$isImg && !$mute && !empty($info['acodec']);
    }
    // Transisi masuk ke potongan n (n ≥ 1): [nama xfade, durasi], durasi dibatasi panjang kedua sisi
    $tin = [];
    foreach ($seq as $n => $it) {
        $tr = $n > 0 ? ($trans[$it === 'main' ? 'main' : $it['id']] ?? null) : null;
        if ($tr && isset(TRANSITION_PRESETS[$tr['type'] ?? ''])) {
            $tin[$n] = [TRANSITION_PRESETS[$tr['type']]['xf'], round(min((float) $tr['dur'], $lens[$n - 1], $lens[$n]), 3)];
        }
    }
    $parts = [];
    $alabels = '';
    $cur = '';
    $curLen = 0;
    foreach ($seq as $n => $it) {
        $d = $lens[$n];
        $padIn = isset($tin[$n]) ? $tin[$n][1] / 2 : 0;
        $padOut = isset($tin[$n + 1]) ? $tin[$n + 1][1] / 2 : 0;
        $tpad = $padIn || $padOut
            ? sprintf(',tpad=start_mode=clone:start_duration=%.3f:stop_mode=clone:stop_duration=%.3f', $padIn, $padOut) : '';
        $parts[] = "[$n:v]{$fits[$n]},setsar=1,fps=$fps,format=yuv420p,trim=0:$d,setpts=PTS-STARTPTS{$tpad}[v$n]";
        $parts[] = $hasAudio[$n]
            ? "[$n:a]aformat=sample_rates=48000:channel_layouts=stereo,apad,atrim=0:$d,asetpts=PTS-STARTPTS[a$n]"
            : "anullsrc=r=48000:cl=stereo,atrim=0:$d,asetpts=PTS-STARTPTS[a$n]";
        $alabels .= "[a$n]";
        $len = $padIn + $d + $padOut;
        if ($n === 0) {
            [$cur, $curLen] = ['v0', $len];
            continue;
        }
        if (isset($tin[$n])) {
            [$xf, $td] = $tin[$n];
            $parts[] = sprintf("[$cur][v$n]xfade=transition=$xf:duration=%.3f:offset=%.3f[x$n]", $td, $curLen - $td);
            $curLen += $len - $td;
        } else {
            $parts[] = "[$cur][v$n]concat=n=2:v=1:a=0[x$n]";
            $curLen += $len;
        }
        $cur = "x$n";
    }
    // Opening di awal video (video diawali footage)
    $parts[] = $opening ? opening_graph($opening, $cur, 'v', (float) $fps) : "[$cur]null[v]";
    $parts[] = $alabels . 'concat=n=' . count($seq) . ':v=0:a=1[a]';
    $total = array_sum($lens);
    $graphFile = TMP_DIR . "/$id/footage.txt";
    file_put_contents($graphFile, implode(";\n", $parts));
    $out = TMP_DIR . "/$id/footage.mp4";
    $lastError = '';
    foreach ($encoders as $enc) {
        $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1' . $inputs
            . ' -/filter_complex ' . escapeshellarg($graphFile) . " -map '[v]' -map '[a]' $enc -c:a aac -b:a 320k -movflags +faststart "
            . escapeshellarg($out);
        [$code, $tail] = run($cmd, function (string $line) use ($id, $total) {
            if (str_starts_with($line, 'out_time_us=')) {
                update_job($id, ['progress' => round(min(99.9, ((int) substr($line, 12)) / 1e6 / max(0.1, $total) * 100), 1)]);
            }
        }, $cancelFlag);
        clearstatcache();
        if ($code === 0 && is_file($out) && filesize($out) > 0 && rename($out, $output)) {
            return;
        }
        $lastError = error_from($tail);
    }
    @unlink($output);
    throw new RuntimeException('Gagal menyambung footage: ' . $lastError);
}

/**
 * Mode kualitas "HD Tajam" / "Ultra HD": bersihkan noise kompresi lalu perbesar bertahap dengan penajaman adaptif (CAS).
 * Sumber ≤1080p (diperbesar): tiap scale lanczos diganti dua tahap (2/3 ukuran → ukuran akhir), masing-masing dipertajam.
 * Sumber lebih besar (diperkecil): cukup scale presisi + penajaman ringan.
 */
function enhance_graph(string $graph, int $vi, int $srcH): string
{
    $flags = 'lanczos+accurate_rnd+full_chroma_int';
    $up = $srcH <= 1080;
    $graph = preg_replace_callback('/scale=(\d+):(-?\d+):flags=lanczos\b/', function ($m) use ($flags, $up) {
        [$w, $h] = [(int) $m[1], (int) $m[2]];
        if (!$up) {
            return "scale=$w:$h:flags=$flags,cas=0.35";
        }
        $w2 = (int) round($w / 3) * 2;
        $h2 = $h < 0 ? $h : (int) round($h / 3) * 2;
        return "scale=$w2:$h2:flags=$flags,cas=0.5,scale=$w:$h:flags=$flags,cas=0.45";
    }, $graph);
    if ($up) {
        $graph = str_replace("[$vi:v]", "[$vi:v]hqdn3d=2:1.5:6:6,", $graph);
    }
    return str_replace(";\n[cv]unsharp=5:5:0.5[cv0]", ";\n[cv]null[cv0]", $graph);
}

/**
 * Tool AI upscale & resolusi akhir clip vertikal: 2K. Mac: super-resolution Apple (tools/superres.swift).
 * Linux / Windows (WSL2): Real-ESRGAN di GPU NVIDIA (tools/superres.py). Antarmuka keduanya sama (frame BGRA lewat pipe).
 */
const SUPERRES_BIN = APP_DIR . '/tools/superres';

function superres_cmd(): ?string
{
    if (IS_MAC) {
        return is_executable(SUPERRES_BIN) ? escapeshellarg(SUPERRES_BIN) : null;
    }
    return is_file(PYTHON) && is_file(STORAGE_DIR . '/models/realesr-general-x4v3.pth')
        ? escapeshellarg(PYTHON) . ' ' . escapeshellarg(APP_DIR . '/tools/superres.py') : null;
}
const AI_W = 1440;
const AI_H = 2560;

/**
 * AI upscale ke 2K: video dasar (1080x1920, tanpa teks) → 540x960 → super-resolution Apple 2x → 1440x2560,
 * lalu subtitle & teks dibakar langsung di 2K (tetap tajam) dengan audio dari video dasar.
 * Kalau AI tidak tersedia / gagal, video dasar diskalakan biasa ke 2K. Mengembalikan catatan untuk hasil render.
 */
function ai_upscale(string $id, string $base, string $output, array $edit, array $assPaths, string $cancelFlag, ?array $opening = null): string
{
    $assPath = $assPaths['sub'] ?? null ?: ($assPaths['text'] ?? null);
    $info = probe($base);
    $dur = max(0.1, (float) $info['duration']);
    $rate = trim((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams v:0 -show_entries stream=r_frame_rate -of csv=p=0 '
        . escapeshellarg($base))) ?: '30';
    $mbps = video_bitrate(['width' => AI_W, 'height' => AI_H, 'fps' => $info['fps']]);
    $encoders = clip_encoders($mbps);
    // Lapisan media / teks / subtitle ditumpuk langsung di 2K sesuai urutan editor
    $k = AI_W / 1080;
    $graphAi = TMP_DIR . "/$id/layers_ai.txt";
    // Opening dipasang paling akhir (di atas subtitle & teks), sama seperti di preview editor
    [$rn, $rd] = array_map('floatval', explode('/', $rate . '/1'));
    $fpsAi = $rd > 0 ? $rn / $rd : 30;
    file_put_contents($graphAi, graph_with_opening(layers_chain($edit, '0:v', 'v', $assPaths, $k), $opening, $fpsAi));
    $graphUp = TMP_DIR . "/$id/layers_up.txt";
    file_put_contents($graphUp, '[0:v]scale=' . AI_W . ':' . AI_H . ":flags=lanczos+accurate_rnd+full_chroma_int,cas=0.4[up];\n"
        . graph_with_opening(layers_chain($edit, 'up', 'v', $assPaths, $k), $opening, $fpsAi));
    $bin = escapeshellarg($assPath ? FFMPEG_FULL : FFMPEG);
    $onLine = function (string $line) use ($id, $dur) {
        if (str_starts_with($line, 'out_time_us=')) {
            update_job($id, ['progress' => round(min(99.9, ((int) substr($line, 12)) / 1e6 / $dur * 100), 1)]);
        }
    };
    $lastError = '';
    foreach ([true, false] as $useAi) {
        if ($useAi && !superres_cmd()) {
            continue;
        }
        foreach ($encoders as $enc) {
            update_job($id, ['status' => 'converting', 'stage' => $useAi ? 'AI upscale ke 2K…' : 'Menskalakan ke 2K…', 'progress' => 0]);
            if ($useAi) {
                $pipe = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -i ' . escapeshellarg($base)
                    . ' -vf scale=540:960:flags=area -f rawvideo -pix_fmt bgra - | '
                    . superres_cmd() . ' 540 960 ' . AI_W . ' ' . AI_H . ' ll | '
                    . "$bin -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -f rawvideo -pix_fmt bgra -s " . AI_W . 'x' . AI_H
                    . " -framerate $rate -i - -i " . escapeshellarg($base) . ' -/filter_complex ' . escapeshellarg($graphAi) . " -map '[v]' -map '1:a?'"
                    . " $enc -c:a copy -shortest -movflags +faststart " . escapeshellarg($output);
                $cmd = '/bin/bash -c ' . escapeshellarg("set -o pipefail; $pipe");
            } else {
                $cmd = "$bin -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -i " . escapeshellarg($base)
                    . ' -/filter_complex ' . escapeshellarg($graphUp) . " -map '[v]' -map '0:a?' $enc -c:a copy -movflags +faststart " . escapeshellarg($output);
            }
            [$code, $tail] = run($cmd, $onLine, $cancelFlag);
            clearstatcache();
            if ($code === 0 && is_file($output) && filesize($output) > 0) {
                @unlink($base);
                return $useAi ? 'AI upscale 2K.' : 'Diskalakan ke 2K (AI upscale tidak tersedia).';
            }
            $lastError = error_from($tail);
            if ($useAi && $code === 3) {
                break; // AI tidak didukung di komputer ini: langsung jalur biasa
            }
        }
    }
    @unlink($output);
    throw new RuntimeException('Gagal membuat video 2K: ' . $lastError);
}

/**
 * Campur musik latar (hasil edit_music_relative) ke audio $output. Video disalin apa adanya (tanpa encode ulang),
 * audio asli tetap di volumenya; tiap potongan musik dipotong, diberi volume & fade, lalu digeser ke posisinya.
 */
function mix_music(string $id, string $output, array $music, string $cancelFlag): void
{
    update_job($id, ['status' => 'converting', 'stage' => 'Menambahkan musik…', 'progress' => 0]);
    $info = probe($output);
    $dur = max(0.1, (float) $info['duration']);
    $inputs = ' -i ' . escapeshellarg($output);
    $graph = [];
    $labels = [];
    foreach ($music as $k => $m) {
        $n = $k + 1;
        $inputs .= ' -i ' . escapeshellarg($m['file']);
        $f = sprintf('[%d:a]atrim=start=%.3f:duration=%.3f,asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo,volume=%s',
            $n, $m['off'], $m['len'], !empty($m['kfexpr']) ? "'" . $m['kfexpr'] . "':eval=frame" : sprintf('%.3f', $m['vol']));
        if ($m['fin'] > 0) {
            $f .= sprintf(',afade=t=in:st=0:d=%.2f', $m['fin']);
        }
        if ($m['fout'] > 0) {
            $f .= sprintf(',afade=t=out:st=%.3f:d=%.2f', max(0, $m['len'] - $m['fout']), $m['fout']);
        }
        $ms = (int) round($m['at'] * 1000);
        $graph[] = $f . ($ms > 0 ? ",adelay=$ms:all=1" : '') . "[m$k]";
        $labels[] = "[m$k]";
    }
    // Clip tanpa audio: dasar campuran berupa hening sepanjang clip
    if ($info['acodec']) {
        $graph[] = '[0:a]aformat=sample_rates=48000:channel_layouts=stereo[base]';
    } else {
        $graph[] = sprintf('anullsrc=r=48000:cl=stereo,atrim=duration=%.3f[base]', $dur);
    }
    $graph[] = '[base]' . implode('', $labels) . 'amix=inputs=' . (count($labels) + 1)
        . ':duration=first:dropout_transition=0:normalize=0,alimiter=limit=0.97:level=disabled[aout]';
    $graphFile = TMP_DIR . "/$id/music.txt";
    @mkdir(dirname($graphFile), 0777, true);
    file_put_contents($graphFile, implode(";\n", $graph));
    $tmp = TMP_DIR . "/$id/music.mp4";
    $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1' . $inputs
        . ' -/filter_complex ' . escapeshellarg($graphFile) . " -map 0:v -map '[aout]' -c:v copy"
        . ($info['vcodec'] === 'hevc' ? ' -tag:v hvc1' : '') . ' -c:a aac -b:a 320k -movflags +faststart ' . escapeshellarg($tmp);
    [$code, $tail] = run($cmd, function (string $line) use ($id, $dur) {
        if (str_starts_with($line, 'out_time_us=')) {
            update_job($id, ['progress' => round(min(99.9, ((int) substr($line, 12)) / 1e6 / $dur * 100), 1)]);
        }
    }, $cancelFlag);
    clearstatcache();
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) === 0 || !rename($tmp, $output)) {
        @unlink($tmp);
        @unlink($output);
        throw new RuntimeException('Gagal menambahkan musik: ' . error_from($tail));
    }
}

function run_clip(string $id, array $job, string $cancelFlag): void
{
    // Clip proyek memakai resep edit: disiapkan dulu kalau belum ada, lalu render mengikuti resep
    $edit = null;
    $splitOn = false;
    if (!empty($job['project_id']) && !empty($job['item_id'])) {
        // Kalau job persiapan clip ini sedang/akan berjalan, tunggu saja (jangan ikut menyiapkan bersamaan)
        wait_prepare_job((string) ($job['prepare_job'] ?? ''), $id, $cancelFlag);
        $edit = ensure_edit($id, $job, $cancelFlag);
        $job['start'] = $edit['trim'][0];
        $job['end'] = $edit['trim'][1];
        $splitOn = split_cfg($edit)['enabled'];
        // Split screen otomatis dirender lewat jalur "split" (bagian 2 orang = split, sisanya framing dasar).
        // split_cfg() selalu mati untuk layout streaming game.
        $job['layout'] = $splitOn ? 'split' : base_layout($edit);
    }
    $start = max(0, (float) $job['start']);
    $end = (float) $job['end'];
    $dur = $end - $start;
    $layout = isset(CLIP_LAYOUTS[$job['layout'] ?? '']) ? $job['layout'] : 'landscape';
    if ($dur <= 0.5 || $dur > 600) {
        throw new RuntimeException('Durasi clip tidak valid.');
    }
    $vertical = $layout !== 'landscape';
    // Layout bingkai dari editor (rasio & sudut membulat); split screen selalu layar penuh
    if ($layout === 'letterbox') {
        $layout = 'crop_center';
    }
    [$fAspect, $fH, $fY0] = $edit && $layout !== 'split' ? frame_geom($edit) : [9 / 16, 1920, 0];
    $clipFilter = fn($l) => $fH < 1920 && in_array($l, ['crop_center', 'blur'], true)
        ? "crop='min(iw,trunc(ih*$fAspect/2)*2)':'min(ih,trunc(iw/$fAspect/2)*2)',scale=1080:$fH:flags=lanczos,setsar=1,pad=1080:1920:0:$fY0:black"
        : clip_filter($l);
    $frameMask = $edit && $vertical && $layout !== 'split' ? frame_mask($edit) : null;
    $filter = $clipFilter($layout);
    $title = safe_filename($job['title'] ?: 'clip');
    $range = str_replace(':', '.', fmt_ts($start)) . '-' . str_replace(':', '.', fmt_ts($end));

    // Video sumber lokal (sudah diunduh di room analisa): jauh lebih cepat & bebas error 403
    $local = !empty($job['analysis_id']) ? wait_local_source($job['analysis_id'], $id, $cancelFlag, $start, $end) : null;
    $output = null;
    $video = null;
    $ok = false;
    $assPath = null;
    $subNote = '';
    $subsDone = false;
    $segments = null;
    $camChecked = false;
    $layoutNote = '';
    $lastError = '';
    // Kualitas dari editor: standard | hd (dipertajam) | ultra (sumber resolusi tertinggi dari YouTube + dipertajam).
    // Sumber lokal hanya 1080p; ultra mengambil stream terbaik (bisa 1440p/4K) dan kembali ke sumber lokal kalau gagal.
    $quality = $edit ? ($edit['quality'] ?? 'hd') : 'standard';
    $enhance = $quality !== 'standard';
    $preferStream = $quality === 'ultra' && !empty($job['url']);
    $qualityNote = '';
    // AI upscale 2K untuk clip proyek vertikal: render dasar 1080x1920 tanpa teks ke file sementara, lalu ai_upscale()
    $aiMode = $edit && $layout !== 'landscape';
    if ($aiMode) {
        [$enhance, $preferStream] = [false, false];
    }
    // Animasi opening di awal video hasil akhir: dipasang di render clip, atau di sambungan footage kalau video diawali footage
    $opening = $edit['opening'] ?? null;
    $preSeq = $opening ? edit_footage_sequence($edit) : [];
    $openingAt = !$opening ? null : ($preSeq && $preSeq[0] !== 'main' ? 'footage' : 'main');
    $baseFile = TMP_DIR . "/$id/base.mp4";
    $aiNote = '';
    $assPaths = ['sub' => null, 'text' => null]; // ASS per lapisan (clip proyek)
    // Stream URL YouTube kadang ditolak (403); ambil URL baru lalu coba lagi
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($local && !$preferStream) {
            $video = $audio = local_format($local);
        } else {
            update_job($id, ['status' => 'downloading', 'progress' => 0,
                'stage' => $attempt === 1 ? 'Mencari stream kualitas terbaik…' : "Stream ditolak, mencoba lagi ($attempt/3)…"]);
            if ($attempt > 1) {
                sleep(2);
            }
            try {
                [$video, $audio] = clip_streams($job['url'], $cancelFlag);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                if (!$local) {
                    throw $e;
                }
                $preferStream = false;
                $qualityNote = 'Sumber resolusi tinggi tidak tersedia, memakai sumber lokal.';
                $video = $audio = local_format($local);
            }
        }
        [$inputs, $vi, $ai] = clip_inputs($video, $audio, $start);
        if (!$subsDone) {
            if ($edit) {
                // Subtitle & hook dari resep edit (teks dan gaya yang sudah diatur di editor)
                $lines = $edit['subtitles'] ? edit_lines_relative($edit) : [];
                $hookOn = !empty($edit['hook']['enabled']) && trim($edit['hook']['text']) !== '';
                $texts = edit_texts_relative($edit);
                $kinetic = ($edit['caption'] ?? '') === 'kinetic' && $edit['subtitles'] && !empty($edit['kinetic']['beats']);
                if ($kinetic) {
                    // Teks kinetik menggantikan subtitle biasa di lapisan subtitle
                    $assPaths['sub'] = TMP_DIR . "/$id/sub.ass";
                    file_put_contents($assPaths['sub'], build_kinetic_ass($edit['kinetic']['beats'], (float) ($edit['kinetic']['y'] ?? 0.3), $start, $dur, (float) ($edit['kinetic']['x'] ?? 0.5)));
                } elseif ($lines) {
                    $assPaths['sub'] = TMP_DIR . "/$id/sub.ass";
                    file_put_contents($assPaths['sub'], build_ass_lines($lines, $edit['style'], $layout, ['enabled' => false] + $edit['hook'], $dur, []));
                }
                if ($hookOn || $texts) {
                    $assPaths['text'] = TMP_DIR . "/$id/text.ass";
                    file_put_contents($assPaths['text'], build_ass_lines([], $edit['style'], $layout, $edit['hook'], $dur, $texts));
                }
                $assPath = $assPaths['sub'] ?: $assPaths['text'];
                $job['subtitles'] = (bool) $lines;
                $subNote = $lines ? count($lines) . ' baris subtitle dari editor.' : '';
            } else {
                [$assPath, $subNote] = clip_subtitles($id, $job, $audio, $start, $dur, $layout, $cancelFlag);
            }
            $subsDone = true;
        }
        if ($layout === 'split' && $segments === null) {
            // Split screen butuh data shot dari resep edit; resep lama dihitung ulang dulu
            if ($edit && (!isset($edit['shots']) || empty($edit['precise']))) {
                update_job($id, ['status' => 'converting', 'stage' => 'Mendeteksi posisi orang untuk split screen…']);
                $edit = retrack_edit($job['project_id'], $job['item_id']);
            }
            $segments = $edit ? edit_split_relative($edit) : [];
            if (!$segments) {
                $layout = 'crop_center';
                $filter = $clipFilter($layout);
                $layoutNote = 'Data wajah tidak tersedia, memakai crop tengah.';
            } else {
                $n2 = count(array_filter($segments, fn($x) => $x['mode'] === 'split'));
                $layoutNote = "$n2 bagian split screen, " . (count($segments) - $n2) . ' bagian satu orang.';
            }
        }
        if ($layout === 'gaming') {
            // Resep lama belum punya data webcam: deteksi ulang sekali
            if ($edit && !$camChecked && !array_key_exists('cam', $edit)) {
                update_job($id, ['status' => 'converting', 'stage' => 'Mencari posisi webcam streamer…']);
                $edit = retrack_edit($job['project_id'], $job['item_id']);
            }
            $camChecked = true;
            if (!empty($edit['cam'])) {
                $filter = gaming_filter($edit['cam'], (int) $video['width'], (int) $video['height']);
                $layoutNote = 'Game 70% di atas, webcam streamer 30% di bawah.';
            } else {
                $layout = 'crop_center';
                $filter = $clipFilter($layout);
                $layoutNote = 'Webcam streamer tidak terdeteksi, memakai crop tengah.';
            }
        }
        if ($layout === 'speaker' && $segments === null) {
            $segments = $edit ? edit_segments_relative($edit) : speaker_segments($id, $job, $start, $dur, $cancelFlag);
            if (!$segments) {
                $layout = 'crop_center'; // tidak ada wajah: kembali ke crop tengah
                $filter = $clipFilter($layout);
                $layoutNote = 'Wajah tidak terdeteksi, memakai crop tengah.';
            } else {
                $layoutNote = count($segments) . ' segmen kamera mengikuti pembicara.';
            }
        }

        $fps = (float) ($video['fps'] ?? 30);
        $outSize = $vertical ? ['width' => 1080, 'height' => 1920, 'fps' => $fps]
            : ['width' => (int) ($video['width'] ?? 1920), 'height' => (int) ($video['height'] ?? 1080), 'fps' => $fps];
        $mbps = video_bitrate($outSize);
        if ($enhance) {
            $mbps = (int) round($mbps * 1.4); // detail hasil penajaman butuh bitrate lebih
        }
        $tag = match ($layout) {
            'blur' => '9x16 blur',
            'letterbox' => '9x16 letterbox',
            'speaker' => '9x16',
            'split' => '9x16 split',
            'gaming' => '9x16 game',
            'landscape' => quality_tag($outSize),
            default => '9x16 ' . substr($layout, 5),
        } . ($aiMode ? ' 2K' : '') . ($assPath && !empty($job['subtitles']) ? ' sub' : '');
        // Render dengan musik diberi penanda di nama file; render ulang selalu dapat nama baru (riwayat render tidak tertimpa)
        // Mode "keduanya": file utama tanpa musik, versi musik dibuat terpisah (lihat di bawah)
        $withMusic = $edit && empty($job['no_music']) && empty($job['both_music']) && edit_music_relative($edit);
        $outName = "$title [clip $range $tag";
        $output ??= unique_path(DOWNLOAD_DIR, $outName . ($withMusic ? ' musik' : '') . ']', 'mp4');

        // Subtitle dibakar setelah layout (crop/blur), memakai ffmpeg-full yang punya libass.
        // Filtergraph ditulis ke file (-/filter_complex) karena bisa panjang untuk mode ikuti pembicara.
        $ass = $assPath && !$edit && !$aiMode ? 'ass=filename=' . $assPath . ':fontsdir=' . SUB_FONTS_DIR : '';
        $target = $aiMode ? $baseFile : $output;
        // Urutan: layout -> (tajamkan) -> efek -> lapisan media / teks / subtitle sesuai urutan editor.
        // Mode AI: lapisan dibakar setelah upscale 2K (lihat ai_upscale), jadi di sini hanya efek.
        $layered = $edit && $vertical;
        $finish = fn(string $from) => $layered && !$aiMode
            ? layers_chain($edit, $from, 'v', $assPaths)
            : "[$from]" . ($ass ? "$ass," : '') . 'format=yuv420p[v]';
        $overlay = $layered ? overlay_graph($edit, 'cv', 'co', false) : '';
        // Masker bingkai (bar hitam & sudut membulat) di atas video + efek, di bawah media & teks
        $masked = fn(string $from) => $frameMask
            ? "movie=$frameMask,format=rgba[fmk];\n[$from][fmk]overlay=0:0:format=auto[fmo];\n"
            : "[$from]null[fmo];\n";
        if (($layout === 'speaker' || $layout === 'split') && $segments) {
            $graph = ($layout === 'split'
                ? split_graph($segments, (int) $video['width'], (int) $video['height'], $vi, $dur)
                : speaker_graph($segments, (int) $video['width'], (int) $video['height'], $vi, $dur, $fAspect, $fH, $edit ? edit_cut_times($edit) : null))
                . ";\n[cv]unsharp=5:5:0.5[cv0]";
            $graph .= $overlay ? ";\n" . overlay_graph($edit, 'cv0', 'co', false) : '';
            $graph .= ";\n" . $masked($overlay ? 'co' : 'cv0') . $finish('fmo');
        } elseif ($overlay) {
            $graph = "[$vi:v]" . ($filter ?: 'null') . "[cv];\n$overlay;\n" . $masked('co') . $finish('fmo');
        } elseif ($layered) {
            $graph = "[$vi:v]" . ($filter ?: 'null') . "[cv];\n" . $masked('cv') . $finish('fmo');
        } else {
            $chain = array_filter([$filter, $ass]);
            $graph = $chain ? "[$vi:v]" . implode(',', $chain) . ',format=yuv420p[v]' : '';
        }
        if ($graph !== '' && $enhance && $vertical) {
            $graph = enhance_graph($graph, $vi, (int) ($video['height'] ?? 1080));
        }
        // Animasi opening: di sini kalau video diawali clip ini & tanpa AI upscale (mode AI: di ai_upscale, ada footage awal: di footage_concat)
        if ($openingAt === 'main' && !$aiMode) {
            $graph = graph_with_opening($graph !== '' ? $graph : "[$vi:v]format=yuv420p[v]", $opening, $fps);
        }
        if ($graph !== '') {
            $graphFile = TMP_DIR . "/$id/graph.txt";
            file_put_contents($graphFile, $graph);
            $vmap = '-/filter_complex ' . escapeshellarg($graphFile) . " -map '[v]'";
        } else {
            $vmap = "-map $vi:v:0 -pix_fmt yuv420p";
        }
        // Volume dari editor (100% = asli)
        $vol = $edit ? (int) ($edit['volume'] ?? 100) : 100;
        if (!empty($edit['tracks']['video']['mute'])) {
            $vol = 0; // track video dibisukan di editor
        }
        $afilter = $vol !== 100 ? ' -af volume=' . number_format($vol / 100, 2, '.', '') : '';
        // Keyframe volume dari editor: kurva volume (t=0 = awal clip)
        if (empty($edit['tracks']['video']['mute']) && !empty($edit['volKf'])) {
            $afilter = ' -af ' . escapeshellarg("volume='" . volume_kf_expr($edit['volKf'], (float) $edit['trim'][0]) . "':eval=frame");
        }
        $amap = $ai !== null ? "-map $ai:a:0$afilter -c:a aac -b:a 320k" : '-an';
        $bin = $assPath && !$aiMode ? FFMPEG_FULL : FFMPEG;
        $base = escapeshellarg($bin) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1'
            . $inputs . ' -t ' . number_format($dur, 3, '.', '') . " $vmap $amap -movflags +faststart";
        // Video dasar mode AI hanya perantara (dihapus setelah upscale), jadi tetap kualitas maksimal
        $encoders = $aiMode ? clip_encoders($mbps, 'max') : clip_encoders($mbps);

        foreach ($encoders as $i => $enc) {
            update_job($id, ['status' => 'converting', 'stage' => $i === 0 ? 'Memotong & encode clip…' : 'Memotong (mode software)…',
                'progress' => 0, 'speed' => '', 'eta' => '']);
            $lastWrite = 0;
            try {
                [$code, $tail] = run("$base $enc " . escapeshellarg($target), function (string $line) use ($id, $dur, &$lastWrite) {
                    if (str_starts_with($line, 'out_time_us=') && microtime(true) - $lastWrite > 0.7) {
                        $sec = ((int) substr($line, 12)) / 1e6;
                        update_job($id, ['progress' => round(min(99.9, max(0, $sec / $dur * 100)), 1)]);
                        $lastWrite = microtime(true);
                    }
                }, $cancelFlag);
            } catch (Cancelled $e) {
                @unlink($target);
                throw $e;
            }
            clearstatcache();
            if ($code === 0 && is_file($target) && filesize($target) > 0) {
                $ok = true;
                break 2;
            }
            $lastError = error_from($tail);
            if (preg_match('/40[0-9] |Error opening input|HTTP error|Connection|I\/O error/i', $lastError)) {
                if ($preferStream && $local && $attempt >= 2) {
                    $preferStream = false;
                    $qualityNote = 'Sumber resolusi tinggi tidak tersedia, memakai sumber lokal.';
                }
                continue 2; // masalah stream: ambil URL baru
            }
        }
        break; // masalah encode, bukan stream: tidak perlu diulang
    }

    if (!$ok) {
        if ($output) {
            @unlink($output);
        }
        throw new RuntimeException('Gagal memotong clip: ' . $lastError);
    }
    // AI upscale 2K + subtitle/teks (sebelum bagian terhapus dibuang, supaya waktu teks tetap sinkron)
    if ($aiMode) {
        $aiNote = ai_upscale($id, $baseFile, $output, $edit, $assPaths, $cancelFlag, $openingAt === 'main' ? $opening : null);
    }

    // Musik latar dari editor dicampur ke audio clip (sebelum bagian terhapus dibuang, supaya ikut terpotong sinkron)
    $music = $edit && empty($job['no_music']) ? edit_music_relative($edit) : [];
    // Render dengan & tanpa musik sekaligus: salinan video yang sama (tanpa encode ulang) diberi musik, jadi gambarnya identik
    $musicOut = null;
    if ($music && !empty($job['both_music'])) {
        $musicOut = unique_path(DOWNLOAD_DIR, ($outName ?? pathinfo($output, PATHINFO_FILENAME)) . ' musik]', 'mp4');
        if (!copy($output, $musicOut)) {
            @unlink($musicOut);
            throw new RuntimeException('Gagal menyiapkan versi dengan musik.');
        }
        try {
            mix_music($id, $musicOut, $music, $cancelFlag);
        } catch (Throwable $e) {
            @unlink($musicOut);
            throw $e;
        }
    } elseif ($music) {
        mix_music($id, $output, $music, $cancelFlag);
    }

    // Langkah akhir dijalankan untuk tiap file hasil (versi tanpa musik & versi musik)
    $fail = function (string $msg) use (&$output, &$musicOut) {
        @unlink($output);
        if ($musicOut) {
            @unlink($musicOut);
        }
        throw new RuntimeException($msg);
    };
    foreach (array_filter([$output, $musicOut]) as $file) {
        $ver = $file === $musicOut ? ' (versi musik)' : ($musicOut ? ' (versi tanpa musik)' : '');
        // Bagian video yang dihapus di editor (hasil potong): buang dari hasil render lalu sambung sisanya.
        // Subtitle, teks, & media sudah ikut terbakar, jadi waktunya otomatis tetap sinkron.
        $keep = $edit ? edit_keep_ranges($edit) : [];
        if ($keep) {
            update_job($id, ['status' => 'converting', 'stage' => 'Membuang bagian yang dihapus…' . $ver, 'progress' => 0]);
            $expr = implode('+', array_map(fn($r) => sprintf('between(t,%.3f,%.3f)', $r[0], $r[1]), $keep));
            $keptDur = array_sum(array_map(fn($r) => $r[1] - $r[0], $keep));
            $cutOut = TMP_DIR . "/$id/cut.mp4";
            $cutOk = false;
            foreach ($encoders as $enc) {
                $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -i ' . escapeshellarg($file)
                    . ' -vf ' . escapeshellarg("select='$expr',setpts=N/FRAME_RATE/TB") . ' -af ' . escapeshellarg("aselect='$expr',asetpts=N/SR/TB")
                    . " $enc -c:a aac -b:a 320k -movflags +faststart " . escapeshellarg($cutOut);
                [$code, $tail] = run($cmd, function (string $line) use ($id, $keptDur) {
                    if (str_starts_with($line, 'out_time_us=')) {
                        update_job($id, ['progress' => round(min(99.9, ((int) substr($line, 12)) / 1e6 / max(0.1, $keptDur) * 100), 1)]);
                    }
                }, $cancelFlag);
                clearstatcache();
                if ($code === 0 && is_file($cutOut) && filesize($cutOut) > 0) {
                    $cutOk = rename($cutOut, $file);
                    break;
                }
                $lastError = error_from($tail);
            }
            if (!$cutOk) {
                $fail('Gagal membuang bagian yang dihapus: ' . $lastError);
            }
        }

        // Footage tambahan dari editor: disambung di awal / akhir clip
        $seq = $edit ? edit_footage_sequence($edit) : [];
        if ($seq) {
            footage_concat($id, $file, $seq, $encoders, $cancelFlag, (array) ($edit['transitions'] ?? []), (array) ($edit['tracks'] ?? []),
                $openingAt === 'footage' ? $opening : null);
        }
    }

    @chmod($output, 0666);
    if ($musicOut) {
        @chmod($musicOut, 0666);
    }
    $final = probe($output);
    update_job($id, [
        'status' => 'done',
        'stage' => 'Selesai',
        'progress' => 100,
        'speed' => '',
        'eta' => '',
        'file' => basename($output),
        'path' => $output,
        'size' => filesize($output),
        'with_music' => (bool) $music && !$musicOut,
        // Render "keduanya": file = tanpa musik, file_music = dengan musik
        'both_music' => (bool) $musicOut,
        'file_music' => $musicOut ? basename($musicOut) : null,
        'size_music' => $musicOut ? filesize($musicOut) : null,
        'result' => [
            'resolution' => "{$final['width']}×{$final['height']}",
            'fps' => $final['fps'] ?: null,
            'vcodec' => $final['vcodec'],
            'acodec' => $final['acodec'],
            'duration' => $final['duration'],
        ],
        'note' => trim('Sumber ' . ($video['height'] ?? '?') . 'p ' . strtoupper(explode('.', (string) $video['vcodec'])[0])
            . ', ' . CLIP_LAYOUTS[$layout] . '. ' . $layoutNote . ' ' . $subNote
            . ($enhance ? ' Kualitas: ' . ($quality === 'ultra' ? 'Ultra HD' : 'HD Tajam') . '. ' . $qualityNote : '') . ' ' . $aiNote),
    ]);
}
