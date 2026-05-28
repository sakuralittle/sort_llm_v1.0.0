<?php
/**
 * T3：SQL Server 連線與 schema 探勘
 * ----------------------------------------------------------
 * 用途：
 *   1. 確認能連上 SQL Server
 *   2. 列出指定資料表的欄位（協助確認欄位名稱）
 *   3. 抓最近 5 筆樣本（確認資料內容、編碼、null 狀況）
 *
 * 執行：
 *   設好下方常數後 → php test_sql.php
 * ----------------------------------------------------------
 * ★ 請依實際環境修改下列常數 ★
 */

declare(strict_types=1);

const DB_DRIVER   = 'sqlsrv';        // 'sqlsrv' 或 'odbc'（依擴充而定）
const DB_HOST     = '192.168.x.x';   // ← TODO 改成 SQL Server IP 或 hostname
const DB_PORT     = 1433;
const DB_NAME     = 'YourDatabase';  // ← TODO 改成資料庫名
const DB_USER     = 'your_user';     // ← TODO
const DB_PASS     = 'your_pass';     // ← TODO（實際正式環境請改用環境變數）
const DB_TABLE    = 'dbo.公文表';    // ← TODO 改成公文資料表的 schema.table
const DB_DATE_COL = '收文日期';       // ← TODO 用來篩「今日」的欄位

// ---- 建立 PDO 連線字串 ----
function build_dsn(): string {
    if (DB_DRIVER === 'sqlsrv') {
        // 需要 pdo_sqlsrv 擴充
        return sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=no;TrustServerCertificate=yes',
            DB_HOST, DB_PORT, DB_NAME
        );
    }
    // ODBC 範例（需先在系統建立 ODBC DSN，或用 driver 字串）
    return sprintf(
        'odbc:Driver={ODBC Driver 17 for SQL Server};Server=%s,%d;Database=%s;',
        DB_HOST, DB_PORT, DB_NAME
    );
}

echo "==========================================\n";
echo " T3  SQL Server 連線與 schema 探勘\n";
echo "==========================================\n";
echo " Driver : " . DB_DRIVER . "\n";
echo " Host   : " . DB_HOST . "\n";
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
    echo "      - pdo_sqlsrv 未啟用（檢查 phpinfo() 或執行 test_env.php）\n";
    echo "      - 帳密錯誤\n";
    echo "      - SQL Server 未啟用 TCP/IP 或防火牆擋 1433\n";
    echo "      - hostname / IP 寫錯\n";
    exit(1);
}

// ---- 步驟 2：列出欄位 schema ----
echo "[2] 列出資料表欄位...\n";
$tableParts = explode('.', DB_TABLE);
$schema = count($tableParts) === 2 ? $tableParts[0] : 'dbo';
$table  = end($tableParts);

try {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([':schema' => $schema, ':table' => $table]);
    $cols = $stmt->fetchAll();
    if (!$cols) {
        echo "    [FAIL] 找不到資料表 " . DB_TABLE . "（檢查 schema.table 名稱）\n";
        exit(1);
    }
    printf("    [OK] 共 %d 個欄位：\n", count($cols));
    printf("    %-30s %-15s %-8s %s\n", '欄位名', '型別', '長度', 'NULL');
    echo "    " . str_repeat('-', 70) . "\n";
    foreach ($cols as $c) {
        printf("    %-30s %-15s %-8s %s\n",
               $c['COLUMN_NAME'],
               $c['DATA_TYPE'],
               $c['CHARACTER_MAXIMUM_LENGTH'] ?? '-',
               $c['IS_NULLABLE']);
    }
    echo "\n";
} catch (PDOException $e) {
    echo "    [FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// ---- 步驟 3：抓最近 5 筆 ----
echo "[3] 抓最近 5 筆資料（限 TOP 5）...\n";
try {
    // 先抓 TOP 5 看看資料長相
    $sql = sprintf("SELECT TOP 5 * FROM %s ORDER BY %s DESC", DB_TABLE, DB_DATE_COL);
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

// ---- 步驟 4：今日公文計數 ----
echo "[4] 今日公文計數...\n";
try {
    $today = date('Y-m-d');
    $sql = sprintf("SELECT COUNT(*) AS cnt FROM %s WHERE CAST(%s AS DATE) = ?",
                   DB_TABLE, DB_DATE_COL);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today]);
    $cnt = (int)$stmt->fetchColumn();
    printf("    [OK] %s 共 %d 筆公文\n", $today, $cnt);
} catch (PDOException $e) {
    echo "    [WARN] " . $e->getMessage() . "\n";
}

echo "\n==========================================\n";
echo " 完成。請把上方欄位清單貼給開發者，以便 M1 撰寫正式 SELECT 語句。\n";
echo "==========================================\n";
