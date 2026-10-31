#!/usr/bin/env bash
# Pasang library editing dari Mac lain (file hasil install/export-library.sh) ke BisaClip di Mac ini.
# Jalankan dari folder aplikasi:   bash install/import-library.sh [path/bisaclip-library.tar.gz]
# (tanpa path: dicari otomatis di folder aplikasi, Desktop, dan Downloads)
#
# Aman untuk Mac yang sudah dipakai: file musik / SFX / gambar yang sudah ada tidak ditimpa, dan daftar
# (musik, SFX, preset, preset brand) DIGABUNG, jadi preset & musik milik Mac ini tetap ada.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP=/Applications/XAMPP/xamppfiles/bin/php

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -ne 0 ]] || die "Jangan pakai sudo. Jalankan: bash install/import-library.sh"
cd "$APP_DIR"

LIB="${1:-}"
if [[ -z "$LIB" ]]; then
  for c in "$APP_DIR/bisaclip-library.tar.gz" "$HOME/Desktop/bisaclip-library.tar.gz" "$HOME/Downloads/bisaclip-library.tar.gz"; do
    [[ -f "$c" ]] && { LIB="$c"; break; }
  done
fi
if [[ -z "$LIB" ]]; then
  warn "File bisaclip-library.tar.gz tidak ditemukan di Desktop / Downloads."
  warn "Buat di Mac utama dengan: bash install/export-library.sh, lalu salin filenya ke Mac ini."
  exit 1
fi
[[ -f "$LIB" ]] || die "File library tidak ditemukan: $LIB"
[[ -x $PHP ]] || die "XAMPP belum terpasang."

say "Memasang library dari ${LIB}…"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
tar -xmozf "$LIB" -C "$TMP" || die "File library rusak atau tidak bisa dibuka."
mkdir -p storage previews

# File (musik, SFX, gambar preset, aset transisi, API key): hanya yang belum ada di Mac ini
for d in previews storage; do
  [[ -d "$TMP/$d" ]] || continue
  rsync -a --ignore-existing \
    --exclude 'music.json' --exclude 'sfx.json' --exclude 'presets.json' --exclude 'brands.json' \
    --exclude 'mproj_presets.json' --exclude 'settings.json' \
    "$TMP/$d/" "$APP_DIR/$d/" 2>/dev/null || warn "Sebagian file di $d/ tidak bisa disalin (izin folder). Coba: sudo chmod -R a+rwX $d"
done

# Daftar musik / SFX / preset / preset brand: digabung
"$PHP" install/merge-library.php "$TMP" "$APP_DIR" || die "Gagal menggabungkan daftar library."

# Apache (user daemon) harus bisa membaca & menulis file baru
chmod -R a+rwX storage previews 2>/dev/null || true
ok "Library terpasang. Buka ulang halaman editor di browser supaya musik & preset baru muncul."
