# Updates Windows hosts file with Stack Panel site entries.
# Usage: powershell -ExecutionPolicy Bypass -File <install>\update-hosts.ps1 -BlockFile <install>\htdocs\panel\data\hosts.block.txt

param(
  [Parameter(Mandatory = $true)]
  [string]$BlockFile
)

$ErrorActionPreference = 'Stop'
$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
$begin = '# --- stack-panel-sites-begin ---'
$end = '# --- stack-panel-sites-end ---'

if (-not (Test-Path -LiteralPath $BlockFile)) {
  throw "Block file not found: $BlockFile"
}

$block = (Get-Content -LiteralPath $BlockFile -Raw)
if ($null -eq $block) { $block = '' }
$block = $block.TrimEnd() + "`r`n"

$current = Get-Content -LiteralPath $hostsPath -Raw
if ($null -eq $current) { $current = '' }

# Collect hostnames from our block
$wanted = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
foreach ($line in ($block -split "`r?`n")) {
  $t = $line.Trim()
  if ($t -eq '' -or $t.StartsWith('#')) { continue }
  $parts = $t -split '\s+'
  if ($parts.Length -lt 2) { continue }
  for ($i = 1; $i -lt $parts.Length; $i++) {
    [void]$wanted.Add($parts[$i].ToLowerInvariant())
  }
}

# Remove previous managed block
$pattern = [regex]::new([regex]::Escape($begin) + '.*?' + [regex]::Escape($end) + '\s*', [System.Text.RegularExpressions.RegexOptions]::Singleline)
$withoutBlock = $pattern.Replace($current, '')

# Drop unmanaged lines that only duplicate our wanted names on 127.0.0.1 / ::1
$kept = New-Object System.Collections.Generic.List[string]
foreach ($line in ($withoutBlock -split "`r?`n")) {
  $trim = $line.Trim()
  if ($trim -eq '' -or $trim.StartsWith('#')) {
    $kept.Add($line)
    continue
  }
  $parts = $trim -split '\s+'
  if ($parts.Length -ge 2 -and ($parts[0] -eq '127.0.0.1' -or $parts[0] -eq '::1')) {
    $names = @()
    for ($i = 1; $i -lt $parts.Length; $i++) {
      $n = $parts[$i].ToLowerInvariant()
      if (-not $wanted.Contains($n)) { $names += $parts[$i] }
    }
    if ($names.Count -eq 0) { continue }
    $kept.Add($parts[0] + ' ' + ($names -join ' '))
    continue
  }
  $kept.Add($line)
}

# Collapse trailing blank lines
$text = ($kept -join "`r`n").TrimEnd()
$updated = $text + "`r`n`r`n" + $block

Set-Content -LiteralPath $hostsPath -Value $updated -Encoding ASCII
Write-Output 'Hosts updated OK'
