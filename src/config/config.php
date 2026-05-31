<?php
/**
 * 公文分辦系統設定 — 實際正式設定（含機敏資料）
 */

return [
    'timezone' => 'Asia/Taipei',

    // ---- MySQL（透過本機 SSH tunnel 轉發到 192.168.6.58:3306）----
    'db' => [
        'driver' => 'mysql',           // mysql / sqlsrv / odbc
        'host'   => '127.0.0.1',       // 走 SSH tunnel
        'port'   => 13306,              // 與 ssh_tunnel.bat 的 LOCAL_PORT 一致
        'name'   => 'EXFODBS',
        'user'   => 'exfoselect',
        'pass'   => 'EXFO@34qwe',
        'table'  => 'IFTDC_INDCM',
        'charset'=> 'utf8mb4',         // MySQL 連線字集

        // 欄位映射
        'columns' => [
            'id'      => 'INNO',
            'date'    => 'INDATE',
            'org'     => 'GUNAME',
            'subject' => 'THEME',
            // 'kind' => 'INKIND',
        ],

        'lookback_days' => 1,
        'max_rows'      => 500,
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

    'paths' => [
        'data_dir' => __DIR__ . '/../data',
        'log_dir'  => __DIR__ . '/../logs',
    ],

    'web' => [
        'title'        => '今日公文 AI 分辦結果',
        'auto_refresh' => 60,
    ],
];
