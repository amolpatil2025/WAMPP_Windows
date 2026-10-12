# Show the Windows approval prompt, then add the firewall allows.
$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot 'allow-firewall.ps1'
try {
  $proc = Start-Process -FilePath 'powershell.exe' -Verb RunAs -Wait -PassThru -WindowStyle Hidden -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', $script
  )
  if ($null -eq $proc) { exit 2 }
  exit [int]$proc.ExitCode
} catch {
  if ($_.Exception.Message -match 'cancel') { exit 2 }
  Write-Output $_.Exception.Message
  exit 1
}
