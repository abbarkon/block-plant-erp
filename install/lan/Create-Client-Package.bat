@echo off
setlocal
title Block Plant ERP - build client package
cd /d "%~dp0"

echo.
echo  Building the client setup package...
echo  The server address is detected automatically.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Create-Client-Package.ps1" %*

echo.
pause
exit /b 0
