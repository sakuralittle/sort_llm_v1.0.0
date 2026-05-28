<?php
/**
 * T2：Ollama AI 主機連通性與效能測試
 * ----------------------------------------------------------
 * 測試項目：
 *   1. GET  /api/tags           — 確認模型存在
 *   2. POST /api/chat（暖機 1 筆）— 量測冷啟動時間
 *   3. POST /api/chat（連續 5 筆）— 量測平均熱啟動時間
 * 執行：php test_ai.php
 * ----------------------------------------------------------
 */

declare(strict_types=1);

const OLLAMA_BASE  = 'http://192.168.1.237:11434';
const MODEL_NAME   = 'gw-classify:finetune';
const TARGET_UNITS = ['主計室','人事室','企劃組','教學研究組','森林作業組',
                      '秘書室','管理組','總務組','育樂組'];

// ---- 5 筆固定測試樣本（用於穩定性比較）----
$samples = [
    ['來文機關'=>'行政院農業委員會林務局', '來文字'=>'林政字',  '來文主旨'=>'檢送本局113年度森林資源調查計畫，請查照辦理'],
    ['來文機關'=>'○○縣政府',             '來文字'=>'府教字',  '來文主旨'=>'函請貴處協助辦理戶外教育活動場地申請事宜'],
    ['來文機關'=>'行政院主計總處',         '來文字'=>'主預字',  '來文主旨'=>'函送114年度單位預算編製作業要點'],
    ['來文機關'=>'考選部',                 '來文字'=>'選特字',  '來文主旨'=>'公告本年度公務人員特種考試錄取人員分發事宜'],
    ['來文機關'=>'國家森林遊樂區管理處',   '來文字'=>'森遊字',  '來文主旨'=>'函送本年度遊客中心修繕工程招標規格'],
];

function http_post(string $url, array $body, int $timeout = 90): array {
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
    return ['ms'=>$ms, 'code'=>$code, 'body'=>$resp, 'err'=>$err];
}

function classify(array $row): array {
    $user = "來文機關：{$row['來文機關']}\n來文字：{$row['來文字']}\n來文主旨：{$row['來文主旨']}";
    $r = http_post(OLLAMA_BASE . '/api/chat', [
        'model'    => MODEL_NAME,
        'stream'   => false,
        'messages' => [['role'=>'user', 'content'=>$user]],
        'options'  => ['temperature'=>0, 'num_predict'=>12],
    ]);
    if ($r['code'] !== 200) {
        return ['ms'=>$r['ms'], 'pred'=>'ERROR', 'raw'=>$r['err'] ?: ('HTTP '.$r['code'])];
    }
    $data = json_decode($r['body'], true);
    $raw  = trim($data['message']['content'] ?? '');
    $pred = 'unknown';
    foreach (TARGET_UNITS as $u) {
        if (mb_strpos($raw, $u) !== false) { $pred = $u; break; }
    }
    return ['ms'=>$r['ms'], 'pred'=>$pred, 'raw'=>$raw];
}

echo "==========================================\n";
echo " T2  Ollama AI 主機連通性 + 效能測試\n";
echo "==========================================\n";
echo " Endpoint : " . OLLAMA_BASE . "\n";
echo " Model    : " . MODEL_NAME . "\n\n";

// ---- 步驟 1：確認模型存在 ----
echo "[1] GET /api/tags 確認模型清單...\n";
$ch = curl_init(OLLAMA_BASE . '/api/tags');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    echo "    [FAIL] 連不到 AI 主機：$err (HTTP $code)\n";
    echo "    請檢查：(a) 中繼主機到 192.168.1.237 的網路 (b) Ollama 服務是否啟動\n";
    exit(1);
}
$tags = json_decode($body, true);
$names = array_map(fn($m) => $m['name'], $tags['models'] ?? []);
echo "    [OK] 取得 " . count($names) . " 個模型\n";
if (!in_array(MODEL_NAME, $names, true)) {
    echo "    [FAIL] 找不到模型 " . MODEL_NAME . "\n";
    echo "    可用模型：" . implode(', ', $names) . "\n";
    exit(1);
}
echo "    [OK] 模型 " . MODEL_NAME . " 已存在\n\n";

// ---- 步驟 2：暖機（量測冷啟動）----
echo "[2] 暖機呼叫（量測冷啟動時間）...\n";
$warm = classify($samples[0]);
printf("    第 1 筆：%6.0f ms  → 預測=%s  原始回應=「%s」\n",
       $warm['ms'], $warm['pred'], $warm['raw']);
if ($warm['pred'] === 'ERROR') {
    echo "    [FAIL] 推論失敗，原因：" . $warm['raw'] . "\n";
    exit(1);
}
if ($warm['ms'] > 5000) {
    echo "    （冷啟動偏慢，正常現象，模型正在載入記憶體）\n";
}
echo "\n";

// ---- 步驟 3：連續 5 筆（量測熱啟動）----
echo "[3] 連續 5 筆推論（量測熱啟動平均）...\n";
$msList = [];
foreach ($samples as $i => $row) {
    $r = classify($row);
    $msList[] = $r['ms'];
    printf("    第 %d 筆：%6.0f ms  → 預測=%s\n",
           $i + 1, $r['ms'], $r['pred']);
}
$avg = array_sum($msList) / count($msList);
$min = min($msList);
$max = max($msList);
echo "\n";
printf("    平均：%6.0f ms   最快：%6.0f ms   最慢：%6.0f ms\n", $avg, $min, $max);

// ---- 結論 ----
echo "\n==========================================\n";
echo " 結論\n";
echo "==========================================\n";
printf(" 第 1 筆（含冷啟動）：%.0f ms\n", $warm['ms']);
printf(" 後 5 筆（熱啟動）平均：%.0f ms\n", $avg);
echo "\n建議：\n";
printf(" - run_classify.php 的 CURLOPT_TIMEOUT 設為 %d（= max + 餘裕）\n",
       max(60, (int)ceil($max / 1000) + 30));
printf(" - 預估每批 30 筆：冷啟動 %.0f + 29×%.0f ≈ %.0f 秒\n",
       $warm['ms']/1000, $avg, ($warm['ms'] + 29 * $avg) / 1000);
echo "==========================================\n";
