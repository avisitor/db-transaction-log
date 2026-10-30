<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\TransactionLogger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Live-DB integration test. Skips unless DB_HOST / DB_USERNAME / DB_PASSWORD /
 * DB_DATABASE env vars are set (point them at a scratch database — the test
 * creates and drops the `transactions` table in it).
 */
class TransactionLoggerLiveTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        $user = getenv('DB_USERNAME');
        $pass = getenv('DB_PASSWORD');
        $db = getenv('DB_DATABASE');
        if (!$host || !$user || $pass === false || !$db) {
            $this->markTestSkipped('DB_* env vars not set');
        }
        $socket = getenv('DB_SOCKET') ?: null;
        $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ($socket ? ';unix_socket=' . $socket : ';port=' . (getenv('DB_PORT') ?: '3306'));
        $this->pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(__DIR__ . '/../schema/transactions.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            $this->pdo->exec($stmt);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->pdo->exec('DROP TABLE IF EXISTS `transactions`');
        }
        TransactionLogger::resetDefault();
    }

    private function makeLogger(array $extra = []): TransactionLogger
    {
        return new TransactionLogger($extra + [
            'enabled' => true,
            'table' => 'transactions',
            'pdo' => $this->pdo,
            'now' => fn() => '2026-10-30 12:00:00',
            'username' => fn() => 'tester',
            'pruneProbability' => 0.0,
        ]);
    }

    public function testLogAndReadBackRoundTrip(): void
    {
        $logger = $this->makeLogger();
        $logger->log('Site', 'update', ['sitename' => 'Live', 'owneremail' => 'live@example.com'], 'ok');
        $logger->log('PlantOrder', 'placeOrder', ['eventid' => 'e9'], 'error', 'duplicate entry');

        $rows = $this->pdo->query('SELECT * FROM `transactions` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('Site', $rows[0]['class']);
        $this->assertSame('update', $rows[0]['operation']);
        $this->assertSame('live@example.com', $rows[0]['email']);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame('2026-10-30 12:00:00', $rows[0]['ts']);
        $this->assertSame(var_export(['sitename' => 'Live', 'owneremail' => 'live@example.com'], true), $rows[0]['params']);
        $this->assertSame('error', $rows[1]['status']);
        $this->assertSame('duplicate entry', $rows[1]['error']);
    }

    public function testDeleteOlderThanRemovesOnlyOldRows(): void
    {
        $logger = $this->makeLogger();
        $this->pdo->exec("INSERT INTO `transactions` (`ts`,`class`,`operation`,`status`) VALUES ('2020-01-01 00:00:00','Site','update','ok')");
        $this->pdo->exec("INSERT INTO `transactions` (`ts`,`class`,`operation`,`status`) VALUES ('2026-10-29 00:00:00','Site','update','ok')");

        $deleted = $logger->deleteOlderThan(365); // cutoff 2025-10-30 12:00:00

        $this->assertSame(1, $deleted);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) c FROM `transactions`')->fetch(PDO::FETCH_ASSOC)['c']);
    }
}
