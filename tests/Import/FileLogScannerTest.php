<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests\Import;

use DbTransactionLog\Import\FileLogScanner;
use PHPUnit\Framework\TestCase;

class FileLogScannerTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/../fixtures/sample-transactions.log';
    }

    public function testIterateEntriesYieldsAllEntries(): void
    {
        $entries = iterator_to_array((new FileLogScanner($this->fixture))->iterateEntries(), false);
        $this->assertCount(3, $entries);
    }

    public function testEntryFieldsExtracted(): void
    {
        $entries = iterator_to_array((new FileLogScanner($this->fixture))->iterateEntries(), false);

        $this->assertSame('2025-11-02 19:31:49', $entries[0]->timestamp);
        $this->assertSame('robw', $entries[0]->username);
        $this->assertSame('Site', $entries[0]->classToken);
        $this->assertSame('update', $entries[0]->operation);
        $this->assertSame('test@example.com', $entries[0]->firstEmail);
        $this->assertStringContainsString("'sitename' => 'Test'", $entries[0]->paramsText);
        $this->assertStringContainsString('$s = new Site();', $entries[0]->raw);

        // Concatenated class+method call token must resolve to the bare method.
        $this->assertSame('DBPlantOrder', $entries[1]->classToken);
        $this->assertSame('placeOrder', $entries[1]->operation);
        $this->assertSame('', $entries[1]->firstEmail);

        // Long array(...) syntax and empty username.
        $this->assertSame('RecurringEvent', $entries[2]->classToken);
        $this->assertSame('update', $entries[2]->operation);
        $this->assertSame('', $entries[2]->username);
        $this->assertStringContainsString("'frequency' => 'weekly'", $entries[2]->paramsText);
    }

    public function testCountEntries(): void
    {
        $this->assertSame(3, (new FileLogScanner($this->fixture))->countEntries());
    }

    public function testMissingFileYieldsNothing(): void
    {
        $scanner = new FileLogScanner('/nonexistent/transactions.log');
        $this->assertSame([], iterator_to_array($scanner->iterateEntries(), false));
        $this->assertSame(0, $scanner->countEntries());
    }

    public function testExtractOperationStripsKnownClassTokens(): void
    {
        $this->assertSame('update', FileLogScanner::extractOperation('DBSiteupdate'));
        $this->assertSame('placeOrder', FileLogScanner::extractOperation('DBPlantOrderplaceOrder'));
        $this->assertSame('update', FileLogScanner::extractOperation('update'));
        $this->assertSame('changeDate', FileLogScanner::extractOperation('RetreeSites\\DB\\DBPlantOrderchangeDate'));
    }
}
