<?php

declare(strict_types=1);

namespace DbTransactionLog;

use DbTransactionLog\Import\FileLogScanner;

/**
 * One-time conversion of a legacy transactions.log file into the DB table.
 *
 * Imported entries are stored verbatim (raw params text, extracted
 * class/operation, first email) with status 'ok' — the legacy file only ever
 * recorded attempted/successful operations, so no result code exists.
 */
final class FileLogImporter
{
    public function __construct(private TransactionLogger $logger)
    {
    }

    /**
     * @param callable(int): void|null $onProgress Called with the running
     *        imported-row count after each batch.
     * @return int Number of rows inserted.
     */
    public function importFile(string $path, int $batchSize = 500, ?callable $onProgress = null): int
    {
        $batchSize = max(1, $batchSize);
        $scanner = new FileLogScanner($path);

        $imported = 0;
        $batch = [];
        foreach ($scanner->iterateEntries() as $entry) {
            $batch[] = [
                'ts' => $entry->timestamp,
                'username' => $entry->username,
                'class' => $entry->classToken,
                'operation' => $entry->operation,
                'email' => $entry->firstEmail,
                'status' => 'ok',
                'error' => '',
                'params' => $entry->paramsText,
                'request' => '',
            ];
            if (count($batch) >= $batchSize) {
                $this->flush($batch);
                $imported += count($batch);
                $batch = [];
                if ($onProgress) {
                    $onProgress($imported);
                }
            }
        }
        if ($batch !== []) {
            $this->flush($batch);
            $imported += count($batch);
            if ($onProgress) {
                $onProgress($imported);
            }
        }
        return $imported;
    }

    /**
     * @param array<int, array<string, string>> $batch
     */
    private function flush(array $batch): void
    {
        foreach ($batch as $row) {
            $this->logger->insertRow($row);
        }
    }
}
