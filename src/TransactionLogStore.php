<?php

declare(strict_types=1);

namespace DbTransactionLog;

use DbTransactionLog\Concern\ResolvesConnection;
use PDO;

/**
 * Reader side of the DB-backed transaction log: filtered, paginated queries
 * for admin views, plus getById() for stable replay targeting.
 *
 * Config keys: `pdo` / `pdoProvider` / `table` (same as TransactionLogger).
 */
final class TransactionLogStore
{
    use ResolvesConnection;

    private string $table;

    public function __construct(array $config = [])
    {
        $this->table = $this->resolveTable($config);
        $this->setConnectionConfig($config);
    }

    private function pdo(): ?PDO
    {
        return $this->connection();
    }

    /**
     * @return TransactionLogQueryResult Newest-first page plus totals and the
     *         unfiltered distinct operation/class lists (for filter dropdowns).
     */
    public function query(
        ?string $operation = null,
        ?string $email = null,
        ?string $classToken = null,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $pageSize = 1000,
    ): TransactionLogQueryResult {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        [$where, $values] = $this->buildFilters($operation, $email, $classToken, $status, $from, $to);

        $totalEntries = $this->countAll();
        $totalMatching = $where === '' ? $totalEntries : $this->countWhere($where, $values);

        $entries = [];
        $pdo = $this->pdo();
        if ($pdo !== null) {
            $sql = 'SELECT * FROM `' . $this->table . '`'
                . ($where !== '' ? " WHERE $where" : '')
                . ' ORDER BY id DESC LIMIT :__limit OFFSET :__offset';
            $stmt = $pdo->prepare($sql);
            foreach ($values as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':__limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':__offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $entries[] = TransactionLogEntry::fromRow($row);
            }
        }

        return new TransactionLogQueryResult(
            $entries,
            $totalEntries,
            $totalMatching,
            $page,
            $pageSize,
            $this->distinctOperations(),
            $this->distinctClasses(),
        );
    }

    public function getById(int $id): ?TransactionLogEntry
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM `' . $this->table . '` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : TransactionLogEntry::fromRow($row);
    }

    public function countAll(): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $stmt = $pdo->query('SELECT COUNT(*) c FROM `' . $this->table . '`');
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return string[]
     */
    public function distinctOperations(): array
    {
        return $this->distinctColumn('operation');
    }

    /**
     * @return string[]
     */
    public function distinctClasses(): array
    {
        return $this->distinctColumn('class');
    }

    /**
     * @return array{0: string, 1: array<string, string>} WHERE clause (without
     *         the WHERE keyword; '' when no filters) and named-placeholder values
     */
    private function buildFilters(
        ?string $operation,
        ?string $email,
        ?string $classToken,
        ?string $status,
        ?string $from,
        ?string $to,
    ): array {
        $conds = [];
        $values = [];
        if ($operation !== null && $operation !== '') {
            $conds[] = '`operation` = :operation';
            $values[':operation'] = $operation;
        }
        if ($classToken !== null && $classToken !== '') {
            $conds[] = '`class` = :class';
            $values[':class'] = $classToken;
        }
        if ($status !== null && $status !== '') {
            $conds[] = '`status` = :status';
            $values[':status'] = $status;
        }
        if ($email !== null && $email !== '') {
            // Preserve the legacy file-log behavior: case-insensitive substring
            // match over the whole entry. `email` is the indexed first email;
            // params LIKE covers emails further down in the params text.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email);
            $conds[] = '(email LIKE :email1 OR params LIKE :email2)';
            $values[':email1'] = '%' . $escaped . '%';
            $values[':email2'] = '%' . $escaped . '%';
        }
        if ($from !== null && $from !== '') {
            $conds[] = '`ts` >= :from';
            $values[':from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $conds[] = '`ts` <= :to';
            $values[':to'] = $to;
        }
        return [implode(' AND ', $conds), $values];
    }

    private function countWhere(string $where, array $values): int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM `' . $this->table . "` WHERE $where");
        $stmt->execute($values);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return string[]
     */
    private function distinctColumn(string $column): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $stmt = $pdo->query('SELECT DISTINCT `' . $column . '` FROM `' . $this->table . '` ORDER BY `' . $column . '`');
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
