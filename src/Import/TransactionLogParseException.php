<?php

declare(strict_types=1);

namespace DbTransactionLog\Import;

/**
 * Exception thrown when parsing transaction log data fails.
 * Includes the offset in the literal where the error occurred.
 */
class TransactionLogParseException extends \RuntimeException
{
    public function __construct(string $message, int $offset, ?\Throwable $previous = null)
    {
        parent::__construct($message . " at offset {$offset}", 0, $previous);
    }
}
