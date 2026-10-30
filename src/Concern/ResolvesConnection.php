<?php

declare(strict_types=1);

namespace DbTransactionLog\Concern;

use PDO;

/**
 * Shared config resolution for the package's PDO-backed classes.
 *
 * Connection resolution order: explicit `pdo`, then `pdoProvider` callable,
 * then \Common\DB\DBBase::getDefaultConnection() when dbbase is loaded.
 */
trait ResolvesConnection
{
    protected function resolveConnection(array $config): ?PDO
    {
        if (isset($config['pdo']) && $config['pdo'] instanceof PDO) {
            return $config['pdo'];
        }
        if (isset($config['pdoProvider']) && is_callable($config['pdoProvider'])) {
            $pdo = ($config['pdoProvider'])();
            return $pdo instanceof PDO ? $pdo : null;
        }
        if (class_exists(\Common\DB\DBBase::class)) {
            $pdo = \Common\DB\DBBase::getDefaultConnection();
            return $pdo instanceof PDO ? $pdo : null;
        }
        return null;
    }

    protected function resolveTable(array $config): string
    {
        $table = (string)($config['table'] ?? 'transactions');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: $table");
        }
        return $table;
    }

    /**
     * Read a config value that may be a plain value or a zero-arg callable.
     */
    protected function callConfig(array $config, string $key, mixed $default): mixed
    {
        $value = $config[$key] ?? null;
        if (is_callable($value)) {
            return $value();
        }
        return $value ?? $default;
    }
}
