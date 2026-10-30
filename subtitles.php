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
    'instrument-serif' => ['Instrument Serif', 'serif'], 'fraunces' => ['Fraunces', 'serif'], 'cinzel' => ['Cinzel', 'serif'],
    'gloock' => ['Gloock', 'serif'], 'young-serif' => ['Young Serif', 'serif'], 'yeseva-one' => ['Yeseva One', 'serif'],
    'caveat' => ['Caveat', 'script'], 'dancing-script' => ['Dancing Script', 'script'], 'pacifico' => ['Pacifico', 'script'],
    'permanent-marker' => ['Permanent Marker', 'script'], 'kalam' => ['Kalam', 'script'], 'satisfy' => ['Satisfy', 'script'],
    'great-vibes' => ['Great Vibes', 'script'], 'shadows-into-light' => ['Shadows Into Light', 'script'], 'gochi-hand' => ['Gochi Hand', 'script'],
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
/** Efek teks per huruf / kata (teks tambahan): ketik, kata per kata, sorot per kata. Sama dengan TEXT_FX di editor. */
const TEXT_FX = ['none', 'type', 'word', 'karaoke'];

function anim_sanitize($a, array $def = ['in' => 'none', 'out' => 'none', 'din' => 0.4, 'dout' => 0.4]): array
{
    $a = is_array($a) ? $a : [];
    $kind = fn($v, $d) => in_array($v, ANIM_KINDS, true) ? $v : $d;
    $dur = fn($v, $d) => round(max(0.1, min(2, is_numeric($v) ? (float) $v : $d)), 2);
    return ['in' => $kind($a['in'] ?? null, $def['in']), 'out' => $kind($a['out'] ?? null, $def['out']),
        'din' => $dur($a['din'] ?? null, $def['din']), 'dout' => $dur($a['dout'] ?? null, $def['dout']),
        'text' => in_array($a['text'] ?? '', TEXT_FX, true) ? $a['text'] : 'none',
        'tdur' => round(max(0.2, min(5, is_numeric($a['tdur'] ?? null) ? (float) $a['tdur'] : 1)), 2),
        'hl' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($a['hl'] ?? '')) ? strtoupper($a['hl']) : '#FFD400',
        'blur' => !empty($a['blur']), 'smooth' => !empty($a['smooth'])];
}

/**
 * Isi teks berefek (ketik / kata per kata / sorot per kata) untuk event ASS yang mulai di detik $a.
 * Hanya alpha & warna yang berubah (ukuran huruf tetap) supaya susunan baris tidak bergeser. Rumus sama dengan textFxState() di editor.
 * $fa / $oa = alpha asli isi / garis tepi (hex 2 digit); $boxed = latar kotak tetap tampil penuh, hanya hurufnya yang muncul.
 */
function text_fx_body(array $tx, float $a, string $fa, string $oa, bool $boxed): string
{
    // Blur masuk: teks muncul sedikit blur lalu menajam (waktu relatif ke awal event)
    // Smoother: durasi x1,8 + easing melambat di akhir (sama dengan ass_t)
    $sm = !empty($tx['anim']['smooth']);
    $bd = (int) round(SUB_BLUR_MS * ($sm ? SMOOTH_K : 1));
    $acc = $sm ? SMOOTH_ACC . ',' : '';
    $b0 = (int) round(((float) $tx['s'] - $a) * 1000);
    $blur = !empty($tx['anim']['blur']) && $b0 + $bd > 0
        ? '{' . ($b0 > 0 ? '\blur' . SUB_BLUR . "\\t($b0," . ($b0 + $bd) . ",$acc\\blur0)" : sprintf('\blur%.1f\t(0,%d,%s\blur0)', SUB_BLUR * ($b0 + $bd) / $bd, $b0 + $bd, $acc)) . '}' : '';
    return $blur . text_fx_units($tx, $a, $fa, $oa, $boxed);
}

function text_fx_units(array $tx, float $a, string $fa, string $oa, bool $boxed): string
{
    $fx = $tx['anim']['text'] ?? 'none';
    $text = (string) $tx['text'];
    if ($fx === 'none' || trim($text) === '') {
        return ass_escape($text);
    }
    $s = (float) $tx['s'];
    $units = $fx === 'type' ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $isWord = fn($u) => trim($u) !== '';
    $n = max(1, count(array_filter($units, $isWord)));
    $ms = fn($t) => (int) round(($t - $a) * 1000);
    $out = '';
    $k = 0;
    if ($fx === 'karaoke') {
        // Kata disorot bergantian merata sepanjang durasi teks
        $step = max(0.05, ((float) $tx['e'] - $s) / $n);
        $base = substr(ass_color($tx['color']), 4);
        $hl = substr(ass_color($tx['anim']['hl'] ?? '#FFD400'), 4);
        foreach ($units as $u) {
            if (!$isWord($u)) {
                $out .= ass_escape($u);
                continue;
            }
            [$t0, $t1] = [$s + $k * $step, $s + ($k + 1) * $step];
            // Ganti warna seketika di detik t (rentang 1 ms)
            $to = fn($t, $c) => sprintf('\t(%d,%d,\1c&H%s&)', $ms($t), $ms($t) + 1, $c);
            $tag = $t1 <= $a ? "\\1c&H$base&" : ($t0 <= $a ? "\\1c&H$hl&" . $to($t1, $base)
                : "\\1c&H$base&" . $to($t0, $hl) . $to($t1, $base));
            $out .= '{' . $tag . '}' . ass_escape($u);
            $k++;
        }
        return $out;
    }
    // Ketik: huruf muncul seketika satu per satu · kata per kata: kata memudar masuk 0,12 dtk
    $step = (float) ($tx['anim']['tdur'] ?? 1) / $n;
    $sm = !empty($tx['anim']['smooth']);
    $fade = $fx === 'word' ? 0.12 * ($sm ? SMOOTH_K : 1) : 0;
    $acc = $sm && $fade ? SMOOTH_ACC . ',' : '';
    $hide = '\1a&HFF&' . ($boxed ? '' : '\3a&HFF&');
    $show = "\\1a&H$fa&" . ($boxed ? '' : "\\3a&H$oa&");
    foreach ($units as $u) {
        if (!$isWord($u)) {
            $out .= ass_escape($u);
            continue;
        }
        $r = $s + $k * $step;
        $tag = $r + $fade <= $a ? $show : $hide . '\t(' . max(0, $ms($r)) . ',' . max(1, $ms($r) + 1, $ms($r + $fade)) . ",$acc$show)";
        $out .= '{' . $tag . '}' . ass_escape($u);
        $k++;
    }
    return $out;
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
function ass_anim_events(int $layer, string $style, float $s, float $e, int $x, int $y, int $an, string|callable $body, ?array $anim): string
{
    [$din, $dout] = anim_durations($anim, $e - $s);
    // $body boleh fungsi (detik mulai event) => isi, untuk efek teks yang waktunya relatif ke awal tiap event
    $B = is_callable($body) ? $body : fn() => $body;
    $ev = fn($a, $b, $tags) => "Dialogue: $layer," . ass_time($a) . ',' . ass_time($b) . ",$style,,0,0,0,,{\\an$an$tags}" . $B($a) . "\n";
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

/** Animasi masuk per kata subtitle (saat kata mulai diucapkan) & blur masuk. Sama dengan SUB_WANIMS / SUB_BLUR di editor. */
const SUB_WANIMS = ['fade', 'pop', 'glow'];
const SUB_BLUR = 6;      // px blur awal saat teks muncul
const SUB_BLUR_MS = 220; // lama blur menajam
/** Smoother: semua animasi teks 1,8x lebih lama dengan easing melambat di akhir (akselerasi \t 0,5). Sama dengan editor. */
const SMOOTH_K = 1.8;
const SMOOTH_ACC = 0.5;

/** Tag \t(t1,t2,[accel,]tags) yang ikut Smoother (waktu dalam ms dari awal event). */
function ass_t(int $a, int $b, string $tags, bool $smooth): string
{
    return $smooth ? sprintf('\t(%d,%d,%s,%s)', $a * SMOOTH_K, $b * SMOOTH_K, SMOOTH_ACC, $tags) : "\\t($a,$b,$tags)";
}

/**
 * Pengaturan highlight & efek subtitle dari style. Resep lama: efek "karaoke" (sorot per kata) = highlight aktif tanpa efek,
 * efek "none" = tanpa highlight; ketik / kata per kata lama tetap dengan highlight.
 */
function sub_hl_cfg(array $st): array
{
    $fx = (string) ($st['fx'] ?? 'karaoke');
    return [
        'hl' => array_key_exists('hl', $st) ? !empty($st['hl']) : $fx !== 'none',
        'fx' => in_array($fx, ['type', 'word'], true) ? $fx : 'none',
        'hl_style' => ($st['hl_style'] ?? '') === 'box' ? 'box' : 'color',
        'hl_font' => isset(SUB_FONT_LIST[$st['hl_font'] ?? '']) ? $st['hl_font'] : '',
        'hl_tc' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($st['hl_tc'] ?? '')) ? $st['hl_tc'] : '#111111',
        'hl_pop' => !array_key_exists('hl_pop', $st) || !empty($st['hl_pop']),
        'wanim' => in_array($st['wanim'] ?? '', SUB_WANIMS, true) ? $st['wanim'] : 'fade',
        'blur' => !empty($st['blur']),
        'smooth' => !empty($st['smooth']),
    ];
}

/**
 * Kata aktif subtitle (waktu relatif ke awal event kata ini = saat kata mulai diucapkan), tanpa tag highlight.
 * Ketik: huruf muncul merata selama kata diucapkan · kata per kata: animasi masuk fade / pop / glow. Sama dengan preview editor.
 * $S = skala kata aktif (110 kalau highlight membesar); $edge = garis tepi akhir kata aktif (tujuan animasi glow).
 * Hanya alpha, tinggi huruf, garis tepi & blur yang dianimasikan, jadi posisi kata dalam baris tidak bergeser.
 */
function sub_fx_word(string $t, array $H, float $dur, string $hidden, string $shown, int $S, string $edge): string
{
    $blur = $H['blur'] ? '\blur' . SUB_BLUR . ass_t(0, SUB_BLUR_MS, '\blur0', $H['smooth']) : '';
    if ($H['fx'] === 'word') {
        return '{' . $hidden . $blur . sub_word_in($H, $shown, $S, $edge) . '}' . $t . '{' . $shown . '}';
    }
    if ($H['fx'] !== 'type') {
        return $t;
    }
    $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    $out = '';
    foreach ($chars as $j => $ch) {
        $ms = (int) round($j / $n * max(0, $dur) * 1000);
        $out .= ($j === 0 ? '{' . $shown . $blur . '}' : '{' . $hidden . '\t(' . $ms . ',' . ($ms + 1) . ',' . $shown . ')}') . $ch;
    }
    return $out . '{' . $shown . '}';
}

/** Animasi masuk satu kata (fade / pop / glow) menuju keadaan tampil $shown. Dipakai juga untuk kotak highlight ($edge kosong = tanpa pendar). */
function sub_word_in(array $H, string $shown, int $S, string $edge): string
{
    $sm = $H['smooth'];
    return match ($H['wanim']) {
        'pop' => sprintf('\fscy%d', $S * 0.4) . ass_t(0, 90, $shown . sprintf('\fscy%d', $S * 1.15), $sm) . ass_t(90, 170, "\\fscy$S", $sm),
        // Glow: muncul dengan pendar putih tebal lalu kembali ke garis tepinya
        'glow' => $edge === '' ? ass_t(0, 90, $shown, $sm)
            : '\3c&HFFFFFF&\bord18\be1' . ass_t(0, 90, $shown, $sm) . ass_t(0, 260, $edge . '\be0', $sm),
        default => ass_t(0, 160, $shown, $sm),
    };
}

/** Padding kotak highlight [samping, atas-bawah] dalam px, sebanding ukuran huruf. Sama dengan SUB_BOX_PAD di editor. */
function sub_box_pad(int $size): array
{
    return [(int) round($size * 0.16), (int) round($size * 0.02)];
}

/** Kotak kata aktif muncul bersama katanya: langsung, atau ikut animasi kata per kata (pop ikut membesar) + blur. */
function sub_box_in(array $H, int $S): string
{
    $on = '\3a&H00&';
    $blur = $H['blur'] && $H['fx'] !== 'none' ? '\blur' . SUB_BLUR . ass_t(0, SUB_BLUR_MS, '\blur0', $H['smooth']) : '';
    return $H['fx'] === 'word' ? '\3a&HFF&' . $blur . sub_word_in($H, $on, $S, '') : $on . $blur;
}

/** Ukuran kotak: padding samping lebih lebar dari atas-bawah supaya tidak menabrak baris lain. */
function sub_box_tags(int $size): string
{
    [$x, $y] = sub_box_pad($size);
    return "\\xbord$x\\ybord$y";
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
        $fa = substr($fill, 2, 2);
        $oa = $boxed ? substr($edge, 2, 2) : '00';
        $textEvents .= ass_anim_events(2, "T$i", (float) $tx['s'], (float) $tx['e'], text_anchor_x($tx, $w), (int) round($h * $tx['y']), $an,
            fn($a) => text_fx_body($tx, (float) $a, $fa, $oa, $boxed), $tx['anim'] ?? null);
    }
    $color = ass_color($style['color']);
    $highlight = ass_color($style['highlight']);

    $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: $w\nPlayResY: $h\nWrapStyle: 0\nScaledBorderAndShadow: yes\n\n"
        . "[V4+ Styles]\n"
        . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        . 'Style: Sub,' . ass_font($style)[0] . ",$subSize,$subFill,$subFill,$subStroke,&H96000000," . $flags($style) . ",0,100,100,$subSpacing,0,1,$subOutline,4,$subAn,70,70,$subMargin,1\n"
        // Kotak highlight: susunan identik dengan Sub, BorderStyle 3 = kotak per huruf berwarna highlight (huruf & kotak kata lain transparan)
        . 'Style: SubBox,' . ass_font($style)[0] . ",$subSize,&HFF000000,&HFF000000," . ass_color($style['highlight']) . ',&HFF000000,' . $flags($style)
            . ",0,100,100,$subSpacing,0,3," . sub_box_pad($subSize)[0] . ",0,$subAn,70,70,$subMargin,1\n"
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
    // Efek teks subtitle: tag sembunyi / tampil (isi, garis tepi, bayangan) untuk ketik & kata per kata
    $H = sub_hl_cfg($style);
    $hidden = $H['fx'] !== 'none' ? '\\1a&HFF&\\3a&HFF&\\4a&HFF&' : '';
    $shown = '\\1a&H' . substr($subFill, 2, 2) . '&\\3a&H00&\\4a&H96&';
    // Highlight kata yang sedang diucapkan: warna teks, atau kotak latar (lapisan SubBox di belakang teks) + font & skala sendiri
    $S = $H['hl'] && $H['hl_pop'] ? 110 : 100;
    $box = $H['hl'] && $H['hl_style'] === 'box';
    [$subFn, $subB] = ass_font($style);
    $hlFont = $H['hl_font'] !== '' ? ass_font(['font' => $H['hl_font'], 'bold' => !empty($style['bold'])]) : null;
    $hlLayout = ($hlFont ? '\\fn' . $hlFont[0] . '\\b' . $hlFont[1] : '') . "\\fscx$S\\fscy$S"; // tag yang memengaruhi susunan baris
    $hlReset = ($hlFont ? '\\fn' . $subFn . '\\b' . $subB : '') . '\\fscx100\\fscy100';
    // Kotak: teks kata aktif tanpa garis tepi & bayangan, kotaknya digambar lapisan SubBox
    $hlOn = ($box ? '\\c' . ass_color($H['hl_tc']) . '&\\bord0\\shad0' : '\\c' . $highlight . '&') . $hlLayout;
    $hlOff = '\\c' . $color . '&' . ($box ? '\\bord' . $subOutline . '\\shad4' : '') . $hlReset;
    $sm = $H['smooth'];
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
            $bparts = []; // lapisan kotak: teks sama persis (susunan identik), hanya kotak kata aktif yang terlihat
            foreach ($texts as $ti => $t) {
                $bparts[] = $ti === $wi && $box ? '{' . $hlLayout . sub_box_tags($subSize) . sub_box_in($H, $S) . '}' . $t . '{\\3a&HFF&' . $hlReset . '}' : $t;
                if ($ti > $wi && $hidden !== '') {
                    // Ketik / kata per kata: kata yang belum diucapkan disembunyikan (tetap memakan tempat, baris tidak bergeser)
                    $parts[] = ($ti === $wi + 1 ? '{' . $hidden . '}' : '') . $t;
                } elseif ($ti === $wi && ($H['hl'] || $H['fx'] !== 'none')) {
                    $fxS = $H['hl'] ? $S : 100;
                    $edge = $box ? '\\bord0' : '\\3c' . $subStroke . '&\\bord' . $subOutline;
                    $parts[] = ($H['hl'] ? '{' . $hlOn . '}' : '') . sub_fx_word($t, $H, $word[1] - $word[0], $hidden, $shown, $fxS, $edge)
                        . ($H['hl'] ? '{' . $hlOff . '}' : '');
                } else {
                    $parts[] = $t;
                }
            }
            // Efek pop saat baris baru muncul (+ blur menajam kalau aktif)
            $intro = $wi === 0 ? '{\\fscx82\\fscy82' . ass_t(0, 110, '\\fscx100\\fscy100', $sm)
                . ($H['blur'] ? '\\blur' . SUB_BLUR . ass_t(0, SUB_BLUR_MS, '\\blur0', $sm) : '') . '}' : '';
            if ($box) {
                // Kotak di lapisan 0, teks di lapisan 1 (lapisan berbeda tidak saling menggeser)
                $ass .= 'Dialogue: 0,' . ass_time($start) . ',' . ass_time($end) . ',SubBox,,0,0,0,,' . $subPos . $intro
                    . '{\\1a&HFF&\\3a&HFF&\\4a&HFF&}' . implode(' ', $bparts) . "\n";
            }
            $ass .= 'Dialogue: ' . ($box ? 1 : 0) . ',' . ass_time($start) . ',' . ass_time($end) . ',Sub,,0,0,0,,' . $subPos . $intro . implode(' ', $parts) . "\n";
        }
    }
    return $ass;
}
