<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\LegacyEventLogImporter;
use DbTransactionLog\TransactionLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class LegacyEventLogImporterTest extends TestCase
{
    /**
     * Build a PDO double that serves: the information_schema existence check,
     * the provenance lookup, the source SELECT, and the target INSERTs.
     *
     * @param array<int, array<string, mixed>> $sourceRows
     * @param array<int, string> $existingMarkers
     * @param array<int, array<string, mixed>> $inserted  Receives inserted values
     */
    private function mockPdo(
        array $sourceRows,
        array $existingMarkers,
        array &$inserted,
        bool $sourceExists = true
    ): PDO {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($existingMarkers, &$inserted, $sourceExists) {
                $stmt = $this->createMock(PDOStatement::class);

                if (str_contains($sql, 'information_schema.tables')) {
                    $stmt->method('execute')->willReturn(true);
                    $stmt->method('fetchColumn')->willReturn($sourceExists ? '1' : '0');
                } elseif (str_contains($sql, '`request` LIKE')) {
                    $stmt->method('execute')->willReturn(true);
                    $values = $existingMarkers;
                    $stmt->method('fetchColumn')->willReturnCallback(
                        function () use (&$values) {
                            return $values === [] ? false : array_shift($values);
                        }
                    );
                } else {
                    $stmt->method('execute')->willReturnCallback(
                        function ($values = null) use (&$inserted) {
                            $inserted[] = $values;
                            return true;
                        }
                    );
                }

                return $stmt;
            }
        );
        $pdo->method('lastInsertId')->willReturn('1');

        $pdo->method('query')->willReturnCallback(
            function (string $sql) use ($sourceRows) {
                $stmt = $this->createMock(PDOStatement::class);
                $rows = $sourceRows;
                $stmt->method('fetch')->willReturnCallback(
                    function () use (&$rows) {
                        return $rows === [] ? false : array_shift($rows);
                    }
                );
                return $stmt;
            }
        );

        return $pdo;
    }

    /** @param array<int, array<string, mixed>> $sourceRows */
    private function loggerFor(array $sourceRows, array $existingMarkers, array &$inserted): TransactionLogger
    {
        return new TransactionLogger([
            'enabled' => true,
            'table' => 'transactions',
            'pdo' => $this->mockPdo($sourceRows, $existingMarkers, $inserted),
            'pruneProbability' => 0.0,
        ]);
    }

    public function testImportsRowsWithMappedClassAliasAndProvenance(): void
    {
        $inserted = [];
        $rows = [
            ['src_id' => '7', 'src_ts' => '2021-09-12 03:12:15', 'src_type' => 'signup',
                'src_email' => 'a@example.com', 'src_username' => 'a@example.com', 'src_detail' => "'x' => 1"],
            ['src_id' => '8', 'src_ts' => '2021-09-13 04:00:00', 'src_type' => 'UngetNotificatio',
                'src_email' => 'b@example.com', 'src_username' => 'b@example.com', 'src_detail' => ''],
        ];

        $importer = new LegacyEventLogImporter($this->loggerFor($rows, [], $inserted), [
            'classByType' => ['signup' => 'Hikes', 'ungetnotificatio' => 'User'],
            'operationAliases' => ['ungetnotificatio' => 'ungetnotifications'],
        ]);
        $summary = $importer->import();

        $this->assertSame(['imported' => 2, 'skipped' => 0, 'lastId' => 8], $summary);

        $this->assertSame('2021-09-12 03:12:15', $inserted[0][':ts']);
        $this->assertSame('Hikes', $inserted[0][':class']);
        $this->assertSame('signup', $inserted[0][':operation']);
        $this->assertSame('a@example.com', $inserted[0][':username']);
        $this->assertSame('a@example.com', $inserted[0][':email']);
        $this->assertSame('ok', $inserted[0][':status']);
        $this->assertSame("'x' => 1", $inserted[0][':params']);
        $this->assertSame('eventlog:7', $inserted[0][':request']);

        $this->assertSame('User', $inserted[1][':class']);
        $this->assertSame('ungetnotifications', $inserted[1][':operation']);
        $this->assertSame('eventlog:8', $inserted[1][':request']);
    }

    public function testSkipsRowsAlreadyPresentByProvenanceMarker(): void
    {
        $inserted = [];
        $rows = [
            ['src_id' => '5', 'src_ts' => '2021-01-01 00:00:00', 'src_type' => 'signup',
                'src_email' => 'a@example.com', 'src_username' => 'a@example.com', 'src_detail' => ''],
        ];

        $importer = new LegacyEventLogImporter(
            $this->loggerFor($rows, ['eventlog:5'], $inserted),
            ['classByType' => ['signup' => 'Hikes']]
        );
        $summary = $importer->import();

        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame([], $inserted);
    }

    public function testUnmappedTypeUsesDefaultClass(): void
    {
        $inserted = [];
        $rows = [
            ['src_id' => '9', 'src_ts' => '2021-01-01 00:00:00', 'src_type' => 'mystery',
                'src_email' => 'a@example.com', 'src_username' => 'a@example.com', 'src_detail' => ''],
        ];

        $importer = new LegacyEventLogImporter(
            $this->loggerFor($rows, [], $inserted),
            ['defaultClass' => 'Legacy']
        );
        $importer->import();

        $this->assertSame('Legacy', $inserted[0][':class']);
        $this->assertSame('mystery', $inserted[0][':operation']);
    }

    public function testMissingSourceTableThrows(): void
    {
        $inserted = [];
        $logger = new TransactionLogger([
            'enabled' => true,
            'table' => 'transactions',
            'pdo' => $this->mockPdo([], [], $inserted, false),
            'pruneProbability' => 0.0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        (new LegacyEventLogImporter($logger))->import();
    }

    public function testReportsProgressPerBatch(): void
    {
        $inserted = [];
        $rows = [];
        foreach (range(1, 5) as $i) {
            $rows[] = ['src_id' => (string)$i, 'src_ts' => '2021-01-01 00:00:00', 'src_type' => 'signup',
                'src_email' => 'a@example.com', 'src_username' => 'a@example.com', 'src_detail' => ''];
        }

        $progress = [];
        $importer = new LegacyEventLogImporter(
            $this->loggerFor($rows, [], $inserted),
            ['classByType' => ['signup' => 'Hikes'], 'batchSize' => 2]
        );
        $importer->import(function (int $imported, int $skipped, int $lastId) use (&$progress) {
            $progress[] = [$imported, $skipped, $lastId];
        });

        $this->assertSame([[2, 0, 2], [4, 0, 4], [5, 0, 5]], $progress);
    }
}
