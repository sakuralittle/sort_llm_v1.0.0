<?php
/**
 * 公文分辦 — AI 推論主程式（CSV 測試版）
 * ----------------------------------------------------------
 * 流程：
 *   1. 從 CSV 載入公文（CP950 → UTF-8 自動轉碼）
 *   2. 確認當日資料夾 data/YYYYMMDD/ 存在
 *   3. 對每筆公文：
 *       a. 若 data/YYYYMMDD/{總收文號}.json 已存在 → 跳過
 *       b. POST 到 Ollama /api/chat 取得預測
 *       c. 組合 JSON（原資料 + 預測 + 真實答案 + 是否正確） → 原子寫入
 *   4. 印統計與耗時、AI 正確率
 *
 * 執行：
 *   php "run_classify copy.php"
 *   php "run_classify copy.php" 50      # 只跑前 50 筆
 * ----------------------------------------------------------
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Taipei');

// ---- 設定（之後會搬到 config.php）-------------------------
const OLLAMA_BASE  = 'http://192.168.1.237:11434';
const MODEL_NAME   = 'gw-classify:finetune';
const TARGET_UNITS = ['主計室','人事室','企劃組','教學研究組','森林作業組',
                      '秘書室','管理組','總務組','育樂組'];
const TIMEOUT_SEC  = 90;

// CSV 測試資料來源（CP950 編碼）
const CSV_PATH     = __DIR__ . '/../data/init_data/1150305實驗林公文資料/1150305now_doc.csv';
const CSV_ENCODING = 'CP950';      // Windows Big5 超集（含香港字、特殊符號）
const DEFAULT_LIMIT = 10;           // 預設只跑前 N 筆，避免測試時跑爆

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

// ---- 取得公文清單（從 CSV 讀）-----------------------------
// 透過 stream filter 在讀檔時自動把 CP950 轉成 UTF-8，無需先複製檔案。
// 之後接 SQL Server 時，把這個函式換成 PDO 查詢即可。
function fetch_pending_docs(int $limit = DEFAULT_LIMIT): array {
    $path = CSV_PATH;
    if (!file_exists($path)) {
        throw new RuntimeException("CSV 不存在：$path");
    }

    $fp = fopen($path, 'r');
    if ($fp === false) {
        throw new RuntimeException("無法開啟 CSV：$path");
    }
    // 自動 CP950 → UTF-8；//IGNORE 容忍極少數無法對應的字
    stream_filter_append($fp, 'convert.iconv.' . CSV_ENCODING . '/UTF-8//IGNORE');

    // 讀 header
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException('CSV header 讀取失敗');
    }
    // 去除 BOM 與前後空白
    $header = array_map(
        fn($h) => trim(str_replace("\xEF\xBB\xBF", '', (string)$h)),
        $header
    );

    // 讀完整份 CSV（總收文號越大代表越新）
    $all = [];
    while (($row = fgetcsv($fp)) !== false) {
        // 不完整列補空字串後 combine（避免 array_combine 例外）
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        } elseif (count($row) > count($header)) {
            $row = array_slice($row, 0, count($header));
        }
        $rec = array_combine($header, $row);

        $docId = trim((string)($rec['總收文號'] ?? ''));
        if ($docId === '') continue;          // 跳過無主鍵

        $all[] = [
            'doc_id'       => $docId,
            '收文日期'     => trim((string)($rec['收文日期'] ?? '')),
            '來文機關'     => trim((string)($rec['來文機關'] ?? '')),
            '來文字'       => trim((string)($rec['來文字'] ?? '')),
            '來文主旨'     => trim((string)($rec['來文主旨'] ?? '')),
            'ground_truth' => trim((string)($rec['主辦單位'] ?? '')),  // 真實答案
        ];
    }
    fclose($fp);

    // 依總收文號降序排序（流水號越大越新）→ 取最新 N 筆
    usort($all, fn($a, $b) => strcmp($b['doc_id'], $a['doc_id']));
    if ($limit > 0) {
        $all = array_slice($all, 0, $limit);
    }
    return $all;
}

// ---- 將收文號轉為安全檔名（避免特殊字元）-----------------
function sanitize_filename(string $name): string {
    // 只保留英數、底線、減號、點
    $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $name);
    return $safe !== '' ? $safe : 'unknown';
}

// ---- AI 呼叫：低階 HTTP POST ----------------------------
function http_post(string $url, array $body, int $timeout = TIMEOUT_SEC): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $ms   = (microtime(true) - $t0) * 1000;
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ms' => $ms, 'code' => $code, 'body' => $resp, 'err' => $err];
}

// ---- AI 呼叫：高階分類函式 -------------------------------
function classify(array $doc): array {
    $user = "來文機關：{$doc['來文機關']}\n"
          . "來文字：{$doc['來文字']}\n"
          . "來文主旨：{$doc['來文主旨']}";

    $r = http_post(OLLAMA_BASE . '/api/chat', [
        'model'    => MODEL_NAME,
        'stream'   => false,
        'messages' => [['role' => 'user', 'content' => $user]],
        'options'  => ['temperature' => 0, 'num_predict' => 12],
    ]);

    if ($r['code'] !== 200) {
        return [
            'status' => 'error',
            'pred'   => '',
            'raw'    => $r['err'] ?: ('HTTP ' . $r['code']),
            'ms'     => $r['ms'],
        ];
    }

    $data = json_decode($r['body'], true);
    $raw  = trim($data['message']['content'] ?? '');

    // 白名單比對
    foreach (TARGET_UNITS as $u) {
        if (mb_strpos($raw, $u) !== false) {
            return ['status' => 'ok', 'pred' => $u, 'raw' => $raw, 'ms' => $r['ms']];
        }
    }
    return ['status' => 'unknown', 'pred' => '', 'raw' => $raw, 'ms' => $r['ms']];
}

// ---- 原子寫入 JSON --------------------------------------
function write_json_atomic(string $path, array $data): void {
    $tmp = $path . '.tmp';
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException("write failed: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("rename failed: $tmp → $path");
    }
}

// ==========================================================
//                        主流程
// ==========================================================

$runStart = microtime(true);
echo "==========================================\n";
echo " 公文分辦 AI 推論執行（" . date('Y-m-d H:i:s') . "）\n";
echo " Model    : " . MODEL_NAME . "\n";
echo " Endpoint : " . OLLAMA_BASE . "\n";
echo "==========================================\n\n";

// 1. 確認當日資料夾
$dateStr = date('Ymd');
$dayDir  = $DATA_DIR . DIRECTORY_SEPARATOR . $dateStr;
if (!is_dir($dayDir) && !mkdir($dayDir, 0777, true) && !is_dir($dayDir)) {
    fwrite(STDERR, "[FATAL] 無法建立資料夾：$dayDir\n");
    exit(1);
}
echo "  資料夾：$dayDir\n\n";

// 2. 取得公文清單（命令列第 1 參數可指定筆數）
$limit = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : DEFAULT_LIMIT;
try {
    $docs = fetch_pending_docs($limit);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] 載入 CSV 失敗：" . $e->getMessage() . "\n");
    exit(1);
}
echo "  CSV 來源：" . CSV_PATH . "\n";
if ($docs) {
    $newest = $docs[0]['doc_id']                . ' (' . $docs[0]['收文日期']                . ')';
    $oldest = $docs[count($docs)-1]['doc_id']   . ' (' . $docs[count($docs)-1]['收文日期']   . ')';
    echo "  讀取筆數：" . count($docs) . " 筆（最新 N=$limit）\n";
    echo "  範圍    ：$newest  ←→  $oldest\n\n";
} else {
    echo "  讀取筆數：0\n\n";
}

// 3. 逐筆處理
$stats = ['total' => 0, 'ok' => 0, 'unknown' => 0, 'error' => 0, 'skipped' => 0, 'correct' => 0];
$msList = [];

foreach ($docs as $i => $doc) {
    $stats['total']++;
    $docId    = $doc['doc_id'];
    $fileName = sanitize_filename($docId);
    $file     = $dayDir . DIRECTORY_SEPARATOR . $fileName . '.json';

    // 3a. 跳過已存在
    if (file_exists($file)) {
        $stats['skipped']++;
        printf("  [%2d] [SKIP]    %s（已存在）\n", $i + 1, $docId);
        continue;
    }

    // 3b. 呼叫 AI
    $now = date('c');
    try {
        $result = classify($doc);
    } catch (Throwable $e) {
        $result = ['status' => 'error', 'pred' => '', 'raw' => $e->getMessage(), 'ms' => 0];
    }
    $msList[] = $result['ms'];

    // 3c. 組裝 + 寫檔
    $isCorrect = ($result['status'] === 'ok'
                  && $doc['ground_truth'] !== ''
                  && $result['pred'] === $doc['ground_truth']);

    $record = [
        'doc_id'       => $docId,
        'model'        => MODEL_NAME,
        'fetched_at'   => $now,
        'predicted_at' => date('c'),
        'elapsed_ms'   => round($result['ms'], 1),
        'status'       => $result['status'],
        'input'        => [
            '收文日期' => $doc['收文日期'],
            '來文機關' => $doc['來文機關'],
            '來文字'   => $doc['來文字'],
            '來文主旨' => $doc['來文主旨'],
        ],
        'prediction'   => [
            'predicted_unit' => $result['pred'],
            'raw_response'   => $result['raw'],
        ],
        'ground_truth' => $doc['ground_truth'],   // CSV 內既有的真實答案（驗證用）
        'correct'      => $isCorrect,
    ];

    try {
        write_json_atomic($file, $record);
    } catch (Throwable $e) {
        fwrite(STDERR, "  [WRITE-ERR] $docId : " . $e->getMessage() . "\n");
        $stats['error']++;
        continue;
    }

    $stats[$result['status']]++;
    if ($isCorrect) $stats['correct']++;

    $tag   = strtoupper($result['status']);
    $label = $result['pred'] !== '' ? $result['pred'] : ('「' . mb_substr($result['raw'], 0, 20) . '」');
    $mark  = '';
    if ($result['status'] === 'ok' && $doc['ground_truth'] !== '') {
        $mark = $isCorrect ? '  ✓' : ('  ✗ (實際:' . $doc['ground_truth'] . ')');
    }
    printf("  [%3d] [%-7s] %s → %s (%.0f ms)%s\n",
           $i + 1, $tag, $docId, $label, $result['ms'], $mark);
}

// 4. 統計
$elapsedSec = microtime(true) - $runStart;
$avgMs      = $msList ? array_sum($msList) / count($msList) : 0;
echo "\n==========================================\n";
echo " 統計\n";
echo "==========================================\n";
printf("  總數     ：%d\n", $stats['total']);
printf("  成功 ok  ：%d\n", $stats['ok']);
printf("  未知 unk ：%d\n", $stats['unknown']);
printf("  錯誤 err ：%d\n", $stats['error']);
printf("  跳過 skip：%d\n", $stats['skipped']);
echo "  -----------------------\n";
$evalN = $stats['ok'];   // 只計算 ok 且有真實答案的
if ($evalN > 0) {
    printf("  AI 正確  ：%d / %d  (%.1f%%)\n",
           $stats['correct'], $evalN, $stats['correct'] / $evalN * 100);
}
printf("  AI 平均  ：%.0f ms/筆\n", $avgMs);
printf("  總耗時   ：%.1f 秒\n", $elapsedSec);
printf("  結果路徑 ：%s\n", $dayDir);

exit($stats['error'] > 0 ? 2 : 0);
