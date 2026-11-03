<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'view');
ensure_finished_stock_transaction_schema();

$pageTitle = 'Finished Goods Movement';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$blockFilter = (int) ($_GET['block_type_id'] ?? 0);
$earliest = $pdo->query("SELECT MIN(transaction_date) FROM finished_stock_transactions")->fetchColumn() ?: date('Y-m-01');

$sql = "SELECT fst.*, bt.name AS block_name, bt.size, u.name AS user_name
        FROM finished_stock_transactions fst
        JOIN block_types bt ON fst.block_type_id = bt.id
        LEFT JOIN users u ON fst.created_by = u.id
        WHERE fst.transaction_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($blockFilter) {
    $sql .= " AND fst.block_type_id = ?";
    $params[] = $blockFilter;
}
$orderBy = " ORDER BY fst.transaction_date DESC, fst.id DESC";

// Inline CSV export, same convention as sales/production/purchases
if (($_GET['export'] ?? '') === 'csv') {
    $exp = $pdo->prepare($sql . $orderBy);
    $exp->execute($params);
    $out = $exp->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finished_goods_movement_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Date', 'Block', 'Size', 'Type', 'Quantity', 'Reference', 'Notes', 'User']);
    foreach ($out as $r) {
        fputcsv($fh, [
            $r['transaction_date'], $r['block_name'], $r['size'], $r['transaction_type'],
            $r['quantity'], $r['reference_no'], $r['notes'], $r['user_name'] ?? '',
        ]);
    }
    fclose($fh);
    exit;
}

$rows = [];
try {
    $stmt = $pdo->prepare($sql . $orderBy . " LIMIT 300");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $rows = [];
}

$blocks = $pdo->query("SELECT id, name, size FROM block_types WHERE status=1 ORDER BY name")->fetchAll();

$summary = ['in' => 0, 'out' => 0];
foreach ($rows as $r) {
    if ($r['quantity'] >= 0) {
        $summary['in'] += $r['quantity'];
    } else {
        $summary['out'] += abs($r['quantity']);
    }
}

require_once "../partials/header.php";
?>

<style>
@media print {
    .no-print, .navbar, .sidebar, .topbar, .header-actions, .filter-section, .filter-form { display: none !important; }
    .stats-grid { display: flex; flex-wrap: wrap; gap: 10px; }
    body { background: #fff; }
}
</style>

<div class="page-header no-print">
    <h1>Finished goods movement</h1>
    <div class="header-actions">
        <a href="finished_adjust.php" class="btn btn-accent">Adjust finished stock</a>
        <a href="index.php" class="btn btn-secondary">Finished stock</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="?export=csv&from=<?= e($from) ?>&to=<?= e($to) ?>&block_type_id=<?= $blockFilter ?>">Export CSV</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#00897b;">↓</div><div><div class="stat-value"><?= number_format($summary['in']) ?></div><div class="stat-label">In (period)</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e74c3c;">↑</div><div><div class="stat-value"><?= number_format($summary['out']) ?></div><div class="stat-label">Out (period)</div></div></div>
</div>

<div class="filter-section">
    <form method="get" class="filter-form">
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <select name="block_type_id">
            <option value="">All block types</option>
            <?php foreach ($blocks as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= $blockFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' ' . $b['size']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>
    <div class="no-print" style="display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; font-size:.85rem; color:#64748b;">
        <span>Quick range:</span>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>&block_type_id=<?= $blockFilter ?>">This month</a>
        <a href="?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-d', strtotime('last day of last month')) ?>&block_type_id=<?= $blockFilter ?>">Last month</a>
        <a href="?from=<?= e($earliest) ?>&to=<?= date('Y-m-d') ?>&block_type_id=<?= $blockFilter ?>">All time (from <?= e(format_date($earliest)) ?>)</a>
    </div>
</div>

<div class="table-container">
    <?php if (count($rows) === 300): ?>
    <div class="no-print" style="background:#fff3cd;border-left:4px solid #ffc107;color:#856404;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:.88rem;">
        Showing the 300 most recent movements in this range. Narrow the dates to see the rest, or use <strong>Export CSV</strong> for the full range.
    </div>
    <?php endif; ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Block</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Reference</th>
                <th>Notes</th>
                <th>User</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b;padding:20px;">No finished-goods movements between <?= e(format_date($from)) ?> and <?= e(format_date($to)) ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= format_date($r['transaction_date']) ?></td>
                <td><?= e($r['block_name'] . ' ' . $r['size']) ?></td>
                <td><span class="badge badge-muted"><?= e($r['transaction_type']) ?></span></td>
                <td style="color:<?= $r['quantity'] >= 0 ? '#00897b' : '#c0392b' ?>;font-weight:600;"><?= $r['quantity'] >= 0 ? '+' : '' ?><?= number_format($r['quantity'], 0) ?></td>
                <td><?= e($r['reference_no'] ?? '') ?></td>
                <td><?= e($r['notes'] ?? '') ?></td>
                <td><?= e($r['user_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
