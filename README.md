# avisitor/db-transaction-log

DB-backed transaction log for apps using `avisitor/dbbase` (`Common\DB\DBBase`).
Replaces append-only transaction log files with a queryable MySQL table —
including **failed** transactions with a result code — with configurable
retention (default 1 year).

## Install

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/avisitor/db-transaction-log.git" }],
"require": { "avisitor/db-transaction-log": "^1.0" }
```

## Schema

Apply `schema/transactions.sql` to your database (e.g. paste the CREATE TABLE
into your app's `createtables.sql`):

| column | type | notes |
|---|---|---|
| `id` | bigint unsigned PK | stable row identity (use for replay, not page indexes) |
| `ts` | datetime | event time; indexed for retention deletes |
| `username` | varchar(64) | from your session |
| `class` | varchar(64) | short class token, e.g. `Site` (leading `DB` stripped) |
| `operation` | varchar(64) | method name, e.g. `update` |
| `email` | varchar(128) | first email found in params; indexed |
| `status` | varchar(16) | `ok` / `error` / … |
| `error` | text | exception message on failure |
| `params` | mediumtext | `var_export()` params text, verbatim |
| `request` | varchar(255) | originating script |

## Writing transactions

Configure once (e.g. in your DB base-class constructor) and log from your
DBBase subclass:

```php
use DbTransactionLog\TransactionLogger;

TransactionLogger::configure([
    'enabled' => true,
    'table' => 'transactions',
    // 'pdo' => $pdo,                        // or:
    // 'pdoProvider' => fn() => \Common\DB\DBBase::getDefaultConnection(),
    'now' => fn() => localNow(),            // your app timezone clock
    'username' => fn() => $_SESSION['username'] ?? '',
    'request' => fn() => $_SERVER['SCRIPT_NAME'] ?? '',
    'retentionDays' => 365,                 // configurable retention
    'pruneProbability' => 0.01,             // opportunistic prune on each log()
]);

// In your DBBase subclass:
protected function logTransaction($params, string $status = 'ok', string $error = ''): void
{
    TransactionLogger::getDefault()?->logCaller($params, $status, $error);
}
```

`logCaller()` derives the class token and operation from the calling method
via `debug_backtrace`. For explicit control use
`$logger->log('Site', 'update', $params, 'ok')`.

Logging is best-effort: any failure is sent to `error_log()` and never thrown
into your application code.

## Reading transactions

```php
use DbTransactionLog\TransactionLogStore;

$store = new TransactionLogStore(['pdo' => $pdo]); // or pdoProvider / default DBBase connection
$result = $store->query(operation: 'update', email: 'a@b', classToken: 'Site',
                        status: 'error', from: '2026-01-01', to: '2026-12-31',
                        page: 1, pageSize: 1000);
$result->entries;            // TransactionLogEntry[] newest-first
$result->totalEntries;       // unfiltered count
$result->totalMatching;      // filtered count
$result->distinctOperations; // for filter dropdowns
$result->distinctClasses;
$entry = $store->getById($id);          // stable replay target
$entry->paramsArray();                  // parsed params (no eval)
$entry->raw();                          // legacy file-style text for display
```

## Retention

- Opportunistic: `log()` calls `maybePrune()` with `pruneProbability` (default
  1% of writes), deleting rows older than `retentionDays`.
- Deterministic: run `vendor/bin/prune-transactions` from cron:

```
17 3 * * * php /path/to/vendor/bin/prune-transactions --dbname=myapp --user=... --pass=... [--days=365]
```

## Migrating an existing transactions.log file (one-time)

```
vendor/bin/import-transaction-log --file=log/transactions.log \
    --dbname=myapp --user=... --pass=... [--table=transactions] [--dry-run]
```

Entries are imported verbatim (status `ok` — the legacy file never recorded
failures). The script prints the file's entry count and warns if the inserted
row count differs. Run it **after** deploying the new code so no entries are
lost (hard cutover: the file is never written again).

## Tests

```
./tests/run-tests.sh          # JSON report in tests/reports/
DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... vendor/bin/phpunit   # incl. live-DB tests
```
