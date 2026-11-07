#!/usr/bin/env bash
# Bagian Ubuntu dari installer Windows: dipanggil oleh install-windows.ps1 sebagai root di WSL2.
#   bash install-in-wsl.sh <folder paket> [--no-cuda]
# Pasang baru: kode → /var/www/bisaclip, lalu install/setup-wsl.sh & library musik/SFX/preset dari paket.
# Update (sudah terpasang): hanya kode yang diganti; storage/ previews/ downloads/ avatars/ tidak disentuh.
set -euo pipefail

PKG="${1:?folder paket}"; shift
APP=/var/www/bisaclip

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || die "Harus dijalankan sebagai root."
[[ -f "$PKG/bisaclip-app.tar.gz" ]] || die "bisaclip-app.tar.gz tidak ada di $PKG"
command -v rsync >/dev/null 2>&1 || { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq rsync >/dev/null; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/app" "$APP"
tar --warning=no-unknown-keyword -xzf "$PKG/bisaclip-app.tar.gz" -C "$TMP/app"
[[ -f "$TMP/app/config.php" ]] || die "Isi paket tidak valid."

# Pemasangan pertama yang belum pernah selesai tetap dianggap baru (penanda ditulis di akhir)
FRESH=1; [[ -f "$APP/storage/.windows-installed" ]] && FRESH=0
if [[ -f "$APP/config.php" ]]; then
  say "Update BisaClip (data di laptop ini tetap)…"
  BK="/root/BisaClip-backup/$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$BK"
  find "$APP/storage" -maxdepth 1 -type f \( -name '*.json' -o -name '*.key' \) -exec cp -p {} "$BK/" \; 2>/dev/null || true
  ok "Cadangan pengaturan: $BK"
else
  say "Memasang BisaClip ke $APP…"
fi
# Kode diganti; folder data dikecualikan (file bawaan di dalamnya, mis. .htaccess, hanya ditambah kalau belum ada)
rsync -a --exclude '/storage/' --exclude '/previews/' --exclude '/downloads/' --exclude '/avatars/' "$TMP/app/" "$APP/"
for d in storage previews downloads avatars; do
  [[ -d "$TMP/app/$d" ]] && { mkdir -p "$APP/$d"; rsync -a --ignore-existing "$TMP/app/$d/" "$APP/$d/"; }
done
ok "Kode: $(cat "$PKG/VERSION" 2>/dev/null || echo terbaru)"

# Model AI yang ikut di file library (opsi --with-models di Mac) dipasang duluan supaya tidak diunduh ulang
LIB="$PKG/bisaclip-library.tar.gz"
if [[ -f "$LIB" ]] && tar -tzf "$LIB" 2>/dev/null | grep -q '^storage/models/'; then
  mkdir -p "$TMP/lib" "$APP/storage/models"
  tar --warning=no-unknown-keyword -xzf "$LIB" -C "$TMP/lib" storage/models
  rsync -a --ignore-existing "$TMP/lib/storage/models/" "$APP/storage/models/"
  ok "Model AI dari paket"
fi

# Tool, Apache, Python, model (yang belum ada) + cek sistem
bash "$APP/install/setup-wsl.sh" "$@"

if [[ -f "$LIB" ]]; then
  say "Memasang library musik, SFX & preset…"
  bash "$APP/install/import-library.sh" "$LIB"
fi
# Pemasangan baru: kompresi H.264 (HEVC dari pengaturan Mac butuh codec tambahan di Windows)
if [[ $FRESH -eq 1 ]]; then
  php -r '$f=$argv[1]; $s=json_decode((string)@file_get_contents($f),true); $s=is_array($s)?$s:[]; $s["clip_codec"]="h264";
    file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));' "$APP/storage/settings.json"
  ok "Kompresi clip: H.264 (bisa diubah di Pengaturan)"
fi
date > "$APP/storage/.windows-installed"
chown -R www-data:www-data "$APP"/{storage,downloads,previews,avatars}
ok "BisaClip siap"
