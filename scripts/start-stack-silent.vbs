' Fully silent stack start — default PHP + Mailpit + FTP + Apache, no console windows.
Option Explicit

Dim WshShell, fso, SCRIPTS, WEB
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

SCRIPTS = fso.GetParentFolderName(WScript.ScriptFullName)
WEB = fso.GetParentFolderName(SCRIPTS)

WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True

StartDefaultPhp

If fso.FileExists(SCRIPTS & "\start-mailpit-silent.vbs") Then
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\start-mailpit-silent.vbs""", 0, False
End If

If fso.FileExists(SCRIPTS & "\start-ftp-silent.vbs") Then
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\start-ftp-silent.vbs""", 0, False
End If

StartApacheWithRetry
WScript.Quit 0

Sub StartDefaultPhp
  Dim raw, key, slice, dir, port
  raw = ReadAllText(WEB & "\htdocs\panel\data\php-runtime.json")
  key = "8.0"
  If raw <> "" Then
    Dim picked
    picked = JsonString(raw, "default")
    If picked <> "" Then key = picked
  End If
  dir = ""
  port = 0
  If raw <> "" Then
    slice = VersionSlice(raw, key)
    If slice <> "" Then
      dir = Replace(JsonString(slice, "dir"), "\\", "\")
      dir = Replace(dir, "/", "\")
      port = JsonNumber(slice, "port")
    End If
  End If
  If dir = "" Or Not fso.FileExists(dir & "\php-cgi.exe") Then
    dir = FindPhpDirByKey(key)
  End If
  If port < 1 Then port = PreferredPhpPort(key)
  If dir = "" Then Exit Sub
  StartPhp dir, port
End Sub

Function VersionSlice(raw, key)
  Dim token, p
  VersionSlice = ""
  token = """" & key & """:"
  p = InStr(1, raw, token)
  If p < 1 Then Exit Function
  VersionSlice = Mid(raw, p, 900)
End Function

Function JsonNumber(raw, key)
  Dim p, i, ch, num
  JsonNumber = 0
  p = InStr(1, LCase(raw), """" & LCase(key) & """")
  If p < 1 Then Exit Function
  p = InStr(p, raw, ":")
  If p < 1 Then Exit Function
  num = ""
  For i = p + 1 To Len(raw)
    ch = Mid(raw, i, 1)
    If ch >= "0" And ch <= "9" Then
      num = num & ch
    ElseIf num <> "" Then
      Exit For
    End If
  Next
  If num <> "" Then JsonNumber = CLng(num)
End Function

Function FindPhpDirByKey(key)
  Dim fold, subf, prefix
  FindPhpDirByKey = ""
  prefix = "php" & key
  On Error Resume Next
  Set fold = fso.GetFolder(WEB)
  If Err.Number <> 0 Then Exit Function
  On Error GoTo 0
  For Each subf In fold.SubFolders
    If LCase(Left(subf.Name, Len(prefix))) = LCase(prefix) Then
      If fso.FileExists(subf.Path & "\php-cgi.exe") Then
        FindPhpDirByKey = subf.Path
        Exit Function
      End If
    End If
  Next
End Function

Function PreferredPhpPort(key)
  Dim parts, maj, mino
  PreferredPhpPort = 9080
  parts = Split(key & ".0", ".")
  If UBound(parts) < 1 Then Exit Function
  maj = CLng(parts(0))
  mino = CLng(parts(1))
  PreferredPhpPort = 9000 + (maj * 10) + mino
End Function

Sub StartPhp(phpDir, port)
  Dim exe
  exe = phpDir & "\php-cgi.exe"
  If Not fso.FileExists(exe) Then Exit Sub
  If IsListening(port) Then Exit Sub
  WshShell.Run "cmd /c set PHPRC=" & phpDir & "&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& """ & exe & """ -b 127.0.0.1:" & port & " -c """ & phpDir & """", 0, False
  Dim i
  For i = 1 To 10
    WScript.Sleep 400
    If IsListening(port) Then Exit Sub
  Next
End Sub

Sub StartApacheWithRetry
  Dim httpd, i
  httpd = WEB & "\Apache24\bin\httpd.exe"
  If Not fso.FileExists(httpd) Then Exit Sub
  WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True
  For i = 1 To 6
    If IsListening(80) Then Exit Sub
    WshShell.Run """" & httpd & """ -d """ & WEB & "\Apache24""", 0, False
    WScript.Sleep 2500
    If IsListening(80) Then Exit Sub
  Next
End Sub

Function JsonString(raw, key)
  Dim p, q1, q2
  JsonString = ""
  p = InStr(1, LCase(raw), """" & LCase(key) & """")
  If p < 1 Then Exit Function
  p = InStr(p, raw, ":")
  If p < 1 Then Exit Function
  q1 = InStr(p, raw, """")
  If q1 < 1 Then Exit Function
  q2 = InStr(q1 + 1, raw, """")
  If q2 <= q1 Then Exit Function
  JsonString = Mid(raw, q1 + 1, q2 - q1 - 1)
End Function

Function ReadAllText(path)
  Dim ts
  ReadAllText = ""
  If Not fso.FileExists(path) Then Exit Function
  Set ts = fso.OpenTextFile(path, 1)
  ReadAllText = ts.ReadAll
  ts.Close
End Function

Function IsListening(port)
  Dim rc
  rc = WshShell.Run("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING >nul", 0, True)
  IsListening = (rc = 0)
End Function
