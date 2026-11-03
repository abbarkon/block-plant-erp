@echo off
setlocal
title Block Plant ERP - database install
cd /d "%~dp0"

:MENU
cls
echo.
echo  ======================================================================
echo   Block Plant ERP - database install
echo  ======================================================================
echo.
echo   1. Install the CURRENT company data   (full backup)
echo   2. Install a CLEAN, empty system      (erases the database)
echo   3. Only make a backup of the current database
echo   0. Exit
echo.
set /p CHOICE=" Choose 0-3: "

if "%CHOICE%"=="1" goto FULL
if "%CHOICE%"=="2" goto CLEAN
if "%CHOICE%"=="3" goto BACKUP
if "%CHOICE%"=="0" goto END
goto MENU

:FULL
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Database.ps1" -Mode Full
goto DONE

:CLEAN
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Database.ps1" -Mode Clean
goto DONE

:BACKUP
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-Database.ps1" -Mode Backup
goto DONE

:DONE
echo.
pause
goto MENU

:END
exit /b 0
