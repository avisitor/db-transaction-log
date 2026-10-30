<?php

declare(strict_types=1);

namespace DbTransactionLog\Import;

/**
 * Parses var_export-style array literals into PHP arrays.
 *
 * Single-pass recursive-descent scanner that handles:
 * - [...] and array(...) syntax
 * - => for key-value pairs
 * - Single-quoted strings with \' and \\ escapes (literal newlines allowed)
 * - Integers and floats
 * - Keywords: NULL, true, false
 * - Trailing commas
 * - Nested arrays
 *
 * No eval is used.
 */
class VarExportArrayParser
{
    private string $input;
    private int $pos;
    private int $len;

    /**
     * Parse a var_export array literal string into a PHP array.
     *
     * @throws TransactionLogParseException If the literal is malformed
     */
    public function parse(string $literal): array
    {
        $this->input = $literal;
        $this->pos = 0;
        $this->len = strlen($literal);

        $this->skipWhitespace();
        $result = $this->parseArray();
        $this->skipWhitespace();

        if ($this->pos < $this->len) {
            throw new TransactionLogParseException(
                "Unexpected content after array",
                $this->pos
            );
        }

        return $result;
    }

    private function parseArray(): array
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->len) {
            throw new TransactionLogParseException(
                "Unexpected end of input while parsing array",
                $this->pos
            );
        }

        // Determine array syntax style
        if ($this->matchKeyword('array')) {
            $this->skipWhitespace();
            if (!$this->matchChar('(')) {
                throw new TransactionLogParseException(
                    "Expected '(' after 'array'",
                    $this->pos
                );
            }
            return $this->parseArrayBody(')');
        } elseif ($this->matchChar('[')) {
            return $this->parseArrayBody(']');
        } else {
            throw new TransactionLogParseException(
                "Expected '[' or 'array'",
                $this->pos
            );
        }
    }

    private function parseArrayBody(string $closeChar): array
    {
        $result = [];
        $this->skipWhitespace();

        // Empty array
        if ($this->peekChar() === $closeChar) {
            $this->advance();
            return $result;
        }

        // Parse elements
        while (true) {
            $this->skipWhitespace();

            if ($this->pos >= $this->len) {
                throw new TransactionLogParseException(
                    "Unexpected end of input in array",
                    $this->pos
                );
            }

            // Check for closing bracket
            if ($this->peekChar() === $closeChar) {
                $this->advance();
                break;
            }

            // Parse key or value
            $keyStart = $this->pos;
            $firstValue = $this->parseValue();

            $this->skipWhitespace();

            // Check if this is a key-value pair
            if ($this->matchString('=>')) {
                if (is_array($firstValue)) {
                    throw new TransactionLogParseException(
                        "Invalid array key: array type is not allowed as key",
                        $keyStart
                    );
                }
                $this->skipWhitespace();
                $value = $this->parseValue();
                $result[$firstValue] = $value;
            } else {
                // Just a value (numeric index)
                $result[] = $firstValue;
            }

            $this->skipWhitespace();

            // Check for comma or closing bracket
            if ($this->matchChar(',')) {
                // Continue to next element (allows trailing comma)
                continue;
            } elseif ($this->peekChar() === $closeChar) {
                $this->advance();
                break;
            } else {
                throw new TransactionLogParseException(
                    "Expected ',' or closing bracket",
                    $this->pos
                );
            }
        }

        return $result;
    }

    private function parseValue(): int|float|string|bool|null|array
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->len) {
            throw new TransactionLogParseException(
                "Unexpected end of input while parsing value",
                $this->pos
            );
        }

        $char = $this->peekChar();

        // String
        if ($char === "'") {
            return $this->parseString();
        }

        // Array (short syntax)
        if ($char === '[') {
            return $this->parseArray();
        }

        // Number (starts with digit or minus)
        if (ctype_digit($char) || $char === '-') {
            return $this->parseNumber();
        }

        // Keywords
        if (ctype_alpha($char)) {
            // Check for 'array' keyword
            if ($this->matchKeyword('array')) {
                $this->skipWhitespace();
                if (!$this->matchChar('(')) {
                    throw new TransactionLogParseException(
                        "Expected '(' after 'array'",
                        $this->pos
                    );
                }
                return $this->parseArrayBody(')');
            }
            // Other keywords: NULL, true, false
            return $this->parseKeyword();
        }

        throw new TransactionLogParseException(
            "Unexpected character '{$char}'",
            $this->pos
        );
    }

    private function parseString(): string
    {
        if (!$this->matchChar("'")) {
            throw new TransactionLogParseException(
                "Expected opening quote",
                $this->pos
            );
        }

        $result = '';

        while ($this->pos < $this->len) {
            $char = $this->input[$this->pos];

            if ($char === '\\') {
                // Escape sequence
                $this->advance();
                if ($this->pos >= $this->len) {
                    throw new TransactionLogParseException(
                        "Unexpected end of input in string escape",
                        $this->pos
                    );
                }
                $nextChar = $this->input[$this->pos];
                if ($nextChar === '\\' || $nextChar === "'") {
                    // Recognized escape
                    $result .= $nextChar;
                    $this->advance();
                } else {
                    // Unknown escape, keep as-is (PHP behavior for single quotes)
                    $result .= '\\' . $nextChar;
                    $this->advance();
                }
            } elseif ($char === "'") {
                // End of string
                $this->advance();
                return $result;
            } else {
                // Regular character (including literal newlines)
                $result .= $char;
                $this->advance();
            }
        }

        throw new TransactionLogParseException(
            "Unterminated string",
            $this->pos
        );
    }

    private function parseNumber(): int|float
    {
        $start = $this->pos;

        // Optional minus sign
        if ($this->peekChar() === '-') {
            $this->advance();
        }

        // Integer part (zero or more digits)
        $hasIntPart = false;
        while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
            $this->advance();
            $hasIntPart = true;
        }

        $isFloat = false;
        $hasDecPart = false;

        // Decimal part
        if ($this->pos < $this->len && $this->input[$this->pos] === '.') {
            $isFloat = true;
            $this->advance();
            while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
                $this->advance();
                $hasDecPart = true;
            }
        }

        // Require at least one digit in integer or decimal part
        if (!$hasIntPart && !$hasDecPart) {
            throw new TransactionLogParseException(
                "Malformed number: missing digits",
                $start
            );
        }

        // Exponent part ([eE][+-]?digits)
        if ($this->pos < $this->len && ($this->input[$this->pos] === 'e' || $this->input[$this->pos] === 'E')) {
            $isFloat = true;
            $this->advance();
            if ($this->pos < $this->len && ($this->input[$this->pos] === '+' || $this->input[$this->pos] === '-')) {
                $this->advance();
            }
            $expStart = $this->pos;
            while ($this->pos < $this->len && ctype_digit($this->input[$this->pos])) {
                $this->advance();
            }
            if ($this->pos === $expStart) {
                throw new TransactionLogParseException(
                    "Malformed number: missing exponent digits",
                    $start
                );
            }
        }

        $numStr = substr($this->input, $start, $this->pos - $start);

        if ($isFloat) {
            return (float)$numStr;
        }

        return (int)$numStr;
    }

    private function parseKeyword(): bool|null
    {
        if ($this->matchKeyword('NULL')) {
            return null;
        }
        if ($this->matchKeyword('true')) {
            return true;
        }
        if ($this->matchKeyword('false')) {
            return false;
        }

        throw new TransactionLogParseException(
            "Unknown keyword",
            $this->pos
        );
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ctype_space($this->input[$this->pos])) {
            $this->advance();
        }
    }

    private function peekChar(): ?string
    {
        return $this->pos < $this->len ? $this->input[$this->pos] : null;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function matchChar(string $char): bool
    {
        if ($this->pos < $this->len && $this->input[$this->pos] === $char) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function matchString(string $str): bool
    {
        $len = strlen($str);
        if ($this->pos + $len <= $this->len && substr($this->input, $this->pos, $len) === $str) {
            $this->pos += $len;
            return true;
        }
        return false;
    }

    private function matchKeyword(string $keyword): bool
    {
        $len = strlen($keyword);
        if ($this->pos + $len <= $this->len && substr($this->input, $this->pos, $len) === $keyword) {
            // Make sure it's not part of a longer identifier
            $nextPos = $this->pos + $len;
            if ($nextPos < $this->len && ctype_alnum($this->input[$nextPos])) {
                return false;
            }
            $this->pos += $len;
            return true;
        }
        return false;
    }
}
