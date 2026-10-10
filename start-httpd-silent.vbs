' Start Apache httpd with no console window.
Option Explicit
Dim WshShell, fso, httpd, web
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
web = fso.GetParentFolderName(WScript.ScriptFullName)
WshShell.Run "wscript //B //Nologo """ & web & "\write-install-root.vbs""", 0, True
httpd = web & "\Apache24\bin\httpd.exe"
If fso.FileExists(httpd) Then
  ' -d sets ServerRoot even when cwd is Startup / System32
  WshShell.Run """" & httpd & """ -d """ & web & "\Apache24""", 0, False
End If
