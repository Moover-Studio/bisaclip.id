#!/usr/bin/env bash
# Update BisaClip ke versi terbaru dari GitHub TANPA menyentuh data di Mac ini.
# Jalankan dari folder aplikasi:   bash install/update-mac.sh
#
# Yang diganti hanya kode aplikasi. Yang tidak disentuh sama sekali:
#   storage/    preset, preset brand, library musik & SFX (daftar), API key, room, project, model AI
#   previews/   file musik, SFX, gambar preset, hasil render
#   downloads/  video unduhan        avatars/   foto
# Sebelum update, daftar & pengaturan penting (file .json dan .key) dicadangkan ke ~/BisaClip-backup/.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO=Moover-Studio/bisaclip.id
BRANCH=main

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -ne 0 ]] || die "Jangan pakai sudo. Jalankan: bash install/update-mac.sh"
[[ -f "$APP_DIR/config.php" ]] || die "Folder aplikasi tidak valid: $APP_DIR"
cd "$APP_DIR"

# ---------- 1. Cadangan data penting ----------
say "Mencadangkan preset & pengaturan…"
BK="$HOME/BisaClip-backup/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
find storage -maxdepth 1 -type f \( -name '*.json' -o -name '*.key' \) -exec cp -p {} "$BK/" \; 2>/dev/null || true
ok "Cadangan: $BK"

# ---------- 2. Ambil kode terbaru ----------
if [[ -d .git ]] && git remote get-url origin >/dev/null 2>&1; then
  say "Mengambil update lewat git…"
  # Folder data tidak dilacak git (.gitignore), jadi tidak ikut berubah
  git pull --ff-only origin "$BRANCH" || die "Update git gagal (ada perubahan kode lokal?). Data kamu aman; cek dengan: git status"
else
  say "Mengunduh versi terbaru dari GitHub…"
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  curl -fL --progress-bar -o "$TMP/app.zip" "https://codeload.github.com/$REPO/zip/refs/heads/$BRANCH" \
    || die "Gagal mengunduh. Periksa internet lalu coba lagi."
  unzip -q "$TMP/app.zip" -d "$TMP/x"
  SRC="$(find "$TMP/x" -mindepth 1 -maxdepth 1 -type d | head -1)"
  [[ -f "$SRC/config.php" ]] || die "File unduhan tidak valid."
  # Salin kode saja; folder data & file lokal dikecualikan (tidak ditimpa, tidak dihapus)
  rsync -a \
    --exclude 'storage/' --exclude 'previews/' --exclude 'downloads/' --exclude 'avatars/' \
    --exclude 'tools/superres' --exclude '.git/' --exclude '*.tar.gz' \
    "$SRC/" "$APP_DIR/"
fi
ok "Kode aplikasi diperbarui"

# ---------- 3. Pasang kebutuhan baru (kalau ada) & cek ----------
say "Memeriksa kebutuhan aplikasi…"
bash install/setup-mac.sh --no-library
