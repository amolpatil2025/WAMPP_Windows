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

Function FindPhpExe()
  Dim fold, subf, exe, phpExe, out, parts
  FindPhpExe = ""
  ' Prefer default from php-runtime.json
  For Each subf In fso.GetFolder(web).SubFolders
    If LCase(Left(subf.Name, 3)) = "php" Then
      exe = subf.Path & "\php.exe"
      If fso.FileExists(exe) Then
        If FindPhpExe = "" Then FindPhpExe = exe
        phpExe = exe
        out = Trim(ExecCapture("""" & phpExe & """ """ & scripts & "\list-php-runtime.php"" default"))
        If out <> "" Then
          parts = Split(out, vbTab)
          If UBound(parts) >= 0 Then
            exe = Trim(parts(0)) & "\php.exe"
            If fso.FileExists(exe) Then
              FindPhpExe = exe
              Exit Function
            End If
          End If
        End If
        Exit Function
      End If
    End If
  Next
End Function

Function ExecCapture(cmd)
  Dim exec
  On Error Resume Next
  Set exec = WshShell.Exec("cmd /c " & cmd)
  Do While exec.Status = 0
    WScript.Sleep 40
  Loop
  ExecCapture = exec.StdOut.ReadAll
  On Error GoTo 0
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
