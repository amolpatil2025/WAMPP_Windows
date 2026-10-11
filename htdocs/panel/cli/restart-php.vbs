' Restart a PHP FastCGI listener by version key (from php-runtime.json)
Option Explicit
Dim sh, fso, root, scripts, ver, phpExe, out, parts, dir, port, cmd, line, pid, execOut, lines, i
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
root = fso.GetParentFolderName(fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)))
scripts = root & "\scripts"
ver = ""
If WScript.Arguments.Count > 0 Then ver = WScript.Arguments(0)
If ver = "" Then WScript.Quit 1

phpExe = FindAnyPhpExe()
If phpExe = "" Then WScript.Quit 1
out = Trim(ExecCapture("""" & phpExe & """ """ & scripts & "\list-php-runtime.php"" " & ver))
If out = "" Then WScript.Quit 1
parts = Split(out, vbTab)
If UBound(parts) < 1 Then WScript.Quit 1
dir = Trim(parts(0))
port = CLng(Trim(parts(1)))

execOut = sh.Exec("cmd /c netstat -ano | findstr /C:"":" & port & " "" | findstr LISTENING").StdOut.ReadAll
lines = Split(execOut, vbCrLf)
For i = 0 To UBound(lines)
  line = Trim(lines(i))
  If Len(line) > 0 Then
    Dim bits
    bits = Split(line)
    pid = Trim(bits(UBound(bits)))
    If IsNumeric(pid) Then
      sh.Run "taskkill /F /PID " & pid, 0, True
    End If
  End If
Next

WScript.Sleep 400
cmd = "cmd /c set PHPRC=" & dir & "&& set PHP_FCGI_CHILDREN=8&& set PHP_FCGI_MAX_REQUESTS=500&& """ & dir & "\php-cgi.exe"" -b 127.0.0.1:" & port & " -c """ & dir & """"
sh.Run "wscript //B //Nologo """ & scripts & "\run-hidden.vbs"" """ & Replace(cmd, """", """""") & """", 0, False

Function FindAnyPhpExe()
  Dim fold, subf, exe
  FindAnyPhpExe = ""
  Set fold = fso.GetFolder(root)
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

Function ExecCapture(cmd)
  Dim exec
  Set exec = sh.Exec("cmd /c " & cmd)
  Do While exec.Status = 0
    WScript.Sleep 50
  Loop
  ExecCapture = exec.StdOut.ReadAll
End Function
