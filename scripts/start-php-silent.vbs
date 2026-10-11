' Start all PHP FastCGI listeners listed in php-runtime.json — fully hidden.
Option Explicit
Dim WshShell, fso, SCRIPTS, WEB, phpExe, out, lines, i, parts, dir, port
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
SCRIPTS = fso.GetParentFolderName(WScript.ScriptFullName)
WEB = fso.GetParentFolderName(SCRIPTS)
WshShell.Run "wscript //B //Nologo """ & SCRIPTS & "\write-install-root.vbs""", 0, True

phpExe = FindAnyPhpExe()
If phpExe = "" Then WScript.Quit 1
out = ExecCapture("""" & phpExe & """ """ & SCRIPTS & "\list-php-runtime.php""")
lines = Split(Replace(out, vbCr, ""), vbLf)
For i = 0 To UBound(lines)
  If Trim(lines(i)) <> "" Then
    parts = Split(lines(i), vbTab)
    If UBound(parts) >= 1 Then
      dir = Trim(parts(0))
      port = CLng(Trim(parts(1)))
      StartPhp dir, port
    End If
  End If
Next
WScript.Quit 0

Function FindAnyPhpExe()
  Dim raw, p, block, endPos, dir, folder, exe
  FindAnyPhpExe = ""
  raw = ReadAllText(WEB & "\htdocs\panel\data\php-runtime.json")
  If raw <> "" Then
    p = InStr(1, raw, """dir""")
    If p > 0 Then
      endPos = InStr(p, raw, ",")
      If endPos < 1 Then endPos = InStr(p, raw, "}")
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
  ' Fallback: first php*\php.exe under install root
  Dim fold, subf
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

Function ExecCapture(cmd)
  Dim exec, out
  Set exec = WshShell.Exec("cmd /c " & cmd)
  Do While exec.Status = 0
    WScript.Sleep 50
  Loop
  out = exec.StdOut.ReadAll
  ExecCapture = out
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
