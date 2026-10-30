<?php
require __DIR__ . '/config.php';
require_local();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Cara pakai · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .eyebrow { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
  .head { padding: 22px 24px; background: linear-gradient(120deg, rgba(0, 88, 211, .16), transparent 55%), var(--surface); }
  .head h1 { font-size: 22px; margin: 0 0 6px; }
  .head p { margin: 0; font-size: 13.5px; color: var(--muted); line-height: 1.6; max-width: 640px; }
  .toc { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 16px; }
  .toc a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); font-size: 12.5px; font-weight: 600; text-decoration: none; }
  .toc a:hover { color: var(--text); border-color: var(--border-strong); }
  .sec { margin-top: 18px; padding: 22px 24px; scroll-margin-top: 16px; }
  .sec h2 { font-size: 17px; margin: 0 0 4px; }
  .sec > p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.6; }

  /* Alur kerja: langkah bernomor */
  .steps { display: grid; gap: 0; margin-top: 18px; counter-reset: st; }
  .step { position: relative; display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 14px; padding-bottom: 18px; }
  .step:last-child { padding-bottom: 0; }
  .step::before { counter-increment: st; content: counter(st); width: 34px; height: 34px; display: grid; place-items: center; border-radius: 50%;
    background: rgba(30, 112, 240, .14); border: 1px solid rgba(61, 139, 255, .35); color: #86B6FF; font-weight: 700; font-size: 14px; }
  .step:not(:last-child)::after { content: ""; position: absolute; left: 16.5px; top: 38px; bottom: 4px; width: 1px; background: var(--border); }
  .step > div > b { display: block; font-size: 14.5px; margin-top: 6px; }
  .step p { margin: 4px 0 0; font-size: 13px; color: var(--muted); line-height: 1.6; }
  .step p b { color: var(--text-2); font-weight: 600; }

  /* Kartu fitur */
  .feats { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; margin-top: 16px; }
  .feat { display: flex; flex-direction: column; gap: 6px; padding: 16px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); color: var(--text); text-decoration: none; transition: border-color .2s; }
  a.feat:hover { border-color: rgba(61, 139, 255, .45); }
  .feat .ic { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; color: #6FA8FF; border: 1px solid rgba(61, 139, 255, .28); background: rgba(30, 112, 240, .12); margin-bottom: 4px; }
  .feat .ic .i { width: 17px; height: 17px; }
  .feat b { font-size: 14px; }
  .feat p { margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.55; flex: 1; }
  .feat .go { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 12.5px; font-weight: 600; color: #6FA8FF; }
  .feat .go .i { width: 14px; height: 14px; }

  /* Tips */
  .tips { display: grid; gap: 8px; margin: 16px 0 0; padding: 0; list-style: none; }
  .tips li { display: grid; grid-template-columns: 18px minmax(0, 1fr); gap: 10px; font-size: 13px; line-height: 1.6; color: var(--text-2); }
  .tips li .i { width: 16px; height: 16px; margin-top: 3px; color: #4fd28a; }
  .tips b { color: var(--text); }

  /* FAQ */
  .faq { margin-top: 14px; border-top: 1px solid var(--border); }
  .faq details { border-bottom: 1px solid var(--border); }
  .faq summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 2px; cursor: pointer; list-style: none; font-size: 14px; font-weight: 600; }
  .faq summary::-webkit-details-marker { display: none; }
  .faq summary .i { width: 16px; height: 16px; color: var(--muted); flex: none; transition: transform .2s; }
  .faq details[open] summary .i { transform: rotate(180deg); }
  .faq details p { margin: 0 0 14px; font-size: 13px; color: var(--muted); line-height: 1.65; }
  .faq a { color: var(--accent-fg); }
  @media (max-width: 640px) { .head, .sec { padding: 18px 16px; } }
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <a class="back" href="index.php" title="Kembali ke Beranda" aria-label="Kembali ke Beranda"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>

  <div class="card head">
    <div class="eyebrow"><span class="grad-text">Panduan</span></div>
    <h1>Cara pakai BisaClip</h1>
    <p>BisaClip mengubah video panjang jadi clip vertikal siap upload. AI mencari momen terbaik, memotong, membuat subtitle,
      judul & caption, lalu kamu tinggal merapikan di editor. Semua berjalan di komputer ini.</p>
    <nav class="toc" aria-label="Isi panduan">
      <a href="#alur">Alur kerja</a><a href="#fitur">Fitur</a><a href="#tips">Tips hasil bagus</a><a href="#faq">Pertanyaan umum</a>
    </nav>
  </div>

  <section class="card sec" id="alur">
    <h2>Dari link YouTube jadi clip</h2>
    <p>Alur utama BisaClip, dari awal sampai siap upload.</p>
    <div class="steps">
      <div class="step"><div><b>Tempel link video</b>
        <p>Di Beranda klik <b>Buat clip</b>, lalu tempel link YouTube. BisaClip mengecek judul, durasi, dan channel videonya.</p></div></div>
      <div class="step"><div><b>Atur clip</b>
        <p>Pilih konsep momen (atau biarkan AI memilih semua momen), gaya caption, bahasa, durasi tiap clip, dan jumlah clip.
          Aktifkan <b>Campaign brand</b> kalau clip perlu menyelipkan produk atau mengikuti brief brand.</p></div></div>
      <div class="step"><div><b>AI bekerja</b>
        <p>Video diunduh, ditranskrip, lalu AI menonton dan memilih momen paling menarik. Tiap clip mendapat skor viral, judul, hook, caption, dan hashtag.
          Proses ini bisa ditinggal, progresnya terlihat di Beranda.</p></div></div>
      <div class="step"><div><b>Cek &amp; edit di Clip Studio</b>
        <p>Buka room-nya untuk melihat semua clip. Klik <b>Edit</b> untuk mengatur layout (ikut pembicara, split screen, latar blur),
          subtitle & templatenya, teks, musik, sound effect, transisi, dan grafik.</p></div></div>
      <div class="step"><div><b>Render &amp; upload</b>
        <p>Render clip (bisa dengan AI upscale 2K), unduh hasilnya, lalu upload ke TikTok/Reels/Shorts.
          Tandai clip yang sudah diupload supaya progresmu tercatat, dan pakai <b>Kalender upload</b> untuk jam posting terbaik.</p></div></div>
    </div>
  </section>

  <section class="card sec" id="fitur">
    <h2>Fitur BisaClip</h2>
    <p>Semua menu yang ada, dan kapan memakainya.</p>
    <div class="feats">
      <a class="feat" href="index.php"><span class="ic"><?= icon('scissors') ?></span><b>Buat clip</b>
        <p>Clip otomatis dari video YouTube: podcast, wawancara, atau streaming game.</p><span class="go">Mulai dari Beranda <?= icon('arrow-right') ?></span></a>
      <a class="feat" href="project.php"><span class="ic"><?= icon('layers') ?></span><b>Edit manual</b>
        <p>Editor timeline untuk menyusun video sendiri dari footage, gambar, musik, teks, dan transisi.</p><span class="go">Buka editor <?= icon('arrow-right') ?></span></a>
      <a class="feat" href="campaign.php"><span class="ic"><?= icon('film') ?></span><b>Clip dari footage sendiri</b>
        <p>Unggah footage dari komputer, tulis angle kontennya, AI memilih adegan yang cocok dan menyusunnya lengkap dengan hook.</p><span class="go">Buka campaign <?= icon('arrow-right') ?></span></a>
      <a class="feat" href="index.php"><span class="ic"><?= icon('download') ?></span><b>YT Downloader</b>
        <p>Unduh video atau audio YouTube dengan kualitas maksimal, tanpa membuat clip.</p><span class="go">Mulai dari Beranda <?= icon('arrow-right') ?></span></a>
      <a class="feat" href="calendar.php"><span class="ic"><?= icon('calendar') ?></span><b>Kalender upload</b>
        <p>Jadwal jam upload terbaik tiap hari, mengikuti hari kerja, libur nasional, dan cuti bersama.</p><span class="go">Lihat kalender <?= icon('arrow-right') ?></span></a>
      <a class="feat" href="settings.php"><span class="ic"><?= icon('settings') ?></span><b>Pengaturan</b>
        <p>API key Gemini, kompresi hasil render, dan pembersihan storage supaya disk tidak penuh.</p><span class="go">Buka pengaturan <?= icon('arrow-right') ?></span></a>
    </div>
  </section>

  <section class="card sec" id="tips">
    <h2>Tips supaya clip bagus</h2>
    <ul class="tips">
      <li><?= icon('check') ?><span><b>Pilih video dengan obrolan yang jelas.</b> Podcast dan wawancara dengan audio bersih menghasilkan subtitle dan pilihan momen paling akurat.</span></li>
      <li><?= icon('check') ?><span><b>Isi konteks tambahan</b> di Atur clip kalau ingin momen tertentu, mis. "fokus ke cerita bisnis" atau "bagian lucu saja".</span></li>
      <li><?= icon('check') ?><span><b>Durasi 30–60 detik</b> paling aman untuk TikTok, Reels, dan Shorts.</span></li>
      <li><?= icon('check') ?><span><b>Mulai dari clip dengan skor viral tertinggi.</b> Skor terlihat di galeri clip pada editor.</span></li>
      <li><?= icon('check') ?><span><b>Pakai template subtitle</b> di editor supaya gaya teks konsisten di semua clip, lalu simpan sebagai preset.</span></li>
      <li><?= icon('check') ?><span><b>Untuk streaming game</b>, pilih jenis clip "Streaming game" dan layout Split: game di atas, webcam di bawah.</span></li>
    </ul>
  </section>

  <section class="card sec" id="faq">
    <h2>Pertanyaan umum</h2>
    <div class="faq">
      <details><summary>Muncul "API key Gemini belum diisi". Apa yang harus dilakukan? <?= icon('chevron-down') ?></summary>
        <p>BisaClip butuh API key Gemini untuk memilih momen dan menulis judul. Buat gratis di
          <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>, lalu tempel di
          <a href="settings.php?tab=keys">Pengaturan → API key</a>.</p></details>
      <details><summary>Berapa lama proses buat clip? <?= icon('chevron-down') ?></summary>
        <p>Tergantung panjang video, koneksi internet, dan antrean layanan AI. Perkiraan waktunya muncul di Atur clip sebelum mulai.
          Prosesnya berjalan di latar belakang, jadi kamu bisa menutup halaman dan kembali lagi nanti.</p></details>
      <details><summary>Apakah video saya diunggah ke server lain? <?= icon('chevron-down') ?></summary>
        <p>Video, room, dan hasil render disimpan di komputer ini. Yang dikirim ke layanan AI hanya bahan yang dibutuhkan untuk analisis.</p></details>
      <details><summary>Disk mulai penuh. Aman tidak menghapus video sumber? <?= icon('chevron-down') ?></summary>
        <p>Aman. Di <a href="settings.php?tab=storage">Pengaturan → Manajemen storage</a>, video sumber dihapus tapi bagian di sekitar tiap clip tetap disimpan,
          jadi clip masih bisa diedit dan dirender ulang.</p></details>
      <details><summary>Hasil render terlalu besar atau tidak bisa diputar di Windows? <?= icon('chevron-down') ?></summary>
        <p>Ganti pilihan di <a href="settings.php?tab=compress">Pengaturan → Kompresi hasil clip</a>. HEVC paling hemat ukuran,
          H.264 paling kompatibel di semua perangkat.</p></details>
      <details><summary>Bisa clip video selain bahasa Indonesia? <?= icon('chevron-down') ?></summary>
        <p>Bisa. Subtitle mengikuti bahasa video, sedangkan judul, hook, dan caption bisa dibuat dalam bahasa lain lewat pilihan Bahasa di Atur clip.</p></details>
    </div>
  </section>
</div>
<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
</body>
</html>
