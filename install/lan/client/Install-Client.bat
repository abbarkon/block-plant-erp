@echo off
setlocal
title Block Plant ERP - client setup
cd /d "%~dp0"

echo.
echo  Installing the Block Plant ERP shortcut for this PC...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Client.ps1" %*
set RESULT=%ERRORLEVEL%

echo.
if not "%RESULT%"=="0" (
    echo  Setup did not finish. Read the messages above.
) else (
    echo  Setup finished.
)
echo.
pause
exit /b %RESULT%
