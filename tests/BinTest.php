<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use PHPUnit\Framework\TestCase;

class BinTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testImportBinDryRunCountsFixtureEntries(): void
    {
        $fixture = $this->root . '/tests/fixtures/sample-transactions.log';
        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($this->root . '/bin/import-transaction-log') . ' --file=' . escapeshellarg($fixture) . ' --dry-run 2>&1', $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('3 entries found', $text);
        $this->assertStringContainsString('Dry run', $text);
    }

    public function testImportBinFailsOnMissingFile(): void
    {
        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($this->root . '/bin/import-transaction-log') . ' --file=/nonexistent.log 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('File not found', implode("\n", $out));
    }

    public function testImportBinUsageWithoutFile(): void
    {
        $out = [];
        $code = 0;
        exec('php ' . escapeshellarg($this->root . '/bin/import-transaction-log') . ' 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage:', implode("\n", $out));
    }
}
