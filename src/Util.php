<?php

declare(strict_types=1);

namespace DbTransactionLog;

/**
 * Utility functions for transaction logging.
 */
final class Util
{
    /**
     * Enrich params with email for transaction logging.
     *
     * The vendor package extracts email via regex from the serialized params string.
     * This method ensures an 'email' field exists in params for better email detection.
     *
     * @param array|null $params The params array to enrich
     * @param string|null $sessionEmail Optional session email to use if no email in params
     * @return array The enriched params (with email field if email found)
     */
    public static function enrichParamsWithEmail(?array $params, ?string $sessionEmail = null): array
    {
        // If params already has email, return as-is
        if ($params !== null && isset($params['email']) && $params['email']) {
            return $params;
        }

        // Use provided session email or try to get from session
        $email = $sessionEmail ?? '';
        if (!$email) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $email = $_SESSION['email'] ?? $_SESSION['username'] ?? '';
        }
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($params === null) {
                $params = ['email' => $email];
            } else {
                $params['email'] = $email;
            }
        }

        return $params ?? [];
    }

    /**
     * Get a meaningful username for transaction logging.
     *
     * In web requests: returns $_SESSION['username'] if available.
     * In CLI requests: returns a context identifier based on the calling script/context.
     *
     * Fallback order:
     * 1. Session username (web requests)
     * 2. Script basename (CLI requests, e.g., "cron.php", "generate-recurring-events.php")
     * 3. "cli" (if no other context available)
     *
     * @param callable(): string|null $fallbackUsername Optional callback to provide a fallback username
     * @return string A username or context identifier
     */
    public static function getTransactionLoggerUsername(?callable $fallbackUsername = null): string
    {
        // First check session (web requests)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $sessionUsername = $_SESSION['username'] ?? '';
        if ($sessionUsername) {
            return $sessionUsername;
        }

        // CLI context: try to get calling script context
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        // Find the script file in the backtrace
        foreach ($bt as $frame) {
            $file = $frame['file'] ?? '';
            if ($file && file_exists($file)) {
                $basename = basename($file);
                // Return script name without .php extension for cleaner display
                $name = preg_replace('/\.php$/', '', $basename);
                // Map common cron scripts to user-friendly names
                $cronMap = [
                    'cron' => 'cron-job',
                    'generate-recurring-events' => 'recurring-events',
                    'batchmail' => 'batch-mail',
                    'process-csv' => 'csv-import',
                    'test-runner' => 'test-runner',
                ];
                return $cronMap[$name] ?? $name;
            }
        }

        // Ultimate fallback
        if ($fallbackUsername !== null) {
            return $fallbackUsername();
        }
        return 'cli';
    }
}
