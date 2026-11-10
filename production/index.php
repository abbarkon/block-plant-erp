<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('production', 'view');

$pageTitle = 'Production Orders';

$search = trim((string) ($_GET['search'] ?? ''));
$blockFilter = (int) ($_GET['block_type_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = "p.production_no LIKE ?";
    $params[] = '%' . $search . '%';
}
if ($blockFilter > 0) {
    $conditions[] = "p.block_type_id = ?";
    $params[] = $blockFilter;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $conditions[] = "p.production_date >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $conditions[] = "p.production_date <= ?";
    $params[] = $to;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("
    SELECT p.*, bt.name AS block_name, bt.size
    FROM production p
    JOIN block_types bt ON p.block_type_id = bt.id
    $where
    ORDER BY p.production_date DESC, p.id DESC
    LIMIT 500
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$blockTypes = $pdo->query("SELECT id, name, size FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();

// CSV export (guarded by production/view).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="production_runs_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Production No', 'Date', 'Block', 'Planned', 'Produced', 'Good', 'Rejected', 'Total Cost']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['production_no'],
            $r['production_date'],
            $r['block_name'] . ' ' . $r['size'],
            $r['planned_quantity'],
            $r['produced_quantity'],
            $r['good_quantity'],
            $r['rejected_quantity'],
            $r['production_cost'],
        ]);
    }
    fclose($out);
    exit;
}

$shownGood = array_sum(array_map(fn($r) => (int) $r['good_quantity'], $rows));
$shownRejected = array_sum(array_map(fn($r) => (int) $r['rejected_quantity'], $rows));
$shownCost = array_sum(array_map(fn($r) => (float) $r['production_cost'], $rows));
$shownProduced = array_sum(array_map(fn($r) => (int) $r['produced_quantity'], $rows));
$shownRejectRate = $shownProduced > 0 ? ($shownRejected / $shownProduced) * 100 : 0;

$todayGood = (int) $pdo->query("SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date=CURDATE()")->fetchColumn();
$monthCost = (float) $pdo->query("SELECT COALESCE(SUM(production_cost),0) FROM production WHERE production_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$pendingSched = 0;
try {
    $pendingSched = (int) $pdo->query("SELECT COUNT(*) FROM production_schedules WHERE status IN ('planned','in_progress') AND schedule_date >= CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    $pendingSched = 0;
}

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'index.php?' . http_build_query($exportQuery);

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
@media print {
    .no-print, .navbar, .sidebar, .topbar, .header-actions, .filter-form { display: none !important; }
    .stats-grid { display: flex; flex-wrap: wrap; gap: 10px; }
    body { background: #fff; }
}
</style>

<div class="page-header no-print">
    <h1>Production Orders</h1>
    <div class="header-actions">
        <?php if (has_permission('production', 'create')): ?>
        <a href="create.php" class="btn btn-accent">+ New run</a>
        <?php endif; ?>
        <a href="planning.php" class="btn btn-primary">Planning</a>
        <a href="targets.php" class="btn btn-secondary">Targets</a>
        <a href="analytics.php" class="btn btn-secondary">Analytics</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid no-print">
    <div class="stat-card">
        <div class="stat-icon" style="background:#4f4fc9;">🏭</div>
        <div><div class="stat-value"><?= number_format(count($rows)) ?></div><div class="stat-label">Runs shown</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2f7d4f;">✓</div>
        <div><div class="stat-value"><?= number_format($shownGood) ?></div><div class="stat-label">Good blocks</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e74c3c;">✗</div>
        <div><div class="stat-value"><?= number_format($shownRejectRate, 1) ?>%</div><div class="stat-label">Rejection rate</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f39c12;">💰</div>
        <div><div class="stat-value"><?= currency_symbol() . money($shownCost) ?></div><div class="stat-label">Cost of runs shown</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#26a69a;">📈</div>
        <div><div class="stat-value"><?= currency_symbol() . money($monthCost) ?></div><div class="stat-label">Cost MTD</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#9b59b6;">📅</div>
        <div><div class="stat-value"><?= number_format($todayGood) ?></div><div class="stat-label">Good blocks today</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#607d8b;">⏳</div>
        <div><div class="stat-value"><?= number_format($pendingSched) ?></div><div class="stat-label">Scheduled jobs</div></div>
    </div>
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <input type="text" name="search" placeholder="Search production #" value="<?= e($search) ?>">
        <select name="block_type_id">
            <option value="">All block types</option>
            <?php foreach ($blockTypes as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= $blockFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' ' . $b['size']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" title="From date">
        <input type="date" name="to" value="<?= e($to) ?>" title="To date">
        <button type="submit" class="btn btn-primary">Apply</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="header-actions no-print" style="margin-bottom:16px;">
    <a href="bom.php" class="btn btn-secondary">BOM</a>
    <a href="schedule.php" class="btn btn-secondary">Schedule</a>
    <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
    <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Production #</th>
                <th>Date</th>
                <th>Block</th>
                <th>Planned</th>
                <th>Produced</th>
                <th>Good</th>
                <th>Rejected</th>
                <th>Cost</th>
                <th class="no-print"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="9" style="text-align:center;color:#64748b;padding:20px;">No production runs match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['production_no']) ?></strong></td>
                <td><?= format_date($r['production_date']) ?></td>
                <td><?= e($r['block_name'] . ' ' . $r['size']) ?></td>
                <td><?= number_format($r['planned_quantity']) ?></td>
                <td><?= number_format($r['produced_quantity']) ?></td>
                <td style="color:#2f7d4f;font-weight:600;"><?= number_format($r['good_quantity']) ?></td>
                <td style="color:#c0392b;"><?= number_format($r['rejected_quantity']) ?></td>
                <td><?= currency_symbol() . money($r['production_cost']) ?></td>
                <td class="no-print"><a class="btn-sm" href="view.php?id=<?= (int) $r['id'] ?>">View</a>
                    <?php if (has_permission('production', 'edit')): ?><a class="btn-sm" href="edit.php?id=<?= (int) $r['id'] ?>">Edit</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
