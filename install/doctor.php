<?php
// Cek kesiapan BisaClip di komputer ini (Mac / Linux / Windows WSL2). Jalankan: php install/doctor.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config.php';
require __DIR__ . '/../clip.php';

$bad = 0;
$row = function (bool $ok, string $name, string $info, bool $optional = false) use (&$bad) {
    if (!$ok && !$optional) {
        $bad++;
    }
    printf("  %s %-20s %s\n", $ok ? "\033[32m✓\033[0m" : ($optional ? "\033[33m-\033[0m" : "\033[31m✗\033[0m"), $name, $info);
};
$ver = fn(string $cmd) => trim((string) strtok((string) shell_exec($cmd . ' 2>&1'), "\n"));

echo "\nBisaClip · " . (IS_MAC ? 'macOS' : (IS_WSL ? 'Windows (WSL2)' : PHP_OS_FAMILY)) . ' · PHP ' . PHP_VERSION . "\n\n";
$row(is_executable(FFMPEG), 'ffmpeg', FFMPEG . ' · ' . $ver(escapeshellarg(FFMPEG) . ' -hide_banner -version'));
$row(is_executable(FFPROBE), 'ffprobe', FFPROBE);
$ass = str_contains((string) shell_exec(escapeshellarg(FFMPEG_FULL) . ' -hide_banner -filters 2>&1'), ' ass ');
$row($ass, 'subtitle (libass)', $ass ? FFMPEG_FULL : 'ffmpeg tanpa libass: subtitle tidak bisa dibakar');
$row(is_executable(YTDLP), 'yt-dlp', YTDLP . ' · ' . $ver(escapeshellarg(YTDLP) . ' --version'));
$row(is_executable(BIN_DIR . '/deno') || is_executable('/usr/local/bin/deno'), 'deno', 'runtime JS untuk yt-dlp (YouTube)');
exec(escapeshellarg(WHISPER_CLI) . ' -h 2>&1', $o, $wc);
$row(is_executable(WHISPER_CLI) && $wc === 0, 'whisper-cli', WHISPER_CLI . ($wc === 0 ? '' : ' (gagal dijalankan, cek library CUDA)'));
$row(is_file(WHISPER_MODEL), 'model whisper', basename(WHISPER_MODEL));
$cv = is_file(PYTHON) ? trim((string) shell_exec(escapeshellarg(PYTHON) . ' -c "import cv2;print(cv2.__version__)" 2>&1')) : '';
$row(preg_match('/^\d+\./', $cv) === 1, 'python + opencv', $cv ?: PYTHON . ' tidak ada');
$row(is_file(FACE_MODEL), 'model wajah', basename(FACE_MODEL));
$row(is_executable(PHP_CLI), 'php (worker)', PHP_CLI);

$hw = hw_encoders();
$row($hw['kind'] !== 'none', 'encoder GPU', match ($hw['kind']) {
    'videotoolbox' => 'Apple VideoToolbox',
    'nvenc' => 'NVIDIA NVENC' . (empty($hw['hevc']) ? ' (H.264)' : ' (H.264 + HEVC)') . (str_contains($hw['hq'], 'multipass') ? ' · kualitas tertinggi' : ''),
    default => 'tidak ada: encoder software (kualitas sama, lebih lambat)',
}, true);
$sr = superres_cmd();
if ($sr && !IS_MAC) {
    $cuda = trim((string) shell_exec(escapeshellarg(PYTHON) . ' -c "import torch;print(torch.cuda.is_available())" 2>&1'));
    $sr = $cuda === 'True' ? $sr : null;
}
$row((bool) $sr, 'AI upscale 2K', $sr ? (IS_MAC ? 'Apple super-resolution' : 'Real-ESRGAN (GPU)') : 'tidak tersedia: scaling biasa', true);

foreach ([STORAGE_DIR, DOWNLOAD_DIR, APP_DIR . '/previews', APP_DIR . '/avatars'] as $d) {
    if (is_dir($d) && !is_writable($d)) {
        $row(false, 'izin tulis', "$d tidak bisa ditulis oleh user " . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?'));
    }
}
echo $bad ? "\n\033[31m$bad komponen wajib belum siap.\033[0m\n\n" : "\n\033[32mSemua komponen wajib siap.\033[0m\n\n";
exit($bad ? 1 : 0);
