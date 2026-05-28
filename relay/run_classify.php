<?php
/**
 * 公文分辦 — AI 推論主程式（同步版）
 * ----------------------------------------------------------
 * 流程：
 *   1. 載入公文清單（目前為 sample，TODO 接 SQL Server）
 *   2. 確認當日資料夾 data/YYYYMMDD/ 存在
 *   3. 對每筆公文：
 *       a. 若 data/YYYYMMDD/{doc_id}.json 已存在 → 跳過
 *       b. POST 到 Ollama /api/chat 取得預測
 *       c. 組合 JSON（原資料 + 預測 + meta） → 原子寫入
 *   4. 印統計與耗時
 *
 * 執行：
 *   php run_classify.php
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

$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

// ---- 取得公文清單 ----------------------------------------
// TODO: 後續改成從 SQL Server 撈當日未處理公文
//       function fetch_pending_docs(): array { ... PDO 查詢 ... }
function fetch_pending_docs(): array {
    return [
        [
            'doc_id'   => 'SAMPLE-20260528-001',
            '來文機關' => '行政院農業委員會林務局',
            '來文字'   => '林政字',
            '來文主旨' => '檢送本局113年度森林資源調查計畫，請查照辦理',
        ],
        [
            'doc_id'   => 'SAMPLE-20260528-002',
            '來文機關' => '○○縣政府',
            '來文字'   => '府教字',
            '來文主旨' => '函請貴處協助辦理戶外教育活動場地申請事宜',
        ],
        [
            'doc_id'   => 'SAMPLE-20260528-003',
            '來文機關' => '行政院主計總處',
            '來文字'   => '主預字',
            '來文主旨' => '函送114年度單位預算編製作業要點',
        ],
        [
            'doc_id'   => 'SAMPLE-20260528-004',
            '來文機關' => '考選部',
            '來文字'   => '選特字',
            '來文主旨' => '公告本年度公務人員特種考試錄取人員分發事宜',
        ],
        [
            'doc_id'   => 'SAMPLE-20260528-005',
            '來文機關' => '國家森林遊樂區管理處',
            '來文字'   => '森遊字',
            '來文主旨' => '函送本年度遊客中心修繕工程招標規格',
        ],
    ];
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

// 2. 取得公文清單
$docs = fetch_pending_docs();
echo "  公文數量：" . count($docs) . "\n\n";

// 3. 逐筆處理
$stats = ['total' => 0, 'ok' => 0, 'unknown' => 0, 'error' => 0, 'skipped' => 0];
$msList = [];

foreach ($docs as $i => $doc) {
    $stats['total']++;
    $docId = $doc['doc_id'];
    $file  = $dayDir . DIRECTORY_SEPARATOR . $docId . '.json';

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
    $record = [
        'doc_id'       => $docId,
        'model'        => MODEL_NAME,
        'fetched_at'   => $now,
        'predicted_at' => date('c'),
        'elapsed_ms'   => round($result['ms'], 1),
        'status'       => $result['status'],
        'input'        => [
            '來文機關' => $doc['來文機關'],
            '來文字'   => $doc['來文字'],
            '來文主旨' => $doc['來文主旨'],
        ],
        'prediction'   => [
            'predicted_unit' => $result['pred'],
            'raw_response'   => $result['raw'],
        ],
    ];

    try {
        write_json_atomic($file, $record);
    } catch (Throwable $e) {
        fwrite(STDERR, "  [WRITE-ERR] $docId : " . $e->getMessage() . "\n");
        $stats['error']++;
        continue;
    }

    $stats[$result['status']]++;
    $tag = strtoupper($result['status']);
    $label = $result['pred'] !== '' ? $result['pred'] : ('「' . mb_substr($result['raw'], 0, 20) . '」');
    printf("  [%2d] [%-7s] %s → %s (%.0f ms)\n",
           $i + 1, $tag, $docId, $label, $result['ms']);
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
printf("  AI 平均  ：%.0f ms/筆\n", $avgMs);
printf("  總耗時   ：%.1f 秒\n", $elapsedSec);
printf("  結果路徑 ：%s\n", $dayDir);

exit($stats['error'] > 0 ? 2 : 0);
