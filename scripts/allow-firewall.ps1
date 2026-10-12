# Add inbound Windows Firewall allows for this install's Apache and PHP CLI.
# Started elevated by allow-firewall-elevate.ps1 after the user accepts UAC.
$ErrorActionPreference = 'Stop'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

$targets = @()
$httpd = Join-Path $Root 'Apache24\bin\httpd.exe'
if (Test-Path -LiteralPath $httpd) {
  $targets += @{ Name = 'PatilWeb Apache'; Path = $httpd }
}
Get-ChildItem -LiteralPath $Root -Directory -Filter 'php*' | ForEach-Object {
  $exe = Join-Path $_.FullName 'php.exe'
  if (Test-Path -LiteralPath $exe) {
    $targets += @{ Name = "PatilWeb PHP $($_.Name)"; Path = $exe }
  }
}

foreach ($t in $targets) {
  & netsh.exe advfirewall firewall delete rule name=all "program=$($t.Path)" | Out-Null
  $add = & netsh.exe advfirewall firewall add rule "name=$($t.Name)" dir=in action=allow "program=$($t.Path)" enable=yes profile=any
  if ($LASTEXITCODE -ne 0) {
    Write-Error (($add | Out-String).Trim())
    exit 1
  }
}
exit 0
