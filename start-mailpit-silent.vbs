' Start Mailpit (local SMTP catcher + web UI) — fully hidden.
Option Explicit
Dim WshShell, fso, exe, dataDir, cmd, web
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

web = fso.GetParentFolderName(WScript.ScriptFullName)
exe = web & "\mailpit\mailpit.exe"
dataDir = web & "\mailpit\data"

If Not fso.FileExists(exe) Then WScript.Quit 1
If IsListening(1025) Then WScript.Quit 0

If Not fso.FolderExists(dataDir) Then fso.CreateFolder dataDir

' SMTP :1025 · UI :8025 · persist messages under mailpit\data
cmd = """" & exe & """ --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025 --database """ & dataDir & "\mailpit.db"" --quiet"
WshShell.Run cmd, 0, False
WScript.Quit 0

Function IsListening(port)
  Dim rc
  ' /C: = literal phrase (avoids ":80 " matching Mailpit :8025)
  rc = WshShell.Run("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING >nul", 0, True)
  IsListening = (rc = 0)
End Function
