<?php

declare(strict_types=1);

namespace DbTransactionLog\Import;

/**
 * Streaming scanner for legacy transaction log files.
 *
 * Entry format (one per `// YYYY-MM-DD HH:MM:SS <username>` header):
 *   // 2026-10-30 08:00:00 admin
 *   $s = new DBPlantOrder();
 *   $s->DBPlantOrderplaceOrder([...var_export params...]);
 *
 * The call token may be the bare method (`$s->update(`) or class+method
 * (`$s->DBSiteupdate(`); extractOperation() normalizes it.
 */
final class FileLogScanner
{
    private const KNOWN_CLASS_TOKENS = [
        'DBPlantOrder', 'DBPlantEvent', 'DBBaseRetree', 'DBMediaContacts', 'DBParticipant',
        'RecurringEvent', 'MailServiceEmail', 'DBSignups', 'DBSites', 'DBSignup',
        'DBPlant', 'DBLead', 'DBSite', 'DBTags', 'DBUser', 'DBEMail',
        'PlantOrder', 'PlantEvent', 'Participant', 'MediaContacts', 'RecurringEvent',
        'Signup', 'Signups', 'Available', 'Contact', 'Template', 'Campaign',
        'Action', 'Vendor', 'Tags', 'Lead', 'Sites', 'Plant', 'User', 'EMail', 'Site'
    ];

    public function __construct(private string $path)
    {
    }

    /**
     * Extract the operation name from a method token.
     * Strips namespace prefix, then attempts to strip known class tokens.
     */
    public static function extractOperation(string $token): string
    {
        // Strip namespace prefix
        $pos = strrpos($token, '\\');
        if ($pos !== false) {
            $token = substr($token, $pos + 1);
        }

        // Find the longest matching known class token
        $longestMatch = null;
        $longestLength = 0;

        foreach (self::KNOWN_CLASS_TOKENS as $classToken) {
            if (str_starts_with($token, $classToken)) {
                $length = strlen($classToken);
                if ($length > $longestLength) {
                    $longestLength = $length;
                    $longestMatch = $classToken;
                }
            }
        }

        // Apply the lowercase guard to the longest match
        if ($longestMatch !== null) {
            $remainder = substr($token, $longestLength);
            if ($remainder !== '' && ctype_lower($remainder[0])) {
                return $remainder;
            }
        }

        return $token;
    }

    /**
     * Stream entries one at a time (O(1) memory per entry).
     *
     * @return \Generator<int, FileLogEntry>
     */
    public function iterateEntries(): \Generator
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return;
        }

        $headerPattern = '/^\/\/ (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?: (.*))?$/';
        $classPattern = '/^\$s = new ([A-Za-z0-9_\\\\]+)\(\);$/';
        $operationPattern = '/^\$s->([A-Za-z0-9_\\\\]+)\(/';

        $lines = [];
        $timestamp = null;
        $username = '';
        $classToken = '';
        $operation = '';
        $operationLineIndex = -1;

        $buildEntry = function () use (&$lines, &$timestamp, &$username, &$classToken, &$operation, &$operationLineIndex): ?FileLogEntry {
            if ($timestamp === null) {
                return null;
            }
            $raw = implode("\n", $lines);

            // paramsText: everything between the first '(' on the call line and the final ');'
            $paramsText = '';
            if ($operationLineIndex >= 0 && $operationLineIndex < count($lines)) {
                $callLine = $lines[$operationLineIndex];
                $parenPos = strpos($callLine, '(');
                if ($parenPos !== false) {
                    $offset = 0;
                    for ($i = 0; $i < $operationLineIndex; $i++) {
                        $offset += strlen($lines[$i]) + 1; // +1 for newline
                    }
                    $offset += $parenPos + 1;
                    $lastParen = strrpos($raw, ');');
                    if ($lastParen !== false && $lastParen > $offset) {
                        $paramsText = substr($raw, $offset, $lastParen - $offset);
                    }
                }
            }

            $firstEmail = '';
            if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $raw, $m)) {
                $firstEmail = $m[0];
            }

            $entry = new FileLogEntry($timestamp, $username, $classToken, $operation, $raw, $paramsText, $firstEmail);

            $lines = [];
            $timestamp = null;
            $username = '';
            $classToken = '';
            $operation = '';
            $operationLineIndex = -1;

            return $entry;
        };

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");

            if (preg_match($headerPattern, $line, $m)) {
                $entry = $buildEntry();
                if ($entry !== null) {
                    yield $entry;
                }
                $timestamp = $m[1];
                $username = rtrim($m[2] ?? '');
            } elseif ($timestamp !== null) {
                $lines[] = $line;
                if (preg_match($classPattern, $line, $m)) {
                    $classToken = $m[1];
                }
                if (preg_match($operationPattern, $line, $m)) {
                    $operation = self::extractOperation($m[1]);
                    $operationLineIndex = count($lines) - 1;
                }
            }
        }

        $entry = $buildEntry();
        if ($entry !== null) {
            yield $entry;
        }

        fclose($handle);
    }

    public function countEntries(): int
    {
        $n = 0;
        foreach ($this->iterateEntries() as $_) {
            $n++;
        }
        return $n;
    }
}
