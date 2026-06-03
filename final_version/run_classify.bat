@echo off
REM ============================================================
REM  Entry point for Windows Task Scheduler (every 5 minutes).
REM  Prerequisite: ssh_tunnel.bat must be running (127.0.0.1:13306 open).
REM
REM  Scheduler settings:
REM    Trigger : Daily, repeat every 5 minutes for 24 hours
REM    Action  : Start a program
REM    Program : <this folder>\run_classify.bat
REM    Start in: (leave empty; this bat self-locates via %~dp0)
REM ============================================================

setlocal

REM ---- If php is not in PATH, set the full path here, e.g.:
REM     set PHP_BIN="C:\xampp\php\php.exe"
set PHP_BIN=php

REM ---- Move to this folder (bat lives next to index.php)
pushd "%~dp0"

if not exist "logs" mkdir "logs"

echo. >> "logs\scheduler.log"
echo ========== %DATE% %TIME% ========== >> "logs\scheduler.log"
%PHP_BIN% "index.php" >> "logs\scheduler.log" 2>&1
set RC=%ERRORLEVEL%
echo (exit code %RC%) >> "logs\scheduler.log"

popd
endlocal & exit /b %RC%
