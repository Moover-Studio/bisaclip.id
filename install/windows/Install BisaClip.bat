@echo off
title Install BisaClip
rem Pasang atau update BisaClip di laptop Windows ini (WSL2 + Ubuntu). Aman dijalankan ulang.
rem Tanpa GPU NVIDIA / lewati CUDA: jalankan dari Command Prompt dengan  "Install BisaClip.bat" -NoCuda
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-windows.ps1" %*
echo.
pause
