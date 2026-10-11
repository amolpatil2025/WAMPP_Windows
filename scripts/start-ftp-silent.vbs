' Start the Web Stack FTP server (plain FTP, default :2121) — fully hidden.
Option Explicit
Dim WshShell, fso, scripts, web, php, port, cmd
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

scripts = fso.GetParentFolderName(WScript.ScriptFullName)
web = fso.GetParentFolderName(scripts)
port = ReadFtpPort()
If IsListening(port) Then WScript.Quit 0

php = FindPhpExe()
If php = "" Then WScript.Quit 1

cmd = """" & php & """ """ & scripts & "\ftp-server.php"""
WshShell.Run cmd, 0, False
WScript.Quit 0

Function ReadFtpPort()
  Dim path, raw, p, tail, i, ch, num
  ReadFtpPort = 2121
  path = web & "\ftp\ftp.json"
  If Not fso.FileExists(path) Then Exit Function
  raw = ReadAllText(path)
  p = InStr(1, LCase(raw), """port""")
  If p < 1 Then Exit Function
  p = InStr(p, raw, ":")
  If p < 1 Then Exit Function
  tail = Mid(raw, p + 1)
  num = ""
  For i = 1 To Len(tail)
    ch = Mid(tail, i, 1)
    If ch >= "0" And ch <= "9" Then
      num = num & ch
    ElseIf num <> "" Then
      Exit For
    End If
  Next
  If num <> "" Then
    If CLng(num) >= 1 And CLng(num) <= 65535 Then ReadFtpPort = CLng(num)
  End If
End Function

Function GetDefaultPhpVersion()
  Dim path, raw, p, q1, q2, ver
  GetDefaultPhpVersion = "8.0"
  path = web & "\htdocs\panel\data\settings.json"
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

Function PhpPath(ver)
  Select Case ver
    Case "7.4": PhpPath = web & "\php7.4.33\php.exe"
    Case "8.0": PhpPath = web & "\php8.0.30\php.exe"
    Case "8.4": PhpPath = web & "\php8.4.26\php.exe"
    Case Else: PhpPath = ""
  End Select
End Function

Function FindPhpExe()
  Dim ver, list, i, exe
  ver = GetDefaultPhpVersion()
  list = Array(ver, "8.4", "8.0", "7.4")
  For i = 0 To UBound(list)
    exe = PhpPath(list(i))
    If exe <> "" Then
      If fso.FileExists(exe) Then
        FindPhpExe = exe
        Exit Function
      End If
    End If
  Next
  FindPhpExe = ""
End Function

Function ReadAllText(path)
  Dim ts
  Set ts = fso.OpenTextFile(path, 1)
  ReadAllText = ts.ReadAll
  ts.Close
End Function

Function IsListening(port)
  Dim rc
  rc = WshShell.Run("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING >nul", 0, True)
  IsListening = (rc = 0)
End Function
