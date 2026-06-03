<?php
/**
 * 公文分辦系統 — 設定檔
 * ----------------------------------------------------------
 * 安裝注意事項：
 *   - 本檔含 DB 密碼，請勿入 git；本資料夾的 .htaccess 已封鎖 HTTP 直接存取
 *   - DB 連線走 SSH tunnel（127.0.0.1:LOCAL_PORT）→ 跳板機 → 內網 MySQL
 *     必須先用 ssh_tunnel.bat 開啟隧道
 * ----------------------------------------------------------
 */

return [
    'timezone' => 'Asia/Taipei',

    // ---- MySQL（透過本機 SSH tunnel 轉發到內網 192.168.6.58:3306）----
    'db' => [
        'driver'  => 'mysql',
        'host'    => '127.0.0.1',    // 走 SSH tunnel 固定 127.0.0.1
        'port'    => 13306,          // 必須與 ssh_tunnel.bat 的 LOCAL_PORT 相同
        'name'    => 'EXFODBS',
        'user'    => 'exfoselect',
        'pass'    => 'EXFO@34qwe',
        'table'   => 'IFTDC_INDCM',
        'charset' => 'utf8mb4',

        // 欄位映射：左鍵固定，右值依實際 schema 修改
        'columns' => [
            'id'      => 'INNO',     // 主鍵 / 公文識別號（檔名）
            'date'    => 'INDATE',   // 收文日期（民國年字串 例：115/05/31）
            'org'     => 'GUNAME',   // 來文機關
            'subject' => 'THEME',    // 主旨
            'kind'    => 'THEYNO1',  // 來文字（可空字串關閉）
        ],

        // 撈最近 N 天的公文（1 = 只看今天）
        'lookback_days' => 1,
        // 單次最大撈取筆數（安全上限）
        'max_rows'      => 500,
    ],

    // ---- Ollama AI 主機 ----
    'ai' => [
        'base_url'    => 'http://192.168.1.237:11434',
        'model'       => 'gw-classify:finetune',
        'timeout_sec' => 90,
        // AI 回應命中白名單視為 ok，否則為 unknown
        'target_units' => [
            '主計室', '人事室', '企劃組', '教學研究組', '森林作業組',
            '秘書室', '管理組', '總務組', '育樂組',
        ],
    ],

    // ---- 輸出路徑（相對本資料夾，會自動建立）----
    'paths' => [
        'data_dir' => __DIR__ . '/data',   // data/YYYYMMDD/<doc_id>.json
        'log_dir'  => __DIR__ . '/logs',   // logs/YYYYMMDD.log + scheduler.log
    ],

    // ---- 網頁顯示 ----
    'web' => [
        'title'        => '今日公文 AI 分辦結果',
        'auto_refresh' => 60,   // 秒；設 0 關閉自動重整
    ],
];
