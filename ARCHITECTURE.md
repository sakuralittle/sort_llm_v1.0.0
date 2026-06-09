# 公文 AI 分辦系統 — v2 架構規劃

> 本文件是 v2 版本（取代 `final_version/`）的系統架構單一來源。所有後續開發應以此為準；
> 若有變更，請直接修改本文件。

---

## 0. 文件目的與使用方式

- **讀者**：本專案未來的開發者（含未來的你自己）
- **內容**：v2 整體架構、模組職責、介面契約、開發路線圖
- **不在本文件範圍**：每個函式的實作細節（留給程式碼註解）、完整 SQL DDL（請見 `migrations/`）

---

## 1. 為何要重構

### 1.1 現況（`final_version/`）的問題

| 問題 | 說明 | 影響 |
|---|---|---|
| **雙模式擠在同一個 `index.php`（894 行）** | 用 `PHP_SAPI === 'cli'` 區分 CLI（排程）與 HTTP（網頁） | 部署耦合、改 A 壞 B、Apache 載入 DB 連線重類別 |
| **DB 密碼放在 web root** | `config.php` 含密碼，靠 `.htaccess` 擋 | 設定一閃失即外洩；不符合最小權限 |
| **JSON 檔當資料庫** | `data/YYYYMMDD/<INNO>.json` 一筆一檔 | 查詢全資料夾掃描；無條件查詢；無 transaction；不可統計趨勢 |
| **沒有 API 層** | 純 server-side render（PHP echo HTML） | 切換日期、篩選都要整頁刷新；無法做 AJAX 互動；無法給手機 App |
| **重複偵測效率差** | `isProcessedRecent()` 掃 N 天資料夾 | 換成 DB 一個 `SELECT` 即可 |
| **時間欄位混亂** | 民國年字串 `115/05/31` 散落各處轉換 | 應該存西元 `DATE` 型別，民國年只在顯示時轉 |
| **缺測試、缺 migration** | 全靠手動驗證 | 改動風險高 |

### 1.2 重構目標

1. **單一職責**：把「定時抓 DB → AI 分類」與「網頁查詢」拆成兩個獨立專案
2. **資料中心化**：用 MySQL 取代 JSON 檔，作為兩個專案唯一耦合點
3. **前後端分離**：網頁專案後端只回 JSON，前端用 AJAX 動態更新
4. **設定隔離**：DB 密碼絕不放在 web root；兩個專案各自的設定檔互不依賴
5. **可漸進擴充**：未來要加「人工覆核」「統計圖表」「行動 App」都不用重做基礎

---

## 2. 新架構總覽

### 2.1 兩個專案

```
 內網 MySQL (192.168.6.58)            本地 MySQL／MariaDB              使用者瀏覽器
        │                                    ▲ ▲                            │
        │  SSH tunnel                        │ │                            │ HTTPS
        ▼                                    │ │                            ▼
 ┌──────────────────────┐  寫入分類結果      │ │  讀分類結果   ┌────────────────────────┐
 │ doc-classifier-worker│ ───────────────────┘ └─────────────│  doc-classifier-web    │
 │   (專案 A：背景服務)  │                                     │  (專案 B：查詢網站)     │
 │   PHP CLI            │                                     │  Apache + PHP + Alpine │
 │   每 5 分鐘觸發一次    │                                     │  前後端分離             │
 └──────────┬───────────┘                                     └────────────────────────┘
            │ HTTP
            ▼
   Ollama AI (192.168.1.237:11434)
```

### 2.2 兩個專案的唯一耦合點

**只有一張資料庫表 `classification_results`**。Worker 寫、Web 讀。彼此不直接呼叫對方的程式碼。

這意味著：
- Worker 故障 → Web 仍可顯示既有資料
- Web 改版 → Worker 完全不受影響
- 兩個專案可以分別部署到不同機器（未來 Web 可上前端伺服器、Worker 留內網）

### 2.3 技術選型

| 層 | 選擇 | 理由 |
|---|---|---|
| 兩專案語言 | PHP 8.1+ | 與現況一致；公司環境已具備 |
| 結果資料庫 | 獨立 MySQL／MariaDB（**非 XAMPP 內建**） | 與 Apache 解耦，未來搬機器有彈性 |
| Worker 排程 | Windows Task Scheduler | 與現況一致 |
| Web 後端 | **多檔案 endpoint（無框架）** | API 僅 3-5 條，框架是負擔；風格與現有程式一致 |
| Web 前端 | **Alpine.js（CDN 版）** | 響應式語法、零 build step、學習曲線平緩 |
| 套件管理 | Composer（僅 Worker 用，若需第三方套件） | Web 端完全不引入第三方 |

---

## 3. 資料庫設計

### 3.1 主表：`classification_results`

```sql
CREATE TABLE classification_results (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- 公文識別
  doc_id          VARCHAR(64)  NOT NULL UNIQUE,         -- 對應 IFTDC_INDCM.INNO
  doc_date        DATE         NOT NULL,                -- 西元日期，從民國年轉換
  doc_date_roc    VARCHAR(12)  NOT NULL,                -- 原民國年字串（如 "115/05/31"）保留供顯示

  -- 公文內容（從來源 DB 撈）
  org             VARCHAR(255) NOT NULL,                -- 來文機關
  kind            VARCHAR(64)  NOT NULL DEFAULT '',     -- 來文字（可空）
  subject         TEXT         NOT NULL,                -- 主旨

  -- AI 分類結果
  predicted_unit  VARCHAR(64)  NOT NULL DEFAULT '',     -- 命中白名單後的組室
  raw_response    TEXT         NOT NULL,                -- AI 原始輸出（debug 用）
  status          ENUM('ok','unknown','error') NOT NULL,
  model           VARCHAR(128) NOT NULL,                -- 用哪個模型分的
  elapsed_ms      INT UNSIGNED NOT NULL DEFAULT 0,      -- 該筆 AI 耗時

  -- 時間戳
  fetched_at      DATETIME     NOT NULL,                -- Worker 撈到此筆的時間
  predicted_at    DATETIME     NOT NULL,                -- AI 完成預測的時間

  -- 預留：人工覆核（未來功能）
  reviewed_unit   VARCHAR(64)  NULL,
  reviewed_by     VARCHAR(64)  NULL,
  reviewed_at     DATETIME     NULL,

  -- 列稽核
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_doc_date (doc_date),
  INDEX idx_status (status),
  INDEX idx_predicted_unit (predicted_unit),
  INDEX idx_predicted_at (predicted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 設計重點

- **`doc_id` 設 UNIQUE**：Worker 用 `INSERT ... ON DUPLICATE KEY UPDATE` 寫入，自然處理重跑。
- **`doc_date` 用西元 DATE**：方便範圍查詢、排序，避免跨月字串比較錯誤（如 `114/9/1 < 114/10/1` 字串比較會錯）。原民國年字串保留在 `doc_date_roc` 供顯示。
- **`raw_response` 留著**：AI debug、訓練語料蒐集都需要。
- **預留 `reviewed_*` 欄位**：之後做人工覆核功能不必再改 schema。
- **索引選擇**：覆蓋最常見的查詢（依日期範圍 / 依狀態 / 依組室）。

### 3.3 Migration 管理

- 路徑：`doc-classifier-worker/migrations/`
- 命名：`001_init.sql`、`002_add_review.sql`…
- 工具：手動執行（規模小到不需要 phinx）。Worker 的 README 內會列出每個 migration 該何時跑。

---

## 4. 專案 A：`doc-classifier-worker`（背景分類服務）

### 4.1 職責

1. 透過 SSH tunnel 連到內網 MySQL，撈最近 N 天的公文
2. 過濾掉本地 DB 已存在的（`SELECT 1 FROM classification_results WHERE doc_id=?`）
3. 對未處理的逐筆呼叫 Ollama AI
4. 把結果 upsert 到本地 `classification_results`
5. 寫詳盡的 log 到 `logs/YYYYMMDD.log`

### 4.2 目錄結構

```
doc-classifier-worker/
├── bin/
│   └── run_classify.php          ← CLI 入口（給 .bat 呼叫）
├── src/
│   ├── Config.php                ← 載入 + 驗證設定
│   ├── Logger.php                ← 沿用現有 final_version 的 Logger
│   ├── Db/
│   │   ├── SourceRepository.php  ← 讀內網 MySQL（IFTDC_INDCM）
│   │   └── ResultRepository.php  ← 寫本地 MySQL（classification_results）
│   ├── Ai/
│   │   └── OllamaClassifier.php  ← 沿用現有 AiClassifier
│   ├── Pipeline/
│   │   └── ClassifyJob.php       ← 主流程編排
│   └── Util/
│       └── RocDate.php           ← 民國年 ↔ 西元 互轉
├── migrations/
│   ├── 001_init.sql
│   └── README.md                 ← 列出每個 migration 何時跑
├── config/
│   ├── config.example.php        ← 入版控
│   └── config.php                ← gitignored，含密碼
├── scripts/
│   ├── ssh_tunnel.bat            ← 沿用
│   ├── run_classify.bat          ← 沿用，但呼叫 bin/run_classify.php
│   ├── register_scheduler.bat    ← 沿用
│   └── unregister_scheduler.bat  ← 沿用
├── logs/                         ← gitignored
├── composer.json                 ← 若需第三方套件（如 Monolog）才用
├── .gitignore
└── README.md                     ← 安裝、排程設定、故障排除
```

### 4.3 各模組職責

#### `src/Config.php`
- 載入 `config/config.php` 並驗證必填欄位
- 提供型別安全的 getter（避免散落 `$cfg['db']['host'] ?? ''`）

#### `src/Db/SourceRepository.php`（讀內網）
- 從 v1 的 `DocRepository` 搬過來
- 唯一職責：`fetchRecent(int $lookbackDays, int $maxRows): array`
- 連線資訊：透過 SSH tunnel `127.0.0.1:13306`

#### `src/Db/ResultRepository.php`（寫本地）
- 新模組
- 主要方法：
  - `existsByDocId(string $docId): bool`
  - `upsert(array $record): void`（用 `INSERT ... ON DUPLICATE KEY UPDATE`）
  - `bulkExists(array $docIds): array<string,true>`（一次查多筆，給 Pipeline 篩選用）

#### `src/Ai/OllamaClassifier.php`
- 從 v1 的 `AiClassifier` 搬過來，介面不變

#### `src/Pipeline/ClassifyJob.php`
- 主流程，相當於 v1 的 `run_classify()` 函式：
  ```
  1. 健康檢查（AI ping）
  2. SourceRepository::fetchRecent()
  3. ResultRepository::bulkExists() 過濾已處理
  4. 逐筆 OllamaClassifier::classify()
  5. ResultRepository::upsert()
  6. 統計 log
  ```

#### `bin/run_classify.php`
- CLI 入口：載入 `Config`，組裝以上元件，呼叫 `ClassifyJob::run()`
- 約 30 行內

### 4.4 設定檔範例（`config/config.example.php`）

```php
return [
    'timezone' => 'Asia/Taipei',

    // 來源 DB（透過 SSH tunnel）
    'source_db' => [
        'host' => '127.0.0.1', 'port' => 13306,
        'name' => 'EXFODBS',   'user' => 'exfoselect', 'pass' => '***',
        'table' => 'IFTDC_INDCM',
        'columns' => [/* 同 v1 */],
        'lookback_days' => 5, 'max_rows' => 500,
    ],

    // 結果 DB（本地獨立 MySQL）
    'result_db' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'name' => 'doc_classify', 'user' => 'classify_writer', 'pass' => '***',
    ],

    'ai' => [/* 同 v1 */],

    'paths' => [
        'log_dir' => __DIR__ . '/../logs',
    ],
];
```

---

## 5. 專案 B：`doc-classifier-web`（查詢網站）

### 5.1 職責

1. 提供使用者一個網頁，顯示最近 N 天的公文 AI 分辦結果
2. 支援單日 / 範圍切換、組室篩選、狀態篩選、關鍵字搜尋
3. 切換條件不刷新整頁，純 AJAX
4. 顯示統計卡片（總筆數、ok / unknown / error 數）
5. 顯示各組室分辦數

### 5.2 目錄結構

```
doc-classifier-web/
├── backend/
│   ├── public/                   ← Apache DocumentRoot 指這裡
│   │   ├── index.php             ← 網頁殼（HTML 骨架）
│   │   ├── .htaccess             ← 安全設定
│   │   └── api/
│   │       ├── _bootstrap.php    ← 共用：載 config、設 JSON header、錯誤處理
│   │       ├── docs.php          ← GET /api/docs
│   │       └── stats.php         ← GET /api/stats
│   ├── src/
│   │   ├── Config.php            ← 與 Worker 同設計
│   │   ├── Repositories/
│   │   │   └── DocRepository.php ← 讀本地 MySQL（與 Worker 的 ResultRepository 不共用）
│   │   └── Util/
│   │       └── RocDate.php       ← 顯示時用
│   ├── config/                   ← 在 public/ 之外，Apache 訪問不到
│   │   ├── config.example.php
│   │   └── config.php            ← gitignored
│   ├── composer.json             ← 可選
│   └── README.md
├── frontend/                     ← 純靜態檔案，可直接放在 public/
│   ├── assets/
│   │   ├── css/app.css
│   │   └── js/app.js             ← Alpine.js 元件邏輯
│   └── （注意：HTML 由 backend/public/index.php 渲染，不是獨立檔）
├── .gitignore
└── README.md
```

### 5.3 安全性設計重點

- **`config/` 目錄在 `public/` 之外**：Apache 訪問不到，不需要 `.htaccess` 擋；DB 密碼從架構上就無法外洩
- **`public/` 是唯一的 DocumentRoot**：Apache vhost 應指向 `backend/public/`，不是專案根目錄
- **`api/_bootstrap.php`**：所有 API 檔案共用，負責設 `Content-Type: application/json`、CORS（如有需要）、try/catch 統一錯誤回應

### 5.4 多檔案 endpoint 寫法範例

#### `backend/public/api/_bootstrap.php`

```php
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../src/autoload.php';   // 簡易 autoload，或用 composer 的
$config = require __DIR__ . '/../../config/config.php';

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

return $config;
```

#### `backend/public/api/docs.php`

```php
<?php
$config = require __DIR__ . '/_bootstrap.php';

$repo = new App\Repositories\DocRepository($config['result_db']);

$from   = $_GET['from']   ?? null;       // YYYY-MM-DD
$to     = $_GET['to']     ?? null;
$date   = $_GET['date']   ?? null;       // 單日，與 from/to 互斥
$unit   = $_GET['unit']   ?? null;
$status = $_GET['status'] ?? null;
$q      = $_GET['q']      ?? null;
$limit  = min(500, max(1, (int)($_GET['limit'] ?? 200)));

$rows = $repo->query([
    'from' => $from, 'to' => $to, 'date' => $date,
    'unit' => $unit, 'status' => $status, 'q' => $q,
    'limit' => $limit,
]);

echo json_encode(['rows' => $rows, 'count' => count($rows)],
                 JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

每個 API 就是一個檔案，職責清楚，無路由配置。

---

## 6. API 規格

### 6.1 `GET /api/docs`

#### Query 參數

| 名稱 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `date` | `YYYY-MM-DD` | 否 | 單日查詢，與 `from`/`to` 互斥 |
| `from` | `YYYY-MM-DD` | 否 | 範圍查詢起日（含） |
| `to` | `YYYY-MM-DD` | 否 | 範圍查詢迄日（含） |
| `unit` | string | 否 | 組室篩選（精確比對） |
| `status` | `ok\|unknown\|error` | 否 | 狀態篩選 |
| `q` | string | 否 | 關鍵字（搜尋 `doc_id`、`org`、`subject`） |
| `limit` | int | 否 | 預設 200、最大 500 |

#### 預設行為
- 三個日期參數都沒給 → 回傳最近 5 天
- 排序：`doc_date DESC`、`predicted_at DESC`、`doc_id DESC`

#### Response

```json
{
  "rows": [
    {
      "doc_id": "1140531001",
      "doc_date": "2026-05-31",
      "doc_date_roc": "115/05/31",
      "org": "○○機關",
      "kind": "○字",
      "subject": "關於……",
      "predicted_unit": "主計室",
      "raw_response": "主計室",
      "status": "ok",
      "model": "gw-classify:finetune",
      "elapsed_ms": 320,
      "predicted_at": "2026-05-31T15:23:01+08:00"
    }
  ],
  "count": 1
}
```

### 6.2 `GET /api/stats`

#### Query 參數
- 同 `/api/docs` 的 `date`、`from`、`to`（其他過濾不適用）

#### Response

```json
{
  "totals": { "total": 120, "ok": 95, "unknown": 15, "error": 10 },
  "by_unit": [
    { "unit": "主計室", "count": 30 },
    { "unit": "人事室", "count": 25 }
  ],
  "ai_avg_ms": 420
}
```

### 6.3 預留：`POST /api/docs/{doc_id}/review`

未來功能，現階段不實作。Schema 已預留欄位。

### 6.4 統一錯誤格式

```json
{ "error": "錯誤訊息" }
```
HTTP status 用 4xx（client 錯）/ 5xx（server 錯）。

---

## 7. 前端設計（Alpine.js）

### 7.1 設計原則

- **單頁面、零路由**：URL 不變，狀態存在 Alpine 元件裡
- **狀態驅動**：所有畫面更新都透過修改 `state.*`，Alpine 自動 reactive
- **API 抽到一個 module**：方便日後改 endpoint

### 7.2 HTML 骨架（`backend/public/index.php`）

```php
<?php $config = require __DIR__ . '/../config/config.php'; ?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title>公文 AI 分辦結果</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body x-data="docApp()" x-init="init()">

  <header>...</header>

  <!-- 篩選列 -->
  <section class="tools">
    <input type="date" x-model="filters.date" @change="reload()">
    <select x-model="filters.unit" @change="reload()">...</select>
    <select x-model="filters.status" @change="reload()">...</select>
    <input type="search" x-model.debounce.300ms="filters.q" @input="reload()">
  </section>

  <!-- 統計卡片 -->
  <section class="cards">
    <div class="card"><span x-text="stats.total"></span></div>
    <div class="card ok"><span x-text="stats.ok"></span></div>
    <!-- ... -->
  </section>

  <!-- 組室 chips -->
  <section class="chips">
    <template x-for="u in stats.byUnit" :key="u.unit">
      <button class="chip" :class="{active: filters.unit === u.unit}"
              @click="setUnit(u.unit)">
        <span x-text="u.unit"></span>
        <span class="chip-n" x-text="u.count"></span>
      </button>
    </template>
  </section>

  <!-- 表格 -->
  <main>
    <table>
      <thead><tr><th>#</th><th>公文代號</th>...</tr></thead>
      <tbody>
        <template x-for="(r, i) in rows" :key="r.doc_id">
          <tr>
            <td x-text="i+1"></td>
            <td x-text="r.doc_id"></td>
            <td x-text="r.doc_date_roc"></td>
            ...
          </tr>
        </template>
      </tbody>
    </table>
    <p x-show="loading">載入中…</p>
    <p x-show="!loading && rows.length === 0">無資料</p>
  </main>

  <script src="/assets/js/app.js"></script>
</body>
</html>
```

### 7.3 JS 模組（`frontend/assets/js/app.js`）

```js
// API wrapper（之後要改網址只動這裡）
const api = {
  async docs(filters)  { return this._get('/api/docs',  filters); },
  async stats(filters) { return this._get('/api/stats', filters); },
  async _get(path, params) {
    const url = new URL(path, location.origin);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== null && v !== '') url.searchParams.set(k, v);
    });
    const res = await fetch(url);
    if (!res.ok) throw new Error((await res.json()).error || 'API error');
    return res.json();
  }
};

// Alpine 元件
function docApp() {
  return {
    filters: { date: '', unit: '', status: '', q: '' },
    rows: [],
    stats: { total: 0, ok: 0, unknown: 0, error: 0, byUnit: [] },
    loading: false,

    async init()    { await this.reload(); },
    setUnit(u)      { this.filters.unit = (this.filters.unit === u ? '' : u); this.reload(); },

    async reload() {
      this.loading = true;
      try {
        const [docsRes, statsRes] = await Promise.all([
          api.docs(this.filters),
          api.stats({ date: this.filters.date }),
        ]);
        this.rows = docsRes.rows;
        this.stats = { ...statsRes.totals, byUnit: statsRes.by_unit };
      } catch (e) {
        alert(e.message);
      } finally {
        this.loading = false;
      }
    },
  };
}
```

整個前端互動只需要這兩個檔案。

---

## 8. 設定檔與秘密管理

| 內容 | Worker | Web |
|---|---|---|
| **內網 MySQL 帳密** | ✅ 必要 | ❌ 不應該有 |
| **本地 MySQL 帳密** | ✅ 寫權限帳號 | ✅ 唯讀帳號（**不同帳號**） |
| **Ollama 位址** | ✅ 必要 | ❌ 不應該有 |
| **網頁顯示參數** | ❌ 不應該有 | ✅ 必要 |

### 重要規則

1. **`config.php` 一律 gitignore**，版控只放 `config.example.php`
2. **建立兩個 DB 帳號**：`classify_writer`（Worker 用，有寫權限）、`classify_reader`（Web 用，只能 SELECT）
3. **Web 的 `config/` 必須在 `public/` 之外**，不靠 `.htaccess` 擋

---

## 9. 部署與排程

### 9.1 Worker 部署步驟

1. 安裝獨立 MySQL／MariaDB 服務（例：MariaDB 11 LTS）
2. 建立資料庫與兩個帳號：
   ```sql
   CREATE DATABASE doc_classify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'classify_writer'@'localhost' IDENTIFIED BY '...';
   CREATE USER 'classify_reader'@'localhost' IDENTIFIED BY '...';
   GRANT SELECT, INSERT, UPDATE ON doc_classify.* TO 'classify_writer'@'localhost';
   GRANT SELECT ON doc_classify.* TO 'classify_reader'@'localhost';
   ```
3. 跑 `migrations/001_init.sql` 建表
4. 複製 `config/config.example.php` 為 `config.php`，填密碼
5. 啟動 SSH tunnel：`scripts/ssh_tunnel.bat`
6. 註冊排程：`scripts/register_scheduler.bat`（每 5 分鐘觸發 `run_classify.bat`）

### 9.2 Web 部署步驟

1. 在 Apache 開一個 vhost，**DocumentRoot 設為 `doc-classifier-web/backend/public/`**
2. 設定 `config/config.php`（使用 `classify_reader` 帳號）
3. 確認 Apache 啟用 `mod_rewrite`、HTTPS

### 9.3 Apache vhost 範例

```apache
<VirtualHost *:443>
    ServerName doc-classify.example.local
    DocumentRoot "C:/path/to/doc-classifier-web/backend/public"

    <Directory "C:/path/to/doc-classifier-web/backend/public">
        Options -Indexes -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile      "..."
    SSLCertificateKeyFile   "..."
</VirtualHost>
```

---

## 10. 開發路線圖

建議分四個階段，每階段結束都有可運作的版本，可隨時暫停。

### 階段 1：建立資料庫與 Worker MVP（最重要）

**目標**：能跑出 v1 的同等功能，但結果寫入 MySQL 而非 JSON。

- [ ] 安裝獨立 MySQL／MariaDB
- [ ] 寫 `migrations/001_init.sql` 並執行
- [ ] 建立 `doc-classifier-worker/` 目錄、移植現有類別
- [ ] 新寫 `ResultRepository` 的 upsert 邏輯
- [ ] 改造 `ClassifyJob`，把寫 JSON 改成寫 DB
- [ ] 註冊排程，觀察 24 小時

**完成後**：v1 的 `final_version/` 可以保留為備份，但實際靠 v2 Worker 跑。

### 階段 2：Web 後端 API

**目標**：可用 `curl` 拿到 JSON 資料。

- [ ] 建立 `doc-classifier-web/` 目錄
- [ ] 寫 `DocRepository::query()` 與 `stats()` 方法
- [ ] 寫 `api/docs.php`、`api/stats.php`、`api/_bootstrap.php`
- [ ] 設定 Apache vhost，DocumentRoot 指 `public/`
- [ ] 用 `curl` 或 Postman 驗證每條 API

**完成後**：API 已可運作，但還沒有網頁。

### 階段 3：Web 前端

**目標**：使用者可以開瀏覽器查資料、切日期、篩選、搜尋。

- [ ] 寫 `index.php`（HTML 骨架）
- [ ] 寫 `assets/css/app.css`（可從 v1 內聯 CSS 移植）
- [ ] 寫 `assets/js/app.js`（Alpine 元件）
- [ ] 手動測試：日期切換、組室篩選、狀態篩選、關鍵字搜尋
- [ ] 加自動重整（沿用 v1 的 60 秒 refresh，或改成 SSE/輪詢更精緻）

**完成後**：v2 已可取代 v1 的 `final_version/`，正式上線。

### 階段 4：擴充功能（可選）

- [ ] 統計圖表（最近 30 天每組室趨勢、AI 耗時走勢）
- [ ] 人工覆核功能（`POST /api/docs/{id}/review`）
- [ ] 匯出 Excel／CSV
- [ ] 多人帳號與權限（如有需要）

---

## 11. 附錄：關鍵設計決策紀錄（ADR）

### ADR-001：為何不引入後端框架（Slim、Laravel）
- **背景**：API 數量預計 3-5 條
- **決定**：用「多檔案 endpoint」+ 共用 `_bootstrap.php`
- **理由**：框架的學習成本、依賴管理對此規模是負擔；風格與現有 PHP 程式一致；未來要遷移到 Slim 也容易（每個檔搬進 Controller）
- **取消條件**：當 API 增至 10+ 條，或需要 middleware（驗證、限流）時，遷移到 Slim

### ADR-002：為何前端用 Alpine.js 而非 Vanilla JS / Vue
- **Vanilla JS**：手動操作 DOM、表格列重建容易出 bug
- **Vue 3**：學習曲線較陡，元件架構對小頁面是過度設計
- **Alpine.js**：在 HTML 屬性上直接寫響應式語法，15KB CDN，零 build step，新手友善
- **取消條件**：頁面複雜到需要路由、狀態管理（如 Pinia）時，遷移到 Vue 3 + Vite

### ADR-003：為何用獨立 MySQL 而非 SQLite
- **SQLite 優點**：零部署
- **MySQL 優點**：未來可橫向擴展、與內網 DB 技術一致、支援更多並發
- **決定**：MySQL，避免將來資料量大或加多人協作功能時要再遷移

### ADR-004：為何捨棄舊 JSON 資料而非 migration
- 資料量不大、價值有限
- Worker `lookback_days=5` 一啟動就會把最近的補上
- 簡化導入過程

### ADR-005：為何 Web 與 Worker 不共用程式碼
- 兩專案實際共用的只有「DB schema 定義」這個契約
- 共用 PHP 類別會引入耦合：改 A 要回歸測 B
- 各自實作 `Repository` 反而清楚（Web 只查不寫、Worker 寫不查同一份資料）

---

## 12. 變更紀錄

| 日期 | 版本 | 變更 | 作者 |
|---|---|---|---|
| 2026-06-08 | 0.1 | 初版規劃 | — |

---

**結語**：本文件是 v2 開發的契約。實作過程中遇到偏離本文件的決策（無論大小），請回來更新本文件，再進行程式碼修改，避免文件與實作脫節。
