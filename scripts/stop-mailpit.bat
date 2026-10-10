@echo off
REM Stop Mailpit (SMTP catcher)
for /f "tokens=5" %%a in ('netstat -ano ^| findstr /C:":1025 " ^| findstr LISTENING') do (
  taskkill /F /PID %%a >nul 2>&1
)
exit /b 0
