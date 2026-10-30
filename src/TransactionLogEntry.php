<?php

declare(strict_types=1);

namespace DbTransactionLog;

/**
 * One row of the `transactions` table.
 *
 * `classToken` maps to the `class` column (kept as `class` in SQL for parity
 * with the legacy log's class tokens such as 'Site' / 'PlantOrder').
 */
final class TransactionLogEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $ts,
        public readonly string $username,
        public readonly string $classToken,
        public readonly string $operation,
        public readonly string $email,
        public readonly string $status,
        public readonly string $error,
        public readonly string $params,
        public readonly string $request,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['id'] ?? 0),
            (string)($row['ts'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['class'] ?? $row['classToken'] ?? ''),
            (string)($row['operation'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['status'] ?? 'ok'),
            (string)($row['error'] ?? ''),
            (string)($row['params'] ?? ''),
            (string)($row['request'] ?? ''),
        );
    }

    /**
     * Reconstruct the legacy file-style entry text for display purposes.
     */
    public function raw(): string
    {
        $callToken = $this->classToken !== ''
            ? $this->classToken . $this->operation
            : $this->operation;

        return '// ' . $this->ts . ($this->username !== '' ? ' ' . $this->username : '') . "\n"
            . '$s = new ' . $this->classToken . "();\n"
            . '$s->' . $callToken . '(' . $this->params . ");\n";
    }

    /**
     * Parse the stored var_export params text back into a PHP array.
     *
     * @throws Import\TransactionLogParseException
     */
    public function paramsArray(): array
    {
        return (new Import\VarExportArrayParser())->parse($this->params);
    }
}
