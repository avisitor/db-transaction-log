<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\FileLogImporter;
use DbTransactionLog\TransactionLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class FileLogImporterTest extends TestCase
{
    private function mockPdo(array &$valuesList): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$valuesList) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($values = null) use (&$valuesList, $sql) {
                $valuesList[] = [$sql, $values];
                return true;
            });
            $stmt->method('rowCount')->willReturn(1);
            return $stmt;
        });
        $pdo->method('lastInsertId')->willReturn('1');
        return $pdo;
    }

    private function fixture(): string
    {
        return __DIR__ . '/fixtures/sample-transactions.log';
    }

    public function testImportFileInsertsEveryEntryWithOkStatusAndVerbatimParams(): void
    {
        $valuesList = [];
        $logger = new TransactionLogger([
            'enabled' => true,
            'table' => 'transactions',
            'pdo' => $this->mockPdo($valuesList),
            'pruneProbability' => 0.0,
        ]);

        $imported = (new FileLogImporter($logger))->importFile($this->fixture(), batchSize: 2);

        $this->assertSame(3, $imported);
        $this->assertCount(3, $valuesList);

        [$sql, $v0] = $valuesList[0];
        $this->assertStringContainsString('INSERT INTO `transactions`', $sql);
        $this->assertSame('2025-11-02 19:31:49', $v0[':ts']);
        $this->assertSame('robw', $v0[':username']);
        $this->assertSame('Site', $v0[':class']);
        $this->assertSame('update', $v0[':operation']);
        $this->assertSame('test@example.com', $v0[':email']);
        $this->assertSame('ok', $v0[':status']);
        $this->assertStringContainsString("'sitename' => 'Test'", $v0[':params']);

        [, $v1] = $valuesList[1];
        $this->assertSame('DBPlantOrder', $v1[':class']);
        $this->assertSame('placeOrder', $v1[':operation']);
    }

    public function testImportFileReportsProgress(): void
    {
        $valuesList = [];
        $logger = new TransactionLogger([
            'enabled' => true,
            'pdo' => $this->mockPdo($valuesList),
            'pruneProbability' => 0.0,
        ]);
        $progress = [];
        $imported = (new FileLogImporter($logger))->importFile($this->fixture(), batchSize: 2, onProgress: function (int $n) use (&$progress) {
            $progress[] = $n;
        });
        $this->assertSame(3, $imported);
        $this->assertSame([2, 3], $progress);
    }

    public function testImportMissingFileReturnsZero(): void
    {
        $valuesList = [];
        $logger = new TransactionLogger([
            'enabled' => true,
            'pdo' => $this->mockPdo($valuesList),
            'pruneProbability' => 0.0,
        ]);
        $this->assertSame(0, (new FileLogImporter($logger))->importFile('/nonexistent.log'));
        $this->assertSame([], $valuesList);
    }
}
