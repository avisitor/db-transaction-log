<?php

declare(strict_types=1);

namespace DbTransactionLog\Import;

/**
 * One entry parsed from a legacy transactions.log file.
 */
final class FileLogEntry
{
    public function __construct(
        public readonly string $timestamp,
        public readonly string $username,
        public readonly string $classToken,
        public readonly string $operation,
        public readonly string $raw,
        public readonly string $paramsText,
        public readonly string $firstEmail,
    ) {
    }
}
