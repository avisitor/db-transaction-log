<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\TransactionLogStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class TransactionLogStoreTest extends TestCase
{
    /**
     * Mock PDO where each prepare() call pops the next seeded result.
     *
     * @param array<int, array{fetchAll?: array, fetchColumn?: mixed, rowCount?: int}> $seeded
     * @return array{0: PDO, 1: array, 2: array} pdo, captured SQLs, captured bind/execute info
     */
    private function mockPdo(array $seeded, &$sqls, &$binds): PDO
    {
        $sqls = [];
        $binds = [];
        $pdo = $this->createMock(PDO::class);
        $prepareFn = function (string $sql) use (&$seeded, &$sqls, &$binds) {
            $sqls[] = $sql;
            $seed = array_shift($seeded) ?? [];
            $idx = count($sqls) - 1;
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('bindValue')->willReturnCallback(function ($param, $value, $type = null) use (&$binds, $idx) {
                $binds[$idx][] = [$param, $value, $type];
                return true;
            });
            $stmt->method('execute')->willReturnCallback(function ($values = null) use (&$binds, $idx, $sql) {
                $binds[$idx][] = ['execute', $values, null];
                return true;
            });
            $stmt->method('fetchAll')->willReturn($seed['fetchAll'] ?? []);
            $stmt->method('fetch')->willReturn($seed['fetch'] ?? false);
            $stmt->method('fetchColumn')->willReturn($seed['fetchColumn'] ?? 0);
            $stmt->method('rowCount')->willReturn($seed['rowCount'] ?? 0);
            return $stmt;
        };
        $pdo->method('prepare')->willReturnCallback($prepareFn);
        $pdo->method('query')->willReturnCallback($prepareFn);
        return $pdo;
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => '9', 'ts' => '2026-10-30 10:00:00', 'username' => 'robw',
            'class' => 'Site', 'operation' => 'update', 'email' => 'a@b.com',
            'status' => 'ok', 'error' => null, 'params' => "[]", 'request' => '/x.php',
        ];
    }

    public function testQueryBuildsFiltersAndPaginates(): void
    {
        $seeded = [
            ['fetchColumn' => 100],                    // countAll
            ['fetchColumn' => 3],                      // count matching
            ['fetchAll' => [$this->row(['id' => '9']), $this->row(['id' => '8'])]], // page rows
            ['fetchAll' => ['placeOrder', 'update']],   // DISTINCT operation (FETCH_COLUMN)
            ['fetchAll' => ['PlantOrder', 'Site']],     // DISTINCT class (FETCH_COLUMN)
        ];
        $store = new TransactionLogStore(['pdo' => $this->mockPdo($seeded, $sqls, $binds)]);

        $result = $store->query(operation: 'update', email: 'a@b', classToken: 'Site', status: 'ok', from: '2026-01-01', to: '2026-12-31', page: 2, pageSize: 50);

        // countAll has no WHERE
        $this->assertStringNotContainsString('WHERE', $sqls[0]);
        // matching count has all filters
        $this->assertStringContainsString('`operation` = :operation', $sqls[1]);
        $this->assertStringContainsString('`class` = :class', $sqls[1]);
        $this->assertStringContainsString('`status` = :status', $sqls[1]);
        $this->assertStringContainsString('`ts` >= :from', $sqls[1]);
        $this->assertStringContainsString('`ts` <= :to', $sqls[1]);
        $this->assertStringContainsString('email LIKE :email1', $sqls[1]);
        $this->assertStringContainsString('params LIKE :email2', $sqls[1]);
        // page select ordered newest-first with int-bound limit/offset
        $this->assertStringContainsString('ORDER BY id DESC LIMIT :__limit OFFSET :__offset', $sqls[2]);

        $exec1 = $binds[1][0][1];
        $this->assertSame('update', $exec1[':operation']);
        $this->assertSame('Site', $exec1[':class']);
        $this->assertSame('ok', $exec1[':status']);
        $this->assertSame('2026-01-01', $exec1[':from']);
        $this->assertSame('2026-12-31', $exec1[':to']);
        $this->assertSame('%a@b%', $exec1[':email1']);
        $this->assertSame('%a@b%', $exec1[':email2']);

        $limitBind = null;
        $offsetBind = null;
        foreach ($binds[2] as $b) {
            if ($b[0] === ':__limit') { $limitBind = $b; }
            if ($b[0] === ':__offset') { $offsetBind = $b; }
        }
        $this->assertSame([50, PDO::PARAM_INT], [$limitBind[1], $limitBind[2]]);
        $this->assertSame([50, PDO::PARAM_INT], [$offsetBind[1], $offsetBind[2]]); // page 2 -> offset 50

        $this->assertSame(100, $result->totalEntries);
        $this->assertSame(3, $result->totalMatching);
        $this->assertSame(2, $result->page);
        $this->assertSame(50, $result->pageSize);
        $this->assertCount(2, $result->entries);
        $this->assertSame(9, $result->entries[0]->id);
        $this->assertSame('Site', $result->entries[0]->classToken);
        $this->assertSame(['placeOrder', 'update'], $result->distinctOperations);
        $this->assertSame(['PlantOrder', 'Site'], $result->distinctClasses);
        $this->assertSame($result->entries, $result->pageEntries());
    }

    public function testQueryWithoutFiltersSkipsMatchingCount(): void
    {
        $seeded = [
            ['fetchColumn' => 7],           // countAll
            ['fetchAll' => [$this->row()]], // page rows (no separate matching count)
            ['fetchAll' => ['update']],
            ['fetchAll' => ['Site']],
        ];
        $store = new TransactionLogStore(['pdo' => $this->mockPdo($seeded, $sqls, $binds)]);
        $result = $store->query();
        $this->assertSame(7, $result->totalEntries);
        $this->assertSame(7, $result->totalMatching);
        $this->assertCount(1, $result->entries);
    }

    public function testEmailLikeEscapesWildcards(): void
    {
        $seeded = [
            ['fetchColumn' => 0],
            ['fetchColumn' => 0],
            ['fetchAll' => []],
            ['fetchAll' => []],
            ['fetchAll' => []],
        ];
        $store = new TransactionLogStore(['pdo' => $this->mockPdo($seeded, $sqls, $binds)]);
        $store->query(email: '50%_x\\y');
        $exec = $binds[1][0][1];
        $this->assertSame('%50\\%\\_x\\\\y%', $exec[':email1']);
    }

    public function testGetByIdReturnsEntryOrNull(): void
    {
        $seeded = [
            ['fetch' => $this->row(['id' => '5', 'status' => 'error', 'error' => 'boom'])],
        ];
        $store = new TransactionLogStore(['pdo' => $this->mockPdo($seeded, $sqls, $binds)]);

        $entry = $store->getById(5);
        $this->assertNotNull($entry);
        $this->assertSame(5, $entry->id);
        $this->assertSame('error', $entry->status);
        $this->assertSame('boom', $entry->error);
        $this->assertStringContainsString('WHERE `id` = :id', $sqls[0]);

        $store2 = new TransactionLogStore(['pdo' => $this->mockPdo([['fetch' => false]], $s2, $b2)]);
        $this->assertNull($store2->getById(999));
    }

    public function testCountAll(): void
    {
        $store = new TransactionLogStore(['pdo' => $this->mockPdo([['fetchColumn' => 42]], $sqls, $binds)]);
        $this->assertSame(42, $store->countAll());
        $this->assertStringContainsString('COUNT(*)', $sqls[0]);
    }

    public function testRejectsInvalidTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransactionLogStore(['pdo' => $this->createMock(PDO::class), 'table' => 'x; DROP TABLE y']);
    }
}
