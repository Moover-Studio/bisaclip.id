<?php
// Hari libur nasional & cuti bersama Indonesia untuk kalender upload.
// Sumber: kalender publik Google "Hari Libur di Indonesia" (mengikuti SKB 3 Menteri), disimpan di cache & diperbarui tiap 7 hari.
// Tanpa internet: pakai cache terakhir, atau data bawaan assets/holidays-id.json. Tanggal bisa ditandai manual (libur / hari kerja).

const HOLIDAY_ICS = 'https://calendar.google.com/calendar/ical/id.indonesian%23holiday%40group.v.calendar.google.com/public/basic.ics';
const HOLIDAY_CACHE = STORAGE_DIR . '/holidays.json';
const HOLIDAY_SEED = APP_DIR . '/assets/holidays-id.json';
const DAY_OVERRIDES = STORAGE_DIR . '/day_overrides.json';

/**
 * Ambil hari libur dari isi file ICS: hanya "Hari libur nasional" (termasuk cuti bersama), bukan sekadar perayaan.
 * Mengembalikan ['YYYY-MM-DD' => ['name' => ..., 'cuti' => bool]].
 */
function parse_holiday_ics(string $ics): array
{
    $ics = str_replace(["\r\n ", "\r\n\t", "\n ", "\n\t"], '', $ics);
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m);
    $days = [];
    foreach ($m[1] as $ev) {
        if (!preg_match('/DTSTART;VALUE=DATE:(\d{8})/', $ev, $s) || !preg_match('/^SUMMARY:(.*)$/m', $ev, $sum)) {
            continue;
        }
        preg_match('/^DESCRIPTION:(.*)$/m', $ev, $desc);
        if (!str_starts_with(trim($desc[1] ?? ''), 'Hari libur nasional')) {
            continue; // "Perayaan" (mis. 1 Ramadan, Malam Tahun Baru) bukan hari libur
        }
        $name = trim(str_replace(['\\,', '\\;'], [',', ';'], $sum[1]));
        $start = DateTimeImmutable::createFromFormat('!Ymd', $s[1]);
        $end = preg_match('/DTEND;VALUE=DATE:(\d{8})/', $ev, $e) ? DateTimeImmutable::createFromFormat('!Ymd', $e[1]) : $start->modify('+1 day');
        // Acara beberapa hari: DTEND eksklusif
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $cuti = stripos($name, 'cuti bersama') !== false;
            // Kalau satu tanggal punya libur nasional & cuti bersama, libur nasional yang ditampilkan
            if (!isset($days[$key]) || ($days[$key]['cuti'] && !$cuti)) {
                $days[$key] = ['name' => $name, 'cuti' => $cuti];
            }
        }
    }
    ksort($days);
    return $days;
}

/** Semua hari libur yang diketahui; memperbarui cache dari internet kalau sudah lebih dari 7 hari. */
function id_holidays(bool $refresh = false): array
{
    $cache = json_decode((string) @file_get_contents(HOLIDAY_CACHE), true);
    $fresh = is_array($cache) && time() - ($cache['fetched_at'] ?? 0) < 7 * 86400;
    if (!$fresh || $refresh) {
        // Gagal mengambil tidak dicoba ulang terus-menerus: tunggu 6 jam sebelum mencoba lagi
        $retryOk = !is_array($cache) || time() - ($cache['tried_at'] ?? 0) > 6 * 3600 || $refresh;
        if ($retryOk) {
            $ch = curl_init(HOLIDAY_ICS);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true]);
            $ics = curl_exec($ch);
            $ok = is_string($ics) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            $days = $ok ? parse_holiday_ics($ics) : [];
            if ($days) {
                $cache = ['fetched_at' => time(), 'tried_at' => time(), 'source' => 'google', 'days' => $days];
            } else {
                $cache = (is_array($cache) ? $cache : []) + ['days' => []];
                $cache['tried_at'] = time();
            }
            file_put_contents(HOLIDAY_CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
            @chmod(HOLIDAY_CACHE, 0666);
        }
    }
    $days = $cache['days'] ?? [];
    // Data bawaan hanya mengisi tahun yang tidak ada di cache (mis. belum pernah online)
    $seed = json_decode((string) @file_get_contents(HOLIDAY_SEED), true);
    $years = array_flip(array_map(fn($k) => substr($k, 0, 4), array_keys($days)));
    foreach (is_array($seed) ? $seed : [] as $k => $v) {
        if (!isset($years[substr($k, 0, 4)])) {
            $days[$k] = $v;
        }
    }
    ksort($days);
    return $days;
}

/** Info sumber data libur untuk ditampilkan di kalender. */
function holiday_source(): array
{
    $cache = json_decode((string) @file_get_contents(HOLIDAY_CACHE), true);
    return ['fetched_at' => $cache['fetched_at'] ?? null, 'online' => !empty($cache['fetched_at'])];
}

/** Tanggal yang ditandai manual: 'libur' (mis. cuti pribadi / libur daerah) atau 'kerja'. */
function day_overrides(): array
{
    $o = json_decode((string) @file_get_contents(DAY_OVERRIDES), true);
    return is_array($o) ? $o : [];
}

function set_day_override(string $date, string $mode): array
{
    $o = day_overrides();
    if ($mode === '') {
        unset($o[$date]);
    } else {
        $o[$date] = $mode;
    }
    ksort($o);
    file_put_contents(DAY_OVERRIDES, json_encode($o), LOCK_EX);
    @chmod(DAY_OVERRIDES, 0666);
    return $o;
}

/** Bagian kalender untuk rentang tanggal [from, to] (YYYY-MM-DD). */
function holidays_between(string $from, string $to): array
{
    return array_filter(id_holidays(), fn($k) => $k >= $from && $k <= $to, ARRAY_FILTER_USE_KEY);
}
