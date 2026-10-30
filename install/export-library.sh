#!/usr/bin/env bash
# Bungkus library editing BisaClip (musik, sound effect, preset editing, preset brand, aset transisi kertas robek,
# dan gambar yang dipakai preset) jadi satu file, untuk dipasang di Mac lain lewat install/setup-mac.sh.
# Video, room, project & hasil render TIDAK ikut (Mac baru mulai dari kosong).
#
# Jalankan di Mac lama, dari folder aplikasi:   bash install/export-library.sh
# Opsi:  --with-keys     ikut sertakan API key Gemini & ElevenLabs (file jadi rahasia, jangan dibagikan)
#        --with-models   ikut sertakan model AI (±550 MB) supaya Mac baru tidak perlu mengunduh
# Hasil: ~/Desktop/bisaclip-library.tar.gz
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$HOME/Desktop/bisaclip-library.tar.gz"
WITH_KEYS=0
WITH_MODELS=0
for a in "$@"; do
  case "$a" in
    --with-keys) WITH_KEYS=1 ;;
    --with-models) WITH_MODELS=1 ;;
    *) printf 'Opsi tidak dikenal: %s\n' "$a"; exit 1 ;;
  esac
done

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }

cd "$APP_DIR"
LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT
add() { [[ -e "$1" ]] && echo "$1" >> "$LIST"; }

say "Mengumpulkan library editing…"
# Daftar & file library (dipakai bersama semua proyek)
for f in storage/music.json storage/sfx.json storage/presets.json storage/mproj_presets.json storage/brands.json storage/settings.json; do add "$f"; done
add previews/music
add previews/sfx
add storage/paper_v1
# Gambar / logo / grafik yang dipakai preset editing (ada di folder media proyek)
if [[ -f storage/presets.json ]]; then
  grep -oE '[0-9a-f]{40}\.(png|jpe?g|webp|gif|mp4|mov|webm)' storage/presets.json | sort -u | while read -r f; do
    [[ -f "previews/overlays/$f" ]] && echo "previews/overlays/$f" >> "$LIST"
  done
fi
if [[ $WITH_KEYS -eq 1 ]]; then add storage/gemini.key; add storage/elevenlabs.key; fi
if [[ $WITH_MODELS -eq 1 ]]; then add storage/models; fi

n_music=$(ls previews/music 2>/dev/null | wc -l | tr -d ' ')
n_sfx=$(ls previews/sfx 2>/dev/null | wc -l | tr -d ' ')
n_ov=$(grep -c '^previews/overlays/' "$LIST" || true)
ok "Musik: $n_music file · SFX: $n_sfx file · gambar preset: $n_ov file"
[[ $WITH_KEYS -eq 1 ]] && warn "API key ikut dibungkus: simpan file hasilnya baik-baik, jangan dibagikan."

say "Membuat ${OUT}…"
# COPYFILE_DISABLE: tanpa file ._ metadata macOS
COPYFILE_DISABLE=1 tar -czf "$OUT" -T "$LIST"
ok "Selesai: $OUT ($(du -h "$OUT" | cut -f1))"
printf '\nSalin file itu ke Mac baru (Desktop / Downloads), lalu jalankan di sana:  bash install/setup-mac.sh\n\n'
