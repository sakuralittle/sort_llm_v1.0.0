@echo off
REM ============================================================
REM  One-time SSH key setup for the jump host.
REM  After this finishes successfully, ssh_tunnel.bat will log in
REM  automatically without prompting for a password.
REM
REM  What this script does:
REM    1) Generates an ed25519 key pair at %USERPROFILE%\.ssh\
REM       (skipped if the key already exists)
REM    2) Uploads the PUBLIC key to the jump host's authorized_keys
REM       (you will be asked for the jump host password ONE LAST TIME)
REM
REM  Requirement: Windows 10+ built-in OpenSSH client (ssh, ssh-keygen).
REM ============================================================

setlocal

REM ---- Jump host (must match ssh_tunnel.bat) ----
set JUMP_USER=root
set JUMP_HOST=192.168.6.57
set JUMP_PORT=22

set KEY_FILE=%USERPROFILE%\.ssh\id_ed25519
set PUB_FILE=%KEY_FILE%.pub

echo ============================================================
echo  SSH key setup for %JUMP_USER%@%JUMP_HOST%
echo  Key file: %KEY_FILE%
echo ============================================================
echo.

REM ---- Step 1: ensure ~/.ssh exists ----
if not exist "%USERPROFILE%\.ssh" (
    echo [INFO] Creating %USERPROFILE%\.ssh
    mkdir "%USERPROFILE%\.ssh"
)

REM ---- Step 2: generate key if missing ----
if exist "%KEY_FILE%" (
    echo [SKIP] Key already exists at %KEY_FILE%
) else (
    echo [STEP] Generating new ed25519 key (no passphrase)...
    ssh-keygen -t ed25519 -f "%KEY_FILE%" -N "" -C "%USERNAME%@%COMPUTERNAME%"
    if errorlevel 1 (
        echo [ERROR] ssh-keygen failed. Make sure OpenSSH client is installed.
        goto :end
    )
)

if not exist "%PUB_FILE%" (
    echo [ERROR] Public key not found: %PUB_FILE%
    goto :end
)

echo.
echo [STEP] Uploading public key to %JUMP_USER%@%JUMP_HOST%
echo        You will be prompted for the jump host password ONE LAST TIME.
echo.

REM ---- Step 3: append public key to remote authorized_keys ----
REM     Single quoting on the remote side avoids local-side variable expansion.
type "%PUB_FILE%" | ssh -p %JUMP_PORT% -o StrictHostKeyChecking=accept-new %JUMP_USER%@%JUMP_HOST% "umask 077 && mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys && sort -u -o ~/.ssh/authorized_keys ~/.ssh/authorized_keys && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys && echo OK_KEY_INSTALLED"

if errorlevel 1 (
    echo.
    echo [ERROR] Upload failed. Common causes:
    echo         - Wrong password
    echo         - Jump host unreachable (check VPN / firewall)
    echo         - sshd on jump host disallows password auth
    goto :end
)

echo.
echo ============================================================
echo  SUCCESS! Key installed.
echo  Test it now (should connect without password prompt):
echo      ssh -i "%KEY_FILE%" -p %JUMP_PORT% %JUMP_USER%@%JUMP_HOST% "echo hello && exit"
echo  Then double-click ssh_tunnel.bat - it will auto-use the key.
echo ============================================================

:end
endlocal
pause
