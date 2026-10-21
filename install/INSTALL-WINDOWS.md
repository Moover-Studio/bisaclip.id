# Memasang BisaClip di Windows

BisaClip berjalan di Windows lewat **WSL2** (Linux bawaan Windows). Aplikasinya tetap dibuka di browser Windows seperti biasa: `http://localhost/bisaclip/`.

## Kualitas: Mac vs Windows

| Bagian | Mac | Windows (WSL2) |
|---|---|---|
| Encode video | Apple VideoToolbox | **NVIDIA NVENC** preset kualitas tertinggi (p7, multipass, bitrate sama). Tanpa GPU NVIDIA: x264/x265 software, kualitas sama atau lebih tinggi, hanya lebih lambat |
| AI upscale 2K | Apple super-resolution | **Real-ESRGAN** di GPU NVIDIA |
| Subtitle & font | ffmpeg + libass | Sama (ffmpeg build lengkap, font Arial Black diambil dari Windows) |
| Transkrip | whisper.cpp (Metal) | whisper.cpp (CUDA) |
| Deteksi wajah | OpenCV YuNet | Sama, model yang sama persis |

Semua pengaturan, preset, dan rumus render sama. Kodenya sudah menyesuaikan otomatis.

## Yang dibutuhkan

- Windows 10 (22H2) atau Windows 11.
- Ruang disk kosong ±25 GB di drive C:.
- Kalau ada GPU NVIDIA: **driver NVIDIA terbaru untuk Windows** (Game Ready atau Studio). Jangan memasang driver NVIDIA di dalam Ubuntu, karena WSL memakai driver Windows.

## 1. Pasang WSL2 + Ubuntu

Buka **PowerShell sebagai Administrator**, lalu jalankan:

```powershell
wsl --install -d Ubuntu-24.04
```

Restart komputer kalau diminta. Ubuntu akan terbuka dan meminta Anda membuat **username & password Linux**. Bebas, tapi ingat password-nya.

Kalau punya GPU NVIDIA, cek dari terminal Ubuntu:

```bash
nvidia-smi
```

Nama GPU Anda harus muncul. Kalau tidak muncul, perbarui driver NVIDIA di Windows lalu jalankan `wsl --shutdown` di PowerShell.

## 2. Salin folder BisaClip

**Di Mac**, buat file zip. Pilih salah satu.

Kalau ingin membawa semua data (room, akun, kalender, musik, preset, clip):

```bash
cd /Applications/XAMPP/xamppfiles/htdocs
zip -r ~/Desktop/bisaclip.zip bisaclip -x "bisaclip/storage/venv/*" "bisaclip/storage/tmp/*" "bisaclip/storage/cache/*" "bisaclip/storage/deno/*" "bisaclip/storage/hwenc.json"
```

Kalau mau mulai bersih (tanpa room & clip lama):

```bash
cd /Applications/XAMPP/xamppfiles/htdocs
zip -r ~/Desktop/bisaclip.zip bisaclip -x "bisaclip/storage/*" "bisaclip/downloads/*" "bisaclip/previews/*"
```

Kalau memilih mulai bersih, isi ulang API key Gemini di aplikasi nanti.

Pindahkan `bisaclip.zip` ke Windows, misalnya ke folder **Downloads**.

**Di terminal Ubuntu**, ganti `NAMA` dengan nama user Windows Anda:

```bash
sudo mkdir -p /var/www
sudo unzip -q /mnt/c/Users/NAMA/Downloads/bisaclip.zip -d /var/www/
```

Folder aplikasi sekarang ada di `/var/www/bisaclip`. Jangan menjalankan BisaClip langsung dari drive Windows (`/mnt/c/...`), karena prosesnya jadi sangat lambat.

## 3. Jalankan instalasi otomatis

```bash
cd /var/www/bisaclip
sudo bash install/setup-wsl.sh
```

Prosesnya sekitar 20–40 menit, karena mengunduh ±5 GB: ffmpeg, CUDA, PyTorch, dan model AI. Skrip ini memasang dan mengatur semuanya:

- Apache dan PHP.
- ffmpeg lengkap.
- yt-dlp dan deno.
- whisper.cpp (versi GPU kalau ada NVIDIA).
- Python, OpenCV, dan PyTorch.
- Model AI.

Di akhir, skrip menampilkan hasil cek sistem. Semua baris wajib harus ✓.

Kalau tidak punya GPU NVIDIA, atau ingin melewati CUDA:

```bash
sudo bash install/setup-wsl.sh --no-cuda
```

## 4. Buka BisaClip

Buka browser di Windows ke **http://localhost/bisaclip/**.

Untuk menyalakan BisaClip di hari berikutnya:

1. Salin `install\BisaClip.bat` ke Desktop. Lokasinya bisa dibuka dari File Explorer: `\\wsl.localhost\Ubuntu-24.04\var\www\bisaclip\install`.
2. Klik dua kali **BisaClip.bat**. Apache menyala dan browser terbuka otomatis.
3. **Biarkan jendela hitamnya terbuka** (boleh di-minimize) selama memakai BisaClip. Menutupnya akan mematikan BisaClip.

## Tips

- **File clip hasil render** ada di `\\wsl.localhost\Ubuntu-24.04\var\www\bisaclip\downloads`. Tempel alamat itu di File Explorer, lalu klik kanan **Pin to Quick access**.
- Di menu **Pengaturan → Kompresi**, pilih **H.264** kalau clip mau diputar atau diedit di Windows. HEVC butuh ekstensi codec tambahan di Windows.
- Cek kesiapan sistem kapan saja dari terminal Ubuntu:
  ```bash
  sudo -u www-data php /var/www/bisaclip/install/doctor.php
  ```
- Memperbarui yt-dlp (kalau download YouTube mulai gagal):
  ```bash
  sudo curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux
  ```

## Kalau ada masalah

| Masalah | Solusi |
|---|---|
| `localhost/bisaclip` tidak bisa dibuka | Jalankan `wsl --shutdown` di PowerShell, lalu klik BisaClip.bat lagi. |
| "Akses hanya dari laptop ini" | Buka lewat `http://localhost/bisaclip/`, bukan lewat alamat IP. |
| Render lambat / doctor menulis "encoder software" | GPU NVIDIA tidak terbaca. Perbarui driver NVIDIA di Windows, `wsl --shutdown`, lalu cek `nvidia-smi` di Ubuntu. Setelah itu hapus `/var/www/bisaclip/storage/hwenc.json` supaya deteksi diulang. |
| Drive C: cepat penuh | Data WSL tersimpan di drive C:. Gunakan fitur **Hapus video sumber** atau **Bersihkan room** di Studio. |
