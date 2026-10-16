@echo off
REM Build ApachePHPWindows.exe with Authenticode signing (fixes "Unknown publisher").
REM
REM Prerequisites:
REM   1. Inno Setup 6+ (iscc.exe on PATH or set ISCC below)
REM   2. Windows SDK / Visual Studio "signtool.exe" on PATH
REM   3. A commercial code-signing certificate (.pfx) from DigiCert, Sectigo, etc.
REM      Self-signed certificates do NOT remove "Unknown publisher" on other PCs.
REM
REM Usage:
REM   set CODESIGN_PFX=C:\secure\patilweb-codesign.pfx
REM   set CODESIGN_PASSWORD=your-pfx-password
REM   compile-signed.bat
REM
setlocal
cd /d "%~dp0"

if "%CODESIGN_PFX%"=="" (
  echo ERROR: Set CODESIGN_PFX to your .pfx certificate path.
  echo Example:
  echo   set CODESIGN_PFX=C:\secure\patilweb-codesign.pfx
  echo   set CODESIGN_PASSWORD=your-password
  echo   "%~nx0"
  exit /b 1
)
if not exist "%CODESIGN_PFX%" (
  echo ERROR: Certificate not found: %CODESIGN_PFX%
  exit /b 1
)

set "ISCC=iscc"
where iscc >nul 2>&1
if errorlevel 1 (
  if exist "%LocalAppData%\Programs\Inno Setup 6\ISCC.exe" set "ISCC=%LocalAppData%\Programs\Inno Setup 6\ISCC.exe"
  if exist "%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" set "ISCC=%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe"
  if exist "%ProgramFiles%\Inno Setup 6\ISCC.exe" set "ISCC=%ProgramFiles%\Inno Setup 6\ISCC.exe"
)

where signtool >nul 2>&1
if errorlevel 1 (
  echo ERROR: signtool.exe not found. Install Windows SDK or add signtool to PATH.
  exit /b 1
)

REM Sign tool name "patilweb" must match SignTool= in setup.iss
set "SIGNCMD=signtool sign /f \"%CODESIGN_PFX%\" /fd sha256 /tr http://timestamp.digicert.com /td sha256 /d \"PatilWeb CPanel\" /du \"https://www.patilweb.com/\" "
if not "%CODESIGN_PASSWORD%"=="" set "SIGNCMD=%SIGNCMD%/p \"%CODESIGN_PASSWORD%\" "
set "SIGNCMD=%SIGNCMD%$f"

echo Compiling and signing installer...
"%ISCC%" "setup.iss" "/Spatilweb=%SIGNCMD%"
if errorlevel 1 exit /b 1

echo.
echo Done. Signed installer is in your OutputDir (see setup.iss OutputDir).
echo Verify:  signtool verify /pa /v "%%USERPROFILE%%\Downloads\ApachePHPWindows.exe"
endlocal
