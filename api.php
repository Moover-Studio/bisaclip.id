<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/editor_lib.php';
require_once __DIR__ . '/source.php';
require_once __DIR__ . '/holidays.php';

require_local();
ensure_dirs();
setup_env();
header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : $_POST;
}

function require_video_url(string $url): string
{
    [$clean, $error] = normalize_video_url($url);
    if ($error) {
        respond(['error' => $error], 400);
    }
    return $clean;
}

function spawn_worker(string $id): void
{
    // Tanpa nohup: di macOS nohup gagal saat dijalankan dari Apache (tidak ada terminal)
    $log = STORAGE_DIR . '/worker.log';
    exec(escapeshellarg(PHP_CLI) . ' ' . escapeshellarg(APP_DIR . '/worker.php') . ' ' . escapeshellarg($id)
        . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 &');
}

const CLIP_LAYOUT_LABELS = [
    'speaker' => '9:16 ikuti pembicara', 'split' => '9:16 split screen', 'landscape' => '16:9', 'crop_center' => '9:16 crop tengah',
    'crop_left' => '9:16 crop kiri', 'crop_right' => '9:16 crop kanan', 'blur' => '9:16 blur', 'gaming' => '9:16 streaming game',
];
define('PROJECTS_DIR', STORAGE_DIR . '/projects');
define('ACCOUNTS_FILE', STORAGE_DIR . '/accounts.json');
define('AVATAR_DIR', APP_DIR . '/avatars');      // foto profil akun (dilayani langsung oleh Apache)
define('THUMB_DIR', APP_DIR . '/previews/thumbs'); // thumbnail feed dari file clip hasil render
// Warna avatar akun (dipilih saat membuat akun)
const ACCOUNT_COLORS = ['#2F7BF0', '#1F9D5C', '#E2558E', '#F2994A', '#8B5CF6', '#14B8A6', '#E5484D', '#D4A017'];

/* ---------- Akun sosmed (lokal): tiap room dihubungkan ke satu akun, mis. akun self development & akun clip game ---------- */

function read_accounts(): array
{
    $list = json_decode((string) @file_get_contents(ACCOUNTS_FILE), true);
    return is_array($list) ? $list : [];
}

function write_accounts(array $list): void
{
    file_put_contents(ACCOUNTS_FILE, json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(ACCOUNTS_FILE, 0666);
}

/* ---------- Preset campaign brand (modal "Atur clip"): brand yang sering dipakai, disimpan per nama ---------- */
define('BRANDS_FILE', STORAGE_DIR . '/brands.json');

function read_brands(): array
{
    $list = json_decode((string) @file_get_contents(BRANDS_FILE), true);
    return is_array($list) ? $list : [];
}

function write_brands(array $list): void
{
    file_put_contents(BRANDS_FILE, json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(BRANDS_FILE, 0666);
}

function find_account(string $id): ?array
{
    foreach (read_accounts() as $a) {
        if ($a['id'] === $id) {
            return $a;
        }
    }
    return null;
}

/**
 * Semua room (analisa) beserta proyek & akunnya: [id analisa => ['job' => job analisa, 'project' => proyek|null]].
 * Dipakai halaman akun & kalender untuk mengelompokkan clip per akun.
 */
function rooms_index(): array
{
    $rooms = [];
    foreach (glob(JOBS_DIR . '/*.json') as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (($j['kind'] ?? '') === 'analyze') {
            $rooms[$j['id']] = ['job' => $j, 'project' => null];
        }
    }
    foreach (glob(PROJECTS_DIR . '/*.json') as $f) {
        $p = json_decode((string) @file_get_contents($f), true);
        $aid = $p['analysis_id'] ?? '';
        if (isset($rooms[$aid]) && (!$rooms[$aid]['project'] || ($p['created_at'] ?? 0) > ($rooms[$aid]['project']['created_at'] ?? 0))) {
            $rooms[$aid]['project'] = $p;
        }
    }
    return $rooms;
}

/** Data satu clip yang sudah diupload (untuk grid profil akun & kalender). */
function uploaded_clip(array $project, array $it, string $accountId): array
{
    $job = read_job($it['job_id']);
    return [
        'date' => $it['uploaded'], 'uploaded_at' => $it['uploaded_at'] ?? 0, 'account_id' => $accountId,
        'pid' => $project['id'], 'iid' => $it['iid'], 'analysis_id' => $project['analysis_id'],
        'title' => $it['title'], 'caption' => $it['caption'] ?? '', 'hashtags' => $it['hashtags'] ?? [], 'en' => $it['en'] ?? null,
        'room' => $project['video']['title'] ?? '', 'start' => $it['start'], 'end' => $it['end'], 'layout' => $it['layout'],
        'file' => ($job['status'] ?? '') === 'done' ? ($job['file'] ?? '') : '',
        'job' => $job['id'] ?? '', 'size' => ($job['status'] ?? '') === 'done' ? ($job['size'] ?? 0) : 0,
        'duration' => $job['result']['duration'] ?? (float) $it['end'] - (float) $it['start'],
    ];
}

function read_analysis(string $id): ?array
{
    $data = json_decode((string) @file_get_contents(STORAGE_DIR . "/analyses/$id.json"), true);
    return is_array($data) ? $data : null;
}

function read_project(string $id): ?array
{
    $data = json_decode((string) @file_get_contents(PROJECTS_DIR . "/$id.json"), true);
    return is_array($data) ? $data : null;
}

function write_project(array $project): void
{
    if (!is_dir(PROJECTS_DIR)) {
        @mkdir(PROJECTS_DIR, 0777, true);
    }
    $path = PROJECTS_DIR . '/' . $project['id'] . '.json';
    file_put_contents("$path.tmp", json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod("$path.tmp", 0666);
    rename("$path.tmp", $path);
}

/** Bahasa video (untuk Whisper) dari hasil analisa. */
function analysis_language(string $analysisId): string
{
    $an = $analysisId ? read_analysis($analysisId) : null;
    $lang = (string) ($an['language'] ?? '');
    if ($lang === '' && preg_match('/\(([A-Z]{2,3})/', (string) ($an['source_label'] ?? ''), $m)) {
        $lang = strtolower($m[1]);
    }
    return preg_match('/^[a-z]{2,3}$/', $lang) ? $lang : '';
}

/** Buat job render clip. Validasi rentang & layout; berhenti dengan error 400 kalau tidak valid. */
function create_clip_job(array $p): string
{
    $start = (float) $p['start'];
    $end = (float) $p['end'];
    if ($start < 0 || $end - $start < 1 || $end - $start > 600) {
        respond(['error' => 'Rentang waktu clip tidak valid.'], 400);
    }
    if (!isset(CLIP_LAYOUT_LABELS[$p['layout']])) {
        respond(['error' => 'Layout tidak dikenal.'], 400);
    }
    $analysisId = valid_job_id((string) ($p['analysis_id'] ?? '')) ? $p['analysis_id'] : '';
    $hookText = mb_substr(trim((string) ($p['hook_text'] ?? '')), 0, 120);
    $subtitles = !empty($p['subtitles']);
    $id = bin2hex(random_bytes(8));
    write_job([
        'id' => $id,
        'url' => $p['url'],
        'mode' => 'clip',
        'mode_label' => 'Clip ' . CLIP_LAYOUT_LABELS[$p['layout']] . (!empty($p['split']) ? ' + split' : '')
            . ($subtitles ? ' + subtitle' : '') . ($hookText !== '' ? ' + hook' : ''),
        'subtitles' => $subtitles,
        'hook_text' => $hookText,
        'language' => analysis_language($analysisId),
        'height' => 0,
        'start' => $start,
        'end' => $end,
        'layout' => $p['layout'],
        'analysis_id' => $analysisId,
        'project_id' => (string) ($p['project_id'] ?? ''),
        'video_duration' => (float) ($p['video_duration'] ?? 0),
        'item_id' => (string) ($p['item_id'] ?? ''),
        'prepare_job' => (string) ($p['prepare_job'] ?? ''),
        'no_music' => !empty($p['no_music']),
        'both_music' => !empty($p['both_music']),
        'title' => mb_substr((string) ($p['title'] ?? ''), 0, 300),
        'thumbnail' => (string) ($p['thumbnail'] ?? ''),
        'status' => 'queued',
        'stage' => 'Menunggu…',
        'progress' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    spawn_worker($id);
    return $id;
}

function create_project_job(array $project, array $item, array $opts = []): string
{
    return create_clip_job([
        'no_music' => !empty($opts['no_music']), // render tanpa musik latar (musik tetap tersimpan di resep)
        'both_music' => !empty($opts['both_music']) && empty($opts['no_music']), // dua file: dengan & tanpa musik
        'url' => $project['video']['url'],
        'start' => $item['start'],
        'end' => $item['end'],
        'layout' => in_array($item['layout'], ['speaker', 'crop_center', 'blur', 'gaming'], true) ? $item['layout'] : 'speaker',
        'split' => !empty($item['split']) || $item['layout'] === 'split',
        'subtitles' => $item['subtitles'],
        'hook_text' => $item['hook_text'],
        'analysis_id' => $project['analysis_id'],
        'project_id' => $project['id'],
        'item_id' => $item['iid'],
        'prepare_job' => (string) ($item['prepare_job'] ?? ''),
        'video_duration' => (float) ($project['video']['duration'] ?? 0),
        'title' => $item['title'],
        'thumbnail' => $project['video']['thumbnail'],
    ]);
}

/**
 * Pastikan video sumber room sedang/sudah diunduh. Membuat job unduhan kalau belum ada atau gagal.
 * Mengembalikan job unduhan.
 */
function ensure_source(string $analysisId, bool $restart = false): ?array
{
    $room = read_job($analysisId);
    if (!$room) {
        return null;
    }
    $job = source_job($analysisId);
    $ok = $job && in_array($job['status'], ['queued', 'downloading', 'converting', 'done'], true)
        && ($job['status'] !== 'done' || is_file(source_file($analysisId)) || source_parts($analysisId));
    // Video sumber sengaja dihapus (potongan sumber tetap ada): tidak diunduh ulang otomatis.
    // Clip baru di luar potongan dipotong langsung dari YouTube.
    if ($ok && !$restart) {
        return $job;
    }
    $id = bin2hex(random_bytes(8));
    write_job([
        'id' => $id, 'kind' => 'source', 'analysis_id' => $analysisId, 'url' => $room['url'],
        'title' => $room['title'] ?? '', 'thumbnail' => $room['thumbnail'] ?? '',
        'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time(),
    ]);
    update_job($analysisId, ['source_job' => $id]);
    spawn_worker($id);
    return read_job($id);
}

/** Buat job persiapan editor (proxy + transkrip + deteksi wajah) untuk satu clip proyek. */
function create_prepare_job(array $project, array $item, bool $priority): string
{
    $id = bin2hex(random_bytes(8));
    write_job([
        'id' => $id, 'mode' => 'prepare', 'mode_label' => 'Persiapan editor', 'priority' => $priority,
        'url' => $project['video']['url'], 'start' => $item['start'], 'end' => $item['end'], 'layout' => $item['layout'],
        'subtitles' => $item['subtitles'], 'hook_text' => $item['hook_text'], 'language' => analysis_language($project['analysis_id']),
        'project_id' => $project['id'], 'item_id' => $item['iid'], 'analysis_id' => $project['analysis_id'],
        'video_duration' => (float) ($project['video']['duration'] ?? 0), 'title' => $item['title'],
        'thumbnail' => $project['video']['thumbnail'], 'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    spawn_worker($id);
    return $id;
}

/**
 * Buang job render lama (saat render ulang / hapus proyek). Job yang masih berjalan dibatalkan dulu;
 * file job-nya dibiarkan supaya worker bisa menulis status terakhir, lalu disembunyikan dari tampilan.
 */
function discard_clip_job(string $jobId, bool $hardDelete = false): void
{
    $job = valid_job_id($jobId) ? read_job($jobId) : null;
    if (!$job) {
        return;
    }
    foreach (['file', 'file_music'] as $k) {
        if (!empty($job[$k])) {
            @unlink(DOWNLOAD_DIR . '/' . basename($job[$k]));
        }
    }
    if (in_array($job['status'], ['queued', 'downloading', 'converting'], true)) {
        touch(JOBS_DIR . "/$jobId.cancel");
        @chmod(JOBS_DIR . "/$jobId.cancel", 0666);
        update_job($jobId, ['superseded' => true]);
        return;
    }
    @unlink(job_path($jobId));
}

/**
 * Clip yang sudah dirender tapi belum ditandai sudah upload, dihitung per akun (kunci '' = tanpa akun).
 * Dipakai kalender & pengingat jam upload ("3 clip siap upload").
 */
function ready_by_account(): array
{
    $accountOf = [];
    foreach (glob(JOBS_DIR . '/*.json') as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (($j['kind'] ?? '') === 'analyze') {
            $accountOf[$j['id']] = (string) ($j['account_id'] ?? '');
        }
    }
    $out = [];
    foreach (glob(PROJECTS_DIR . '/*.json') as $f) {
        $project = json_decode((string) @file_get_contents($f), true);
        $acc = $accountOf[$project['analysis_id'] ?? ''] ?? '';
        foreach ($project['items'] ?? [] as $it) {
            if (empty($it['uploaded']) && ($j = read_job((string) ($it['job_id'] ?? ''))) && $j['status'] === 'done') {
                $out[$acc] = ($out[$acc] ?? 0) + 1;
            }
        }
    }
    return $out;
}

/* ---------- Bersihkan room: hapus file berat, simpan data & riwayat clip ---------- */

/** Thumbnail feed (frame detik ke-1 dari file clip render) untuk satu job; dibuat kalau belum ada. */
function clip_thumb_file(array $job): ?string
{
    $thumb = THUMB_DIR . '/' . $job['id'] . '.jpg';
    if (is_file($thumb)) {
        return $thumb;
    }
    $file = ($job['status'] ?? '') === 'done' ? DOWNLOAD_DIR . '/' . basename((string) ($job['file'] ?? '')) : '';
    if (!$file || !is_file($file)) {
        return null;
    }
    if (!is_dir(THUMB_DIR)) {
        @mkdir(THUMB_DIR, 0777, true);
        @chmod(THUMB_DIR, 0777);
    }
    shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -loglevel error -y -ss 1 -i ' . escapeshellarg($file)
        . ' -frames:v 1 -vf scale=540:-2 -q:v 4 ' . escapeshellarg($thumb));
    @chmod($thumb, 0666);
    return is_file($thumb) ? $thumb : null;
}

/** Semua proyek dari room (analisa) yang sama: video sumbernya dipakai bersama. */
function room_projects(string $analysisId): array
{
    $out = [];
    foreach (glob(PROJECTS_DIR . '/*.json') as $f) {
        $p = json_decode((string) @file_get_contents($f), true);
        if (($p['analysis_id'] ?? '') === $analysisId) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * File berat milik room yang bisa dibersihkan, per kelompok: ['files' => [...], 'bytes' => n].
 * source = video sumber, proxy = video preview editor, preview = cuplikan preview analisa,
 * old = versi render lama (riwayat), clips = file clip final terbaru.
 */
function room_heavy_files(array $project): array
{
    $aid = $project['analysis_id'];
    $g = ['source' => [], 'proxy' => [], 'preview' => [], 'old' => [], 'clips' => []];
    $g['source'][] = source_file($aid);
    foreach (glob(source_parts_dir($aid) . '/*') ?: [] as $f) {
        $g['source'][] = $f; // potongan sumber (setelah video sumber lengkap dihapus)
    }
    foreach (room_projects($aid) as $p) {
        foreach (glob(APP_DIR . '/previews/proj_' . $p['id'] . '/*') ?: [] as $f) {
            $g['proxy'][] = $f;
        }
        foreach ($p['items'] ?? [] as $it) {
            foreach ((array) ($it['renders'] ?? []) as $rid) {
                $j = read_job($rid);
                foreach (array_filter([$j['file'] ?? '', $j['file_music'] ?? '']) as $f) {
                    $g['old'][] = DOWNLOAD_DIR . '/' . basename($f);
                }
            }
            $j = read_job((string) $it['job_id']);
            foreach (array_filter([$j['file'] ?? '', $j['file_music'] ?? '']) as $f) {
                $g['clips'][] = DOWNLOAD_DIR . '/' . basename($f);
            }
        }
    }
    // Cuplikan preview analisa (mp4); frame thumbnail (jpg) tetap disimpan
    foreach (glob(APP_DIR . "/previews/$aid/*.mp4") ?: [] as $f) {
        $g['preview'][] = $f;
    }
    $out = [];
    foreach ($g as $k => $files) {
        $files = array_values(array_unique(array_filter($files, 'is_file')));
        $out[$k] = ['files' => $files, 'bytes' => array_sum(array_map('filesize', $files))];
    }
    return $out;
}

/**
 * Rentang video asli (detik) yang dipakai clip-clip room: rentang editor tiap clip (resep edit), atau rentang clip ±EDIT_PAD
 * kalau editornya belum disiapkan. Disimpan sebagai potongan sumber saat video sumber lengkap dihapus.
 */
function room_source_ranges(string $aid): array
{
    $ranges = [];
    foreach (room_projects($aid) as $p) {
        foreach ($p['items'] ?? [] as $it) {
            $e = json_decode((string) @file_get_contents(edit_path($p['id'], $it['iid'])), true);
            if (!empty($e['pad'])) {
                $a = min($e['pad'][0], $e['trim'][0] ?? $e['pad'][0]);
                $b = max($e['pad'][1], $e['trim'][1] ?? $e['pad'][1]);
            } else {
                [$a, $b] = [(float) $it['start'] - EDIT_PAD, (float) $it['end'] + EDIT_PAD];
            }
            $ranges[] = [max(0, $a - 1), $b + 1];
        }
    }
    return $ranges;
}

/** Lempar exception kalau video sumber room masih diunduh atau ada clip yang sedang disiapkan / dirender. */
function room_assert_idle(string $aid): void
{
    $sj = source_job($aid);
    if ($sj && in_array($sj['status'], ['queued', 'downloading', 'converting'], true)) {
        throw new RuntimeException('Video sumber masih diunduh.');
    }
    foreach (room_projects($aid) as $p) {
        foreach ($p['items'] ?? [] as $it) {
            $pj = !empty($it['prepare_job']) ? read_job($it['prepare_job']) : null;
            $rj = read_job((string) $it['job_id']);
            foreach ([$pj, $rj] as $j) {
                if ($j && in_array($j['status'], ['queued', 'downloading', 'converting'], true)) {
                    throw new RuntimeException('Masih ada clip yang sedang diproses. Tunggu sampai selesai, lalu coba lagi.');
                }
            }
        }
    }
}

/** Total detik video asli yang dipakai clip-clip room (rentang digabung). */
function room_cover_seconds(string $aid): float
{
    $rs = room_source_ranges($aid);
    usort($rs, fn($a, $b) => $a[0] <=> $b[0]);
    $cover = 0;
    $last = -1;
    foreach ($rs as [$a, $b]) {
        $a = max($a, $last);
        if ($b > $a) {
            $cover += $b - $a;
            $last = $b;
        }
    }
    return $cover;
}

/**
 * Hapus video sumber lengkap sebuah room. Potongan di sekitar tiap clip disimpan dulu (tanpa encode ulang),
 * jadi semua clip tetap bisa diedit & dirender. Preview editor, render, & file clip tidak disentuh.
 * Melempar exception (video sumber tidak dihapus) kalau tidak ada / ada clip yang sedang diproses / gagal.
 */
function slim_room(string $aid): array
{
    $srcFile = source_file($aid);
    if (!is_file($srcFile)) {
        throw new RuntimeException('Video sumber room ini sudah tidak ada.');
    }
    room_assert_idle($aid);
    try {
        $meta = make_source_parts($aid, room_source_ranges($aid));
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage() . ' Video sumber tidak dihapus.');
    }
    $freed = filesize($srcFile);
    @unlink($srcFile);
    $slim = ['at' => time(), 'freed' => $freed, 'kept' => array_sum(array_column($meta['parts'], 'bytes')), 'parts' => count($meta['parts'])];
    foreach (room_projects($aid) as $p) {
        $p['slim'] = $slim;
        write_project($p);
    }
    return $slim;
}

/** Pemakaian disk BisaClip per kelompok (untuk Pengaturan → Manajemen storage). */
function storage_info(): array
{
    $sum = fn(array $files) => ['bytes' => array_sum(array_map(fn($f) => (int) @filesize($f), $files)), 'count' => count($files)];
    $sources = glob(SOURCES_DIR . '/*.mp4') ?: [];
    $parts = glob(SOURCES_DIR . '/*/p_*.mp4') ?: [];
    $cur = $old = [];
    foreach (glob(PROJECTS_DIR . '/*.json') ?: [] as $pf) {
        $p = json_decode((string) file_get_contents($pf), true);
        foreach ((array) ($p['items'] ?? []) as $it) {
            foreach (array_merge([$it['job_id'] ?? ''], (array) ($it['renders'] ?? [])) as $k => $rid) {
                $j = valid_job_id((string) $rid) ? read_job($rid) : null;
                foreach (array_filter([$j['file'] ?? '', $j['file_music'] ?? '']) as $f) {
                    $path = DOWNLOAD_DIR . '/' . basename($f);
                    if (is_file($path)) {
                        $k === 0 ? $cur[] = $path : $old[] = $path;
                    }
                }
            }
        }
    }
    $known = array_flip(array_merge($cur, $old));
    $other = array_filter(glob(DOWNLOAD_DIR . '/*') ?: [], fn($f) => is_file($f) && !isset($known[$f]) && basename($f) !== '.htaccess');
    $proxies = glob(APP_DIR . '/previews/proj_*/*') ?: [];
    // Perkiraan ruang yang kembali kalau video sumber semua room dihapus (potongan di sekitar clip tetap disimpan)
    $rooms = [];
    $save = 0;
    foreach ($sources as $f) {
        $aid = basename($f, '.mp4');
        $dur = (float) (source_job($aid)['duration'] ?? 0);
        $bytes = filesize($f);
        $keep = room_projects($aid) && $dur > 0 ? (int) ($bytes * min(1, room_cover_seconds($aid) / $dur)) : 0;
        $save += $bytes - $keep;
        $rooms[] = ['id' => $aid, 'title' => read_job($aid)['title'] ?? 'Room terhapus', 'bytes' => $bytes, 'save' => $bytes - $keep, 'clips' => (bool) room_projects($aid),
            'thumb' => read_job($aid)['thumbnail'] ?? ''];
    }
    // Potongan versi lama (satu file besar dari detik 0) yang bisa dikemas ulang
    foreach (glob(SOURCES_DIR . '/*/parts.json') ?: [] as $pj) {
        $aid = basename(dirname($pj));
        $m = source_parts($aid);
        $files = array_values(array_unique(array_column($m['parts'] ?? [], 'file')));
        $path = dirname($pj) . '/' . ($files[0] ?? '');
        if (count($files) !== 1 || ($m['parts'][0]['from'] ?? 1) > 0 || !is_file($path) || !room_projects($aid)) {
            continue;
        }
        $len = max(1, (float) max(array_column($m['parts'], 'to')));
        $bytes = filesize($path);
        $keep = (int) ($bytes * min(1, room_cover_seconds($aid) / $len));
        if ($bytes - $keep > 20 * 1048576) {
            $save += $bytes - $keep;
            $rooms[] = ['id' => $aid, 'title' => read_job($aid)['title'] ?? 'Room', 'bytes' => $bytes, 'save' => $bytes - $keep, 'clips' => true, 'repack' => true,
                'thumb' => read_job($aid)['thumbnail'] ?? ''];
        }
    }
    usort($rooms, fn($a, $b) => $b['save'] <=> $a['save']);
    return [
        'groups' => [
            'source' => $sum($sources),
            'parts' => $sum($parts),
            'old' => $sum(array_values($old)),
            'current' => $sum(array_values($cur)),
            'downloads' => $sum(array_values($other)),
            'proxy' => $sum($proxies),
            'music' => $sum(glob(MUSIC_DIR . '/*') ?: []),
            // Footage project manual (file asli + preview ringan)
            'manual' => $sum(array_merge(glob(STORAGE_DIR . '/manual/*/*.*') ?: [], glob(APP_DIR . '/previews/manual/*/*.mp4') ?: [])),
            // Bahan footage campaign (file asli + versi ringan untuk pratinjau)
            'campaign' => $sum(array_merge(glob(STORAGE_DIR . '/campaigns/*/*.{mp4,mov,m4v,mkv,webm,avi}', GLOB_BRACE) ?: [],
                glob(APP_DIR . '/previews/campaign/*/*.mp4') ?: [])),
            'models' => $sum(glob(STORAGE_DIR . '/models/*') ?: []),
        ],
        'rooms' => $rooms,
        'source_save' => $save,
        'disk_free' => (int) @disk_free_space(APP_DIR),
    ];
}

/** Semua clip di room (semua proyek) sudah ditandai sudah upload? */
function room_all_uploaded(string $analysisId): bool
{
    $n = 0;
    foreach (room_projects($analysisId) as $p) {
        foreach ($p['items'] ?? [] as $it) {
            if (empty($it['uploaded'])) {
                return false;
            }
            $n++;
        }
    }
    return $n > 0;
}

/* ---------- Preset editing: style clip yang disimpan dengan nama, dipakai ulang di semua proyek ---------- */
function read_presets(): array
{
    $p = json_decode((string) @file_get_contents(STORAGE_DIR . '/presets.json'), true);
    return is_array($p) ? array_values($p) : [];
}

function write_presets(array $list): void
{
    file_put_contents(STORAGE_DIR . '/presets.json', json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(STORAGE_DIR . '/presets.json', 0666);
}

const RENDER_HISTORY_MAX = 10; // render lama yang disimpan per clip (selain render terbaru)

/**
 * Sebelum clip dirender ulang: render lama yang sudah selesai dipindah ke riwayat (file tetap ada & bisa diunduh),
 * render yang belum selesai / gagal dibuang seperti biasa. Riwayat lebih dari batas dihapus dari yang terlama.
 */
function archive_clip_job(array &$item): void
{
    $job = valid_job_id((string) $item['job_id']) ? read_job($item['job_id']) : null;
    if ($job && $job['status'] === 'done' && !empty($job['file']) && is_file(DOWNLOAD_DIR . '/' . basename($job['file']))) {
        $item['renders'] = array_values(array_filter((array) ($item['renders'] ?? []), fn($id) => $id !== $item['job_id']));
        $item['renders'][] = $item['job_id'];
        while (count($item['renders']) > RENDER_HISTORY_MAX) {
            discard_clip_job(array_shift($item['renders']), true);
        }
        return;
    }
    discard_clip_job($item['job_id']);
}

/** Ringkasan render untuk panel riwayat: terbaru dulu (render aktif clip paling atas). */
function render_history(array $item): array
{
    $out = [];
    foreach (array_reverse(array_merge((array) ($item['renders'] ?? []), [$item['job_id']])) as $id) {
        $j = valid_job_id((string) $id) ? read_job($id) : null;
        if (!$j || ($j['status'] !== 'done' && $id !== $item['job_id'])) {
            continue;
        }
        $out[] = ['id' => $j['id'], 'status' => $j['status'], 'current' => $id === $item['job_id'], 'file' => $j['file'] ?? '',
            'size' => $j['size'] ?? 0, 'created_at' => $j['created_at'] ?? 0, 'done_at' => $j['updated_at'] ?? 0,
            'no_music' => !empty($j['no_music']), 'with_music' => !empty($j['with_music']),
            'both_music' => !empty($j['both_music']), 'file_music' => $j['file_music'] ?? '', 'size_music' => $j['size_music'] ?? 0,
            'duration' => $j['result']['duration'] ?? null, 'resolution' => $j['result']['resolution'] ?? null,
            'exists' => !empty($j['file']) && is_file(DOWNLOAD_DIR . '/' . basename($j['file']))];
    }
    return $out;
}

/** Ambil proyek + indeks item dari parameter id & iid; berhenti dengan 404 kalau tidak ada. */
function project_item_or_fail(array $in): array
{
    $pid = (string) ($in['id'] ?? '');
    $project = valid_job_id($pid) ? read_project($pid) : null;
    if (!$project) {
        respond(['error' => 'Proyek tidak ditemukan.'], 404);
    }
    foreach ($project['items'] as $k => $item) {
        if ($item['iid'] === (string) ($in['iid'] ?? '')) {
            return [$project, $k];
        }
    }
    respond(['error' => 'Clip tidak ditemukan.'], 404);
}

/** Buat proyek Clip Studio dari scene hasil analisa (indeks clip), mulai persiapan editor & render. */
function create_project(string $analysisId, array $an, array $scenes): string
{
    ensure_source($analysisId); // room lama: mulai unduh video sumber kalau belum ada
    $pid = bin2hex(random_bytes(8));
    $project = [
        'id' => $pid,
        'analysis_id' => $analysisId,
        'video' => $an['video'],
        'created_at' => time(),
        'items' => [],
    ];
    foreach ($scenes as $idx) {
        $project['items'][] = new_project_item($project, $an, $idx);
    }
    write_project($project);
    write_job([
        'id' => $pid,
        'kind' => 'project',
        'analysis_id' => $analysisId,
        'title' => $an['video']['title'],
        'thumbnail' => $an['video']['thumbnail'],
        'url' => $an['video']['url'],
        'clip_count' => count($project['items']),
        'status' => 'done',
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    update_job($analysisId, ['project_id' => $pid]);
    return $pid;
}

/** Item proyek baru dari scene analisa ke-$idx, langsung disiapkan & masuk antrean render. */
function new_project_item(array $project, array $an, int $idx): array
{
    $c = $an['clips'][$idx];
    $item = [
        'iid' => bin2hex(random_bytes(4)),
        'scene' => $idx,
        'title' => $c['title'],
        'caption' => $c['caption'],
        'hashtags' => $c['hashtags'],
        'start' => $c['start'],
        'end' => $c['end'],
        'hook_text' => $c['on_screen_text'],
        'concept' => (string) ($c['concept'] ?? ''),
        'brand_fit' => (string) ($c['brand_fit'] ?? ''),     // campaign brand: hard | soft | '' (clip biasa)
        'brand_angle' => (string) ($c['brand_angle'] ?? ''),
        'subtitles' => true,
        // Style dari modal "Atur clip": streaming game = game atas 70% + webcam bawah 30%
        'layout' => ($an['style'] ?? '') === 'gaming' ? 'gaming' : 'speaker',
    ];
    // Editor disiapkan sejak awal di jalur terpisah; job render menunggu hasil persiapan ini
    $item['prepare_job'] = create_prepare_job($project, $item, false);
    $item['job_id'] = create_project_job($project, $item);
    return $item;
}

/**
 * Tambah clip: hasil job analyze_more yang sudah selesai dimasukkan ke proyek (sekali saja).
 * Mengembalikan job tambah clip terbaru room ini (atau null).
 */
function apply_more_clips(string $aid, string $pid): ?array
{
    $more = ($mid = (string) (read_job($aid)['more_job'] ?? '')) ? read_job($mid) : null;
    if (!$more || $more['status'] !== 'done' || !empty($more['applied']) || !$pid) {
        return $more;
    }
    $lock = fopen(STORAGE_DIR . "/tmp/more-$aid.lock", 'c');
    flock($lock, LOCK_EX);
    try {
        $more = read_job($more['id']);
        $project = read_project($pid);
        $an = read_analysis($aid);
        if (empty($more['applied']) && $project && $an) {
            $have = array_column($project['items'], 'scene');
            foreach ($more['new_scenes'] ?? [] as $idx) {
                if (isset($an['clips'][$idx]) && !in_array($idx, $have, true)) {
                    $project['items'][] = new_project_item($project, $an, $idx);
                }
            }
            write_project($project);
            update_job($pid, ['clip_count' => count($project['items'])]);
            $more = update_job($more['id'], ['applied' => true]);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $more;
}

/** Id proyek milik sebuah analisa (dicatat di job analisa; proyek lama dicari dari daftar job). */
function project_for_analysis(string $analysisId): string
{
    $pid = (string) (read_job($analysisId)['project_id'] ?? '');
    if ($pid && is_file(PROJECTS_DIR . "/$pid.json")) {
        return $pid;
    }
    $found = '';
    foreach (glob(JOBS_DIR . '/*.json') as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (($j['kind'] ?? '') === 'project' && ($j['analysis_id'] ?? '') === $analysisId && is_file(PROJECTS_DIR . '/' . $j['id'] . '.json')
            && ($j['created_at'] ?? 0) >= ($found ? (read_job($found)['created_at'] ?? 0) : 0)) {
            $found = $j['id'];
        }
    }
    if ($found) {
        update_job($analysisId, ['project_id' => $found]);
    }
    return $found;
}

/**
 * Simpan gambar unggahan ($_FILES['file']) ke folder overlay. Disimpan per isi file, jadi unggah ulang tidak dobel.
 * Mengembalikan [nama file, lebar, tinggi]; berhenti dengan pesan error kalau tidak valid.
 */
function save_image_upload(): array
{
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $big = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        respond(['error' => $big ? 'Ukuran gambar terlalu besar.' : 'Gambar gagal diunggah.'], 400);
    }
    if ($f['size'] > 20 * 1024 * 1024) {
        respond(['error' => 'Ukuran gambar maksimal 20 MB.'], 400);
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        respond(['error' => 'Format gambar harus PNG, JPG, atau WEBP.'], 400);
    }
    if (!is_dir(OVERLAY_DIR)) {
        @mkdir(OVERLAY_DIR, 0777, true);
        @chmod(OVERLAY_DIR, 0777);
    }
    $name = sha1_file($f['tmp_name']) . ".$ext";
    $dest = OVERLAY_DIR . "/$name";
    if (!is_file($dest) && !move_uploaded_file($f['tmp_name'], $dest)) {
        respond(['error' => 'Gambar gagal disimpan.'], 500);
    }
    @chmod($dest, 0666);
    return [$name, (int) $info[0], (int) $info[1]];
}

/**
 * Simpan video unggahan ($_FILES['file']) ke folder overlay untuk dipakai sebagai footage, plus thumbnail JPG.
 * Mengembalikan [nama file, lebar, tinggi, durasi]; berhenti dengan pesan error kalau tidak valid.
 */
function save_video_upload(): array
{
    $f = $_FILES['file'];
    $ext = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v']
        [(string) @mime_content_type($f['tmp_name'])] ?? null;
    if (!$ext) {
        respond(['error' => 'Format video harus MP4, MOV, atau WEBM.'], 400);
    }
    $info = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams v:0 -show_entries stream=width,height'
        . ' -show_entries format=duration -of json ' . escapeshellarg($f['tmp_name'])), true) ?: [];
    $w = (int) ($info['streams'][0]['width'] ?? 0);
    $h = (int) ($info['streams'][0]['height'] ?? 0);
    $dur = round((float) ($info['format']['duration'] ?? 0), 3);
    if (!$w || !$h || $dur < 0.2) {
        respond(['error' => 'Video tidak bisa dibaca.'], 400);
    }
    if ($dur > 600) {
        respond(['error' => 'Durasi video footage maksimal 10 menit.'], 400);
    }
    if (!is_dir(OVERLAY_DIR)) {
        @mkdir(OVERLAY_DIR, 0777, true);
        @chmod(OVERLAY_DIR, 0777);
    }
    $sha = sha1_file($f['tmp_name']);
    $dest = OVERLAY_DIR . "/$sha.$ext";
    if (!is_file($dest) && !move_uploaded_file($f['tmp_name'], $dest)) {
        respond(['error' => 'Video gagal disimpan.'], 500);
    }
    @chmod($dest, 0666);
    $thumb = OVERLAY_DIR . "/$sha.thumb.jpg";
    if (!is_file($thumb)) {
        shell_exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . min(0.5, $dur / 2) . ' -i ' . escapeshellarg($dest)
            . ' -frames:v 1 -vf scale=320:-2 ' . escapeshellarg($thumb));
        @chmod($thumb, 0666);
    }
    return ["$sha.$ext", $w, $h, $dur];
}

/**
 * Simpan musik unggahan ($_FILES['file']) ke pustaka musik. Mengembalikan [nama file, durasi detik];
 * berhenti dengan pesan error kalau bukan audio yang bisa dibaca.
 */
function save_music_upload(): array
{
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $big = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        respond(['error' => $big ? 'Ukuran file musik terlalu besar.' : 'Musik gagal diunggah.'], 400);
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $ext = ['mpeg' => 'mp3', 'mpga' => 'mp3'][$ext] ?? $ext;
    if (!in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'flac'], true)) {
        respond(['error' => 'Format musik harus MP3, M4A, AAC, WAV, OGG, atau FLAC.'], 400);
    }
    $info = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams a:0 -show_entries stream=codec_type'
        . ' -show_entries format=duration -of json ' . escapeshellarg($f['tmp_name'])), true) ?: [];
    $dur = round((float) ($info['format']['duration'] ?? 0), 3);
    if (($info['streams'][0]['codec_type'] ?? '') !== 'audio' || $dur < 1) {
        respond(['error' => 'File musik tidak bisa dibaca.'], 400);
    }
    if ($dur > 3600) {
        respond(['error' => 'Durasi musik maksimal 60 menit.'], 400);
    }
    if (!is_dir(MUSIC_DIR)) {
        @mkdir(MUSIC_DIR, 0777, true);
        @chmod(MUSIC_DIR, 0777);
    }
    $name = sha1_file($f['tmp_name']) . ".$ext";
    $dest = MUSIC_DIR . "/$name";
    if (!is_file($dest) && !move_uploaded_file($f['tmp_name'], $dest)) {
        respond(['error' => 'Musik gagal disimpan.'], 500);
    }
    @chmod($dest, 0666);
    return [$name, $dur];
}

function media_library_path(string $pid): string
{
    return PROJECTS_DIR . "/$pid/media.json";
}

function read_media_library(string $pid): array
{
    $lib = json_decode((string) @file_get_contents(media_library_path($pid)), true);
    return array_values(array_filter(is_array($lib) ? $lib : [], fn($m) => footage_file_ok($m['file'] ?? null)));
}

function write_media_library(string $pid, array $lib): void
{
    $dir = dirname(media_library_path($pid));
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(media_library_path($pid), json_encode(array_slice($lib, 0, 200), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod(media_library_path($pid), 0666);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'info':
        set_time_limit(120);
        $url = trim((string) (input()['url'] ?? ''));
        $url = require_video_url($url);

        $cmd = escapeshellarg(YTDLP) . ' --dump-single-json --no-playlist --no-warnings --socket-timeout 20 -- '
            . escapeshellarg($url);
        [$code, $raw, $timedOut] = run_with_timeout($cmd, 60);
        if ($timedOut) {
            respond(['error' => 'Waktu habis (60 detik) saat membaca video. Periksa koneksi internet lalu coba lagi.'], 504);
        }
        $info = null;
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '{')) {
                $info = json_decode($line, true);
            }
        }
        if ($code !== 0 || !is_array($info)) {
            $msg = trim(preg_replace('/^.*ERROR:\s*/s', '', $raw));
            respond(['error' => 'Gagal membaca video: ' . mb_substr($msg, 0, 300)], 422);
        }

        // Kelompokkan format video per resolusi, ambil bitrate tertinggi tiap resolusi
        $bestAudio = 0;
        foreach ($info['formats'] ?? [] as $f) {
            if (($f['vcodec'] ?? 'none') === 'none' && ($f['acodec'] ?? 'none') !== 'none') {
                $size = $f['filesize'] ?? $f['filesize_approx'] ?? 0;
                $bestAudio = max($bestAudio, (int) $size);
            }
        }

        $qualities = [];
        foreach ($info['formats'] ?? [] as $f) {
            $h = (int) ($f['height'] ?? 0);
            if (($f['vcodec'] ?? 'none') === 'none' || $h < 144) {
                continue;
            }
            $fps = (int) round($f['fps'] ?? 30);
            $size = (int) ($f['filesize'] ?? $f['filesize_approx'] ?? 0);
            $hdr = ($f['dynamic_range'] ?? 'SDR') !== 'SDR';
            $q = $qualities[$h] ?? ['height' => $h, 'fps' => 0, 'size' => 0, 'hdr' => false, 'codecs' => []];
            $q['fps'] = max($q['fps'], $fps);
            $q['size'] = max($q['size'], $size);
            $q['hdr'] = $q['hdr'] || $hdr;
            $codec = strtoupper(explode('.', (string) $f['vcodec'])[0]);
            $codec = ['AVC1' => 'H.264', 'AV01' => 'AV1', 'VP09' => 'VP9'][$codec] ?? $codec;
            $q['codecs'][$codec] = true;
            $qualities[$h] = $q;
        }
        krsort($qualities);
        $qualities = array_map(function ($q) use ($bestAudio) {
            $q['codecs'] = array_keys($q['codecs']);
            $q['size'] = $q['size'] ? $q['size'] + $bestAudio : 0;
            return $q;
        }, array_values($qualities));

        respond([
            'url' => $info['webpage_url'] ?? $url,
            'title' => $info['title'] ?? 'video',
            'uploader' => $info['uploader'] ?? $info['channel'] ?? '',
            'duration' => (int) ($info['duration'] ?? 0),
            'thumbnail' => $info['thumbnail'] ?? '',
            'is_live' => (bool) ($info['is_live'] ?? false),
            'language' => strtolower(explode('-', (string) ($info['language'] ?? ''))[0]),
            'qualities' => $qualities,
        ]);

    case 'start':
        $in = input();
        $url = trim((string) ($in['url'] ?? ''));
        $mode = (string) ($in['mode'] ?? 'edit_h264');
        $height = (int) ($in['height'] ?? 0); // 0 = terbaik
        $url = require_video_url($url);
        if (!isset(MODES[$mode])) {
            respond(['error' => 'Mode tidak dikenal.'], 400);
        }

        $id = bin2hex(random_bytes(8));
        write_job([
            'id' => $id,
            'url' => $url,
            'mode' => $mode,
            'mode_label' => MODES[$mode]['label'],
            'analysis_id' => valid_job_id((string) ($in['analysis_id'] ?? '')) ? $in['analysis_id'] : '',
            'height' => $height,
            'title' => mb_substr((string) ($in['title'] ?? ''), 0, 300),
            'thumbnail' => (string) ($in['thumbnail'] ?? ''),
            'status' => 'queued',
            'stage' => 'Menunggu…',
            'progress' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        spawn_worker($id);
        respond(['id' => $id]);

    case 'analyze':
        $in = input();
        $url = trim((string) ($in['url'] ?? ''));
        $url = require_video_url($url);
        if (trim((string) @file_get_contents(STORAGE_DIR . '/gemini.key')) === '') {
            respond(['error' => 'API key Gemini belum diisi. Buka Pengaturan → API key.'], 400);
        }
        // Pengaturan dari modal "Buat Clip": rentang durasi clip (detik) & jumlah maksimal
        $clipMin = (int) ($in['clip_min'] ?? CLIP_MIN_SEC);
        $clipMax = (int) ($in['clip_max'] ?? CLIP_MAX_SEC);
        $maxClips = (int) ($in['max_clips'] ?? 0);
        $style = ($in['style'] ?? '') === 'gaming' ? 'gaming' : 'podcast';
        $outLang = in_array($in['out_lang'] ?? '', ['id', 'en'], true) ? $in['out_lang'] : 'auto';
        // Konsep clip (boleh lebih dari satu) + konteks tambahan bebas dari user
        $concepts = clip_concepts_pick($style, array_map('strval', (array) ($in['concepts'] ?? [])));
        $conceptNote = mb_substr(trim((string) ($in['concept_note'] ?? '')), 0, 200);
        $capStyle = isset(CAPTION_STYLES[$in['caption_style'] ?? '']) ? $in['caption_style'] : 'auto';
        $brand = brand_clean((array) ($in['brand'] ?? []));
        $accountId = (string) ($in['account_id'] ?? '');
        if ($accountId !== '' && !find_account($accountId)) {
            respond(['error' => 'Akun tidak ditemukan. Pilih akun lain.'], 400);
        }
        if ($clipMin < 10 || $clipMax > 180 || $clipMax - $clipMin < 10) {
            respond(['error' => 'Rentang durasi clip tidak valid.'], 400);
        }
        $id = bin2hex(random_bytes(8));
        write_job([
            'id' => $id,
            'kind' => 'analyze',
            'url' => $url,
            'clip_min' => $clipMin,
            'clip_max' => $clipMax,
            'max_clips' => max(0, min(20, $maxClips)),
            'style' => $style,
            'out_lang' => $outLang, // bahasa judul & caption: auto (ikuti video) | id | en
            'concepts' => $concepts,
            'concept_note' => $conceptNote,
            'caption_style' => $capStyle, // gaya judul/hook/caption: auto = ikuti jenis clip
            'brand' => $brand,             // campaign brand: null = clip biasa
            'account_id' => $accountId,
            'title' => mb_substr((string) ($in['title'] ?? ''), 0, 300),
            'thumbnail' => (string) ($in['thumbnail'] ?? ''),
            'status' => 'queued',
            'stage' => 'Menunggu…',
            'progress' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        spawn_worker($id);
        ensure_source($id); // video sumber diunduh bersamaan dengan analisa
        if ($accountId !== '') {
            // Ingat style terakhir akun ini (akun clip game → streaming game) untuk modal berikutnya
            write_accounts(array_map(fn($a) => $a['id'] === $accountId ? ['style' => $style] + $a : $a, read_accounts()));
        }
        respond(['id' => $id]);

    case 'analyze_more':
        // Tambah clip dari Studio: AI menganalisa ulang video yang sama, mencari momen di luar clip yang sudah ada
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        $aid = (string) ($project['analysis_id'] ?? '');
        $an = $aid ? read_job($aid) : null;
        if (!$project || !$an || ($an['status'] ?? '') !== 'done') {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        if (trim((string) @file_get_contents(STORAGE_DIR . '/gemini.key')) === '') {
            respond(['error' => 'API key Gemini belum diisi. Buka Pengaturan → API key.'], 400);
        }
        $prev = !empty($an['more_job']) ? read_job($an['more_job']) : null;
        if ($prev && in_array($prev['status'], ['queued', 'downloading', 'converting', 'analyzing'], true)) {
            respond(['error' => 'AI masih mencari clip tambahan.'], 409);
        }
        $clipMin = (int) ($in['clip_min'] ?? $an['clip_min'] ?? CLIP_MIN_SEC);
        $clipMax = (int) ($in['clip_max'] ?? $an['clip_max'] ?? CLIP_MAX_SEC);
        if ($clipMin < 10 || $clipMax > 180 || $clipMax - $clipMin < 10) {
            respond(['error' => 'Rentang durasi clip tidak valid.'], 400);
        }
        $id = bin2hex(random_bytes(8));
        write_job([
            'id' => $id,
            'kind' => 'analyze_more',
            'analysis_id' => $aid,
            'project_id' => $pid,
            'url' => $an['url'],
            'clip_min' => $clipMin,
            'clip_max' => $clipMax,
            'max_clips' => max(1, min(10, (int) ($in['count'] ?? 3))),
            'style' => ($an['style'] ?? '') === 'gaming' ? 'gaming' : 'podcast',
            // Clip tambahan memakai bahasa yang sama dengan analisa awal room
            'out_lang' => in_array($an['out_lang_used'] ?? '', ['id', 'en'], true) ? $an['out_lang_used'] : ($an['out_lang'] ?? 'auto'),
            'concepts' => $an['concepts'] ?? [],      // clip tambahan memakai konsep yang sama dengan analisa awal
            'concept_note' => $an['concept_note'] ?? '',
            'caption_style' => $an['caption_style'] ?? 'auto',
            'brand' => $an['brand'] ?? null,          // clip tambahan juga mengikuti campaign brand yang sama
            'title' => $an['title'] ?? '',
            'thumbnail' => $an['thumbnail'] ?? '',
            'status' => 'queued',
            'stage' => 'Menunggu…',
            'progress' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        if ($prev) {
            @unlink(job_path($prev['id'])); // hanya job tambah clip terakhir yang disimpan
        }
        update_job($aid, ['more_job' => $id]);
        spawn_worker($id);
        respond(['id' => $id]);

    case 'brands':
        respond(['brands' => read_brands(), 'categories' => BRAND_CATEGORIES]);

    case 'brand_save':
        // Simpan preset brand (ditimpa kalau namanya sama)
        $b = brand_clean(['enabled' => true] + (array) (input()['brand'] ?? []));
        if (!$b) {
            respond(['error' => 'Isi nama brand dulu.'], 400);
        }
        $list = array_values(array_filter(read_brands(), fn($x) => mb_strtolower($x['name']) !== mb_strtolower($b['name'])));
        array_unshift($list, $b + ['updated_at' => time()]);
        write_brands(array_slice($list, 0, 50));
        respond(['ok' => true, 'brands' => read_brands()]);

    case 'brand_delete':
        $name = mb_strtolower(trim((string) (input()['name'] ?? '')));
        write_brands(array_filter(read_brands(), fn($x) => mb_strtolower($x['name']) !== $name));
        respond(['ok' => true, 'brands' => read_brands()]);

    case 'brand_suggest':
        // AI menyarankan topik pembicaraan yang relevan dengan brand (untuk soft sell)
        $in = input();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
        if ($name === '') {
            respond(['error' => 'Isi nama brand dulu.'], 400);
        }
        if (trim((string) @file_get_contents(STORAGE_DIR . '/gemini.key')) === '') {
            respond(['error' => 'API key Gemini belum diisi. Buka Pengaturan → API key.'], 400);
        }
        require_once __DIR__ . '/gemini.php';
        $cat = BRAND_CATEGORIES[$in['category'] ?? ''][0] ?? '';
        $brief = mb_substr(trim((string) ($in['brief'] ?? '')), 0, 1500);
        $prompt = "Kamu membantu clipper podcast Indonesia ikut campaign clip untuk brand \"$name\"" . ($cat ? " (kategori: $cat)" : '') . ".\n"
            . ($brief !== '' ? "Brief campaign:\n\"\"\"\n$brief\n\"\"\"\n" : '')
            . "Sebutkan 10 topik obrolan yang sering muncul di podcast dan relevan dengan brand ini (untuk soft sell),\n"
            . "misalnya brand skincare → kulit, jerawat, percaya diri, kurang tidur. Tiap topik 1–3 kata, Bahasa Indonesia, huruf kecil,\n"
            . "konkret (bukan nama brand), urut dari yang paling relevan.";
        // Permintaan ringan & ditunggu user: model lite dulu (paling cepat), tiap model dibatasi 40 dtk
        $payload = json_encode(['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => [
            'responseMimeType' => 'application/json', 'temperature' => 0.7,
            'responseSchema' => ['type' => 'OBJECT', 'properties' => ['topics' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['topics']],
        ]], JSON_UNESCAPED_UNICODE);
        $res = null;
        $started = time();
        foreach (array_unique(['gemini-3.1-flash-lite', ...GEMINI_MODELS]) as $model) {
            if (isset(gemini_quota_blocked()[$model]) || time() - $started > 80) {
                continue;
            }
            try {
                [$code, $body] = gemini_http('POST', GEMINI_BASE . "/v1beta/models/$model:generateContent", ['Content-Type: application/json'], $payload, '', 40);
            } catch (Throwable $e) {
                continue;
            }
            $text = '';
            foreach (json_decode($body, true)['candidates'][0]['content']['parts'] ?? [] as $p) {
                $text .= empty($p['thought']) ? ($p['text'] ?? '') : '';
            }
            if ($code === 200 && is_array($res = json_decode($text, true))) {
                break;
            }
            $res = null;
        }
        if (!$res) {
            respond(['error' => 'AI sedang sibuk, coba lagi sebentar atau isi topik manual.'], 502);
        }
        $topics = array_slice(array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 40), (array) ($res['topics'] ?? [])))), 0, 12);
        respond(['topics' => $topics]);

    case 'source_retry':
        $aid = (string) (input()['id'] ?? '');
        if (!valid_job_id($aid) || !read_job($aid)) {
            respond(['error' => 'Room tidak ditemukan.'], 404);
        }
        respond(['job' => ensure_source($aid, true)]);

    case 'source_delete':
        $aid = (string) (input()['id'] ?? '');
        $job = valid_job_id($aid) ? source_job($aid) : null;
        if ($job && in_array($job['status'], ['queued', 'downloading', 'converting'], true)) {
            respond(['error' => 'Unduhan masih berjalan.'], 409);
        }
        @unlink(source_file($aid));
        rrmdir(source_parts_dir($aid));
        if ($job) {
            @unlink(job_path($job['id']));
        }
        respond(['ok' => true]);

    case 'retry':
        // Jalankan ulang analisa room / unduhan Beranda yang gagal atau dibatalkan, tetap dengan ID yang sama
        $id = (string) (input()['id'] ?? '');
        $job = valid_job_id($id) ? read_job($id) : null;
        $isDownload = $job && empty($job['kind']) && isset(MODES[$job['mode'] ?? '']) && ($job['mode'] ?? '') !== 'clip';
        if (!$job || (($job['kind'] ?? '') !== 'analyze' && !$isDownload)) {
            respond(['error' => $isDownload ? 'Unduhan tidak ditemukan.' : 'Room tidak ditemukan.'], 404);
        }
        if (!in_array($job['status'], ['error', 'cancelled'], true)) {
            respond(['error' => 'Room ini tidak sedang gagal.'], 409);
        }
        update_job($id, ['status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'error' => '']);
        spawn_worker($id);
        respond(['ok' => true]);

    case 'analysis':
        $id = (string) ($_GET['id'] ?? '');
        $file = STORAGE_DIR . "/analyses/$id.json";
        if (!valid_job_id($id) || !is_file($file)) {
            respond(['error' => 'Hasil analisa tidak ditemukan.'], 404);
        }
        $an = json_decode((string) file_get_contents($file), true);
        unset($an['model'], $an['tokens']);
        respond($an);

    case 'frame':
    case 'preview':
        require_once __DIR__ . '/preview.php';
        set_time_limit(180);
        $in = $action === 'frame' ? $_GET : input();
        $id = (string) ($in['id'] ?? '');
        $file = STORAGE_DIR . "/analyses/$id.json";
        if (!valid_job_id($id) || !is_file($file)) {
            respond(['error' => 'Hasil analisa tidak ditemukan.'], 404);
        }
        $an = json_decode((string) file_get_contents($file), true);
        $max = (float) ($an['video']['duration'] ?? 0) ?: INF;
        try {
            if ($action === 'frame') {
                $t = round(min($max, max(0, (float) ($in['t'] ?? 0))), 1);
                $path = make_frame($id, $an['video']['url'], $t);
                header('Content-Type: image/jpeg');
                header('Cache-Control: max-age=31536000, immutable');
                readfile($path);
                exit;
            }
            $s = round(max(0, (float) ($in['start'] ?? 0)), 1);
            $e = round(min($max, (float) ($in['end'] ?? 0)), 1);
            if ($e - $s < 1 || $e - $s > 180) {
                respond(['error' => 'Rentang preview tidak valid.'], 400);
            }
            $path = make_preview_video($id, $an['video']['url'], $s, $e);
            respond(['url' => 'previews/' . $id . '/' . basename($path)]);
        } catch (Throwable $ex) {
            respond(['error' => $ex->getMessage()], 502);
        }

    case 'clip':
        $in = input();
        $id = create_clip_job([
            'url' => require_video_url(trim((string) ($in['url'] ?? ''))),
            'start' => (float) ($in['start'] ?? -1),
            'end' => (float) ($in['end'] ?? -1),
            'layout' => (string) ($in['layout'] ?? 'landscape'),
            'subtitles' => !empty($in['subtitles']),
            'hook_text' => (string) ($in['hook_text'] ?? ''),
            'analysis_id' => (string) ($in['analysis_id'] ?? ''),
            'title' => (string) ($in['title'] ?? ''),
            'thumbnail' => (string) ($in['thumbnail'] ?? ''),
        ]);
        respond(['id' => $id]);

    case 'project_create':
        // Buat proyek Clip Studio dari scene hasil analisa yang dipilih
        $in = input();
        $analysisId = (string) ($in['analysis_id'] ?? '');
        $an = valid_job_id($analysisId) ? read_analysis($analysisId) : null;
        if (!$an) {
            respond(['error' => 'Hasil analisa tidak ditemukan.'], 404);
        }
        $scenes = array_values(array_unique(array_map('intval', (array) ($in['scenes'] ?? []))));
        $scenes = array_values(array_filter($scenes, fn($i) => isset($an['clips'][$i])));
        if (!$scenes) {
            respond(['error' => 'Pilih minimal satu scene.'], 400);
        }
        respond(['id' => create_project($analysisId, $an, $scenes)]);

    case 'studio':
        // Satu halaman alur: analisa AI -> unduh sumber -> proyek clip dibuat otomatis -> render -> siap edit.
        // id boleh berupa id analisa (saat masih dianalisa) atau id proyek.
        $id = (string) ($_GET['id'] ?? '');
        $job = valid_job_id($id) ? read_job($id) : null;
        if (!$job || !in_array($job['kind'] ?? '', ['analyze', 'project'], true)) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        if ($job['kind'] === 'project') {
            $pid = $id;
            $aid = (string) ($job['analysis_id'] ?? '');
        } else {
            $aid = $id;
            $pid = project_for_analysis($aid);
        }
        $analysis = valid_job_id($aid) ? read_job($aid) : null;
        // Analisa selesai tapi belum ada proyek: buat otomatis dari semua clip rekomendasi AI
        if (!$pid && $analysis && $analysis['status'] === 'done' && ($an = read_analysis($aid)) && $an['clips']) {
            $lock = fopen(STORAGE_DIR . "/tmp/create-$aid.lock", 'c');
            flock($lock, LOCK_EX);
            $pid = project_for_analysis($aid) ?: create_project($aid, $an, array_keys($an['clips']));
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($analysis) {
            unset($analysis['model']);
        }
        $more = $analysis ? apply_more_clips($aid, $pid) : null;
        $out = ['account' => $analysis && !empty($analysis['account_id']) ? find_account($analysis['account_id']) : null,
            'analysis' => $analysis, 'source' => $analysis ? source_job($aid) : null, 'more' => $more ? array_diff_key($more, ['model' => 1]) : null, 'project' => null, 'jobs' => [], 'prepare' => [], 'edited' => []];
        if ($pid && ($project = read_project($pid))) {
            $out['project'] = $project;
            // Bahasa yang diucapkan di video (dari analisa): video English -> judul & caption bawaan English
            $out['video_lang'] = (string) (read_analysis($project['analysis_id'])['language'] ?? '');
            foreach ($project['items'] as $item) {
                if ($j = read_job($item['job_id'])) {
                    $out['jobs'][$item['job_id']] = $j;
                }
                if (!empty($item['prepare_job']) && ($j = read_job($item['prepare_job']))) {
                    $out['prepare'][$item['iid']] = ['status' => $j['status'], 'progress' => $j['progress'] ?? 0];
                }
                $e = read_edit($pid, $item['iid']);
                $out['edited'][$item['iid']] = $e !== null && (!empty($e['edited']) || isset($e['split']));
                $out['renders'][$item['iid']] = render_history($item);
                $rj = $out['jobs'][$item['job_id']] ?? null; // $j di atas bisa berisi job persiapan
                if ($rj && ($rj['status'] ?? '') === 'done' && !is_file(DOWNLOAD_DIR . '/' . basename((string) ($rj['file'] ?? '')))) {
                    $out['missing'][$item['iid']] = true;
                }
            }
        }
        respond($out);

    case 'project':
        $pid = (string) ($_GET['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $jobs = [];
        foreach ($project['items'] as $item) {
            if ($job = read_job($item['job_id'])) {
                $jobs[$item['job_id']] = $job;
            }
        }
        // Clip yang pernah disimpan dari editor (resep lama tanpa penanda dikenali dari kunci 'split')
        $edited = [];
        foreach ($project['items'] as $item) {
            $e = read_edit($project['id'], $item['iid']);
            $edited[$item['iid']] = $e !== null && (!empty($e['edited']) || isset($e['split']));
        }
        // Skor viral AI (0–100) tiap clip, dari analisa
        $an = read_analysis($project['analysis_id']);
        $scores = [];
        foreach ($project['items'] as $item) {
            $sc = $an['clips'][$item['scene'] ?? -1] ?? null;
            $scores[$item['iid']] = $sc && isset($sc['overall']) ? (int) $sc['overall'] : null;
        }
        respond(['project' => $project, 'jobs' => $jobs, 'edited' => $edited, 'scores' => $scores]);

    case 'project_item':
        // Simpan hasil edit satu clip lalu render ulang
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $key = null;
        foreach ($project['items'] as $k => $item) {
            if ($item['iid'] === ($in['iid'] ?? '')) {
                $key = $k;
            }
        }
        if ($key === null) {
            respond(['error' => 'Clip tidak ditemukan.'], 404);
        }
        $item = $project['items'][$key];
        $item['start'] = max(0, (float) ($in['start'] ?? $item['start']));
        $item['end'] = (float) ($in['end'] ?? $item['end']);
        $item['layout'] = (string) ($in['layout'] ?? $item['layout']);
        $item['subtitles'] = !empty($in['subtitles']);
        $item['hook_text'] = mb_substr(trim((string) ($in['hook_text'] ?? '')), 0, 120);
        if ($item['end'] - $item['start'] < 1 || $item['end'] - $item['start'] > 600) {
            respond(['error' => 'Rentang waktu clip tidak valid.'], 400);
        }
        if ($edit = read_edit($pid, $item['iid'])) {
            if ($item['start'] < $edit['pad'][0] || $item['end'] > $edit['pad'][1]) {
                @unlink(edit_path($pid, $item['iid'])); // di luar rentang proxy: siapkan ulang
            } else {
                $edit['trim'] = [$item['start'], $item['end']];
                $edit['layout'] = $item['layout'];
                $edit['subtitles'] = $item['subtitles'];
                $edit['hook']['text'] = $item['hook_text'];
                $edit['hook']['enabled'] = $item['hook_text'] !== '';
                write_edit($pid, $item['iid'], $edit);
            }
        }
        archive_clip_job($item);
        $item['job_id'] = create_project_job($project, $item);
        $project['items'][$key] = $item;
        write_project($project);
        respond(['ok' => true, 'job_id' => $item['job_id']]);

    case 'project_upload':
        // Tombol "Done upload" di Clip Studio: tandai clip sudah diupload pada tanggal tertentu (tanggal lokal user), atau batalkan
        $in = input();
        [$project, $key] = project_item_or_fail($in);
        $item = $project['items'][$key];
        if (!empty($in['uploaded'])) {
            $date = (string) ($in['date'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                respond(['error' => 'Tanggal upload tidak valid.'], 400);
            }
            $item['uploaded'] = $date;
            $item['uploaded_at'] = $item['uploaded_at'] ?? time();
        } else {
            unset($item['uploaded'], $item['uploaded_at']);
        }
        $project['items'][$key] = $item;
        write_project($project);
        respond(['ok' => true, 'item' => $item]);

    case 'upload_calendar':
        // Kalender upload: semua clip (semua room) yang ditandai sudah diupload pada bulan tertentu (YYYY-MM)
        $month = (string) ($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            respond(['error' => 'Bulan tidak valid.'], 400);
        }
        $clips = [];
        $total = 0;
        $accountOf = [];
        foreach (glob(JOBS_DIR . '/*.json') as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (($j['kind'] ?? '') === 'analyze') {
                $accountOf[$j['id']] = (string) ($j['account_id'] ?? '');
            }
        }
        foreach (glob(PROJECTS_DIR . '/*.json') as $f) {
            $project = json_decode((string) @file_get_contents($f), true);
            foreach ($project['items'] ?? [] as $it) {
                if (empty($it['uploaded'])) {
                    continue;
                }
                $total++;
                if (str_starts_with($it['uploaded'], $month)) {
                    $clips[] = uploaded_clip($project, $it, $accountOf[$project['analysis_id'] ?? ''] ?? '');
                }
            }
        }
        usort($clips, fn($a, $b) => [$a['date'], $a['uploaded_at']] <=> [$b['date'], $b['uploaded_at']]);
        // Hari libur untuk grid bulan ini (termasuk tanggal bulan sebelah yang ikut tampil)
        $from = date('Y-m-d', strtotime("$month-01 -7 days"));
        $to = date('Y-m-d', strtotime("$month-01 +1 month +7 days"));
        respond(['month' => $month, 'clips' => $clips, 'total' => $total, 'accounts' => read_accounts(),
            'holidays' => holidays_between($from, $to), 'overrides' => day_overrides(), 'ready' => ready_by_account(), 'holiday_source' => holiday_source()]);

    case 'upload_today':
        // Pengingat jam upload: info hari ini (tanggal lokal dari browser) + akun & jumlah clip siap upload
        $date = (string) ($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            respond(['error' => 'Tanggal tidak valid.'], 400);
        }
        $h = id_holidays();
        respond(['date' => $date, 'holiday' => $h[$date] ?? null, 'override' => day_overrides()[$date] ?? null,
            'accounts' => read_accounts(), 'ready' => ready_by_account()]);

    case 'day_override':
        // Tandai tanggal sebagai libur (mis. cuti pribadi / libur daerah) atau hari kerja; mode kosong = ikuti kalender
        $in = input();
        $date = (string) ($in['date'] ?? '');
        $mode = (string) ($in['mode'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($mode, ['', 'libur', 'kerja'], true)) {
            respond(['error' => 'Data tidak valid.'], 400);
        }
        respond(['overrides' => set_day_override($date, $mode)]);

    case 'settings':
        respond(['settings' => read_settings(), 'codecs' => CLIP_CODECS]);

    case 'settings_save':
        $codec = (string) (input()['clip_codec'] ?? '');
        if (!isset(CLIP_CODECS[$codec])) {
            respond(['error' => 'Pilihan kompresi tidak dikenal.'], 400);
        }
        respond(['settings' => write_settings(['clip_codec' => $codec])]);

    case 'keys':
        // Status API key (hanya 4 karakter terakhir yang dikirim ke browser)
        $mask = fn(string $k) => $k === '' ? '' : '••••' . substr($k, -4);
        respond(['gemini' => $mask(trim((string) @file_get_contents(STORAGE_DIR . '/gemini.key'))),
            'elevenlabs' => $mask(elevenlabs_key())]);

    case 'keys_save':
        // Simpan API key ke storage/<nama>.key (ditulis oleh Apache sendiri, jadi pasti bisa dibaca). Kosong = hapus.
        $in = input();
        $name = (string) ($in['name'] ?? '');
        $key = trim((string) ($in['key'] ?? ''));
        if (!in_array($name, ['gemini', 'elevenlabs'], true)) {
            respond(['error' => 'Jenis API key tidak dikenal.'], 400);
        }
        if ($key !== '' && !preg_match('/^[\w.-]{20,200}$/', $key)) {
            respond(['error' => 'Format API key tidak valid. Salin ulang tanpa spasi atau tanda kutip.'], 400);
        }
        if ($key !== '') {
            // Cek ke server sebelum disimpan supaya key yang salah langsung ketahuan
            [$url, $hdr] = $name === 'gemini'
                ? [GEMINI_BASE . '/v1beta/models?pageSize=1', 'x-goog-api-key: ' . $key]
                : ['https://api.elevenlabs.io/v1/user/subscription', 'xi-api-key: ' . $key];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [$hdr], CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10]);
            $ok = curl_exec($ch) !== false;
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$ok) {
                respond(['error' => 'Tidak bisa menghubungi server untuk mengecek key. Periksa internet lalu coba lagi.'], 400);
            }
            if (in_array($code, [400, 401, 403], true)) {
                respond(['error' => 'API key ditolak server. Pastikan key yang disalin benar dan masih aktif.'], 400);
            }
        }
        $file = STORAGE_DIR . "/$name.key";
        if ($key === '') {
            @unlink($file);
        } elseif (@file_put_contents($file, $key . "\n", LOCK_EX) === false) {
            respond(['error' => 'Gagal menyimpan key: folder storage tidak bisa ditulis. Jalankan ulang install/setup-mac.sh.'], 500);
        }
        respond(['ok' => true]);

    case 'accounts':
        // Daftar akun + ringkasan: jumlah room, clip, clip sudah diupload, upload terakhir
        $stats = [];
        foreach (rooms_index() as $r) {
            $acc = (string) ($r['job']['account_id'] ?? '');
            $s = $stats[$acc] ?? ['rooms' => 0, 'clips' => 0, 'uploaded' => 0, 'last_upload' => ''];
            $s['rooms']++;
            foreach ($r['project']['items'] ?? [] as $it) {
                $s['clips']++;
                if (!empty($it['uploaded'])) {
                    $s['uploaded']++;
                    $s['last_upload'] = max($s['last_upload'], $it['uploaded']);
                }
            }
            $stats[$acc] = $s;
        }
        $empty = ['rooms' => 0, 'clips' => 0, 'uploaded' => 0, 'last_upload' => ''];
        respond(['accounts' => array_map(fn($a) => $a + ($stats[$a['id']] ?? $empty), read_accounts()),
            'unassigned' => $stats[''] ?? $empty, 'colors' => ACCOUNT_COLORS]);

    case 'account_save':
        // Buat / ubah akun: nama wajib, @username & niche opsional
        $in = input();
        $name = trim((string) ($in['name'] ?? ''));
        $handle = ltrim(trim((string) ($in['handle'] ?? '')), '@');
        $niche = trim((string) ($in['niche'] ?? ''));
        if ($name === '' || mb_strlen($name) > 40) {
            respond(['error' => 'Isi nama akun (maksimal 40 karakter).'], 400);
        }
        if ($handle !== '' && !preg_match('/^[\w.-]{1,40}$/u', $handle)) {
            respond(['error' => 'Username hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung (-).'], 400);
        }
        $list = read_accounts();
        $color = in_array($in['color'] ?? '', ACCOUNT_COLORS, true) ? $in['color'] : ACCOUNT_COLORS[count($list) % count(ACCOUNT_COLORS)];
        $fields = ['name' => $name, 'handle' => $handle, 'niche' => mb_substr($niche, 0, 60), 'color' => $color];
        $id = (string) ($in['id'] ?? '');
        if ($id !== '') {
            $found = false;
            foreach ($list as $k => $a) {
                if ($a['id'] === $id) {
                    $list[$k] = $fields + $a;
                    $found = $list[$k];
                }
            }
            if (!$found) {
                respond(['error' => 'Akun tidak ditemukan.'], 404);
            }
            write_accounts($list);
            respond(['ok' => true, 'account' => $found]);
        }
        $acc = ['id' => bin2hex(random_bytes(6))] + $fields + ['style' => '', 'created_at' => time()];
        $list[] = $acc;
        write_accounts($list);
        respond(['ok' => true, 'account' => $acc]);

    case 'account_avatar':
        // Foto profil akun: dipotong persegi di tengah, diperkecil ke 400x400 JPG. Tanpa file = hapus foto.
        $id = (string) ($_POST['id'] ?? input()['id'] ?? '');
        $acc = find_account($id);
        if (!$acc) {
            respond(['error' => 'Akun tidak ditemukan.'], 404);
        }
        $url = '';
        if (!empty($_FILES['file'])) {
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 15 * 1024 * 1024) {
                respond(['error' => 'Foto gagal diunggah (maksimal 15 MB).'], 400);
            }
            $info = @getimagesize($f['tmp_name']);
            if (!in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
                respond(['error' => 'Format foto harus JPG, PNG, WEBP, atau GIF.'], 400);
            }
            $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
            if (!$src) {
                respond(['error' => 'Foto tidak bisa dibaca.'], 400);
            }
            [$w, $h] = [imagesx($src), imagesy($src)];
            $side = min($w, $h);
            $dst = imagecreatetruecolor(400, 400);
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 400, 400, $side, $side);
            if (!is_dir(AVATAR_DIR)) {
                @mkdir(AVATAR_DIR, 0777, true);
                @chmod(AVATAR_DIR, 0777);
            }
            $name = $id . '-' . time() . '.jpg'; // nama baru tiap ganti foto supaya cache browser ikut berganti
            if (!@imagejpeg($dst, AVATAR_DIR . "/$name", 88)) {
                respond(['error' => 'Foto gagal disimpan. Pastikan folder avatars bisa ditulis.'], 500);
            }
            @chmod(AVATAR_DIR . "/$name", 0666);
            $url = "avatars/$name";
        }
        if (!empty($acc['avatar']) && str_starts_with($acc['avatar'], 'avatars/')) {
            @unlink(APP_DIR . '/' . $acc['avatar']);
        }
        write_accounts(array_map(fn($a) => $a['id'] === $id ? ['avatar' => $url] + $a : $a, read_accounts()));
        respond(['ok' => true, 'avatar' => $url]);

    case 'clip_thumb':
        // Thumbnail feed dari file clip hasil render (frame detik ke-1), disimpan per job render.
        // Tetap tersedia setelah room dibersihkan (file clip boleh sudah dihapus).
        [$project, $key] = project_item_or_fail($_GET);
        $job = read_job($project['items'][$key]['job_id']);
        $thumb = $job ? clip_thumb_file($job) : null;
        if (!$thumb) {
            respond(['error' => 'Clip belum selesai dirender.'], 404);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=31536000, immutable');
        readfile($thumb);
        exit;

    case 'account_delete':
        // Hapus akun: room & clip-nya tetap ada, hanya jadi "tanpa akun"
        $id = (string) (input()['id'] ?? '');
        if (!empty(find_account($id)['avatar'])) {
            @unlink(APP_DIR . '/' . find_account($id)['avatar']);
        }
        write_accounts(array_filter(read_accounts(), fn($a) => $a['id'] !== $id));
        foreach (rooms_index() as $aid => $r) {
            if (($r['job']['account_id'] ?? '') === $id) {
                update_job($aid, ['account_id' => '']);
            }
        }
        respond(['ok' => true]);

    case 'account_view':
        // Profil akun: room milik akun, clip yang sudah diupload (grid seperti profil sosmed) & antrean siap upload
        $id = (string) ($_GET['id'] ?? '');
        $acc = find_account($id);
        if (!$acc) {
            respond(['error' => 'Akun tidak ditemukan.'], 404);
        }
        $rooms = $posts = $queue = [];
        $clipCount = 0;
        foreach (rooms_index() as $aid => $r) {
            if (($r['job']['account_id'] ?? '') !== $id) {
                continue;
            }
            $p = $r['project'];
            $items = $p['items'] ?? [];
            $clipCount += count($items);
            $rooms[] = ['id' => $p['id'] ?? $aid, 'analysis_id' => $aid, 'title' => $r['job']['title'] ?? '', 'thumbnail' => $r['job']['thumbnail'] ?? '',
                'created_at' => $r['job']['created_at'] ?? 0, 'status' => $r['job']['status'] ?? '', 'style' => $r['job']['style'] ?? 'podcast',
                'clips' => count($items), 'uploaded' => count(array_filter($items, fn($it) => !empty($it['uploaded'])))];
            foreach ($items as $it) {
                if (!empty($it['uploaded'])) {
                    $posts[] = uploaded_clip($p, $it, $id);
                } elseif (($job = read_job($it['job_id'])) && $job['status'] === 'done') {
                    $queue[] = ['pid' => $p['id'], 'iid' => $it['iid'], 'analysis_id' => $aid, 'title' => $it['title'],
                        'room' => $p['video']['title'] ?? '', 'start' => $it['start'], 'end' => $it['end'], 'file' => $job['file']];
                }
            }
        }
        usort($posts, fn($a, $b) => [$b['date'], $b['uploaded_at']] <=> [$a['date'], $a['uploaded_at']]);
        usort($rooms, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        respond(['account' => $acc, 'rooms' => $rooms, 'posts' => $posts, 'queue' => $queue, 'clips' => $clipCount, 'colors' => ACCOUNT_COLORS]);

    case 'hook_voice':
        // Editor → Intro hook: buat (atau ambil dari cache) voice over teks hook, untuk didengar sebelum render
        $in = input();
        [$project] = project_item_or_fail($in);
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 120);
        $voice = (string) ($in['voice'] ?? 'Puck');
        if ($text === '') {
            respond(['error' => 'Isi teks hook dulu.'], 400);
        }
        if (!hook_voice_ok($voice)) {
            respond(['error' => 'Suara tidak dikenal.'], 400);
        }
        $style = (string) ($in['style'] ?? 'auto');
        $script = mb_substr(trim((string) ($in['script'] ?? '')), 0, 300);
        try {
            [$file, $dur, $said] = hook_tts($text, $voice, hook_voice_dir($project['id']), '', $style, $script);
        } catch (Throwable $e) {
            respond(['error' => $e->getMessage()], 502);
        }
        respond(['file' => basename($file), 'dur' => round($dur, 2), 'said' => $said]);

    case 'tts_script':
        // Menu Intro: naskah suara dirapikan AI (disiapkan lebih dulu supaya Dengar / Generate tidak menunggu lama)
        $in = input();
        [$project] = project_item_or_fail($in);
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 120);
        if ($text === '') {
            respond(['error' => 'Isi teks hook dulu.'], 400);
        }
        $dir = hook_voice_dir($project['id']);
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
        [$script, $tag] = tts_script($text, (string) ($in['style'] ?? 'auto'), '', $dir);
        respond(['script' => $script, 'tag' => $tag]);

    case 'intro_frame':
        // Menu Intro di editor: frame resolusi penuh dari video sumber di detik t (waktu video asli).
        // Dibingkai di editor dengan rumus preview yang sama (ikuti pembicara, split, blur, bingkai) lalu ditimpa grafik hook.
        [$project, $key] = project_item_or_fail($_GET);
        $item = $project['items'][$key];
        $edit = read_edit($project['id'], $item['iid']);
        if (!$edit) {
            respond(['error' => 'Editor clip ini belum siap.'], 409);
        }
        [$p0, $p1] = $edit['pad'];
        $t = round(max($p0, min($p1 - 0.05, (float) ($_GET['t'] ?? $p0))), 2);
        $dir = edit_dir($project['id']) . '/intro';
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
        // Sumber lokal 1080p; kalau belum/tidak ada, pakai video preview editor (resolusi rendah). Jenis sumber ikut kunci cache.
        $src = local_source($project['analysis_id'], $t, $t + 0.1);
        $out = "$dir/frame_" . md5($item['iid'] . '|' . $t . '|' . ($src ? 'src' : 'proxy')) . '.jpg';
        if (!is_file($out)) {
            [$file, $at] = $src ? [$src['path'], $t - $src['shift']] : [APP_DIR . '/' . proxy_rel($project['id'], $item['iid']), $t - $p0];
            exec(escapeshellarg(FFMPEG) . ' -hide_banner -nostdin -y -loglevel error -ss ' . number_format(max(0, $at), 3, '.', '') . ' -i ' . escapeshellarg($file)
                . ' -frames:v 1 -q:v 2 ' . escapeshellarg($out) . ' 2>&1', $tail, $code);
            if ($code !== 0 || !is_file($out)) {
                respond(['error' => 'Gagal mengambil frame clip.'], 500);
            }
            @chmod($out, 0666);
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($out));
        header('Cache-Control: private, max-age=86400');
        readfile($out);
        exit;

    case 'tts_voices':
        respond(tts_voices());

    case 'hook_voice_file':
        [$project] = project_item_or_fail($_GET);
        $name = (string) ($_GET['f'] ?? '');
        $path = hook_voice_dir($project['id']) . '/' . $name;
        if (!preg_match('/^hookvo_[a-f0-9]{32}\.wav$/', $name) || !is_file($path)) {
            respond(['error' => 'Suara tidak ditemukan.'], 404);
        }
        header('Content-Type: audio/wav');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;

    case 'edit_proxy_hd':
        // Preview editor lama (480p) dibuat ulang 720p dari video sumber lokal; resep edit tidak berubah
        $in = input();
        [$project, $key] = project_item_or_fail($in);
        $item = $project['items'][$key];
        @set_time_limit(320);
        $done = upgrade_proxy($project, $item['iid']);
        $file = APP_DIR . '/' . proxy_rel($project['id'], $item['iid']);
        respond(['upgraded' => $done, 'proxy' => proxy_rel($project['id'], $item['iid']) . '?v=' . (is_file($file) ? filemtime($file) : 0)]);

    case 'edit_get':
        [$project, $key] = project_item_or_fail($_GET);
        $item = $project['items'][$key];
        $edit = read_edit($project['id'], $item['iid']);
        $proxyFile = APP_DIR . '/' . proxy_rel($project['id'], $item['iid']);
        if ($edit && is_file($proxyFile)) {
            // Resep sementara (subtitle/wajah masih diproses): sertakan status persiapan & jadikan prioritas
            $prepJob = null;
            if (!empty($edit['pending'])) {
                foreach ([$item['prepare_job'] ?? '', $item['job_id']] as $jid) {
                    $j = $jid ? read_job($jid) : null;
                    if ($j && in_array($j['status'], ['queued', 'downloading', 'converting'], true)) {
                        $prepJob = empty($j['priority']) && ($j['mode'] ?? '') === 'prepare' ? update_job($jid, ['priority' => true]) : $j;
                        break;
                    }
                }
            }
            // Rentang timeline = rekomendasi AI (diperluas kalau trim sudah melewatinya, supaya editan tidak hilang)
            $scene = read_analysis($project['analysis_id'])['clips'][$item['scene']] ?? null;
            $b0 = $scene ? (float) $scene['start'] : $edit['trim'][0];
            $b1 = $scene ? (float) $scene['end'] : $edit['trim'][1];
            $edit['base'] = [max($edit['pad'][0], min($b0, $edit['trim'][0])), min($edit['pad'][1], max($b1, $edit['trim'][1]))];
            respond(['edit' => $edit, 'item' => $item, 'video' => $project['video'], 'analysis_id' => $project['analysis_id'],
                'proxy' => proxy_rel($project['id'], $item['iid']) . '?v=' . filemtime($proxyFile),
                'prep_stage' => $prepJob['stage'] ?? '']);
        }
        if (!empty($_GET['peek'])) {
            // Hanya mengintip (prefetch dari daftar clip di editor): jangan memulai persiapan
            respond(['preparing' => true, 'peek' => true, 'item' => $item]);
        }
        ensure_source($project['analysis_id']);
        // Belum ada resep: pakai job persiapan clip ini dan jadikan prioritas (clip yang sedang dibuka didahulukan)
        $prep = !empty($item['prepare_job']) ? read_job($item['prepare_job']) : null;
        $active = $prep && in_array($prep['status'], ['queued', 'downloading', 'converting'], true);
        if ($prep && $prep['status'] === 'error' && empty($_GET['retry'])) {
            respond(['preparing' => false, 'error' => $prep['error'] ?? 'Gagal menyiapkan editor.', 'item' => $item]);
        }
        if ($active) {
            if (empty($prep['priority'])) {
                $prep = update_job($prep['id'], ['priority' => true]);
            }
        } else {
            $project['items'][$key]['prepare_job'] = create_prepare_job($project, $item, true);
            write_project($project);
            $prep = read_job($project['items'][$key]['prepare_job']);
        }
        $waitFor = $prep;
        $src = source_job($project['analysis_id']);
        respond(['preparing' => true, 'job' => $waitFor, 'item' => $item,
            'source' => $src ? ['status' => $src['status'], 'progress' => $src['progress'] ?? 0] : null]);

    case 'edit_reset':
        // Buang semua editan clip: resep & proxy dihapus, lalu clip disiapkan & dirender ulang dari hasil AI
        $in = input();
        [$project, $key] = project_item_or_fail($in);
        $item = $project['items'][$key];
        $an = read_analysis($project['analysis_id']);
        $scene = $an['clips'][$item['scene']] ?? null;
        if ($scene) {
            $item['start'] = $scene['start'];
            $item['end'] = $scene['end'];
            $item['hook_text'] = $scene['on_screen_text'];
        }
        $item['layout'] = 'speaker';
        $item['subtitles'] = true;
        @unlink(edit_path($project['id'], $item['iid']));
        @unlink(APP_DIR . '/' . proxy_rel($project['id'], $item['iid']));
        archive_clip_job($item);
        $item['prepare_job'] = create_prepare_job($project, $item, false);
        $item['job_id'] = create_project_job($project, $item);
        $project['items'][$key] = $item;
        write_project($project);
        respond(['ok' => true, 'job_id' => $item['job_id']]);

    case 'edit_track':
        // Hitung ulang data shot/wajah (resep lama belum punya data split screen)
        [$project, $key] = project_item_or_fail(input());
        set_time_limit(180);
        try {
            $edit = retrack_edit($project['id'], $project['items'][$key]['iid']);
        } catch (RuntimeException $e) {
            respond(['error' => $e->getMessage()], 500);
        }
        respond(['segments' => $edit['segments'], 'shots' => $edit['shots'], 'cam' => $edit['cam'] ?? null, 'precise' => true]);

    case 'overlay_upload':
        [$name, $w, $h] = save_image_upload();
        respond(['file' => $name, 'w' => $w, 'h' => $h]);

    case 'kinetic':
        // Teks kinetik: susun beat dari kata subtitle (kalau belum ada), lalu layout tiap beat untuk preview editor
        $in = input();
        $y = (float) ($in['y'] ?? 0.3);
        $x = (float) ($in['x'] ?? 0.5);
        if (isset($in['beats'])) {
            $k = kinetic_sanitize(['beats' => $in['beats'], 'y' => $y], 0, 1e7);
        } else {
            $t0 = (float) ($in['trim'][0] ?? 0);
            $t1 = (float) ($in['trim'][1] ?? 0);
            $k = ['beats' => kinetic_beats((array) ($in['lines'] ?? []), $t0, $t1), 'y' => $y];
        }
        respond(['beats' => $k['beats'], 'layout' => array_map(fn($b) => kinetic_layout($b, $y, $x), $k['beats'])]);

    case 'music_list':
        respond(['music' => read_music_library()]);

    case 'music_upload':
        [$name, $dur] = save_music_upload();
        $title = trim(preg_replace('/\.\w+$/', '', basename((string) ($_FILES['file']['name'] ?? 'Musik'))));
        respond(['music' => music_library_put($name, $title, $dur, 'upload')]);

    case 'sfx_list':
        respond(['sfx' => sfx_library(), 'ai' => elevenlabs_key() !== '']);

    case 'sfx_generate':
        // Menu Sound effect → Buat dengan AI (ElevenLabs Sound Effects). Hasilnya masuk pustaka SFX semua proyek.
        $in = input();
        $text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 450);
        if ($text === '') {
            respond(['error' => 'Tulis deskripsi suaranya dulu.'], 400);
        }
        $dur = is_numeric($in['dur'] ?? null) && (float) $in['dur'] > 0 ? (float) $in['dur'] : null;
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80) ?: mb_substr($text, 0, 40);
        $tag = ($in['tag'] ?? '') === 'paper' ? 'paper' : '';
        try {
            $item = sfx_generate_ai($text, $dur, $name, $tag);
        } catch (Throwable $e) {
            respond(['error' => $e->getMessage()], 502);
        }
        respond(['sfx' => sfx_library(), 'item' => $item]);

    case 'sfx_upload':
        // SFX unggahan user: audio pendek (maks. 60 dtk), disimpan di pustaka SFX untuk semua proyek
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            respond(['error' => 'Sound effect gagal diunggah.'], 400);
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        $ext = ['mpeg' => 'mp3', 'mpga' => 'mp3'][$ext] ?? $ext;
        if (!in_array($ext, ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'flac'], true)) {
            respond(['error' => 'Format sound effect harus MP3, M4A, AAC, WAV, OGG, atau FLAC.'], 400);
        }
        $info = json_decode((string) shell_exec(escapeshellarg(FFPROBE) . ' -v error -select_streams a:0 -show_entries stream=codec_type'
            . ' -show_entries format=duration -of json ' . escapeshellarg($f['tmp_name'])), true) ?: [];
        $dur = round((float) ($info['format']['duration'] ?? 0), 3);
        if (($info['streams'][0]['codec_type'] ?? '') !== 'audio' || $dur < 0.02) {
            respond(['error' => 'File sound effect tidak bisa dibaca.'], 400);
        }
        if ($dur > 60) {
            respond(['error' => 'Durasi sound effect maksimal 60 detik. Untuk lagu, pakai menu Musik.'], 400);
        }
        sfx_library(); // pastikan folder & SFX bawaan ada
        $name = sha1_file($f['tmp_name']) . ".$ext";
        if (!is_file(SFX_DIR . "/$name") && !move_uploaded_file($f['tmp_name'], SFX_DIR . "/$name")) {
            respond(['error' => 'Sound effect gagal disimpan.'], 500);
        }
        @chmod(SFX_DIR . "/$name", 0666);
        $user = json_decode((string) @file_get_contents(STORAGE_DIR . '/sfx.json'), true) ?: [];
        $user = array_values(array_filter($user, fn($x) => ($x['file'] ?? '') !== $name));
        $user[] = ['file' => $name, 'name' => mb_substr(trim(preg_replace('/\.\w+$/', '', basename((string) $f['name']))) ?: 'Sound effect', 0, 80), 'dur' => $dur];
        write_sfx_uploads($user);
        respond(['sfx' => sfx_library()]);

    case 'sfx_delete':
        // Hanya SFX unggahan yang bisa dihapus; SFX bawaan tetap
        $file = (string) (input()['file'] ?? '');
        $user = json_decode((string) @file_get_contents(STORAGE_DIR . '/sfx.json'), true) ?: [];
        if (!in_array($file, array_column($user, 'file'), true)) {
            respond(['error' => 'Sound effect bawaan tidak bisa dihapus.'], 400);
        }
        write_sfx_uploads(array_filter($user, fn($x) => $x['file'] !== $file));
        @unlink(SFX_DIR . '/' . basename($file));
        respond(['sfx' => sfx_library()]);

    case 'music_rename':
        $in = input();
        $title = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80));
        if ($title === '') {
            respond(['error' => 'Nama musik tidak boleh kosong.'], 400);
        }
        $lib = array_map(fn($m) => $m['file'] === ($in['file'] ?? '') ? ['name' => $title] + $m : $m, read_music_library());
        write_music_library($lib);
        respond(['music' => $lib]);

    case 'music_delete':
        // Hapus dari pustaka saja; file tetap ada karena bisa masih dipakai clip yang sudah diedit
        $in = input();
        $lib = array_values(array_filter(read_music_library(), fn($m) => $m['file'] !== ($in['file'] ?? '')));
        write_music_library($lib);
        respond(['music' => $lib]);

    case 'media_list':
        // Pustaka media (gambar) milik satu proyek, dipakai di panel Media editor
        $pid = (string) ($_GET['id'] ?? '');
        if (!valid_job_id($pid) || !read_project($pid)) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        respond(['media' => read_media_library($pid)]);

    case 'media_upload':
        $pid = (string) ($_POST['id'] ?? '');
        if (!valid_job_id($pid) || !read_project($pid)) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        // Gambar (overlay / footage) atau video (footage)
        $isVideo = str_starts_with((string) @mime_content_type($_FILES['file']['tmp_name'] ?? ''), 'video/')
            && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK;
        if ($isVideo) {
            [$name, $w, $h, $dur] = save_video_upload();
        } else {
            [$name, $w, $h] = save_image_upload();
        }
        $lib = array_values(array_filter(read_media_library($pid), fn($m) => $m['file'] !== $name));
        array_unshift($lib, ['file' => $name, 'name' => mb_substr(basename((string) ($_FILES['file']['name'] ?? 'media')), 0, 80),
            'w' => $w, 'h' => $h, 'added' => time()] + ($isVideo ? ['kind' => 'video', 'dur' => $dur, 'thumb' => preg_replace('/\.\w+$/', '.thumb.jpg', $name)] : ['kind' => 'image']));
        write_media_library($pid, $lib);
        respond(['media' => $lib]);

    case 'media_delete':
        // Hapus dari pustaka saja; file tetap ada karena bisa masih dipakai clip lain
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        if (!valid_job_id($pid) || !read_project($pid)) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $lib = array_values(array_filter(read_media_library($pid), fn($m) => $m['file'] !== ($in['file'] ?? '')));
        write_media_library($pid, $lib);
        respond(['media' => $lib]);

    case 'edit_save':
    case 'edit_render':
        $in = input();
        [$project, $key] = project_item_or_fail($in);
        $item = $project['items'][$key];
        $current = read_edit($project['id'], $item['iid']);
        if (!$current) {
            respond(['error' => 'Data editor belum siap.'], 409);
        }
        try {
            $edit = sanitize_edit((array) ($in['edit'] ?? []), $current);
        } catch (RuntimeException $e) {
            respond(['error' => $e->getMessage()], 400);
        }
        write_edit($project['id'], $item['iid'], $edit);
        // Data kartu di Studio ikut diperbarui
        $item['start'] = $edit['trim'][0];
        $item['end'] = $edit['trim'][1];
        $item['layout'] = $edit['layout'];
        $item['split'] = !empty($edit['split']['enabled']);
        $item['subtitles'] = $edit['subtitles'];
        $item['hook_text'] = $edit['hook']['enabled'] ? $edit['hook']['text'] : '';
        if ($action === 'edit_render') {
            archive_clip_job($item);
            $item['job_id'] = create_project_job($project, $item, ['no_music' => !empty($in['no_music']), 'both_music' => !empty($in['both_music'])]);
        }
        $project['items'][$key] = $item;
        write_project($project);
        respond(['ok' => true, 'job_id' => $item['job_id'], 'edit' => $edit]);

    case 'captions_en':
        // Versi English judul, caption & hashtag (kata kunci bahasa Inggris) untuk semua clip di proyek.
        // Satu permintaan teks ke AI; hasil disimpan di item['en'] dan dipakai tombol salin di Studio / halaman akun.
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        if (trim((string) @file_get_contents(STORAGE_DIR . '/gemini.key')) === '') {
            respond(['error' => 'API key Gemini belum diisi. Buka Pengaturan → API key.'], 400);
        }
        require_once __DIR__ . '/gemini.php';
        set_time_limit(180);
        $an = read_analysis($project['analysis_id']) ?? [];
        $style = (read_job($project['analysis_id'])['style'] ?? '') === 'gaming' ? 'gaming' : 'podcast';
        $only = array_filter((array) ($in['iids'] ?? []), 'is_string');
        $rows = [];
        foreach ($project['items'] as $it) {
            if ($only && !in_array($it['iid'], $only, true)) {
                continue;
            }
            $sc = $an['clips'][$it['scene'] ?? -1] ?? [];
            $rows[] = ['id' => $it['iid'], 'type' => ($it['concept'] ?? '') ?: ($sc['concept'] ?? '') ?: ($sc['category'] ?? ''),
                'title' => $it['title'], 'caption' => $it['caption'] ?? '', 'hashtags' => $it['hashtags'] ?? [],
                'quote' => $sc['quote'] ?? '', 'hook' => $sc['hook'] ?? ''];
        }
        if (!$rows) {
            respond(['error' => 'Tidak ada clip untuk dibuatkan versi English.'], 400);
        }
        $niche = $style === 'gaming'
            ? 'gaming / streaming highlight clips (keywords like the game name, gaming, gameplay, funny moments, clutch, streamer)'
            : 'podcast clips about motivation, mindset, self-improvement, success, life lessons';
        $schema = ['type' => 'OBJECT', 'properties' => ['clips' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'id' => ['type' => 'STRING'], 'title' => ['type' => 'STRING'], 'caption' => ['type' => 'STRING'],
            'hashtags' => ['type' => 'STRING', 'description' => '4-6 English hashtags separated by single spaces, e.g. "#mindset #success #fyp"']],
            'required' => ['id', 'title', 'caption', 'hashtags']]]], 'required' => ['clips']];
        $byId = [];
        // Maksimal 3 putaran: clip yang tidak terjawab / jawabannya rusak diminta ulang
        for ($round = 0; $round < 3 && count($byId) < count($rows); $round++) {
            $todo = array_values(array_filter($rows, fn($r) => !isset($byId[$r['id']])));
            $prompt = "You are a native English short-form content editor (TikTok, Instagram Reels, YouTube Shorts) for $niche.\n"
                . "Video: " . ($project['video']['title'] ?? '') . "\n\n"
                . "For EVERY clip below (" . count($todo) . " clips) write an English version for posting. Do NOT translate word for word: rewrite naturally like a native creator.\n"
                . "- title: catchy, curiosity-driven, max 70 characters, include 1-2 searchable English keywords about the topic.\n"
                . "- caption: max 150 characters, natural English, may use 1-2 emojis, NO hashtags.\n"
                . "- hashtags: one string with 4-6 English hashtags separated by spaces, keyword-rich and searchable (topic keywords + broad tags like #fyp #shorts).\n"
                . "Match the TONE of each clip to its \"type\" (and the actual content), not to the channel niche in general:\n"
                . "  funny / laugh / fail -> playful, entertaining, relatable, emojis like 😂🤣😭, invite people to tag a friend; NEVER preachy or wise-sounding.\n"
                . "  wise / meaningful / mindset -> reflective and short, invite to save it.\n"
                . "  viral / controversial statement -> bold, end with a question that sparks comments (\"Agree or not?\").\n"
                . "  emotional / story -> empathetic, personal, curiosity.  educational / insight -> promise a concrete takeaway.\n"
                . "  motivation -> energetic, direct call to action.  tense / clutch / jumpscare / reaction (gaming) -> hype and dramatic.\n"
                . (($cs = read_job($project['analysis_id'])['caption_style'] ?? 'auto') !== 'auto' && isset(CAPTION_STYLES[$cs])
                    ? "OVERRIDE: the user chose ONE caption style for every clip: \"" . CAPTION_STYLES[$cs][0] . "\" (" . CAPTION_STYLES[$cs][2] . "). Apply this style in natural English to all titles & captions, while staying relevant to each clip.\n" : '')
                . "Keep the meaning of the original (Indonesian) title/caption and the spoken quote. Return exactly one entry per clip with the same id.\n\n"
                . json_encode($todo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            try {
                [$res] = gemini_generate([['text' => $prompt]], $schema, JOBS_DIR . '/captions-en.cancel');
            } catch (Throwable $e) {
                if (!$byId) {
                    respond(['error' => $e->getMessage()], 502);
                }
                break;
            }
            foreach ($res['clips'] ?? [] as $c) {
                $cid = (string) ($c['id'] ?? '');
                preg_match_all('/#[\p{L}\p{N}_]+/u', (string) ($c['hashtags'] ?? ''), $m);
                $title = trim((string) ($c['title'] ?? ''));
                // Jawaban rusak (mis. JSON bocor ke dalam teks) dilewati supaya diminta ulang
                if ($cid === '' || $title === '' || str_contains($title . ($c['caption'] ?? ''), '","') || !$m[0]) {
                    continue;
                }
                $byId[$cid] = ['title' => mb_substr($title, 0, 100),
                    'caption' => mb_substr(trim(preg_replace('/\s*#[\p{L}\p{N}_]+/u', '', (string) $c['caption'])), 0, 300),
                    'hashtags' => array_slice(array_values(array_unique($m[0])), 0, 8)];
            }
        }
        // Proyek dibaca ulang supaya perubahan lain selama AI bekerja tidak tertimpa
        $project = read_project($pid);
        $n = 0;
        foreach ($project['items'] as $k => $it) {
            if (!empty($byId[$it['iid']]['title'])) {
                $project['items'][$k]['en'] = $byId[$it['iid']];
                $n++;
            }
        }
        write_project($project);
        respond(['ok' => true, 'count' => $n, 'items' => array_map(fn($it) => ['iid' => $it['iid'], 'en' => $it['en'] ?? null], $project['items'])]);

    case 'presets':
        respond(['presets' => read_presets()]);

    case 'preset_save':
        // Simpan preset baru, atau timpa preset yang ada (id) dengan style clip yang sedang diedit
        $in = input();
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 60));
        $edit = $in['edit'] ?? null;
        if (!is_array($edit) || strlen(json_encode($edit)) > 400000) {
            respond(['error' => 'Data preset tidak valid.'], 400);
        }
        $list = read_presets();
        $id = preg_replace('/[^a-z0-9]/', '', (string) ($in['id'] ?? ''));
        $found = false;
        foreach ($list as &$p) {
            if ($id !== '' && $p['id'] === $id) {
                $p = ['name' => $name !== '' ? $name : $p['name'], 'edit' => $edit, 'updated_at' => time()] + $p;
                $found = true;
            }
        }
        unset($p);
        if (!$found) {
            if ($name === '') {
                respond(['error' => 'Nama preset wajib diisi.'], 400);
            }
            if (count($list) >= 100) {
                respond(['error' => 'Maksimal 100 preset. Hapus preset yang tidak dipakai dulu.'], 400);
            }
            array_unshift($list, ['id' => bin2hex(random_bytes(5)), 'name' => $name, 'edit' => $edit, 'created_at' => time(), 'updated_at' => time()]);
        }
        write_presets($list);
        respond(['presets' => $list]);

    case 'preset_rename':
        $in = input();
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 60));
        if ($name === '') {
            respond(['error' => 'Nama preset wajib diisi.'], 400);
        }
        $list = array_map(fn($p) => $p['id'] === ($in['id'] ?? '') ? ['name' => $name] + $p : $p, read_presets());
        write_presets($list);
        respond(['presets' => $list]);

    case 'preset_delete':
        $in = input();
        $list = array_values(array_filter(read_presets(), fn($p) => $p['id'] !== ($in['id'] ?? '')));
        write_presets($list);
        respond(['presets' => $list]);

    case 'room_cleanup_info':
        // Ukuran file berat room yang bisa dibersihkan (untuk kartu & modal di Studio)
        $pid = (string) ($_GET['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $g = room_heavy_files($project);
        // Hapus video sumber saja: perkiraan ukuran potongan sumber yang tetap disimpan
        $aid = $project['analysis_id'];
        $srcFile = source_file($aid);
        $srcBytes = is_file($srcFile) ? filesize($srcFile) : 0;
        $keep = 0;
        if ($srcBytes && ($dur = (float) (source_job($aid)['duration'] ?? 0)) > 0) {
            $cover = 0;
            $last = -1;
            $rs = room_source_ranges($aid);
            usort($rs, fn($a, $b) => $a[0] <=> $b[0]);
            foreach ($rs as [$a, $b]) {
                $a = max($a, $last);
                if ($b > $a) {
                    $cover += $b - $a;
                    $last = $b;
                }
            }
            $keep = (int) ($srcBytes * min(1, $cover / $dur));
        }
        respond(['sizes' => array_map(fn($x) => ['bytes' => $x['bytes'], 'count' => count($x['files'])], $g),
            'all_uploaded' => room_all_uploaded($aid), 'cleaned' => $project['cleaned'] ?? null,
            'source_only' => ['bytes' => $srcBytes, 'keep' => $keep], 'slim' => $project['slim'] ?? null]);

    case 'room_finish':
        // Tandai room selesai (tidak aktif): di beranda dipindah ke bagian "Room selesai". done=false = aktifkan lagi.
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $finished = !empty($in['done']) ? time() : null;
        foreach (room_projects($project['analysis_id']) as $p) {
            $p['finished'] = $finished;
            write_project($p);
            if (read_job($p['id'])) {
                update_job($p['id'], ['finished' => $finished]);
            }
        }
        respond(['ok' => true, 'finished' => $finished]);

    case 'room_slim':
        // Hapus video sumber lengkap saja (lihat slim_room)
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        set_time_limit(300);
        try {
            respond(['ok' => true, 'slim' => slim_room($project['analysis_id'])]);
        } catch (Throwable $e) {
            respond(['error' => $e->getMessage()], 409);
        }

    case 'storage_info':
        // Halaman Pengaturan → Manajemen storage
        respond(storage_info());

    case 'storage_slim_all':
        // Hapus video sumber di semua room. Room yang punya clip: potongan di sekitar clip disimpan (edit & render tetap jalan).
        // Room tanpa clip / sudah dihapus: video sumbernya dihapus begitu saja (diunduh ulang kalau nanti dibuat clip).
        // ids: hanya room yang dipilih (kosong = semua room)
        set_time_limit(0);
        $in = input();
        if (array_key_exists('ids', $in) && !$in['ids']) {
            respond(['error' => 'Pilih minimal satu room.'], 400);
        }
        $only = array_flip(array_map('strval', (array) ($in['ids'] ?? [])));
        $pick = fn($aid) => !$only || isset($only[$aid]);
        $freed = 0;
        $done = 0;
        $skipped = [];
        foreach (glob(SOURCES_DIR . '/*.mp4') ?: [] as $f) {
            $aid = basename($f, '.mp4');
            if (!$pick($aid)) {
                continue;
            }
            $title = read_job($aid)['title'] ?? $aid;
            try {
                if (room_projects($aid)) {
                    $freed += slim_room($aid)['freed'] - 0;
                } else {
                    $sj = source_job($aid);
                    if ($sj && in_array($sj['status'], ['queued', 'downloading', 'converting'], true)) {
                        throw new RuntimeException('Masih diunduh.');
                    }
                    $freed += (int) filesize($f);
                    @unlink($f);
                }
                $done++;
            } catch (Throwable $e) {
                $skipped[] = ['title' => $title, 'reason' => $e->getMessage()];
            }
        }
        // Potongan sumber versi lama yang terlalu besar (satu file dari detik 0): dikemas ulang jadi potongan kecil
        foreach (glob(SOURCES_DIR . '/*/parts.json') ?: [] as $pj) {
            $aid = basename(dirname($pj));
            if (!room_projects($aid) || !$pick($aid)) {
                continue;
            }
            try {
                room_assert_idle($aid);
                if ($r = repack_source_parts($aid, room_source_ranges($aid))) {
                    $freed += max(0, $r[0] - $r[1]);
                    $done++;
                    // Angka hemat di kartu Studio ikut diperbarui
                    $n = count(source_parts($aid)['parts'] ?? []);
                    foreach (room_projects($aid) as $p) {
                        $p['slim'] = ['at' => time(), 'freed' => ($p['slim']['freed'] ?? $r[0]), 'kept' => $r[1], 'parts' => $n];
                        write_project($p);
                    }
                }
            } catch (Throwable $e) {
                $skipped[] = ['title' => read_job($aid)['title'] ?? $aid, 'reason' => $e->getMessage()];
            }
        }
        respond(['ok' => true, 'freed' => $freed, 'done' => $done, 'skipped' => $skipped, 'info' => storage_info()]);

    case 'storage_purge_renders':
        // Hapus semua versi render lama (riwayat) di semua room; render terbaru tiap clip tetap
        $freed = 0;
        $n = 0;
        foreach (glob(PROJECTS_DIR . '/*.json') ?: [] as $pf) {
            $p = json_decode((string) file_get_contents($pf), true);
            if (!is_array($p) || empty($p['items'])) {
                continue;
            }
            $changed = false;
            foreach ($p['items'] as $k => $it) {
                foreach ((array) ($it['renders'] ?? []) as $rid) {
                    if ($rid === $it['job_id']) {
                        continue;
                    }
                    $j = read_job((string) $rid);
                    foreach (array_filter([$j['file'] ?? '', $j['file_music'] ?? '']) as $f) {
                        $freed += (int) @filesize(DOWNLOAD_DIR . '/' . basename($f));
                    }
                    discard_clip_job((string) $rid, true);
                    $n++;
                }
                if (!empty($it['renders'])) {
                    $p['items'][$k]['renders'] = [];
                    $changed = true;
                }
            }
            if ($changed) {
                write_project($p);
            }
        }
        respond(['ok' => true, 'freed' => $freed, 'count' => $n, 'info' => storage_info()]);

    case 'room_cleanup':
        // Bersihkan room yang semua clipnya sudah diupload: hapus video sumber, preview editor, cuplikan preview,
        // versi render lama, dan (opsional) file clip final. Data room tetap: judul, caption, tanggal upload, thumbnail.
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $project = valid_job_id($pid) ? read_project($pid) : null;
        if (!$project) {
            respond(['error' => 'Proyek tidak ditemukan.'], 404);
        }
        $aid = $project['analysis_id'];
        if (!room_all_uploaded($aid)) {
            respond(['error' => 'Room baru bisa dibersihkan setelah semua clip ditandai sudah upload.'], 409);
        }
        $withClips = !empty($in['clips']);
        // 1. Simpan thumbnail dulu (frame dari video sumber + thumbnail feed dari file clip) selagi filenya masih ada
        require_once __DIR__ . '/preview.php';
        $an = json_decode((string) @file_get_contents(STORAGE_DIR . "/analyses/$aid.json"), true);
        foreach (room_projects($aid) as $p) {
            foreach ($p['items'] ?? [] as $it) {
                try {
                    make_frame($aid, (string) ($an['video']['url'] ?? ''), round(((float) $it['start'] + (float) $it['end']) / 2, 1));
                } catch (Throwable $e) {
                    // thumbnail gagal dibuat: lanjut, kartu memakai gambar cadangan
                }
                if ($j = read_job((string) $it['job_id'])) {
                    clip_thumb_file($j);
                }
            }
        }
        // 2. Hapus file berat
        $g = room_heavy_files($project);
        $freed = 0;
        foreach (['source', 'proxy', 'preview', 'old'] as $k) {
            foreach ($g[$k]['files'] as $f) {
                $freed += (int) @filesize($f);
                @unlink($f);
            }
        }
        if ($withClips) {
            foreach ($g['clips']['files'] as $f) {
                $freed += (int) @filesize($f);
                @unlink($f);
            }
        }
        @rmdir(source_parts_dir($aid));
        // Folder preview editor & versi render lama ikut dibuang dari riwayat (job lama tidak dibutuhkan lagi)
        $cleaned = ['at' => time(), 'freed' => $freed, 'clips' => $withClips];
        foreach (room_projects($aid) as $p) {
            rrmdir(APP_DIR . '/previews/proj_' . $p['id']);
            foreach ($p['items'] as $k => $it) {
                foreach ((array) ($it['renders'] ?? []) as $rid) {
                    discard_clip_job($rid, true);
                }
                $p['items'][$k]['renders'] = [];
            }
            $p['cleaned'] = $cleaned;
            write_project($p);
            if (read_job($p['id'])) {
                update_job($p['id'], ['cleaned' => $cleaned]);
            }
        }
        respond(['ok' => true, 'freed' => $freed, 'cleaned' => $cleaned]);

    case 'render_delete':
        // Hapus satu render lama dari riwayat clip (file ikut dihapus). Render terbaru tidak bisa dihapus di sini.
        $in = input();
        [$project, $key] = project_item_or_fail($in);
        $item = $project['items'][$key];
        $rid = (string) ($in['job'] ?? '');
        if (!in_array($rid, (array) ($item['renders'] ?? []), true)) {
            respond(['error' => 'Render ini tidak ada di riwayat clip.'], 404);
        }
        discard_clip_job($rid, true);
        $item['renders'] = array_values(array_diff((array) $item['renders'], [$rid]));
        $project['items'][$key] = $item;
        write_project($project);
        respond(['ok' => true, 'renders' => render_history($item)]);

    case 'edit_render_all':
        // Render ulang semua clip proyek dengan resep edit masing-masing. Clip yang sedang dibuka di editor
        // dikirim beserta editan terbarunya (belum tentu sudah disimpan). Clip yang editornya belum siap dilewati.
        $in = input();
        [$project] = project_item_or_fail($in);
        $noMusic = !empty($in['no_music']);
        $onlyPending = !empty($in['only_pending']); // hanya clip yang belum diupload
        $queued = [];
        $skipped = [];
        $uploadedSkipped = 0;
        foreach ($project['items'] as $k => $item) {
            $current = read_edit($project['id'], $item['iid']);
            if (!$current) {
                $skipped[] = $item['iid'];
                continue;
            }
            $edit = $current;
            if ($item['iid'] === ($in['iid'] ?? '') && is_array($in['edit'] ?? null)) {
                try {
                    $edit = sanitize_edit($in['edit'], $current);
                } catch (RuntimeException $e) {
                    respond(['error' => $e->getMessage()], 400);
                }
                write_edit($project['id'], $item['iid'], $edit);
            }
            // Clip yang sudah diupload tidak dirender ulang (editan clip yang sedang dibuka tetap tersimpan di atas)
            if ($onlyPending && !empty($item['uploaded'])) {
                $uploadedSkipped++;
                continue;
            }
            $item['start'] = $edit['trim'][0];
            $item['end'] = $edit['trim'][1];
            $item['layout'] = $edit['layout'];
            $item['split'] = !empty($edit['split']['enabled']);
            $item['subtitles'] = $edit['subtitles'];
            $item['hook_text'] = $edit['hook']['enabled'] ? $edit['hook']['text'] : '';
            archive_clip_job($item);
            $item['job_id'] = create_project_job($project, $item, ['no_music' => $noMusic, 'both_music' => !empty($in['both_music'])]);
            $project['items'][$k] = $item;
            $queued[] = $item['iid'];
        }
        write_project($project);
        respond(['ok' => true, 'queued' => $queued, 'skipped' => $skipped, 'uploaded_skipped' => $uploadedSkipped]);

    /* ---------- Project manual (project.php): susun footage sendiri di timeline & render ---------- */
    case 'mproj_list':
        require_once __DIR__ . '/mproject_lib.php';
        $list = [];
        foreach (glob(MPROJ_DIR . '/*.json') ?: [] as $f) {
            $p = json_decode((string) file_get_contents($f), true);
            if (!is_array($p) || empty($p['id'])) {
                continue;
            }
            $first = $p['timeline'][0]['mid'] ?? array_key_first($p['media'] ?? []) ?? '';
            $list[] = ['id' => $p['id'], 'name' => $p['name'], 'ratio' => $p['ratio'], 'clips' => count($p['timeline'] ?? []),
                'duration' => round(array_sum(array_map(fn($c) => $c['out'] - $c['in'], $p['timeline'] ?? [])), 1),
                'thumb' => $p['media'][$first]['thumb'] ?? '', 'renders' => count($p['renders'] ?? []), 'updated_at' => $p['updated_at'] ?? 0];
        }
        usort($list, fn($a, $b) => $b['updated_at'] <=> $a['updated_at']);
        respond(['projects' => $list]);

    case 'mproj_create':
        require_once __DIR__ . '/mproject_lib.php';
        // Rasio dipilih user saat membuat project (tidak lagi ditebak dari footage pertama)
        $in = input();
        $ratio = isset(MPROJ_RATIOS[$in['ratio'] ?? '']) ? $in['ratio'] : null;
        $p = ['id' => bin2hex(random_bytes(8)), 'name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Project baru', 0, 100),
            'ratio' => $ratio ?? '9:16', 'ratio_set' => (bool) $ratio, 'fit' => 'blur', 'media' => [], 'timeline' => [], 'renders' => [], 'created_at' => time()];
        @mkdir(mproj_media_dir($p['id']), 0777, true);
        @chmod(mproj_media_dir($p['id']), 0777);
        write_mproj($p);
        respond(['project' => $p]);

    case 'mproj_get':
        require_once __DIR__ . '/mproject_lib.php';
        $p = read_mproj((string) ($_GET['id'] ?? ''));
        if (!$p) {
            respond(['error' => 'Project tidak ditemukan.'], 404);
        }
        foreach ($p['media'] as $mid => $m) {
            if (in_array($m['status'] ?? '', ['queued', 'processing'], true) && !empty($m['job']) && ($j = read_job($m['job']))) {
                $p['media'][$mid]['progress'] = $j['progress'] ?? 0;
                $p['media'][$mid]['stage'] = $j['stage'] ?? '';
            }
        }
        $rj = !empty($p['render_job']) ? read_job($p['render_job']) : null;
        respond(['project' => $p, 'native' => mproj_native_short($p), 'qualities' => MPROJ_QUALITIES, 'ratios' => array_keys(MPROJ_RATIOS),
            'render' => $rj ? array_intersect_key($rj, array_flip(['id', 'status', 'stage', 'progress', 'error', 'file', 'size', 'note'])) : null,
            'ai' => mproj_ai_available()]);

    case 'mproj_save':
        // Simpan nama, rasio, mode isi layar, & susunan timeline (dirapikan: hanya media yang ada, potongan dalam durasi media)
        require_once __DIR__ . '/mproject_lib.php';
        $in = input();
        $p = update_mproj((string) ($in['id'] ?? ''), function ($p) use ($in) {
            if (isset($in['name'])) {
                $p['name'] = mb_substr(trim((string) $in['name']), 0, 100) ?: $p['name'];
            }
            if (isset($in['ratio']) && isset(MPROJ_RATIOS[$in['ratio']])) {
                $p['ratio'] = $in['ratio'];
                $p['ratio_set'] = true;
            }
            if (isset($in['fit']) && in_array($in['fit'], ['fill', 'blur', 'black'], true)) {
                $p['fit'] = $in['fit'];
            }
            if (isset($in['timeline']) && is_array($in['timeline'])) {
                $tl = [];
                foreach (array_slice($in['timeline'], 0, 300) as $c) {
                    $m = $p['media'][(string) ($c['mid'] ?? '')] ?? null;
                    if (!$m || ($m['status'] ?? '') !== 'done') {
                        continue;
                    }
                    if ($m['kind'] === 'image') {
                        $in0 = 0;
                        $out = max(0.5, min(60, (float) ($c['out'] ?? 3)));
                    } else {
                        $in0 = max(0, min($m['duration'] - 0.2, (float) ($c['in'] ?? 0)));
                        $out = max($in0 + 0.2, min($m['duration'], (float) ($c['out'] ?? $m['duration'])));
                    }
                    $tl[] = ['cid' => preg_match('/^[a-z0-9]{4,12}$/', (string) ($c['cid'] ?? '')) ? $c['cid'] : bin2hex(random_bytes(4)),
                        'mid' => $m['mid'], 'in' => round($in0, 3), 'out' => round($out, 3), 'vol' => (int) max(0, min(200, (int) ($c['vol'] ?? 100))),
                        'fit' => in_array($c['fit'] ?? '', ['fill', 'blur', 'black'], true) ? $c['fit'] : null,
                        // Transisi masuk dari potongan sebelumnya
                        'tin' => is_array($c['tin'] ?? null) && isset(TRANSITION_PRESETS[$c['tin']['type'] ?? ''])
                            ? ['type' => $c['tin']['type'], 'dur' => round(max(0.2, min(2, (float) ($c['tin']['dur'] ?? 0.5))), 2)] : null,
                        // Ukuran (1 = pas layar) & titik tengah footage di layar; null = ikut mode isi layar
                        'tr' => is_array($c['tr'] ?? null) ? ['s' => round(max(0.1, min(5, (float) ($c['tr']['s'] ?? 1))), 3),
                            'x' => round(max(-1, min(2, (float) ($c['tr']['x'] ?? 0.5))), 3), 'y' => round(max(-1, min(2, (float) ($c['tr']['y'] ?? 0.5))), 3)] : null];
                }
                $p['timeline'] = $tl;
            }
            $total = array_sum(array_map(fn($c) => $c['out'] - $c['in'], $p['timeline']));
            $clamp = fn($v, $a, $b) => max($a, min($b, (float) $v));
            if (isset($in['overlays']) && is_array($in['overlays'])) {
                // Grafik & teks: template (dirapikan seperti editor clip), PNG hasil gambar, waktu tampil, posisi & lebar
                $ov = [];
                foreach (array_slice($in['overlays'], 0, 60) as $o) {
                    $g = gfx_sanitize($o['gfx'] ?? null);
                    $file = basename((string) ($o['file'] ?? ''));
                    if (!$g || !footage_file_ok($file)) {
                        continue;
                    }
                    $s0 = round($clamp($o['s'] ?? 0, 0, 3600), 3);
                    $ov[] = ['oid' => preg_match('/^[a-z0-9]{4,12}$/', (string) ($o['oid'] ?? '')) ? $o['oid'] : bin2hex(random_bytes(4)), 'gfx' => $g, 'file' => $file,
                        's' => $s0, 'e' => round(max($s0 + 0.2, $clamp($o['e'] ?? $s0 + 3, 0, 3600)), 3),
                        'x' => round($clamp($o['x'] ?? 0.5, -0.5, 1.5), 4), 'y' => round($clamp($o['y'] ?? 0.3, -0.5, 1.5), 4), 'w' => round($clamp($o['w'] ?? 0.84, 0.05, 2), 4),
                        'opacity' => (int) $clamp($o['opacity'] ?? 100, 0, 100), 'anim' => anim_sanitize($o['anim'] ?? null)];
                }
                $p['overlays'] = $ov;
            }
            if (isset($in['music']) && is_array($in['music'])) {
                $mu = [];
                foreach (array_slice($in['music'], 0, 20) as $m) {
                    if (!music_file_ok($m['file'] ?? null)) {
                        continue;
                    }
                    $s0 = round($clamp($m['s'] ?? 0, 0, 3600), 3);
                    $mu[] = ['id' => preg_match('/^[a-z0-9]{4,12}$/', (string) ($m['id'] ?? '')) ? $m['id'] : bin2hex(random_bytes(4)), 'file' => $m['file'],
                        'name' => mb_substr((string) ($m['name'] ?? 'Musik'), 0, 80), 'dur' => round($clamp($m['dur'] ?? 0, 0, 36000), 3),
                        's' => $s0, 'e' => round(max($s0 + 0.2, $clamp($m['e'] ?? $s0 + 10, 0, 3600)), 3), 'off' => round($clamp($m['off'] ?? 0, 0, 36000), 3),
                        'vol' => (int) $clamp($m['vol'] ?? 40, 0, 100), 'fin' => round($clamp($m['fin'] ?? 0, 0, 10), 1), 'fout' => round($clamp($m['fout'] ?? 1.5, 0, 10), 1)];
                }
                $p['music'] = $mu;
            }
            if (isset($in['pips']) && is_array($in['pips'])) {
                // Overlay media: footage/gambar dari panel Media yang ditaruh di atas footage utama
                $pp = [];
                foreach (array_slice($in['pips'], 0, 40) as $q) {
                    $m = $p['media'][$q['mid'] ?? ''] ?? null;
                    if (!$m) {
                        continue;
                    }
                    $img = $m['kind'] === 'image';
                    $in0 = $img ? 0 : round($clamp($q['in'] ?? 0, 0, max(0, (float) $m['duration'] - 0.1)), 3);
                    $s0 = round($clamp($q['s'] ?? 0, 0, 3600), 3);
                    $max = $img ? 3600 : $s0 + max(0.1, (float) $m['duration'] - $in0);
                    $pp[] = ['id' => preg_match('/^[a-z0-9]{4,12}$/', (string) ($q['id'] ?? '')) ? $q['id'] : bin2hex(random_bytes(4)), 'mid' => $m['mid'],
                        's' => $s0, 'e' => round(max($s0 + 0.1, min($max, $clamp($q['e'] ?? $s0 + 3, 0, 3600))), 3), 'in' => $in0,
                        'x' => round($clamp($q['x'] ?? 0.5, -0.5, 1.5), 4), 'y' => round($clamp($q['y'] ?? 0.5, -0.5, 1.5), 4), 'w' => round($clamp($q['w'] ?? 0.5, 0.05, 2), 4),
                        'vol' => (int) $clamp($q['vol'] ?? 100, 0, 200)];
                }
                $p['pips'] = $pp;
            }
            if (isset($in['tracks']) && is_array($in['tracks'])) {
                // Status track timeline: sembunyikan (mata), kunci, bisukan
                $tk = [];
                foreach (['gfx', 'pip', 'video', 'music'] as $k) {
                    $tk[$k] = ['hide' => !empty($in['tracks'][$k]['hide']), 'lock' => !empty($in['tracks'][$k]['lock']), 'mute' => !empty($in['tracks'][$k]['mute'])];
                }
                $p['tracks'] = $tk;
            }
            return $p;
        });
        if (!$p) {
            respond(['error' => 'Project tidak ditemukan.'], 404);
        }
        respond(['project' => $p]);

    case 'mproj_upload':
        // Unggah footage per potongan (chunk) supaya file besar tidak terbentur batas upload PHP
        require_once __DIR__ . '/mproject_lib.php';
        $pid = (string) ($_POST['id'] ?? '');
        if (!read_mproj($pid)) {
            respond(['error' => 'Project tidak ditemukan.'], 404);
        }
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || ($chunk['error'] ?? 1) !== UPLOAD_ERR_OK) {
            respond(['error' => 'Potongan file gagal diterima.'], 400);
        }
        $idx = (int) ($_POST['idx'] ?? 0);
        $total = max(1, (int) ($_POST['total'] ?? 1));
        $mid = (string) ($_POST['mid'] ?? '');
        if ($idx === 0) {
            $name = mb_substr(basename((string) ($_POST['name'] ?? 'footage')), 0, 120);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $kind = in_array($ext, MPROJ_VIDEO_EXTS, true) ? 'video' : (in_array($ext, MPROJ_IMAGE_EXTS, true) ? 'image' : '');
            if (!$kind) {
                respond(['error' => 'Format harus video (MP4, MOV, MKV, WEBM) atau gambar (JPG, PNG, WEBP).'], 400);
            }
            $mid = bin2hex(random_bytes(4));
            update_mproj($pid, function ($p) use ($mid, $name, $ext, $kind) {
                $p['media'][$mid] = ['mid' => $mid, 'name' => $name, 'ext' => $ext, 'kind' => $kind, 'status' => 'uploading', 'added_at' => time()];
                return $p;
            });
        }
        $p = read_mproj($pid);
        $m = $p['media'][$mid] ?? null;
        if (!$m || $m['status'] !== 'uploading') {
            respond(['error' => 'Unggahan tidak dikenal.'], 400);
        }
        $part = mproj_media_dir($pid) . "/$mid.part";
        if ($idx === 0) {
            @unlink($part);
        }
        file_put_contents($part, file_get_contents($chunk['tmp_name']), FILE_APPEND | LOCK_EX);
        if ($idx < $total - 1) {
            respond(['mid' => $mid, 'ok' => true]);
        }
        rename($part, mproj_media_file($pid, $m));
        @chmod(mproj_media_file($pid, $m), 0666);
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'mproj_media', 'project_id' => $pid, 'media_id' => $mid, 'title' => $m['name'],
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_mproj($pid, function ($p) use ($mid, $jid) {
            $p['media'][$mid]['status'] = 'processing';
            $p['media'][$mid]['job'] = $jid;
            $p['media'][$mid]['size'] = (int) @filesize(mproj_media_file($p['id'], $p['media'][$mid]));
            return $p;
        });
        spawn_worker($jid);
        respond(['mid' => $mid, 'ok' => true, 'done' => true]);

    case 'mproj_media_delete':
        require_once __DIR__ . '/mproject_lib.php';
        $in = input();
        $pid = (string) ($in['id'] ?? '');
        $mid = (string) ($in['mid'] ?? '');
        $p = read_mproj($pid);
        $m = $p['media'][$mid] ?? null;
        if (!$m) {
            respond(['error' => 'Media tidak ditemukan.'], 404);
        }
        if (!empty($m['job'])) {
            touch(JOBS_DIR . "/{$m['job']}.cancel");
            @chmod(JOBS_DIR . "/{$m['job']}.cancel", 0666);
        }
        @unlink(mproj_media_file($pid, $m));
        @unlink(mproj_media_dir($pid) . "/$mid.part");
        foreach (glob(MPROJ_PUB . "/$pid/{$mid}*") ?: [] as $f) {
            @unlink($f);
        }
        $p = update_mproj($pid, function ($p) use ($mid) {
            unset($p['media'][$mid]);
            $p['timeline'] = array_values(array_filter($p['timeline'], fn($c) => $c['mid'] !== $mid));
            return $p;
        });
        respond(['project' => $p]);

    case 'mproj_render':
        require_once __DIR__ . '/mproject_lib.php';
        $in = input();
        $p = read_mproj((string) ($in['id'] ?? ''));
        if (!$p) {
            respond(['error' => 'Project tidak ditemukan.'], 404);
        }
        if (!$p['timeline']) {
            respond(['error' => 'Timeline masih kosong. Seret footage ke timeline dulu.'], 400);
        }
        $old = !empty($p['render_job']) ? read_job($p['render_job']) : null;
        if ($old && in_array($old['status'], ['queued', 'converting'], true)) {
            respond(['error' => 'Render sebelumnya masih berjalan.'], 409);
        }
        $short = in_array((int) ($in['short'] ?? 0), MPROJ_QUALITIES, true) ? (int) $in['short'] : mproj_native_short($p);
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'mproj_render', 'project_id' => $p['id'], 'title' => $p['name'], 'timeline' => $p['timeline'],
            'overlays' => $p['overlays'] ?? [], 'music' => $p['music'] ?? [], 'pips' => $p['pips'] ?? [], 'tracks' => $p['tracks'] ?? [],
            'ratio' => $p['ratio'], 'short' => $short, 'upscale' => !empty($in['upscale']),
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_mproj($p['id'], function ($p) use ($jid) {
            $p['render_job'] = $jid;
            return $p;
        });
        spawn_worker($jid);
        respond(['ok' => true, 'job' => $jid]);

    case 'mproj_render_delete':
        require_once __DIR__ . '/mproject_lib.php';
        $in = input();
        $p = update_mproj((string) ($in['id'] ?? ''), function ($p) use ($in) {
            foreach ($p['renders'] ?? [] as $k => $r) {
                if ($r['job'] === ($in['job'] ?? '')) {
                    @unlink(DOWNLOAD_DIR . '/' . basename($r['file']));
                    @unlink(job_path($r['job']));
                    unset($p['renders'][$k]);
                }
            }
            $p['renders'] = array_values($p['renders'] ?? []);
            return $p;
        });
        respond(['project' => $p]);

    case 'mproj_presets':
        // Preset editor manual: gaya yang bisa dipakai ulang (grafik/teks, transisi, musik, isi layar)
        $list = json_decode((string) @file_get_contents(STORAGE_DIR . '/mproj_presets.json'), true);
        respond(['presets' => is_array($list) ? $list : []]);

    case 'mproj_preset_save':
    case 'mproj_preset_delete':
        $in = input();
        $path = STORAGE_DIR . '/mproj_presets.json';
        $list = json_decode((string) @file_get_contents($path), true);
        $list = is_array($list) ? $list : [];
        if ($action === 'mproj_preset_save') {
            $data = $in['data'] ?? null;
            if (!is_array($data) || strlen(json_encode($data)) > 300000) {
                respond(['error' => 'Data preset tidak valid.'], 400);
            }
            array_unshift($list, ['id' => bin2hex(random_bytes(6)), 'name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: 'Preset', 0, 60),
                'data' => $data, 'created_at' => time()]);
        } else {
            $list = array_values(array_filter($list, fn($x) => $x['id'] !== ($in['id'] ?? '')));
        }
        file_put_contents($path, json_encode(array_slice($list, 0, 50), JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($path, 0666);
        respond(['presets' => array_slice($list, 0, 50)]);

    case 'mproj_delete':
        require_once __DIR__ . '/mproject_lib.php';
        $pid = (string) (input()['id'] ?? '');
        $p = read_mproj($pid);
        if (!$p) {
            respond(['error' => 'Project tidak ditemukan.'], 404);
        }
        foreach ($p['media'] as $m) {
            if (!empty($m['job'])) {
                @unlink(job_path($m['job']));
            }
        }
        rrmdir(mproj_media_dir($pid));
        rrmdir(MPROJ_PUB . "/$pid");
        @unlink(mproj_path($pid));
        @unlink(MPROJ_DIR . "/$pid.lock");
        // Hasil render tetap ada di folder downloads
        respond(['ok' => true]);

    /* ---------- Campaign dari footage lokal (campaign.php) ---------- */
    case 'campaign_list':
        require_once __DIR__ . '/campaign_lib.php';
        $list = [];
        foreach (glob(CAMPAIGN_DIR . '/*.json') ?: [] as $f) {
            $c = json_decode((string) file_get_contents($f), true);
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $first = $c['files'] ? read_cfile($c['id'], $c['files'][0]) : null;
            $list[] = ['id' => $c['id'], 'title' => $c['title'], 'niche' => $c['niche'], 'angle' => $c['angle'], 'files' => count($c['files']),
                'builds' => count($c['builds'] ?? []), 'thumb' => $first['thumb'] ?? '', 'updated_at' => $c['updated_at'] ?? 0];
        }
        usort($list, fn($a, $b) => $b['updated_at'] <=> $a['updated_at']);
        respond(['campaigns' => $list]);

    case 'campaign_create':
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $c = ['id' => bin2hex(random_bytes(8)), 'title' => mb_substr(trim((string) ($in['title'] ?? '')) ?: 'Campaign baru', 0, 100),
            'niche' => 'film', 'angle' => '', 'target' => 45, 'lang' => 'id', 'account_id' => '', 'files' => [], 'plan' => null,
            'builds' => [], 'created_at' => time()];
        @mkdir(campaign_dir($c['id']), 0777, true);
        @chmod(campaign_dir($c['id']), 0777);
        write_campaign($c);
        respond(['campaign' => $c]);

    case 'campaign_get':
        require_once __DIR__ . '/campaign_lib.php';
        $c = read_campaign((string) ($_GET['id'] ?? ''));
        if (!$c) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        $files = [];
        foreach ($c['files'] as $k => $fid) {
            $f = read_cfile($c['id'], $fid);
            if (!$f) {
                continue;
            }
            $j = !empty($f['job']) ? read_job($f['job']) : null;
            $f['code'] = 'F' . ($k + 1);
            $f['progress'] = $j['progress'] ?? 0;
            if ($j && in_array($j['status'], ['queued', 'downloading', 'converting'], true)) {
                $f['stage'] = $j['stage'] ?? $f['stage'] ?? '';
            }
            $files[] = $f;
        }
        // Hasil rakit yang selesai dijadikan proyek Clip Studio (sekali saja)
        $builds = [];
        foreach ($c['builds'] ?? [] as $k => $b) {
            $j = read_job($b['job']);
            if ($j && $j['status'] === 'done' && empty($b['project_id']) && !empty($j['analysis_id'])) {
                // Di dalam kunci campaign: polling yang bersamaan tidak membuat proyek dobel
                $c = update_campaign($c['id'], function ($c) use ($k, $j) {
                    if (empty($c['builds'][$k]['project_id']) && ($an = read_analysis($j['analysis_id']))) {
                        $c['builds'][$k]['project_id'] = create_project($j['analysis_id'], $an, [0]);
                        $c['builds'][$k]['analysis_id'] = $j['analysis_id'];
                    }
                    return $c;
                });
                $b = $c['builds'][$k];
            }
            $p = !empty($b['project_id']) ? read_project($b['project_id']) : null;
            $builds[] = $b + ['status' => $j['status'] ?? 'error', 'stage' => $j['stage'] ?? '', 'progress' => $j['progress'] ?? 0,
                'error' => $j['error'] ?? '', 'iid' => $p['items'][0]['iid'] ?? '', 'exists' => !empty($b['project_id']) ? (bool) $p : true];
        }
        $pj = !empty($c['plan_job']) ? read_job($c['plan_job']) : null;
        // Worker berhenti tanpa sempat mencatat (mis. proses dimatikan): dianggap gagal setelah 15 menit tanpa kabar
        if ($pj && in_array($pj['status'], ['queued', 'converting'], true) && time() - ($pj['updated_at'] ?? 0) > 900) {
            $pj = update_job($pj['id'], ['status' => 'error', 'error' => 'Proses menyusun berhenti tanpa kabar. Klik "Coba lagi".']);
            $c['plan_error'] = $pj['error'];
        }
        respond(['campaign' => $c, 'files' => $files, 'builds' => array_reverse($builds), 'niches' => CAMPAIGN_NICHES, 'targets' => CAMPAIGN_TARGETS,
            'plan_job' => $pj ? ['status' => $pj['status'], 'stage' => $pj['stage'] ?? '', 'error' => $pj['error'] ?? '', 'started' => $pj['created_at']] : null]);

    case 'campaign_update':
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $c = update_campaign((string) ($in['id'] ?? ''), function ($c) use ($in) {
            if (isset($in['title'])) {
                $c['title'] = mb_substr(trim((string) $in['title']), 0, 100) ?: $c['title'];
            }
            if (isset($in['angle'])) {
                $c['angle'] = mb_substr(trim((string) $in['angle']), 0, 300);
            }
            if (isset($in['niche']) && !empty(CAMPAIGN_NICHES[$in['niche']]['on'])) {
                $c['niche'] = $in['niche'];
            }
            if (isset($in['target']) && in_array((int) $in['target'], CAMPAIGN_TARGETS, true)) {
                $c['target'] = (int) $in['target'];
            }
            if (isset($in['lang'])) {
                $c['lang'] = $in['lang'] === 'en' ? 'en' : 'id';
            }
            if (isset($in['account_id'])) {
                $c['account_id'] = preg_match('/^[a-f0-9]{0,32}$/', (string) $in['account_id']) ? (string) $in['account_id'] : '';
            }
            if (isset($in['files']) && is_array($in['files'])) {
                // Urutan bahan (hanya id yang memang ada)
                $keep = array_values(array_intersect(array_map('strval', $in['files']), $c['files']));
                $c['files'] = array_merge($keep, array_values(array_diff($c['files'], $keep)));
            }
            if (array_key_exists('plan', $in)) {
                $c['plan'] = is_array($in['plan']) ? campaign_clean_plan($c, $in['plan']) : null;
            }
            return $c;
        });
        if (!$c) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        respond(['campaign' => $c]);

    case 'campaign_upload':
        // Unggah bahan per potongan (chunk) supaya file besar tidak terbentur batas upload PHP
        require_once __DIR__ . '/campaign_lib.php';
        $cid = (string) ($_POST['id'] ?? '');
        $c = read_campaign($cid);
        if (!$c) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        $idx = (int) ($_POST['idx'] ?? 0);
        $total = max(1, (int) ($_POST['total'] ?? 1));
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || ($chunk['error'] ?? 1) !== UPLOAD_ERR_OK) {
            respond(['error' => 'Potongan file gagal diterima.'], 400);
        }
        $fid = (string) ($_POST['fid'] ?? '');
        if ($idx === 0) {
            $name = mb_substr(basename((string) ($_POST['name'] ?? 'footage')), 0, 120);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, CAMPAIGN_EXTS, true)) {
                respond(['error' => 'Format harus video: ' . strtoupper(implode(', ', CAMPAIGN_EXTS)) . '.'], 400);
            }
            $fid = bin2hex(random_bytes(4));
            write_cfile($cid, ['fid' => $fid, 'name' => $name, 'ext' => $ext, 'size' => (int) ($_POST['size'] ?? 0), 'status' => 'uploading',
                'stage' => 'Mengunggah…', 'added_at' => time()]);
            update_campaign($cid, function ($c) use ($fid) {
                $c['files'][] = $fid;
                return $c;
            });
        }
        $f = read_cfile($cid, $fid);
        if (!$f || ($f['status'] ?? '') !== 'uploading') {
            respond(['error' => 'Unggahan tidak dikenal.'], 400);
        }
        $part = campaign_dir($cid) . "/$fid.part";
        if ($idx === 0) {
            @unlink($part);
        }
        file_put_contents($part, file_get_contents($chunk['tmp_name']), FILE_APPEND | LOCK_EX);
        if ($idx < $total - 1) {
            respond(['fid' => $fid, 'ok' => true]);
        }
        // Potongan terakhir: file utuh, mulai analisa adegan oleh AI
        rename($part, cfile_media($cid, $f));
        @chmod(cfile_media($cid, $f), 0666);
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'campaign_file', 'campaign_id' => $cid, 'file_id' => $fid, 'title' => $f['name'],
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_cfile($cid, $fid, ['status' => 'queued', 'stage' => 'Menunggu analisa…', 'job' => $jid, 'size' => filesize(cfile_media($cid, $f))]);
        spawn_worker($jid);
        respond(['fid' => $fid, 'ok' => true, 'done' => true]);

    case 'campaign_file_retry':
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $cid = (string) ($in['id'] ?? '');
        $f = read_cfile($cid, (string) ($in['fid'] ?? ''));
        if (!$f || !is_file(cfile_media($cid, $f))) {
            respond(['error' => 'File bahan tidak ditemukan.'], 404);
        }
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'campaign_file', 'campaign_id' => $cid, 'file_id' => $f['fid'], 'title' => $f['name'],
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_cfile($cid, $f['fid'], ['status' => 'queued', 'stage' => 'Menunggu analisa…', 'error' => '', 'job' => $jid]);
        spawn_worker($jid);
        respond(['ok' => true]);

    case 'campaign_file_delete':
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $cid = (string) ($in['id'] ?? '');
        $f = read_cfile($cid, (string) ($in['fid'] ?? ''));
        if (!$f) {
            respond(['error' => 'File bahan tidak ditemukan.'], 404);
        }
        if (!empty($f['job']) && ($j = read_job($f['job'])) && in_array($j['status'], ['queued', 'converting'], true)) {
            touch(JOBS_DIR . "/{$j['id']}.cancel");
            @chmod(JOBS_DIR . "/{$j['id']}.cancel", 0666);
        }
        @unlink(cfile_media($cid, $f));
        @unlink(campaign_dir($cid) . "/{$f['fid']}.part");
        @unlink(campaign_dir($cid) . "/{$f['fid']}.json");
        foreach (glob(CAMPAIGN_PUB . "/$cid/{$f['fid']}*.{jpg,mp4}", GLOB_BRACE) ?: [] as $t) {
            @unlink($t);
        }
        $c = update_campaign($cid, function ($c) use ($f) {
            $c['files'] = array_values(array_diff($c['files'], [$f['fid']]));
            if (!empty($c['plan']['segments'])) {
                $c['plan']['segments'] = array_values(array_filter($c['plan']['segments'], fn($s) => $s['fid'] !== $f['fid']));
            }
            return $c;
        });
        respond(['campaign' => $c]);

    case 'campaign_plan':
        // AI menyusun clip dari adegan semua bahan sesuai angle — di latar belakang (worker), karena bisa lama
        // kalau server AI sibuk. Halaman memantau lewat campaign_get (plan_job).
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $c = read_campaign((string) ($in['id'] ?? ''));
        if (!$c) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        if (trim($c['angle']) === '') {
            respond(['error' => 'Isi dulu angle / konsep clip.'], 400);
        }
        if (!campaign_ready_files($c)) {
            respond(['error' => 'Belum ada footage yang selesai dianalisa.'], 400);
        }
        $old = !empty($c['plan_job']) ? read_job($c['plan_job']) : null;
        if ($old && in_array($old['status'], ['queued', 'converting'], true)) {
            respond(['ok' => true, 'job' => $old['id']]); // masih berjalan
        }
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'campaign_plan', 'campaign_id' => $c['id'], 'title' => $c['title'],
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_campaign($c['id'], function ($c) use ($jid) {
            $c['plan_job'] = $jid;
            $c['plan_error'] = '';
            return $c;
        });
        spawn_worker($jid);
        respond(['ok' => true, 'job' => $jid]);

    case 'campaign_build':
        // Rakit susunan jadi video, lalu jadi room Clip Studio (proyek dibuat saat campaign_get melihat job selesai)
        require_once __DIR__ . '/campaign_lib.php';
        $in = input();
        $c = read_campaign((string) ($in['id'] ?? ''));
        if (!$c) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        $plan = campaign_clean_plan($c, is_array($in['plan'] ?? null) ? $in['plan'] : (array) $c['plan']);
        if (!$plan['segments']) {
            respond(['error' => 'Susunan clip masih kosong.'], 400);
        }
        $jid = bin2hex(random_bytes(8));
        write_job(['id' => $jid, 'kind' => 'campaign_build', 'campaign_id' => $c['id'], 'title' => $plan['title'] ?: $c['title'], 'plan' => $plan,
            'status' => 'queued', 'stage' => 'Menunggu…', 'progress' => 0, 'created_at' => time(), 'updated_at' => time()]);
        update_campaign($c['id'], function ($c) use ($plan, $jid) {
            $c['plan'] = $plan;
            $c['builds'][] = ['job' => $jid, 'title' => $plan['title'] ?: $c['title'], 'angle' => $plan['angle'], 'segments' => count($plan['segments']),
                'duration' => round(array_sum(array_map(fn($s) => $s['end'] - $s['start'], $plan['segments'])), 1), 'at' => time()];
            return $c;
        });
        spawn_worker($jid);
        respond(['ok' => true, 'job' => $jid]);

    case 'campaign_delete':
        // Hapus campaign & bahan-bahannya. Room Clip Studio yang sudah dibuat tetap ada.
        require_once __DIR__ . '/campaign_lib.php';
        $cid = (string) (input()['id'] ?? '');
        if (!read_campaign($cid)) {
            respond(['error' => 'Campaign tidak ditemukan.'], 404);
        }
        // Job analisa & rakit milik campaign: yang masih jalan dihentikan, catatannya dihapus
        foreach (glob(JOBS_DIR . '/*.json') ?: [] as $jf) {
            $j = json_decode((string) file_get_contents($jf), true);
            if (!in_array($j['kind'] ?? '', ['campaign_file', 'campaign_build'], true) || ($j['campaign_id'] ?? '') !== $cid) {
                continue;
            }
            if (in_array($j['status'], ['queued', 'converting'], true)) {
                touch(JOBS_DIR . "/{$j['id']}.cancel");
                @chmod(JOBS_DIR . "/{$j['id']}.cancel", 0666);
            } else {
                @unlink($jf);
            }
        }
        rrmdir(campaign_dir($cid));
        @unlink(campaign_path($cid));
        @unlink(CAMPAIGN_DIR . "/$cid.lock");
        // Thumbnail room hasil rakit dipertahankan selama room-nya masih ada (dipakai kartu room)
        foreach (glob(CAMPAIGN_PUB . "/$cid/*.{jpg,mp4}", GLOB_BRACE) ?: [] as $t) {
            if (!preg_match('/^room_([a-f0-9]{16})\.jpg$/', basename($t), $rm) || !read_job($rm[1])) {
                @unlink($t);
            }
        }
        @rmdir(CAMPAIGN_PUB . "/$cid");
        respond(['ok' => true]);

    case 'jobs':
        $jobs = [];
        foreach (glob(JOBS_DIR . '/*.json') as $file) {
            $job = json_decode((string) file_get_contents($file), true);
            if (is_array($job)) {
                $jobs[] = $job;
            }
        }
        usort($jobs, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));
        $jobs = array_map(fn($j) => array_diff_key($j, ['model' => 1]), $jobs); // job lama masih menyimpan nama model AI
        respond(['jobs' => $jobs, 'download_dir' => DOWNLOAD_DIR]);

    case 'cancel':
        $id = (string) (input()['id'] ?? '');
        if (!valid_job_id($id) || !read_job($id)) {
            respond(['error' => 'Job tidak ditemukan.'], 404);
        }
        touch(JOBS_DIR . "/$id.cancel");
        @chmod(JOBS_DIR . "/$id.cancel", 0666);
        respond(['ok' => true]);

    case 'delete':
        $in = input();
        $id = (string) ($in['id'] ?? '');
        $job = valid_job_id($id) ? read_job($id) : null;
        if (!$job) {
            respond(['error' => 'Job tidak ditemukan.'], 404);
        }
        if (($job['kind'] ?? '') === 'project') {
            $project = read_project($id);
            foreach ($project['items'] ?? [] as $item) {
                discard_clip_job($item['job_id'], true);
                foreach ((array) ($item['renders'] ?? []) as $old) {
                    discard_clip_job($old, true);
                }
            }
            @unlink(PROJECTS_DIR . "/$id.json");
            rrmdir(PROJECTS_DIR . "/$id");
            rrmdir(APP_DIR . "/previews/proj_$id");
            @unlink(job_path($id));
            respond(['ok' => true]);
        }
        if (in_array($job['status'], ['queued', 'downloading', 'converting', 'analyzing'], true)) {
            respond(['error' => 'Batalkan dulu sebelum menghapus.'], 409);
        }
        if (($job['kind'] ?? '') === 'analyze' && ($src = source_job($id))) {
            if (in_array($src['status'], ['queued', 'downloading', 'converting'], true)) {
                touch(JOBS_DIR . '/' . $src['id'] . '.cancel');
                @chmod(JOBS_DIR . '/' . $src['id'] . '.cancel', 0666);
            } else {
                @unlink(job_path($src['id']));
            }
            @unlink(source_file($id));
            rrmdir(source_parts_dir($id));
        }
        if (($job['kind'] ?? '') === 'analyze' && !empty($job['more_job']) && ($more = read_job($job['more_job']))) {
            touch(JOBS_DIR . '/' . $more['id'] . '.cancel'); // job tambah clip yang masih jalan ikut dihentikan
            @chmod(JOBS_DIR . '/' . $more['id'] . '.cancel', 0666);
            @unlink(job_path($more['id']));
        }
        if (!empty($in['delete_file'])) {
            foreach (array_filter([$job['file'] ?? '', $job['file_music'] ?? '']) as $f) {
                @unlink(DOWNLOAD_DIR . '/' . basename($f));
            }
        }
        @unlink(job_path($id));
        @unlink(STORAGE_DIR . "/analyses/$id.json");
        require_once __DIR__ . '/preview.php';
        delete_previews($id);
        respond(['ok' => true]);

    default:
        respond(['error' => 'Aksi tidak dikenal.'], 400);
}
