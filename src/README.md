# 公文分辦系統 — src/

中繼主機端的 AI 公文分辦執行檔與內網瀏覽頁。  
全部以 **PHP（同步寫法）** 實作；資料來源走 **SSH tunnel → MySQL（實驗林公文系統 EXFODBS）**；排程由 **Windows 工作排程器** 觸發；對外用 **XAMPP Apache + HTTPS** 提供瀏覽。

---

## 目錄結構

```
src/
├── bin/
│   ├── run_classify.php       ← 第一支主程式（撈 SQL → 呼叫 AI → 寫 JSON）
│   ├── run_classify.bat       ← Windows 工作排程器入口（自動定位 cwd）
│   └── ssh_tunnel.bat         ← 開啟 SSH tunnel（DB 必備）
├── lib/
│   ├── bootstrap.php          ← 共用啟動器（載入 config + 所有類別）
│   ├── Logger.php             ← 寫 logs/YYYYMMDD.log + stdout
│   ├── DocRepository.php      ← MySQL 撈公文（PDO mysql；INDATE 民國年字串比對）
│   ├── AiClassifier.php       ← Ollama /api/chat 呼叫
│   └── JsonStore.php          ← 原子寫入 data/YYYYMMDD/<docId>.json
├── config/
│   ├── config.example.php     ← 設定範本（進 git）
│   └── config.php             ← 真實設定（被 .gitignore，請手動建立）
├── web/
│   ├── index.php              ← 第二支「網頁列表」入口
│   ├── ciallo.jpg             ← favicon
│   └── assets/
│       ├── style.css
│       └── app.js             ← 即時搜尋 + 篩選
├── apache/
│   └── sort-llm.vhost.example.conf  ← vhost 範本（Define 變數化，可搬移）
├── data/                       ← 每日 JSON 結果（執行時自動建立、被 .gitignore）
└── logs/                       ← 每日 log（執行時自動建立、被 .gitignore）
```

> 整個專案設計為**可搬移**：PHP 程式全用 `__DIR__` 相對路徑；`.bat` 用 `%~dp0` 自動定位；Apache vhost 用 `Define SORTLLM_HOME` 變數。搬到任何位置只需改 vhost 一行。

---

## 第一支程式：`run_classify.php`

1. **連線**：從 `config.php['db']` 讀連線資訊；預設經 `127.0.0.1:13306` → 由 `ssh_tunnel.bat` 轉發到內網 MySQL
2. **撈取**：抓「最近 `lookback_days` 天」內、且 `data/YYYYMMDD/<INNO>.json` 尚未存在的公文  
   `INDATE` 為民國年字串（例：`115/05/31`），由 `DocRepository::rocDateRange()` 自動產生對應字串清單做 `IN (...)` 比對
3. **欄位映射**（在 `config['db']['columns']`）：
   - `id`      → `INNO`（公文識別號／檔名）
   - `date`    → `INDATE`
   - `org`     → `GUNAME`（來文機關）
   - `subject` → `THEME`（主旨）
   - `kind`    → `THEYNO1`（來文字，例：實管／溪育／溪總）
4. **AI**：POST 到 `OLLAMA_BASE/api/chat`，model 由 config 指定
5. **儲存**：每筆一個 JSON 檔，欄位包含原資料、AI 預測組室、原始回應、耗時、狀態
6. **日誌**：`logs/YYYYMMDD.log`（PHP 寫）+ `logs/scheduler.log`（.bat 寫）

### 狀態判斷

| 狀態 | 觸發條件 |
|---|---|
| `ok` | AI 回 HTTP 200，且回應內容包含 `ai.target_units` 白名單中的組室名 |
| `unknown` | AI 回 HTTP 200，但內容不含任何白名單組室 |
| `error` | HTTP 非 200、cURL 失敗、或處理過程拋出例外 |

### 手動執行

```powershell
# 跑全部未處理
php src\bin\run_classify.php

# 只跑前 5 筆（測試用）
php src\bin\run_classify.php 5
```

---

## 第二支程式：`web/index.php`

純 PHP 一頁式，**進站時一次載入當日資料**到 JS，前端做即時搜尋與篩選。

| 功能 | 說明 |
|---|---|
| 統計卡 | 總筆數 / 成功 / 未知 / 錯誤 |
| 各組室快速篩選 | 點 chip 即刻過濾 |
| 搜尋框 | 比對公文代號 / 來文機關 / 主旨 / 來文字 |
| 狀態下拉 | ok / unknown / error |
| 日期切換 | 右上日期選擇器，可回看歷史 |
| 自動重整 | `config['web']['auto_refresh']`（秒），預設 60；設 0 關閉 |

---

## 部署到新機器（一頁式 checklist）

> 文件用 `<PROJECT_DIR>` 代表你的實際安裝位置，例如 `E:\program_dev\sort_llm_v1.0.0` 或 `D:\projects\sort_llm`。

### 0. 前置條件

- Windows 10/11 + XAMPP（含 Apache + PHP 8.x + OpenSSL）
- Windows 內建 OpenSSH client（`ssh.exe` 可用）
- 中繼主機能 SSH 連到跳板機，且跳板機能連到內網 MySQL

### 1. 取得專案

```powershell
git clone <repo-url> <PROJECT_DIR>
```

PHP 程式本身不依賴特定路徑，放任何位置都可以。

### 2. 確認 PHP 擴充

```powershell
<XAMPP>\php\php -m | findstr /i "curl json mbstring pdo_mysql"
```

需出現：`curl` `json` `mbstring` `pdo_mysql`。若沒有 `pdo_mysql`，請在 `<XAMPP>\php\php.ini` 取消註解 `extension=pdo_mysql`。

### 3. 建立 config.php

```powershell
copy <PROJECT_DIR>\src\config\config.example.php <PROJECT_DIR>\src\config\config.php
notepad <PROJECT_DIR>\src\config\config.php
```

填入：
- `db.host` / `db.port` / `db.name` / `db.user` / `db.pass` / `db.table`
- `db.columns`（如果欄位名與預設不同）
- `ai.base_url` / `ai.model` / `ai.target_units`

### 4. 設定並啟動 SSH tunnel

編輯 `<PROJECT_DIR>\src\bin\ssh_tunnel.bat`：
- `LOCAL_PORT`：本機 listen port（預設 13306，避開本機 MySQL 3306）
- `DB_HOST` / `DB_PORT`：從跳板機看出去的 MySQL 位址
- `JUMP_USER@JUMP_HOST`：跳板主機與帳號
- `KEY_FILE`：SSH 金鑰路徑（若空則 fallback 密碼）

雙擊執行 → 視窗保持開啟代表 tunnel 正常。  
驗證：另開 cmd `powershell -c "Test-NetConnection 127.0.0.1 -Port 13306"` 應 `TcpTestSucceeded : True`。

> **常駐建議**：把 `ssh_tunnel.bat` 設為 Windows 開機啟動（捷徑放到 `shell:startup`），或用 NSSM 包成服務。

### 5. 驗證 SQL 與 AI 可通

```powershell
php <PROJECT_DIR>\relay\tools\test_env.php
php <PROJECT_DIR>\relay\tools\test_ai.php
php <PROJECT_DIR>\relay\tools\test_sql.php
```

三項都過再進下一步。

### 6. 手動跑一次主程式

```powershell
php <PROJECT_DIR>\src\bin\run_classify.php 5
```

確認 `<PROJECT_DIR>\src\data\YYYYMMDD\*.json` 有產出且內容正常。

### 7. 設定 Apache vhost（HTTP + HTTPS）

**步驟 7a — 複製 vhost 範本到 Apache**：

```powershell
copy <PROJECT_DIR>\src\apache\sort-llm.vhost.example.conf C:\xampp\apache\conf\extra\sort-llm.vhost.conf
```

**步驟 7b — 修改 vhost 唯一一行**：

打開 `C:\xampp\apache\conf\extra\sort-llm.vhost.conf`，找到：

```apache
Define SORTLLM_HOME "E:/program_dev/sort_llm_v1.0.0/src"
```

改成你的實際路徑（**用正斜線 `/`，不是反斜線 `\`**）：

```apache
Define SORTLLM_HOME "D:/projects/sort_llm/src"
```

**步驟 7c — 修改 `C:\xampp\apache\conf\httpd.conf`**，加入或確認以下三項：

```apache
# 1. 載入需要的模組（XAMPP 預設有寫但前面有 #，移除即可）
LoadModule rewrite_module       modules/mod_rewrite.so
LoadModule ssl_module           modules/mod_ssl.so
LoadModule socache_shmcb_module modules/mod_socache_shmcb.so

# 2. Listen 兩個 port
Listen 80
Listen 443

# 3. 載入我們的 vhost（加在檔案最後）
Include conf/extra/sort-llm.vhost.conf
```

**步驟 7d — 重啟 Apache**：

XAMPP Control Panel → Apache 按「Stop」再按「Start」。或：

```powershell
C:\xampp\apache\bin\httpd.exe -k restart
```

**步驟 7e — 瀏覽器測試**：

- http://localhost/  → 應自動跳轉到 https://sort-llm.local/
- https://localhost/ → 首次連線會顯示「不安全憑證」警告（XAMPP self-signed），按「進階 → 仍要前往」即可看到網頁

> 內網其他電腦要連的話，把 `localhost` 換成中繼主機的 IP（例如 `https://192.168.x.x/`）。  
> 若要用 `sort-llm.local` 漂亮網址，需在各 client 機器的 `C:\Windows\System32\drivers\etc\hosts` 加：  
> `<中繼主機IP>  sort-llm.local`

### 8. Windows 工作排程器

開「**工作排程器**」 → 建立基本工作：

- 名稱：`公文分辦 AI`
- 觸發程序：每日，重複時間例如 30 分鐘，期間 12 小時
- 動作：**啟動程式**
  - 程式或指令碼：`<PROJECT_DIR>\src\bin\run_classify.bat`
  - 開始位置：（可留空，run_classify.bat 內部自動定位 cwd）

設好之後右鍵 → 「**執行**」測試一次，看 `<PROJECT_DIR>\src\logs\scheduler.log` 有沒有錯。

---

## 系統啟動流程（每次中繼主機開機）

1. `ssh_tunnel.bat` 自動啟動（放 `shell:startup` 或服務化）
2. Apache 自動啟動（Windows 服務）
3. Windows 工作排程器自動觸發 `run_classify.bat`（按設定間隔）
4. 使用者開瀏覽器看 `https://<中繼主機>/` → 直接看到當日結果

---

## 安全設計

- `config.php`（含密碼）只在本機，**不入 git**
- `ssh_tunnel.bat`（含跳板機 IP/帳號）建議也加入 `.gitignore`
- Apache vhost 的 `DocumentRoot` 只指到 `src/web/`，**`config / lib / data / logs / bin / apache` 全部在 web root 之外**，URL 無法存取
- 額外用 `Require all denied` 對上述目錄做縱深防禦
- HTTPS 強制（HTTP 自動 redirect 到 HTTPS）
- 預設 SSL cert 為 XAMPP 內建 self-signed，可改用 mkcert 簽發內網 CA 信任的憑證

---

## 疑難排解

| 症狀 | 排查方向 |
|---|---|
| `[FATAL] 找不到 config.php` | 沒複製 `config.example.php` 到 `config.php` |
| `SQL 撈取失敗` | (1) `ssh_tunnel.bat` 是否在跑？(2) `Test-NetConnection 127.0.0.1 -Port 13306` (3) DB 帳密 / 表名是否正確 |
| `AI 主機無法連線` | (1) 中繼主機到 AI 主機通不通？(2) Ollama 是否啟動？(3) model 名稱對不對（由 `ping()` 驗證） |
| 今日公文計數 = 0 但實際有資料 | `INDATE` 是民國年字串，必須用 `DocRepository::rocDateRange()` 生成的字串清單比對 |
| 網頁顯示「尚無資料」 | 檢查 `src/data/YYYYMMDD/` 是否有檔；確認 Apache 帳號對該目錄有讀權限 |
| 工作排程器執行完沒結果 | 看 `src/logs/scheduler.log`、`src/logs/YYYYMMDD.log` |
| Apache 啟動失敗 | XAMPP Control Panel 看紅字錯誤；或執行 `httpd.exe -t` 檢查語法；常見：80 / 443 port 被其他程式佔用（Skype、IIS） |
| 瀏覽器顯示「您的連線不是私人連線」 | 預期行為（self-signed cert）。按「進階 → 仍要前往」即可。要徹底解決請改用 mkcert |

---

## TODO（後續迭代）

- [ ] AI 失敗自動 retry（同步、間隔 30 秒）
- [ ] 網頁加 CSV 匯出
- [ ] 網頁加「修正分辦」功能（人工覆寫 + 回灌訓練資料）
- [ ] AJAX 輪詢取代 meta refresh（不閃屏）
- [ ] mkcert 簽發內網 CA 信任的 HTTPS 憑證
