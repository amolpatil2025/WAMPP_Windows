' Start PHP listeners only — fully hidden (no console).
Option Explicit
Dim WshShell, fso, SCRIPTS, WEB
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
SCRIPTS = fso.GetParentFolderName(WScript.ScriptFullName)
WEB = fso.GetParentFolderName(SCRIPTS)
WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True

StartPhp WEB & "\php7.4.33", 9074
StartPhp WEB & "\php8.0.30", 9080
StartPhp WEB & "\php8.4.26", 9084
WScript.Quit 0

Sub StartPhp(phpDir, port)
  Dim exe
  exe = phpDir & "\php-cgi.exe"
  If Not fso.FileExists(exe) Then Exit Sub
  If IsListening(port) Then Exit Sub
  WshShell.Run "cmd /c set PHPRC=" & phpDir & "&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& """ & exe & """ -b 127.0.0.1:" & port & " -c """ & phpDir & """", 0, False
End Sub

Function IsListening(port)
  Dim rc
  rc = WshShell.Run("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING >nul", 0, True)
  IsListening = (rc = 0)
End Function
