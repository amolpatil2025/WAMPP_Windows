# Stop this install: Apache, every PHP listener, Mailpit, and FTP.
# MySQL is not part of this folder, so it is left running.
$ErrorActionPreference = 'SilentlyContinue'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

function Stop-Pid([int]$ProcessId) {
  if ($ProcessId -gt 0) {
    & taskkill.exe /F /T /PID $ProcessId 2>$null | Out-Null
  }
}

function Stop-Port([int]$Port) {
  $lines = & netstat.exe -ano | Select-String -Pattern (":$Port\s") | Select-String 'LISTENING'
  foreach ($line in $lines) {
    if ($line.Line -match '\s(\d+)\s*$') {
      Stop-Pid ([int]$Matches[1])
    }
  }
}

& taskkill.exe /F /T /IM httpd.exe 2>$null | Out-Null

Stop-Port 1025
Stop-Port 8025

$ftpPort = 2121
$ftpJson = Join-Path $Root 'ftp\ftp.json'
if (Test-Path -LiteralPath $ftpJson) {
  try {
    $cfg = Get-Content -LiteralPath $ftpJson -Raw | ConvertFrom-Json
    if ($cfg.port) { $ftpPort = [int]$cfg.port }
  } catch {}
}
if ($ftpPort -ge 1 -and $ftpPort -le 65535) {
  Stop-Port $ftpPort
}

$runtime = Join-Path $Root 'htdocs\panel\data\php-runtime.json'
if (Test-Path -LiteralPath $runtime) {
  try {
    $data = Get-Content -LiteralPath $runtime -Raw | ConvertFrom-Json
    foreach ($ver in $data.versions.PSObject.Properties) {
      $port = [int]$ver.Value.port
      if ($port -ge 1 -and $port -le 65535) { Stop-Port $port }
    }
  } catch {}
}

$rootLower = $Root.TrimEnd('\').ToLower()
Get-CimInstance Win32_Process -Filter "Name = 'php-cgi.exe' OR Name = 'php.exe' OR Name = 'mailpit.exe'" | ForEach-Object {
  $path = [string]$_.ExecutablePath
  if ($path -and $path.ToLower().StartsWith($rootLower)) {
    Stop-Pid ([int]$_.ProcessId)
  }
}

Write-Output 'Stopped Apache, PHP, Mailpit, and FTP.'
