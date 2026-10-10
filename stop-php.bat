@echo off
REM Stop non-default PHP listeners only. Default (localhost) stays up.
set "ROOT=%~dp0"
cd /d "%ROOT%"
REM Prefer PHP 8.4 CLI for the helper; fall back through versions.
set PHPCLI=
if exist "%ROOT%php8.4.26\php.exe" set "PHPCLI=%ROOT%php8.4.26\php.exe"
if "%PHPCLI%"=="" if exist "%ROOT%php8.0.30\php.exe" set "PHPCLI=%ROOT%php8.0.30\php.exe"
if "%PHPCLI%"=="" if exist "%ROOT%php7.4.33\php.exe" set "PHPCLI=%ROOT%php7.4.33\php.exe"
if "%PHPCLI%"=="" (
  echo No PHP CLI found.
  exit /b 1
)
"%PHPCLI%" "%ROOT%htdocs\panel\cli\stop-php.php"
exit /b %ERRORLEVEL%
