@echo off
cd /d "%~dp0.."
set "PY=%LocalAppData%\Programs\Python\Python312\python.exe"
if not exist "%PY%" set "PY=python"
"%PY%" -m pip install -r requirements.txt
"%PY%" -m playwright install chromium
echo Done.
pause
