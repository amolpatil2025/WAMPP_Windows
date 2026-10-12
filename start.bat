@echo off
REM Start the tray in the background, then Apache, default PHP, Mailpit, and FTP.
REM Default PHP comes from htdocs\panel\data\php-runtime.json (currently 8.0).
start "" powershell -WindowStyle Hidden -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0tray\WebStackTray.ps1" -TrayCheckOnly
wscript //B //Nologo "%~dp0scripts\start-stack-silent.vbs"
start "" "http://localhost/panel/"
exit /b 0
