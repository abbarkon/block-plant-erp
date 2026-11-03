@echo off
setlocal
rem Runs the daily Telegram report with the XAMPP PHP binary.
rem Usage: telegram_daily.bat [--dry-run] [--date=YYYY-MM-DD] [--force] [--no-attachment]
set "PHP_EXE=%~dp0..\..\..\php\php.exe"
if not exist "%PHP_EXE%" (
    echo PHP not found at "%PHP_EXE%".
    exit /b 1
)
"%PHP_EXE%" "%~dp0telegram_daily.php" %*
exit /b %errorlevel%
