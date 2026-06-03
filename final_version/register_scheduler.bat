@echo off
REM ============================================================
REM  One-click: register run_classify.bat to Windows Task Scheduler.
REM
REM  - Trigger : every 5 minutes, indefinitely
REM  - Run as  : the current Windows user (you)
REM  - Mode    : runs WHETHER YOU ARE LOGGED ON OR NOT
REM
REM  HOW IT ACHIEVES "even when not logged on":
REM    schtasks is given /RU <yourUser> /RP <yourPassword>.
REM    Windows then stores the password in the LSA secret vault and uses
REM    it to log you in non-interactively when the trigger fires.
REM    That is what the GUI checkbox "Run whether user is logged on or
REM    not" does internally.
REM
REM  Prerequisites for unattended runs:
REM    1) ssh_tunnel.bat must also be running 24/7. Use the companion
REM       script register_ssh_tunnel.bat (or set it up manually).
REM    2) php must be on the SYSTEM PATH (not just user PATH), or you
REM       must edit run_classify.bat to use a full path to php.exe.
REM
REM  Re-running this script will overwrite the existing task safely.
REM  To remove the task later, run: unregister_scheduler.bat
REM ============================================================

setlocal

set TASK_NAME=DocClassifyAI
set BAT_PATH=%~dp0run_classify.bat

if not exist "%BAT_PATH%" (
    echo [ERROR] run_classify.bat not found at: %BAT_PATH%
    goto :end
)

REM ---- Compose the user identity (DOMAIN\user or just user) -------
if defined USERDOMAIN (
    set RUN_AS=%USERDOMAIN%\%USERNAME%
) else (
    set RUN_AS=%USERNAME%
)

echo Registering scheduled task "%TASK_NAME%"
echo   Program  : %BAT_PATH%
echo   Trigger  : every 5 minutes (/SC MINUTE /MO 5)
echo   Run as   : %RUN_AS%
echo   Login    : NOT required (runs whether logged on or not)
echo.
echo You will be asked for your Windows password ONCE, so the task
echo scheduler can store it and log you in non-interactively.
echo.

REM /SC MINUTE /MO 5  -> every 5 minutes
REM /RU              -> the user account that will run the task
REM /RP              -> the password (omit value to be prompted)
REM /RL LIMITED      -> standard token (no UAC elevation)
REM /F               -> force overwrite if task already exists
schtasks /Create ^
    /TN "%TASK_NAME%" ^
    /TR "\"%BAT_PATH%\"" ^
    /SC MINUTE /MO 5 ^
    /RU "%RUN_AS%" ^
    /RP ^
    /RL LIMITED ^
    /F

if errorlevel 1 (
    echo.
    echo [ERROR] Task creation failed. Common causes:
    echo   - Wrong password
    echo   - Local Security Policy denies "Log on as a batch job" for your
    echo     account. To fix: secpol.msc -^> Local Policies -^> User Rights
    echo     Assignment -^> "Log on as a batch job" -^> add your account.
    goto :end
)

echo.
echo [OK] Task registered. Quick check:
echo.
schtasks /Query /TN "%TASK_NAME%" /V /FO LIST | findstr /R /C:"TaskName" /C:"Status" /C:"Next Run Time" /C:"Last Run Time" /C:"Last Result" /C:"Logon Mode"

echo.
echo You can also open taskschd.msc to inspect/edit it visually.
echo The "General" tab should show: "Run whether user is logged on or not".

:end
endlocal
pause
