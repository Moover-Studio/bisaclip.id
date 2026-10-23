<?php
// Project manual (beranda → "Buat project"): user mengunggah footage (video / gambar), menyusunnya bebas di timeline
// (seret, ubah urutan, potong awal/akhir), lalu render dengan kualitas pilihan — sesuai footage, resolusi tertentu,
// atau upscale AI. Terpisah dari proyek Clip Studio (storage/projects) yang berasal dari video YouTube.

define('MPROJ_DIR', STORAGE_DIR . '/manual');           // data project + file asli footage
define('MPROJ_PUB', APP_DIR . '/previews/manual');      // thumbnail & preview ringan (dibuka browser)
const MPROJ_VIDEO_EXTS = ['mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi'];
const MPROJ_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
// Rasio layar hasil: key => [lebar, tinggi] pada 1080p (sisi pendek 1080)
const MPROJ_RATIOS = ['9:16' => [1080, 1920], '16:9' => [1920, 1080], '1:1' => [1080, 1080], '4:5' => [1080, 1350], '3:4' => [1080, 1440]];
const MPROJ_QUALITIES = [720, 1080, 1440, 2160];        // sisi pendek hasil render

function mproj_path(string $id): string
{
    return MPROJ_DIR . "/$id.json";
}

function mproj_media_dir(string $id): string
{
    return MPROJ_DIR . "/$id";
}

function mproj_pub_dir(string $id): string
{
    $d = MPROJ_PUB . "/$id";
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
        @chmod(MPROJ_PUB, 0777);
        @chmod($d, 0777);
    }
    return $d;
}

function read_mproj(string $id): ?array
{
    if (!valid_job_id($id)) {
        return null;
    }
    $p = json_decode((string) @file_get_contents(mproj_path($id)), true);
    return is_array($p) ? $p : null;
}

function write_mproj(array $p): void
{
    if (!is_dir(MPROJ_DIR)) {
        @mkdir(MPROJ_DIR, 0777, true);
        @chmod(MPROJ_DIR, 0777);
    }
    $p['updated_at'] = time();
    file_put_contents(mproj_path($p['id']), json_encode($p, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(mproj_path($p['id']), 0666);
}

/** Ubah project secara aman (worker & editor bisa menulis bersamaan). */
function update_mproj(string $id, callable $fn): ?array
{
    $lockPath = MPROJ_DIR . "/$id.lock";
    $lock = fopen($lockPath, 'c');
    @chmod($lockPath, 0666);
    flock($lock, LOCK_EX);
    try {
        $p = read_mproj($id);
        if (!$p) {
            return null;
        }
        $p = $fn($p) ?? $p;
        write_mproj($p);
        return $p;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** File asli sebuah media. */
function mproj_media_file(string $pid, array $m): string
{
    return mproj_media_dir($pid) . "/{$m['mid']}.{$m['ext']}";
}

/** Ada tool AI upscale di komputer ini? (sama dengan superres_cmd() di clip.php, versi ringan untuk API) */
function mproj_ai_available(): bool
{
    return IS_MAC ? is_executable(APP_DIR . '/tools/superres')
        : is_file(PYTHON) && is_file(STORAGE_DIR . '/models/realesr-general-x4v3.pth');
}

/** Info video / gambar: durasi, ukuran, fps, ada audio. */
function mproj_probe(string $file): array
{
    $d = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -print_format json -show_streams -show_format ' . escapeshellarg($file)), true) ?: [];
    $info = ['duration' => (float) ($d['format']['duration'] ?? 0), 'width' => 0, 'height' => 0, 'fps' => 0, 'audio' => false];
    foreach ($d['streams'] ?? [] as $s) {
        if ($s['codec_type'] === 'video' && !$info['width']) {
            [$n, $dn] = array_map('floatval', explode('/', $s['avg_frame_rate'] ?? '0/1') + [1 => 1]);
            $info = array_merge($info, ['width' => (int) $s['width'], 'height' => (int) $s['height'], 'fps' => $dn > 0 ? round($n / $dn, 3) : 0]);
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

/** Ukuran hasil render (genap) untuk rasio & sisi pendek tertentu. */
function mproj_canvas(string $ratio, int $short): array
{
    [$w, $h] = MPROJ_RATIOS[$ratio] ?? MPROJ_RATIOS['9:16'];
    $k = $short / min($w, $h);
    return [(int) (round($w * $k / 2) * 2), (int) (round($h * $k / 2) * 2)];
}

/** Sisi pendek resolusi "sesuai footage": footage terbesar yang dipakai di timeline (720–2160). */
function mproj_native_short(array $p): int
{
    $best = 0;
    foreach ($p['timeline'] ?? [] as $c) {
        $m = $p['media'][$c['mid']] ?? null;
        if ($m && ($m['status'] ?? '') === 'done') {
            $best = max($best, min((int) $m['width'], (int) $m['height']));
        }
    }
    if (!$best) {
        return 1080;
    }
    // Pilihan kualitas tertinggi yang tidak melebihi footage (toleransi 10%), minimal 720p
    $pick = 720;
    foreach (MPROJ_QUALITIES as $q) {
        if ($best >= $q * 0.9) {
            $pick = $q;
        }
    }
    return $pick;
}

/* ---------- Worker: siapkan media (info, thumbnail, preview ringan) ---------- */

function run_mproj_media(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    [$pid, $mid] = [$job['project_id'], $job['media_id']];
    $p = read_mproj($pid);
    $m = $p['media'][$mid] ?? null;
    $src = $m ? mproj_media_file($pid, $m) : '';
    if (!$m || !is_file($src)) {
        throw new RuntimeException('File media tidak ditemukan.');
    }
    update_job($id, ['status' => 'converting', 'stage' => 'Membaca file…', 'progress' => 10]);
    $info = mproj_probe($src);
    if (!$info['width']) {
        throw new RuntimeException('File ini tidak bisa dibaca sebagai video / gambar.');
    }
    $pub = mproj_pub_dir($pid);
    $image = $m['kind'] === 'image';
    exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . ($image ? '' : ' -ss ' . sprintf('%.2f', min(1, $info['duration'] / 3)))
        . ' -i ' . escapeshellarg($src) . ' -frames:v 1 -vf scale=320:-2 -q:v 4 ' . escapeshellarg("$pub/$mid.jpg"));
    @chmod("$pub/$mid.jpg", 0666);
    $patch = ['width' => $info['width'], 'height' => $info['height'], 'thumb' => "previews/manual/$pid/$mid.jpg"];
    if ($image) {
        // Gambar: preview = gambarnya sendiri (diperkecil), durasi bawaan di timeline 3 detik
        exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($src)
            . " -vf \"scale='min(1280,iw)':-2\" -q:v 3 " . escapeshellarg("$pub/{$mid}_p.jpg"));
        @chmod("$pub/{$mid}_p.jpg", 0666);
        $patch += ['duration' => 0, 'fps' => 0, 'audio' => false, 'preview' => "previews/manual/$pid/{$mid}_p.jpg"];
    } else {
        // Video: preview 540p ringan untuk diputar di editor (render tetap memakai file asli)
        update_job($id, ['stage' => 'Membuat preview…', 'progress' => 30]);
        $short = min($info['width'], $info['height']);
        $scale = $info['width'] >= $info['height'] ? 'scale=-2:' . min(540, $short) : 'scale=' . min(540, $short) . ':-2';
        $prev = "$pub/$mid.mp4";
        foreach (venc_list(['-c:v h264_videotoolbox -b:v 2500k', '-c:v libx264 -preset veryfast -crf 24']) as $enc) {
            [$code] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($src)
                . " -map 0:v:0 -map '0:a:0?' -vf $scale -pix_fmt yuv420p $enc -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($prev),
                fn() => null, $cancelFlag, 1800);
            if ($code === 0 && is_file($prev) && filesize($prev) > 0) {
                break;
            }
        }
        if (!is_file($prev)) {
            throw new RuntimeException('Gagal membuat preview video.');
        }
        @chmod($prev, 0666);
        $patch += ['duration' => round($info['duration'], 3), 'fps' => $info['fps'], 'audio' => $info['audio'], 'preview' => "previews/manual/$pid/$mid.mp4"];
    }
    update_mproj($pid, function ($p) use ($mid, $patch) {
        if (isset($p['media'][$mid])) {
            $p['media'][$mid] = array_merge($p['media'][$mid], $patch, ['status' => 'done', 'error' => '']);
            // Footage yang diunggah pertama menentukan rasio "Asli" project (bukan yang selesai diproses duluan)
            if (empty($p['ratio_auto_done']) && array_key_first($p['media']) === $mid) {
                $r = $patch['width'] / max(1, $patch['height']);
                $best = '9:16';
                foreach (MPROJ_RATIOS as $k => [$w, $h]) {
                    if (abs($w / $h - $r) < abs(MPROJ_RATIOS[$best][0] / MPROJ_RATIOS[$best][1] - $r)) {
                        $best = $k;
                    }
                }
                if (empty($p['ratio_set'])) {
                    $p['ratio'] = $best;
                }
                $p['ratio_auto_done'] = true;
            }
        }
        return $p;
    });
    update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100]);
}

/* ---------- Worker: render timeline ---------- */

/**
 * Graph penyusun: tiap potongan dipaskan ke layar (isi penuh / utuh + latar blur / utuh + hitam), fps sama,
 * lalu disambung berurutan. Audio: suara footage (kecuali dibisukan) atau hening untuk gambar.
 */
/**
 * Graph render lengkap: potongan footage (+ transisi), grafik/teks overlay, musik latar. Keluaran [v] & [a].
 * Rumus waktu sama dengan editor: transisi berpusat di sambungan, total durasi = jumlah durasi potongan.
 */
function mproj_graph(array $p, array $clips, int $W, int $H, float $fps, array $overlays = [], array $music = [], array $pips = [], array $tracks = []): array
{
    // Track yang disembunyikan (mata) tidak dirender; track yang dibisukan tidak masuk audio
    $hide = fn($k) => !empty($tracks[$k]['hide']);
    $mute = fn($k) => !empty($tracks[$k]['mute']);
    $inputs = '';
    $parts = [];
    $acat = '';
    $vlen = [];
    // Transisi masuk ke potongan n: [nama xfade, durasi], dibatasi panjang kedua sisi
    $tin = [];
    foreach ($clips as $n => $c) {
        $tr = $c['tin'] ?? null;
        if ($n > 0 && $tr && isset(TRANSITION_PRESETS[$tr['type'] ?? ''])) {
            $tin[$n] = [TRANSITION_PRESETS[$tr['type']]['xf'], round(min((float) $tr['dur'], $clips[$n - 1]['out'] - $clips[$n - 1]['in'], $c['out'] - $c['in']), 3)];
        }
    }
    foreach ($clips as $n => $c) {
        $m = $p['media'][$c['mid']];
        $len = round($c['out'] - $c['in'], 3);
        $file = mproj_media_file($p['id'], $m);
        $inputs .= $m['kind'] === 'image'
            ? sprintf(' -loop 1 -framerate %s -t %.3f -i %s', $fps, $len, escapeshellarg($file))
            : sprintf(' -ss %.3f -t %.3f -i %s', $c['in'], $len, escapeshellarg($file));
        $fit = $c['fit'] ?? $p['fit'] ?? 'blur';
        if (!empty($c['tr'])) {
            // Ukuran & posisi pilihan: latar blur (atau hitam) + footage diperkecil/diperbesar di titik tengah tr (sama dengan editor)
            $k = min($W / max(1, $m['width']), $H / max(1, $m['height']));
            $fw = max(2, (int) (round($m['width'] * $k * $c['tr']['s'] / 2) * 2));
            $fh = max(2, (int) (round($m['height'] * $k * $c['tr']['s'] / 2) * 2));
            $ox = (int) round($c['tr']['x'] * $W - $fw / 2);
            $oy = (int) round($c['tr']['y'] * $H - $fh / 2);
            $vf = "split[ta$n][tb$n];[ta$n]scale=$W:$H:force_original_aspect_ratio=increase,crop=$W:$H,boxblur=40:2" . ($fit === 'black' ? ',drawbox=c=black:t=fill' : '')
                . "[tg$n];[tb$n]scale=$fw:$fh:flags=lanczos[tf$n];[tg$n][tf$n]overlay=x=$ox:y=$oy";
        } else $vf = match ($fit) {
            'fill' => "scale=$W:$H:force_original_aspect_ratio=increase:flags=lanczos,crop=$W:$H",
            'black' => "scale=$W:$H:force_original_aspect_ratio=decrease:flags=lanczos,pad=$W:$H:(ow-iw)/2:(oh-ih)/2:black",
            default => "split[fa$n][fb$n];[fa$n]scale=$W:$H:force_original_aspect_ratio=increase,crop=$W:$H,boxblur=40:2[bg$n];"
                . "[fb$n]scale=$W:$H:force_original_aspect_ratio=decrease:flags=lanczos[fg$n];[bg$n][fg$n]overlay=(W-w)/2:(H-h)/2",
        };
        // Transisi: kedua sisi sambungan diperpanjang setengah durasi transisi dengan frame diam (total durasi tetap sama dengan timeline)
        $padIn = isset($tin[$n]) ? $tin[$n][1] / 2 : 0;
        $padOut = isset($tin[$n + 1]) ? $tin[$n + 1][1] / 2 : 0;
        $tpad = $padIn || $padOut ? sprintf(',tpad=start_mode=clone:start_duration=%.3f:stop_mode=clone:stop_duration=%.3f', $padIn, $padOut) : '';
        $vlen[$n] = $padIn + $len + $padOut;
        $parts[] = "[$n:v]$vf,setsar=1,fps=$fps,format=yuv420p,trim=0:$len,setpts=PTS-STARTPTS{$tpad}[v$n]";
        $vol = max(0, min(2, ($c['vol'] ?? 100) / 100));
        $fo = max(0, $len - 0.05);
        $parts[] = $m['kind'] !== 'image' && !empty($m['audio']) && $vol > 0
            ? "[$n:a]aformat=sample_rates=48000:channel_layouts=stereo,apad,atrim=0:$len,asetpts=PTS-STARTPTS,volume=$vol,afade=t=in:d=0.03,afade=t=out:st=$fo:d=0.05[a$n]"
            : "anullsrc=r=48000:cl=stereo,atrim=0:$len,asetpts=PTS-STARTPTS[a$n]";
        $acat .= "[a$n]";
    }
    // Video: sambung berurutan, xfade di sambungan yang punya transisi
    $cur = 'v0';
    $curLen = $vlen[0];
    for ($n = 1; $n < count($clips); $n++) {
        if (isset($tin[$n])) {
            [$xf, $td] = $tin[$n];
            $parts[] = sprintf("[$cur][v$n]xfade=transition=$xf:duration=%.3f:offset=%.3f[x$n]", $td, $curLen - $td);
            $curLen += $vlen[$n] - $td;
        } else {
            $parts[] = "[$cur][v$n]concat=n=2:v=1:a=0[x$n]";
            $curLen += $vlen[$n];
        }
        $cur = "x$n";
    }
    $parts[] = $acat . 'concat=n=' . count($clips) . ':v=0:a=1' . ($mute('video') ? '[aseq0];[aseq0]volume=0[aseq]' : '[aseq]');
    $total = array_sum(array_map(fn($c) => $c['out'] - $c['in'], $clips));
    $idx = count($clips);
    $mix = [];
    if ($hide('video')) {
        $parts[] = "[$cur]drawbox=c=black:t=fill[vh]";
        $cur = 'vh';
    }
    // Overlay media (footage di atas footage utama): lebar w × layar, titik tengah (x, y), mulai di detik s timeline
    foreach ($pips as $k => $q) {
        $m = $p['media'][$q['mid'] ?? ''] ?? null;
        $len = round(min($q['e'], $total) - $q['s'], 3);
        if (!$m || ($m['status'] ?? '') !== 'done' || $len <= 0.05) {
            continue;
        }
        $file = mproj_media_file($p['id'], $m);
        $img = $m['kind'] === 'image';
        $inputs .= $img ? sprintf(' -loop 1 -framerate %s -t %.3f -i %s', $fps, $len, escapeshellarg($file))
            : sprintf(' -ss %.3f -t %.3f -i %s', $q['in'], $len, escapeshellarg($file));
        if (!$hide('pip')) {
            $ow = max(2, (int) (round($q['w'] * $W / 2) * 2));
            $parts[] = sprintf("[%d:v]scale=%d:-2:flags=lanczos,fps=%s,format=yuva420p,setpts=PTS-STARTPTS+%.3f/TB[q%d]", $idx, $ow, $fps, $q['s'], $k);
            $parts[] = sprintf("[$cur][q%d]overlay=x='%.1f-w/2':y='%.1f-h/2':enable='between(t,%.3f,%.3f)':eof_action=pass:format=auto[qv%d]",
                $k, $q['x'] * $W, $q['y'] * $H, $q['s'], $q['s'] + $len, $k);
            $cur = "qv$k";
        }
        $vol = max(0, min(2, ($q['vol'] ?? 100) / 100));
        if (!$img && !empty($m['audio']) && $vol > 0 && !$mute('pip')) {
            $ms = (int) round($q['s'] * 1000);
            $parts[] = sprintf('[%d:a]asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo,volume=%.3f,afade=t=in:d=0.03,adelay=%d|%d[qa%d]', $idx, $vol, $ms, $ms, $k);
            $mix[] = "[qa$k]";
        }
        $idx++;
    }
    if ($hide('gfx')) {
        $overlays = [];
    }
    if ($mute('music')) {
        $music = [];
    }
    // Grafik & teks (PNG) di atas video: lebar w × layar, titik tengah (x, y), opacity & animasi masuk/keluar
    // (rumus sama dengan media_graph() editor clip; jarak geser animasi dalam px layar 1080 lebar)
    $kx = fn($e) => $e === '0' ? $e : sprintf('%.4f*(%s)', $W / 1080, $e);
    foreach ($overlays as $k => $o) {
        $file = OVERLAY_DIR . '/' . $o['file'];
        $s0 = (float) $o['s'];
        $e0 = min((float) $o['e'], $total);
        if (!is_file($file) || $e0 - $s0 < 0.05) {
            continue;
        }
        $inputs .= sprintf(' -loop 1 -framerate %s -t %.3f -i %s', $fps, $total, escapeshellarg($file));
        $ow = max(2, (int) (round($o['w'] * $W / 2) * 2));
        $anim = $o['anim'] ?? null;
        [$din, $dout] = anim_durations($anim, $e0 - $s0);
        $op = (int) ($o['opacity'] ?? 100);
        $alpha = $op < 100 ? sprintf(',colorchannelmixer=aa=%.3f', max(0, $op) / 100) : '';
        $p = sprintf('clip((t-%.3f)/%.3f,0,1)', $s0, max(0.01, $din));
        $q = sprintf('clip((t-%.3f)/%.3f,0,1)', $e0 - $dout, max(0.01, $dout));
        [$dxi, $dyi, $sci] = $din > 0 ? anim_expr($anim['in'], 'in', $p) : ['0', '0', '1'];
        [$dxo, $dyo, $sco] = $dout > 0 ? anim_expr($anim['out'], 'out', $q) : ['0', '0', '1'];
        $scaled = $sci !== '1' || $sco !== '1';
        $chain = sprintf('[%d:v]format=rgba,scale=%d:-2:flags=lanczos', $idx, $scaled ? (int) round($ow * 1.12 / 2) * 2 : $ow) . $alpha;
        if ($din > 0) {
            $pop = $anim['in'] === 'pop';
            $chain .= sprintf(',fade=t=in:st=%.3f:d=%.3f:alpha=1', $s0, $pop ? $din / 3 : $din);
        }
        if ($dout > 0) {
            $pop = $anim['out'] === 'pop';
            $chain .= sprintf(',fade=t=out:st=%.3f:d=%.3f:alpha=1', $pop ? $e0 - 0.4 * $dout : $e0 - $dout, $pop ? 0.4 * $dout : $dout);
        }
        if ($scaled) {
            $chain .= ",scale=w='max(2,trunc($ow*($sci)*($sco)/2)*2)':h=-2:eval=frame";
        }
        $parts[] = "{$chain}[o$k]";
        $parts[] = sprintf("[$cur][o%d]overlay=x='%.1f-w/2+(%s)+(%s)':y='%.1f-h/2+(%s)+(%s)':enable='between(t,%.3f,%.3f)':format=auto[ov%d]",
            $k, $o['x'] * $W, $kx($dxi), $kx($dxo), $o['y'] * $H, $kx($dyi), $kx($dyo), $s0, $e0, $k);
        $cur = "ov$k";
        $idx++;
    }
    // Panjang video tepat sepanjang timeline (input PNG grafik tidak boleh memperpanjangnya)
    $parts[] = sprintf("[$cur]trim=duration=%.3f,format=yuv420p[v]", $total);
    // Musik latar: dipotong, volume & fade, digeser ke posisinya, lalu dicampur dengan suara footage
    foreach ($music as $k => $mu) {
        $file = MUSIC_DIR . '/' . $mu['file'];
        $len = round(min($mu['e'], $total) - $mu['s'], 3);
        if (!is_file($file) || $len <= 0.1) {
            continue;
        }
        $inputs .= ' -i ' . escapeshellarg($file);
        $ms = (int) round($mu['s'] * 1000);
        $chain = sprintf('[%d:a]atrim=start=%.3f:duration=%.3f,asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo,volume=%.3f',
            $idx, $mu['off'], $len, $mu['vol'] / 100);
        if ($mu['fin'] > 0) {
            $chain .= sprintf(',afade=t=in:st=0:d=%.2f', min($mu['fin'], $len / 2));
        }
        if ($mu['fout'] > 0) {
            $chain .= sprintf(',afade=t=out:st=%.3f:d=%.2f', max(0, $len - min($mu['fout'], $len / 2)), min($mu['fout'], $len / 2));
        }
        $parts[] = "$chain,adelay={$ms}|{$ms}[m$k]";
        $mix[] = "[m$k]";
        $idx++;
    }
    $parts[] = $mix ? '[aseq]' . implode('', $mix) . 'amix=inputs=' . (count($mix) + 1) . ':duration=first:normalize=0[a]' : '[aseq]anull[a]';
    return [$inputs, implode(";\n", $parts)];
}

function run_mproj_render(string $id, array $job, string $tmpDir, string $cancelFlag): void
{
    $p = read_mproj($job['project_id']);
    if (!$p) {
        throw new RuntimeException('Project tidak ditemukan.');
    }
    $clips = array_values(array_filter($job['timeline'], fn($c) => isset($p['media'][$c['mid']]) && is_file(mproj_media_file($p['id'], $p['media'][$c['mid']]))));
    if (!$clips) {
        throw new RuntimeException('Timeline kosong.');
    }
    $short = (int) $job['short'];
    $upscale = !empty($job['upscale']);
    [$W, $H] = mproj_canvas($job['ratio'], $short);
    // fps footage video yang paling lama dipakai
    $byFps = [];
    foreach ($clips as $c) {
        $m = $p['media'][$c['mid']];
        if ($m['kind'] === 'video' && $m['fps'] > 0) {
            $byFps[(string) $m['fps']] = ($byFps[(string) $m['fps']] ?? 0) + ($c['out'] - $c['in']);
        }
    }
    arsort($byFps);
    $fps = (float) (array_key_first($byFps) ?? 30);
    $fps = $fps > 61 ? 60 : ($fps < 15 ? 30 : $fps);
    $total = array_sum(array_map(fn($c) => $c['out'] - $c['in'], $clips));
    $title = safe_filename($p['name'] ?: 'Project');
    $output = unique_path(DOWNLOAD_DIR, "$title [project " . ($upscale ? 'AI ' : '') . "{$short}p]", 'mp4');
    $mbps = video_bitrate(['width' => $W, 'height' => $H, 'fps' => $fps]);
    $progress = function (float $from, float $span) use ($id, $total) {
        return function (string $line) use ($id, $total, $from, $span) {
            if (str_starts_with($line, 'out_time_us=')) {
                update_job($id, ['progress' => round(min(99, $from + ((int) substr($line, 12)) / 1e6 / max(0.1, $total) * $span), 1)]);
            }
        };
    };

    // Upscale AI: susun dulu di resolusi footage (dasar), lalu diperbesar AI ke resolusi tujuan
    $baseShort = $upscale ? min($short, max(720, mproj_native_short($p))) : $short;
    [$bW, $bH] = mproj_canvas($job['ratio'], $baseShort);
    // Grafik/teks & musik dari snapshot job (sesuai project saat tombol Render ditekan)
    [$inputs, $graph] = mproj_graph($p, $clips, $bW, $bH, $fps, (array) ($job['overlays'] ?? []), (array) ($job['music'] ?? []),
        (array) ($job['pips'] ?? []), (array) ($job['tracks'] ?? []));
    $gfile = "$tmpDir/graph.txt";
    file_put_contents($gfile, $graph);
    $base = $upscale ? "$tmpDir/base.mp4" : "$tmpDir/out.mp4";
    $encoders = $upscale ? clip_encoders(video_bitrate(['width' => $bW, 'height' => $bH, 'fps' => $fps]), 'max') : clip_encoders($mbps);
    $ok = false;
    $err = '';
    foreach ($encoders as $enc) {
        update_job($id, ['status' => 'converting', 'stage' => 'Menyusun ' . count($clips) . ' footage…', 'progress' => 0]);
        [$code, $tail] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1' . $inputs
            . ' -/filter_complex ' . escapeshellarg($gfile) . " -map '[v]' -map '[a]' $enc -c:a aac -b:a 320k -movflags +faststart " . escapeshellarg($base),
            $progress(0, $upscale ? 45 : 99), $cancelFlag);
        clearstatcache();
        if ($ok = $code === 0 && is_file($base) && filesize($base) > 0) {
            break;
        }
        $err = error_from($tail);
    }
    if (!$ok) {
        throw new RuntimeException('Gagal merender: ' . $err);
    }
    $note = "{$W}×{$H} · " . count($clips) . ' footage';
    if ($upscale) {
        $note .= ' · ' . mproj_upscale($id, $base, "$tmpDir/out.mp4", $bW, $bH, $W, $H, $fps, $mbps, $total, $cancelFlag);
    }
    rename("$tmpDir/out.mp4", $output);
    @chmod($output, 0666);
    $size = filesize($output);
    update_mproj($p['id'], function ($p) use ($id, $output, $size, $W, $H, $note) {
        $p['renders'] = array_slice(array_merge([['job' => $id, 'file' => basename($output), 'size' => $size, 'w' => $W, 'h' => $H, 'note' => $note, 'at' => time()]],
            $p['renders'] ?? []), 0, 20);
        return $p;
    });
    update_job($id, ['status' => 'done', 'stage' => 'Selesai', 'progress' => 100, 'file' => basename($output), 'size' => $size, 'note' => $note]);
}

/**
 * Upscale AI: video dasar → diperkecil ke kotak 960 px → super-resolution (Apple / Real-ESRGAN) → resolusi tujuan.
 * Kalau AI tidak tersedia di komputer ini: diperbesar biasa (lanczos + penajaman ringan). Mengembalikan catatan.
 */
function mproj_upscale(string $id, string $base, string $out, int $bW, int $bH, int $W, int $H, float $fps, int $mbps, float $total, string $cancelFlag): string
{
    $k = 960 / max($bW, $bH);
    $iW = (int) (round($bW * $k / 2) * 2);
    $iH = (int) (round($bH * $k / 2) * 2);
    $progress = function (string $line) use ($id, $total) {
        if (str_starts_with($line, 'out_time_us=')) {
            update_job($id, ['progress' => round(min(99, 45 + ((int) substr($line, 12)) / 1e6 / max(0.1, $total) * 54), 1)]);
        }
    };
    $sr = superres_cmd();
    foreach (clip_encoders($mbps) as $enc) {
        if ($sr) {
            update_job($id, ['stage' => "AI upscale ke {$W}×{$H}…", 'progress' => 45]);
            $pipe = escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -i ' . escapeshellarg($base)
                . " -vf scale=$iW:$iH:flags=area -f rawvideo -pix_fmt bgra - | $sr $iW $iH $W $H ll | "
                . escapeshellarg(FFMPEG) . " -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -f rawvideo -pix_fmt bgra -s {$W}x{$H}"
                . " -framerate $fps -i - -i " . escapeshellarg($base) . " -map 0:v -map '1:a?' -pix_fmt yuv420p $enc -c:a copy -shortest -movflags +faststart " . escapeshellarg($out);
            [$code] = run('/bin/bash -c ' . escapeshellarg("set -o pipefail; $pipe"), $progress, $cancelFlag);
            clearstatcache();
            if ($code === 0 && is_file($out) && filesize($out) > 0) {
                return 'AI upscale';
            }
        }
        update_job($id, ['stage' => "Memperbesar ke {$W}×{$H}…", 'progress' => 45]);
        [$code] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -nostats -progress pipe:1 -i ' . escapeshellarg($base)
            . " -vf scale=$W:$H:flags=lanczos+accurate_rnd+full_chroma_int,cas=0.4 -pix_fmt yuv420p $enc -c:a copy -movflags +faststart " . escapeshellarg($out),
            $progress, $cancelFlag);
        clearstatcache();
        if ($code === 0 && is_file($out) && filesize($out) > 0) {
            return 'diperbesar (AI tidak tersedia)';
        }
    }
    throw new RuntimeException('Gagal memperbesar video.');
}
