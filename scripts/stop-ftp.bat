@echo off
REM Stop the Web Stack FTP server (port from ftp\ftp.json, default 2121)
setlocal
set PORT=2121
set CFG=%~dp0..\ftp\ftp.json
if exist "%CFG%" (
  for /f "usebackq tokens=1,* delims=:" %%a in (`findstr /I /C:"\"port\"" "%CFG%"`) do (
    for /f "tokens=1 delims=, " %%n in ("%%b") do set PORT=%%n
  )
)
for /f "tokens=5" %%a in ('netstat -ano ^| findstr /C:":%PORT% " ^| findstr LISTENING') do (
  taskkill /F /PID %%a >nul 2>&1
)
exit /b 0
