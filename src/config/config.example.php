<?php
/**
 * 公文分辦系統設定範本
 * ----------------------------------------------------------
 * 安裝時：
 *   copy config.example.php config.php
 * 然後修改 config.php 內的連線資訊（config.php 已被 .gitignore 排除）
 * ----------------------------------------------------------
 */

return [
    'timezone' => 'Asia/Taipei',

    // ---- SQL Server（透過本機 SSH tunnel 轉發）-----------
    'db' => [
        'driver' => 'sqlsrv',          // 'sqlsrv' 或 'odbc'
        'host'   => '127.0.0.1',       // 走 SSH tunnel 時固定 127.0.0.1
        'port'   => 1433,              // 須與 ssh_tunnel.bat 中 LOCAL_PORT 相同
        'name'   => 'YourDatabase',    // ← TODO 改為實際資料庫
        'user'   => 'your_user',       // ← TODO
        'pass'   => 'your_pass',       // ← TODO
        'table'  => 'dbo.公文表',       // ← TODO schema.table

        // 欄位映射（依實際 schema 調整鍵右側）
        'columns' => [
            'id'      => 'INNO',       // 主鍵 / 公文識別號（檔名）
            'date'    => 'INDATE',     // 收文日期
            'org'     => 'GUNAME',     // 來文機關
            'subject' => 'THEME',      // 主旨
            // 'kind' => 'INKIND',     // （可選）來文字，例「林政字」
        ],

        // 撈「最近 N 天」內且本地尚未產生 JSON 的公文（N=1 = 只看今天）
        'lookback_days' => 1,

        // 安全上限，避免一次撈太多
        'max_rows' => 500,
    ],

    // ---- Ollama AI ---------------------------------------
    'ai' => [
        'base_url'    => 'http://192.168.1.237:11434',
        'model'       => 'gw-classify:finetune',
        'timeout_sec' => 90,
        'target_units' => [
            '主計室','人事室','企劃組','教學研究組','森林作業組',
            '秘書室','管理組','總務組','育樂組',
        ],
    ],

    // ---- 輸出路徑 -----------------------------------------
    'paths' => [
        'data_dir' => __DIR__ . '/../data',   // 每日 JSON 根目錄 → data/YYYYMMDD/
        'log_dir'  => __DIR__ . '/../logs',   // 每日 log → logs/YYYYMMDD.log
    ],

    // ---- 網頁顯示 -----------------------------------------
    'web' => [
        'title'        => '今日公文 AI 分辦結果',
        'auto_refresh' => 60,   // 每 N 秒自動 reload，設 0 關閉
    ],
];
