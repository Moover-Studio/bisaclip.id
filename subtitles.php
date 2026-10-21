<?php
// Subtitle animasi untuk clip: transkrip per kata (Whisper lokal) + file ASS gaya "Bold Kuning".
// Dipakai oleh clip.php. Fungsi run(), error_from() dan class Cancelled berasal dari worker.php.

const SUB_FONT = 'Arial Black';
const SUB_FONTS_DIR = __DIR__ . '/assets/fonts';

/**
 * Pilihan font subtitle & hook: key => [label, kategori]. File ada di assets/fonts/{key}.ttf (Google Fonts),
 * nama family-nya sudah diseragamkan jadi "Clip {label}" supaya libass & browser memakai font yang sama persis.
 * 'arial-black' = font sistem bawaan (gaya Bold Kuning awal).
 */
const SUB_FONT_LIST = [
    'arial-black' => ['Arial Black', 'sans'],
    'montserrat' => ['Montserrat', 'sans'], 'poppins' => ['Poppins', 'sans'], 'inter' => ['Inter', 'sans'],
    'rubik' => ['Rubik', 'sans'], 'nunito' => ['Nunito', 'sans'], 'archivo-black' => ['Archivo Black', 'sans'],
    'anton' => ['Anton', 'display'], 'bebas-neue' => ['Bebas Neue', 'display'], 'oswald' => ['Oswald', 'display'],
    'lilita-one' => ['Lilita One', 'display'], 'luckiest-guy' => ['Luckiest Guy', 'display'], 'bangers' => ['Bangers', 'display'],
    'playfair-display' => ['Playfair Display', 'serif'], 'merriweather' => ['Merriweather', 'serif'], 'lora' => ['Lora', 'serif'],
    'dm-serif-display' => ['DM Serif Display', 'serif'], 'abril-fatface' => ['Abril Fatface', 'serif'],
    'libre-baskerville' => ['Libre Baskerville', 'serif'], 'cormorant-garamond' => ['Cormorant Garamond', 'serif'],
    'bodoni-moda' => ['Bodoni Moda', 'serif'], 'eb-garamond' => ['EB Garamond', 'serif'], 'roboto-slab' => ['Roboto Slab', 'serif'],
];

function sub_font_key($key): string
{
    return is_string($key) && isset(SUB_FONT_LIST[$key]) ? $key : 'arial-black';
}

/** Nama family font untuk file ASS (dan CSS editor). */
function sub_font_family(string $key): string
{
    $key = sub_font_key($key);
    return $key === 'arial-black' ? SUB_FONT : 'Clip ' . SUB_FONT_LIST[$key][0];
}
/**
 * Ketebalan asli yang terpasang untuk satu font: file assets/fonts/<key>-w<bobot>.ttf (Google Fonts, OFL).
 * Tiap bobot punya family sendiri di dalam file ("ClipW Montserrat 600") supaya libass memilih persis bobot itu.
 */
function sub_font_weights(string $key): array
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $w = [];
        foreach (glob(SUB_FONTS_DIR . '/' . $key . '-w*.ttf') ?: [] as $f) {
            if (preg_match('/-w(\d{3})\.ttf$/', $f, $m)) {
                $w[] = (int) $m[1];
            }
        }
        sort($w);
        $cache[$key] = $w;
    }
    return $cache[$key];
}

/** Bobot pilihan user kalau terpasang untuk font itu, selain itu 0 (= font bawaan). */
function sub_font_weight($cfg): int
{
    $key = sub_font_key($cfg['font'] ?? null);
    $w = (int) ($cfg['weight'] ?? 0);
    return in_array($w, sub_font_weights($key), true) ? $w : 0;
}

/** [nama font ASS, nilai Bold ASS] untuk pengaturan teks: family per bobot, atau font bawaan (+ bold sintetis). */
function ass_font(array $cfg): array
{
    $w = sub_font_weight($cfg);
    if ($w) {
        return ['ClipW ' . SUB_FONT_LIST[sub_font_key($cfg['font'] ?? null)][0] . " $w", 0];
    }
    return [sub_font_family((string) ($cfg['font'] ?? '')), !empty($cfg['bold']) ? -1 : 0];
}
const SUB_HIGHLIGHT = '&H0000D4FF'; // kuning #FFD400 dalam format ASS (&HAABBGGRR)

/**
 * Transkrip audio clip per kata. Mengembalikan [[start, end, word], ...] dalam detik, relatif ke awal clip.
 */
function whisper_words(string $wav, string $lang, string $tmpDir, string $cancelFlag, string $prefix = ''): array
{
    $out = "$tmpDir/words";
    $cmd = $prefix . escapeshellarg(WHISPER_CLI) . ' -m ' . escapeshellarg(WHISPER_MODEL) . ' -f ' . escapeshellarg($wav)
        . ' -l ' . escapeshellarg($lang ?: 'auto') . ' -t 4 -ml 1 -sow -oj -np -of ' . escapeshellarg($out);
    // stdout whisper berisi transkrip lengkap; tidak perlu ditampung
    [$code, $tail] = run($cmd, fn() => null, $cancelFlag, 600);
    $data = json_decode((string) @file_get_contents("$out.json"), true);
    if ($code !== 0 || !is_array($data)) {
        throw new RuntimeException('Whisper gagal: ' . error_from($tail));
    }

    $words = [];
    foreach ($data['transcription'] ?? [] as $seg) {
        $text = trim((string) ($seg['text'] ?? ''));
        if ($text === '' || preg_match('/^\[.*\]$|^\(.*\)$/u', $text)) {
            continue; // kosong atau tanda seperti [Musik]
        }
        $words[] = [($seg['offsets']['from'] ?? 0) / 1000, ($seg['offsets']['to'] ?? 0) / 1000, $text];
    }
    // Rapikan kata berdurasi nol (sering muncul setelah jeda): mulai = akhir kata sebelumnya
    $n = count($words);
    for ($i = 0; $i < $n; $i++) {
        if ($words[$i][1] - $words[$i][0] < 0.05) {
            $next = $words[$i + 1][0] ?? $words[$i][0] + 0.25;
            $words[$i][1] = max($words[$i][0] + 0.12, min($next, $words[$i][0] + 0.4));
        }
    }
    return $words;
}

function ass_time(float $t): string
{
    $t = max(0, $t);
    $cs = (int) round($t * 100);
    return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs, 6000) % 60, intdiv($cs, 100) % 60, $cs % 100);
}

function ass_escape(string $s): string
{
    return str_replace(['\\', '{', '}', "\n"], ['', '(', ')', ' '], $s);
}

/** Kelompokkan kata menjadi potongan 1–3 kata yang tampil bersamaan. */
function subtitle_chunks(array $words): array
{
    $chunks = [];
    $cur = [];
    foreach ($words as $i => $w) {
        $cur[] = $w;
        $text = implode(' ', array_column($cur, 2));
        $next = $words[$i + 1] ?? null;
        $endsSentence = (bool) preg_match('/[.!?,;:…]["”]?$/u', $w[2]);
        $gap = $next ? $next[0] - $w[1] : 1;
        $nextLen = $next ? mb_strlen($text . ' ' . $next[2]) : 0;
        if (!$next || count($cur) >= 3 || $endsSentence || $gap > 0.45 || $nextLen > 18) {
            $chunks[] = $cur;
            $cur = [];
        }
    }
    return $chunks;
}

/** Bersihkan kata untuk ditampilkan: huruf kapital, tanpa koma/titik di ujung. */
function display_word(string $w, bool $upper = true): string
{
    $w = preg_replace('/[.,;:…]+["”]?$/u', '', $w);
    return $upper ? mb_strtoupper(ass_escape($w)) : ass_escape($w);
}

/** Warna #RRGGBB ke format ASS &H00BBGGRR. */
function ass_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    return '&H00' . strtoupper(substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2));
}

/** Warna ASS dengan transparansi: opacity 0-100 (100 = penuh) -> &HAABBGGRR. */
function ass_color_a(string $hex, float $opacity = 100): string
{
    $hex = ltrim($hex, '#');
    $alpha = (int) round(255 * (1 - max(0, min(100, $opacity)) / 100));
    return '&H' . sprintf('%02X', $alpha) . strtoupper(substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2));
}

/** Titik jangkar horizontal teks sesuai perataan (kiri = tepi kiri, tengah = titik tengah, kanan = tepi kanan). */
function text_anchor_x(array $cfg, int $w): int
{
    $default = ['left' => 70 / 1080, 'right' => 1010 / 1080][$cfg['align'] ?? 'center'] ?? 0.5;
    return (int) round($w * ($cfg['x'] ?? $default));
}

/**
 * Buat isi file ASS dari kata hasil Whisper (gaya bawaan). Dipakai clip tanpa resep edit.
 */
function build_ass(array $words, string $layout, string $hook, float $duration): string
{
    $style = ['size' => 98, 'color' => '#FFFFFF', 'highlight' => '#FFD400',
        'pos' => match ($layout) { 'blur' => 0.78, 'landscape' => 0.92, default => 0.71 }, 'uppercase' => true];
    $hookCfg = ['enabled' => trim($hook) !== '', 'text' => $hook, 'duration' => 3.5];
    return build_ass_lines(subtitle_chunks($words), $style, $layout, $hookCfg, $duration);
}

/**
 * Buat isi file ASS dari baris subtitle. $lines = [[[start, end, kata], ...], ...] relatif ke awal clip.
 * $style: size (px pada lebar 1080), color & highlight (#RRGGBB), pos (posisi bawah teks, 0..1 dari atas), uppercase.
 * $hook: enabled, text, duration.
 */
/** Animasi masuk / keluar untuk teks, hook, dan media. Jarak geser dalam px (layar 1080 lebar). */
const ANIM_KINDS = ['none', 'fade', 'up', 'down', 'left', 'right', 'zoom', 'pop'];
const ANIM_DIST = 140;
const HOOK_ANIM = ['in' => 'fade', 'out' => 'fade', 'din' => 0.15, 'dout' => 0.25];

function anim_sanitize($a, array $def = ['in' => 'none', 'out' => 'none', 'din' => 0.4, 'dout' => 0.4]): array
{
    $a = is_array($a) ? $a : [];
    $kind = fn($v, $d) => in_array($v, ANIM_KINDS, true) ? $v : $d;
    $dur = fn($v, $d) => round(max(0.1, min(2, is_numeric($v) ? (float) $v : $d)), 2);
    return ['in' => $kind($a['in'] ?? null, $def['in']), 'out' => $kind($a['out'] ?? null, $def['out']),
        'din' => $dur($a['din'] ?? null, $def['din']), 'dout' => $dur($a['dout'] ?? null, $def['dout'])];
}

/** Durasi efektif [masuk, keluar]; dipersingkat kalau melebihi 90% durasi item. Sama dengan animDur() di editor. */
function anim_durations(?array $a, float $len): array
{
    $din = ($a['in'] ?? 'none') !== 'none' ? (float) $a['din'] : 0;
    $dout = ($a['out'] ?? 'none') !== 'none' ? (float) $a['dout'] : 0;
    if ($din + $dout > $len * 0.9 && $din + $dout > 0) {
        $f = $len * 0.9 / ($din + $dout);
        [$din, $dout] = [$din * $f, $dout * $f];
    }
    return [$din, $dout];
}

/**
 * Keadaan animasi masuk ('in', p 0..1) / keluar ('out', p 0..1): [geser x, geser y, skala, alpha].
 * Rumus sama dengan animPhase() di editor dan anim_expr() untuk media.
 */
function anim_phase(string $kind, string $phase, float $p): array
{
    $p = max(0, min(1, $p));
    $D = ANIM_DIST;
    if ($phase === 'in') {
        $e = 1 - (1 - $p) ** 3;
        return match ($kind) {
            'fade' => [0, 0, 1, $p],
            'up' => [0, $D * (1 - $e), 1, $p],
            'down' => [0, -$D * (1 - $e), 1, $p],
            'left' => [$D * (1 - $e), 0, 1, $p],
            'right' => [-$D * (1 - $e), 0, 1, $p],
            'zoom' => [0, 0, 0.6 + 0.4 * $e, $p],
            'pop' => [0, 0, $p < 0.6 ? max(0.05, 1.12 * sin($p / 0.6 * M_PI / 2)) : 1.12 - 0.12 * sin(($p - 0.6) / 0.4 * M_PI / 2), min(1, $p * 3)],
            default => [0, 0, 1, 1],
        };
    }
    $i = $p ** 3;
    return match ($kind) {
        'fade' => [0, 0, 1, 1 - $p],
        'up' => [0, -$D * $i, 1, 1 - $p],
        'down' => [0, $D * $i, 1, 1 - $p],
        'left' => [-$D * $i, 0, 1, 1 - $p],
        'right' => [$D * $i, 0, 1, 1 - $p],
        'zoom' => [0, 0, 1 - 0.4 * $i, 1 - $p],
        'pop' => [0, 0, $p < 0.35 ? 1 + 0.1 * sin($p / 0.35 * M_PI / 2) : max(0.05, 1.1 * (1 - sin(($p - 0.35) / 0.65 * M_PI / 2))),
            $p < 0.6 ? 1 : 1 - ($p - 0.6) / 0.4],
        default => [0, 0, 1, 1],
    };
}

/**
 * Event ASS untuk satu teks beranimasi di titik jangkar (x, y). Bagian masuk & keluar dipecah jadi beberapa potongan pendek
 * (\move + \t skala + \fade) supaya kurva easing-nya sama dengan preview; bagian tengah diam di posisinya.
 */
function ass_anim_events(int $layer, string $style, float $s, float $e, int $x, int $y, int $an, string $body, ?array $anim): string
{
    [$din, $dout] = anim_durations($anim, $e - $s);
    $ev = fn($a, $b, $tags) => "Dialogue: $layer," . ass_time($a) . ',' . ass_time($b) . ",$style,,0,0,0,,{\\an$an$tags}$body\n";
    $out = '';
    $part = function (string $phase, float $t0, float $d) use ($anim, $x, $y, $ev): string {
        $kind = $anim[$phase] ?? 'none';
        $n = 6;
        $res = '';
        for ($k = 0; $k < $n; $k++) {
            [$dx0, $dy0, $s0, $a0] = anim_phase($kind, $phase, $k / $n);
            [$dx1, $dy1, $s1, $a1] = anim_phase($kind, $phase, ($k + 1) / $n);
            $a = round($t0 + $d * $k / $n, 2);
            $b = round($t0 + $d * ($k + 1) / $n, 2);
            $ms = (int) round(($b - $a) * 1000);
            if ($ms <= 0) {
                continue;
            }
            $tags = sprintf('\move(%d,%d,%d,%d,0,%d)', round($x + $dx0), round($y + $dy0), round($x + $dx1), round($y + $dy1), $ms);
            if (abs($s0 - 1) > 0.001 || abs($s1 - 1) > 0.001) {
                $tags .= sprintf('\fscx%.1f\fscy%.1f\t(0,%d,\fscx%.1f\fscy%.1f)', $s0 * 100, $s0 * 100, $ms, $s1 * 100, $s1 * 100);
            }
            $tags .= sprintf('\fade(%d,%d,%d,0,%d,%d,%d)', round((1 - $a0) * 255), round((1 - $a1) * 255), round((1 - $a1) * 255), $ms, $ms, $ms);
            $res .= $ev($a, $b, $tags);
        }
        return $res;
    };
    if ($din > 0) {
        $out .= $part('in', $s, $din);
    }
    $m0 = round($s + $din, 2);
    $m1 = round($e - $dout, 2);
    if ($m1 > $m0) {
        $out .= $ev($m0, $m1, "\\pos($x,$y)");
    }
    if ($dout > 0) {
        $out .= $part('out', $m1, $e - $m1);
    }
    return $out;
}

function build_ass_lines(array $lines, array $style, string $layout, array $hook, float $duration, array $texts = []): string
{
    $vertical = $layout !== 'landscape';
    [$w, $h] = $vertical ? [1080, 1920] : [1920, 1080];
    $scale = $vertical ? 1 : 0.72;
    $subSize = (int) round($style['size'] * $scale);
    $subMargin = (int) round($h * (1 - $style['pos']));
    $hookSize = $vertical ? (int) ($hook['size'] ?? 68) : 46;
    $hookMargin = $vertical ? ($layout === 'blur' ? 330 : 230) : 60;
    // Posisi bebas dari editor (titik tengah horizontal & tepi teks). Lebar bungkus teks tetap W-140,
    // sama seperti preview, jadi hanya titik jangkarnya yang digeser lewat \pos.
    // Perataan teks -> alignment ASS (subtitle dijangkar di bawah, hook di atas)
    $subAn = ['left' => 1, 'center' => 2, 'right' => 3][$style['align'] ?? 'center'] ?? 2;
    $hookAn = ['left' => 7, 'center' => 8, 'right' => 9][$hook['align'] ?? 'center'] ?? 8;
    $subPos = isset($style['x']) ? '{\\an' . $subAn . '\\pos(' . text_anchor_x($style, $w) . ',' . (int) round($h * $style['pos']) . ')}' : '';
    $hookPos = isset($hook['x']) || isset($hook['y']) || ($hook['align'] ?? 'center') !== 'center'
        ? '{\\an' . $hookAn . '\\pos(' . text_anchor_x($hook, $w) . ',' . (int) round(isset($hook['y']) ? $h * $hook['y'] : $hookMargin) . ')}' : '';
    $flags = fn($c) => ass_font($c)[1] . ',' . (!empty($c['italic']) ? -1 : 0) . ',' . (!empty($c['underline']) ? -1 : 0);
    $subFill = ass_color_a($style['color'], $style['opacity'] ?? 100);
    $subStroke = ass_color($style['stroke'] ?? '#000000');
    $subOutline = round((float) ($style['stroke_w'] ?? 7) * $scale, 1);
    $subSpacing = round((float) ($style['spacing'] ?? 1) * $scale, 1);
    $hookFill = ass_color_a($hook['color'] ?? '#111111', $hook['opacity'] ?? 100);
    $hookBox = ass_color_a($hook['bg'] ?? '#FFFFFF', $hook['bg_opacity'] ?? 100);
    $hookPad = (int) round(26 * $hookSize / 68);
    $hookSpacing = round((float) ($hook['spacing'] ?? 0), 1);
    // Teks tambahan: satu style per teks. Latar aktif = kotak (BorderStyle 3), selain itu garis tepi biasa
    $textStyles = '';
    $textEvents = '';
    foreach (array_values($texts) as $i => $tx) {
        $an = ['left' => 7, 'center' => 8, 'right' => 9][$tx['align'] ?? 'center'] ?? 8;
        $size = (int) round($tx['size'] * $scale);
        $boxed = ($tx['bg_opacity'] ?? 0) > 0;
        $fill = ass_color_a($tx['color'], $tx['opacity'] ?? 100);
        $edge = $boxed ? ass_color_a($tx['bg'], $tx['bg_opacity']) : ass_color($tx['stroke'] ?? '#000000');
        $outline = $boxed ? (int) round($size * 0.382) : round((float) ($tx['stroke_w'] ?? 0) * $scale, 1);
        $textStyles .= "Style: T$i," . ass_font($tx)[0] . ",$size,$fill,$fill,$edge,&H00000000," . $flags($tx)
            . ',0,100,100,' . round((float) ($tx['spacing'] ?? 0), 1) . ',0,' . ($boxed ? 3 : 1) . ",$outline,0,$an,70,70,0,1\n";
        $textEvents .= ass_anim_events(2, "T$i", (float) $tx['s'], (float) $tx['e'], text_anchor_x($tx, $w), (int) round($h * $tx['y']), $an,
            ass_escape($tx['text']), $tx['anim'] ?? null);
    }
    $color = ass_color($style['color']);
    $highlight = ass_color($style['highlight']);

    $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: $w\nPlayResY: $h\nWrapStyle: 0\nScaledBorderAndShadow: yes\n\n"
        . "[V4+ Styles]\n"
        . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        . 'Style: Sub,' . ass_font($style)[0] . ",$subSize,$subFill,$subFill,$subStroke,&H96000000," . $flags($style) . ",0,100,100,$subSpacing,0,1,$subOutline,4,$subAn,70,70,$subMargin,1\n"
        . 'Style: Hook,' . ass_font($hook)[0] . ",$hookSize,$hookFill,$hookFill,$hookBox,&H00000000," . $flags($hook) . ",0,100,100,$hookSpacing,0,3,$hookPad,0,$hookAn,70,70,$hookMargin,1\n" . $textStyles . "\n"
        . "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    $hookText = trim((string) ($hook['text'] ?? ''));
    if (!empty($hook['enabled']) && $hookText !== '') {
        $end = min((float) ($hook['duration'] ?? 3.5), max(0.5, $duration - 0.1));
        $hy = (int) round(isset($hook['y']) ? $h * $hook['y'] : $hookMargin);
        $ass .= ass_anim_events(1, 'Hook', 0, $end, text_anchor_x($hook, $w), $hy, $hookAn, ass_escape($hookText), $hook['anim'] ?? HOOK_ANIM);
    }
    $ass .= $textEvents;

    $upper = !empty($style['uppercase']);
    foreach ($lines as $ci => $chunk) {
        $chunkEnd = end($chunk)[1];
        $nextStart = $lines[$ci + 1][0][0] ?? $duration;
        // Kalau jedanya pendek, biarkan teks tetap tampil sampai baris berikutnya (tidak berkedip)
        if ($nextStart - $chunkEnd < 0.35) {
            $chunkEnd = $nextStart;
        }
        $chunkEnd = min($chunkEnd + 0.15, $nextStart, $duration);
        $texts = array_map(fn($x) => display_word($x[2], $upper), $chunk);

        foreach ($chunk as $wi => $word) {
            $start = $word[0];
            $end = isset($chunk[$wi + 1]) ? $chunk[$wi + 1][0] : $chunkEnd;
            if ($end - $start < 0.04) {
                continue;
            }
            $parts = [];
            foreach ($texts as $ti => $t) {
                $parts[] = $ti === $wi
                    ? '{\\c' . $highlight . '&\\fscx110\\fscy110}' . $t . '{\\c' . $color . '&\\fscx100\\fscy100}'
                    : $t;
            }
            // Efek pop saat baris baru muncul
            $intro = $wi === 0 ? '{\\fscx82\\fscy82\\t(0,110,\\fscx100\\fscy100)}' : '';
            $ass .= 'Dialogue: 0,' . ass_time($start) . ',' . ass_time($end) . ',Sub,,0,0,0,,' . $subPos . $intro . implode(' ', $parts) . "\n";
        }
    }
    return $ass;
}
