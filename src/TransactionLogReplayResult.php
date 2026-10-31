<?php

declare(strict_types=1);

namespace DbTransactionLog;

/**
 * Readonly result of a transaction log replay attempt.
 */
readonly class TransactionLogReplayResult
{
    public function __construct(
        public bool $ok,
        public string $classToken,
        public string $operation,
        public string $invocation,
        public string $resultText,
        public string $errorText
    ) {
    }
}
