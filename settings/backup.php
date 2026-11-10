<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'edit');

if (isset($_GET['backup'])) {
    $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $requested = $_GET['tables'] ?? [];
    $selected = [];
    if (is_array($requested) && $requested) {
        foreach ($requested as $table) {
            if (in_array($table, $allTables, true)) {
                $selected[] = $table;
            }
        }
    }
    if (!$selected) {
        $selected = $allTables;
    }

    $useGzip = !empty($_GET['gzip']) && function_exists('deflate_init');
    $filename = 'block_plant_backup_' . date('Y-m-d_His') . '.sql' . ($useGzip ? '.gz' : '');
    header('Content-Type: ' . ($useGzip ? 'application/gzip' : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    // Log before streaming so the audit trail always records the download.
    log_activity('export', 'settings', null, 'Database backup downloaded (' . count($selected) . ' table' . (count($selected) === 1 ? '' : 's') . ($useGzip ? ', gzip' : '') . ')');

    // php://output is not seekable, so gzopen() cannot be used here. Stream
    // through deflate_add() instead, which produces a valid gzip container.
    $deflate = $useGzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    $write = static function ($text) use (&$deflate) {
        if ($deflate) {
            echo deflate_add($deflate, $text, ZLIB_NO_FLUSH);
        } else {
            echo $text;
        }
    };

    $write("-- Block Plant ERP database backup\n");
    $write("-- Generated: " . date('c') . "\n");
    $write("-- Tables: " . count($selected) . " of " . count($allTables) . "\n\n");
    $write("SET NAMES utf8mb4;\n");
    $write("SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($selected as $table) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $write("DROP TABLE IF EXISTS {$quotedTable};\n");
        $create = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_NUM);
        $write($create[1] . ";\n\n");

        $rows = $pdo->query("SELECT * FROM {$quotedTable}");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(static function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, array_keys($row));
            $values = array_map(static function ($value) use ($pdo) {
                return $value === null ? 'NULL' : $pdo->quote($value);
            }, array_values($row));
            $write("INSERT INTO {$quotedTable} (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n");
        }
        $write("\n");
    }

    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    if ($deflate) {
        echo deflate_add($deflate, '', ZLIB_FINISH);
    }
    exit;
}

$pageTitle = "Backup & Restore";

$tables = $pdo->query("SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME")->fetchAll();
$totalRows = 0;
$totalBytes = 0;
foreach ($tables as &$table) {
    $table['row_count'] = (int) $table['row_count'];
    $table['size'] = (int) $table['data_length'] + (int) $table['index_length'];
    $totalRows += $table['row_count'];
    $totalBytes += $table['size'];
}
unset($table);
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$canGzip = function_exists('deflate_init');

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .backup-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .backup-actions .btn { text-decoration:none; }
    .table-picker { max-height:340px; overflow:auto; border:1px solid #e2e8f0; border-radius:10px; }
    .table-picker table { margin:0; }
    .table-picker th { position:sticky; top:0; z-index:1; }
    .table-picker tr.is-selected { background:#eef0fa; }
    .backup-stat { display:flex; gap:10px 22px; flex-wrap:wrap; color:#475569; font-size:.86rem; margin:12px 0 0; }
    .backup-stat strong { color:#1c2250; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>💾 Backup &amp; Restore</h1>
        <p style="color:#64748b;margin:6px 0 0;">Download a portable SQL snapshot of the complete ERP database, or select only the tables you need.</p>
    </div>
    <div class="header-actions no-print">
        <a href="company.php" class="btn btn-secondary">Company settings</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="grid-2">
    <div class="panel no-print">
        <h3>Create a backup</h3>
        <p>The export includes table structure, indexes, and every record for the selected tables. Foreign-key checks are disabled during restore so related ERP data can be imported safely.</p>
        <div class="backup-actions" style="margin-top:16px;">
            <a href="?backup=1" class="btn btn-accent">Download full SQL backup</a>
            <?php if ($canGzip): ?>
            <a href="?backup=1&amp;gzip=1" class="btn btn-secondary">Download full backup (.sql.gz)</a>
            <?php endif; ?>
        </div>
        <div class="backup-stat">
            <span>Database: <strong><?= e($dbName) ?></strong></span>
            <span>Tables: <strong><?= number_format(count($tables)) ?></strong></span>
            <span>Approx. rows: <strong><?= number_format($totalRows) ?></strong></span>
            <span>Approx. size: <strong><?= e(format_bytes($totalBytes)) ?></strong></span>
        </div>
        <div class="flash flash-warning" style="margin-top:16px;">Store backups outside the web folder and create one before system updates.</div>
    </div>

    <div class="panel">
        <h3>Restore options</h3>
        <p>Restore a downloaded SQL file using phpMyAdmin or the MySQL command line.</p>
        <ol style="line-height:1.9;padding-left:22px;">
            <li>Open phpMyAdmin and select the <strong><?= e($dbName) ?></strong> database.</li>
            <li>Choose <strong>Import</strong> and select the SQL backup (unzip a <code>.sql.gz</code> file first).</li>
            <li>Alternatively run <code>mysql -u root <?= e($dbName) ?> &lt; backup.sql</code>.</li>
        </ol>
        <div class="flash flash-danger">Restoring replaces database contents. Create a current backup first, and never import an untrusted file.</div>
    </div>
</div>

<div class="panel" style="margin-top:20px;">
    <h3>Select tables to export</h3>
    <p style="color:#64748b;font-size:.86rem;">Useful for migrating a single module or sharing a small dataset. Leave everything unticked and use “Download full SQL backup” above for a complete snapshot.</p>
    <form method="get" id="tableBackupForm">
        <input type="hidden" name="backup" value="1">
        <div class="table-picker">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:42px;"><input type="checkbox" id="pickAll" aria-label="Select all tables"></th>
                        <th>Table</th>
                        <th style="text-align:right;">Rows (approx.)</th>
                        <th style="text-align:right;">Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $table): ?>
                    <tr>
                        <td><input type="checkbox" class="table-pick" name="tables[]" value="<?= e($table['name']) ?>" aria-label="Export <?= e($table['name']) ?>"></td>
                        <td><code><?= e($table['name']) ?></code></td>
                        <td style="text-align:right;"><?= number_format($table['row_count']) ?></td>
                        <td style="text-align:right;"><?= e(format_bytes($table['size'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="backup-actions" style="margin-top:14px;">
            <button type="submit" class="btn btn-accent">Download selected tables</button>
            <?php if ($canGzip): ?>
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin:0;">
                <input type="checkbox" name="gzip" value="1" style="width:auto;margin:0;"> Compress with gzip
            </label>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel" style="margin-top:20px;">
    <h3>Recommended schedule</h3>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Frequency</th><th>Recommended timing</th><th>Retention</th><th>Purpose</th></tr></thead>
            <tbody>
                <tr><td>Daily</td><td>End of business day</td><td>7 days</td><td>Recover operational mistakes</td></tr>
                <tr><td>Weekly</td><td>End of week</td><td>4 weeks</td><td>Recover from weekly issues</td></tr>
                <tr><td>Monthly</td><td>End of month</td><td>12 months</td><td>Historical reporting</td></tr>
                <tr><td>Before updates</td><td>Immediately before changes</td><td>Until verified</td><td>Rollback protection</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var pickAll = document.getElementById('pickAll');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.table-pick'));
    function refresh() {
        boxes.forEach(function (box) {
            box.closest('tr').classList.toggle('is-selected', box.checked);
        });
        if (pickAll) {
            pickAll.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
            pickAll.indeterminate = !pickAll.checked && boxes.some(function (box) { return box.checked; });
        }
    }
    if (pickAll) {
        pickAll.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = pickAll.checked; });
            refresh();
        });
    }
    boxes.forEach(function (box) { box.addEventListener('change', refresh); });
    refresh();
})();
</script>

<?php require_once "../partials/footer.php"; ?>
