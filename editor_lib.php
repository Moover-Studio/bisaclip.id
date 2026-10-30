<?php
// Data editor clip ("resep edit"): disiapkan sekali per clip proyek, lalu dipakai editor & render.
// Fungsi run(), error_from(), probe(), class Cancelled dari worker.php; clip_streams() dll dari clip.php.

require_once __DIR__ . '/source.php';
require_once __DIR__ . '/subtitles.php';
require_once __DIR__ . '/kinetic.php';
require_once __DIR__ . '/hook_intro.php';

define('OVERLAY_DIR', APP_DIR . '/previews/overlays'); // gambar overlay / lower third unggahan user
define('MUSIC_DIR', APP_DIR . '/previews/music');       // pustaka musik (dipakai ulang di semua proyek)
define('SFX_DIR', APP_DIR . '/previews/sfx');           // pustaka sound effect: bawaan (tools/sfx_gen.py) + unggahan user
const OVERLAY_MARGIN = 115;

/**
 * Efek transisi overlay (panel Efek). Dibuat prosedural, tanpa file video:
 * - leak  : beberapa gumpalan cahaya berwarna yang bergerak, dicampur ke video dengan mode screen
 * - flash : kilatan putih (mode screen)
 * - dip   : fade ke hitam lalu kembali
 * - sweep : pita cahaya miring yang menyapu layar (angle = arah, radian)
 * - anamorphic : garis flare horizontal ala lensa anamorphic + inti, halo & ghost
 * - glow  : video diberi cahaya lembut (bloom) dari salinan blur-nya
 * - zoom  : zoom punch cepat lalu kembali; shake: guncangan kamera yang mereda; vignette: tepi layar menggelap
 * blobs: [x0, y0, x1, y1, radius, R, G, B] — posisi awal/akhir & radius dalam pecahan lebar layar.
 * color: [R, G, B] untuk flash, sweep & anamorphic.
 * Rumus yang sama dipakai preview di editor (drawEffects) supaya hasil render sama.
 */
const EFFECT_PRESETS = [
    'leak_warm' => ['label' => 'Light Leak Hangat', 'kind' => 'leak', 'dur' => 1.6, 'blobs' => [
        [-0.25, 0.2, 0.85, 0.38, 0.55, 255, 110, 25], [1.2, 0.72, 0.25, 0.52, 0.5, 255, 185, 60], [0.45, 1.15, 0.65, 0.68, 0.42, 255, 55, 85]]],
    'leak_gold' => ['label' => 'Light Leak Emas', 'kind' => 'leak', 'dur' => 1.6, 'blobs' => [
        [1.2, 0.15, 0.2, 0.42, 0.6, 255, 205, 90], [-0.2, 0.8, 0.7, 0.55, 0.5, 255, 150, 40], [0.5, -0.15, 0.5, 0.3, 0.4, 255, 235, 170]]],
    'leak_cool' => ['label' => 'Light Leak Dingin', 'kind' => 'leak', 'dur' => 1.6, 'blobs' => [
        [-0.2, 0.3, 0.9, 0.45, 0.55, 70, 130, 255], [1.2, 0.8, 0.3, 0.55, 0.5, 170, 90, 255], [0.5, 1.15, 0.55, 0.7, 0.42, 40, 215, 255]]],
    'leak_pink' => ['label' => 'Light Leak Pink', 'kind' => 'leak', 'dur' => 1.6, 'blobs' => [
        [1.2, 0.25, 0.15, 0.4, 0.55, 255, 70, 160], [-0.2, 0.75, 0.75, 0.6, 0.5, 255, 120, 90], [0.5, 1.15, 0.45, 0.65, 0.4, 200, 80, 255]]],
    'leak_sunset' => ['label' => 'Light Leak Senja', 'kind' => 'leak', 'dur' => 1.8, 'blobs' => [
        [-0.3, 0.1, 0.9, 0.5, 0.6, 255, 95, 40], [1.25, 0.62, 0.2, 0.32, 0.55, 230, 45, 140], [0.4, 1.2, 0.6, 0.78, 0.45, 255, 170, 60]]],
    'leak_prism' => ['label' => 'Prisma', 'kind' => 'leak', 'dur' => 1.4, 'blobs' => [
        [-0.35, 0.25, 0.65, 0.35, 0.3, 255, 40, 80], [-0.25, 0.35, 0.8, 0.45, 0.3, 255, 190, 40], [-0.15, 0.45, 0.95, 0.55, 0.3, 60, 220, 120],
        [-0.05, 0.55, 1.1, 0.65, 0.3, 60, 120, 255], [0.05, 0.65, 1.25, 0.75, 0.3, 170, 70, 255]]],
    'bokeh' => ['label' => 'Bokeh Emas', 'kind' => 'leak', 'dur' => 2.2, 'blobs' => [
        [0.2, 0.25, 0.3, 0.15, 0.09, 255, 200, 110], [0.75, 0.35, 0.68, 0.22, 0.12, 255, 170, 80], [0.4, 0.7, 0.48, 0.58, 0.1, 255, 220, 150],
        [0.85, 0.8, 0.8, 0.68, 0.08, 255, 190, 100], [0.1, 0.85, 0.18, 0.72, 0.11, 255, 160, 70], [0.55, 0.12, 0.6, 0.04, 0.07, 255, 230, 170]]],
    'sweep' => ['label' => 'Light Sweep', 'kind' => 'sweep', 'dur' => 1.2, 'color' => [255, 236, 200], 'angle' => 0.45],
    'sweep_cool' => ['label' => 'Kilau Biru', 'kind' => 'sweep', 'dur' => 1.2, 'color' => [160, 210, 255], 'angle' => -0.45],
    'flare_blue' => ['label' => 'Anamorphic Flare', 'kind' => 'anamorphic', 'dur' => 1.6, 'color' => [70, 150, 255]],
    'flare_gold' => ['label' => 'Flare Emas', 'kind' => 'anamorphic', 'dur' => 1.6, 'color' => [255, 165, 55]],
    'glow' => ['label' => 'Glow Lembut', 'kind' => 'glow', 'dur' => 1.5],
    'flash' => ['label' => 'Flash Putih', 'kind' => 'flash', 'dur' => 0.6, 'color' => [255, 255, 255]],
    'flash_warm' => ['label' => 'Flash Hangat', 'kind' => 'flash', 'dur' => 0.6, 'color' => [255, 196, 135]],
    'dip' => ['label' => 'Fade Hitam', 'kind' => 'dip', 'dur' => 0.8],
    'zoom' => ['label' => 'Zoom Punch', 'kind' => 'zoom', 'dur' => 0.6],
    'shake' => ['label' => 'Guncangan', 'kind' => 'shake', 'dur' => 0.5],
    'vignette' => ['label' => 'Vignette', 'kind' => 'vignette', 'dur' => 2],
    // Filter (color grading): eq = [kecerahan, kontras, saturasi], tint = [R, G, B, kekuatan] dicampur mode soft light.
    // dur 0 = otomatis sepanjang clip saat ditambahkan.
    // mul = pengali kecerahan RGB (redup tanpa menghancurkan detail gelap), k = intensitas bawaan (%)
    'grade_moody' => ['label' => 'Dark Moody', 'kind' => 'grade', 'dur' => 0, 'mul' => 0.58, 'eq' => [0.0, 1.06, 0.4], 'tint' => [18, 22, 30, 0.25], 'k' => 100],
    'grade_mono' => ['label' => 'Dark Mono', 'kind' => 'grade', 'dur' => 0, 'mul' => 0.56, 'eq' => [0.0, 1.1, 0.0], 'tint' => null, 'k' => 100],
    'grade_dark' => ['label' => 'Dark', 'kind' => 'grade', 'dur' => 0, 'eq' => [-0.06, 1.15, 0.82], 'tint' => [20, 32, 64, 0.35]],
    'grade_warm' => ['label' => 'Warm', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.0, 1.05, 1.1], 'tint' => [255, 150, 60, 0.4]],
    'grade_cold' => ['label' => 'Cold', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.0, 1.05, 0.92], 'tint' => [70, 140, 255, 0.4]],
    'grade_cine' => ['label' => 'Cinematic', 'kind' => 'grade', 'dur' => 0, 'eq' => [-0.02, 1.12, 1.08], 'tint' => [0, 150, 165, 0.3]],
    'grade_vintage' => ['label' => 'Vintage', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.02, 0.92, 0.7], 'tint' => [235, 190, 120, 0.45]],
    'grade_fade' => ['label' => 'Fade', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.05, 0.84, 0.85], 'tint' => null],
    'grade_vivid' => ['label' => 'Vivid', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.02, 1.08, 1.4], 'tint' => null],
    'grade_bw' => ['label' => 'Hitam Putih', 'kind' => 'grade', 'dur' => 0, 'eq' => [0.0, 1.12, 0.0], 'tint' => null],
];

/** Rasio bingkai video (lebar / tinggi) di layar 1080x1920; selain "full", video berada di tengah layar hitam (atau background blur). */
const FRAME_RATIOS = ['full' => 9 / 16, '1:1' => 1.0, '4:5' => 0.8, '3:4' => 0.75, '16:9' => 16 / 9];

/** Geometri bingkai: [rasio, tinggi area video, y atas, radius sudut, layout aktif?] */
function frame_geom(array $edit): array
{
    $f = ($edit['layout'] ?? '') === 'gaming' ? [] : ($edit['frame'] ?? []); // streaming game selalu layar penuh
    $ratio = ($edit['layout'] ?? '') === 'letterbox' && empty($f['ratio']) ? '3:4' : ($f['ratio'] ?? 'full');
    $ratio = $ratio === 'split' ? 'full' : $ratio; // split screen selalu layar penuh
    $a = FRAME_RATIOS[$ratio] ?? 9 / 16;
    $h = $ratio === 'full' ? 1920 : (int) round(1080 / $a / 2) * 2;
    $r = (int) ($f['radius'] ?? 0);
    return [$a, $h, (int) ((1920 - $h) / 2), $r, $ratio !== 'full' || $r > 0];
}

/** Masker PNG 1080x1920: hitam di luar area video bersudut membulat, transparan di dalamnya (dibuat sekali per ukuran). */
function frame_mask(array $edit): ?string
{
    [, $h, $y0, $r, $on] = frame_geom($edit);
    if (!$on) {
        return null;
    }
    $dir = TMP_DIR . '/masks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = "$dir/frame_{$h}_{$r}.png";
    if (!is_file($file)) {
        $y1 = $y0 + $h - 1;
        $d = "hypot(max(0,max($r-X,X-(1079-$r))),max(0,max($y0+$r-Y,Y-($y1-$r))))";
        $a = $r > 0 ? "if(between(Y,$y0,$y1),clip(($d-$r+0.5)*255,0,255),255)" : "if(between(Y,$y0,$y1),0,255)";
        shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -loglevel error -y -f lavfi -i color=c=black:s=1080x1920:d=1 -frames:v 1 -vf '
            . escapeshellarg("format=rgba,geq=r=0:g=0:b=0:a='$a'") . ' ' . escapeshellarg($file));
        @chmod($file, 0666);
    }
    return is_file($file) ? $file : null;
}

/** Template transisi antar footage / clip: label & nama transisi xfade ffmpeg. */
const TRANSITION_PRESETS = [
    'fade' => ['label' => 'Fade', 'xf' => 'fade'],
    'fadeblack' => ['label' => 'Fade hitam', 'xf' => 'fadeblack'],
    'fadewhite' => ['label' => 'Fade putih', 'xf' => 'fadewhite'],
    'slideleft' => ['label' => 'Geser kiri', 'xf' => 'slideleft'],
    'slideright' => ['label' => 'Geser kanan', 'xf' => 'slideright'],
    'wipeleft' => ['label' => 'Wipe', 'xf' => 'wipeleft'],
    'circleopen' => ['label' => 'Lingkaran', 'xf' => 'circleopen'],
    'zoomin' => ['label' => 'Zoom', 'xf' => 'zoomin'],
    // Bukan xfade: gumpalan kertas lalu robek membuka item berikutnya (aset tools/paper_tear.py, durasi tetap)
    'paper' => ['label' => 'Kertas robek', 'xf' => 'paper'],
];

/*
 * Animasi opening di detik pertama video (menu Transisi → Opening). Sama dengan OPENINGS / openingAt() di editor.
 * bars: shape hitam atas-bawah membuka secara vertikal ('open' = terbuka penuh, 'letter' = berhenti sebagai letterbox lalu hilang)
 * zoom: skala awal yang mengecil ke 1 (zoom out), fade: muncul dari hitam. ease: kurva gerak.
 */
const OPENING_PRESETS = [
    'cine_open' => ['label' => 'Cinematic buka', 'cat' => 'cine', 'dur' => 1.2, 'ease' => 'inout', 'bars' => 'open'],
    'cine_fast' => ['label' => 'Cinematic cepat', 'cat' => 'cine', 'dur' => 0.6, 'ease' => 'out3', 'bars' => 'open'],
    'cine_letter' => ['label' => 'Letterbox', 'cat' => 'cine', 'dur' => 2.4, 'ease' => 'inout', 'bars' => 'letter'],
    'cine_zoom' => ['label' => 'Cinematic + zoom', 'cat' => 'cine', 'dur' => 1.6, 'ease' => 'inout', 'bars' => 'open', 'zoom' => 1.25],
    'zoom_fast' => ['label' => 'Zoom out cepat', 'cat' => 'zoom', 'dur' => 0.5, 'ease' => 'out3', 'zoom' => 1.35],
    'zoom_smooth' => ['label' => 'Zoom out halus', 'cat' => 'zoom', 'dur' => 1.4, 'ease' => 'inout', 'zoom' => 1.2],
    'zoom_punch' => ['label' => 'Zoom out punch', 'cat' => 'zoom', 'dur' => 0.35, 'ease' => 'out4', 'zoom' => 1.6],
    'zoom_slow' => ['label' => 'Zoom out pelan', 'cat' => 'zoom', 'dur' => 3.0, 'ease' => 'out2', 'zoom' => 1.12],
    'fade_black' => ['label' => 'Fade dari hitam', 'cat' => 'fade', 'dur' => 0.8, 'ease' => 'inout', 'fade' => true],
];
const OPENING_LETTERBOX = 0.12; // tebal tiap bar letterbox (bagian dari tinggi layar)

/** Kurva gerak opening sebagai ekspresi ffmpeg dari progres $p (0..1). Sama dengan OP_EASE di editor. */
function opening_ease(string $ease, string $p): string
{
    return match ($ease) {
        'out2' => "(1-pow(1-($p),2))",
        'out3' => "(1-pow(1-($p),3))",
        'out4' => "(1-pow(1-($p),4))",
        default => "(($p)*($p)*(3-2*($p)))",
    };
}

/**
 * Filtergraph opening dari label [$in] ke [$out] (t=0 = awal video). Zoom memakai perspective (posisi sub-piksel, halus),
 * bar hitam memakai overlay kotak hitam yang bergeser. Semua filter mati setelah durasi opening, jadi sisa video tidak tersentuh.
 */
function opening_graph(array $op, string $in, string $out, float $fps): string
{
    $pr = OPENING_PRESETS[$op['type']];
    $D = sprintf('%.3f', $op['dur']);
    $en = "enable='lt(t,$D)'";
    $parts = [];
    $cur = $in;
    if (!empty($pr['zoom'])) {
        // Progres dari nomor frame (perspective tidak punya variabel waktu)
        $e = opening_ease($pr['ease'], sprintf('clip(in/%.4f/%s,0,1)', $fps ?: 30, $D));
        $z = sprintf('(%.4f-%.4f*%s)', $pr['zoom'], $pr['zoom'] - 1, $e);
        $c = fn($axis, $sign) => "'$axis/2{$sign}$axis/2/$z'";
        $parts[] = "[$cur]perspective=x0={$c('W', '-')}:y0={$c('H', '-')}:x1={$c('W', '+')}:y1={$c('H', '-')}"
            . ":x2={$c('W', '-')}:y2={$c('H', '+')}:x3={$c('W', '+')}:y3={$c('H', '+')}:interpolation=cubic:eval=frame:{$en}[opz]";
        $cur = 'opz';
    }
    if (!empty($pr['bars'])) {
        // C = bagian layar yang masih tertutup di tiap sisi (0,5 = tertutup penuh)
        $p = "clip(t/$D,0,1)";
        $L = OPENING_LETTERBOX;
        $C = $pr['bars'] === 'letter'
            ? sprintf('if(lt(%1$s,0.45),0.5-%2$.3f*%3$s,if(lt(%1$s,0.75),%4$.3f,%4$.3f*(1-%5$s)))', $p, 0.5 - $L,
                opening_ease($pr['ease'], "$p/0.45"), $L, opening_ease($pr['ease'], "($p-0.75)/0.25"))
            : '0.5*(1-' . opening_ease($pr['ease'], $p) . ')';
        $parts[] = sprintf('color=c=black:s=4096x4096:r=%.4f:d=%.3f,format=yuv420p,split[opb1][opb2]', $fps ?: 30, $op['dur'] + 0.1);
        $parts[] = "[$cur][opb1]overlay=x=0:y='$C*H-h':eof_action=pass:{$en}[opt]";
        $parts[] = "[opt][opb2]overlay=x=0:y='H-$C*H':eof_action=pass:{$en}[opbars]";
        $cur = 'opbars';
    }
    if (!empty($pr['fade'])) {
        $parts[] = "[$cur]fade=t=in:st=0:d={$D}[opf]";
        $cur = 'opf';
    }
    $parts[] = "[$cur]format=yuv420p[$out]";
    return implode(";\n", $parts);
}

/** Tambahkan opening di ujung filtergraph yang keluarannya [v]. */
function graph_with_opening(string $graph, ?array $op, float $fps): string
{
    $g = rtrim($graph);
    if (!$op || !isset(OPENING_PRESETS[$op['type'] ?? '']) || !str_ends_with($g, '[v]')) {
        return $graph;
    }
    return substr($g, 0, -3) . "[vop0];\n" . opening_graph($op, 'vop0', 'v', $fps);
}

/** Ekspresi ffmpeg (waktu lokal $v, durasi $d) untuk kurva intensitas efek. Sama dengan fxEnv() di editor. */
function effect_env_expr(string $kind, float $d, string $v = 'T'): string
{
    $p = "min(1,max(0,$v/$d))";
    return match ($kind) {
        'leak', 'sweep', 'anamorphic', 'glow' => "sin(PI*$p)",
        'flash' => "if(lt($p,0.3),$p/0.3,(1-$p)/0.7)",
        'zoom' => "if(lt($p,0.3),sin($p/0.3*PI/2),cos(($p-0.3)/0.7*PI/2))",
        'shake' => "(1-$p)",
        default => "min(1,2.5*min($p,1-$p))",
    };
}

/**
 * Cahaya sweep / anamorphic per piksel (koordinat x = X/W, y = Y/W dalam satuan lebar layar, P = progres 0..1).
 * Mengembalikan [bagian berwarna, bagian putih]. Rumus sama dengan lightTerms() di editor.
 */
function effect_light_terms(array $pre, string $P): array
{
    $f = fn($n) => sprintf('%.4f', $n);
    if ($pre['kind'] === 'sweep') {
        $c = cos($pre['angle']);
        $s = sin($pre['angle']);
        $us = [0, $c, 16 / 9 * $s, $c + 16 / 9 * $s];
        $u0 = min($us) - 0.3;
        $u1 = max($us) + 0.3;
        $q = "((X/W*{$f($c)}+Y/W*{$f($s)})-({$f($u0)}+{$f($u1 - $u0)}*$P))";
        $q = "($q*$q)";
        return ["(exp(-$q/0.01)+0.35*exp(-$q/0.1225))", "0.6*exp(-$q/0.0016)"];
    }
    $dx = "(X/W-(0.22+0.56*$P))";
    $dy = '(Y/W-0.7467)';
    $r2 = "($dx*$dx+$dy*$dy)";
    $gx = "(X/W-(0.78-0.56*$P))";
    $streak = "exp(-$dy*$dy/0.0002)*exp(-$dx*$dx/0.64)";
    $ghost = "exp(-($gx*$gx+($dy-0.06)*($dy-0.06))/0.0012)+0.6*exp(-($gx*$gx*2.2+($dy-0.12)*($dy-0.12))/0.004)";
    return ["($streak+0.35*exp(-$r2/0.09)+0.45*($ghost))", "(exp(-$r2/0.0025)+0.25*$streak)"];
} // jarak overlay posisi atas/bawah dari tepi layar (px pada tinggi 1920)

const EDIT_PAD = 10; // detik tambahan di kiri & kanan clip supaya trim bisa diperpanjang di editor

function edit_dir(string $pid): string
{
    $dir = STORAGE_DIR . "/projects/$pid";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777); // umask Apache membuat folder 0755; buka supaya bisa dikelola dari luar Apache
    }
    return $dir;
}

function edit_path(string $pid, string $iid): string
{
    return edit_dir($pid) . "/$iid.json";
}

/** Tinggi video preview editor (proxy). 720p supaya preview 9:16 (potongan sempit video landscape) tetap tajam. */
const PROXY_H = 720;
const PROXY_ENC = ['-c:v h264_videotoolbox -b:v 3500k', '-c:v libx264 -preset veryfast -crf 22'];

function proxy_rel(string $pid, string $iid): string
{
    return "previews/proj_$pid/$iid.mp4";
}

function read_edit(string $pid, string $iid): ?array
{
    $data = json_decode((string) @file_get_contents(edit_path($pid, $iid)), true);
    return is_array($data) ? upgrade_layout($data) : null;
}

/**
 * Konsep layout baru: jenis clip (podcast / streaming game), toggle ikuti pembicara, dan layout bingkai
 * (rasio, split screen + rasio atas:bawah, background solid / blur). Resep lama dikonversi:
 * crop tengah → ikuti pembicara mati, background blur → bingkai 16:9 + background blur, letterbox → bingkai 3:4,
 * streaming game → jenis streaming game + split 70:30, split screen otomatis → split 50:50.
 */
function upgrade_layout(array $edit): array
{
    $l = (string) ($edit['layout'] ?? 'speaker');
    $f = is_array($edit['frame'] ?? null) ? $edit['frame'] : [];
    $ratio = $f['ratio'] ?? 'full';
    if ($l === 'blur') {
        $f['ratio'] = $ratio === 'full' ? '16:9' : $ratio;
        $f['bg'] = 'blur';
    } elseif ($l === 'letterbox' && $ratio === 'full') {
        $f['ratio'] = '3:4';
    } elseif ($l === 'crop_center') {
        $edit['follow'] = false;
    } elseif ($l === 'gaming' && $ratio !== 'split') {
        [$f['ratio'], $f['split'], $edit['kind']] = ['split', 70, 'gaming'];
    } elseif (($l === 'split' || !empty($edit['split']['enabled'])) && $ratio !== 'split') {
        [$f['ratio'], $f['split']] = ['split', 50];
    }
    $edit['kind'] ??= $l === 'gaming' ? 'gaming' : 'podcast';
    $edit['frame'] = $f + ['ratio' => 'full', 'radius' => 0];
    $edit['layout'] = $l === 'gaming' || ($edit['frame']['ratio'] === 'split' && $edit['kind'] === 'gaming') ? 'gaming' : 'speaker';
    return $edit;
}

/** Pilihan rasio split screen: persen tinggi panel atas (game / orang pertama). */
const SPLIT_RATIOS = [50, 40, 60, 30, 70];

/** Tinggi panel atas split screen dalam px (dari 1920). */
function split_top(array $edit): int
{
    $p = (int) ($edit['frame']['split'] ?? 0);
    if (!in_array($p, SPLIT_RATIOS, true)) {
        $p = ($edit['layout'] ?? '') === 'gaming' ? 70 : 50;
    }
    return (int) (round(1920 * $p / 100 / 2) * 2);
}

function write_edit(string $pid, string $iid, array $edit): void
{
    $path = edit_path($pid, $iid);
    file_put_contents("$path.tmp", json_encode($edit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod("$path.tmp", 0666);
    rename("$path.tmp", $path);
}

/** Gaya subtitle bawaan (Bold Kuning). pos = posisi bawah teks, pecahan tinggi layar dari atas. */
function default_sub_style(string $layout): array
{
    return [
        'size' => 98,
        'color' => '#FFFFFF',
        'highlight' => '#FFD400',
        'pos' => $layout === 'blur' ? 0.78 : 0.71,
        'uppercase' => true,
        'font' => 'arial-black',
    ];
}

/** Ubah kata [[s,e,w],...] (relatif) menjadi baris subtitle dengan waktu absolut. */
function words_to_lines(array $words, float $offset): array
{
    $lines = [];
    foreach (subtitle_chunks($words) as $chunk) {
        $ws = array_map(fn($w) => ['s' => round($w[0] + $offset, 3), 'e' => round($w[1] + $offset, 3), 'w' => $w[2]], $chunk);
        $lines[] = ['s' => $ws[0]['s'], 'e' => end($ws)['e'], 'words' => $ws];
    }
    return $lines;
}

/**
 * Unduh rentang video sebagai proxy 480p secara paralel (beberapa koneksi), lalu gabungkan.
 * Setiap bagian di-encode ulang dengan seek presisi, jadi sambungannya pas.
 */
function make_proxy_parallel(array $v, ?array $a, float $start, float $dur, string $out, string $tmpDir, string $cancelFlag): bool
{
    $n = (int) max(1, min(8, ceil($dur / 15)));
    $len = $dur / $n;
    $script = "#!/bin/sh\n";
    $list = '';
    // Hingga 8 bagian di-encode bersamaan: di NVIDIA memakai CPU (proxy 480p ringan) supaya tidak menghabiskan
    // jatah sesi NVENC GeForce yang dibutuhkan render
    $enc = IS_MAC ? PROXY_ENC[0] : PROXY_ENC[1];
    for ($k = 0; $k < $n; $k++) {
        $part = "$tmpDir/proxy_$k.mp4";
        @unlink($part);
        [$inputs, $vi, $ai] = clip_inputs($v, $a, $start + $k * $len);
        $amap = $ai !== null ? "-map $ai:a:0 -c:a aac -b:a 128k" : '-an';
        // Bagian terakhir dibiarkan sampai akhir rentang supaya tidak ada sisa karena pembulatan
        $t = $k === $n - 1 ? $dur - $k * $len : $len;
        $script .= escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . $inputs
            . ' -t ' . number_format($t, 3, '.', '') . " -map $vi:v:0 -vf scale=-2:" . PROXY_H . " -pix_fmt yuv420p $amap"
            . " $enc " . escapeshellarg($part) . " &\n";
        $list .= "file '" . str_replace("'", "'\\''", $part) . "'\n";
    }
    $script .= "wait\n";
    file_put_contents("$tmpDir/proxy.sh", $script);
    file_put_contents("$tmpDir/proxy_list.txt", $list);
    run('/bin/sh ' . escapeshellarg("$tmpDir/proxy.sh"), fn() => null, $cancelFlag, 300);
    clearstatcache();
    for ($k = 0; $k < $n; $k++) {
        if (!is_file("$tmpDir/proxy_$k.mp4") || filesize("$tmpDir/proxy_$k.mp4") === 0) {
            return false;
        }
    }
    [$code] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -f concat -safe 0 -i '
        . escapeshellarg("$tmpDir/proxy_list.txt") . ' -c copy -movflags +faststart ' . escapeshellarg($out),
        fn() => null, $cancelFlag, 120);
    clearstatcache();
    return $code === 0 && is_file($out) && filesize($out) > 0;
}

/**
 * Pastikan resep edit clip tersedia. Dikunci per clip supaya job persiapan dan job render
 * tidak menyiapkan clip yang sama bersamaan; yang datang belakangan cukup menunggu hasilnya.
 */
function ensure_edit(string $jobId, array $job, string $cancelFlag): array
{
    $lockPath = edit_path($job['project_id'], $job['item_id']) . '.lock';
    $lock = fopen($lockPath, 'c');
    @chmod($lockPath, 0666);
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (file_exists($cancelFlag)) {
            fclose($lock);
            throw new Cancelled();
        }
        update_job($jobId, ['stage' => 'Menunggu persiapan editor clip ini…']);
        sleep(1);
    }
    try {
        $edit = read_edit($job['project_id'], $job['item_id']);
        return $edit && empty($edit['pending']) ? $edit : prepare_edit($jobId, $job, $cancelFlag);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Siapkan resep edit untuk satu clip proyek: proxy video 480p (rentang ±10 detik),
 * transkrip per kata (Whisper) dan segmen kamera (pelacak wajah), semuanya dari proxy lokal.
 */
function prepare_edit(string $jobId, array $job, string $cancelFlag): array
{
    $pid = $job['project_id'];
    $iid = $job['item_id'];
    $tmpDir = TMP_DIR . "/$jobId";
    $duration = (float) ($job['video_duration'] ?? 0);
    $padStart = max(0, (float) $job['start'] - EDIT_PAD);
    $padEnd = (float) $job['end'] + EDIT_PAD;
    if ($duration > 0) {
        $padEnd = min($duration, $padEnd);
    }
    $padDur = $padEnd - $padStart;
    // Persiapan clip yang tidak sedang dibuka di editor berjalan dengan prioritas CPU rendah
    $nice = empty($job['priority']) ? 'nice -n 15 ' : '';

    // Pakai video sumber lokal kalau ada (tunggu kalau masih diunduh); kalau tidak ada, ambil lewat link
    $local = !empty($job['analysis_id']) ? wait_local_source($job['analysis_id'], $jobId, $cancelFlag, $padStart, $padEnd) : null;
    update_job($jobId, ['status' => 'converting', 'stage' => 'Menyiapkan editor: membaca video…', 'progress' => 0]);
    if ($local) {
        [$srcW, $srcH] = [$local['w'], $local['h']];
    } else {
        [$hi] = clip_streams($job['url'], $cancelFlag);
        $srcW = (int) ($hi['width'] ?? 1920);
        $srcH = (int) ($hi['height'] ?? 1080);
    }

    // 1. Proxy (PROXY_H) + audio. YouTube membatasi kecepatan per koneksi, jadi rentangnya dibagi
    //    menjadi beberapa bagian (±15 detik) yang diunduh paralel, lalu digabung. Diulang kalau stream ditolak.
    $proxyRel = proxy_rel($pid, $iid);
    $proxy = APP_DIR . '/' . $proxyRel;
    if (!is_dir(dirname($proxy))) {
        @mkdir(dirname($proxy), 0777, true);
        @chmod(dirname($proxy), 0777);
    }
    $ok = false;
    if ($local) {
        update_job($jobId, ['stage' => 'Menyiapkan editor: membuat video preview…', 'progress' => 0]);
        [$inputs] = clip_inputs(local_format($local), null, $padStart);
        foreach (venc_list(PROXY_ENC) as $enc) {
            [$code] = run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . $inputs
                . ' -t ' . number_format($padDur, 3, '.', '') . " -map 0:v:0 -map '0:a:0?' -vf scale=-2:" . PROXY_H . " -pix_fmt yuv420p"
                . " -c:a aac -b:a 128k $enc -movflags +faststart " . escapeshellarg($proxy), fn() => null, $cancelFlag, 300);
            clearstatcache();
            if ($ok = $code === 0 && is_file($proxy) && filesize($proxy) > 0) {
                break;
            }
        }
    }
    for ($try = 1; $try <= 3 && !$ok; $try++) {
        update_job($jobId, ['stage' => $try === 1 ? 'Menyiapkan editor: mengunduh video preview…' : "Mengunduh video preview (percobaan $try/3)…",
            'progress' => 0]);
        if ($try > 1) {
            sleep(2);
        }
        $raw = '';
        run(escapeshellarg(YTDLP) . ' -j --no-playlist --no-warnings'
            . ' -f ' . escapeshellarg('bv*[height<=' . PROXY_H . '][protocol=https]+ba[protocol=https]/b[height<=' . PROXY_H . ']/bv*[height<=' . PROXY_H . ']+ba')
            . ' -S ' . escapeshellarg('res,fps') . ' --socket-timeout 20 -- ' . escapeshellarg($job['url']),
            function ($line) use (&$raw) {
                if ($line !== '' && $line[0] === '{') {
                    $raw = $line;
                }
            }, $cancelFlag, 90);
        $info = json_decode($raw, true);
        if (!is_array($info)) {
            continue;
        }
        $v = $a = null;
        foreach ($info['requested_formats'] ?? [$info] as $f) {
            if (($f['vcodec'] ?? 'none') !== 'none' && !$v) {
                $v = $f;
            }
            if (($f['acodec'] ?? 'none') !== 'none' && !$a) {
                $a = $f;
            }
        }
        if (!$v) {
            continue;
        }
        $ok = make_proxy_parallel($v, $a, $padStart, $padDur, $proxy, $tmpDir, $cancelFlag);
    }
    if (!$ok) {
        throw new RuntimeException('Gagal membuat video preview untuk editor.');
    }
    @chmod($proxy, 0666);
    $proxyDur = (float) (probe($proxy)['duration'] ?: $padDur);

    // Resep sementara: editor sudah bisa dibuka (video, trim, gaya) sambil subtitle & wajah diproses
    $layout = (string) ($job['layout'] ?? 'speaker');
    $edit = [
        'version' => 1,
        'pending' => true,
        'pad' => [round($padStart, 3), round($padStart + $proxyDur, 3)],
        'trim' => [round((float) $job['start'], 3), round((float) $job['end'], 3)],
        'src' => ['w' => $srcW, 'h' => $srcH],
        'layout' => $layout,
        'subtitles' => !empty($job['subtitles']),
        'style' => default_sub_style($layout),
        'lines' => [],
        'segments' => [],
        'hook' => ['enabled' => trim((string) ($job['hook_text'] ?? '')) !== '', 'text' => (string) ($job['hook_text'] ?? ''), 'duration' => 3.5, 'font' => 'arial-black'],
        'updated_at' => time(),
    ];
    write_edit($pid, $iid, $edit);

    // 2. Transkrip per kata dari audio proxy (lokal, cepat)
    $words = [];
    $wav = "$tmpDir/audio.wav";
    update_job($jobId, ['stage' => 'Menyiapkan editor: transkrip subtitle (Whisper)…', 'progress' => 0]);
    run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -i ' . escapeshellarg($proxy)
        . ' -vn -ac 1 -ar 16000 -c:a pcm_s16le ' . escapeshellarg($wav), fn() => null, $cancelFlag, 120);
    if (is_file($wav)) {
        try {
            $words = whisper_words($wav, (string) ($job['language'] ?? ''), $tmpDir, $cancelFlag, $nice);
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable $e) {
            $words = []; // subtitle kosong, tetap bisa diisi manual di editor
        }
    }
    file_put_contents("$tmpDir/words_clip.json", json_encode($words));
    merge_prepared($pid, $iid, ['lines' => words_to_lines($words, $padStart)], true);

    // 3. Segmen kamera dari proxy
    update_job($jobId, ['stage' => 'Menyiapkan editor: mendeteksi wajah & pembicara…', 'progress' => 0]);
    $pw = 640;
    $pp = probe($proxy);
    $ph = $pp['width'] ? (int) (round(640 * $pp['height'] / $pp['width'] / 2) * 2) : 360;
    $trackOut = "$tmpDir/track.json";
    run(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -i ' . escapeshellarg($proxy)
        . " -an -vf fps=10,scale=$pw:$ph -f rawvideo -pix_fmt bgr24 - | $nice" . escapeshellarg(PYTHON) . ' '
        . escapeshellarg(APP_DIR . '/tools/speaker_track.py') . " --width $pw --height $ph --fps 10"
        . ' --model ' . escapeshellarg(FACE_MODEL) . ' --words ' . escapeshellarg("$tmpDir/words_clip.json")
        . ' --out ' . escapeshellarg($trackOut), fn() => null, $cancelFlag, 300);
    $track = json_decode((string) @file_get_contents($trackOut), true);
    [$segments, $shots, $cam] = track_to_edit($track, $padStart, precise_cuts($proxy));
    return merge_prepared($pid, $iid, ['segments' => $segments, 'shots' => $shots, 'cam' => $cam, 'precise' => true], false);
}

/**
 * Titik pergantian kamera presisi per frame (detik relatif proxy), dari deteksi scene ffmpeg.
 * Pelacak wajah hanya memeriksa 10 frame/detik sehingga batas shot-nya bisa meleset ±1 frame
 * (terlihat sebagai kedipan saat berganti antara split dan satu orang).
 */
function precise_cuts(string $proxy): array
{
    $out = (string) shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -i ' . escapeshellarg($proxy)
        . " -an -vf \"scale=160:-2,select='gt(scene,0.1)',metadata=print:file=-\" -f null - 2>/dev/null");
    preg_match_all('/pts_time:([\d.]+)/', $out, $m);
    return array_map('floatval', $m[1]);
}

/** Geser batas yang dekat (≤0,25 dtk) dengan cut presisi ke titik cut tersebut. */
function snap_to_cut(float $t, array $cuts): float
{
    foreach ($cuts as $c) {
        if (abs($c - $t) <= 0.25) {
            return $c;
        }
    }
    return $t;
}

/** Ubah hasil speaker_track.py (waktu relatif proxy) menjadi segmen, shot (waktu absolut) & posisi webcam streamer. */
function track_to_edit(?array $track, float $padStart, array $cuts = []): array
{
    if ($cuts) {
        foreach (['segments', 'shots'] as $k) {
            foreach ($track[$k] ?? [] as $i => $s) {
                $track[$k][$i]['start'] = $i === 0 ? $s['start'] : snap_to_cut($s['start'], $cuts);
                $track[$k][$i]['end'] = snap_to_cut($s['end'], $cuts);
            }
        }
    }
    $segments = [];
    foreach ($track['segments'] ?? [] as $s) {
        $segments[] = ['s' => round($s['start'] + $padStart, 3), 'e' => round($s['end'] + $padStart, 3),
            'cx' => round($s['cx'], 4), 'cy' => round($s['cy'], 4), 'fh' => round($s['fh'], 4)];
    }
    $shots = [];
    foreach ($track['shots'] ?? [] as $s) {
        $shots[] = ['s' => round($s['start'] + $padStart, 3), 'e' => round($s['end'] + $padStart, 3),
            'people' => array_map(fn($p) => ['cx' => round($p['cx'], 4), 'cy' => round($p['cy'], 4), 'fh' => round($p['fh'], 4)], $s['people'])];
    }
    $c = $track['cam'] ?? null;
    $cam = $c ? ['cx' => round($c['cx'], 4), 'cy' => round($c['cy'], 4), 'fh' => round($c['fh'], 4)] : null;
    return [$segments, $shots, $cam];
}

/** Tinggi video proxy (px), 0 kalau tidak terbaca. */
function proxy_height(string $file): int
{
    $s = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams v:0 -show_entries stream=height -of json ' . escapeshellarg($file)), true);
    return (int) ($s['streams'][0]['height'] ?? 0);
}

/**
 * Proxy lama (480p) dibuat ulang jadi PROXY_H dari video sumber lokal, supaya preview editor tajam. Resep edit tidak berubah
 * (rentang proxy = pad yang sama). Mengembalikan true kalau proxy diganti; false kalau sudah tajam / sumber lokal tidak ada.
 */
function upgrade_proxy(array $project, string $iid): bool
{
    $edit = read_edit($project['id'], $iid);
    $proxy = APP_DIR . '/' . proxy_rel($project['id'], $iid);
    if (!$edit || !empty($edit['pending']) || !is_file($proxy) || proxy_height($proxy) >= PROXY_H) {
        return false;
    }
    [$p0, $p1] = $edit['pad'];
    $local = local_source($project['analysis_id'], $p0, $p1);
    if (!$local || $local['h'] <= 480) {
        return false;
    }
    $lock = fopen("$proxy.lock", 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        return false; // sedang dibuat ulang oleh permintaan lain
    }
    $tmp = "$proxy.hd.mp4";
    // Sama dengan clip_inputs() untuk sumber lokal (dipanggil dari API, clip.php tidak dimuat): posisi relatif ke awal potongan
    $inputs = ' -ss ' . number_format(max(0, $p0 - (float) $local['shift']), 3, '.', '') . ' -i ' . escapeshellarg($local['path']);
    $ok = false;
    foreach (venc_list(PROXY_ENC) as $enc) {
        @unlink($tmp);
        [$code] = run_with_timeout(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error' . $inputs
            . ' -t ' . number_format($p1 - $p0, 3, '.', '') . " -map 0:v:0 -map '0:a:0?' -vf scale=-2:" . PROXY_H . ' -pix_fmt yuv420p'
            . " -c:a aac -b:a 128k $enc -movflags +faststart " . escapeshellarg($tmp), 300);
        clearstatcache();
        if ($ok = $code === 0 && is_file($tmp) && filesize($tmp) > 0) {
            break;
        }
    }
    if ($ok) {
        @chmod($tmp, 0666);
        rename($tmp, $proxy);
    }
    @unlink($tmp);
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink("$proxy.lock");
    return $ok;
}

/**
 * Hitung ulang segmen kamera & shot dari video preview lokal (untuk resep lama yang belum punya data shot).
 * Dipanggil langsung dari API; butuh ±5–15 detik.
 */
function retrack_edit(string $pid, string $iid): array
{
    $edit = read_edit($pid, $iid);
    $proxy = APP_DIR . '/' . proxy_rel($pid, $iid);
    if (!$edit || !is_file($proxy)) {
        throw new RuntimeException('Data editor belum siap.');
    }
    $tmp = TMP_DIR . '/track_' . $pid . '_' . $iid;
    @mkdir($tmp, 0777, true);
    // Waktu bicara dari subtitle (relatif terhadap awal proxy) membantu memilih pembicara
    $words = [];
    foreach ($edit['lines'] as $l) {
        foreach ($l['words'] as $w) {
            $words[] = [$w['s'] - $edit['pad'][0], $w['e'] - $edit['pad'][0], $w['w']];
        }
    }
    file_put_contents("$tmp/words.json", json_encode($words));
    $pp = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams v:0 -show_entries stream=width,height -of json '
        . escapeshellarg($proxy)), true)['streams'][0] ?? ['width' => 854, 'height' => 480];
    $h = (int) (round(640 * $pp['height'] / $pp['width'] / 2) * 2);
    [$code] = run_with_timeout(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -i ' . escapeshellarg($proxy)
        . " -an -vf fps=10,scale=640:$h -f rawvideo -pix_fmt bgr24 - | " . escapeshellarg(PYTHON) . ' '
        . escapeshellarg(APP_DIR . '/tools/speaker_track.py') . " --width 640 --height $h --fps 10"
        . ' --model ' . escapeshellarg(FACE_MODEL) . ' --words ' . escapeshellarg("$tmp/words.json")
        . ' --out ' . escapeshellarg("$tmp/track.json"), 120);
    $track = json_decode((string) @file_get_contents("$tmp/track.json"), true);
    rrmdir($tmp);
    if (!is_array($track)) {
        throw new RuntimeException('Deteksi wajah gagal.');
    }
    [$segments, $shots, $cam] = track_to_edit($track, $edit['pad'][0], precise_cuts($proxy));
    // Simpan ke resep terbaru tanpa mengubah editan lain
    $latest = read_edit($pid, $iid);
    $latest['segments'] = $segments;
    $latest['shots'] = $shots;
    $latest['cam'] = $cam;
    $latest['precise'] = true; // batas shot sudah dipresisikan ke cut per frame
    write_edit($pid, $iid, $latest);
    return $latest;
}

/** Pengaturan split screen otomatis (resep lama dengan layout "split" dianggap ikuti pembicara + split aktif). */
function split_cfg(array $edit): array
{
    $cfg = $edit['split'] ?? [];
    return [
        'enabled' => (!empty($cfg['enabled']) || ($edit['layout'] ?? '') === 'split' || ($edit['frame']['ratio'] ?? '') === 'split' && ($edit['kind'] ?? '') !== 'gaming')
            && ($edit['layout'] ?? '') !== 'gaming',
        // Toleransi = split muncul sekian detik LEBIH AWAL dari shot 2 orang yang terdeteksi AI
        'early' => max(0, min(5, (float) ($cfg['early'] ?? 0))),
    ];
}

/** Framing dasar (dipakai di luar bagian split). */
function base_layout(array $edit): string
{
    $e = upgrade_layout($edit);
    return $e['layout'] === 'gaming' ? 'gaming' : (($e['follow'] ?? true) === false ? 'crop_center' : 'speaker');
}

/** Shot 2 orang yang lebih pendek dari ini tidak dijadikan split, supaya tampilan tidak berkedip. */
const SPLIT_MIN_SHOT = 0.5;

/**
 * Bagian split screen (waktu absolut): shot berisi 2 orang, dimulai lebih awal sebesar toleransi
 * (tidak melewati akhir bagian split sebelumnya). Selama detik "lebih awal" itu posisi dua orang
 * diambil dari shot 2 orang tersebut. Hasil: [[s, e, [orangAtas, orangBawah]], ...]
 */
function split_parts(array $edit): array
{
    $early = split_cfg($edit)['early'];
    $parts = [];
    foreach ($edit['shots'] ?? [] as $sh) {
        if (count($sh['people']) < 2) {
            continue;
        }
        $pp = $sh['people'];
        usort($pp, fn($x, $y) => $y['fh'] <=> $x['fh']);
        $pp = array_slice($pp, 0, 2);
        usort($pp, fn($x, $y) => $x['cx'] <=> $y['cx']);
        if ($sh['e'] - $sh['s'] >= SPLIT_MIN_SHOT) {
            $parts[] = [$sh['s'], $sh['e'], $pp];
        }
    }
    usort($parts, fn($x, $y) => $x[0] <=> $y[0]);
    $prevEnd = 0.0;
    foreach ($parts as $k => [$s, $e]) {
        $parts[$k][0] = max($prevEnd, $s - $early, 0);
        $prevEnd = $e;
    }
    return $parts;
}

/**
 * Potongan render relatif ke awal clip. Bagian split = mode split; sisanya mengikuti framing dasar
 * (speaker = segmen pembicara, crop_center = crop tengah, blur = background blur).
 */
function edit_split_relative(array $edit): array
{
    [$t0, $t1] = $edit['trim'];
    $base = base_layout($edit);
    $out = [];
    $fill = function (float $a, float $b) use (&$out, $edit, $base, $t0) {
        if ($b - $a <= 0.05) {
            return;
        }
        if ($base !== 'speaker') {
            $out[] = ['mode' => $base, 'start' => $a - $t0, 'end' => $b - $t0];
            return;
        }
        $covered = false;
        foreach ($edit['segments'] as $s) {
            $sa = max($a, $s['s']);
            $sb = min($b, $s['e']);
            if ($sb - $sa > 0.05) {
                $out[] = ['mode' => 'single', 'start' => $sa - $t0, 'end' => $sb - $t0, 'cx' => $s['cx'], 'cy' => $s['cy'], 'fh' => $s['fh']];
                $covered = true;
            }
        }
        if (!$covered) {
            $out[] = ['mode' => 'crop_center', 'start' => $a - $t0, 'end' => $b - $t0];
        }
    };
    $cursor = $t0;
    foreach (split_parts($edit) as [$s, $e, $pp]) {
        $a = max($t0, $s, $cursor);
        $b = min($t1, $e);
        if ($b - $a <= 0.05) {
            continue;
        }
        $fill($cursor, $a);
        $out[] = ['mode' => 'split', 'start' => $a - $t0, 'end' => $b - $t0, 'a' => $pp[0], 'b' => $pp[1]];
        $cursor = $b;
    }
    $fill($cursor, $t1);
    // Rapatkan celah antar potongan supaya tidak ada frame yang hilang
    usort($out, fn($x, $y) => $x['start'] <=> $y['start']);
    foreach ($out as $k => $it) {
        if (isset($out[$k + 1])) {
            $out[$k]['end'] = $out[$k + 1]['start'];
        }
    }
    if ($out) {
        $out[0]['start'] = 0;
    }
    return $out;
}

/**
 * Masukkan hasil persiapan (baris subtitle / segmen kamera) ke resep terbaru tanpa menimpa
 * perubahan trim, gaya, atau hook yang mungkin sudah disimpan pengguna selama persiapan berjalan.
 */
function merge_prepared(string $pid, string $iid, array $fields, bool $stillPending): array
{
    $edit = read_edit($pid, $iid) ?? [];
    foreach ($fields as $k => $v) {
        $edit[$k] = $v;
    }
    if ($stillPending) {
        $edit['pending'] = true;
    } else {
        unset($edit['pending']);
    }
    $edit['updated_at'] = time();
    write_edit($pid, $iid, $edit);
    return $edit;
}

/** Bersihkan & validasi resep edit dari editor (input pengguna). */
function sanitize_edit(array $in, array $current): array
{
    $num = fn($v, $min, $max) => max($min, min($max, round((float) $v, 3)));
    [$p0, $p1] = $current['pad'];
    $edit = $current;
    $edit['edited'] = true; // pernah disimpan dari editor (label "Sudah diedit" di Studio)
    $t0 = $num($in['trim'][0] ?? $current['trim'][0], $p0, $p1);
    $t1 = $num($in['trim'][1] ?? $current['trim'][1], $p0, $p1);
    if ($t1 - $t0 < 1) {
        throw new RuntimeException('Durasi clip minimal 1 detik.');
    }
    $edit['trim'] = [$t0, $t1];
    // Titik potong video & bagian video yang dihapus (waktu absolut, di dalam trim)
    $cuts = [];
    foreach (array_slice((array) ($in['cuts'] ?? []), 0, 100) as $c) {
        if (is_numeric($c) && $c > $t0 + 0.05 && $c < $t1 - 0.05) {
            $cuts[] = round((float) $c, 3);
        }
    }
    $cuts = array_values(array_unique($cuts));
    sort($cuts);
    $edit['cuts'] = $cuts;
    $edit['gaps'] = [];
    foreach (array_slice((array) ($in['gaps'] ?? []), 0, 50) as $g) {
        $a = max($t0, (float) ($g[0] ?? 0));
        $b = min($t1, (float) ($g[1] ?? 0));
        if ($b - $a >= 0.05) {
            $edit['gaps'][] = [round($a, 3), round($b, 3)];
        }
    }
    // Jenis clip, ikuti pembicara, layout bingkai (rasio / split screen, sudut membulat, background solid / blur).
    // layout & split.enabled diturunkan dari situ: split screen streaming game = game + webcam, podcast = split 2 orang otomatis.
    $cur = upgrade_layout($current);
    $up = upgrade_layout(['layout' => (string) ($in['layout'] ?? $cur['layout']), 'frame' => (array) ($in['frame'] ?? $cur['frame']),
        'split' => (array) ($in['split'] ?? []), 'kind' => $in['kind'] ?? $cur['kind'], 'follow' => $in['follow'] ?? $cur['follow'] ?? true]);
    $fr = (array) $up['frame'];
    $ratio = in_array($fr['ratio'] ?? '', [...array_keys(FRAME_RATIOS), 'split'], true) ? $fr['ratio'] : 'full';
    $edit['kind'] = $up['kind'] === 'gaming' ? 'gaming' : 'podcast';
    $edit['follow'] = $up['follow'] !== false;
    $edit['frame'] = [
        'ratio' => $ratio,
        'radius' => (int) max(0, min(120, (int) ($fr['radius'] ?? 0))),
        'bg' => ($fr['bg'] ?? '') === 'blur' ? 'blur' : 'solid',
        'split' => in_array((int) ($fr['split'] ?? 0), SPLIT_RATIOS, true) ? (int) $fr['split'] : ($edit['kind'] === 'gaming' ? 70 : 50),
    ];
    $edit['layout'] = $ratio === 'split' && $edit['kind'] === 'gaming' ? 'gaming' : 'speaker';
    // Gaya teks ucapan: subtitle biasa atau teks kinetik (beat bertumpuk)
    $edit['caption'] = ($in['caption'] ?? '') === 'kinetic' ? 'kinetic' : 'subtitle';
    $edit['kinetic'] = kinetic_sanitize($in['kinetic'] ?? null, $p0, $p1);
    // Kualitas render: standard | hd (dipertajam) | ultra (sumber resolusi tertinggi)
    $edit['quality'] = in_array($in['quality'] ?? '', ['standard', 'hd', 'ultra'], true) ? $in['quality'] : ($current['quality'] ?? 'hd');
    $sp = (array) ($in['split'] ?? []);
    $edit['split'] = ['enabled' => $ratio === 'split' && $edit['kind'] !== 'gaming', 'early' => round($num($sp['early'] ?? 0, 0, 5), 2)];
    $edit['subtitles'] = !empty($in['subtitles']);
    $edit['volume'] = (int) $num($in['volume'] ?? 100, 0, 200); // volume audio clip, persen
    $edit['volKf'] = kf_sanitize($in['volKf'] ?? [], $p0, $p1, 200); // keyframe volume audio clip (kosong = volume tetap)

    $st = $in['style'] ?? [];
    $hex = fn($v, $d) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $v) ? strtoupper($v) : $d;
    $edit['style'] = [
        'size' => (int) $num($st['size'] ?? 98, 40, 180),
        'color' => $hex($st['color'] ?? '', '#FFFFFF'),
        'highlight' => $hex($st['highlight'] ?? '', '#FFD400'),
        'pos' => $num($st['pos'] ?? 0.71, 0.1, 0.95),
        'x' => round($num($st['x'] ?? 0.5, 0.03, 0.97), 4),
        // Pengaturan teks (panel "Teks" di editor)
        'bold' => !empty($st['bold']),
        'weight' => sub_font_weight($st), // ketebalan asli (0 = font bawaan)
        'italic' => !empty($st['italic']),
        'underline' => !empty($st['underline']),
        'spacing' => round($num($st['spacing'] ?? 1, -5, 30), 1),
        'align' => in_array($st['align'] ?? '', ['left', 'center', 'right'], true) ? $st['align'] : 'center',
        'opacity' => (int) $num($st['opacity'] ?? 100, 0, 100),
        'stroke' => $hex($st['stroke'] ?? '', '#000000'),
        'stroke_w' => round($num($st['stroke_w'] ?? 7, 0, 20), 1),
        'uppercase' => !empty($st['uppercase']),
        'font' => sub_font_key($st['font'] ?? null),
        'chunk' => (int) $num($st['chunk'] ?? 0, 0, 4), // kata per tampilan subtitle: 0 = sesuai baris, 1-4 kata
        'tpl' => preg_match('/^[a-z0-9-]{1,30}$/', (string) ($st['tpl'] ?? '')) ? $st['tpl'] : '', // template subtitle terakhir dipakai
        // Highlight kata yang sedang diucapkan & efek teks subtitle (mengikuti waktu ucapan); resep lama dikonversi oleh sub_hl_cfg()
    ] + sub_hl_cfg($st);

    $lines = [];
    foreach (array_slice((array) ($in['lines'] ?? []), 0, 3000) as $l) {
        $words = [];
        foreach (array_slice((array) ($l['words'] ?? []), 0, 40) as $w) {
            $text = trim(mb_substr((string) ($w['w'] ?? ''), 0, 60));
            if ($text === '') {
                continue;
            }
            $s = $num($w['s'] ?? 0, $p0, $p1);
            $e = $num($w['e'] ?? $s, $s, $p1);
            $words[] = ['s' => $s, 'e' => $e, 'w' => $text];
        }
        if ($words) {
            usort($words, fn($a, $b) => $a['s'] <=> $b['s']);
            $lines[] = ['s' => $words[0]['s'], 'e' => max(array_column($words, 'e')), 'words' => $words];
        }
    }
    usort($lines, fn($a, $b) => $a['s'] <=> $b['s']);
    // Selama persiapan berjalan, baris subtitle & segmen milik proses persiapan (belum bisa diedit)
    if (empty($current['pending'])) {
        $edit['lines'] = $lines;
    }

    $hk = $in['hook'] ?? [];
    $edit['hook'] = [
        'enabled' => !empty($hk['enabled']),
        'text' => trim(mb_substr((string) ($hk['text'] ?? ''), 0, 120)),
        'duration' => $num($hk['duration'] ?? 3.5, 0.5, 15),
        'font' => sub_font_key($hk['font'] ?? null),
        'size' => (int) $num($hk['size'] ?? 68, 30, 140),
        'bold' => !empty($hk['bold']),
        'weight' => sub_font_weight($hk),
        'italic' => !empty($hk['italic']),
        'underline' => !empty($hk['underline']),
        'spacing' => round($num($hk['spacing'] ?? 0, -5, 30), 1),
        'align' => in_array($hk['align'] ?? '', ['left', 'center', 'right'], true) ? $hk['align'] : 'center',
        'color' => $hex($hk['color'] ?? '', '#111111'),
        'opacity' => (int) $num($hk['opacity'] ?? 100, 0, 100),
        'bg' => $hex($hk['bg'] ?? '', '#FFFFFF'),
        'bg_opacity' => (int) $num($hk['bg_opacity'] ?? 100, 0, 100),
        'anim' => anim_sanitize($hk['anim'] ?? null, HOOK_ANIM),
    ];
    // Posisi hook hasil geser di preview (null = posisi bawaan sesuai layout)
    if (isset($hk['x']) && is_numeric($hk['x'])) {
        $edit['hook']['x'] = round($num($hk['x'], 0.03, 0.97), 4);
    }
    if (isset($hk['y']) && is_numeric($hk['y'])) {
        $edit['hook']['y'] = round($num($hk['y'], 0.02, 0.95), 4);
    }
    // Item timeline (media, teks, efek, musik) biasanya memakai detik video asli (rentang editor p0..p1).
    // Item yang digeser ke bagian footage / intro menempel ke footage itu: ref = id footage, s/e = detik dari awal footage itu
    // di video akhir (boleh berlanjut melewati sambungan ke clip). Footage-nya dicek setelah daftar footage disanitasi.
    $refRange = fn(array $it) => !empty($it['ref']) && is_string($it['ref']) && $it['ref'] !== 'main'
        ? [['ref' => preg_replace('/[^a-z0-9]/', '', $it['ref'])], -600.0, 3600.0] : [[], $p0, $p1];
    // Media (gambar) di timeline: waktu tampil absolut, posisi titik tengah & lebar sebagai pecahan layar 1080x1920
    $edit['media'] = [];
    foreach (array_slice((array) ($in['media'] ?? []), 0, 30) as $m) {
        if (!overlay_file_ok($m['file'] ?? null)) {
            continue;
        }
        [$ref, $lo, $hi] = $refRange($m);
        $s = $num($m['s'] ?? $lo, $lo, $hi);
        $edit['media'][] = $ref + [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($m['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'file' => $m['file'],
            'name' => mb_substr((string) ($m['name'] ?? ''), 0, 80),
            's' => round($s, 3),
            'e' => round($num($m['e'] ?? $s + 3, $s + 0.2, $hi), 3),
            'x' => round($num($m['x'] ?? 0.5, -0.5, 1.5), 4),
            'y' => round($num($m['y'] ?? 0.5, -0.5, 1.5), 4),
            'w' => round($num($m['w'] ?? 0.6, 0.05, 1.5), 4),
            'opacity' => (int) $num($m['opacity'] ?? 100, 0, 100), // transparansi gambar / grafik, persen
            'anim' => anim_sanitize($m['anim'] ?? null),
            'gfx' => gfx_sanitize($m['gfx'] ?? null),
        ];
    }
    // Teks tambahan bebas (panel Teks): waktu absolut, posisi jangkar x & tepi atas y (pecahan layar), gaya teks sendiri
    $edit['texts'] = [];
    foreach (array_slice((array) ($in['texts'] ?? []), 0, 30) as $tx) {
        $text = trim(mb_substr((string) ($tx['text'] ?? ''), 0, 200));
        if ($text === '') {
            continue;
        }
        [$ref, $lo, $hi] = $refRange($tx);
        $s = $num($tx['s'] ?? $lo, $lo, $hi);
        $edit['texts'][] = $ref + [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($tx['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'text' => $text,
            's' => round($s, 3),
            'e' => round($num($tx['e'] ?? $s + 3, $s + 0.2, $hi), 3),
            'x' => round($num($tx['x'] ?? 0.5, -0.2, 1.2), 4),
            'y' => round($num($tx['y'] ?? 0.45, 0, 0.98), 4),
            'font' => sub_font_key($tx['font'] ?? null),
            'size' => (int) $num($tx['size'] ?? 64, 20, 200),
            'bold' => !empty($tx['bold']),
            'weight' => sub_font_weight($tx),
            'italic' => !empty($tx['italic']),
            'underline' => !empty($tx['underline']),
            'spacing' => round($num($tx['spacing'] ?? 0, -5, 30), 1),
            'align' => in_array($tx['align'] ?? '', ['left', 'center', 'right'], true) ? $tx['align'] : 'center',
            'color' => $hex($tx['color'] ?? '', '#FFFFFF'),
            'opacity' => (int) $num($tx['opacity'] ?? 100, 0, 100),
            'stroke' => $hex($tx['stroke'] ?? '', '#000000'),
            'stroke_w' => round($num($tx['stroke_w'] ?? 0, 0, 20), 1),
            'bg' => $hex($tx['bg'] ?? '', '#000000'),
            'bg_opacity' => (int) $num($tx['bg_opacity'] ?? 0, 0, 100),
            'anim' => anim_sanitize($tx['anim'] ?? null),
        ];
    }
    // Efek transisi overlay: jenis preset, waktu absolut, intensitas (persen)
    $edit['effects'] = [];
    foreach (array_slice((array) ($in['effects'] ?? []), 0, 30) as $fx) {
        if (!isset(EFFECT_PRESETS[$fx['type'] ?? ''])) {
            continue;
        }
        [$ref, $lo, $hi] = $refRange($fx);
        $s = $num($fx['s'] ?? $lo, $lo, $hi);
        $edit['effects'][] = $ref + [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($fx['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'type' => $fx['type'],
            's' => round($s, 3),
            'e' => round($num($fx['e'] ?? $s + 1, $s + 0.2, $hi), 3),
            'intensity' => (int) $num($fx['intensity'] ?? 100, 0, 100),
        ];
    }
    $ov = (array) ($in['overlay'] ?? []);
    $file = overlay_file_ok($ov['file'] ?? null) ? $ov['file'] : '';
    $edit['overlay'] = [
        'enabled' => $file !== '' && !empty($ov['enabled']),
        'file' => $file,
        'pos' => in_array($ov['pos'] ?? '', ['top', 'center', 'bottom'], true) ? $ov['pos'] : 'bottom',
        'size' => round($num($ov['size'] ?? 0.9, 0.1, 1), 3),
        'offset' => (int) $num($ov['offset'] ?? 0, -600, 600),
    ];
    // Footage tambahan di awal (pre) / akhir (post) clip: gambar (durasi bebas, bawaan 5 dtk) atau video (durasi videonya)
    $edit['footage'] = [];
    foreach (array_slice((array) ($in['footage'] ?? []), 0, 20) as $f) {
        if (!footage_file_ok($f['file'] ?? null)) {
            continue;
        }
        $isVideo = footage_is_video($f['file']);
        $edit['footage'][] = [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($f['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'file' => $f['file'],
            'name' => mb_substr((string) ($f['name'] ?? ''), 0, 80),
            'kind' => $isVideo ? 'video' : 'image',
            'pos' => ($f['pos'] ?? '') === 'pre' ? 'pre' : 'post',
            'dur' => round($num($f['dur'] ?? 5, 0.5, $isVideo ? 600 : 60), 3),
        ] + (!$isVideo && is_array($f['intro'] ?? null) ? intro_sanitize($f, $num, $p0, $p1) : []);
    }
    // Sound effect (track SFX): menempel ke clip utama (ref 'main', at = detik video asli) atau ke footage (ref = id, at = detik di footage)
    $feet = array_column($edit['footage'], 'dur', 'id');
    $edit['sfx'] = [];
    foreach (array_slice((array) ($in['sfx'] ?? []), 0, 60) as $x) {
        $ref = (string) ($x['ref'] ?? 'main');
        if (!sfx_file_ok($x['file'] ?? null) || ($ref !== 'main' && !isset($feet[$ref]))) {
            continue;
        }
        $edit['sfx'][] = [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($x['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'file' => $x['file'],
            'name' => mb_substr((string) ($x['name'] ?? ''), 0, 80),
            'dur' => round($num($x['dur'] ?? 1, 0.02, 60), 3),
            'ref' => $ref,
            'at' => round($ref === 'main' ? $num($x['at'] ?? $p0, $p0, $p1) : $num($x['at'] ?? 0, 0, $feet[$ref]), 3),
            'vol' => (int) $num($x['vol'] ?? 100, 0, 200),
        ] + (!empty($x['tr']) ? ['tr' => preg_replace('/[^a-z0-9]/', '', (string) $x['tr'])] : []); // terikat ke transisi (posisi ikut durasinya)
    }
    // Musik latar dari pustaka musik: waktu absolut (s..e), off = detik lagu yang diputar di s, volume & fade
    $edit['music'] = [];
    foreach (array_slice((array) ($in['music'] ?? []), 0, 10) as $m) {
        if (!music_file_ok($m['file'] ?? null)) {
            continue;
        }
        [$ref, $lo, $hi] = $refRange($m);
        $s = $num($m['s'] ?? $lo, $lo, $hi);
        $len = $num($m['dur'] ?? 600, 0.2, 3600);
        $edit['music'][] = $ref + [
            'id' => preg_replace('/[^a-z0-9]/', '', (string) ($m['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'file' => $m['file'],
            'name' => mb_substr((string) ($m['name'] ?? ''), 0, 80),
            'dur' => $len,
            's' => round($s, 3),
            'e' => round($num($m['e'] ?? $s + $len, $s + 0.2, $hi), 3),
            'off' => $num($m['off'] ?? 0, 0, $len),
            'vol' => (int) $num($m['vol'] ?? 40, 0, 100),
            'fin' => round($num($m['fin'] ?? 0, 0, 10), 1),
            'fout' => round($num($m['fout'] ?? 1.5, 0, 10), 1),
            'kf' => kf_sanitize($m['kf'] ?? [], $lo, $hi, 100), // keyframe volume musik
        ];
    }
    // Item yang menempel ke footage yang sudah tidak ada dibuang
    foreach (['media', 'texts', 'effects', 'music'] as $k) {
        $edit[$k] = array_values(array_filter($edit[$k], fn($it) => !isset($it['ref']) || isset($feet[$it['ref']])));
    }
    // Kontrol track timeline: sembunyikan / kunci (+ bisukan untuk video & musik). Subtitle memakai saklar 'subtitles'.
    $tr = (array) ($in['tracks'] ?? []);
    $edit['tracks'] = [];
    foreach (['media', 'text', 'fx', 'sub', 'video', 'music', 'sfx'] as $k) {
        $t = (array) ($tr[$k] ?? []);
        $edit['tracks'][$k] = ['lock' => !empty($t['lock'])] + (!in_array($k, ['sub', 'music', 'sfx'], true) ? ['hide' => !empty($t['hide'])] : [])
            + (in_array($k, ['video', 'music', 'sfx'], true) ? ['mute' => !empty($t['mute'])] : []);
    }
    // Urutan lapisan di atas video (paling atas dulu)
    $edit['layers'] = edit_layer_order(['layers' => $in['layers'] ?? []]);
    // Transisi di sambungan menuju item (id footage / 'main'): jenis template & durasi
    $ids = array_merge(['main'], array_column($edit['footage'], 'id'));
    $edit['transitions'] = [];
    foreach ((array) ($in['transitions'] ?? []) as $k => $tr) {
        $k = (string) $k;
        if (in_array($k, $ids, true) && isset(TRANSITION_PRESETS[$tr['type'] ?? ''])) {
            $edit['transitions'][$k] = ['type' => $tr['type'], 'dur' => round($num($tr['dur'] ?? 0.5, 0.2, 2), 2)];
        }
    }
    // Animasi opening di awal video (null = tanpa opening)
    $op = $in['opening'] ?? null;
    $edit['opening'] = is_array($op) && isset(OPENING_PRESETS[$op['type'] ?? ''])
        ? ['type' => $op['type'], 'dur' => round($num($op['dur'] ?? OPENING_PRESETS[$op['type']]['dur'], 0.2, 5), 2)] : null;
    unset($edit['hook_intro']); // intro lama (dirakit saat render) diganti footage intro
    $edit['updated_at'] = time();
    return $edit;
}

/**
 * Data footage intro (menu Intro di editor): screenshot clip + grafik hook + suara AI yang membacakan hook.
 * Gambar akhirnya sudah jadi file footage biasa; data ini disimpan supaya intro bisa diedit & dibuat ulang.
 * vo = file suara (hookvo_*.wav di folder voice proyek) yang menjadi audio footage ini saat render.
 */
function intro_sanitize(array $f, callable $num, float $p0, float $p1): array
{
    $in = $f['intro'];
    $vo = (string) ($f['vo'] ?? '');
    return [
        'vo' => preg_match('/^hookvo_[a-f0-9]{32}\.wav$/', $vo) ? $vo : '',
        'intro' => [
            't' => round($num($in['t'] ?? $p0, $p0, $p1), 3),           // detik screenshot (waktu video asli)
            'gfx' => gfx_sanitize($in['gfx'] ?? null),
            'y' => round($num($in['y'] ?? 0.7, 0.05, 0.95), 4),          // posisi tengah grafik (bagian tinggi layar)
            'w' => round($num($in['w'] ?? 0.87, 0.3, 1), 4),
            'voice' => hook_voice_ok($in['voice'] ?? null) ? $in['voice'] : 'Puck',
            'style' => isset(TTS_STYLES[$in['style'] ?? '']) ? $in['style'] : 'auto',   // gaya baca suara AI
            'script' => mb_substr(trim((string) ($in['script'] ?? '')), 0, 300),       // naskah suara buatan user (kosong = dirapikan AI)
        ],
    ];
}

/** File pustaka media: gambar (overlay/footage) atau video (footage). */
function footage_file_ok($file): bool
{
    return is_string($file) && preg_match('/^[a-f0-9]{40}\.(png|jpg|webp|mp4|mov|webm|m4v)$/', $file) && is_file(OVERLAY_DIR . "/$file");
}

/** File pustaka musik: nama sha1 + ekstensi audio di MUSIC_DIR. */
function music_file_ok($file): bool
{
    return is_string($file) && preg_match('/^[a-f0-9]{40}\.(mp3|m4a|aac|wav|ogg|flac)$/', $file) && is_file(MUSIC_DIR . "/$file");
}

/* ---------- Keyframe volume (audio clip & musik): [{t: detik absolut, v: persen, e: transisi ke keyframe berikutnya}] ---------- */
const KF_EASES = ['linear', 'ease', 'in', 'out', 'hold'];

function kf_sanitize($list, float $p0, float $p1, int $maxV): array
{
    $out = [];
    foreach (array_slice((array) $list, 0, 50) as $k) {
        if (!is_array($k) || !is_numeric($k['t'] ?? null)) {
            continue;
        }
        $out[] = ['t' => round(max($p0, min($p1, (float) $k['t'])), 3), 'v' => (int) max(0, min($maxV, (int) round((float) ($k['v'] ?? 100)))),
            'e' => in_array($k['e'] ?? '', KF_EASES, true) ? $k['e'] : 'ease'];
    }
    usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
    // Keyframe di waktu yang sama: yang terakhir dipakai
    $uniq = [];
    foreach ($out as $k) {
        $uniq[(string) $k['t']] = $k;
    }
    return array_values($uniq);
}

/**
 * Ekspresi ffmpeg (filter volume, eval=frame) untuk kurva keyframe. $t0 = waktu absolut yang menjadi t=0 di filter.
 * Antar keyframe: linear, ease (pelan di awal & akhir), in (makin cepat), out (makin pelan), hold (tetap lalu lompat).
 * Sebelum keyframe pertama / sesudah terakhir: nilai keyframe itu. Sama dengan kfValue() di editor.
 */
function volume_kf_expr(array $kfs, float $t0): string
{
    usort($kfs, fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($kfs);
    $f = fn($i) => sprintf('%.4f', $kfs[$i]['v'] / 100);
    $T = fn($i) => sprintf('%.3f', $kfs[$i]['t'] - $t0);
    $expr = $f($n - 1);
    for ($i = $n - 2; $i >= 0; $i--) {
        $len = max(0.001, $kfs[$i + 1]['t'] - $kfs[$i]['t']);
        $p = sprintf('clip((t-%s)/%.3f,0,1)', $T($i), $len);
        $ease = match ($kfs[$i]['e']) {
            'hold' => '0',
            'in' => "($p)*($p)",
            'out' => "(1-(1-$p)*(1-$p))",
            'ease' => "($p)*($p)*(3-2*($p))",
            default => $p,
        };
        $seg = sprintf('%s+(%s-%s)*%s', $f($i), $f($i + 1), $f($i), $ease);
        $expr = sprintf('if(lt(t,%s),%s,%s)', $T($i + 1), $seg, $expr);
    }
    return $expr;
}

/** Pustaka musik bersama untuk semua proyek (storage/music.json), terbaru dulu. */
function read_music_library(): array
{
    $lib = json_decode((string) @file_get_contents(STORAGE_DIR . '/music.json'), true);
    return array_values(array_filter(is_array($lib) ? $lib : [], fn($m) => music_file_ok($m['file'] ?? null)));
}

function write_music_library(array $lib): void
{
    file_put_contents(STORAGE_DIR . '/music.json', json_encode(array_slice($lib, 0, 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(STORAGE_DIR . '/music.json', 0666);
}

/** Taruh lagu (file sudah ada di MUSIC_DIR) paling atas di pustaka; lagu yang sama tidak dobel. Sumber: upload | download. */
function music_library_put(string $file, string $name, float $dur, string $from): array
{
    $lib = array_values(array_filter(read_music_library(), fn($m) => $m['file'] !== $file));
    array_unshift($lib, ['file' => $file, 'name' => mb_substr(trim($name) ?: 'Musik', 0, 80), 'dur' => round($dur, 3), 'added' => time(), 'from' => $from]);
    write_music_library($lib);
    return $lib;
}

/**
 * Masukkan hasil unduhan audio (MP3/WAV di folder downloads) ke pustaka musik.
 * Memakai hard link supaya tidak memakan ruang disk dua kali (salin biasa kalau link gagal).
 */
function music_library_import(string $path, string $name): void
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $dur = (float) trim((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path)));
    if (!in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'flac'], true) || $dur < 1) {
        return;
    }
    if (!is_dir(MUSIC_DIR)) {
        @mkdir(MUSIC_DIR, 0777, true);
        @chmod(MUSIC_DIR, 0777);
    }
    $file = sha1_file($path) . ".$ext";
    $dest = MUSIC_DIR . "/$file";
    if (!is_file($dest) && !@link($path, $dest) && !copy($path, $dest)) {
        return;
    }
    @chmod($dest, 0666);
    music_library_put($file, $name, $dur, 'download');
}

/**
 * Potongan musik yang masuk ke clip, waktunya relatif ke awal clip:
 * [file, mulai di clip, panjang, mulai di lagu, volume 0..1, fade in, fade out]. Kosong kalau track musik dibisukan.
 */
function edit_music_relative(array $edit): array
{
    if (!empty($edit['tracks']['music']['mute'])) {
        return [];
    }
    [$t0, $t1] = $edit['trim'];
    $out = [];
    foreach ($edit['music'] ?? [] as $m) {
        $s = max((float) $m['s'], $t0);
        $e = min((float) $m['e'], $t1);
        if ($e - $s < 0.1 || $m['vol'] <= 0 || !music_file_ok($m['file'])) {
            continue;
        }
        $len = $e - $s;
        $out[] = ['file' => MUSIC_DIR . '/' . $m['file'], 'at' => $s - $t0, 'len' => $len, 'off' => $m['off'] + ($s - $m['s']),
            'vol' => $m['vol'] / 100, 'fin' => min($m['fin'], $len / 2), 'fout' => min($m['fout'], $len / 2),
            // Keyframe: t=0 filter = awal potongan musik di clip
            'kfexpr' => !empty($m['kf']) ? volume_kf_expr($m['kf'], $s) : null];
    }
    return $out;
}

/** File sound effect di pustaka SFX: bawaan (paper_rip.wav) atau unggahan (sha1.ext). */
function sfx_file_ok($file): bool
{
    return is_string($file) && preg_match('/^[a-z0-9_]{3,64}\.(wav|mp3|m4a|aac|ogg|flac)$/', $file) && is_file(SFX_DIR . "/$file");
}

/** Pustaka SFX: bawaan (dibuat sekali oleh tools/sfx_gen.py) lalu unggahan user. [{file, name, dur, builtin}] */
function sfx_library(): array
{
    $manifest = SFX_DIR . '/manifest.json';
    if (!is_file($manifest)) {
        @mkdir(SFX_DIR, 0777, true);
        @chmod(SFX_DIR, 0777);
        exec(escapeshellarg(PYTHON) . ' ' . escapeshellarg(APP_DIR . '/tools/sfx_gen.py') . ' --out ' . escapeshellarg(SFX_DIR) . ' 2>&1');
    }
    $built = (array) json_decode((string) @file_get_contents($manifest), true);
    // Detik puncak tiap SFX bawaan dihitung sekali lalu disimpan di manifest
    if ($built && !isset($built[0]['peak'])) {
        $built = array_map(fn($s) => $s + ['peak' => audio_peak(SFX_DIR . '/' . $s['file'])], $built);
        @file_put_contents($manifest, json_encode($built, JSON_UNESCAPED_UNICODE));
    }
    $built = array_map(fn($s) => $s + ['builtin' => true] + ($s['file'] === 'paper_rip.wav' ? ['tag' => 'paper'] : []), $built);
    $user = json_decode((string) @file_get_contents(STORAGE_DIR . '/sfx.json'), true);
    return array_values(array_filter([...$built, ...(is_array($user) ? $user : [])], fn($s) => sfx_file_ok($s['file'] ?? null)));
}

/** API key ElevenLabs (storage/elevenlabs.key, tidak bisa diakses lewat web); kosong = fitur SFX AI tidak aktif. */
function elevenlabs_key(): string
{
    return trim((string) @file_get_contents(STORAGE_DIR . '/elevenlabs.key'));
}

/**
 * Detik puncak (bagian paling keras) sebuah file audio: dipakai menyelaraskan SFX dengan momen visual,
 * mis. bunyi robekan dengan saat kertas mulai robek di transisi. Jendela RMS 40 ms.
 */
function audio_peak(string $path): float
{
    $pcm = (string) shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -loglevel error -i ' . escapeshellarg($path)
        . ' -ac 1 -ar 8000 -f s16le - 2>/dev/null');
    $s = $pcm !== '' ? array_values(unpack('s*', $pcm)) : [];
    $win = 320;
    [$best, $at] = [-1.0, 0];
    for ($i = 0; $i + $win <= count($s); $i += 80) {
        $e = 0.0;
        for ($k = $i; $k < $i + $win; $k += 4) {
            $e += $s[$k] * $s[$k];
        }
        if ($e > $best) {
            [$best, $at] = [$e, $i];
        }
    }
    return round(($at + $win / 2) / 8000, 3);
}

/**
 * Buat sound effect dari deskripsi teks lewat ElevenLabs Sound Effects, simpan ke pustaka SFX.
 * $dur null = durasi ditentukan AI. $tag mis. 'paper' = SFX kertas robek untuk transisi kertas.
 */
function sfx_generate_ai(string $text, ?float $dur, string $name, string $tag = ''): array
{
    $key = elevenlabs_key();
    if ($key === '') {
        throw new RuntimeException('API key ElevenLabs belum diatur.');
    }
    $body = ['text' => $text, 'prompt_influence' => 0.5] + ($dur ? ['duration_seconds' => max(0.5, min(22, $dur))] : []);
    $ch = curl_init('https://api.elevenlabs.io/v1/sound-generation?output_format=mp3_44100_128');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $key, 'Content-Type: application/json', 'Accept: audio/mpeg'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('Tidak bisa terhubung ke ElevenLabs. Periksa internet lalu coba lagi.');
    }
    if ($code !== 200 || !str_contains($type, 'audio')) {
        $err = json_decode((string) $res, true)['detail'] ?? [];
        $status = is_array($err) ? (string) ($err['status'] ?? '') : '';
        throw new RuntimeException(match (true) {
            $status === 'missing_permissions' => 'API key ElevenLabs belum punya izin Sound Effects. Buka elevenlabs.io → Developers → API Keys, edit key ini, lalu aktifkan izin "Sound Effects".',
            $status === 'quota_exceeded' || $code === 402 => 'Kredit ElevenLabs habis. Isi ulang kredit atau tunggu periode berikutnya.',
            $code === 401 => 'API key ElevenLabs tidak valid.',
            $code === 429 => 'ElevenLabs sedang sibuk / batas pemakaian tercapai. Coba lagi sebentar.',
            default => 'ElevenLabs gagal membuat sound effect' . (is_array($err) && !empty($err['message']) ? ': ' . $err['message'] : " (HTTP $code)."),
        });
    }
    sfx_library(); // pastikan folder pustaka ada
    $file = sha1((string) $res) . '.mp3';
    file_put_contents(SFX_DIR . "/$file", $res);
    @chmod(SFX_DIR . "/$file", 0666);
    $len = (float) trim((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg(SFX_DIR . "/$file")));
    $user = json_decode((string) @file_get_contents(STORAGE_DIR . '/sfx.json'), true) ?: [];
    $user[] = ['file' => $file, 'name' => mb_substr($name, 0, 80), 'dur' => round($len, 3), 'ai' => true, 'prompt' => mb_substr($text, 0, 300),
        'peak' => audio_peak(SFX_DIR . "/$file")] + ($tag !== '' ? ['tag' => $tag] : []);
    write_sfx_uploads($user);
    return end($user);
}

/** Simpan daftar SFX unggahan user (bawaan tidak disimpan di sini). */
function write_sfx_uploads(array $list): void
{
    $path = STORAGE_DIR . '/sfx.json';
    file_put_contents($path, json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path, 0666);
}

/**
 * Detik awal tiap potongan di video akhir (footage awal, clip yang dipertahankan, footage akhir; sama dengan footage_concat()):
 * [[id footage | 'main' => detik], panjang total, rentang clip yang dipertahankan (relatif awal trim)].
 */
function edit_output_starts(array $edit): array
{
    [$t0, $t1] = $edit['trim'];
    $keep = edit_keep_ranges($edit) ?: [[0, $t1 - $t0]];
    $mainLen = array_sum(array_map(fn($r) => $r[1] - $r[0], $keep));
    $start = [];
    $pos = 0.0;
    foreach (edit_footage_sequence($edit) ?: ['main'] as $it) {
        $start[$it === 'main' ? 'main' : $it['id']] = $pos;
        $pos += $it === 'main' ? $mainLen : (float) $it['dur'];
    }
    return [$start, $pos, $keep];
}

/** Resep edit tanpa item yang menempel ke footage (dirender seperti biasa di clip), dan daftar item yang menempel ke footage. */
function edit_split_ref(array $edit): array
{
    $ref = [];
    foreach (['media', 'texts', 'effects', 'music'] as $k) {
        $ref[$k] = array_values(array_filter($edit[$k] ?? [], fn($it) => !empty($it['ref'])));
        $edit[$k] = array_values(array_filter($edit[$k] ?? [], fn($it) => empty($it['ref'])));
    }
    return [$edit, $ref];
}

/**
 * Item yang menempel ke footage dipindah ke waktu video akhir (detik dari awal video), untuk dirender di lapisan paling akhir.
 * Mengembalikan resep "video akhir" (trim = [0, total]) berisi item-item itu saja, atau null kalau tidak ada.
 */
function edit_ref_output(array $edit, array $ref): ?array
{
    if (!array_filter($ref)) {
        return null;
    }
    [$start, $total] = edit_output_starts($edit);
    $g = ['trim' => [0.0, $total], 'pad' => [0.0, $total], 'tracks' => $edit['tracks'] ?? [], 'layers' => $edit['layers'] ?? [],
        'layout' => $edit['layout'] ?? 'speaker', 'style' => $edit['style'] ?? [], 'hook' => ['enabled' => false] + ($edit['hook'] ?? []),
        'overlay' => ['enabled' => false], 'media' => [], 'texts' => [], 'effects' => [], 'music' => []];
    foreach ($ref as $k => $items) {
        foreach ($items as $it) {
            if (!isset($start[$it['ref']])) {
                continue;
            }
            $o = $start[$it['ref']];
            $s = max(0, $o + $it['s']);
            $e = min($total, $o + $it['e']);
            if ($e - $s < 0.05) {
                continue;
            }
            // Musik: bagian lagu yang terpotong di depan (s < 0) dilewati; keyframe ikut digeser ke waktu video akhir
            $extra = $k === 'music' ? ['off' => $it['off'] + ($s - ($o + $it['s'])),
                'kf' => array_map(fn($p) => ['t' => $p['t'] + $o] + $p, $it['kf'] ?? [])] : [];
            $g[$k][] = ['s' => $s, 'e' => $e] + $extra + $it;
        }
    }
    return $g;
}

/**
 * Sound effect di video akhir (setelah bagian terhapus dibuang & footage disambung): [file, detik di video akhir, panjang, volume].
 * Urutan video akhir = footage awal, clip (bagian yang dipertahankan), footage akhir, sama dengan footage_concat().
 */
function edit_sfx_output(array $edit): array
{
    if (empty($edit['sfx']) || !empty($edit['tracks']['sfx']['mute'])) {
        return [];
    }
    [$t0] = $edit['trim'];
    [$start, , $keep] = edit_output_starts($edit);
    $out = [];
    foreach ($edit['sfx'] as $x) {
        if (!isset($start[$x['ref']]) || $x['vol'] <= 0 || !sfx_file_ok($x['file'])) {
            continue;
        }
        if ($x['ref'] === 'main') {
            // Detik video asli → detik clip yang dipertahankan; SFX di luar trim / di bagian terhapus tidak dipakai
            $rel = $x['at'] - $t0;
            $at = null;
            $acc = 0.0;
            foreach ($keep as [$a, $b]) {
                if ($rel >= $a - 0.001 && $rel < $b) {
                    $at = $acc + max(0, $rel - $a);
                    break;
                }
                $acc += $b - $a;
            }
            if ($at === null) {
                continue;
            }
        } else {
            $at = (float) $x['at'];
        }
        $out[] = ['file' => SFX_DIR . '/' . $x['file'], 'at' => $start[$x['ref']] + $at, 'len' => (float) $x['dur'], 'off' => 0,
            'vol' => $x['vol'] / 100, 'fin' => 0, 'fout' => 0];
    }
    return $out;
}

function footage_is_video(string $file): bool
{
    return (bool) preg_match('/\.(mp4|mov|webm|m4v)$/', $file);
}

/** Urutan potongan video akhir: footage awal, 'main' (clip hasil render), footage akhir. Kosong kalau tidak ada footage. */
function edit_footage_sequence(array $edit): array
{
    $items = array_values(array_filter($edit['footage'] ?? [], fn($f) => footage_file_ok($f['file'] ?? null)));
    if (!$items) {
        return [];
    }
    return [...array_filter($items, fn($f) => $f['pos'] === 'pre'), 'main', ...array_filter($items, fn($f) => $f['pos'] !== 'pre')];
}

/**
 * Parameter template grafik (judul berita ala clipper). Gambarnya dibuat di editor (canvas → PNG) dan dipakai sebagai media;
 * parameter disimpan supaya grafik bisa diedit lagi.
 */
function gfx_sanitize($g): ?array
{
    if (!is_array($g) || !in_array($g['type'] ?? '', ['hook', 'hook_dark', 'hook_yellow', 'hook_red', 'hook_grad', 'hook_outline', 'clipnews', 'news', 'viral', 'breaking', 'minimal', 'banner', 'banner_blue', 'banner_dark', 'banner_yellow', 'banner_white', 'banner_square', 'banner_pill', 'quote', 'quote_dark', 'quote_blue', 'quote_yellow'], true)) {
        return null;
    }
    $hex = fn($v, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? strtoupper((string) $v) : $d;
    return [
        'type' => $g['type'],
        'label' => mb_substr(trim((string) ($g['label'] ?? '')), 0, 40),
        'text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 200),
        'c1' => $hex($g['c1'] ?? '', '#E5432E'),
        'c2' => $hex($g['c2'] ?? '', '#FFFFFF'),
        'c3' => $hex($g['c3'] ?? '', '#111111'),
        'c4' => $hex($g['c4'] ?? '', ''),   // latar label (banner); kosong = ikut warna kartu
        'c5' => $hex($g['c5'] ?? '', ''),   // teks label (banner); kosong = ikut warna judul
        'ls' => (int) max(60, min(200, (int) ($g['ls'] ?? 100))), // ukuran label, persen
        'ts' => (int) max(60, min(160, (int) ($g['ts'] ?? 100))), // ukuran teks utama, persen
        'lh' => (int) max(70, min(160, (int) ($g['lh'] ?? 100))), // jarak baris teks utama, persen
        'tw' => in_array((string) ($g['tw'] ?? ''), ['400', '500', '600', '700', '800', '900'], true) ? (string) $g['tw'] : '',
        'ta' => in_array((string) ($g['ta'] ?? ''), ['left', 'center', 'right'], true) ? (string) $g['ta'] : '',
        'fill' => ($g['fill'] ?? true) !== false,                  // banner: pita sampai bawah layar
        'ext' => (int) max(0, min(4000, (int) ($g['ext'] ?? 0))),  // panjang tambahan pita (px desain)
    ];
}

/** Versi ekspresi ffmpeg dari anim_phase() (tanpa alpha; alpha memakai filter fade): [geser x, geser y, skala]. */
function anim_expr(string $kind, string $phase, string $p): array
{
    $D = ANIM_DIST;
    if ($phase === 'in') {
        $e = "(1-pow(1-$p,3))";
        return match ($kind) {
            'up' => ['0', "$D*(1-$e)", '1'],
            'down' => ['0', "-$D*(1-$e)", '1'],
            'left' => ["$D*(1-$e)", '0', '1'],
            'right' => ["-$D*(1-$e)", '0', '1'],
            'zoom' => ['0', '0', "(0.6+0.4*$e)"],
            'pop' => ['0', '0', "if(lt($p,0.6),max(0.05,1.12*sin($p/0.6*PI/2)),1.12-0.12*sin(($p-0.6)/0.4*PI/2))"],
            default => ['0', '0', '1'],
        };
    }
    $i = "pow($p,3)";
    return match ($kind) {
        'up' => ['0', "-$D*$i", '1'],
        'down' => ['0', "$D*$i", '1'],
        'left' => ["-$D*$i", '0', '1'],
        'right' => ["$D*$i", '0', '1'],
        'zoom' => ['0', '0', "(1-0.4*$i)"],
        'pop' => ['0', '0', "if(lt($p,0.35),1+0.1*sin($p/0.35*PI/2),max(0.05,1.1*(1-sin(($p-0.35)/0.65*PI/2))))"],
        default => ['0', '0', '1'],
    };
}

function overlay_file_ok($file): bool
{
    return is_string($file) && preg_match('/^[a-f0-9]{40}\.(png|jpg|webp)$/', $file) && is_file(OVERLAY_DIR . "/$file");
}

/**
 * Potongan filtergraph untuk menempel gambar overlay (lower third) di video 1080x1920 berlabel [$in] -> [$out].
 * Mengembalikan '' kalau overlay tidak aktif. Posisi sama dengan pratinjau di editor.
 */
function overlay_graph(array $edit, string $in, string $out, bool $withMedia = true): string
{
    $parts = [];
    $cur = $in;
    $t0 = (float) $edit['trim'][0];
    $dur = (float) $edit['trim'][1] - $t0;
    // Track video disembunyikan: layar hitam (efek, media & teks tetap tampil)
    if (!empty($edit['tracks']['video']['hide'])) {
        $parts[] = "[$cur]drawbox=c=black:t=fill[vhid]";
        $cur = 'vhid';
    }
    // Efek aktif (waktu relatif ke awal clip), dikelompokkan menurut cara render
    $groups = ['move' => [], 'grade' => [], 'glow' => [], 'screen' => [], 'dark' => []];
    foreach (empty($edit['tracks']['fx']['hide']) ? array_values($edit['effects'] ?? []) : [] as $fx) {
        $s = max(0, $fx['s'] - $t0);
        $e = min($dur, $fx['e'] - $t0);
        $pre = EFFECT_PRESETS[$fx['type']] ?? null;
        if (!$pre || $e - $s < 0.05 || $fx['intensity'] <= 0) {
            continue;
        }
        $item = ['s' => round($s, 3), 'e' => round($e, 3), 'd' => round($e - $s, 3), 'k' => $fx['intensity'] / 100, 'pre' => $pre];
        $groups[match ($pre['kind']) { 'zoom', 'shake' => 'move', 'grade' => 'grade', 'glow' => 'glow', 'dip', 'vignette' => 'dark', default => 'screen' }][] = $item;
    }
    // 1. Gerak kamera (zoom punch / guncangan): potongan video di rentang efek diubah lalu ditempel kembali
    foreach ($groups['move'] as $j => $fx) {
        [$s, $e, $d, $k] = [$fx['s'], $fx['e'], $fx['d'], $fx['k']];
        $env = effect_env_expr($fx['pre']['kind'], $d, 't');
        if ($fx['pre']['kind'] === 'zoom') {
            $z = "(1+0.18*$k*$env)";
            $tf = "scale=w='trunc(1080*$z/2)*2':h='trunc(1920*$z/2)*2':eval=frame:flags=bicubic,crop=1080:1920";
        } else {
            // Sedikit diperbesar (ikut mereda) supaya geseran tidak memperlihatkan tepi frame
            $z = "(1+0.06*$env)";
            $a = round(30 * $k, 1);
            $tf = "scale=w='trunc(1080*$z/2)*2':h='trunc(1920*$z/2)*2':eval=frame:flags=bicubic,"
                . "crop=1080:1920:x='(iw-ow)/2+$a*$env*sin(2*PI*13*t)':y='(ih-oh)/2+$a*$env*cos(2*PI*11*t)'";
        }
        $parts[] = "[$cur]split[mva$j][mvb$j]";
        $parts[] = "[mvb$j]trim=start=$s:end=$e,setpts=PTS-STARTPTS,$tf,setpts=PTS+$s/TB[mvc$j]";
        $parts[] = "[mva$j][mvc$j]overlay=eof_action=pass:enable='between(t,$s,$e)'[mvo$j]";
        $cur = "mvo$j";
    }
    // 1b. Filter (color grading): eq lalu lapisan warna mode soft light, hanya di rentang filter. Sama dengan drawEffects() di editor.
    foreach ($groups['grade'] as $j => $fx) {
        [$s, $e, $k] = [$fx['s'], $fx['e'], $fx['k']];
        [$b, $c, $sa] = $fx['pre']['eq'];
        if (($mul = $fx['pre']['mul'] ?? 1) < 1) {
            $m = round(1 - (1 - $mul) * $k, 3);
            $parts[] = "[$cur]colorchannelmixer=rr=$m:gg=$m:bb=$m:enable='between(t,$s,$e)'[grm$j]";
            $cur = "grm$j";
        }
        $parts[] = sprintf("[$cur]eq=brightness=%.3f:contrast=%.3f:saturation=%.3f:enable='between(t,%.3f,%.3f)'[gre$j]",
            $b * $k, 1 + ($c - 1) * $k, 1 + ($sa - 1) * $k, $s, $e);
        $cur = "gre$j";
        if ($tint = $fx['pre']['tint'] ?? null) {
            $parts[] = sprintf('color=c=0x%02X%02X%02X:s=1080x1920:r=30:d=%.3f,format=gbrp[grc%d]', $tint[0], $tint[1], $tint[2], $dur + 1, $j);
            $parts[] = "[$cur]format=gbrp[grb$j]";
            $parts[] = sprintf("[grb$j][grc$j]blend=all_mode=softlight:all_opacity=%.3f:shortest=1:enable='between(t,%.3f,%.3f)',format=yuv420p[grt$j]",
                $tint[3] * $k, $s, $e);
            $cur = "grt$j";
        }
    }
    // 2. Glow lembut: salinan blur dicampur mode screen
    foreach ($groups['glow'] as $j => $fx) {
        [$s, $e, $d] = [$fx['s'], $fx['e'], $fx['d']];
        $env = effect_env_expr('glow', $d, "(T-$s)");
        $parts[] = "[$cur]split[gla$j][glb$j]";
        $parts[] = "[glb$j]gblur=sigma=26:enable='between(t,$s,$e)',format=gbrp[glc$j]";
        $parts[] = "[gla$j]format=gbrp[gld$j]";
        $parts[] = "[gld$j][glc$j]blend=all_expr='A+(B-A*B/255)*" . round(0.85 * $fx['k'], 3) . "*$env':enable='between(t,$s,$e)',format=yuv420p[glo$j]";
        $cur = "glo$j";
    }
    $ov = $edit['overlay'] ?? [];
    if (!empty($ov['enabled']) && overlay_file_ok($ov['file'] ?? null)) {
        $w = (int) round(1080 * $ov['size']);
        $m = OVERLAY_MARGIN;
        $y = match ($ov['pos']) { 'top' => "$m", 'center' => '(H-h)/2', default => "H-h-$m" } . sprintf('%+d', (int) $ov['offset']);
        $parts[] = 'movie=' . OVERLAY_DIR . '/' . $ov['file'] . ",scale=$w:-1,format=rgba[ovimg]";
        $parts[] = "[$cur][ovimg]overlay=x=(W-w)/2:y=$y:format=auto[ov0]";
        $cur = 'ov0';
    }
    // 3. Cahaya (light leak, bokeh, sweep, flare, flash): satu lapisan hitam selebar clip, dicampur mode screen
    if ($groups['screen']) {
        $parts[] = sprintf('color=c=black:s=1080x1920:r=30:d=%.3f,format=gbrp[fxbase]', $dur + 1);
        $layer = 'fxbase';
        foreach ($groups['screen'] as $j => $fx) {
            $d = $fx['d'];
            $kind = $fx['pre']['kind'];
            $env = effect_env_expr($kind, $d);
            $P = "min(1,T/$d)";
            $ch = [];
            if ($kind === 'leak') {
                // Jumlah gumpalan gaussian yang bergerak (koordinat dalam lebar layar, frame kecil lalu diperbesar)
                foreach ([5 => 'r', 6 => 'g', 7 => 'b'] as $ci => $name) {
                    $terms = [];
                    foreach ($fx['pre']['blobs'] as $bl) {
                        $c = round($bl[$ci] / 255, 3);
                        $x = "({$bl[0]}+(" . ($bl[2] - $bl[0]) . ")*$P)";
                        $y = "({$bl[1]}+(" . ($bl[3] - $bl[1]) . ")*$P)";
                        $terms[] = "$c*exp(-((X/W-$x)*(X/W-$x)+(Y/H-$y)*(Y/H-$y)*(H/W)*(H/W))/({$bl[4]}*{$bl[4]}))";
                    }
                    $ch[] = "$name='clip(255*{$fx['k']}*$env*(" . implode('+', $terms) . "),0,255)'";
                }
            } elseif ($kind === 'flash') {
                foreach (['r', 'g', 'b'] as $ci => $name) {
                    $ch[] = "$name='clip(" . round($fx['pre']['color'][$ci] * $fx['k'], 2) . "*$env,0,255)'";
                }
            } else {
                [$col, $white] = effect_light_terms($fx['pre'], $P);
                foreach (['r', 'g', 'b'] as $ci => $name) {
                    $ch[] = "$name='clip(255*{$fx['k']}*$env*($col*" . round($fx['pre']['color'][$ci] / 255, 3) . "+$white),0,255)'";
                }
            }
            $parts[] = "color=c=black:s=270x480:r=30:d=$d,format=gbrp,geq=" . implode(':', $ch) . ",scale=1080:1920:flags=bicubic,setpts=PTS+"
                . $fx['s'] . "/TB[fxs$j]";
            $parts[] = "[$layer][fxs$j]overlay=eof_action=pass:format=gbrp[fxl$j]";
            $layer = "fxl$j";
        }
        $parts[] = "[$cur]format=gbrp[fxa]";
        $parts[] = "[$layer]format=gbrp[fxb]";
        $parts[] = '[fxa][fxb]blend=all_mode=screen:shortest=1,format=yuv420p[fxo]';
        $cur = 'fxo';
    }
    // 4. Gelap: fade hitam ditempel dengan alpha, vignette memakai filter vignette
    foreach ($groups['dark'] as $j => $fx) {
        $d = $fx['d'];
        if ($fx['pre']['kind'] === 'vignette') {
            $env = effect_env_expr('vignette', $d, "(t-{$fx['s']})");
            $parts[] = "[$cur]vignette=angle='0.12+" . round(0.95 * $fx['k'], 3) . "*$env':eval=frame:enable='between(t,{$fx['s']},{$fx['e']})'[fxk$j]";
        } else {
            $parts[] = "color=c=black:s=64x112:r=30:d=$d,format=rgba,geq=r=0:g=0:b=0:a='clip(255*{$fx['k']}*"
                . effect_env_expr('dip', $d) . ",0,255)',scale=1080:1920,setpts=PTS+" . $fx['s'] . "/TB[fxd$j]";
            $parts[] = "[$cur][fxd$j]overlay=eof_action=pass:format=auto[fxk$j]";
        }
        $cur = "fxk$j";
    }
    // Media & grafik (bisa dilewati: urutannya diatur lewat lapisan, lihat media_graph() & layers_chain())
    if ($withMedia) {
        $mg = media_graph($edit, $cur, 'medo');
        if ($mg !== '') {
            $parts[] = $mg;
            $cur = 'medo';
        }
    }
    if (!$parts) {
        return '';
    }
    $parts[] = "[$cur]null[$out]";
    return implode(";\n", $parts);
}

/**
 * Media & grafik di timeline sebagai overlay [$in] -> [$out]. $k = faktor skala layar (1 = 1080x1920, 4/3 = 2K).
 * Mengembalikan '' kalau tidak ada media (atau track Media disembunyikan).
 */
function media_graph(array $edit, string $in, string $out, float $k = 1.0): string
{
    if (!empty($edit['tracks']['media']['hide'])) {
        return '';
    }
    $t0 = (float) $edit['trim'][0];
    $dur = (float) $edit['trim'][1] - $t0;
    $kx = fn($e) => $e === '0' || abs($k - 1) < 1e-6 ? $e : sprintf('%.4f*(%s)', $k, $e);
    $parts = [];
    $cur = $in;
    // Tiap gambar tampil hanya di rentang waktunya (relatif ke awal clip); urutan array = urutan tumpuk
    foreach (array_values($edit['media'] ?? []) as $i => $m) {
        $s = max(0, $m['s'] - $t0);
        $e = min($dur, $m['e'] - $t0);
        if ($e - $s < 0.05 || !overlay_file_ok($m['file'] ?? null)) {
            continue;
        }
        $w = max(2, (int) round(1080 * $k * $m['w'] / 2) * 2);
        $X = (int) round(1080 * $k * $m['x']);
        $Y = (int) round(1920 * $k * $m['y']);
        $anim = $m['anim'] ?? null;
        [$din, $dout] = anim_durations($anim, $e - $s);
        // Opacity < 100%: kanal alpha gambar dikalikan (transparansi asli PNG tetap terjaga)
        $op = (int) ($m['opacity'] ?? 100);
        $alpha = $op < 100 ? sprintf(',colorchannelmixer=aa=%.3f', max(0, $op) / 100) : '';
        if ($din <= 0 && $dout <= 0) {
            $parts[] = 'movie=' . OVERLAY_DIR . '/' . $m['file'] . ",scale=$w:-1,format=rgba{$alpha}[mimg$i]";
            $parts[] = sprintf("[$cur][mimg$i]overlay=x=%d-w/2:y=%d-h/2:enable='between(t,%.3f,%.3f)':format=auto[med$i]", $X, $Y, $s, $e);
        } else {
            // Animasi: gambar dijadikan stream (loop) supaya alpha (fade), skala, dan posisi bisa berubah per frame
            $p = sprintf('clip((t-%.3f)/%.3f,0,1)', $s, max(0.01, $din));
            $q = sprintf('clip((t-%.3f)/%.3f,0,1)', $e - $dout, max(0.01, $dout));
            [$dxi, $dyi, $sci] = $din > 0 ? anim_expr($anim['in'], 'in', $p) : ['0', '0', '1'];
            [$dxo, $dyo, $sco] = $dout > 0 ? anim_expr($anim['out'], 'out', $q) : ['0', '0', '1'];
            $scaled = $sci !== '1' || $sco !== '1';
            $chain = 'movie=' . OVERLAY_DIR . '/' . $m['file'] . ',scale=' . ($scaled ? (int) round($w * 1.12 / 2) * 2 : $w) . ':-1,format=rgba' . $alpha . ',loop=loop=-1:size=1,setpts=N/30/TB'
                . sprintf(',trim=end=%.3f', $dur + 0.5);
            if ($din > 0) {
                $pop = $anim['in'] === 'pop';
                $chain .= sprintf(',fade=t=in:st=%.3f:d=%.3f:alpha=1', $s, $pop ? $din / 3 : $din);
            }
            if ($dout > 0) {
                $pop = $anim['out'] === 'pop';
                $chain .= sprintf(',fade=t=out:st=%.3f:d=%.3f:alpha=1', $pop ? $e - 0.4 * $dout : $e - $dout, $pop ? 0.4 * $dout : $dout);
            }
            if ($scaled) {
                $chain .= ",scale=w='max(2,trunc($w*($sci)*($sco)/2)*2)':h=-2:eval=frame";
            }
            $parts[] = "{$chain}[mimg$i]";
            $parts[] = sprintf("[$cur][mimg$i]overlay=x='%d-w/2+(%s)+(%s)':y='%d-h/2+(%s)+(%s)':enable='between(t,%.3f,%.3f)':format=auto[med$i]",
                $X, $kx($dxi), $kx($dxo), $Y, $kx($dyi), $kx($dyo), $s, $e);
        }
        $cur = "med$i";
    }
    if (!$parts) {
        return '';
    }
    $parts[] = "[$cur]null[$out]";
    return implode(";\n", $parts);
}

/** Urutan lapisan di atas video dari editor (paling atas dulu): media/grafik, teks (+hook), subtitle. */
function edit_layer_order(array $edit): array
{
    $o = array_values(array_unique(array_filter((array) ($edit['layers'] ?? []), fn($x) => in_array($x, ['media', 'text', 'sub'], true))));
    foreach (['text', 'sub', 'media'] as $x) {
        if (!in_array($x, $o, true)) {
            $o[] = $x;
        }
    }
    return $o;
}

/**
 * Tumpuk lapisan (media, teks, subtitle) sesuai urutan editor di atas [$in] -> [$out] (yuv420p).
 * $ass = ['text' => file ASS teks & hook, 'sub' => file ASS subtitle]; $k = faktor skala layar.
 */
function layers_chain(array $edit, string $in, string $out, array $ass, float $k = 1.0): string
{
    $parts = [];
    $cur = $in;
    foreach (array_reverse(edit_layer_order($edit)) as $n => $layer) {
        if ($layer === 'media') {
            $g = media_graph($edit, $cur, "lm$n", $k);
            if ($g !== '') {
                $parts[] = $g;
                $cur = "lm$n";
            }
        } elseif (!empty($ass[$layer])) {
            $parts[] = "[$cur]ass=filename={$ass[$layer]}:fontsdir=" . SUB_FONTS_DIR . "[la$n]";
            $cur = "la$n";
        }
    }
    $parts[] = "[$cur]format=yuv420p[$out]";
    return implode(";\n", $parts);
}

/**
 * Rentang video yang DIPERTAHANKAN (relatif ke awal clip) setelah bagian hasil potong dihapus.
 * Mengembalikan [] kalau tidak ada bagian yang dihapus.
 */
function edit_keep_ranges(array $edit): array
{
    [$t0, $t1] = $edit['trim'];
    $gaps = array_filter($edit['gaps'] ?? [], fn($g) => $g[1] > $t0 && $g[0] < $t1);
    if (!$gaps) {
        return [];
    }
    usort($gaps, fn($x, $y) => $x[0] <=> $y[0]);
    $keep = [];
    $cur = $t0;
    foreach ($gaps as [$a, $b]) {
        if ($a - $cur >= 0.05) {
            $keep[] = [$cur - $t0, $a - $t0];
        }
        $cur = max($cur, $b);
    }
    if ($t1 - $cur >= 0.05) {
        $keep[] = [$cur - $t0, $t1 - $t0];
    }
    return $keep;
}

/** Teks tambahan di dalam rentang trim, waktunya relatif ke awal clip. */
function edit_texts_relative(array $edit): array
{
    if (!empty($edit['tracks']['text']['hide'])) {
        return [];
    }
    [$t0, $t1] = $edit['trim'];
    $out = [];
    foreach ($edit['texts'] ?? [] as $tx) {
        $s = max(0, $tx['s'] - $t0);
        $e = min($t1 - $t0, $tx['e'] - $t0);
        if ($e - $s >= 0.05) {
            $out[] = ['s' => $s, 'e' => $e] + $tx;
        }
    }
    return $out;
}

/** Baris subtitle di dalam rentang trim, waktunya digeser supaya relatif ke awal clip. */
function edit_lines_relative(array $edit): array
{
    [$t0, $t1] = $edit['trim'];
    $out = [];
    foreach ($edit['lines'] as $l) {
        $words = [];
        foreach ($l['words'] as $w) {
            if ($w['e'] <= $t0 || $w['s'] >= $t1) {
                continue;
            }
            $words[] = [max(0, $w['s'] - $t0), min($t1, $w['e']) - $t0, $w['w']];
        }
        if (!$words) {
            continue;
        }
        // Kata per tampilan: baris dipecah per N kata. Potongan tetap tampil sampai potongan berikutnya mulai
        // (kata terakhirnya diperpanjang), sama seperti preview editor.
        $n = (int) ($edit['style']['chunk'] ?? 0);
        $parts = $n > 0 ? array_chunk($words, $n) : [$words];
        foreach ($parts as $k => $part) {
            if (isset($parts[$k + 1])) {
                $part[count($part) - 1][1] = max($part[count($part) - 1][1], $parts[$k + 1][0][0]);
            }
            $out[] = $part;
        }
    }
    return $out;
}

/** Segmen kamera di dalam rentang trim, relatif ke awal clip (format speaker_graph). */
/** Waktu cut di video sumber (awal tiap shot), relatif ke awal clip; null kalau resep belum punya data shot. */
function edit_cut_times(array $edit): ?array
{
    if (empty($edit['shots'])) {
        return null;
    }
    $t0 = (float) $edit['trim'][0];
    return array_values(array_map(fn($s) => round($s['s'] - $t0, 3), $edit['shots']));
}

function edit_segments_relative(array $edit): array
{
    [$t0, $t1] = $edit['trim'];
    $out = [];
    foreach ($edit['segments'] as $s) {
        if ($s['e'] <= $t0 || $s['s'] >= $t1) {
            continue;
        }
        $out[] = ['start' => max(0, $s['s'] - $t0), 'end' => min($t1, $s['e']) - $t0, 'cx' => $s['cx'], 'cy' => $s['cy'], 'fh' => $s['fh']];
    }
    return $out;
}
