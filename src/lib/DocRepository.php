<?php
declare(strict_types=1);

/**
 * 公文資料來源（MySQL / SQL Server 雙支援）
 * ----------------------------------------------------------
 * 從 DB 抓取「最近 N 天」內、且本地尚未產生 JSON 的公文。
 *
 * 設計重點：
 *   - SQL 端只負責拋出候選清單；是否已處理由 JsonStore 判斷後過濾。
 *   - 欄位名稱由 config['db']['columns'] 映射，避免 schema 改變需動程式。
 *   - 主機/帳密由 config['db'] 提供（透過 SSH tunnel 對應到本機 127.0.0.1）。
 *   - driver = 'mysql' / 'sqlsrv'，會自動切 DSN 與 SQL 方言。
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

    private function driver(): string
    {
        return strtolower((string)($this->cfg['driver'] ?? 'mysql'));
    }

    /**
     * 建立 PDO 連線（lazy）
     */
    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->driver();
        if ($driver === 'mysql') {
            $charset = (string)($this->cfg['charset'] ?? 'utf8mb4');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->cfg['host'], (int)$this->cfg['port'], $this->cfg['name'], $charset
            );
        } elseif ($driver === 'sqlsrv') {
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
     * 將欄位名包成該方言的 identifier
     */
    private function quoteId(string $name): string
    {
        if ($this->driver() === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    /**
     * 抓取最近 N 天內的公文
     *
     * @return array<int, array{doc_id:string,收文日期:string,來文機關:string,來文字:string,來文主旨:string}>
     */
    public function fetchRecent(int $lookbackDays, int $maxRows): array
    {
        $cols  = $this->cfg['columns'];
        $table = $this->cfg['table'];

        $idCol      = $this->quoteId($cols['id']);
        $dateCol    = $this->quoteId($cols['date']);
        $orgCol     = $this->quoteId($cols['org']);
        $subjectCol = $this->quoteId($cols['subject']);
        $kindCol    = !empty($cols['kind']) ? $this->quoteId($cols['kind']) : null;

        // table 名稱可能是 schema.table，逐段 quote
        $tableQuoted = implode('.', array_map([$this, 'quoteId'], explode('.', $table)));

        $selectKind = $kindCol ? ", $kindCol AS `kind`" : ", '' AS `kind`";

        // lookbackDays=1 ⇒ 今天；2 ⇒ 今天 + 昨天
        $offset = max(0, $lookbackDays - 1);

        if ($this->driver() === 'mysql') {
            // MySQL 方言
            $sql = "SELECT
                        $idCol      AS `doc_id`,
                        $dateCol    AS `date`,
                        $orgCol     AS `org`,
                        $subjectCol AS `subject`
                        $selectKind
                    FROM $tableQuoted
                    WHERE DATE($dateCol) >= DATE_SUB(CURDATE(), INTERVAL :off DAY)
                      AND DATE($dateCol) <= CURDATE()
                    ORDER BY $idCol DESC
                    LIMIT :lim";
            $stmt = $this->pdo()->prepare($sql);
            $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
            $stmt->bindValue(':lim', $maxRows, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } else {
            // SQL Server 方言
            $sql = "SELECT TOP $maxRows
                        $idCol      AS [doc_id],
                        $dateCol    AS [date],
                        $orgCol     AS [org],
                        $subjectCol AS [subject]
                        $selectKind
                    FROM $tableQuoted
                    WHERE CAST($dateCol AS DATE) >= DATEADD(day, -$offset, CAST(GETDATE() AS DATE))
                      AND CAST($dateCol AS DATE) <= CAST(GETDATE() AS DATE)
                    ORDER BY $idCol DESC";
            $rows = $this->pdo()->query($sql)->fetchAll();
        }

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
