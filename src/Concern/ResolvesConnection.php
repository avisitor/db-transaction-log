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
    /** @var array config used for lazy per-call connection resolution */
    private array $connConfig = [];

    protected function setConnectionConfig(array $config): void
    {
        $this->connConfig = $config;
    }

    /**
     * Resolve the connection on every call (not once in the constructor) so a
     * connection that becomes available later in the process is still used.
     */
    protected function connection(): ?PDO
    {
        return $this->resolveConnection($this->connConfig);
    }

    protected function resolveConnection(array $config): ?PDO
    {
        if (isset($config['pdo']) && $config['pdo'] instanceof PDO) {
            return $config['pdo'];
        }
        if (isset($config['pdoProvider']) && is_callable($config['pdoProvider'])) {
            try {
                $pdo = ($config['pdoProvider'])();
            } catch (\Throwable $e) {
                return null;
            }
            return $pdo instanceof PDO ? $pdo : null;
        }
        if (class_exists(\Common\DB\DBBase::class)) {
            try {
                $pdo = \Common\DB\DBBase::getDefaultConnection();
            } catch (\Throwable $e) {
                return null;
            }
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
