@echo off
setlocal
title Block Plant ERP - connection test
cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Test-Connection.ps1"

echo.
pause
exit /b 0
