#!/usr/bin/env bash
# Pasang BisaClip di Windows lewat WSL2 (Ubuntu 22.04 / 24.04).
# Jalankan dari dalam folder aplikasi:  sudo bash install/setup-wsl.sh
# Opsi:  --no-cuda   lewati CUDA (whisper & AI upscale tanpa GPU NVIDIA)
# Aman dijalankan ulang: yang sudah terpasang dilewati / diperbarui.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WANT_CUDA=1
[[ "${1:-}" == "--no-cuda" ]] && WANT_CUDA=0

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || die "Jalankan dengan sudo: sudo bash install/setup-wsl.sh"
grep -qi microsoft /proc/version || warn "Sepertinya bukan WSL. Skrip tetap jalan untuk Linux biasa."
[[ -f "$APP_DIR/config.php" ]] || die "Folder aplikasi tidak valid: $APP_DIR"
case "$APP_DIR" in
  /mnt/*) die "Jangan jalankan dari drive Windows (/mnt/c/...): sangat lambat. Salin dulu ke /var/www/bisaclip (lihat install/INSTALL-WINDOWS.md)." ;;
esac

HAS_GPU=0
if [[ -x /usr/lib/wsl/lib/nvidia-smi ]] && /usr/lib/wsl/lib/nvidia-smi -L >/dev/null 2>&1; then HAS_GPU=1; fi
if command -v nvidia-smi >/dev/null 2>&1 && nvidia-smi -L >/dev/null 2>&1; then HAS_GPU=1; fi
[[ $WANT_CUDA -eq 0 ]] && HAS_GPU=0
# Generasi GPU (compute capability), mis. RTX 5060 = 12.0, RTX 4060 = 8.9, GTX 1060 = 6.1
CC=""
if [[ $HAS_GPU -eq 1 ]]; then
  CC=$( (/usr/lib/wsl/lib/nvidia-smi --query-gpu=compute_cap --format=csv,noheader 2>/dev/null || nvidia-smi --query-gpu=compute_cap --format=csv,noheader) | head -1 | tr -d ' ')
fi
if [[ $HAS_GPU -eq 1 ]]; then ok "GPU NVIDIA terdeteksi: $( (/usr/lib/wsl/lib/nvidia-smi -L 2>/dev/null || nvidia-smi -L) | head -1)"; else warn "Tanpa GPU NVIDIA: render & whisper memakai CPU (kualitas sama, lebih lambat), AI upscale memakai scaling biasa."; fi

# ---------- 1. Paket sistem ----------
say "Memasang paket sistem (Apache, PHP, Python, aria2)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-curl php-mbstring php-xml php-zip \
  python3-venv python3-pip aria2 curl ca-certificates xz-utils unzip git build-essential cmake fontconfig rsync >/dev/null
ok "PHP $(php -r 'echo PHP_VERSION;'), Apache $(apache2 -v | head -1 | sed 's/.*Apache\///;s/ .*//')"

# Font subtitle bawaan "Arial Black" (font sistem di Mac & Windows, tidak ada di Linux): ambil dari Windows
say "Memasang font Arial Black…"
WINFONT=$(ls /mnt/c/Windows/Fonts/ariblk.ttf /mnt/c/Windows/Fonts/ARIBLK.TTF 2>/dev/null | head -1 || true)
if [[ -n "$WINFONT" ]]; then
  mkdir -p /usr/local/share/fonts/bisaclip && cp "$WINFONT" /usr/local/share/fonts/bisaclip/ariblk.ttf && fc-cache -f >/dev/null
fi
fc-list | grep -qi "Arial Black" && ok "Arial Black" || warn "Arial Black tidak ditemukan: salin ariblk.ttf ke /usr/local/share/fonts lalu jalankan fc-cache -f"

# ---------- 2. ffmpeg (build statis lengkap: libass, x264, x265, NVENC) ----------
say "Memasang ffmpeg…"
TMPD=$(mktemp -d)
curl -fsSL -o "$TMPD/ff.tar.xz" https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz
rm -rf /opt/ffmpeg && mkdir -p /opt/ffmpeg
tar -xJf "$TMPD/ff.tar.xz" -C /opt/ffmpeg --strip-components=1
ln -sf /opt/ffmpeg/bin/ffmpeg /usr/local/bin/ffmpeg
ln -sf /opt/ffmpeg/bin/ffprobe /usr/local/bin/ffprobe
for f in libass libx264 libx265; do ffmpeg -hide_banner -buildconf | grep -q "enable-$f" || die "ffmpeg tanpa $f"; done
ok "$(ffmpeg -hide_banner -version | head -1 | cut -c1-60)"

# ---------- 3. yt-dlp + deno (runtime JS yang dibutuhkan yt-dlp untuk YouTube) ----------
say "Memasang yt-dlp & deno…"
curl -fsSL -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux
chmod 755 /usr/local/bin/yt-dlp
ok "yt-dlp $(/usr/local/bin/yt-dlp --version)"
if [[ ! -x /usr/local/bin/deno ]]; then
  curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh -s -- -y --no-modify-path >/dev/null
fi
ok "deno $(/usr/local/bin/deno --version | head -1 | awk '{print $2}')"

# ---------- 4. CUDA toolkit (untuk whisper.cpp versi GPU) ----------
# Selalu CUDA dari repo NVIDIA (/usr/local/cuda), bukan paket Ubuntu yang terlalu lama untuk RTX 50xx
if [[ $HAS_GPU -eq 1 ]] && [[ ! -x /usr/local/cuda/bin/nvcc ]]; then
  say "Memasang CUDA toolkit untuk WSL (±3 GB, sekali saja)…"
  curl -fsSL -o "$TMPD/keyring.deb" https://developer.download.nvidia.com/compute/cuda/repos/wsl-ubuntu/x86_64/cuda-keyring_1.1-1_all.deb
  dpkg -i "$TMPD/keyring.deb" >/dev/null
  apt-get update -qq
  apt-get install -y -qq cuda-toolkit >/dev/null
fi
if [[ $HAS_GPU -eq 1 ]] && [[ -x /usr/local/cuda/bin/nvcc ]]; then
  NVCC_V=$(/usr/local/cuda/bin/nvcc --version | sed -n 's/.*release \([0-9.]*\).*/\1/p')
  ok "CUDA $NVCC_V"
  # RTX 50xx (Blackwell) butuh CUDA 12.8 ke atas
  if [[ -n "$CC" ]] && awk "BEGIN{exit !($CC >= 12.0 && $NVCC_V < 12.8)}"; then
    apt-get install -y -qq cuda-toolkit >/dev/null || true
  fi
fi
export PATH="/usr/local/cuda/bin:$PATH"
# Library CUDA (dipakai whisper-cli) harus bisa ditemukan oleh semua user, termasuk www-data
if [[ -d /usr/local/cuda/lib64 ]]; then echo /usr/local/cuda/lib64 > /etc/ld.so.conf.d/bisaclip-cuda.conf; ldconfig; fi

# ---------- 5. whisper.cpp ----------
say "Membangun whisper.cpp…"
if [[ -d /opt/whisper.cpp/.git ]]; then git -C /opt/whisper.cpp pull -q; else git clone -q --depth 1 https://github.com/ggml-org/whisper.cpp /opt/whisper.cpp; fi
CUDA_FLAG=OFF
[[ $HAS_GPU -eq 1 ]] && command -v nvcc >/dev/null 2>&1 && CUDA_FLAG=ON
# Dikompilasi khusus untuk arsitektur GPU ini (RTX 50xx = sm_120, butuh CUDA 12.8+)
ARCH_FLAG=""
[[ $CUDA_FLAG == ON && -n "$CC" ]] && ARCH_FLAG="-DCMAKE_CUDA_ARCHITECTURES=${CC/./}"
rm -rf /opt/whisper.cpp/build
cmake -S /opt/whisper.cpp -B /opt/whisper.cpp/build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DGGML_CUDA=$CUDA_FLAG $ARCH_FLAG -DWHISPER_BUILD_TESTS=OFF >/dev/null
cmake --build /opt/whisper.cpp/build -j"$(nproc)" --target whisper-cli >/dev/null
ln -sf /opt/whisper.cpp/build/bin/whisper-cli /usr/local/bin/whisper-cli
ok "whisper-cli (CUDA: $CUDA_FLAG)"

# ---------- 6. Python venv (OpenCV deteksi wajah + PyTorch untuk AI upscale) ----------
say "Menyiapkan Python…"
VENV="$APP_DIR/storage/venv"
# venv bawaan Mac (kalau folder disalin dari Mac) tidak bisa dipakai di Linux
if [[ -d "$VENV" ]] && ! "$VENV/bin/python" -c 'import sys' >/dev/null 2>&1; then rm -rf "$VENV"; fi
[[ -x "$VENV/bin/python" ]] || python3 -m venv "$VENV"
"$VENV/bin/pip" install -q --upgrade pip
"$VENV/bin/pip" install -q numpy opencv-python-headless fonttools
if [[ $HAS_GPU -eq 1 ]]; then
  # PyTorch CUDA 12.8 hanya untuk GPU RTX 20xx ke atas (compute capability >= 7.0); GTX 9xx/10xx memakai CUDA 12.6
  TORCH_CU=cu128
  [[ -n "$CC" ]] && awk "BEGIN{exit !($CC < 7.0)}" && TORCH_CU=cu126
  "$VENV/bin/pip" install -q torch --index-url "https://download.pytorch.org/whl/$TORCH_CU"
  "$VENV/bin/python" -c 'import torch; assert torch.cuda.is_available()' && ok "PyTorch CUDA siap" || warn "PyTorch tidak melihat GPU: AI upscale memakai scaling biasa"
fi
ok "Python $("$VENV/bin/python" -V | awk '{print $2}')"

# ---------- 7. Model ----------
say "Mengunduh model yang belum ada…"
M="$APP_DIR/storage/models"
mkdir -p "$M"
fetch() { [[ -s "$M/$1" ]] && { ok "$1 sudah ada"; return; }; curl -fL --progress-bar -o "$M/$1.part" "$2" && mv "$M/$1.part" "$M/$1" && ok "$1"; }
fetch ggml-large-v3-turbo-q5_0.bin https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-large-v3-turbo-q5_0.bin
fetch face_detection_yunet.onnx https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx
[[ $HAS_GPU -eq 1 ]] && fetch realesr-general-x4v3.pth https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-general-x4v3.pth

# ---------- 8. Apache ----------
say "Mengatur Apache…"
cat > /etc/apache2/conf-available/bisaclip.conf <<EOF
# BisaClip: http://localhost/bisaclip/
Alias /bisaclip "$APP_DIR"
<Directory "$APP_DIR">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    php_admin_value upload_max_filesize 4G
    php_admin_value post_max_size 4G
    php_admin_value memory_limit 1024M
    php_admin_value max_execution_time 0
</Directory>
Timeout 600
EOF
a2enconf -q bisaclip
apache2ctl configtest 2>&1 | grep -q "Syntax OK" || die "Konfigurasi Apache error"

# Apache berjalan sebagai www-data: folder data harus bisa ditulis
mkdir -p "$APP_DIR"/{storage,downloads,previews,avatars}
# Kunci API & data internal tidak boleh bisa dibuka lewat browser
[[ -f "$APP_DIR/storage/.htaccess" ]] || echo "Require all denied" > "$APP_DIR/storage/.htaccess"
[[ -f "$APP_DIR/downloads/.htaccess" ]] || echo "Options -Indexes" > "$APP_DIR/downloads/.htaccess"
[[ -f "$APP_DIR/install/.htaccess" ]] || echo "Require all denied" > "$APP_DIR/install/.htaccess"
chown -R www-data:www-data "$APP_DIR"/{storage,downloads,previews,avatars}
chmod -R ug+rwX "$APP_DIR"/{storage,downloads,previews,avatars}
[[ -n "${SUDO_USER:-}" ]] && usermod -aG www-data "$SUDO_USER"
rm -f "$APP_DIR/storage/hwenc.json"   # deteksi encoder GPU diulang

if pidof systemd >/dev/null 2>&1; then systemctl enable -q apache2; systemctl restart apache2; else service apache2 restart >/dev/null; fi
ok "Apache berjalan"

# ---------- 9. Cek akhir ----------
say "Cek sistem"
sudo -u www-data php "$APP_DIR/install/doctor.php" || true
rm -rf "$TMPD"
printf '\n\033[1;32mSelesai.\033[0m Buka di browser Windows: \033[1mhttp://localhost/bisaclip/\033[0m\n'
printf 'Untuk menyalakan BisaClip berikutnya, klik dua kali install\\BisaClip.bat (lihat INSTALL-WINDOWS.md).\n\n'
