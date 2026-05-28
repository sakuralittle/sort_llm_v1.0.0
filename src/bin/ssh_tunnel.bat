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

REM ---- 內網 SQL Server 主機（從跳板看出去的位址）
set DB_HOST=10.0.0.50
set DB_PORT=1433

REM ---- 跳板（SSH 登入主機）
set JUMP_USER=jumpuser
set JUMP_HOST=jump.example.com
set JUMP_PORT=22

REM ---- SSH 金鑰（建議用 ed25519；無金鑰時請先 ssh-keygen）
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
ssh -i "%KEY_FILE%" -N -T ^
    -o ServerAliveInterval=30 ^
    -o ServerAliveCountMax=3 ^
    -o ExitOnForwardFailure=yes ^
    -p %JUMP_PORT% ^
    -L %LOCAL_PORT%:%DB_HOST%:%DB_PORT% ^
    %JUMP_USER%@%JUMP_HOST%

echo.
echo SSH tunnel closed. (exit code %ERRORLEVEL%)
endlocal
pause
