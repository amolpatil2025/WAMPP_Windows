# Web Stack system tray (XAMPP-style). Run hidden via start-tray-silent.vbs
# Requires STA: powershell -STA -WindowStyle Hidden -File this.ps1

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$ErrorActionPreference = 'SilentlyContinue'
$WebRoot = Split-Path -Parent $PSScriptRoot
$PanelUrl = 'http://localhost/panel/'
$MutexName = 'Local\WebStackTrayMutex'
$trayCheckOnly = $args -contains '-TrayCheckOnly'

$mutex = New-Object System.Threading.Mutex($false, $MutexName)
if (-not $mutex.WaitOne(0, $false)) {
  if (-not $trayCheckOnly) {
    [System.Windows.Forms.MessageBox]::Show('PatilWeb CPanel tray is already running.', 'PatilWeb CPanel') | Out-Null
  }
  exit 0
}

function Test-Port([int]$Port) {
  $out = & netstat -ano 2>$null | Select-String ":$Port " | Select-String 'LISTENING'
  return $null -ne $out
}

function Get-PhpRuntime {
  $path = Join-Path $WebRoot 'htdocs\panel\data\php-runtime.json'
  if (-not (Test-Path $path)) { return $null }
  try {
    return Get-Content $path -Raw | ConvertFrom-Json
  } catch {
    return $null
  }
}

function Get-DefaultPhp {
  $rt = Get-PhpRuntime
  if ($rt -and $rt.default) { return [string]$rt.default }
  $settings = Join-Path $WebRoot 'htdocs\panel\data\settings.json'
  $ver = '8.0'
  if (Test-Path $settings) {
    try {
      $j = Get-Content $settings -Raw | ConvertFrom-Json
      if ($j.default_php) { $ver = [string]$j.default_php }
    } catch {}
  }
  return $ver
}

function Get-PhpMap {
  $map = @{}
  $rt = Get-PhpRuntime
  if ($rt -and $rt.versions) {
    foreach ($prop in $rt.versions.PSObject.Properties) {
      $map[$prop.Name] = @{
        Dir = [string]$prop.Value.dir
        Port = [int]$prop.Value.port
        Label = [string]$prop.Value.label
      }
    }
  }
  return $map
}

function Start-Hidden([string]$Command) {
  $vbs = Join-Path $WebRoot 'scripts\run-hidden.vbs'
  if (Test-Path $vbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//B', '//Nologo', "`"$vbs`"", "`"$Command`"") -WindowStyle Hidden
  } else {
    Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', $Command) -WindowStyle Hidden
  }
}

function Start-StackSilent {
  $vbs = Join-Path $WebRoot 'scripts\start-stack-silent.vbs'
  if (Test-Path $vbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//B', '//Nologo', "`"$vbs`"") -WindowStyle Hidden
  }
}

function Stop-AllServices {
  $bat = Join-Path $WebRoot 'stop.bat'
  if (Test-Path -LiteralPath $bat) {
    Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', "`"$bat`"") -WindowStyle Hidden -Wait
  }
}

function Get-BrowserAppExe {
  $candidates = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe"
  )
  foreach ($p in $candidates) {
    if ($p -and (Test-Path -LiteralPath $p)) { return $p }
  }
  return $null
}

# Open URL in a dedicated Windows app window (no browser tabs/chrome).
function Open-AppWindow([string]$Url, [string]$ProfileName = 'WebStackApp') {
  $exe = Get-BrowserAppExe
  if (-not $exe) {
    Start-Process $Url
    return
  }
  $profile = Join-Path $env:LOCALAPPDATA $ProfileName
  if (-not (Test-Path -LiteralPath $profile)) {
    New-Item -ItemType Directory -Path $profile -Force | Out-Null
  }
  $args = @(
    "--app=$Url",
    "--user-data-dir=$profile",
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    '--new-window'
  )
  Start-Process -FilePath $exe -ArgumentList $args
}

function Open-StackPanel {
  Open-AppWindow -Url $PanelUrl -ProfileName 'WebStackPanelApp'
}

function Start-PhpVersion([string]$Version) {
  $map = Get-PhpMap
  if (-not $map.ContainsKey($Version)) { return }
  $m = $map[$Version]
  if (Test-Port $m.Port) { return }
  $cmd = "cmd /c set PHPRC=$($m.Dir)&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& `"$($m.Dir)\php-cgi.exe`" -b 127.0.0.1:$($m.Port) -c `"$($m.Dir)`""
  Start-Hidden $cmd
}

function Stop-PhpVersion([string]$Version, [switch]$ForceDefault) {
  $default = Get-DefaultPhp
  if (-not $ForceDefault -and $Version -eq $default) {
    [System.Windows.Forms.MessageBox]::Show("Cannot stop default PHP $Version (used by localhost).", 'Web Stack') | Out-Null
    return
  }
  $map = Get-PhpMap
  if (-not $map.ContainsKey($Version)) { return }
  $port = $map[$Version].Port
  $lines = & netstat -ano 2>$null | Select-String ":$port " | Select-String 'LISTENING'
  foreach ($line in $lines) {
    if ($line -match '\s(\d+)\s*$') {
      Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/F', '/PID', $Matches[1]) -WindowStyle Hidden -Wait
    }
  }
}

function Stop-PhpOthers {
  $default = Get-DefaultPhp
  foreach ($v in (Get-PhpMap).Keys) {
    if ($v -ne $default) { Stop-PhpVersion $v -ForceDefault }
  }
}

function Stop-Apache {
  Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/F', '/IM', 'httpd.exe') -WindowStyle Hidden -Wait
  # Default PHP only serves via Apache — stop it too
  Stop-PhpVersion (Get-DefaultPhp) -ForceDefault
}

function Start-Apache {
  # Bring default PHP back with Apache
  Start-PhpVersion (Get-DefaultPhp)
  if (Test-Port 80) { return }
  $vbs = Join-Path $WebRoot 'scripts\start-httpd-silent.vbs'
  if (Test-Path $vbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//B', '//Nologo', "`"$vbs`"") -WindowStyle Hidden
    return
  }
  $httpd = Join-Path $WebRoot 'Apache24\bin\httpd.exe'
  if (Test-Path $httpd) {
    Start-Hidden "`"$httpd`" -d `"$WebRoot\Apache24`""
  }
}

function Start-Mailpit {
  $vbs = Join-Path $WebRoot 'scripts\start-mailpit-silent.vbs'
  if (Test-Path $vbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//B', '//Nologo', "`"$vbs`"") -WindowStyle Hidden
  }
}

function Stop-Mailpit {
  $lines = & netstat -ano 2>$null | Select-String ':1025 ' | Select-String 'LISTENING'
  foreach ($line in $lines) {
    if ($line -match '\s(\d+)\s*$') {
      Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/F', '/PID', $Matches[1]) -WindowStyle Hidden -Wait
    }
  }
}

function Get-FtpPort {
  $path = Join-Path $WebRoot 'ftp\ftp.json'
  $port = 2121
  if (Test-Path $path) {
    try {
      $j = Get-Content $path -Raw | ConvertFrom-Json
      if ($j.port) { $port = [int]$j.port }
    } catch {}
  }
  if ($port -lt 1 -or $port -gt 65535) { $port = 2121 }
  return $port
}

function Start-Ftp {
  $vbs = Join-Path $WebRoot 'scripts\start-ftp-silent.vbs'
  if (Test-Path $vbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList @('//B', '//Nologo', "`"$vbs`"") -WindowStyle Hidden
  }
}

function Stop-Ftp {
  $port = Get-FtpPort
  $lines = & netstat -ano 2>$null | Select-String ":$port " | Select-String 'LISTENING'
  foreach ($line in $lines) {
    if ($line -match '\s(\d+)\s*$') {
      Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/F', '/PID', $Matches[1]) -WindowStyle Hidden -Wait
    }
  }
}

function Get-StatusText {
  $parts = @()
  $parts += if (Test-Port 80) { 'Apache: ON' } else { 'Apache: OFF' }
  $parts += if (Test-Port (Get-FtpPort)) { 'FTP:ON' } else { 'FTP:OFF' }
  $parts += if (Test-Port 1025) { 'Mail:ON' } else { 'Mail:OFF' }
  $map = Get-PhpMap
  foreach ($key in ($map.Keys | Sort-Object)) {
    $on = if (Test-Port $map[$key].Port) { 'ON' } else { 'OFF' }
    $parts += "$key`:$on"
  }
  $parts += 'default ' + (Get-DefaultPhp)
  return ($parts -join ' | ')
}

function New-TrayIcon {
  $logo = Join-Path $WebRoot 'htdocs\panel\assets\patilweb-logo.jpg'
  if (Test-Path $logo) {
    $src = [System.Drawing.Image]::FromFile($logo)
    $bmp = New-Object System.Drawing.Bitmap 32, 32
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.Clear([System.Drawing.Color]::White)
    $g.DrawImage($src, 1, 1, 30, 30)
    $g.Dispose()
    $src.Dispose()
    return [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
  }
  $bmp = New-Object System.Drawing.Bitmap 32, 32
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.Clear([System.Drawing.Color]::FromArgb(0, 0, 0, 0))
  $brush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 32, 160, 110))
  $g.FillEllipse($brush, 2, 2, 28, 28)
  $font = New-Object System.Drawing.Font 'Segoe UI', 11, ([System.Drawing.FontStyle]::Bold)
  $tbrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
  $g.DrawString('P', $font, $tbrush, 8, 6)
  $g.Dispose()
  $icon = [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
  return $icon
}

$form = New-Object System.Windows.Forms.Form
$form.Text = 'PatilWeb CPanel'
$form.ShowInTaskbar = $false
$form.WindowState = 'Minimized'
$form.Visible = $false
$form.Opacity = 0

$notify = New-Object System.Windows.Forms.NotifyIcon
$notify.Icon = New-TrayIcon
$notify.Text = 'PatilWeb CPanel'
$notify.Visible = $true

$menu = New-Object System.Windows.Forms.ContextMenuStrip

function Add-MenuItem([string]$Text, $Action) {
  $item = New-Object System.Windows.Forms.ToolStripMenuItem $Text
  if ($null -ne $Action) {
    $item.add_Click($Action)
  }
  [void]$menu.Items.Add($item)
  return $item
}

function Add-Separator {
  [void]$menu.Items.Add((New-Object System.Windows.Forms.ToolStripSeparator))
}

Add-MenuItem 'Open PatilWeb CPanel' { Open-StackPanel } | Out-Null
Add-MenuItem 'Open localhost' { Open-AppWindow -Url 'http://localhost/' -ProfileName 'WebStackLocalApp' } | Out-Null
Add-MenuItem 'Open Mail inbox' { Open-AppWindow -Url 'http://127.0.0.1:8025/' -ProfileName 'WebStackMailApp' } | Out-Null
Add-MenuItem 'Open panel in browser' { Start-Process $PanelUrl } | Out-Null
Add-Separator
Add-MenuItem 'Start All (Apache + default PHP + Mail + FTP)' { Start-StackSilent; Start-Sleep -Seconds 1; Update-Tray } | Out-Null
Add-MenuItem 'Stop All (Apache + PHP + Mail + FTP)' { Stop-AllServices; Update-Tray } | Out-Null
Add-MenuItem 'Stop PHP (keep default)' { Stop-PhpOthers; Update-Tray } | Out-Null
Add-MenuItem 'Stop Apache' { Stop-Apache; Update-Tray } | Out-Null
Add-MenuItem 'Restart Apache' {
  Stop-Apache
  Start-Sleep -Milliseconds 800
  Start-Apache
  Start-Sleep -Seconds 1
  Update-Tray
} | Out-Null
Add-MenuItem 'Start Mailpit' { Start-Mailpit; Start-Sleep -Milliseconds 600; Update-Tray } | Out-Null
Add-MenuItem 'Stop Mailpit' { Stop-Mailpit; Update-Tray } | Out-Null
Add-MenuItem 'Start FTP' { Start-Ftp; Start-Sleep -Milliseconds 600; Update-Tray } | Out-Null
Add-MenuItem 'Stop FTP' { Stop-Ftp; Update-Tray } | Out-Null
Add-Separator

$statusItem = Add-MenuItem 'Status: …' $null
$statusItem.Enabled = $false

Add-Separator
$phpStopItems = @{}
foreach ($key in ((Get-PhpMap).Keys | Sort-Object)) {
  $k = $key
  Add-MenuItem ("PHP $k - Start") { Start-PhpVersion $k; Start-Sleep -Milliseconds 600; Update-Tray }.GetNewClosure() | Out-Null
  $phpStopItems[$k] = Add-MenuItem ("PHP $k - Stop") { Stop-PhpVersion $k; Update-Tray }.GetNewClosure()
}

Add-Separator
Add-MenuItem 'Exit tray' {
  $timer.Stop()
  $notify.Visible = $false
  $notify.Dispose()
  [System.Windows.Forms.Application]::Exit()
} | Out-Null

$notify.ContextMenuStrip = $menu
$notify.add_DoubleClick({ Open-StackPanel })

function Update-Tray {
  $text = Get-StatusText
  if ($text.Length -gt 63) { $text = $text.Substring(0, 63) }
  $notify.Text = $text
  $statusItem.Text = Get-StatusText
  $default = Get-DefaultPhp
  foreach ($key in $phpStopItems.Keys) {
    $item = $phpStopItems[$key]
    $item.Enabled = ($default -ne $key)
    $item.Text = if ($default -eq $key) { "PHP $key - Stop (default)" } else { "PHP $key - Stop" }
  }
}

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 5000
$timer.add_Tick({ Update-Tray })
$timer.Start()

# Ensure stack is up when tray starts (silent). Wait long enough for
# start-stack-silent.vbs Apache retries after login.
Start-StackSilent
Start-Sleep -Seconds 3
if (-not (Test-Port 80)) {
  Start-Apache
  Start-Sleep -Seconds 2
}
Update-Tray

$notify.ShowBalloonTip(2500, 'PatilWeb CPanel', 'Tray is running. Right-click the icon for Start/Stop.', [System.Windows.Forms.ToolTipIcon]::Info)

[System.Windows.Forms.Application]::Run()
$mutex.ReleaseMutex() | Out-Null
