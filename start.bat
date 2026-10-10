@echo off
REM Start Apache + default PHP + Mailpit (silent). Optional helpers live in scripts\
wscript //B //Nologo "%~dp0scripts\start-stack-silent.vbs"
exit /b 0
