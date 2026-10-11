' Fully silent stack start — default PHP + Mailpit + FTP + Apache, no console windows.
' On Windows Startup / tray launch: only the panel default PHP is started (not all versions).
' This file lives in scripts\; install root is the parent folder.
Option Explicit

Dim WshShell, fso, SCRIPTS, WEB
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

SCRIPTS = fso.GetParentFolderName(WScript.ScriptFullName)
WEB = fso.GetParentFolderName(SCRIPTS)

' Rewrite php.ini / Apache WEBROOT for this folder before starting listeners
WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True

StartDefaultPhp

' Local mail catcher (SMTP 1025 / UI 8025)
If fso.FileExists(SCRIPTS & "\start-mailpit-silent.vbs") Then
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\start-mailpit-silent.vbs""", 0, False
End If

' Local FTP (default 127.0.0.1:2121)
If fso.FileExists(SCRIPTS & "\start-ftp-silent.vbs") Then
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\start-ftp-silent.vbs""", 0, False
End If

' Apache after PHP/Mail; retry — at login port 80 can be briefly busy,
' and a naive findstr ":80 " falsely matches Mailpit :8025.
StartApacheWithRetry

WScript.Quit 0

Sub StartDefaultPhp
  Dim ver
  ver = GetDefaultPhpVersion()
  Select Case ver
    Case "7.4"
      StartPhp WEB & "\php7.4.33", 9074
    Case "8.4"
      StartPhp WEB & "\php8.4.26", 9084
    Case Else
      StartPhp WEB & "\php8.0.30", 9080
  End Select
End Sub

Function GetDefaultPhpVersion()
  Dim path, raw, p, q1, q2, ver
  GetDefaultPhpVersion = "8.0"
  path = WEB & "\htdocs\panel\data\settings.json"
  If Not fso.FileExists(path) Then Exit Function
  raw = ReadAllText(path)
  p = InStr(1, LCase(raw), """default_php""")
  If p < 1 Then Exit Function
  p = InStr(p, raw, ":")
  If p < 1 Then Exit Function
  q1 = InStr(p, raw, """")
  If q1 < 1 Then Exit Function
  q2 = InStr(q1 + 1, raw, """")
  If q2 <= q1 Then Exit Function
  ver = Mid(raw, q1 + 1, q2 - q1 - 1)
  If ver = "7.4" Or ver = "8.0" Or ver = "8.4" Then GetDefaultPhpVersion = ver
End Function

Function ReadAllText(path)
  Dim ts
  Set ts = fso.OpenTextFile(path, 1)
  ReadAllText = ts.ReadAll
  ts.Close
End Function

Sub StartPhp(phpDir, port)
  Dim exe
  exe = phpDir & "\php-cgi.exe"
  If Not fso.FileExists(exe) Then Exit Sub
  If IsListening(port) Then Exit Sub
  WshShell.Run "cmd /c set PHPRC=" & phpDir & "&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& """ & exe & """ -b 127.0.0.1:" & port & " -c """ & phpDir & """", 0, False
End Sub

Sub StartApacheWithRetry
  Dim httpd, i
  httpd = WEB & "\Apache24\bin\httpd.exe"
  If Not fso.FileExists(httpd) Then Exit Sub
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True
  For i = 1 To 6
    If IsListening(80) Then Exit Sub
    ' -d sets ServerRoot even when cwd is Startup / System32
    WshShell.Run """" & httpd & """ -d """ & WEB & "\Apache24""", 0, False
    WScript.Sleep 2500
    If IsListening(80) Then Exit Sub
  Next
End Sub

Function IsListening(port)
  Dim rc
  ' /C: = literal phrase. Without it, findstr splits on spaces and
  ' ":80 " becomes ":80", which falsely matches Mailpit :8025.
  rc = WshShell.Run("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING >nul", 0, True)
  IsListening = (rc = 0)
End Function
