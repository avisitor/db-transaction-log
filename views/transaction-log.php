<?php

declare(strict_types=1);

/**
 *Transaction log viewer template for DbTransactionLog.
 *
 * This is a shared template that apps can include directly or copy and customize.
 * Apps should provide configuration via $config array with the following keys:
 *
 * - pdoProvider: callable() that returns PDO
 * - table: transactions table name (default: 'transactions')
 * - pageSize: results per page (default: 200)
 * - adminCheck: callable() that returns true if user is admin (default: requireAdmin())
 * - bannerInclude: path to banner HTML include (optional)
 * - returnUrl: URL to return button link (default: 'index.php')
 * - pageName: page title (default: 'Transaction Log')
 * - statusOptionText: text for status options (default: ['ok', 'error'])
 * - operationsWhitelist: allowed operations for replay (default: [])
 * - pageJs: additional JS to include (optional)
 * - replayerFactory: callable() that returns a TransactionLogReplayer instance
 *   (default: new \DbTransactionLog\TransactionLogReplayer()). Apps that need
 *   custom class resolution or allowed operations should provide a subclass
 *   here instead of duplicating replay logic.
 */

// Config with defaults
$config = $config ?? [];
$config = array_merge([
    'pdoProvider' => fn() => \Common\DB\DBBase::getDefaultConnection(),
    'table' => 'transactions',
    'pageSize' => 200,
    'adminCheck' => fn() => (function_exists('requireAdmin') ? requireAdmin() : true),
    'bannerInclude' => null,
    'returnUrl' => 'index.php',
    'pageName' => 'Transaction Log',
    'statusOptions' => ['ok', 'error'],
    'operationsWhitelist' => [],
    'pageJs' => '',
    'replayerFactory' => fn() => new \DbTransactionLog\TransactionLogReplayer(),
], $config);

// Run admin check
if (!($config['adminCheck'] ?? false)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

$store = new \DbTransactionLog\TransactionLogStore([
    'pdoProvider' => $config['pdoProvider'],
    'table' => $config['table'],
]);

$pageSize = (int)($config['pageSize'] ?? 200);
$operationsWhitelist = $config['operationsWhitelist'] ?? [];

// Query parameters
$op = $_POST['op'] ?? $_GET['op'] ?? '';
$email = $_POST['email'] ?? $_GET['email'] ?? '';
$cls = $_POST['class'] ?? $_GET['class'] ?? '';
$status = $_POST['status'] ?? $_GET['status'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));
$ajaxRows = ($_GET['ajax'] ?? '') === 'rows';

// Nonce
$_SESSION['txlog_nonce'] ??= bin2hex(random_bytes(16));
$nonce = $_SESSION['txlog_nonce'];

// Query
$scanError = null;
try {
    $scan = $store->query(
        $op !== '' ? $op : null,
        $email !== '' ? $email : null,
        $cls !== '' ? $cls : null,
        $status !== '' ? $status : null,
        null,
        null,
        $page,
        $pageSize
    );
} catch (\Throwable $e) {
    $scanError = $e->getMessage();
    $scan = new \DbTransactionLog\TransactionLogQueryResult([], 0, 0, $page, $pageSize, [], []);
}

// Replay
$replayResult = null;
$replayError = null;
$replayedEntry = null;
$topRow = 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'replay') {
    $postNonce = $_POST['nonce'] ?? '';
    if (!hash_equals($nonce, $postNonce)) {
        $replayError = 'Invalid or expired session token';
    } else {
        $postId = (int)($_POST['id'] ?? 0);
        $topRow = (int)($_POST['toprow'] ?? 0);
        try {
            $entry = $postId > 0 ? $store->getById($postId) : null;
            if ($entry === null) {
                $replayError = 'Entry no longer available';
            } else {
                $replayedEntry = $entry;

                $params = [];
                try {
                    $parser = new \DbTransactionLog\Import\VarExportArrayParser();
                    $params = $parser->parse($entry->params);
                } catch (\DbTransactionLog\Import\TransactionLogParseException $e) {
                    $replayError = 'Parse error: ' . $e->getMessage();
                }

                if ($replayError === null) {
                    $replayer = $config['replayerFactory']();
                    $replayResult = $replayer->replay($entry->classToken, $entry->operation, $params);
                }
            }
        } catch (\Throwable $e) {
            $replayError = 'Replay failed: ' . $e->getMessage();
        }
    }
}

// Row renderer
$renderRow = function (\DbTransactionLog\TransactionLogEntry $entry) use ($operationsWhitelist): string {
    ob_start();
    ?>
    <tr data-id="<?= htmlspecialchars((string)$entry->id, ENT_QUOTES, 'UTF-8') ?>">
        <td><?= htmlspecialchars($entry->ts, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($entry->username, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($entry->classToken, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($entry->operation, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($entry->email, ENT_QUOTES, 'UTF-8') ?></td>
        <td>
            <?php if ($entry->status === 'ok'): ?>
                <span style="color: green;">ok</span>
            <?php else: ?>
                <span style="color: red;"><?= htmlspecialchars($entry->status, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($entry->error !== ''): ?>
                    <br><small><?= htmlspecialchars(mb_strimwidth($entry->error, 0, 120, '…'), ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <td>
            <details>
                <summary>View</summary>
                <pre><?= htmlspecialchars($entry->raw(), ENT_QUOTES, 'UTF-8') ?></pre>
            </details>
        </td>
        <td>
            <?php if (empty($operationsWhitelist) || in_array($entry->operation, $operationsWhitelist, true)): ?>
                <button type="button" class="replay-btn btn btn-outline-secondary btn-sm"
                        data-class="<?= htmlspecialchars($entry->classToken, ENT_QUOTES, 'UTF-8') ?>"
                        data-op="<?= htmlspecialchars($entry->operation, ENT_QUOTES, 'UTF-8') ?>"
                        data-id="<?= htmlspecialchars((string)$entry->id, ENT_QUOTES, 'UTF-8') ?>">
                    Replay
                </button>
            <?php else: ?>
                <span style="color: #ccc;">N/A</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return (string)ob_get_clean();
};

///ajax endpoint
if ($ajaxRows) {
    if ($scanError !== null) {
        http_response_code(500);
        echo 'Transaction log unavailable: ' . $scanError;
        return;
    }
    echo "<table><tbody>\n";
    foreach ($scan->pageEntries() as $entry) {
        echo $renderRow($entry);
    }
    echo "</tbody></table>\n";
    return;
}

$operations = $scan->distinctOperations;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?= htmlspecialchars($config['pageName'] ?? 'Transaction Log', ENT_QUOTES, 'UTF-8') ?></title>
    <?= $htmlheader ?? '' ?>
    <script src="js/infinite-query.js"></script>
    <?php if (isset($config['pageJs']) && $config['pageJs']): ?>
        <?= $config['pageJs'] ?>
    <?php endif; ?>
</head>
<body>
    <?php if ($config['bannerInclude'] && file_exists($config['bannerInclude'])): ?>
        <?php include $config['bannerInclude']; ?>
    <?php endif; ?>
    <div class="wholepage">
    <p><a href="<?= htmlspecialchars($config['returnUrl'] ?? 'index.php', ENT_QUOTES, 'UTF-8') ?>">Return</a></p>
    <h2><?= htmlspecialchars($config['pageName'] ?? 'Transaction Log', ENT_QUOTES, 'UTF-8') ?></h2>

    <?php if ($replayResult !== null || $replayError !== null): ?>
    <div class="alert alert-info">
        <h3>Replay result</h3>
        <?php if ($replayError !== null): ?>
            <p style="color: red;"><strong>Error:</strong> <?= htmlspecialchars($replayError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($replayResult !== null): ?>
            <p><strong>Class:</strong> <?= htmlspecialchars($replayResult->classToken, ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Operation:</strong> <?= htmlspecialchars($replayResult->operation, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ($replayResult->ok): ?>
                <p style="color: green;"><strong>Status:</strong> Success</p>
                <?php if ($replayResult->invocation !== ''): ?>
                    <p><strong>Invocation:</strong> <code><?= htmlspecialchars($replayResult->invocation, ENT_QUOTES, 'UTF-8') ?></code></p>
                <?php endif; ?>
                <?php if ($replayResult->resultText !== ''): ?>
                    <p><strong>Result:</strong> <code><?= htmlspecialchars($replayResult->resultText, ENT_QUOTES, 'UTF-8') ?></code></p>
                <?php endif; ?>
            <?php else: ?>
                <p style="color: red;"><strong>Status:</strong> Failed</p>
                <?php if ($replayResult->invocation !== ''): ?>
                    <p><strong>Invocation:</strong> <code><?= htmlspecialchars($replayResult->invocation, ENT_QUOTES, 'UTF-8') ?></code></p>
                <?php endif; ?>
                <p><strong>Error:</strong> <code><?= htmlspecialchars($replayResult->errorText, ENT_QUOTES, 'UTF-8') ?></code></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="get" action="<?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8') ?>" id="filter-form" class="mb-3">
        <label class="me-2">Class:
            <select name="class" onchange="this.form.submit()" class="form-select d-inline-block" style="width:auto;">
                <option value="">All</option>
                <?php foreach ($scan->distinctClasses as $className): ?>
                    <option value="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>" <?= $cls === $className ? 'selected' : '' ?>>
                        <?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="me-2">Operation:
            <select name="op" onchange="this.form.submit()" class="form-select d-inline-block" style="width:auto;">
                <option value="">All</option>
                <?php foreach ($operations as $operation): ?>
                    <option value="<?= htmlspecialchars($operation, ENT_QUOTES, 'UTF-8') ?>" <?= $op === $operation ? 'selected' : '' ?>>
                        <?= htmlspecialchars($operation, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="me-2">Status:
            <select name="status" onchange="this.form.submit()" class="form-select d-inline-block" style="width:auto;">
                <option value="">All</option>
                <?php foreach ($config['statusOptions'] ?? ['ok', 'error'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="me-2">Email: <input type="text" name="email" id="email-input" class="form-control d-inline-block" style="width:auto;" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"></label>
    </form>

    <?php if ($scanError !== null): ?>
        <p class="alert alert-warning">Transaction log unavailable: <?= htmlspecialchars($scanError, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php
    $entries = $scan->pageEntries();
    $totalMatching = $scan->totalMatching;
    $totalEntries = $scan->totalEntries;
    $isFiltered = ($op !== '' || $email !== '' || $cls !== '' || $status !== '');
    $Z = $isFiltered ? $totalMatching : $totalEntries;
    $shown = count($entries);
    ?>
    <p class="text-muted">showing <span id="txlog-shown"><?= $shown ?></span> of <?= $Z ?></p>
    <div id="txlog-scroll-status" class="text-muted my-2">
        <span class="infinite-scroll-request" style="display:none;">Loading…</span>
        <span class="infinite-scroll-last" style="display:none;">— end of log —</span>
    </div>
    <table class="table table-bordered table-sm" id="txlog-table" data-total="<?= $Z ?>" data-page-size="<?= $pageSize ?>">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Class</th>
                <th>Operation</th>
                <th>Email</th>
                <th>Status</th>
                <th>Entry</th>
                <th>Replay</th>
            </tr>
        </thead>
        <tbody id="txlog-tbody">
            <?php if (empty($entries)): ?>
            <tr><td colspan="8">No transactions found</td></tr>
            <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                    <?= $renderRow($entry) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <form method="post" action="<?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8') ?>" id="replay-form" style="display: none;">
        <input type="hidden" name="action" value="replay">
        <input type="hidden" name="nonce" value="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="op" value="<?= htmlspecialchars($op, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="class" value="<?= htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="toprow" value="0">
    </form>

    <script>
    window.__txTopRow = <?= $topRow ?>;
    (function() {
        var tbody = document.getElementById('txlog-tbody');
        var status = document.getElementById('txlog-scroll-status');
        var form = document.getElementById('replay-form');
        if (!tbody || !form) {
            return;
        }

        var qs = 'op=<?= urlencode($op) ?>&email=<?= urlencode($email) ?>&class=<?= urlencode($cls) ?>&status=<?= urlencode($status) ?>';
        var $tbody = jQuery(tbody);
        if (typeof jQuery !== 'undefined' && jQuery.fn.infiniteScroll && status) {
            $tbody.infiniteScroll({
                path: '<?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '', ENT_QUOTES, 'UTF-8') ?>?ajax=rows&p={{#}}&' + qs,
                append: 'tr',
                history: false,
                status: status,
                scrollThreshold: 200,
                prefill: true
            });
            var infScroll = $tbody.data('infiniteScroll');
            var updateShown = function() {
                var el = document.getElementById('txlog-shown');
                if (el) {
                    el.textContent = tbody.querySelectorAll('tr[data-id]').length;
                }
            };
            var topRow = window.__txTopRow || 0;
            var restore = function() {
                if (!topRow) return true;
                var row = tbody.querySelector('tr[data-id="' + topRow + '"]');
                if (row) {
                    row.scrollIntoView();
                    topRow = 0;
                    return true;
                }
                return false;
            };
            if (infScroll) {
                infScroll.on('append', function() {
                    updateShown();
                    if (topRow && !restore()) infScroll.loadNextPage();
                });
                infScroll.on('last', function() {
                    updateShown();
                });
            }
            updateShown();
            if (topRow) infScroll.loadNextPage();
        }

        jQuery(tbody).on('click', '.replay-btn', function() {
            var classToken = this.getAttribute('data-class');
            var operation = this.getAttribute('data-op');
            var id = this.getAttribute('data-id');

            var message = 'Re-execute ' + classToken + '::' + operation + '?';
            <?php if (in_array('deleteOrder', $operationsWhitelist)): ?>
            if (operation === 'deleteOrder') message += ' Warning: this deletes data.';
            <?php endif; ?>

            if (confirm(message)) {
                var rows = tbody.querySelectorAll('tr[data-id]');
                var top = 0;
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].getBoundingClientRect().bottom > 0) {
                        top = rows[i].getAttribute('data-id');
                        break;
                    }
                }
                form.querySelector('input[name="id"]').value = id;
                form.querySelector('input[name="toprow"]').value = top;
                form.submit();
            }
        });

        var filterForm = document.getElementById('filter-form');
        var emailInput = document.getElementById('email-input');
        if (filterForm && emailInput) {
            var timer = null;
            emailInput.addEventListener('input', function() {
                clearTimeout(timer);
                timer = setTimeout(function() { filterForm.submit(); }, 400);
            });
            emailInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    clearTimeout(timer);
                    filterForm.submit();
                }
            });
        }
    })();
    </script>
</body>
</html>
