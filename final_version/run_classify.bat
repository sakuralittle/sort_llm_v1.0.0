@echo off
REM ============================================================
REM  Windows 工作排程器入口（每 5 分鐘觸發一次）
REM  前置條件：ssh_tunnel.bat 必須在跑（127.0.0.1:13306 已開）
REM
REM  排程器設定：
REM    觸發程序：每日，重複時間 5 分鐘，期間 24 小時
REM    動作    ：啟動程式
REM    程式   ：<本資料夾>\run_classify.bat
REM    開始位置：可留空（本檔已用 %~dp0 自動定位）
REM ============================================================

setlocal

REM ---- 若 php 不在 PATH 內，把這行改成完整路徑，例：
REM     set PHP_BIN="C:\xampp\php\php.exe"
set PHP_BIN=php

REM ---- 切到本資料夾（bat 與 index.php 同層）
pushd "%~dp0"

if not exist "logs" mkdir "logs"

echo. >> "logs\scheduler.log"
echo ========== %DATE% %TIME% ========== >> "logs\scheduler.log"
%PHP_BIN% "index.php" >> "logs\scheduler.log" 2>&1
set RC=%ERRORLEVEL%
echo (exit code %RC%) >> "logs\scheduler.log"

popd
endlocal & exit /b %RC%
