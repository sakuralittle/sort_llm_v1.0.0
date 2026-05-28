# 公文分辦系統 — src/

中繼主機端的 AI 公文分辦執行檔與內網瀏覽頁。  
全部以 **PHP（同步寫法）** 實作；資料來源走 **SSH tunnel → SQL Server**；排程由 **Windows 工作排程器** 觸發。

---

## 目錄結構

```
src/
├── bin/
│   ├── run_classify.php       ← 第一支主程式（撈 SQL → 呼叫 AI → 寫 JSON）
│   ├── run_classify.bat       ← Windows 工作排程器入口
│   └── ssh_tunnel.bat         ← 開啟 SSH tunnel（DB 必備）
├── lib/
│   ├── bootstrap.php          ← 共用啟動器（載入 config + 所有類別）
│   ├── Logger.php             ← 寫 logs/YYYYMMDD.log + stdout
│   ├── DocRepository.php      ← SQL Server 撈公文（PDO sqlsrv/odbc）
│   ├── AiClassifier.php       ← Ollama /api/chat 呼叫
│   └── JsonStore.php          ← 原子寫入 data/YYYYMMDD/<docId>.json
├── config/
│   ├── config.example.php     ← 設定範本（進 git）
│   └── config.php             ← 真實設定（被 .gitignore，請手動建立）
├── web/
│   ├── index.php              ← 第二支「網頁列表」入口
│   ├── .htaccess              ← Apache 安全/Index 設定
│   └── assets/
│       ├── style.css
│       └── app.js             ← 即時搜尋 + 篩選
├── apache/
│   └── sort-llm.vhost.example.conf
├── data/                       ← 每日 JSON 結果（執行時自動建立、被 ignore）
└── logs/                       ← 每日 log（執行時自動建立、被 ignore）
```

---

## 第一支程式：`run_classify.php`

1. **連線**：從 `config.php['db']` 讀連線資訊，預設經 `127.0.0.1:1433` → 由 `ssh_tunnel.bat` 轉發到內網 SQL Server  
2. **撈取**：抓「最近 `lookback_days` 天」內、且 `data/YYYYMMDD/<INNO>.json` 尚未存在的公文  
3. **欄位**：透過 `config['db']['columns']` 對映：
   - `id` → `INNO`（公文識別號／檔名）
   - `date` → `INDATE`
   - `org` → `GUNAME`
   - `subject` → `THEME`
   - `kind` → `INKIND`（可選；若 schema 沒有就留空，prompt 自動省略）
4. **AI**：POST 到 `OLLAMA_BASE/api/chat`，model 由 config 指定
5. **儲存**：每筆一個 JSON 檔，欄位包含原資料、預測組室、原始回應、耗時
6. **日誌**：`logs/YYYYMMDD.log`（PHP 寫）+ `logs/scheduler.log`（.bat 寫）

### 手動執行

```powershell
# 跑全部未處理
php src\bin\run_classify.php

# 只跑前 5 筆（測試用，等同 --limit 5）
php src\bin\run_classify.php 5
```

---

## 第二支程式：`web/index.php`

純 PHP 一頁式，**進站時全載入當日資料**到 JS，前端做即時搜尋與篩選。

| 功能 | 說明 |
|---|---|
| 統計卡 | 總筆數 / 成功 / 未知 / 錯誤 |
| 各組室快速篩選 | 點 chip 即刻過濾 |
| 搜尋框 | 比對公文代號 / 來文機關 / 主旨 / 來文字 |
| 狀態下拉 | ok / unknown / error |
| 日期切換 | 右上日期選擇器，可回看歷史 |
| 自動重整 | `config['web']['auto_refresh']`（秒），預設 60；設 0 關閉 |

---

## 安裝步驟

### 1. PHP 環境（中繼主機）

確認以下擴充已啟用（`php -m`）：

- `curl` `json` `mbstring` `pdo`
- `pdo_sqlsrv`（或 `pdo_odbc`，需先裝 Microsoft Drivers for PHP for SQL Server）

可沿用 `@relay/tools/test_env.php` 驗證。

### 2. 建立設定檔

```powershell
copy src\config\config.example.php src\config\config.php
notepad src\config\config.php
```

填入：
- `db.name` / `db.user` / `db.pass` / `db.table`
- `db.columns`（如果欄位名與預設不同）
- `ai.base_url` / `ai.model`

### 3. 開啟 SSH tunnel

編輯 `@src/bin/ssh_tunnel.bat`，填：
- `DB_HOST` / `DB_PORT`：從跳板看出去的 SQL Server 位址
- `JUMP_USER@JUMP_HOST`：跳板主機與帳號
- `KEY_FILE`：SSH 金鑰路徑

雙擊執行 → 視窗保持開啟代表 tunnel 正常。  
驗證：另開 cmd 執行 `telnet 127.0.0.1 1433` 應能連上。

> **常駐建議**：把 `ssh_tunnel.bat` 設為 Windows 開機啟動（捷徑放到 `shell:startup`），或用 NSSM 包成服務。

### 4. 先用 `relay/tools` 確認 SQL 與 AI 都通

```powershell
php relay\tools\test_env.php
php relay\tools\test_ai.php
php relay\tools\test_sql.php   # 先把 test_sql.php 頂部常數改成跟 config.php 一致
```

三項都過再進下一步。

### 5. 手動跑一次主程式

```powershell
php src\bin\run_classify.php 5    # 先跑 5 筆觀察 log 與 data/YYYYMMDD/
```

確認 `src\data\YYYYMMDD\*.json` 有產出且內容正常。

### 6. 設定 Windows 工作排程器

開「**工作排程器**」 → 建立基本工作：

- 名稱：`公文分辦 AI`
- 觸發程序：每日，重複時間例如 30 分鐘，期間 12 小時
- 動作：**啟動程式**
  - 程式或指令碼：`E:\program_dev\sort_llm_v1.0.0\src\bin\run_classify.bat`
  - 開始位置：`E:\program_dev\sort_llm_v1.0.0\src`

設好之後右鍵 → 「**執行**」測試一次，看 `src\logs\scheduler.log` 有沒有錯。

### 7. 設定 Apache（內網瀏覽）

複製 `@src/apache/sort-llm.vhost.example.conf` 到 Apache 的 `conf/extra/`，改其中的 `DocumentRoot`/`<Directory>` 絕對路徑，並在 `httpd.conf` 加：

```apache
Include conf/extra/sort-llm.vhost.conf
```

重啟 Apache，瀏覽 `http://<中繼主機 IP>/` 即見頁面。

---

## 系統啟動流程（每次開機）

1. `ssh_tunnel.bat` 自動啟動（放 `shell:startup` 或服務化）  
2. Apache 自動啟動（Windows 服務）  
3. Windows 工作排程器自動觸發 `run_classify.bat`（按設定間隔）  
4. 使用者開瀏覽器看 `http://<中繼主機>/` → 直接看到當日結果

---

## 疑難排解

| 症狀 | 排查方向 |
|---|---|
| `[FATAL] 找不到 config.php` | 沒複製 `config.example.php` 到 `config.php` |
| `SQL 撈取失敗` | (1) `ssh_tunnel.bat` 是否在跑？(2) `telnet 127.0.0.1 1433`(3) DB 帳密 / 表名是否正確 |
| `AI 主機無法連線` | (1) 中繼主機到 `192.168.1.237:11434` 通不通？(2) Ollama 是否啟動？(3) model 名稱對不對 |
| 網頁顯示「尚無資料」 | 檢查 `src/data/YYYYMMDD/` 是否有檔；確認 PHP user 對該目錄有讀權限 |
| 工作排程器執行完沒結果 | 看 `src/logs/scheduler.log`、`src/logs/YYYYMMDD.log` |

---

## TODO（後續迭代）

- [ ] DocRepository 增加「依分辦狀態欄位」過濾（若實際 SQL 表有此欄位）
- [ ] 網頁加 CSV 匯出
- [ ] 網頁加「修正分辦」功能（人工覆寫 + 回灌訓練資料）
- [ ] AI 失敗自動 retry（同步、間隔 30 秒）
