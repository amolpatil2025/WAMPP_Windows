@echo off
REM Start Apache + default PHP + Mailpit (silent). Optional helpers live in scripts\

REM Start the tray if not already running (by mutex in the script)
set "TRAY_PS1=%~dp0tray\WebStackTray.ps1"
powershell -WindowStyle Hidden -NoProfile -ExecutionPolicy Bypass -File "%TRAY_PS1%" -TrayCheckOnly
REM ^ This script should do nothing (and exit quickly) if tray is already running.

wscript //B //Nologo "%~dp0scripts\start-stack-silent.vbs"
exit /b 0
