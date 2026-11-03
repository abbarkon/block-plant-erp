@echo off
setlocal
title Block Plant ERP - remove shortcut
cd /d "%~dp0"

echo.
echo  Removing the Block Plant ERP shortcuts from this PC...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Uninstall-Client.ps1"

echo.
pause
exit /b 0
