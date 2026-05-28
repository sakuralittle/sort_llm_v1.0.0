@echo off
REM ============================================================
REM  公文分辦排程入口
REM  - 由 Windows 工作排程器呼叫此檔
REM  - 前置條件：ssh_tunnel.bat 須已在背景常駐（127.0.0.1:1433 開著）
REM  - log 會由 PHP 端自動寫到 src/logs/YYYYMMDD.log
REM    這裡再多留一份 scheduler.log 給排程器除錯用
REM ============================================================

setlocal

REM ---- 如需指定 PHP 路徑，把這裡改成完整路徑，例如：
REM      set PHP_BIN="C:\php\php.exe"
set PHP_BIN=php

REM ---- 切到 src/ 根目錄（此 .bat 在 src/bin/ 下）
pushd "%~dp0\.."

REM ---- 確保 logs 目錄存在
if not exist "logs" mkdir "logs"

REM ---- 執行主程式
echo. >> "logs\scheduler.log"
echo ========== %DATE% %TIME% ========== >> "logs\scheduler.log"
%PHP_BIN% "bin\run_classify.php" >> "logs\scheduler.log" 2>&1
set RC=%ERRORLEVEL%
echo (exit code %RC%) >> "logs\scheduler.log"

popd
endlocal & exit /b %RC%
