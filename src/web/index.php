<?php
declare(strict_types=1);

/**
 * 公文分辦結果 — 內網瀏覽頁
 * ----------------------------------------------------------
 * 進入點：http://<中繼主機>/
 * 直接讀 data/YYYYMMDD/*.json，全資料一次塞進頁面，前端做即時搜尋/篩選。
 * ----------------------------------------------------------
 */

$config = require __DIR__ . '/../lib/bootstrap.php';

$store = new JsonStore($config['paths']['data_dir']);

// 允許 ?date=YYYYMMDD（必須是 8 位數字）；預設今天
$dateStr = $_GET['date'] ?? date('Ymd');
if (!preg_match('/^\d{8}$/', $dateStr)) {
    $dateStr = date('Ymd');
}

$records = $store->listForDate($dateStr);

// 統計
$stats = ['total' => count($records), 'ok' => 0, 'unknown' => 0, 'error' => 0];
$byUnit = [];
foreach ($records as $r) {
    $st = $r['status'] ?? 'error';
    if (!isset($stats[$st])) $stats[$st] = 0;
    $stats[$st]++;
    $u = $r['prediction']['ai_predicted_unit'] ?? $r['prediction']['predicted_unit'] ?? '';
    if ($u !== '') {
        $byUnit[$u] = ($byUnit[$u] ?? 0) + 1;
    }
}
arsort($byUnit);

$autoRefresh = (int)($config['web']['auto_refresh'] ?? 0);
$title       = (string)($config['web']['title'] ?? '今日公文 AI 分辦結果');

// 給前端 JS 用的精簡資料
$jsRows = [];
foreach ($records as $r) {
    $jsRows[] = [
        'doc_id'   => (string)($r['doc_id'] ?? ''),
        'date'     => (string)($r['input']['收文日期'] ?? ''),
        'org'      => (string)($r['input']['來文機關'] ?? ''),
        'kind'     => (string)($r['input']['來文字']   ?? ''),
        'subject'  => (string)($r['input']['來文主旨'] ?? ''),
        'unit'     => (string)($r['prediction']['ai_predicted_unit'] ?? $r['prediction']['predicted_unit'] ?? ''),
        'raw'      => (string)($r['prediction']['raw_response']   ?? ''),
        'status'   => (string)($r['status']        ?? ''),
        'ms'       => (float)($r['elapsed_ms']     ?? 0),
        'pred_at'  => (string)($r['predicted_at']  ?? ''),
    ];
}

// 顯示用日期格式
$displayDate = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($title) ?> - <?= h($displayDate) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/jpeg" href="ciallo.jpg">
<link rel="shortcut icon" type="image/jpeg" href="ciallo.jpg">
<?php if ($autoRefresh > 0): ?>
<meta http-equiv="refresh" content="<?= (int)$autoRefresh ?>">
<?php endif; ?>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <h1><?= h($title) ?></h1>
  <div class="datepick">
    <form method="get" class="datepick-form">
      <label>日期
        <input type="date" name="date" value="<?= h($displayDate) ?>" onchange="this.form.submit()">
      </label>
      <noscript><button type="submit">查詢</button></noscript>
    </form>
    <span class="ts">最後更新：<?= h(date('Y-m-d H:i:s')) ?><?php if ($autoRefresh > 0): ?>（每 <?= (int)$autoRefresh ?> 秒自動重整）<?php endif; ?></span>
  </div>
</header>

<section class="cards">
  <div class="card"><div class="card-label">總筆數</div><div class="card-num"><?= (int)$stats['total'] ?></div></div>
  <div class="card ok"><div class="card-label">成功</div><div class="card-num"><?= (int)$stats['ok'] ?></div></div>
  <div class="card warn"><div class="card-label">未知</div><div class="card-num"><?= (int)$stats['unknown'] ?></div></div>
  <div class="card err"><div class="card-label">錯誤</div><div class="card-num"><?= (int)$stats['error'] ?></div></div>
</section>

<?php if (!empty($byUnit)): ?>
<section class="bypanel">
  <h2>各組室分辦數</h2>
  <div class="chips">
    <?php foreach ($byUnit as $u => $n): ?>
      <button type="button" class="chip" data-unit="<?= h($u) ?>"><?= h($u) ?> <span class="chip-n"><?= (int)$n ?></span></button>
    <?php endforeach; ?>
    <button type="button" class="chip chip-all active" data-unit="">全部</button>
  </div>
</section>
<?php endif; ?>

<section class="tools">
  <input type="search" id="q" placeholder="搜尋公文代號 / 來文機關 / 主旨…" autocomplete="off">
  <label class="filter">
    <select id="statusFilter">
      <option value="">全部狀態</option>
      <option value="ok">僅成功</option>
      <option value="unknown">僅未知</option>
      <option value="error">僅錯誤</option>
    </select>
  </label>
  <span class="count" id="count"></span>
</section>

<main>
<?php if (empty($records)): ?>
  <div class="empty">
    今日（<?= h($displayDate) ?>）尚無資料。<br>
    若已執行 <code>run_classify.php</code>，請檢查 <code>data/<?= h($dateStr) ?>/</code> 是否有產生 JSON。
  </div>
<?php else: ?>
  <table id="grid">
    <thead>
      <tr>
        <th>#</th>
        <th>公文代號</th>
        <th>收文日期</th>
        <th>來文機關</th>
        <th>主旨</th>
        <th>AI分辦組室</th>
        <th>AI分文狀態</th>
        <th>耗時</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
<?php endif; ?>
</main>

<script>
  window.__ROWS__ = <?= json_encode($jsRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
