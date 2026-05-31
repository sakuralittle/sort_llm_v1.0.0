<?php
/**
 * 公文分辦系統設定 — 實際正式設定（含機敏資料，已被 .gitignore 排除）
 * 由 config.example.php 複製並填入實驗林環境而成。
 */

return [
    'timezone' => 'Asia/Taipei',

    // ---- SQL Server（透過本機 SSH tunnel 轉發到 192.168.6.58）----
    'db' => [
        'driver' => 'sqlsrv',
        'host'   => '127.0.0.1',       // 走 SSH tunnel
        'port'   => 1433,              // 與 ssh_tunnel.bat 的 LOCAL_PORT 一致
        'name'   => 'EXFODBS',    // ★ TODO 填實際資料庫名稱
        'user'   => 'exfoselect',
        'pass'   => 'EXFO@34qwe',
        'table'  => 'IFTDC_INDCM',       // ★ TODO 填實際 schema.table

        // 欄位映射（依實際 schema 調整鍵右側欄位名）
        'columns' => [
            'id'      => 'INNO',
            'date'    => 'INDATE',
            'org'     => 'GUNAME',
            'subject' => 'THEME',
            // 'kind' => 'INKIND',  // 可選：來文字
        ],

        // 撈最近 N 天內、且本地尚未產生 JSON 的公文
        'lookback_days' => 1,

        // 安全上限
        'max_rows' => 500,
    ],

    // ---- Ollama AI ----
    'ai' => [
        'base_url'    => 'http://192.168.1.237:11434',
        'model'       => 'gw-classify:finetune',
        'timeout_sec' => 90,
        'target_units' => [
            '主計室','人事室','企劃組','教學研究組','森林作業組',
            '秘書室','管理組','總務組','育樂組',
        ],
    ],

    // ---- 輸出路徑 ----
    'paths' => [
        'data_dir' => __DIR__ . '/../data',
        'log_dir'  => __DIR__ . '/../logs',
    ],

    // ---- 網頁顯示 ----
    'web' => [
        'title'        => '今日公文 AI 分辦結果',
        'auto_refresh' => 60,
    ],
];
