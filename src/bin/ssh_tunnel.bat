@echo off
REM ============================================================
REM  SSH Tunnel: local 3306 -> jump host -> internal MySQL 3306
REM  Use Windows 10+ built-in OpenSSH (ssh.exe).
REM
REM  Usage:
REM    1. Double click to run; keep this window open while tunnel is needed.
REM    2. To stop: close this window or press Ctrl+C.
REM
REM  Verify (in another cmd window):
REM    powershell -c "Test-NetConnection 127.0.0.1 -Port 3306"
REM ============================================================

setlocal

REM ---- Local listening port (must match db.port in src\config\config.php)
set LOCAL_PORT=3306

REM ---- Internal DB host (visible from the jump host 192.168.6.57). MySQL = 3306
set DB_HOST=192.168.6.58
set DB_PORT=3306

REM ---- Jump host (the AP server we SSH into)
set JUMP_USER=root
set JUMP_HOST=192.168.6.57
set JUMP_PORT=22

REM ---- SSH key (optional). If present -> key login. If missing -> password prompt.
set KEY_FILE=%USERPROFILE%\.ssh\id_ed25519

echo ============================================================
echo  Opening SSH tunnel
echo    Local  : 127.0.0.1:%LOCAL_PORT%
echo    Remote : %DB_HOST%:%DB_PORT%  (via %JUMP_USER%@%JUMP_HOST%:%JUMP_PORT%)
echo  Press Ctrl+C or close this window to stop.
echo ============================================================

if exist "%KEY_FILE%" (
    ssh -i "%KEY_FILE%" -N -T ^
        -o ServerAliveInterval=30 ^
        -o ServerAliveCountMax=3 ^
        -o ExitOnForwardFailure=yes ^
        -p %JUMP_PORT% ^
        -L %LOCAL_PORT%:%DB_HOST%:%DB_PORT% ^
        %JUMP_USER%@%JUMP_HOST%
) else (
    echo [INFO] No key found at %KEY_FILE%, falling back to password auth.
    ssh -N -T ^
        -o ServerAliveInterval=30 ^
        -o ServerAliveCountMax=3 ^
        -o ExitOnForwardFailure=yes ^
        -p %JUMP_PORT% ^
        -L %LOCAL_PORT%:%DB_HOST%:%DB_PORT% ^
        %JUMP_USER%@%JUMP_HOST%
)

echo.
echo SSH tunnel closed. (exit code %ERRORLEVEL%)
endlocal
pause
