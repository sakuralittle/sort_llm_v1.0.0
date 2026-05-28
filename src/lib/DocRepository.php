<?php
declare(strict_types=1);

/**
 * 公文資料來源
 * ----------------------------------------------------------
 * 從 SQL Server 抓取「最近 N 天」內、且本地尚未產生 JSON 的公文。
 *
 * 設計重點：
 *   - SQL 端只負責拋出候選清單；是否已處理由 JsonStore 判斷後過濾。
 *   - 欄位名稱由 config['db']['columns'] 映射，避免 schema 改變需動程式。
 *   - 主機/帳密由 config['db'] 提供（透過 SSH tunnel 對應到本機 127.0.0.1）。
 * ----------------------------------------------------------
 */
final class DocRepository
{
    /** @var array config['db'] 區段 */
    private array $cfg;
    private ?PDO  $pdo = null;

    public function __construct(array $dbConfig)
    {
        $this->cfg = $dbConfig;
    }

    /**
     * 建立 PDO 連線（lazy）
     */
    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->cfg['driver'] ?? 'sqlsrv';
        if ($driver === 'sqlsrv') {
            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s;Encrypt=no;TrustServerCertificate=yes',
                $this->cfg['host'], (int)$this->cfg['port'], $this->cfg['name']
            );
        } else { // odbc
            $dsn = sprintf(
                'odbc:Driver={ODBC Driver 17 for SQL Server};Server=%s,%d;Database=%s;',
                $this->cfg['host'], (int)$this->cfg['port'], $this->cfg['name']
            );
        }

        $this->pdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $this->pdo;
    }

    /**
     * 抓取最近 N 天內的公文（不去重，由呼叫端再過濾已處理）
     *
     * @return array<int, array{doc_id:string,收文日期:string,來文機關:string,來文字:string,來文主旨:string}>
     */
    public function fetchRecent(int $lookbackDays, int $maxRows): array
    {
        $cols  = $this->cfg['columns'];
        $table = $this->cfg['table'];

        $idCol      = $cols['id'];
        $dateCol    = $cols['date'];
        $orgCol     = $cols['org'];
        $subjectCol = $cols['subject'];
        $kindCol    = $cols['kind'] ?? null;

        // 選擇欄位（用 [中括號] 包，避免欄位名是中文或保留字）
        $selectKind = $kindCol ? ", [{$kindCol}] AS [kind]" : ", '' AS [kind]";

        // 注意：lookbackDays=1 ⇒ 今天；2 ⇒ 今天 + 昨天
        $sql = sprintf(
            "SELECT TOP %d
                [%s] AS [doc_id],
                [%s] AS [date],
                [%s] AS [org],
                [%s] AS [subject]
                %s
             FROM %s
             WHERE CAST([%s] AS DATE) >= DATEADD(day, -%d, CAST(GETDATE() AS DATE))
               AND CAST([%s] AS DATE) <= CAST(GETDATE() AS DATE)
             ORDER BY [%s] DESC",
            $maxRows,
            $idCol, $dateCol, $orgCol, $subjectCol,
            $selectKind,
            $table,
            $dateCol, max(0, $lookbackDays - 1),
            $dateCol,
            $idCol
        );

        $rows = $this->pdo()->query($sql)->fetchAll();

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
