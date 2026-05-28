# 前置測試工具（拋棄式）

這三支腳本只在開發前期使用，正式系統運行後可刪除。

## 執行順序

```powershell
# 1. 環境檢查（先確認 PHP 與必要擴充 OK）
php test_env.php

# 2. AI 主機測試（確認 Ollama 連得上，量測實際速度）
php test_ai.php

# 3. SQL Server 測試（先打開檔案修改連線常數，再執行）
#    ← 編輯 test_sql.php 頂部的 DB_HOST、DB_NAME、DB_USER、DB_PASS、DB_TABLE
php test_sql.php
```

## 各測試的「通過」標準

| 測試 | 通過條件 |
|---|---|
| `test_env.php` | 所有項目皆顯示 `[OK]`（FAIL 必須先排除） |
| `test_ai.php` | 第 1 筆能拿到預測組室；後 5 筆平均 < 2000 ms |
| `test_sql.php` | 能列出欄位、能撈到資料 |

## 完成後

請把 `test_sql.php` 步驟 [2] 的欄位清單與 [3] 的樣本（敏感資料可遮蔽）回報給開發者，
就能進入 **M1：撰寫 `config.php` 與 `run_classify.php`**。
