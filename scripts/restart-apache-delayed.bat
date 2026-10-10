@echo off
REM Restart Apache with no visible console.
timeout /t 2 /nobreak >nul
taskkill /F /IM httpd.exe >nul 2>&1
timeout /t 1 /nobreak >nul
wscript //B //Nologo "%~dp0start-httpd-silent.vbs"
