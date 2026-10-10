' Write Apache24/conf/install-root.conf for THIS install folder (not a fixed C:\web).
' Safe to run anytime; start-stack / start-httpd call this before launching Apache.
Option Explicit
Dim fso, web, confDir, logsDir, conf, unix, ts
Set fso = CreateObject("Scripting.FileSystemObject")
web = fso.GetParentFolderName(WScript.ScriptFullName)
confDir = web & "\Apache24\conf"
logsDir = web & "\Apache24\logs"
If Not fso.FolderExists(confDir) Then WScript.Quit 1
If Not fso.FolderExists(logsDir) Then fso.CreateFolder logsDir
unix = Replace(web, "\", "/")
conf = confDir & "\install-root.conf"
Set ts = fso.CreateTextFile(conf, True)
ts.Write "# Auto-generated — do not edit. Install root for this copy of the stack." & vbCrLf
ts.Write "Define WEBROOT """ & unix & """" & vbCrLf
ts.Write "Define SRVROOT """ & unix & "/Apache24""" & vbCrLf
ts.Close
WScript.Quit 0

