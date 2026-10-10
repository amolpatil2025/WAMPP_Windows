' Run any command fully hidden: wscript //B run-hidden.vbs "command here"
Option Explicit
Dim WshShell
If WScript.Arguments.Count < 1 Then WScript.Quit 1
Set WshShell = CreateObject("WScript.Shell")
WshShell.Run WScript.Arguments(0), 0, False
