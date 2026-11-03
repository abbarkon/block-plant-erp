@echo off
setlocal
title Block Plant ERP - LAN server setup
cd /d "%~dp0"

echo.
echo  Block Plant ERP - LAN server setup
echo  ---------------------------------
echo  This prepares THIS PC to serve the application to the office network.
echo  Windows will ask for Administrator permission.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath powershell -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-NoExit','-File','%~dp0Setup-LAN.ps1')"

if errorlevel 1 (
    echo.
    echo  Could not start the setup with Administrator rights.
    echo  Right-click Setup-LAN.bat and choose "Run as administrator".
    echo.
    pause
)
exit /b 0
