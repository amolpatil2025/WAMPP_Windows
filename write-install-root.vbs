' Sync install-local paths for THIS folder (portable across machines):
'  - Apache24/conf/install-root.conf (WEBROOT / SRVROOT)
'  - Apache24/logs
'  - each php*/tmp, php*/logs
'  - force php.ini session/error/upload/temp paths to THIS php folder
' Called before PHP/Apache start.
Option Explicit
Dim fso, web, unix, confDir, logsDir, conf, ts, folder, phpIni
Set fso = CreateObject("Scripting.FileSystemObject")
web = fso.GetParentFolderName(WScript.ScriptFullName)
unix = Replace(web, "\", "/")

confDir = web & "\Apache24\conf"
logsDir = web & "\Apache24\logs"
If Not fso.FolderExists(web & "\Apache24") Then WScript.Quit 1
If Not fso.FolderExists(confDir) Then WScript.Quit 1
If Not fso.FolderExists(logsDir) Then fso.CreateFolder logsDir

conf = confDir & "\install-root.conf"
Set ts = fso.CreateTextFile(conf, True)
ts.Write "# Auto-generated — do not edit. Install root for this copy of the stack." & vbCrLf
ts.Write "Define WEBROOT """ & unix & """" & vbCrLf
ts.Write "Define SRVROOT """ & unix & "/Apache24""" & vbCrLf
ts.Close

For Each folder In fso.GetFolder(web).SubFolders
  If LCase(Left(folder.Name, 3)) = "php" Then
    EnsureDir folder.Path & "\tmp"
    EnsureDir folder.Path & "\logs"
    phpIni = folder.Path & "\php.ini"
    If fso.FileExists(phpIni) Then SyncPhpIni folder.Path, phpIni
  End If
Next

WScript.Quit 0

Sub EnsureDir(path)
  If Not fso.FolderExists(path) Then fso.CreateFolder path
End Sub

Sub SyncPhpIni(phpDir, iniPath)
  Dim raw, updated, phpUnix, phpName, tmpUnix, logUnix, stream, re
  raw = ReadText(iniPath)
  phpUnix = Replace(phpDir, "\", "/")
  phpName = fso.GetFileName(phpDir)
  tmpUnix = phpUnix & "/tmp"
  logUnix = phpUnix & "/logs/php_errors.log"
  updated = raw

  ' Rewrite old absolute paths on active lines only (other device / folder).
  updated = RewriteActivePaths(updated, phpName, phpUnix, unix, web)

  ' Always pin runtime dirs to this php folder (device-independent).
  updated = SetIniDirective(updated, "session.save_path", """" & tmpUnix & """")
  updated = SetIniDirective(updated, "error_log", logUnix)
  updated = SetIniDirective(updated, "upload_tmp_dir", """" & tmpUnix & """")
  updated = SetIniDirective(updated, "sys_temp_dir", """" & tmpUnix & """")
  updated = SetIniDirective(updated, "soap.wsdl_cache_dir", """" & tmpUnix & """")

  If updated <> raw Then
    Set stream = fso.CreateTextFile(iniPath, True)
    stream.Write updated
    stream.Close
  End If
End Sub

Function SetIniDirective(content, key, valueLine)
  Dim re, line, lines, i, row, out, seen, activeRe, stubRe
  line = key & " = " & valueLine
  Set activeRe = New RegExp
  activeRe.IgnoreCase = True
  activeRe.Pattern = "^\s*" & EscapeRe(key) & "\s*="

  Set stubRe = New RegExp
  stubRe.IgnoreCase = True
  stubRe.Pattern = "^;" & EscapeRe(key) & "\s*="

  lines = Split(NormalizeNewlines(content), vbLf)
  out = ""
  seen = False
  For i = 0 To UBound(lines)
    row = lines(i)
    If activeRe.Test(row) Then
      If Not seen Then
        If Len(out) > 0 Then out = out & vbCrLf
        out = out & line
        seen = True
      End If
    ElseIf (Not seen) And stubRe.Test(row) Then
      If Len(out) > 0 Then out = out & vbCrLf
      out = out & line
      seen = True
    Else
      If Len(out) > 0 Then out = out & vbCrLf
      out = out & row
    End If
  Next
  If Not seen Then
    If Len(out) > 0 Then out = out & vbCrLf
    out = out & line & vbCrLf
  End If
  SetIniDirective = out
End Function

Function RewriteActivePaths(content, phpName, phpUnix, webUnix, webWin)
  Dim lines, i, row, trim, re, out
  Set re = New RegExp
  re.Global = True
  re.IgnoreCase = True
  re.Pattern = "[A-Za-z]:[^""\r\n']*[\\/]" & EscapeRe(phpName) & "(?=[\\/])"
  lines = Split(NormalizeNewlines(content), vbLf)
  out = ""
  For i = 0 To UBound(lines)
    row = lines(i)
    trim = LTrim(row)
    If Len(trim) > 0 And Left(trim, 1) <> ";" Then
      row = re.Replace(row, phpUnix)
      row = Replace(row, "C:/web", webUnix)
      row = Replace(row, "C:\web", webWin)
      row = Replace(row, "c:/web", webUnix)
      row = Replace(row, "c:\web", webWin)
    End If
    If Len(out) > 0 Then out = out & vbCrLf
    out = out & row
  Next
  RewriteActivePaths = out
End Function

Function NormalizeNewlines(s)
  NormalizeNewlines = Replace(Replace(s, vbCrLf, vbLf), vbCr, vbLf)
End Function

Function EscapeRe(s)
  Dim out, i, ch
  out = ""
  For i = 1 To Len(s)
    ch = Mid(s, i, 1)
    If InStr("\.+*?[^]$(){}=!<>|:-", ch) > 0 Then
      out = out & "\" & ch
    Else
      out = out & ch
    End If
  Next
  EscapeRe = out
End Function

Function ReadText(path)
  Dim stream
  Set stream = fso.OpenTextFile(path, 1)
  ReadText = stream.ReadAll
  stream.Close
End Function
