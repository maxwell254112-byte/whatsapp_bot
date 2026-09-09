@echo off
cd /d "%~dp0.."
set "PY=%LocalAppData%\Programs\Python\Python312\python.exe"
if not exist "%PY%" set "PY=python"
echo Opening WhatsApp Web for QR linking...
echo Keep this window open and scan the QR code.
"%PY%" main.py
pause
