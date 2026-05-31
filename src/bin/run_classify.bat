@echo off
REM ============================================================
REM  Entry point for Windows Task Scheduler.
REM  Prerequisite: ssh_tunnel.bat must be running (127.0.0.1:1433 open).
REM  PHP-side log goes to src\logs\YYYYMMDD.log automatically.
REM  This bat also appends a line to src\logs\scheduler.log for debugging.
REM ============================================================

setlocal

REM ---- Set full path here if php is not in PATH, e.g.:
REM      set PHP_BIN="C:\php\php.exe"
set PHP_BIN=php

REM ---- Move to src\ root (this bat lives in src\bin\)
pushd "%~dp0\.."

if not exist "logs" mkdir "logs"

echo. >> "logs\scheduler.log"
echo ========== %DATE% %TIME% ========== >> "logs\scheduler.log"
%PHP_BIN% "bin\run_classify.php" >> "logs\scheduler.log" 2>&1
set RC=%ERRORLEVEL%
echo (exit code %RC%) >> "logs\scheduler.log"

popd
endlocal & exit /b %RC%
