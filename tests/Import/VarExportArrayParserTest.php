<?php

declare(strict_types=1);

namespace DbTransactionLog\Tests\Import;

use DbTransactionLog\Import\TransactionLogParseException;
use DbTransactionLog\Import\VarExportArrayParser;
use PHPUnit\Framework\TestCase;

class VarExportArrayParserTest extends TestCase
{
    public function testParsesShortArraySyntax(): void
    {
        $p = new VarExportArrayParser();
        $this->assertSame(['a' => 'x', 'n' => 42, 'f' => 3.14, 't' => true, 'f2' => false, 'z' => null],
            $p->parse("['a' => 'x', 'n' => 42, 'f' => 3.14, 't' => true, 'f2' => false, 'z' => NULL]"));
    }

    public function testParsesLongArraySyntaxWithTrailingComma(): void
    {
        $p = new VarExportArrayParser();
        $this->assertSame(['k' => 'v', 'list' => [1, 2]], $p->parse("array (\n  'k' => 'v',\n  'list' => array (1, 2),\n)"));
    }

    public function testParsesEscapesAndLiteralNewlines(): void
    {
        $p = new VarExportArrayParser();
        $literal = "['s' => 'a\\\\b\\'c', 'm' => 'line1
line2',]";
        $this->assertSame(['s' => "a\\b'c", 'm' => "line1\nline2"], $p->parse($literal));
    }

    public function testEmptyAndNested(): void
    {
        $p = new VarExportArrayParser();
        $this->assertSame([], $p->parse('[]'));
        $this->assertSame([0 => 'zero', 'deep' => ['x' => [1]]], $p->parse("[0 => 'zero', 'deep' => ['x' => [1]],]"));
    }

    public function testMangledCloseParenInsideStringStaysVerbatim(): void
    {
        // Legacy log entries had ')' replaced by ']' inside string values;
        // the parser must not choke and must keep the mangled text as-is.
        $p = new VarExportArrayParser();
        $this->assertSame(['note' => 'a] b'], $p->parse("['note' => 'a] b']"));
    }

    public function testMalformedThrows(): void
    {
        $p = new VarExportArrayParser();
        $this->expectException(TransactionLogParseException::class);
        $this->expectExceptionMessageMatches('/offset \d+/');
        $p->parse("['a' => ]");
    }

    public function testMalformedNumberMinusOnlyThrows(): void
    {
        $p = new VarExportArrayParser();
        $this->expectException(TransactionLogParseException::class);
        $p->parse('[-]');
    }

    public function testParsesScientificNotationRoundTrip(): void
    {
        $p = new VarExportArrayParser();
        $original = ['f' => 1.0e20, 'small' => 2.5e-4, 'neg' => -3.14e+2];
        $exported = var_export($original, true);
        $this->assertSame($original, $p->parse($exported));
    }

    public function testArrayAsKeyThrowsParseException(): void
    {
        $p = new VarExportArrayParser();
        $this->expectException(TransactionLogParseException::class);
        $this->expectExceptionMessageMatches('/offset \d+/');
        $p->parse('[[] => 1]');
    }
}
