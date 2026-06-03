@echo off
REM ============================================================
REM  SSH Tunnel: local 13306 -> jump host -> internal MySQL 3306
REM  使用 Windows 10+ 內建 OpenSSH（ssh.exe）
REM
REM  使用方式：
REM    1. 雙擊執行；視窗保持開啟代表隧道運作中
REM    2. 結束方式：關閉視窗或按 Ctrl+C
REM
REM  驗證（另開 cmd）：
REM    powershell -c "Test-NetConnection 127.0.0.1 -Port 13306"
REM
REM  常駐建議：把本檔捷徑放到 shell:startup，開機自啟動
REM ============================================================

setlocal

REM ---- 本機監聽埠（必須與 config.php 的 db.port 一致）
REM     避開本機 MySQL 預設 3306，這裡用 13306
set LOCAL_PORT=13306

REM ---- 內網 DB（從跳板機看出去的位址）
set DB_HOST=192.168.6.58
set DB_PORT=3306

REM ---- 跳板主機
set JUMP_USER=root
set JUMP_HOST=192.168.6.57
set JUMP_PORT=22

REM ---- SSH 金鑰（找不到則 fallback 密碼登入）
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
