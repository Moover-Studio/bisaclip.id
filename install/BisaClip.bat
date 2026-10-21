@echo off
title BisaClip
rem Menyalakan BisaClip (Apache di WSL2) lalu membuka browser.
rem Biarkan jendela ini terbuka (boleh di-minimize) selama memakai BisaClip; tutup untuk mematikan.
wsl -u root -- service apache2 start
if errorlevel 1 (
  echo Gagal menyalakan Apache di WSL. Pastikan Ubuntu WSL sudah terpasang dan setup-wsl.sh sudah dijalankan.
  pause
  exit /b 1
)
start "" http://localhost/bisaclip/
echo.
echo  BisaClip berjalan di http://localhost/bisaclip/
echo  Biarkan jendela ini terbuka (boleh di-minimize). Tutup jendela untuk mematikan.
echo.
wsl -u root -- sleep infinity
