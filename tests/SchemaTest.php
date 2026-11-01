<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests;

use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    public function testSchemaCreatesTableOnlyWhenMissing(): void
    {
        $sql = (string)file_get_contents(__DIR__ . '/../schema/transactions.sql');

        $this->assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+`?transactions`?/i',
            $sql,
            'schema must create the table when missing'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/DROP TABLE/i',
            $sql,
            'schema must never destroy recorded history'
        );
    }
}
