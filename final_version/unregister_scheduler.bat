@echo off
REM ============================================================
REM  Remove the DocClassifyAI scheduled task.
REM ============================================================
setlocal
set TASK_NAME=DocClassifyAI

schtasks /Query /TN "%TASK_NAME%" >nul 2>&1
if errorlevel 1 (
    echo [INFO] Task "%TASK_NAME%" is not registered. Nothing to remove.
    goto :end
)

schtasks /Delete /TN "%TASK_NAME%" /F
if errorlevel 1 (
    echo [ERROR] Failed to delete the task.
) else (
    echo [OK] Task "%TASK_NAME%" removed.
)

:end
endlocal
pause
