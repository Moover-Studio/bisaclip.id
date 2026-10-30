# Pasang BisaClip di Mac lain

Butuh Mac Apple Silicon (M1/M2/M3/…) dan internet. Video, room & hasil render tidak ikut dipindah (mulai kosong);
library editing (musik, sound effect, preset, preset brand, aset transisi) ikut lewat satu file.

## Di Mac lama (sekali saja)

```
cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
bash install/export-library.sh
```

Hasilnya `bisaclip-library.tar.gz` di Desktop. Opsi:

- `--with-keys`: ikut bawa API key Gemini & ElevenLabs (file jadi rahasia, jangan dibagikan).
- `--with-models`: ikut bawa model AI (±550 MB) supaya Mac baru tidak perlu mengunduh.

## Di Mac baru

1. Pasang **XAMPP for macOS** dari https://www.apachefriends.org
2. Ambil kode aplikasi ke folder XAMPP:
   ```
   cd /Applications/XAMPP/xamppfiles/htdocs
   git clone <alamat-repo> bisaclip
   ```
3. Taruh `bisaclip-library.tar.gz` di Desktop atau Downloads, lalu jalankan:
   ```
   cd /Applications/XAMPP/xamppfiles/htdocs/bisaclip
   bash install/setup-mac.sh
   ```
   Skrip memasang Homebrew & semua tool, menyiapkan Python, mengunduh model AI, memasang library, lalu mengecek semuanya.
   Aman dijalankan ulang.
4. Buka XAMPP → **Manage Servers** → Start **Apache Web Server**, lalu buka http://localhost/bisaclip
5. Kalau API key tidak ikut dibawa, isi di **Pengaturan → API key** (http://localhost/bisaclip/settings.php?tab=keys).
