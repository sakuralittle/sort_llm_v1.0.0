<?php
declare(strict_types=1);

/**
 * 公文分辦 — 主程式（同步版）
 * ----------------------------------------------------------
 * 1. 從 SQL Server 撈最近 N 天的公文
 * 2. 過濾掉已產出 JSON 的公文
 * 3. 對每筆呼叫 Ollama AI，原子寫入 data/YYYYMMDD/<INNO>.json
 * 4. 印統計與耗時，並寫 log
 *
 * 用法：
 *   php bin/run_classify.php          # 跑全部未處理
 *   php bin/run_classify.php 5        # 只跑前 5 筆（測試用）
 * ----------------------------------------------------------
 */

$config = require __DIR__ . '/../lib/bootstrap.php';

$logger  = new Logger($config['paths']['log_dir']);
$store   = new JsonStore($config['paths']['data_dir']);
$ai      = new AiClassifier($config['ai']);
$repo    = new DocRepository($config['db']);

$limit = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 0;
$today = date('Ymd');
$runT0 = microtime(true);

$logger->raw('==========================================');
$logger->raw(' 公文分辦 AI 推論執行（' . date('Y-m-d H:i:s') . '）');
$logger->raw(' Model    : ' . $ai->model());
$logger->raw(' Endpoint : ' . $ai->baseUrl());
$logger->raw(' Lookback : ' . (int)$config['db']['lookback_days'] . ' 天');
if ($limit > 0) $logger->raw(' Limit    : ' . $limit . ' 筆（測試用）');
$logger->raw('==========================================');

// 1. 確保當日資料夾
$dayDir = $store->dayDir($today);
$logger->info("資料夾：$dayDir");

// 2. AI 健康檢查（失敗就直接結束，避免大量 error JSON 弄髒當日資料夾）
$ping = $ai->ping();
if (!$ping['ok']) {
    $logger->error($ping['msg']);
    exit(1);
}
$logger->info($ping['msg']);

// 3. 撈候選公文
try {
    $docs = $repo->fetchRecent(
        (int)($config['db']['lookback_days'] ?? 1),
        (int)($config['db']['max_rows']      ?? 500)
    );
} catch (Throwable $e) {
    $logger->error('SQL 撈取失敗：' . $e->getMessage());
    $logger->error('提示：確認 SSH tunnel 已啟動、config.php 連線資訊正確。');
    exit(1);
}
$logger->info('SQL 候選公文：' . count($docs) . ' 筆');

// 4. 過濾已處理
$pending = [];
$skipped = 0;
foreach ($docs as $d) {
    if ($store->isProcessed($today, $d['doc_id'])) {
        $skipped++;
        continue;
    }
    $pending[] = $d;
}
$logger->info("已處理略過：$skipped 筆；待處理：" . count($pending) . ' 筆');

if ($limit > 0 && count($pending) > $limit) {
    $pending = array_slice($pending, 0, $limit);
    $logger->info('套用 --limit，實際處理：' . count($pending) . ' 筆');
}

// 5. 逐筆呼叫 AI
$stats  = ['total' => 0, 'ok' => 0, 'unknown' => 0, 'error' => 0];
$msList = [];

foreach ($pending as $i => $doc) {
    $stats['total']++;
    $docId = $doc['doc_id'];
    $now   = date('c');

    try {
        $r = $ai->classify($doc);
    } catch (Throwable $e) {
        $r = ['status' => 'error', 'pred' => '', 'raw' => $e->getMessage(), 'ms' => 0.0];
    }
    $msList[] = $r['ms'];

    $record = [
        'doc_id'       => $docId,
        'model'        => $ai->model(),
        'fetched_at'   => $now,
        'predicted_at' => date('c'),
        'elapsed_ms'   => round($r['ms'], 1),
        'status'       => $r['status'],
        'input'        => [
            '收文日期' => $doc['收文日期'],
            '來文機關' => $doc['來文機關'],
            '來文字'   => $doc['來文字'],
            '來文主旨' => $doc['來文主旨'],
        ],
        'prediction'   => [
            'ai_predicted_unit' => $r['pred'],
            'raw_response'      => $r['raw'],
        ],
    ];

    try {
        $store->write($today, $docId, $record);
    } catch (Throwable $e) {
        $logger->error("[WRITE-ERR] $docId : " . $e->getMessage());
        $stats['error']++;
        continue;
    }

    $stats[$r['status']]++;
    $label = $r['pred'] !== '' ? $r['pred'] : ('「' . mb_substr($r['raw'], 0, 20) . '」');
    $logger->raw(sprintf(
        '  [%3d] [%-7s] %s → %s (%.0f ms)',
        $i + 1, strtoupper($r['status']), $docId, $label, $r['ms']
    ));
}

// 6. 統計
$elapsedSec = microtime(true) - $runT0;
$avgMs      = $msList ? array_sum($msList) / count($msList) : 0;

$logger->raw('');
$logger->raw('==========================================');
$logger->raw(' 統計');
$logger->raw('==========================================');
$logger->raw(sprintf('  總處理   ：%d', $stats['total']));
$logger->raw(sprintf('  成功 ok  ：%d', $stats['ok']));
$logger->raw(sprintf('  未知 unk ：%d', $stats['unknown']));
$logger->raw(sprintf('  錯誤 err ：%d', $stats['error']));
$logger->raw(sprintf('  跳過 skip：%d', $skipped));
$logger->raw('  -----------------------');
$logger->raw(sprintf('  AI 平均  ：%.0f ms/筆', $avgMs));
$logger->raw(sprintf('  總耗時   ：%.1f 秒', $elapsedSec));
$logger->raw(sprintf('  結果路徑 ：%s', $dayDir));
$logger->raw(sprintf('  Log 檔   ：%s', $logger->file()));

exit($stats['error'] > 0 ? 2 : 0);
