<?php

declare(strict_types=1);

namespace DbTransactionLog;

use DbTransactionLog\Concern\ResolvesConnection;
use PDO;

/**
 * Writer side of the DB-backed transaction log.
 *
 * One row per recorded operation in the configured table, including failed
 * operations (status/error columns). Logging is strictly best-effort: any
 * failure is reported via error_log() and never thrown into app code.
 *
 * Config keys (constructor / configure()):
 *  - enabled          bool   default false — no writes until enabled
 *  - table            string default 'transactions'
 *  - pdo              ?PDO   explicit connection
 *  - pdoProvider      callable(): ?PDO  e.g. fn() => DBBase::getDefaultConnection()
 *  - now              callable(): string 'Y-m-d H:i:s'  (default date('Y-m-d H:i:s'))
 *  - username         callable(): string
 *  - request          callable(): string  (default $_SERVER['SCRIPT_NAME'])
 *  - retentionDays    int    default 365 — used by deleteOlderThan()/maybePrune()
 *  - pruneProbability float  default 0.01 — chance per log() call to run a retention delete
 */
final class TransactionLogger
{
    use ResolvesConnection;

    private const COLUMNS = ['ts', 'username', 'class', 'operation', 'email', 'status', 'error', 'params', 'request'];

    private static ?TransactionLogger $default = null;

    private array $config;
    private bool $enabled;
    private string $table;
    private int $retentionDays;
    private float $pruneProbability;
    /** @var array<string, \PDOStatement> prepared-statement cache keyed by column list */
    private array $stmtCache = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->table = $this->resolveTable($config);
        $this->setConnectionConfig($config);
        $this->retentionDays = (int)($config['retentionDays'] ?? 365);
        $this->pruneProbability = (float)($config['pruneProbability'] ?? 0.01);
    }

    public static function configure(array $config): void
    {
        self::$default = new self($config);
    }

    public static function getDefault(): ?self
    {
        return self::$default;
    }

    public static function resetDefault(): void
    {
        self::$default = null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getConnection(): ?PDO
    {
        return $this->connection();
    }

    /**
     * Record one transaction. $params is stored as var_export() text verbatim
     * so replays see exactly what was logged. Never throws: any failure
     * (including throwing config callables) goes to error_log().
     */
    public function log(string $classToken, string $operation, mixed $params, string $status = 'ok', string $error = ''): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $pdo = $this->connection();
            if ($pdo === null) {
                return;
            }
            $paramsText = var_export($params, true);
            $email = '';
            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $paramsText, $m)) {
                $email = $m[0];
            }
            $this->insertRow([
                'ts' => (string)$this->callConfig($this->config, 'now', date('Y-m-d H:i:s')),
                'username' => (string)$this->callConfig($this->config, 'username', ''),
                'class' => $classToken,
                'operation' => $operation,
                'email' => $email,
                'status' => $status,
                'error' => $error,
                'params' => $paramsText,
                'request' => (string)$this->callConfig($this->config, 'request', $_SERVER['SCRIPT_NAME'] ?? ''),
            ]);
            $this->maybePrune();
        } catch (\Throwable $e) {
            error_log('db-transaction-log: log failed: ' . $e->getMessage());
        }
    }

    /**
     * Like log(), but derives classToken/operation from the calling method via
     * debug_backtrace (frame $btLimit-1). The class token is the short class
     * name with a leading 'DB' stripped, matching DBBase::getClassName()
     * (e.g. RetreeSites\DB\DBSite -> 'Site').
     */
    public function logCaller(mixed $params, string $status = 'ok', string $error = '', int $btLimit = 2): void
    {
        if (!$this->enabled) {
            return;
        }
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $btLimit);
        $class = (string)($bt[1]['class'] ?? '');
        $func = (string)($bt[1]['function'] ?? '');
        $classToken = $class;
        if ($class !== '' && class_exists($class)) {
            $classToken = (string)preg_replace('/^DB/', '', (new \ReflectionClass($class))->getShortName());
        }
        $this->log($classToken, $func, $params, $status, $error);
    }

    /**
     * Low-level insert used by log() and by the legacy file importer (which
     * already has raw params text). Returns the new id, or null on failure.
     */
    public function insertRow(array $row): ?int
    {
        $pdo = $this->connection();
        if ($pdo === null) {
            return null;
        }
        try {
            $fields = [];
            foreach (self::COLUMNS as $col) {
                if (array_key_exists($col, $row)) {
                    $fields[] = $col;
                }
            }
            $sql = 'INSERT INTO `' . $this->table . '` (`' . implode('`,`', $fields) . '`)'
                . ' VALUES (:' . implode(',:', $fields) . ')';
            $values = [];
            foreach ($fields as $col) {
                $values[':' . $col] = $row[$col];
            }
            $cacheKey = spl_object_id($pdo) . ':' . implode(',', $fields);
            $stmt = $this->stmtCache[$cacheKey] ?? null;
            if ($stmt === null) {
                $stmt = $pdo->prepare($sql);
                $this->stmtCache[$cacheKey] = $stmt;
            }
            $stmt->execute($values);
            $id = $pdo->lastInsertId();
            return $id === false || $id === null ? null : (int)$id;
        } catch (\Throwable $e) {
            error_log('db-transaction-log: insert failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retention: delete rows older than $days (default: configured
     * retentionDays). Returns the number of deleted rows.
     */
    public function deleteOlderThan(?int $days = null): int
    {
        $pdo = $this->connection();
        if ($pdo === null) {
            return 0;
        }
        $days = $days ?? $this->retentionDays;
        try {
            $now = (string)$this->callConfig($this->config, 'now', date('Y-m-d H:i:s'));
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . max(0, $days) . ' days', strtotime($now)));
            $stmt = $pdo->prepare('DELETE FROM `' . $this->table . '` WHERE `ts` < :cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('db-transaction-log: retention delete failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Probabilistic retention prune, called by log() so retention runs even
     * without an external cron. For deterministic pruning use deleteOlderThan()
     * from bin/prune-transactions via cron.
     */
    public function maybePrune(): void
    {
        if (!$this->enabled || $this->pruneProbability <= 0.0) {
            return;
        }
        if (mt_rand(1, 10000) <= (int)round($this->pruneProbability * 10000)) {
            $this->deleteOlderThan();
        }
    }
}
