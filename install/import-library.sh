#!/usr/bin/env bash
# Pasang library editing dari Mac lain (file hasil install/export-library.sh) ke BisaClip di Mac ini.
# Jalankan dari folder aplikasi:   bash install/import-library.sh [path/bisaclip-library.tar.gz]
# Windows (WSL2): dipanggil otomatis oleh installer Windows, sebagai root (sudo).
# (tanpa path: dicari otomatis di folder aplikasi, Desktop, dan Downloads)
#
# Aman untuk Mac yang sudah dipakai: file musik / SFX / gambar yang sudah ada tidak ditimpa, dan daftar
# (musik, SFX, preset, preset brand) DIGABUNG, jadi preset & musik milik Mac ini tetap ada.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IS_MAC=0; [[ "$(uname -s)" == Darwin ]] && IS_MAC=1
if [[ $IS_MAC -eq 1 ]]; then PHP=/Applications/XAMPP/xamppfiles/bin/php; else PHP="$(command -v php || echo php)"; fi

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

if [[ $IS_MAC -eq 1 ]]; then
  [[ $EUID -ne 0 ]] || die "Jangan pakai sudo. Jalankan: bash install/import-library.sh"
else
  [[ $EUID -eq 0 ]] || die "Jalankan dengan sudo: sudo bash install/import-library.sh"
fi
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
[[ -x $PHP ]] || die "PHP belum terpasang (Mac: XAMPP, Windows: jalankan installer dulu)."

say "Memasang library dari ${LIB}…"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
tar -xmozf "$LIB" $([[ $IS_MAC -eq 1 ]] || echo --warning=no-unknown-keyword) -C "$TMP" || die "File library rusak atau tidak bisa dibuka."
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

# Apache (Mac: user daemon, WSL: www-data) harus bisa membaca & menulis file baru
chmod -R a+rwX storage previews 2>/dev/null || true
[[ $IS_MAC -eq 0 ]] && id www-data >/dev/null 2>&1 && chown -R www-data:www-data storage previews
ok "Library terpasang. Buka ulang halaman editor di browser supaya musik & preset baru muncul."
