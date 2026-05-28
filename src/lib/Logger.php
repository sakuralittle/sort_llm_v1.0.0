<?php
declare(strict_types=1);

/**
 * 簡易 Logger
 *  - 每日一份檔案：<log_dir>/YYYYMMDD.log
 *  - 同步輸出到 stdout（方便排程器的 transcript / 手動執行觀察）
 *  - 不依賴任何第三方套件
 */
final class Logger
{
    private string $logDir;
    private string $logFile;
    private bool   $echo;

    public function __construct(string $logDir, bool $echo = true)
    {
        $this->logDir = rtrim($logDir, "\\/");
        if (!is_dir($this->logDir) && !mkdir($this->logDir, 0777, true) && !is_dir($this->logDir)) {
            throw new RuntimeException("無法建立 log 目錄：{$this->logDir}");
        }
        $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        $this->echo    = $echo;
    }

    public function info(string $msg): void  { $this->write('INFO',  $msg); }
    public function warn(string $msg): void  { $this->write('WARN',  $msg); }
    public function error(string $msg): void { $this->write('ERROR', $msg); }
    public function debug(string $msg): void { $this->write('DEBUG', $msg); }

    /**
     * 純文字（不加 timestamp / level）— 用來印分隔線、表格
     */
    public function raw(string $line): void
    {
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND);
        if ($this->echo) {
            echo $line, PHP_EOL;
        }
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
