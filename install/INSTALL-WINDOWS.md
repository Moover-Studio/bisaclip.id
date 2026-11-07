# BisaClip di Windows

BisaClip berjalan di Windows lewat **WSL2** (Linux bawaan Windows). Aplikasinya tetap dibuka di browser Windows seperti biasa, di `http://localhost/bisaclip/`.

Pemasangan memakai **paket installer**: satu file ZIP yang dibuat di Mac utama. Isinya kode terbaru, library musik, SFX dan preset, serta installer sekali klik.

## Kualitas: Mac vs Windows

| Bagian | Mac | Windows (WSL2) |
|---|---|---|
| Encode video | Apple VideoToolbox | **NVIDIA NVENC** preset kualitas tertinggi (p7, multipass, bitrate sama). Tanpa GPU NVIDIA: x264/x265 software, kualitas sama atau lebih tinggi, hanya lebih lambat |
| AI upscale 2K | Apple super-resolution | **Real-ESRGAN** di GPU NVIDIA. Tanpa GPU NVIDIA: scaling biasa |
| Subtitle & font | ffmpeg + libass | Sama: ffmpeg build lengkap, font ikut aplikasi, Arial Black diambil dari Windows |
| Transkrip | whisper.cpp (Metal) | whisper.cpp (CUDA, atau CPU tanpa NVIDIA) |
| Deteksi wajah & split screen | OpenCV YuNet | Sama, model yang sama persis |

Semua pengaturan, preset, dan rumus render sama. Kodenya menyesuaikan otomatis.

---

## A. Untuk admin: membuat paket (di Mac utama)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
bash install/make-windows-package.sh
```

Hasilnya `~/Desktop/BisaClip-Windows-<tanggal>-<commit>.zip`.

| Opsi | Kegunaan |
|---|---|
| `--with-models` | Ikut membawa model AI (±550 MB), supaya tiap laptop tidak mengunduhnya sendiri. Cocok kalau internet kantor lambat. |
| `--no-library` | Tanpa musik, SFX dan preset. Laptop mulai kosong. |

> Paket berisi **API key Gemini bawaan tim** (`storage/gemini.default.key`), jadi semua laptop langsung bisa memakai AI tanpa mengisi key. Kuota key itu dipakai bersama. **Jangan bagikan paket ke luar tim.**

Bagikan ZIP lewat flashdisk atau Google Drive. Paket yang sama dipakai untuk update.

---

## B. Untuk tim: memasang di laptop Windows

### Yang dibutuhkan

- Windows 10 (22H2) atau Windows 11, 64-bit.
- Ruang kosong ±25 GB di drive C:.
- RAM minimal 8 GB (16 GB disarankan).
- Internet.
- Kalau ada GPU NVIDIA: **driver NVIDIA terbaru untuk Windows** (Game Ready atau Studio). Jangan memasang driver NVIDIA di dalam Ubuntu, karena WSL memakai driver Windows.

### Langkah

1. Salin ZIP ke laptop, klik kanan, lalu pilih **Extract All** → **Extract**.
2. Buka folder hasil ekstrak, lalu klik dua kali **Install BisaClip.bat**.
   - *"Windows protected your PC"*: klik **More info** → **Run anyway**.
   - Jendela izin Administrator: klik **Yes**.
   - Diminta **restart**: restart laptop, lalu klik dua kali **Install BisaClip.bat** lagi. Installer melanjutkan dari bagian yang belum selesai.
3. Tunggu sampai muncul **"Selesai!"** (20–40 menit, sebagian besar menunggu unduhan). Di akhir, installer menampilkan cek sistem; semua baris wajib harus ✓.

Yang dipasang otomatis:

- WSL2 + Ubuntu 24.04.
- Apache + PHP.
- ffmpeg lengkap.
- yt-dlp + deno.
- whisper.cpp (versi GPU kalau ada NVIDIA).
- Python + OpenCV, dan PyTorch untuk AI upscale kalau ada NVIDIA.
- Model AI.
- Library musik, SFX, dan preset.
- Dua ikon di Desktop.

Laptop tanpa GPU NVIDIA otomatis memakai CPU. Untuk sengaja melewati CUDA, buka Command Prompt di folder paket, lalu jalankan:

```bat
"Install BisaClip.bat" -NoCuda
```

### Memakai sehari-hari

- Klik dua kali ikon **BisaClip** di Desktop. Apache menyala dan browser terbuka ke `http://localhost/bisaclip/`.
- **Biarkan jendela hitamnya terbuka** (boleh di-minimize) selama memakai BisaClip. Menutupnya akan mematikan BisaClip.
- Video hasil render bisa diunduh dari aplikasi. Bisa juga lewat ikon **Hasil BisaClip** di Desktop, yaitu folder `\\wsl$\Ubuntu-24.04\var\www\bisaclip\downloads`. Folder ini hanya bisa dibuka saat BisaClip menyala.
- Di **Pengaturan → Kompresi**, pilih **H.264**, karena HEVC butuh ekstensi codec tambahan di Windows.

### Update

Minta paket terbaru ke admin, ekstrak, lalu klik dua kali **Install BisaClip.bat** lagi.

- Hanya kode yang diganti.
- Tidak disentuh: preset, musik, SFX, room, project, API key, dan hasil render.
- Pengaturan dicadangkan dulu ke `/root/BisaClip-backup/` di Ubuntu.

> Jangan menghapus Ubuntu (`wsl --unregister`) kecuali memang ingin menghapus **semua** data BisaClip di laptop itu.

---

## Kalau ada masalah

| Masalah | Solusi |
|---|---|
| "Ubuntu tidak bisa dijalankan" / error `0x80370102` | Virtualisasi mati. Cek **Task Manager → Performance → CPU → Virtualization**. Kalau *Disabled*, aktifkan di BIOS (Intel VT-x / AMD SVM), lalu restart. |
| Installer berhenti di tengah (internet putus) | Jalankan **Install BisaClip.bat** lagi. Bagian yang sudah selesai dilewati. |
| `localhost/bisaclip` tidak bisa dibuka | Tutup jendela BisaClip, jalankan `wsl --shutdown` di PowerShell, lalu klik ikon BisaClip lagi. |
| "Akses hanya dari laptop ini" | Buka lewat `http://localhost/bisaclip/`, bukan lewat alamat IP. |
| Render lambat / cek sistem menulis "encoder software" | GPU NVIDIA tidak terbaca. Perbarui driver NVIDIA di Windows, jalankan `wsl --shutdown`, lalu jalankan installer lagi. Installer mengulang deteksi GPU. |
| Download YouTube mulai gagal | Jalankan installer lagi (yt-dlp ikut diperbarui), atau di PowerShell: `wsl -d Ubuntu-24.04 -u root -- curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux` |
| Drive C: cepat penuh | Gunakan **Pengaturan → Manajemen storage** untuk menghapus video sumber dan render lama. |
| Ingin cek komponen | PowerShell: `wsl -d Ubuntu-24.04 -u root -- sudo -u www-data php /var/www/bisaclip/install/doctor.php` |

Saat melapor bug, sertakan hasil cek komponen di atas. **Jangan pernah menempel API key** di laporan, screenshot, atau chat.

---

## Pemasangan manual (tanpa paket)

Kalau perlu memasang langsung dari GitHub di terminal Ubuntu (WSL2), jalankan:

```bash
sudo git clone https://github.com/Moover-Studio/bisaclip.id.git /var/www/bisaclip
cd /var/www/bisaclip && sudo bash install/setup-wsl.sh
```

Jangan menjalankan BisaClip dari drive Windows (`/mnt/c/...`), karena prosesnya jadi sangat lambat.

Library dari Mac (hasil `bash install/export-library.sh`) bisa dipasang dengan:

```bash
sudo bash install/import-library.sh /mnt/c/Users/NAMA/Downloads/bisaclip-library.tar.gz
```

Setelah itu salin `install/BisaClip.bat` ke Desktop.
