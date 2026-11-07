#!/usr/bin/env bash
# Buat paket instalasi BisaClip untuk laptop Windows tim (WSL2), dari Mac ini.
# Jalankan dari folder aplikasi:   bash install/make-windows-package.sh
# Opsi:  --with-models   ikut sertakan model AI (±550 MB) supaya laptop tidak perlu mengunduhnya
#        --no-library    tanpa musik, SFX & preset (laptop mulai kosong)
# Hasil: ~/Desktop/BisaClip-Windows-<tanggal>-<commit>.zip berisi kode di Mac ini (file yang dilacak git, termasuk
# perubahan yang belum di-commit), library editing, dan installer. Paket yang sama dipakai untuk update.
# Key Gemini bawaan (storage/gemini.default.key) ikut di library: paket hanya untuk tim internal, jangan dibagikan.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WITH_MODELS=0
NO_LIB=0
for a in "$@"; do
  case "$a" in
    --with-models) WITH_MODELS=1 ;;
    --no-library) NO_LIB=1 ;;
    *) printf 'Opsi tidak dikenal: %s\n' "$a"; exit 1 ;;
  esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

cd "$APP_DIR"
[[ -d .git ]] || die "Butuh folder git BisaClip."
VER="$(date +%Y%m%d)-$(git rev-parse --short HEAD)"
[[ -n "$(git status --porcelain)" ]] && { VER="$VER-lokal"; warn "Ada perubahan yang belum di-commit: ikut masuk paket ($VER)."; }
NAME="BisaClip-Windows-$VER"
OUT="$HOME/Desktop/$NAME.zip"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PK="$WORK/$NAME"
mkdir -p "$PK"

say "Membungkus kode aplikasi…"
# File yang dilacak git + file baru yang tidak di-.gitignore (data pribadi, video & model tidak ikut)
git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [[ -f "$f" ]] && printf '%s\n' "$f"; done > "$WORK/files"
COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs -czf "$PK/bisaclip-app.tar.gz" -T "$WORK/files"
echo "$VER" > "$PK/VERSION"
ok "$(wc -l < "$WORK/files" | tr -d ' ') file · $(du -h "$PK/bisaclip-app.tar.gz" | cut -f1)"

if [[ $NO_LIB -eq 0 ]]; then
  say "Membungkus library (musik, SFX, preset)…"
  LIBARGS=(--out="$PK/bisaclip-library.tar.gz")
  [[ $WITH_MODELS -eq 1 ]] && LIBARGS+=(--with-models)
  bash install/export-library.sh "${LIBARGS[@]}" >/dev/null
  ok "$(du -h "$PK/bisaclip-library.tar.gz" | cut -f1)"
fi

say "Menyiapkan installer Windows…"
cp install/windows/install-windows.ps1 install/windows/install-in-wsl.sh "$PK/"
# File Windows (.bat, .txt) butuh akhir baris CRLF
crlf() { perl -pe 's/\r?\n/\r\n/' "$1" > "$2"; }
crlf "install/windows/Install BisaClip.bat" "$PK/Install BisaClip.bat"
crlf install/BisaClip.bat "$PK/BisaClip.bat"
crlf "install/windows/BACA DULU.txt" "$PK/BACA DULU.txt"
cp install/INSTALL-WINDOWS.md "$PK/Panduan Windows.md"
perl -pi -e 's/\r?\n/\r\n/' "$PK/install-windows.ps1"

rm -f "$OUT"
(cd "$WORK" && zip -qrX "$OUT" "$NAME")
ok "Selesai: $OUT ($(du -h "$OUT" | cut -f1))"
printf '\nSalin ZIP itu ke laptop Windows (flashdisk / Google Drive), ekstrak, lalu klik dua kali "Install BisaClip.bat".\n'
printf 'Paket berisi API key Gemini bawaan tim: jangan dibagikan ke luar tim.\n\n'
