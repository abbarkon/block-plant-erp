@echo off
setlocal
rem Runs the attendance reminder with the XAMPP PHP binary.
rem Usage: attendance_reminder.bat [--dry-run] [--date=YYYY-MM-DD] [--force]
set "PHP_EXE=%~dp0..\..\..\php\php.exe"
if not exist "%PHP_EXE%" (
    echo PHP not found at "%PHP_EXE%".
    exit /b 1
)
"%PHP_EXE%" "%~dp0attendance_reminder.php" %*
exit /b %errorlevel%
