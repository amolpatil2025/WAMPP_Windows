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
  Dim phpExe, out, parts, dir, port
  phpExe = FindAnyPhpExe()
  If phpExe = "" Then Exit Sub
  out = Trim(ExecCapture("""" & phpExe & """ """ & SCRIPTS & "\list-php-runtime.php"" default"))
  If out = "" Then Exit Sub
  parts = Split(out, vbTab)
  If UBound(parts) < 1 Then Exit Sub
  dir = Trim(parts(0))
  port = CLng(Trim(parts(1)))
  StartPhp dir, port
End Sub

Function FindAnyPhpExe()
  Dim fold, subf, exe, raw, p, endPos, block, dir
  FindAnyPhpExe = ""
  raw = ReadAllText(WEB & "\htdocs\panel\data\php-runtime.json")
  If raw <> "" Then
    p = InStr(1, raw, """dir""")
    If p > 0 Then
      endPos = InStr(p, raw, ",")
      If endPos < 1 Then endPos = InStr(p, raw, "}")
      If endPos > p Then
        block = Mid(raw, p, endPos - p + 1)
        dir = JsonString(block, "dir")
        If dir <> "" Then
          exe = dir & "\php.exe"
          If fso.FileExists(exe) Then
            FindAnyPhpExe = exe
            Exit Function
          End If
        End If
      End If
    End If
  End If
  Set fold = fso.GetFolder(WEB)
  For Each subf In fold.SubFolders
    If LCase(Left(subf.Name, 3)) = "php" Then
      exe = subf.Path & "\php.exe"
      If fso.FileExists(exe) Then
        FindAnyPhpExe = exe
        Exit Function
      End If
    End If
  Next
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
    WshShell.Run """" & httpd & """ -d """ & WEB & "\Apache24""", 0, False
    WScript.Sleep 2500
    If IsListening(80) Then Exit Sub
  Next
End Sub

Function ExecCapture(cmd)
  Dim exec
  Set exec = WshShell.Exec("cmd /c " & cmd)
  Do While exec.Status = 0
    WScript.Sleep 50
  Loop
  ExecCapture = exec.StdOut.ReadAll
End Function

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
