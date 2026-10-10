@echo off
REM Restart one PHP FastCGI listener after a short delay so the HTTP response can finish.
REM Usage: restart-php-delayed.bat 8.4
set "VER=%~1"
if "%VER%"=="" exit /b 1
timeout /t 2 /nobreak >nul
wscript //B //Nologo "%~dp0..\htdocs\panel\cli\restart-php.vbs" "%VER%"
