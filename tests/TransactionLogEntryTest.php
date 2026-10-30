<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use DbTransactionLog\TransactionLogEntry;
use PHPUnit\Framework\TestCase;

class TransactionLogEntryTest extends TestCase
{
    public function testFromRowMapsColumnsIncludingClassColumn(): void
    {
        $entry = TransactionLogEntry::fromRow([
            'id' => '42',
            'ts' => '2026-10-30 12:34:56',
            'username' => 'robw',
            'class' => 'Site',
            'operation' => 'update',
            'email' => 'a@b.com',
            'status' => 'ok',
            'error' => null,
            'params' => "array (\n  'sitename' => 'X',\n)",
            'request' => '/planting/sites/update.php',
        ]);

        $this->assertSame(42, $entry->id);
        $this->assertSame('2026-10-30 12:34:56', $entry->ts);
        $this->assertSame('robw', $entry->username);
        $this->assertSame('Site', $entry->classToken);
        $this->assertSame('update', $entry->operation);
        $this->assertSame('a@b.com', $entry->email);
        $this->assertSame('ok', $entry->status);
        $this->assertSame('', $entry->error);
        $this->assertSame("array (\n  'sitename' => 'X',\n)", $entry->params);
        $this->assertSame('/planting/sites/update.php', $entry->request);
    }

    public function testRawReconstructsFileStyleEntry(): void
    {
        $entry = new TransactionLogEntry(
            7,
            '2026-10-30 01:02:03',
            'robw',
            'PlantOrder',
            'placeOrder',
            'x@y.org',
            'error',
            'boom',
            "[\n  'eventid' => 'e1',\n]",
            '/planting/sites/orders.php',
        );

        $raw = $entry->raw();

        $this->assertStringContainsString('// 2026-10-30 01:02:03 robw', $raw);
        $this->assertStringContainsString('$s = new PlantOrder();', $raw);
        $this->assertStringContainsString('$s->PlantOrderplaceOrder(', $raw);
        $this->assertStringContainsString("'eventid' => 'e1'", $raw);
    }

    public function testRawOmitsUsernameWhenEmpty(): void
    {
        $entry = new TransactionLogEntry(1, '2026-01-01 00:00:00', '', 'Site', 'update', '', 'ok', '', '[]', '');
        $this->assertStringStartsWith('// 2026-01-01 00:00:00' . "\n", $entry->raw());
    }
}
