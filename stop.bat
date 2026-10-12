@echo off
REM Stop Apache, every PHP listener, Mailpit, and FTP for this install.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\stop-all.ps1"
exit /b %ERRORLEVEL%
