<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\TransactionLogger;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class FakeDaoForLogCaller
{
    public function __construct(private TransactionLogger $logger)
    {
    }

    public function update($params): void
    {
        $this->logger->logCaller($params, 'ok');
    }
}

class TransactionLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        TransactionLogger::resetDefault();
    }

    /**
     * @return array{0: PDO, 1: array, 2: array} [pdo mock, captured SQL list, captured values list]
     */
    private function mockPdo(array &$sqls = [], array &$valuesList = [], int $rowCount = 1, string $lastId = '42'): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$sqls, &$valuesList, $rowCount) {
            $sqls[] = $sql;
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($values = null) use (&$valuesList, $sql) {
                $valuesList[] = [$sql, $values];
                return true;
            });
            $stmt->method('rowCount')->willReturn($rowCount);
            return $stmt;
        });
        $pdo->method('lastInsertId')->willReturn($lastId);
        return $pdo;
    }

    private function makeLogger(PDO $pdo, array $extra = []): TransactionLogger
    {
        return new TransactionLogger($extra + [
            'enabled' => true,
            'table' => 'transactions',
            'pdo' => $pdo,
            'now' => fn() => '2026-10-30 12:00:00',
            'username' => fn() => 'robw',
            'request' => fn() => '/planting/sites/update.php',
            'pruneProbability' => 0.0,
        ]);
    }

    public function testDisabledByDefaultWritesNothing(): void
    {
        $sqls = [];
        $logger = new TransactionLogger(['pdo' => $this->mockPdo($sqls)]);
        $this->assertFalse($logger->isEnabled());
        $logger->log('Site', 'update', ['a' => 1]);
        $this->assertSame([], $sqls);
    }

    public function testLogInsertsRowWithExtractedEmailAndVerbatimParams(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList));

        $logger->log('Site', 'update', ['sitename' => 'X', 'email' => 'a@b.com'], 'ok');

        $this->assertCount(1, $sqls);
        $this->assertStringContainsString('INSERT INTO `transactions`', $sqls[0]);
        $this->assertStringContainsString('`class`', $sqls[0]);
        [$sql, $values] = $valuesList[0];
        $this->assertSame('2026-10-30 12:00:00', $values[':ts']);
        $this->assertSame('robw', $values[':username']);
        $this->assertSame('Site', $values[':class']);
        $this->assertSame('update', $values[':operation']);
        $this->assertSame('a@b.com', $values[':email']);
        $this->assertSame('ok', $values[':status']);
        $this->assertSame('', $values[':error']);
        $this->assertSame(var_export(['sitename' => 'X', 'email' => 'a@b.com'], true), $values[':params']);
        $this->assertSame('/planting/sites/update.php', $values[':request']);
    }

    public function testLogStoresErrorStatusAndMessage(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList));
        $logger->log('PlantOrder', 'placeOrder', ['eventid' => 'e1'], 'error', 'duplicate key');
        [, $values] = $valuesList[0];
        $this->assertSame('error', $values[':status']);
        $this->assertSame('duplicate key', $values[':error']);
    }

    public function testInsertFailureNeverThrows(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('db gone'));
        $logger = $this->makeLogger($pdo);

        $logger->log('Site', 'update', ['a' => 1], 'ok');

        $this->assertNull($logger->insertRow([
            'ts' => '2026-10-30 12:00:00', 'username' => 'u', 'class' => 'Site',
            'operation' => 'update', 'email' => '', 'status' => 'ok', 'error' => '',
            'params' => '[]', 'request' => '',
        ]));
        $this->assertSame(0, $logger->deleteOlderThan(365)); // also swallows
    }

    public function testLogCallerExtractsClassTokenAndOperationFromBacktrace(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList));
        $dao = new FakeDaoForLogCaller($logger);
        $dao->update(['id' => 'x1']);

        [, $values] = $valuesList[0];
        $this->assertSame('FakeDaoForLogCaller', $values[':class']);
        $this->assertSame('update', $values[':operation']);
    }

    public function testLogCallerStripsLeadingDbPrefixFromClassToken(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList));
        $dao = new class($logger) {
            public function __construct(private TransactionLogger $logger) {}
            public function placeOrder($p): void { $this->logger->logCaller($p); }
        };
        // anonymous class short name contains "class@anonymous" — use a named class instead
        $named = new DBNamedDao($logger);
        $named->placeOrder(['e' => 1]);

        [, $values] = $valuesList[0];
        $this->assertSame('NamedDao', $values[':class']);
        $this->assertSame('placeOrder', $values[':operation']);
    }

    public function testDeleteOlderThanUsesRetentionCutoff(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList, rowCount: 17));

        $deleted = $logger->deleteOlderThan(365);

        $this->assertSame(17, $deleted);
        $this->assertStringContainsString('DELETE FROM `transactions`', $sqls[0]);
        $this->assertStringContainsString('`ts` < :cutoff', $sqls[0]);
        [, $values] = $valuesList[0];
        $this->assertSame('2025-10-30 12:00:00', $values[':cutoff']);
    }

    public function testDeleteOlderThanDefaultsToConfiguredRetention(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList), ['retentionDays' => 30]);
        $logger->deleteOlderThan();
        [, $values] = $valuesList[0];
        $this->assertSame('2026-09-30 12:00:00', $values[':cutoff']);
    }

    public function testMaybePruneRunsWhenProbabilityIsOne(): void
    {
        $sqls = [];
        $logger = $this->makeLogger($this->mockPdo($sqls), ['pruneProbability' => 1.0, 'retentionDays' => 365]);
        $logger->maybePrune();
        $this->assertCount(1, $sqls);
        $this->assertStringContainsString('DELETE', $sqls[0]);
    }

    public function testMaybePruneSkipsWhenProbabilityIsZero(): void
    {
        $sqls = [];
        $logger = $this->makeLogger($this->mockPdo($sqls), ['pruneProbability' => 0.0]);
        $logger->maybePrune();
        $this->assertSame([], $sqls);
    }

    public function testLogTriggersMaybePrune(): void
    {
        $sqls = [];
        $logger = $this->makeLogger($this->mockPdo($sqls), ['pruneProbability' => 1.0]);
        $logger->log('Site', 'update', ['a' => 1]);
        $this->assertCount(2, $sqls); // INSERT then DELETE
        $this->assertStringContainsString('INSERT', $sqls[0]);
        $this->assertStringContainsString('DELETE', $sqls[1]);
    }

    public function testConfigureAndGetDefault(): void
    {
        $this->assertNull(TransactionLogger::getDefault());
        $logger = $this->makeLogger($this->mockPdo());
        TransactionLogger::configure(['enabled' => true, 'pdo' => $this->mockPdo()]);
        $this->assertInstanceOf(TransactionLogger::class, TransactionLogger::getDefault());
        $this->assertTrue(TransactionLogger::getDefault()->isEnabled());
        TransactionLogger::resetDefault();
        $this->assertNull(TransactionLogger::getDefault());
    }

    public function testRejectsInvalidTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransactionLogger(['enabled' => true, 'table' => 'transactions; DROP TABLE users', 'pdo' => $this->mockPdo()]);
    }

    public function testLogNeverThrowsEvenWhenConfigCallablesThrow(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList), [
            'now' => function () { throw new \RuntimeException('bad timezone'); },
        ]);

        $logger->log('Site', 'update', ['a' => 1], 'ok'); // must not throw

        $this->assertSame([], $sqls); // nothing inserted, but no exception escaped
    }

    public function testOverLongColumnValuesAreTruncatedToSchemaLimits(): void
    {
        $sqls = [];
        $valuesList = [];
        $logger = $this->makeLogger($this->mockPdo($sqls, $valuesList), [
            'username' => fn() => str_repeat('u', 200),
            'request' => fn() => str_repeat('r', 500),
        ]);

        $longEmail = str_repeat('a', 120) . '@' . str_repeat('b', 120) . '.com';
        $logger->log(str_repeat('C', 100), str_repeat('o', 100), ['email' => $longEmail], str_repeat('s', 30), str_repeat('e', 70000));

        $this->assertCount(1, $sqls);
        [, $values] = $valuesList[0];
        $this->assertLessThanOrEqual(64, strlen($values[':username']));
        $this->assertLessThanOrEqual(255, strlen($values[':request']));
        $this->assertLessThanOrEqual(64, strlen($values[':class']));
        $this->assertLessThanOrEqual(64, strlen($values[':operation']));
        $this->assertLessThanOrEqual(128, strlen($values[':email']));
        $this->assertLessThanOrEqual(16, strlen($values[':status']));
        $this->assertLessThanOrEqual(65535, strlen($values[':error']));
    }

    public function testConnectionIsResolvedLazilyPerCall(): void
    {
        $sqls = [];
        $valuesList = [];
        $calls = 0;
        $pdo = $this->mockPdo($sqls, $valuesList);
        // First resolution returns null (connection not yet set up), second returns the pdo
        $logger = new TransactionLogger([
            'enabled' => true,
            'table' => 'transactions',
            'pdoProvider' => function () use (&$calls, $pdo) {
                $calls++;
                return $calls >= 2 ? $pdo : null;
            },
            'now' => fn() => '2026-10-30 12:00:00',
            'pruneProbability' => 0.0,
        ]);

        $logger->log('Site', 'update', ['a' => 1]); // no connection yet -> no-op
        $this->assertSame([], $sqls);
        $logger->log('Site', 'update', ['a' => 2]); // connection now available -> insert
        $this->assertCount(1, $sqls);
        [, $values] = $valuesList[0];
        $this->assertSame(var_export(['a' => 2], true), $values[':params']);
    }
}

class DBNamedDao
{
    public function __construct(private TransactionLogger $logger)
    {
    }

    public function placeOrder($p): void
    {
        $this->logger->logCaller($p);
    }
}
