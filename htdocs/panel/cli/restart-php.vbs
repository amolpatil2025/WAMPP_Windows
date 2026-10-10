' Restart a PHP FastCGI listener by version key (7.4 / 8.0 / 8.4)
Option Explicit
Dim sh, fso, root, ver, port, dir, cmd, line, out, pid
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
root = fso.GetParentFolderName(fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)))
ver = ""
If WScript.Arguments.Count > 0 Then ver = WScript.Arguments(0)

Select Case ver
  Case "7.4"
    port = 9074
    dir = root & "\php7.4.33"
  Case "8.0"
    port = 9080
    dir = root & "\php8.0.30"
  Case "8.4"
    port = 9084
    dir = root & "\php8.4.26"
  Case Else
    WScript.Quit 1
End Select

' Kill listeners on the port
out = sh.Exec("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING").StdOut.ReadAll
Dim lines, i, parts
lines = Split(out, vbCrLf)
For i = 0 To UBound(lines)
  line = Trim(lines(i))
  If Len(line) > 0 Then
    parts = Split(line)
    pid = Trim(parts(UBound(parts)))
    If IsNumeric(pid) Then
      sh.Run "taskkill /F /PID " & pid, 0, True
    End If
  End If
Next

WScript.Sleep 400

cmd = "cmd /c set PHPRC=" & dir & "&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& """ & dir & "\php-cgi.exe"" -b 127.0.0.1:" & port & " -c """ & dir & """"
sh.Run "wscript //B //Nologo """ & root & "\scripts\run-hidden.vbs"" """ & Replace(cmd, """", """""") & """", 0, False
