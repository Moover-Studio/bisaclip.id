# BisaClip

Ubah video panjang jadi clip vertikal siap upload (TikTok, Reels, Shorts). AI mencari momen terbaik, memotong,
membuat subtitle, judul, hook & caption, lalu kamu tinggal merapikan di editor. Semua berjalan di komputer sendiri.

- **Buat clip**: tempel link YouTube, AI memilih momen dan membuat clip lengkap dengan subtitle & hook.
- **Editor clip**: layout ikut pembicara / split screen, template subtitle, template grafik, musik, SFX, transisi.
- **Edit manual**: editor timeline untuk menyusun video dari footage sendiri.
- **Preset**: pakai preset siap pakai, atau simpan gaya editanmu sendiri dan terapkan ke banyak clip sekaligus.
- **Unduh asset**: unduh video atau audio YouTube dengan kualitas maksimal.
- **Kalender upload**: jam upload terbaik tiap hari.

> **Status: tahap uji coba.** Terima kasih sudah membantu testing! Lihat [Lapor bug & masukan](#lapor-bug--masukan).

---

## Yang dibutuhkan

| | |
|---|---|
| Komputer | **Mac Apple Silicon** (M1, M2, M3, M4, …). Mac Intel belum didukung. Windows: lihat [install/INSTALL-WINDOWS.md](install/INSTALL-WINDOWS.md) |
| Ruang disk | ±15 GB kosong (tool, model AI, ditambah video yang kamu proses) |
| Internet | Untuk instalasi, unduh video, dan layanan AI |
| API key Gemini | Gratis, dibuat di [aistudio.google.com/apikey](https://aistudio.google.com/apikey) (login akun Google) |

Cek jenis Mac: menu  → **About This Mac**. Kalau tertulis *Chip Apple M…*, berarti Apple Silicon.

---

## Instalasi (±20–40 menit, sebagian besar menunggu unduhan)

### 1. Pasang XAMPP

1. Unduh **XAMPP for macOS** di [apachefriends.org](https://www.apachefriends.org/download.html).
2. Buka file `.dmg`, lalu jalankan installer-nya sampai selesai (pilihan bawaan sudah cukup).
3. Kalau muncul peringatan keamanan, buka  → **System Settings** → **Privacy & Security**, lalu klik **Open Anyway**.

### 2. Pasang git

Buka **Terminal** (tekan `⌘ + Spasi`, ketik *Terminal*, Enter), lalu jalankan:

```bash
git --version
```

- Muncul nomor versi (mis. `git version 2.x`) → lanjut ke langkah 3.
- Muncul jendela *Command Line Tools* → klik **Install**, tunggu selesai, tutup & buka lagi Terminal, lalu ulangi `git --version`.
- Jendela tidak muncul → jalankan `xcode-select --install`.

### 3. Ambil kode BisaClip

```bash
cd /Applications/XAMPP/xamppfiles/htdocs
git clone https://github.com/Moover-Studio/bisaclip.id.git bisaclip
```

> Folder harus bernama `bisaclip` di dalam `htdocs`, supaya aplikasi bisa dibuka di `http://localhost/bisaclip`.

### 4. Jalankan skrip instalasi

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
bash install/setup-mac.sh
```

Skrip ini memasang semua yang dibutuhkan secara otomatis:

- **Homebrew**: diminta password Mac. Saat diketik, password memang tidak terlihat.
- **Tool video & AI**: ffmpeg, yt-dlp, whisper, dan Python + OpenCV.
- **Model AI**: subtitle (±550 MB) dan deteksi wajah.
- **AI upscale 2K**: opsional, butuh Command Line Tools dari langkah 2.

Di akhir, skrip mengecek semua komponen. Pastikan muncul **"Semua komponen wajib siap"**.

Skrip aman dijalankan ulang kalau terputus di tengah jalan.

> Pesan *"Tidak ada file library"* itu normal untuk instalasi baru: musik, SFX, dan preset mulai kosong.

### 5. Nyalakan Apache

1. Buka **Finder** → **Applications** → **XAMPP** → **manager-osx**.
2. Masuk ke tab **Manage Servers**, pilih **Apache Web Server**, lalu klik **Start**. Statusnya akan menjadi hijau (*Running*).

### 6. Buka BisaClip & isi API key

1. Buka [http://localhost/bisaclip](http://localhost/bisaclip) di browser (Chrome atau Safari).
2. Klik **Pengaturan** → **API key**, tempel API key Gemini, lalu klik **Simpan**.
   Key dicek dulu ke server Google, jadi kalau salah salin akan langsung ketahuan.

Selesai! Coba klik **Buat clip** dan tempel link video YouTube (podcast atau wawancara paling cocok untuk percobaan pertama).

Panduan pemakaian lengkap ada di dalam aplikasi: tombol **"Baru pakai BisaClip?"** di Beranda.

---

## Update ke versi terbaru

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
bash install/update-mac.sh
```

Yang dilakukan skrip ini:

- **Mengganti kode saja.** Data kamu tidak disentuh: preset, musik, SFX, API key, room, project, dan hasil render.
- **Mencadangkan pengaturan dulu** ke `~/BisaClip-backup/` sebelum update.

> **Jangan** menghapus folder `bisaclip` atau menimpanya dengan ZIP baru, karena semua datamu tersimpan di dalam folder itu.

---

## Salin library musik, SFX & preset dari Mac lain

1. Di Mac yang sudah punya library: `bash install/export-library.sh`. Hasilnya `bisaclip-library.tar.gz` di Desktop.
2. Salin file itu ke Desktop atau Downloads Mac tujuan (AirDrop, flashdisk, atau Google Drive).
3. Di Mac tujuan:
   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
   bash install/import-library.sh
   ```

Library milik Mac tujuan tetap ada; yang baru ditambahkan tanpa menimpa apa pun.

---

## Kalau ada masalah

| Masalah | Solusi |
|---|---|
| `http://localhost/bisaclip` tidak terbuka | Apache belum jalan: buka **manager-osx** → **Start** Apache Web Server. |
| *"API key Gemini belum diisi"* | Isi di **Pengaturan → API key**. |
| *"API key ditolak server"* | Salin ulang key dari [aistudio.google.com/apikey](https://aistudio.google.com/apikey), pastikan tanpa spasi. |
| `Could not resolve host: github.com` | Internet / DNS bermasalah. Coba buka github.com di browser, ganti Wi-Fi atau hotspot HP, matikan VPN, atau set DNS ke `1.1.1.1` (System Settings → Network → Wi-Fi → Details → DNS). |
| `git: command not found` | Jalankan `xcode-select --install`, tunggu selesai, buka ulang Terminal. |
| `brew: command not found` setelah instalasi | Jalankan `echo 'eval "$(/opt/homebrew/bin/brew shellenv)"' >> ~/.zprofile`, lalu buka ulang Terminal. |
| Ingin cek komponen apa yang belum siap | `/Applications/XAMPP/xamppfiles/bin/php install/doctor.php` dari folder `bisaclip`. |
| Disk penuh | **Pengaturan → Manajemen storage**: hapus video sumber & render lama (clip tetap bisa diedit). |

---

## Lapor bug & masukan

Buka tab **[Issues](https://github.com/Moover-Studio/bisaclip.id/issues)** → **New issue**, lalu sertakan:

1. **Apa yang kamu lakukan**: langkah-langkahnya, mis. "Buat clip → pilih 5 clip → klik Mulai".
2. **Apa yang terjadi**, dan apa yang seharusnya terjadi.
3. **Screenshot** atau rekaman layar kalau bisa.
4. **Link video YouTube** yang dipakai, kalau masalahnya terkait video tertentu.
5. **Hasil cek komponen**: jalankan perintah di bawah, lalu tempel hasilnya.
   ```bash
   /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/bisaclip/install/doctor.php
   ```

> **Jangan pernah menempel API key** di issue, screenshot, atau chat.
