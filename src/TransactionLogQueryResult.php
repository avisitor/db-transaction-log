<?php

declare(strict_types=1);

namespace DbTransactionLog;

/**
 * Result of a TransactionLogStore::query() call.
 */
final class TransactionLogQueryResult
{
    /**
     * @param TransactionLogEntry[] $entries Newest-first entries for the page
     * @param string[] $distinctOperations All operations in the table (unfiltered)
     * @param string[] $distinctClasses All class tokens in the table (unfiltered)
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $totalEntries,
        public readonly int $totalMatching,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $distinctOperations,
        public readonly array $distinctClasses,
    ) {
    }

    /**
     * @return TransactionLogEntry[]
     */
    public function pageEntries(): array
    {
        return $this->entries;
    }
}
