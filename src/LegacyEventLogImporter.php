<?php

declare(strict_types=1);

namespace DbTransactionLog;

use PDO;

/**
 * One-time backfill of the DB transaction log from a legacy "event log" table.
 *
 * Legacy event tables (e.g. Outings' `eventlog`) recorded one row per
 * user-visible action: a type, an email, a created timestamp, a free-form
 * detail blob and sometimes an outing id. That is enough to reconstruct a
 * usable transaction history for the admin log viewer, but not enough to
 * replay the operation, so imported rows are stored with status 'ok' and the
 * original detail text as params.
 *
 * The import is idempotent: each imported row is stamped with
 * `request = '<provenance>:<source id>'`, and source ids already present in
 * the target table are skipped. Re-running therefore only adds rows that
 * appeared since the previous run.
 *
 * Options (constructor):
 *  - sourceTable    string  default 'eventlog'
 *  - idColumn       string  default 'id'
 *  - tsColumn       string  default 'created'
 *  - typeColumn     string  default 'type'
 *  - emailColumn    string  default 'email'
 *  - detailColumn   string  default 'detail'
 *  - classByType    array<string,string>  type => class token (case-insensitive)
 *  - defaultClass   string  used for unmapped types (default '')
 *  - operationAliases array<string,string> normalise legacy/typo types
 *  - batchSize      int     default 500
 *  - provenance     string  default 'eventlog'
 *  - usernameColumn string|null  column to use as the acting username
 *                   (default: emailColumn)
 */
final class LegacyEventLogImporter
{
    private string $sourceTable;
    private string $idColumn;
    private string $tsColumn;
    private string $typeColumn;
    private string $emailColumn;
    private string $detailColumn;
    private string $usernameColumn;
    /** @var array<string,string> lower-cased type => class token */
    private array $classByType;
    private string $defaultClass;
    /** @var array<string,string> lower-cased type => operation */
    private array $operationAliases;
    private int $batchSize;
    private string $provenance;

    public function __construct(private TransactionLogger $logger, array $options = [])
    {
        $this->sourceTable = (string)($options['sourceTable'] ?? 'eventlog');
        $this->idColumn = (string)($options['idColumn'] ?? 'id');
        $this->tsColumn = (string)($options['tsColumn'] ?? 'created');
        $this->typeColumn = (string)($options['typeColumn'] ?? 'type');
        $this->emailColumn = (string)($options['emailColumn'] ?? 'email');
        $this->detailColumn = (string)($options['detailColumn'] ?? 'detail');
        $this->usernameColumn = (string)($options['usernameColumn'] ?? $this->emailColumn);
        $this->defaultClass = (string)($options['defaultClass'] ?? '');
        $this->batchSize = max(1, (int)($options['batchSize'] ?? 500));
        $this->provenance = (string)($options['provenance'] ?? 'eventlog');

        $this->classByType = [];
        foreach ((array)($options['classByType'] ?? []) as $type => $classToken) {
            $this->classByType[strtolower((string)$type)] = (string)$classToken;
        }
        $this->operationAliases = [];
        foreach ((array)($options['operationAliases'] ?? []) as $type => $alias) {
            $this->operationAliases[strtolower((string)$type)] = (string)$alias;
        }
    }

    /**
     * Import all not-yet-imported source rows.
     *
     * @param callable(int $imported, int $skipped, int $lastId): void|null $onProgress
     * @return array{imported:int, skipped:int, lastId:int}
     * @throws \RuntimeException when the source table is missing or a row
     *         cannot be written (never silently drops history).
     */
    public function import(?callable $onProgress = null): array
    {
        $pdo = $this->logger->getConnection();
        if ($pdo === null) {
            throw new \RuntimeException('LegacyEventLogImporter: no database connection');
        }

        $this->assertSourceTableExists($pdo);
        $alreadyImported = $this->existingSourceIds($pdo);

        $imported = 0;
        $skipped = 0;
        $lastId = 0;

        $select = sprintf(
            'SELECT `%s` AS src_id, `%s` AS src_ts, `%s` AS src_type, `%s` AS src_email,'
            . ' `%s` AS src_username, `%s` AS src_detail'
            . ' FROM `%s` ORDER BY `%s` ASC',
            $this->idColumn,
            $this->tsColumn,
            $this->typeColumn,
            $this->emailColumn,
            $this->usernameColumn,
            $this->detailColumn,
            $this->sourceTable,
            $this->idColumn
        );
        $stmt = $pdo->query($select);
        if ($stmt === false) {
            throw new \RuntimeException('LegacyEventLogImporter: could not read ' . $this->sourceTable);
        }

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $srcId = (int)$row['src_id'];
            $lastId = $srcId;
            if (isset($alreadyImported[$srcId])) {
                $skipped++;
                continue;
            }

            $type = (string)$row['src_type'];
            $operation = $this->operationAliases[strtolower($type)] ?? $type;
            $classToken = $this->classByType[strtolower($type)] ?? $this->defaultClass;

            $inserted = $this->logger->insertRow([
                'ts' => (string)$row['src_ts'],
                'username' => (string)$row['src_username'],
                'class' => $classToken,
                'operation' => $operation,
                'email' => (string)$row['src_email'],
                'status' => 'ok',
                'error' => '',
                'params' => (string)$row['src_detail'],
                'request' => $this->provenance . ':' . $srcId,
            ]);
            if ($inserted === null) {
                throw new \RuntimeException(
                    'LegacyEventLogImporter: failed to insert source row ' . $srcId
                );
            }

            $imported++;
            if ($onProgress !== null && $imported % $this->batchSize === 0) {
                $onProgress($imported, $skipped, $lastId);
            }
        }

        if ($onProgress !== null) {
            $onProgress($imported, $skipped, $lastId);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'lastId' => $lastId];
    }

    /**
     * Source row ids already present in the target table, read from the
     * provenance marker in the request column.
     *
     * @return array<int,true>
     */
    private function existingSourceIds(PDO $pdo): array
    {
        $prefix = $this->provenance . ':';
        $sql = 'SELECT `request` FROM `' . $this->logger->getTable() . '`'
            . ' WHERE `request` LIKE :prefix';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':prefix' => $prefix . '%']);

        $ids = [];
        while (($value = $stmt->fetchColumn()) !== false) {
            $id = substr((string)$value, strlen($prefix));
            if ($id !== '' && ctype_digit($id)) {
                $ids[(int)$id] = true;
            }
        }
        return $ids;
    }

    private function assertSourceTableExists(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute([':table' => $this->sourceTable]);
        if ((int)$stmt->fetchColumn() === 0) {
            throw new \RuntimeException(
                'LegacyEventLogImporter: source table `' . $this->sourceTable . '` does not exist'
            );
        }
    }
}
