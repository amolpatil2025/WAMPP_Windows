' Launch Web Stack system tray icon (no console window).
Option Explicit
Dim WshShell, fso, scripts, web, cmd
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
scripts = fso.GetParentFolderName(WScript.ScriptFullName)
web = fso.GetParentFolderName(scripts)
cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File """ & web & "\tray\WebStackTray.ps1"""
WshShell.Run cmd, 0, False
