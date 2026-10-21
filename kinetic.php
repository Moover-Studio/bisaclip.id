<?php
/**
 * Mesin teks kinetik (gaya clip "Kinetik"): kalimat dipecah jadi beat (frasa pendek) yang tampil menumpuk kata demi kata.
 * Kata kunci besar, tebal, miring & berwarna; kata penghubung kecil serif.
 *
 * Layout dihitung di sini (server) memakai file font TTF yang sama dengan render, lalu dipakai bersama oleh
 * preview editor (lewat api.php?action=kinetic) dan render (ASS libass) supaya hasilnya identik.
 * Semua koordinat dalam layar 1080x1920.
 */
require_once __DIR__ . '/subtitles.php';

const KINETIC_KEY_FONT = 'archivo-black';
const KINETIC_FILL_FONT = 'libre-baskerville';
// Kata kunci pertama tiap beat putih, berikutnya bergiliran warna aksen
const KINETIC_ACCENTS = ['#E8338F', '#F5C518', '#4CC9B8', '#B7B2F7', '#F4B18A'];
const KINETIC_STOP = [
    // Indonesia
    'dan', 'yang', 'di', 'ke', 'dari', 'itu', 'ini', 'ada', 'aku', 'saya', 'kamu', 'kita', 'kami', 'dia', 'mereka', 'untuk', 'dengan', 'pada',
    'juga', 'karena', 'jadi', 'tapi', 'atau', 'kalau', 'kalo', 'akan', 'sudah', 'udah', 'lagi', 'bisa', 'aja', 'saja', 'itu', 'nya', 'pun',
    'gak', 'nggak', 'tidak', 'enggak', 'ya', 'kan', 'sih', 'deh', 'dong', 'lah', 'tuh', 'nih', 'buat', 'sama', 'masih', 'harus', 'mau',
    'oleh', 'dalam', 'seperti', 'kayak', 'gitu', 'begitu', 'apa', 'jika', 'agar', 'supaya', 'bahwa', 'para', 'si', 'sang', 'lebih', 'paling',
    // Inggris
    'the', 'a', 'an', 'and', 'or', 'but', 'to', 'of', 'in', 'on', 'at', 'for', 'with', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its',
    'i', "i'm", 'im', 'you', 'we', 'they', 'he', 'she', 'my', 'your', 'our', 'that', 'this', 'what', 'will', 'can', 'do', 'just', 'so', 'as',
    'from', 'by', 'if', 'then', 'than', 'about', 'have', 'has', 'had', 'me', 'us', 'them', 'there', 'all', 'still', 'maybe', 'could', 'would',
];

/** Metrik font TTF: [units per em, usWinAscent, usWinDescent] (dibaca langsung dari tabel head & OS/2). */
function kinetic_font_metrics(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $file = SUB_FONTS_DIR . "/$key.ttf";
    $d = (string) @file_get_contents($file);
    $n = strlen($d) > 12 ? unpack('n', substr($d, 4, 2))[1] : 0;
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $rec = unpack('a4tag/Ncs/Noff/Nlen', substr($d, 12 + 16 * $i, 16));
        $t[$rec['tag']] = $rec['off'];
    }
    $upem = isset($t['head']) ? unpack('n', substr($d, $t['head'] + 18, 2))[1] : 1000;
    [$wa, $wd] = isset($t['OS/2']) ? array_values(unpack('n2', substr($d, $t['OS/2'] + 74, 4))) : [800, 200];
    return $cache[$key] = [$upem, $wa, $wd];
}

/** Lebar teks (px) pada ukuran em tertentu. GD memakai 96 dpi: point = px * 0.75. */
function kinetic_text_width(string $key, float $em, string $text): float
{
    if ($text === '') {
        return 0;
    }
    $b = imagettfbbox($em * 0.75, 0, SUB_FONTS_DIR . "/$key.ttf", $text);
    return (float) ($b[2] - $b[0]);
}

/** Kata ditampilkan tanpa tanda baca di ujung (seperti subtitle), kata kunci huruf kecil semua. */
function kinetic_clean(string $w): string
{
    return trim(preg_replace('/[.,;:!?…"“”]+$/u', '', $w));
}

/**
 * Pecah kata subtitle (waktu absolut) di dalam trim jadi beat: maks. 9 kata, putus di akhir kalimat atau jeda > 0,5 dtk.
 * Peran kata: kunci (bukan kata umum, ≥ 3 huruf; 1–3 per beat) atau penghubung.
 */
function kinetic_beats(array $lines, float $t0, float $t1): array
{
    $words = [];
    foreach ($lines as $l) {
        foreach ($l['words'] ?? [] as $w) {
            if ($w['e'] > $t0 && $w['s'] < $t1 && trim((string) $w['w']) !== '') {
                $words[] = $w;
            }
        }
    }
    $beats = [];
    $cur = [];
    foreach ($words as $i => $w) {
        $cur[] = $w;
        $next = $words[$i + 1] ?? null;
        $endSentence = preg_match('/[.!?…]$/u', (string) $w['w']);
        $gap = $next ? $next['s'] - $w['e'] : 9;
        if (!$next || count($cur) >= 9 || ($endSentence && count($cur) >= 2) || $gap > 0.5) {
            $beats[] = $cur;
            $cur = [];
        }
    }
    $out = [];
    $accent = 0;
    foreach ($beats as $bi => $bw) {
        $items = [];
        foreach ($bw as $w) {
            $clean = kinetic_clean((string) $w['w']);
            $lower = mb_strtolower($clean);
            $items[] = ['w' => $clean, 's' => round((float) $w['s'], 3), 'e' => round((float) $w['e'], 3),
                'k' => mb_strlen($clean) >= 3 && !in_array($lower, KINETIC_STOP, true), 'c' => null];
        }
        // Maks. 3 kata kunci (yang terpanjang); minimal 1
        $keys = array_keys(array_filter($items, fn($x) => $x['k']));
        if (!$keys) {
            $lens = array_map(fn($x) => mb_strlen($x['w']), $items);
            $items[array_search(max($lens), $lens, true)]['k'] = true;
        } elseif (count($keys) > 3) {
            usort($keys, fn($a, $b) => mb_strlen($items[$b]['w']) <=> mb_strlen($items[$a]['w']));
            foreach (array_slice($keys, 3) as $k) {
                $items[$k]['k'] = false;
            }
        }
        $n = 0;
        foreach ($items as &$it) {
            if ($it['k']) {
                $it['c'] = $n++ === 0 ? '#FFFFFF' : KINETIC_ACCENTS[$accent++ % count(KINETIC_ACCENTS)];
            }
        }
        unset($it);
        $next = $beats[$bi + 1][0]['s'] ?? null;
        $end = (float) end($bw)['e'] + 0.6;
        $out[] = ['s' => $items[0]['s'], 'e' => round(min($next ?? $end, $end, $t1), 3), 'words' => $items];
    }
    return $out;
}

/** Bersihkan beat dari editor (input pengguna). */
function kinetic_sanitize($k, float $p0, float $p1): array
{
    $k = is_array($k) ? $k : [];
    $hex = fn($v) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? strtoupper((string) $v) : null;
    $beats = [];
    foreach (array_slice((array) ($k['beats'] ?? []), 0, 400) as $b) {
        $words = [];
        foreach (array_slice((array) ($b['words'] ?? []), 0, 12) as $w) {
            $text = trim(mb_substr((string) ($w['w'] ?? ''), 0, 40));
            if ($text === '') {
                continue;
            }
            $s = max($p0, min($p1, (float) ($w['s'] ?? $p0)));
            $words[] = ['w' => $text, 's' => round($s, 3), 'e' => round(max($s, min($p1, (float) ($w['e'] ?? $s))), 3),
                'k' => !empty($w['k']), 'c' => $hex($w['c'] ?? null)];
        }
        if (!$words) {
            continue;
        }
        $bs = round(max($p0, min($p1, (float) ($b['s'] ?? $words[0]['s']))), 3);
        $beats[] = ['s' => $bs, 'e' => round(max($bs + 0.1, min($p1, (float) ($b['e'] ?? end($words)['e']))), 3), 'words' => $words];
    }
    return ['beats' => $beats, 'y' => round(max(0.08, min(0.92, (float) ($k['y'] ?? 0.3))), 4), 'x' => round(max(0.15, min(0.85, (float) ($k['x'] ?? 0.5))), 4)];
}

/**
 * Layout satu beat: baris kata kunci (besar, mengisi lebar) dengan kata penghubung pendek di depan / belakangnya,
 * atau baris kata penghubung tersendiri (kecil). Mengembalikan kata dengan posisi garis dasar (x, base), ukuran em,
 * font, miring, warna, dan tepi bawah kotak baris ASS (bottom) untuk jangkar \an2.
 */
function kinetic_layout(array $beat, float $cy = 0.3, float $cx = 0.5): array
{
    $maxW = 820;
    $fillEm = 40;
    $keyFont = KINETIC_KEY_FONT;
    $fillFont = KINETIC_FILL_FONT;
    $words = array_values($beat['words']);
    // Susun baris
    $lines = [];
    $buf = [];
    $short = fn($arr) => count($arr) <= 2 && mb_strlen(implode(' ', array_map(fn($i) => $words[$i]['w'], $arr))) <= 12;
    foreach ($words as $i => $w) {
        if (!$w['k']) {
            $buf[] = $i;
            continue;
        }
        $pre = [];
        if ($buf && $short($buf)) {
            $pre = $buf;
        } elseif ($buf) {
            $lines[] = ['type' => 'fill', 'w' => $buf];
        }
        $lines[] = ['type' => 'key', 'pre' => $pre, 'key' => $i, 'suf' => []];
        $buf = [];
    }
    if ($buf) {
        $last = count($lines) - 1;
        if ($last >= 0 && $lines[$last]['type'] === 'key' && !$lines[$last]['suf'] && $short($buf)) {
            $lines[$last]['suf'] = $buf;
        } else {
            $lines[] = ['type' => 'fill', 'w' => $buf];
        }
    }
    $space = kinetic_text_width($fillFont, $fillEm, 'a a') - kinetic_text_width($fillFont, $fillEm, 'aa');
    $fillW = fn(array $idx) => kinetic_text_width($fillFont, $fillEm, implode(' ', array_map(fn($i) => $words[$i]['w'], $idx)));
    // Ukuran & tinggi tiap baris
    $rows = [];
    foreach ($lines as $ln) {
        if ($ln['type'] === 'fill') {
            $rows[] = $ln + ['em' => $fillEm, 'h' => $fillEm * 1.25, 'width' => $fillW($ln['w'])];
            continue;
        }
        $kw = $words[$ln['key']]['w'];
        $w100 = max(1, kinetic_text_width($keyFont, 100, $kw));
        $side = ($ln['pre'] ? $fillW($ln['pre']) + $space * 2 : 0) + ($ln['suf'] ? $fillW($ln['suf']) + $space * 2 : 0);
        $em = max(64, min(168, ($maxW - $side) / $w100 * 100));
        $rows[] = $ln + ['em' => $em, 'h' => $em * 0.86, 'width' => $w100 * $em / 100 + $side];
    }
    $total = array_sum(array_column($rows, 'h'));
    $top = $cy * 1920 - $total / 2;
    $out = [];
    $put = function (int $i, float $x, float $base, float $em, string $font, bool $italic) use (&$out, $words) {
        [$upem, , $wd] = kinetic_font_metrics($font);
        $out[$i] = ['i' => $i, 'w' => $words[$i]['w'], 'x' => round($x, 1), 'base' => round($base, 1), 'em' => round($em, 2),
            'font' => $font, 'italic' => $italic, 'c' => $words[$i]['k'] ? ($words[$i]['c'] ?: '#FFFFFF') : '#FFFFFF',
            'width' => round(kinetic_text_width($font, $em, $words[$i]['w']), 1), 'bottom' => round($base + $wd * $em / $upem, 1),
            's' => $words[$i]['s'], 'e' => $words[$i]['e']];
    };
    foreach ($rows as $r) {
        $x = $cx * 1080 - $r['width'] / 2;
        if ($r['type'] === 'fill') {
            $base = $top + $r['em'] * 0.98;
            foreach ($r['w'] as $i) {
                $put($i, $x, $base, $r['em'], $fillFont, false);
                $x += kinetic_text_width($fillFont, $r['em'], $words[$i]['w']) + $space;
            }
        } else {
            $base = $top + $r['em'] * 0.78;
            foreach ($r['pre'] as $i) {
                $put($i, $x, $base, $fillEm, $fillFont, false);
                $x += kinetic_text_width($fillFont, $fillEm, $words[$i]['w']) + $space;
            }
            if ($r['pre']) {
                $x += $space;
            }
            $put($r['key'], $x, $base, $r['em'], $keyFont, true);
            $x += kinetic_text_width($keyFont, $r['em'], $words[$r['key']]['w']) + $space * 2;
            foreach ($r['suf'] as $i) {
                $put($i, $x, $base, $fillEm, $fillFont, false);
                $x += kinetic_text_width($fillFont, $fillEm, $words[$i]['w']) + $space;
            }
        }
        $top += $r['h'];
    }
    ksort($out);
    return array_values($out);
}

/**
 * ASS untuk teks kinetik. Tiap kata = satu event (jangkar bawah-tengah) yang muncul saat diucapkan dengan efek pop,
 * bertahan sampai beat selesai. Bayangan lembut = salinan kata hitam ber-blur di lapisan bawah.
 * Waktu beat/kata relatif ke awal clip. $offset = waktu absolut awal clip.
 */
function build_kinetic_ass(array $beats, float $cy, float $offset, float $duration, float $cx = 0.5): string
{
    $fam = fn($k) => sub_font_family($k);
    $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\nWrapStyle: 2\nScaledBorderAndShadow: yes\n\n"
        . "[V4+ Styles]\n"
        . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
        . 'Style: K,' . $fam(KINETIC_KEY_FONT) . ",100,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,0,0,2,0,0,0,1\n"
        . 'Style: F,' . $fam(KINETIC_FILL_FONT) . ",100,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,0,0,2,0,0,0,1\n\n"
        . "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
    foreach ($beats as $b) {
        $bs = $b['s'] - $offset;
        $be = min($duration, $b['e'] - $offset);
        if ($be <= 0 || $bs >= $duration) {
            continue;
        }
        foreach (kinetic_layout($b, $cy, $cx) as $w) {
            [$upem, $wa, $wd] = kinetic_font_metrics($w['font']);
            $fs = round($w['em'] * ($wa + $wd) / $upem, 2);
            $st = max(0, max($bs, $w['s'] - $offset - 0.04));
            if ($st >= $be) {
                continue;
            }
            $cx = round($w['x'] + $w['width'] / 2, 1);
            $style = $w['font'] === KINETIC_KEY_FONT ? 'K' : 'F';
            $pop = '\fscx72\fscy72\t(0,130,\fscx100\fscy100)\fad(90,0)';
            $tags = fn($y) => "\\an2\\pos($cx,$y)\\fs$fs" . ($w['italic'] ? '\i1' : '');
            $text = ass_escape($w['w']);
            $t1 = ass_time($st);
            $t2 = ass_time($be);
            $ass .= "Dialogue: 0,$t1,$t2,$style,,0,0,0,,{" . $tags($w['bottom'] + 4) . '\1c&H000000&\1a&H90&\blur8' . $pop . "}$text\n";
            $ass .= "Dialogue: 1,$t1,$t2,$style,,0,0,0,,{" . $tags($w['bottom']) . '\1c' . ass_color($w['c']) . '&' . $pop . "}$text\n";
        }
    }
    return $ass;
}
