<?php
declare(strict_types=1);

/**
 * 每日 JSON 結果儲存
 * ----------------------------------------------------------
 * 結構：
 *   <data_dir>/
 *     20260531/
 *       <docId>.json
 *       <docId>.json
 *
 * 提供：
 *   - dayDir() 確保資料夾存在
 *   - isProcessed() 判斷某筆是否已寫過
 *   - write() 原子寫入（temp + rename）
 *   - listForDate() 列出某日所有 JSON 結果（給 web 使用）
 * ----------------------------------------------------------
 */
final class JsonStore
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, "\\/");
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0777, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("無法建立資料目錄：{$this->baseDir}");
        }
    }

    public function baseDir(): string { return $this->baseDir; }

    /**
     * 取得指定日期的子資料夾（自動建立）
     * @param string $dateStr 'YYYYMMDD'
     */
    public function dayDir(string $dateStr): string
    {
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $dateStr;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("無法建立資料夾：{$dir}");
        }
        return $dir;
    }

    /**
     * 將公文識別號轉成安全的檔名（避免特殊字元、中文除外）
     */
    public static function sanitizeFilename(string $name): string
    {
        $safe = preg_replace('/[\\\\\/:\*\?"<>\|\s]+/u', '_', $name);
        return $safe !== '' ? $safe : 'unknown';
    }

    public function pathFor(string $dateStr, string $docId): string
    {
        return $this->dayDir($dateStr) . DIRECTORY_SEPARATOR . self::sanitizeFilename($docId) . '.json';
    }

    public function isProcessed(string $dateStr, string $docId): bool
    {
        return file_exists($this->pathFor($dateStr, $docId));
    }

    /**
     * 原子寫入 JSON（tmp + rename）
     */
    public function write(string $dateStr, string $docId, array $data): string
    {
        $path = $this->pathFor($dateStr, $docId);
        $tmp  = $path . '.tmp';

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
        if (!@rename($tmp, $path)) {
            // Windows 上若目標已存在 rename 會失敗 → 改成先 unlink 再 rename
            if (file_exists($path) && @unlink($path) && @rename($tmp, $path)) {
                return $path;
            }
            @unlink($tmp);
            throw new RuntimeException("rename failed: $tmp → $path");
        }
        return $path;
    }

    /**
     * 列出某日所有 JSON 結果（給 web 端讀）
     * @return array<int, array<string,mixed>>
     */
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
            if (!is_array($data)) continue;
            $records[] = $data;
        }
        // 依 predicted_at 由新到舊（缺值放最後）
        usort($records, function ($a, $b) {
            return strcmp((string)($b['predicted_at'] ?? ''), (string)($a['predicted_at'] ?? ''));
        });
        return $records;
    }
}
