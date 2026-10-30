#!/usr/bin/env bash
# Pasang BisaClip di Mac (Apple Silicon). Jalankan dari folder aplikasi:   bash install/setup-mac.sh
# Opsi:  path ke file library, mis.  bash install/setup-mac.sh ~/Downloads/bisaclip-library.tar.gz
#        (tanpa path: dicari otomatis di folder aplikasi, Desktop, dan Downloads)
#        --no-library   lewati pemasangan file library (dipakai install/update-mac.sh)
# Aman dijalankan ulang: yang sudah terpasang dilewati, dan data yang sudah ada (preset, musik, dll.) tidak pernah ditimpa.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
XAMPP_PHP=/Applications/XAMPP/xamppfiles/bin/php
WHISPER_URL=https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q5_0.bin
FACE_URL=https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

[[ "$(uname -s)" == Darwin ]] || die "Skrip ini untuk macOS. Untuk Windows lihat install/INSTALL-WINDOWS.md."
[[ "$(uname -m)" == arm64 ]] || die "Butuh Mac Apple Silicon (M1/M2/M3/…)."
[[ $EUID -ne 0 ]] || die "Jangan pakai sudo. Jalankan: bash install/setup-mac.sh"
[[ -f "$APP_DIR/config.php" ]] || die "Folder aplikasi tidak valid: $APP_DIR"
cd "$APP_DIR"

# ---------- 1. XAMPP ----------
say "Memeriksa XAMPP…"
[[ -x $XAMPP_PHP ]] || die "XAMPP belum terpasang. Unduh XAMPP for macOS di https://www.apachefriends.org, pasang, lalu jalankan skrip ini lagi."
ok "XAMPP: $($XAMPP_PHP -r 'echo PHP_VERSION;')"
[[ "$APP_DIR" == /Applications/XAMPP/xamppfiles/htdocs/* ]] \
  || warn "Folder aplikasi sebaiknya di /Applications/XAMPP/xamppfiles/htdocs/bisaclip supaya bisa dibuka di http://localhost/bisaclip"

# ---------- 2. Homebrew & tool ----------
say "Memasang tool (ffmpeg, yt-dlp, whisper, python)…"
if ! command -v brew >/dev/null 2>&1 && [[ ! -x /opt/homebrew/bin/brew ]]; then
  warn "Homebrew belum ada, memasang (akan diminta password Mac)…"
  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
fi
eval "$(/opt/homebrew/bin/brew shellenv)"
for f in ffmpeg ffmpeg-full yt-dlp deno whisper-cpp python@3.15; do
  if brew list --formula "$f" >/dev/null 2>&1; then ok "$f sudah ada"; else brew install "$f"; ok "$f terpasang"; fi
done

# ---------- 3. Python (deteksi wajah & transisi) ----------
say "Menyiapkan Python…"
PY=/opt/homebrew/opt/python@3.15/bin/python3.15
if ! storage/venv/bin/python -c 'import cv2, numpy' >/dev/null 2>&1; then
  mkdir -p storage
  rm -rf storage/venv
  "$PY" -m venv storage/venv
  storage/venv/bin/pip install -q --upgrade pip
  storage/venv/bin/pip install -q opencv-python-headless numpy fonttools
fi
ok "OpenCV $(storage/venv/bin/python -c 'import cv2;print(cv2.__version__)')"

# ---------- 4. Library editing dari Mac lama ----------
say "Mencari file library (bisaclip-library.tar.gz)…"
LIB="" NO_LIB=0
for a in "$@"; do [[ "$a" == --no-library ]] && NO_LIB=1 || LIB="$a"; done
if [[ $NO_LIB -eq 1 ]]; then
  ok "Dilewati (update): data library di Mac ini tetap"
elif [[ -z "$LIB" ]]; then
  for c in "$APP_DIR/bisaclip-library.tar.gz" "$HOME/Desktop/bisaclip-library.tar.gz" "$HOME/Downloads/bisaclip-library.tar.gz"; do
    [[ -f "$c" ]] && { LIB="$c"; break; }
  done
fi
if [[ $NO_LIB -eq 1 ]]; then
  :
elif [[ -n "$LIB" ]]; then
  [[ -f "$LIB" ]] || die "File library tidak ditemukan: $LIB"
  # -k: file yang sudah ada di Mac ini TIDAK ditimpa (preset & musik baru tetap aman kalau skrip dijalankan ulang)
  # -m: tanggal file tidak dikembalikan (folder buatan Apache dimiliki user daemon), -o: pemilik = user ini
  tar -xkmozf "$LIB" -C "$APP_DIR" || die "Gagal membuka file library. Coba: sudo chmod -R a+rwX storage previews, lalu ulangi."
  ok "Library dipasang dari $LIB (musik: $(ls previews/music 2>/dev/null | wc -l | tr -d ' '), SFX: $(ls previews/sfx 2>/dev/null | wc -l | tr -d ' '))"
else
  warn "Tidak ada file library: musik, SFX & preset mulai kosong. (Buat di Mac lama: bash install/export-library.sh)"
fi

# ---------- 5. Model AI ----------
say "Memeriksa model AI…"
mkdir -p storage/models
if [[ ! -s storage/models/ggml-large-v3-turbo-q5_0.bin ]]; then
  warn "Mengunduh model subtitle Whisper (±550 MB)…"
  curl -fL --progress-bar -C - -o storage/models/ggml-large-v3-turbo-q5_0.bin.part "$WHISPER_URL"
  mv storage/models/ggml-large-v3-turbo-q5_0.bin.part storage/models/ggml-large-v3-turbo-q5_0.bin
fi
ok "Model subtitle"
if [[ ! -s storage/models/face_detection_yunet.onnx ]]; then
  curl -fsSL -o storage/models/face_detection_yunet.onnx "$FACE_URL"
fi
ok "Model deteksi wajah"

# ---------- 6. AI upscale 2K (opsional) ----------
if [[ ! -x tools/superres || tools/superres.swift -nt tools/superres ]]; then
  if xcrun --find swiftc >/dev/null 2>&1; then
    say "Membuat tool AI upscale 2K…"
    xcrun swiftc -O tools/superres.swift -o tools/superres && ok "AI upscale siap"
  else
    warn "AI upscale 2K dilewati (butuh Xcode Command Line Tools: xcode-select --install). Render tetap jalan."
  fi
fi

# ---------- 7. Folder data & izin tulis (Apache XAMPP berjalan sebagai user daemon) ----------
say "Menyiapkan folder data…"
mkdir -p storage downloads previews avatars
# File yang sudah dibuat Apache (milik daemon) dilewati: memang sudah bisa ditulis olehnya
chmod -R a+rwX storage downloads previews avatars 2>/dev/null || true
ok "Izin tulis"

# ---------- 8. Cek akhir ----------
say "Cek kesiapan…"
"$XAMPP_PHP" install/doctor.php || true
printf '\nLangkah terakhir:\n  1. Buka aplikasi XAMPP (manager-osx) → tab Manage Servers → Start "Apache Web Server"\n'
printf '  2. Buka http://localhost/bisaclip\n'
[[ -s storage/gemini.key ]] || printf '  3. Masukkan API key Gemini di Pengaturan → tab API key (http://localhost/bisaclip/settings.php?tab=keys)\n'
printf '\n'
