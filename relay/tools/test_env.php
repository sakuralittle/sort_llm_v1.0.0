<?php
/**
 * T1：PHP 環境檢查
 * ----------------------------------------------------------
 * 用途：在中繼主機上跑一次，確認 PHP 版本與必要擴充已啟用。
 * 執行：php test_env.php
 * ----------------------------------------------------------
 */

declare(strict_types=1);

echo "==========================================\n";
echo " T1  PHP 環境檢查\n";
echo "==========================================\n\n";

// ---- PHP 版本 ----
$phpVer = PHP_VERSION;
$phpOk  = version_compare($phpVer, '7.4.0', '>=');
printf("[%s] PHP 版本：%s（需 >= 7.4）\n", $phpOk ? 'OK' : 'FAIL', $phpVer);

// ---- 必要擴充 ----
$required = [
    'curl'       => '呼叫 Ollama AI 主機',
    'json'       => 'JSON 編解碼',
    'mbstring'   => '中文字串處理',
    'pdo'        => 'PDO 基礎',
];

// SQL Server 擴充（兩擇一即可）
$sqlExts = ['pdo_sqlsrv', 'pdo_odbc'];

foreach ($required as $ext => $why) {
    $ok = extension_loaded($ext);
    printf("[%s] 擴充 %-12s（%s）\n", $ok ? 'OK' : 'FAIL', $ext, $why);
}

$sqlAvail = array_filter($sqlExts, 'extension_loaded');
if ($sqlAvail) {
    printf("[OK] SQL Server 擴充：%s\n", implode(', ', $sqlAvail));
} else {
    printf("[FAIL] 無 SQL Server 擴充，請安裝 pdo_sqlsrv（建議）或 pdo_odbc\n");
    echo "         下載：https://learn.microsoft.com/sql/connect/php/download-drivers-for-php-for-sql-server\n";
}

// ---- 時區 ----
$tz = date_default_timezone_get();
printf("[%s] 預設時區：%s（建議 Asia/Taipei）\n",
       $tz === 'Asia/Taipei' ? 'OK' : 'WARN', $tz);

// ---- php.ini 路徑（除錯用）----
echo "\n  php.ini 位置：" . (php_ini_loaded_file() ?: '未載入') . "\n";
echo "  PHP SAPI    ：" . PHP_SAPI . "\n";
echo "  作業系統    ：" . PHP_OS_FAMILY . "\n";

echo "\n==========================================\n";
echo " 完成。若有 FAIL 項目請先排除再進入 T2/T3。\n";
echo "==========================================\n";
