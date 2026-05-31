<?php
/**
 * T3：MySQL 連線與 schema 探勘
 * ----------------------------------------------------------
 * 用途：
 *   1. 確認能連上 MySQL（透過 SSH tunnel：127.0.0.1:13306）
 *   2. 列出指定資料表的欄位
 *   3. 抓最近 5 筆樣本（確認資料內容、編碼、null 狀況）
 *   4. 計算今日筆數
 *
 * 執行：
 *   php relay\tools\test_sql.php
 * ----------------------------------------------------------
 */

declare(strict_types=1);

const DB_DRIVER   = 'mysql';
const DB_HOST     = '127.0.0.1';     // 走 SSH tunnel
const DB_PORT     = 13306;           // 與 ssh_tunnel.bat 的 LOCAL_PORT 一致
const DB_NAME     = 'EXFODBS';
const DB_USER     = 'exfoselect';
const DB_PASS     = 'EXFO@34qwe';
const DB_TABLE    = 'IFTDC_INDCM';
const DB_DATE_COL = 'INDATE';
const DB_CHARSET  = 'utf8mb4';

function build_dsn(): string {
    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
}

echo "==========================================\n";
echo " T3  MySQL 連線與 schema 探勘\n";
echo "==========================================\n";
echo " Driver : " . DB_DRIVER . "\n";
echo " Host   : " . DB_HOST . ":" . DB_PORT . "\n";
echo " DB     : " . DB_NAME . "\n";
echo " Table  : " . DB_TABLE . "\n\n";

// ---- 步驟 1：建立連線 ----
echo "[1] 建立連線...\n";
try {
    $pdo = new PDO(build_dsn(), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "    [OK] 連線成功\n\n";
} catch (PDOException $e) {
    echo "    [FAIL] " . $e->getMessage() . "\n";
    echo "    常見原因：\n";
    echo "      - pdo_mysql 未啟用（執行 php -m | findstr mysql 確認）\n";
    echo "      - SSH tunnel 沒開（127.0.0.1:13306 不通）\n";
    echo "      - 帳密錯誤 / DB 名稱錯誤 / 該帳號無 EXFODBS 存取權\n";
    exit(1);
}

// ---- 步驟 2：列出欄位 ----
echo "[2] 列出資料表欄位...\n";
try {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_COMMENT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([':db' => DB_NAME, ':tbl' => DB_TABLE]);
    $cols = $stmt->fetchAll();
    if (!$cols) {
        echo "    [FAIL] 找不到資料表 " . DB_TABLE . "（檢查表名大小寫，MySQL 在 Linux 上預設區分大小寫）\n";
        exit(1);
    }
    printf("    [OK] 共 %d 個欄位：\n", count($cols));
    printf("    %-25s %-15s %-8s %-6s %s\n", '欄位名', '型別', '長度', 'NULL', '註解');
    echo "    " . str_repeat('-', 80) . "\n";
    foreach ($cols as $c) {
        printf("    %-25s %-15s %-8s %-6s %s\n",
               $c['COLUMN_NAME'],
               $c['DATA_TYPE'],
               $c['CHARACTER_MAXIMUM_LENGTH'] ?? '-',
               $c['IS_NULLABLE'],
               mb_substr((string)($c['COLUMN_COMMENT'] ?? ''), 0, 30));
    }
    echo "\n";
} catch (PDOException $e) {
    echo "    [FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// ---- 步驟 3：抓最近 5 筆 ----
echo "[3] 抓最近 5 筆資料...\n";
try {
    $sql = sprintf("SELECT * FROM `%s` ORDER BY `%s` DESC LIMIT 5",
                   DB_TABLE, DB_DATE_COL);
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo "    [WARN] 資料表是空的\n";
    } else {
        printf("    [OK] 取得 %d 筆\n\n", count($rows));
        foreach ($rows as $i => $r) {
            echo "    --- 第 " . ($i+1) . " 筆 ---\n";
            foreach ($r as $k => $v) {
                $val = is_string($v) ? mb_substr($v, 0, 60) : (string)$v;
                printf("    %-20s : %s\n", $k, $val);
            }
            echo "\n";
        }
    }
} catch (PDOException $e) {
    echo "    [FAIL] " . $e->getMessage() . "\n";
    echo "    （請確認 " . DB_DATE_COL . " 欄位是否存在）\n";
    exit(1);
}

// ---- 步驟 4：今日公文計數（INDATE 是民國年字串）----
echo "[4] 今日公文計數...\n";
try {
    $rocDate = sprintf('%d/%s', (int)date('Y') - 1911, date('m/d'));  // 例：115/05/31
    $sql = sprintf("SELECT COUNT(*) AS cnt FROM `%s` WHERE `%s` = ?", DB_TABLE, DB_DATE_COL);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$rocDate]);
    $cnt = (int)$stmt->fetchColumn();
    printf("    [OK] 民國 %s（西元 %s）共 %d 筆公文\n", $rocDate, date('Y-m-d'), $cnt);
} catch (PDOException $e) {
    echo "    [WARN] " . $e->getMessage() . "\n";
}

echo "\n==========================================\n";
echo " 完成。請把上方欄位清單貼給開發者。\n";
echo "==========================================\n";
