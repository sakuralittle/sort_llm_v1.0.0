<?php
declare(strict_types=1);

/**
 * 共用啟動器：載入設定 + 所有 lib 類別。
 * 任何 entry point（bin/* 或 web/*）都應該先 require 這支。
 */

if (defined('SRC_BOOTSTRAPPED')) {
    return $GLOBALS['__config'] ?? null;
}
define('SRC_BOOTSTRAPPED', true);

$srcDir   = dirname(__DIR__);
$cfgFile  = $srcDir . '/config/config.php';
$exFile   = $srcDir . '/config/config.example.php';

if (!file_exists($cfgFile)) {
    fwrite(STDERR, "[FATAL] 找不到 config.php，請複製 config.example.php → config.php 並填入連線資訊。\n");
    fwrite(STDERR, "        範本：$exFile\n");
    exit(1);
}

/** @var array $__config */
$__config = require $cfgFile;
if (!is_array($__config)) {
    fwrite(STDERR, "[FATAL] config.php 必須 return 一個陣列。\n");
    exit(1);
}

date_default_timezone_set($__config['timezone'] ?? 'Asia/Taipei');

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/AiClassifier.php';
require_once __DIR__ . '/DocRepository.php';

$GLOBALS['__config'] = $__config;
return $__config;
