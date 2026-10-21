<?php
// Halaman analisa lama sudah digabung ke Clip Studio: analisa, pembuatan clip, dan hasilnya kini satu halaman.
require __DIR__ . '/config.php';
require_local();
$id = (string) ($_GET['id'] ?? '');
header('Location: ' . (valid_job_id($id) ? 'studio.php?id=' . $id : 'index.php'));
exit;
