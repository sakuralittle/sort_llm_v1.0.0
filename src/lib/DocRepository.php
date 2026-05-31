<?php
declare(strict_types=1);

/**
 * 公文資料來源（MySQL 主，SQL Server 後援）
 * ----------------------------------------------------------
 * 從 DB 抓取「最近 N 天」內、且本地尚未產生 JSON 的公文。
 *
 * 重要：實驗林 IFTDC_INDCM 的 INDATE 欄位是「民國年字串」（varchar(9)）
 *        例如「115/05/31」，不是 DATE 型別。
 *        因此 PHP 端先把今天 ~ 今天-(N-1) 天的民國年字串組好，
 *        SQL 端用 INDATE IN (:d0, :d1, ...) 比對，效率最佳（可走索引）。
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
        } else {
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

    private function quoteId(string $name): string
    {
        if ($this->driver() === 'mysql') {
            return '`' . str_replace('`', '``', $name) . '`';
        }
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    /**
     * 把 DateTimeImmutable 轉為民國年字串：「YYY/MM/DD」
     * 注意：採 zero-pad 月日（與 DB 內格式 115/05/31 一致）
     */
    public static function toRocDate(DateTimeImmutable $d): string
    {
        $rocYear = (int)$d->format('Y') - 1911;
        return sprintf('%d/%s', $rocYear, $d->format('m/d'));
    }

    /**
     * 產生「今天、今天-1、…、今天-(N-1)」的民國年字串清單
     */
    public static function rocDateRange(int $lookbackDays): array
    {
        $n   = max(1, $lookbackDays);
        $now = new DateTimeImmutable('today');
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::toRocDate($now->modify("-$i day"));
        }
        return $out;
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

        $tableQuoted = implode('.', array_map([$this, 'quoteId'], explode('.', $table)));

        $selectKind = $kindCol ? ", $kindCol AS `kind`" : ", '' AS `kind`";

        // 產生民國年字串清單作為 IN (...) 條件
        $rocDates = self::rocDateRange($lookbackDays);
        $placeholders = [];
        $params = [];
        foreach ($rocDates as $i => $v) {
            $key = ":d$i";
            $placeholders[] = $key;
            $params[$key]   = $v;
        }
        $inClause = implode(', ', $placeholders);

        if ($this->driver() === 'mysql') {
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
        } else {
            // SQL Server 後援（同樣以字串比對）
            $sql = "SELECT TOP $maxRows
                        $idCol      AS [doc_id],
                        $dateCol    AS [date],
                        $orgCol     AS [org],
                        $subjectCol AS [subject]
                        $selectKind
                    FROM $tableQuoted
                    WHERE $dateCol IN ($inClause)
                    ORDER BY $idCol DESC";
            $stmt = $this->pdo()->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
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
