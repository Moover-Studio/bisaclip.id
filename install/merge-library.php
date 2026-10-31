<?php
// Gabungkan daftar library dari file library (hasil export-library.sh, sudah dibuka di <folder sumber>) ke aplikasi ini.
// Item yang sudah ada di Mac ini tetap; item dari Mac lain yang belum ada ditambahkan (tidak ada yang ditimpa / dihapus).
// Dipanggil oleh install/import-library.sh:   php install/merge-library.php <folder sumber> <folder aplikasi>
[$src, $app] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_dir("$src/storage") || !is_dir("$app/storage")) {
    fwrite(STDERR, "Folder tidak valid.\n");
    exit(1);
}
// Daftar => kunci unik tiap item
$lists = ['music' => 'file', 'sfx' => 'file', 'presets' => 'id', 'brands' => 'name', 'mproj_presets' => 'id'];
foreach ($lists as $name => $key) {
    $from = json_decode((string) @file_get_contents("$src/storage/$name.json"), true);
    if (!is_array($from) || !$from) {
        continue;
    }
    $path = "$app/storage/$name.json";
    $cur = json_decode((string) @file_get_contents($path), true);
    $cur = is_array($cur) ? $cur : [];
    $id = fn($it) => is_array($it) && isset($it[$key]) ? (string) $it[$key] : json_encode($it);
    $have = array_flip(array_map($id, $cur));
    $add = array_values(array_filter($from, fn($it) => !isset($have[$id($it)])));
    if (!$add) {
        printf("    %-14s %d item (tidak ada yang baru)\n", $name, count($cur));
        continue;
    }
    $out = array_merge($cur, $add);
    // Tulis lewat file sementara lalu rename: tetap bisa walau file lama dibuat Apache (milik user daemon)
    $tmp = "$path.tmp" . getmypid();
    file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0666);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        fwrite(STDERR, "Gagal menulis $path\n");
        exit(1);
    }
    printf("    %-14s %d item (+%d dari file library)\n", $name, count($out), count($add));
}
// Pengaturan aplikasi hanya dipakai kalau Mac ini belum punya
if (!is_file("$app/storage/settings.json") && is_file("$src/storage/settings.json")) {
    copy("$src/storage/settings.json", "$app/storage/settings.json");
    @chmod("$app/storage/settings.json", 0666);
}
