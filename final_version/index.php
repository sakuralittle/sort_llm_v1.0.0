<?php
declare(strict_types=1);

/**
 * 公文分辦系統 — 單一入口（雙模式）
 * ============================================================
 *  CLI 模式（由 Windows 工作排程器每 5 分鐘觸發 run_classify.bat）：
 *     1. 從 MySQL 撈最近 N 天的公文（走本機 SSH tunnel → 跳板 → 內網）
 *     2. 過濾掉已產 JSON 的公文
 *     3. 呼叫 Ollama AI 取得分辦組室，原子寫入 data/YYYYMMDD/<INNO>.json
 *
 *  HTTP 模式（XAMPP Apache HTTPS）：
 *     讀 data/YYYYMMDD/*.json 一次塞進頁面，前端做即時搜尋／篩選
 *
 *  入口：
 *     https://<host>/final_version/        ← 網頁
 *     php index.php                        ← 排程跑分類
 * ============================================================
 */

$CONFIG = require __DIR__ . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Taipei');

// =============================================================
//  共用類別（內聯）
// =============================================================

/** 簡易 Logger：寫 logs/YYYYMMDD.log + stdout（CLI）。 */
final class Logger
{
    private string $logFile;
    private bool   $echo;

    public function __construct(string $logDir, bool $echo = true)
    {
        $logDir = rtrim($logDir, "\\/");
        if (!is_dir($logDir) && !@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            throw new RuntimeException("無法建立 log 目錄：$logDir");
        }
        $this->logFile = $logDir . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        $this->echo    = $echo;
    }

    public function info(string $m): void  { $this->write('INFO',  $m); }
    public function warn(string $m): void  { $this->write('WARN',  $m); }
    public function error(string $m): void { $this->write('ERROR', $m); }

    /** 無 timestamp/level 的純文字行（用來印分隔線、統計表） */
    public function raw(string $line): void
    {
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);
        if ($this->echo) echo $line, PHP_EOL;
    }

    private function write(string $level, string $msg): void
    {
        $line = sprintf('[%s] [%-5s] %s', date('Y-m-d H:i:s'), $level, $msg);
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);
        if ($this->echo) {
            $stream = ($level === 'ERROR' || $level === 'WARN') ? STDERR : STDOUT;
            fwrite($stream, $line . PHP_EOL);
        }
    }

    public function file(): string { return $this->logFile; }
}

/** 每日 JSON 結果儲存：data/YYYYMMDD/<docId>.json */
final class JsonStore
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, "\\/");
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0777, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("無法建立資料目錄：{$this->baseDir}");
        }
    }

    public function dayDir(string $dateStr): string
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $dateStr;
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("無法建立資料夾：$dir");
        }
        return $dir;
    }

    /** 公文識別號 → 安全檔名 */
    public static function sanitize(string $name): string
    {
        $safe = preg_replace('/[\\\\\/:\*\?"<>\|\s]+/u', '_', $name);
        return $safe !== '' ? $safe : 'unknown';
    }

    public function pathFor(string $dateStr, string $docId): string
    {
        return $this->dayDir($dateStr) . DIRECTORY_SEPARATOR . self::sanitize($docId) . '.json';
    }

    public function isProcessed(string $dateStr, string $docId): bool
    {
        return file_exists($this->pathFor($dateStr, $docId));
    }

    /** 原子寫入（tmp + rename） */
    public function write(string $dateStr, string $docId, array $data): string
    {
        $path = $this->pathFor($dateStr, $docId);
        $tmp  = $path . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("write failed: $tmp");
        }
        if (!@rename($tmp, $path)) {
            // Windows 上 rename 若目標存在會失敗 → 先 unlink
            if (file_exists($path) && @unlink($path) && @rename($tmp, $path)) {
                return $path;
            }
            @unlink($tmp);
            throw new RuntimeException("rename failed: $tmp → $path");
        }
        return $path;
    }

    /** 讀某日全部 JSON（給網頁端用），依 predicted_at 由新到舊 */
    public function listForDate(string $dateStr): array
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $dateStr;
        if (!is_dir($dir)) return [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $records = [];
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            if ($raw === false) continue;
            $data = json_decode($raw, true);
            if (is_array($data)) $records[] = $data;
        }
        usort($records, fn($a, $b) =>
            strcmp((string)($b['predicted_at'] ?? ''), (string)($a['predicted_at'] ?? '')));
        return $records;
    }
}

/** Ollama 公文分類器：POST /api/chat */
final class AiClassifier
{
    private string $baseUrl;
    private string $model;
    private int    $timeout;
    /** @var string[] */
    private array  $targetUnits;

    public function __construct(array $aiConfig)
    {
        $this->baseUrl     = rtrim((string)$aiConfig['base_url'], '/');
        $this->model       = (string)$aiConfig['model'];
        $this->timeout     = (int)($aiConfig['timeout_sec'] ?? 90);
        $this->targetUnits = $aiConfig['target_units'] ?? [];
    }

    public function model(): string   { return $this->model; }
    public function baseUrl(): string { return $this->baseUrl; }

    /**
     * @return array{status:'ok'|'unknown'|'error', pred:string, raw:string, ms:float}
     */
    public function classify(array $doc): array
    {
        $kindLine = !empty($doc['來文字']) ? "來文字：{$doc['來文字']}\n" : '';
        $user = "來文機關：{$doc['來文機關']}\n"
              . $kindLine
              . "來文主旨：{$doc['來文主旨']}";

        $r = $this->httpPost($this->baseUrl . '/api/chat', [
            'model'    => $this->model,
            'stream'   => false,
            'messages' => [['role' => 'user', 'content' => $user]],
            'options'  => ['temperature' => 0, 'num_predict' => 12],
        ]);

        if ($r['code'] !== 200) {
            return [
                'status' => 'error',
                'pred'   => '',
                'raw'    => $r['err'] !== '' ? $r['err'] : ('HTTP ' . $r['code']),
                'ms'     => $r['ms'],
            ];
        }

        $data = json_decode((string)$r['body'], true);
        $raw  = trim((string)($data['message']['content'] ?? ''));

        foreach ($this->targetUnits as $u) {
            if (mb_strpos($raw, $u) !== false) {
                return ['status' => 'ok', 'pred' => $u, 'raw' => $raw, 'ms' => $r['ms']];
            }
        }
        return ['status' => 'unknown', 'pred' => '', 'raw' => $raw, 'ms' => $r['ms']];
    }

    /** 健康檢查：GET /api/tags 並確認 model 存在 */
    public function ping(): array
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
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
            return ['ok' => false, 'msg' => "AI 主機無法連線（HTTP $code, $err）"];
        }
        $tags  = json_decode((string)$body, true);
        $names = array_map(fn($m) => $m['name'] ?? '', $tags['models'] ?? []);
        if (!in_array($this->model, $names, true)) {
            return ['ok' => false, 'msg' => "AI 主機可連，但找不到模型 {$this->model}（現有：" . implode(', ', $names) . ")"];
        }
        return ['ok' => true, 'msg' => 'AI 主機與模型 OK'];
    }

    private function httpPost(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $t0   = microtime(true);
        $resp = curl_exec($ch);
        $ms   = (microtime(true) - $t0) * 1000;
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'ms'   => $ms,
            'code' => (int)$code,
            'body' => $resp === false ? '' : (string)$resp,
            'err'  => (string)$err,
        ];
    }
}

/**
 * 公文資料來源（MySQL）
 *  - INDATE 為民國年字串（varchar(9)，例：115/05/31），於 PHP 端產生
 *    對應字串清單做 IN (...) 比對，效率最佳（可走索引）
 */
final class DocRepository
{
    private array $cfg;
    private ?PDO  $pdo = null;

    public function __construct(array $dbConfig) { $this->cfg = $dbConfig; }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) return $this->pdo;
        $charset = (string)($this->cfg['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->cfg['host'], (int)$this->cfg['port'], $this->cfg['name'], $charset
        );
        $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $this->pdo;
    }

    private static function quoteId(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function toRocDate(DateTimeImmutable $d): string
    {
        $rocYear = (int)$d->format('Y') - 1911;
        return sprintf('%d/%s', $rocYear, $d->format('m/d'));
    }

    public static function rocDateRange(int $lookbackDays): array
    {
        $n = max(1, $lookbackDays);
        $now = new DateTimeImmutable('today');
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::toRocDate($now->modify("-$i day"));
        }
        return $out;
    }

    /**
     * @return array<int, array{doc_id:string,收文日期:string,來文機關:string,來文字:string,來文主旨:string}>
     */
    public function fetchRecent(int $lookbackDays, int $maxRows): array
    {
        $cols  = $this->cfg['columns'];
        $table = (string)$this->cfg['table'];

        $idCol      = self::quoteId($cols['id']);
        $dateCol    = self::quoteId($cols['date']);
        $orgCol     = self::quoteId($cols['org']);
        $subjectCol = self::quoteId($cols['subject']);
        $kindCol    = !empty($cols['kind']) ? self::quoteId($cols['kind']) : null;

        $tableQuoted = implode('.', array_map([self::class, 'quoteId'], explode('.', $table)));
        $selectKind  = $kindCol ? ", $kindCol AS `kind`" : ", '' AS `kind`";

        $rocDates = self::rocDateRange($lookbackDays);
        $placeholders = [];
        $params = [];
        foreach ($rocDates as $i => $v) {
            $key = ":d$i";
            $placeholders[] = $key;
            $params[$key]   = $v;
        }
        $inClause = implode(', ', $placeholders);

        $sql = "SELECT
                    $idCol      AS `doc_id`,
                    $dateCol    AS `date`,
                    $orgCol     AS `org`,
                    $subjectCol AS `subject`
                    $selectKind
                FROM $tableQuoted
                WHERE $dateCol IN ($inClause)
                ORDER BY $idCol DESC
                LIMIT :lim";

        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $maxRows, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $docId = trim((string)($r['doc_id'] ?? ''));
            if ($docId === '') continue;
            $out[] = [
                'doc_id'   => $docId,
                '收文日期' => trim((string)($r['date']    ?? '')),
                '來文機關' => trim((string)($r['org']     ?? '')),
                '來文字'   => trim((string)($r['kind']    ?? '')),
                '來文主旨' => trim((string)($r['subject'] ?? '')),
            ];
        }
        return $out;
    }
}

// =============================================================
//  入口：依 SAPI 切換 CLI / HTTP 模式
// =============================================================

if (PHP_SAPI === 'cli') {
    run_classify($CONFIG, $argv ?? []);
    exit;
}
render_web($CONFIG);

// =============================================================
//  CLI 模式：SQL → AI → JSON
// =============================================================

function run_classify(array $cfg, array $argv): void
{
    $logger = new Logger($cfg['paths']['log_dir']);
    $store  = new JsonStore($cfg['paths']['data_dir']);
    $ai     = new AiClassifier($cfg['ai']);
    $repo   = new DocRepository($cfg['db']);

    $limit = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 0;
    $today = date('Ymd');
    $t0    = microtime(true);

    $logger->raw('==========================================');
    $logger->raw(' 公文分辦 AI 推論執行（' . date('Y-m-d H:i:s') . '）');
    $logger->raw(' Model    : ' . $ai->model());
    $logger->raw(' Endpoint : ' . $ai->baseUrl());
    $logger->raw(' Lookback : ' . (int)$cfg['db']['lookback_days'] . ' 天');
    if ($limit > 0) $logger->raw(' Limit    : ' . $limit . ' 筆（測試用）');
    $logger->raw('==========================================');

    // 1. 確保當日資料夾
    $dayDir = $store->dayDir($today);
    $logger->info("資料夾：$dayDir");

    // 2. AI 健康檢查
    $ping = $ai->ping();
    if (!$ping['ok']) {
        $logger->error($ping['msg']);
        exit(1);
    }
    $logger->info($ping['msg']);

    // 3. 撈候選公文
    try {
        $docs = $repo->fetchRecent(
            (int)($cfg['db']['lookback_days'] ?? 1),
            (int)($cfg['db']['max_rows']      ?? 500)
        );
    } catch (Throwable $e) {
        $logger->error('SQL 撈取失敗：' . $e->getMessage());
        $logger->error('提示：確認 ssh_tunnel.bat 已啟動、config.php 連線資訊正確。');
        exit(1);
    }
    $logger->info('SQL 候選公文：' . count($docs) . ' 筆');

    // 4. 過濾已處理
    $pending = [];
    $skipped = 0;
    foreach ($docs as $d) {
        if ($store->isProcessed($today, $d['doc_id'])) { $skipped++; continue; }
        $pending[] = $d;
    }
    $logger->info("已處理略過：$skipped 筆；待處理：" . count($pending) . ' 筆');

    if ($limit > 0 && count($pending) > $limit) {
        $pending = array_slice($pending, 0, $limit);
        $logger->info('套用 limit，實際處理：' . count($pending) . ' 筆');
    }

    // 5. 逐筆呼叫 AI
    $stats  = ['total' => 0, 'ok' => 0, 'unknown' => 0, 'error' => 0];
    $msList = [];

    foreach ($pending as $i => $doc) {
        $stats['total']++;
        $docId = $doc['doc_id'];
        $fetchedAt = date('c');

        try {
            $r = $ai->classify($doc);
        } catch (Throwable $e) {
            $r = ['status' => 'error', 'pred' => '', 'raw' => $e->getMessage(), 'ms' => 0.0];
        }
        $msList[] = $r['ms'];

        $record = [
            'doc_id'       => $docId,
            'model'        => $ai->model(),
            'fetched_at'   => $fetchedAt,
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
    $elapsed = microtime(true) - $t0;
    $avgMs   = $msList ? array_sum($msList) / count($msList) : 0;

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
    $logger->raw(sprintf('  總耗時   ：%.1f 秒', $elapsed));
    $logger->raw(sprintf('  結果路徑 ：%s', $dayDir));
    $logger->raw(sprintf('  Log 檔   ：%s', $logger->file()));

    exit($stats['error'] > 0 ? 2 : 0);
}

// =============================================================
//  HTTP 模式：渲染結果頁
// =============================================================

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function render_web(array $cfg): void
{
    $store = new JsonStore($cfg['paths']['data_dir']);

    // ?date=YYYYMMDD 必須 8 位數字；預設今天
    $dateStr = (string)($_GET['date'] ?? date('Ymd'));
    if (!preg_match('/^\d{8}$/', $dateStr)) {
        $dateStr = date('Ymd');
    }

    $records = $store->listForDate($dateStr);

    $stats  = ['total' => count($records), 'ok' => 0, 'unknown' => 0, 'error' => 0];
    $byUnit = [];
    foreach ($records as $r) {
        $st = (string)($r['status'] ?? 'error');
        if (!isset($stats[$st])) $stats[$st] = 0;
        $stats[$st]++;
        $u = (string)($r['prediction']['ai_predicted_unit'] ?? '');
        if ($u !== '') $byUnit[$u] = ($byUnit[$u] ?? 0) + 1;
    }
    arsort($byUnit);

    $autoRefresh = (int)($cfg['web']['auto_refresh'] ?? 0);
    $title       = (string)($cfg['web']['title'] ?? '今日公文 AI 分辦結果');

    // 給前端 JS 用的精簡資料
    $jsRows = [];
    foreach ($records as $r) {
        $jsRows[] = [
            'doc_id'  => (string)($r['doc_id'] ?? ''),
            'date'    => (string)($r['input']['收文日期'] ?? ''),
            'org'     => (string)($r['input']['來文機關'] ?? ''),
            'kind'    => (string)($r['input']['來文字']   ?? ''),
            'subject' => (string)($r['input']['來文主旨'] ?? ''),
            'unit'    => (string)($r['prediction']['ai_predicted_unit'] ?? ''),
            'raw'     => (string)($r['prediction']['raw_response']      ?? ''),
            'status'  => (string)($r['status'] ?? ''),
            'ms'      => (float)($r['elapsed_ms'] ?? 0),
            'pred_at' => (string)($r['predicted_at'] ?? ''),
        ];
    }

    $displayDate = substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?= h($title) ?> - <?= h($displayDate) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($autoRefresh > 0): ?>
<meta http-equiv="refresh" content="<?= (int)$autoRefresh ?>">
<?php endif; ?>
<style>
:root {
  --bg:#f4f6fa; --fg:#1f2937; --muted:#6b7280; --card:#fff; --border:#e5e7eb;
  --accent:#2563eb; --ok:#10b981; --warn:#f59e0b; --err:#ef4444;
  --shadow:0 1px 2px rgba(0,0,0,.04),0 4px 12px rgba(0,0,0,.04);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);
  font-family:"Noto Sans TC","Microsoft JhengHei","Segoe UI",system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.55;}
.topbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;
  gap:12px;padding:16px 24px;background:#fff;border-bottom:1px solid var(--border);}
.topbar h1{margin:0;font-size:20px;font-weight:700;}
.datepick{display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.datepick-form label{font-size:13px;color:var(--muted);}
.datepick-form input[type=date]{margin-left:6px;padding:6px 10px;border:1px solid var(--border);
  border-radius:6px;font-size:14px;}
.ts{color:var(--muted);font-size:12px;}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:16px 24px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 18px;box-shadow:var(--shadow);}
.card-label{color:var(--muted);font-size:12px;letter-spacing:.5px;}
.card-num{font-size:28px;font-weight:700;margin-top:4px;}
.card.ok .card-num{color:var(--ok);}
.card.warn .card-num{color:var(--warn);}
.card.err .card-num{color:var(--err);}
.bypanel{padding:0 24px 4px;}
.bypanel h2{font-size:14px;color:var(--muted);margin:12px 0 8px;font-weight:600;}
.chips{display:flex;flex-wrap:wrap;gap:8px;}
.chip{cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--fg);
  padding:6px 12px;border-radius:999px;font-size:13px;transition:all .15s;}
.chip:hover{border-color:var(--accent);color:var(--accent);}
.chip.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.chip.active .chip-n{background:rgba(255,255,255,.25);color:#fff;}
.chip-n{display:inline-block;margin-left:4px;padding:1px 8px;border-radius:999px;
  background:var(--bg);color:var(--muted);font-size:12px;font-weight:600;}
.tools{display:flex;align-items:center;gap:12px;padding:14px 24px;flex-wrap:wrap;}
.tools input[type=search]{flex:1 1 320px;min-width:240px;padding:8px 12px;font-size:14px;
  border:1px solid var(--border);border-radius:8px;background:#fff;}
.tools input[type=search]:focus{outline:none;border-color:var(--accent);}
.tools select{padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#fff;}
.count{color:var(--muted);font-size:13px;margin-left:auto;}
main{padding:0 24px 32px;}
table#grid{width:100%;border-collapse:separate;border-spacing:0;background:#fff;
  border:1px solid var(--border);border-radius:10px;overflow:hidden;box-shadow:var(--shadow);}
#grid th,#grid td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top;}
#grid th{background:#f9fafb;font-weight:600;font-size:13px;color:var(--muted);position:sticky;top:0;z-index:1;}
#grid tr:last-child td{border-bottom:none;}
#grid tr:hover td{background:#fafbff;}
#grid td.docid{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;white-space:nowrap;}
#grid td.subject{max-width:480px;}
#grid td.ms{text-align:right;color:var(--muted);white-space:nowrap;}
.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;}
.badge.ok{background:rgba(16,185,129,.12);color:#047857;}
.badge.warn{background:rgba(245,158,11,.15);color:#92400e;}
.badge.err{background:rgba(239,68,68,.12);color:#b91c1c;}
.badge.unit{background:rgba(37,99,235,.10);color:#1d4ed8;}
.badge.muted{background:#f3f4f6;color:var(--muted);}
.empty{margin:32px 24px;padding:32px;border-radius:10px;background:#fff;
  border:1px dashed var(--border);color:var(--muted);text-align:center;}
.empty code{background:#f3f4f6;padding:1px 6px;border-radius:4px;font-size:13px;}
@media(max-width:720px){
  .cards{grid-template-columns:repeat(2,1fr);}
  .topbar h1{font-size:17px;}
  main{padding:0 12px 24px;}
  .cards,.bypanel,.tools{padding-left:12px;padding-right:12px;}
  #grid td.subject{max-width:220px;}
}
</style>
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
    請確認：(1) <code>ssh_tunnel.bat</code> 在跑；(2) Windows 工作排程器已設定每 5 分鐘觸發 <code>run_classify.bat</code>；<br>
    或在本資料夾手動執行 <code>php index.php</code> 觀察錯誤。
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
        <th>AI分辦建議</th>
        <th>AI判斷狀態</th>
        <th>AI分辨所費時間</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
<?php endif; ?>
</main>

<script>
window.__ROWS__ = <?= json_encode($jsRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function () {
  const rows = window.__ROWS__ || [];
  const tbody = document.querySelector('#grid tbody');
  if (!tbody) return;

  const q = document.getElementById('q');
  const statusFilter = document.getElementById('statusFilter');
  const countEl = document.getElementById('count');
  const chips = Array.from(document.querySelectorAll('.chip'));

  let kw = '', stat = '', unit = '';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function statusBadge(s) {
    if (s === 'ok')      return '<span class="badge ok">成功</span>';
    if (s === 'unknown') return '<span class="badge warn">未知</span>';
    if (s === 'error')   return '<span class="badge err">錯誤</span>';
    return '<span class="badge muted">' + esc(s) + '</span>';
  }
  function unitBadge(u, raw) {
    if (u) return '<span class="badge unit">' + esc(u) + '</span>';
    if (raw) return '<span class="badge muted" title="' + esc(raw) + '">原始：' + esc(raw.slice(0, 12)) + '</span>';
    return '<span class="badge muted">-</span>';
  }
  function render() {
    const kwLower = kw.trim().toLowerCase();
    const out = [];
    let shown = 0;
    rows.forEach(r => {
      if (stat && r.status !== stat) return;
      if (unit && r.unit !== unit) return;
      if (kwLower) {
        const hay = (r.doc_id + ' ' + r.org + ' ' + r.subject + ' ' + r.kind).toLowerCase();
        if (hay.indexOf(kwLower) < 0) return;
      }
      shown++;
      out.push(
        '<tr>' +
          '<td>' + shown + '</td>' +
          '<td class="docid">' + esc(r.doc_id) + '</td>' +
          '<td>' + esc(r.date) + '</td>' +
          '<td>' + esc(r.org) + '</td>' +
          '<td class="subject">' + esc(r.subject) + '</td>' +
          '<td>' + unitBadge(r.unit, r.raw) + '</td>' +
          '<td>' + statusBadge(r.status) + '</td>' +
          '<td class="ms">' + (r.ms ? r.ms.toFixed(0) + ' ms' : '-') + '</td>' +
        '</tr>'
      );
    });
    tbody.innerHTML = out.join('') || '<tr><td colspan="8" style="text-align:center;color:#6b7280;padding:24px">無符合條件的資料</td></tr>';
    if (countEl) countEl.textContent = '顯示 ' + shown + ' / ' + rows.length + ' 筆';
  }
  if (q) q.addEventListener('input', e => { kw = e.target.value; render(); });
  if (statusFilter) statusFilter.addEventListener('change', e => { stat = e.target.value; render(); });
  chips.forEach(c => {
    c.addEventListener('click', () => {
      chips.forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      unit = c.dataset.unit || '';
      render();
    });
  });
  render();
})();
</script>
</body>
</html>
<?php
}
