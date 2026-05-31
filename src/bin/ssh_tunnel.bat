@echo off
REM ============================================================
REM  SSH Tunnel：本機 1433 → 跳板 → 內網 SQL Server 1433
REM  使用 Windows 10+ 內建 OpenSSH（ssh.exe）
REM
REM  使用流程：
REM    1. 確保已建立 SSH 金鑰並把 public key 加到跳板主機 authorized_keys
REM    2. 修改下方 5 個變數
REM    3. 雙擊執行（或登入時自動啟動）→ 視窗保持開啟即代表 tunnel 在運作
REM    4. 想中止：直接關閉此 cmd 視窗，或 Ctrl+C
REM
REM  測試：
REM    開啟另一個 cmd → telnet 127.0.0.1 1433  （能連上即代表 tunnel OK）
REM ============================================================

setlocal

REM ---- 本機監聽埠（給 PHP 連的對象，要與 src/config/config.php 的 db.port 一致）
set LOCAL_PORT=1433

REM ---- 內網 DB 主機（從跳板 192.168.6.57 看出去的實驗林 DB 主機）
set DB_HOST=192.168.6.58
set DB_PORT=1433

REM ---- 跳板（實驗林 AP 主機，中繼主機透過 SSH 登入這台）
set JUMP_USER=root
set JUMP_HOST=192.168.6.57
set JUMP_PORT=22

REM ---- SSH 金鑰（推薦做法）：若已用 ssh-copy-id 設定免密碼登入，請設此檔路徑
REM     若還沒做金鑰，啟動時會跳出密碼提示，互動輸入 root 的密碼即可
set KEY_FILE=%USERPROFILE%\.ssh\id_ed25519

echo ============================================================
echo  Opening SSH tunnel
echo    Local  : 127.0.0.1:%LOCAL_PORT%
echo    Remote : %DB_HOST%:%DB_PORT%  (via %JUMP_USER%@%JUMP_HOST%:%JUMP_PORT%)
echo  Press Ctrl+C or close this window to stop.
echo ============================================================

REM -N : 不執行遠端命令，只開隧道
REM -T : 不分配 TTY
REM ServerAliveInterval 30 : 每 30 秒送 keep-alive，防止 NAT/防火牆斷線
REM
REM 若已建立金鑰登入：使用下方含 -i 的版本
REM 若尚未建立金鑰  ：注解掉含 -i 的版本，改用「無 -i 版本」，啟動時輸入密碼

if exist "%KEY_FILE%" (
    ssh -i "%KEY_FILE%" -N -T ^
        -o ServerAliveInterval=30 ^
        -o ServerAliveCountMax=3 ^
        -o ExitOnForwardFailure=yes ^
        -p %JUMP_PORT% ^
        -L %LOCAL_PORT%:%DB_HOST%:%DB_PORT% ^
        %JUMP_USER%@%JUMP_HOST%
) else (
    echo [INFO] 找不到金鑰 %KEY_FILE%，改用密碼登入（會跳出提示）
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
