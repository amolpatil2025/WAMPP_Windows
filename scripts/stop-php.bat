@echo off
REM Manual: stop non-default PHP listeners only. Default (localhost) stays up.
set "SCRIPTS=%~dp0"
set "ROOT=%~dp0.."
cd /d "%ROOT%"
set PHPCLI=
if exist "%ROOT%\php8.4.26\php.exe" set "PHPCLI=%ROOT%\php8.4.26\php.exe"
if "%PHPCLI%"=="" if exist "%ROOT%\php8.0.30\php.exe" set "PHPCLI=%ROOT%\php8.0.30\php.exe"
if "%PHPCLI%"=="" if exist "%ROOT%\php7.4.33\php.exe" set "PHPCLI=%ROOT%\php7.4.33\php.exe"
if "%PHPCLI%"=="" (
  echo No PHP CLI found.
  exit /b 1
)
"%PHPCLI%" "%ROOT%\htdocs\panel\cli\stop-php.php"
exit /b %ERRORLEVEL%
