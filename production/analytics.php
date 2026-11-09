<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'view');

$pageTitle = 'Production Analytics';

$stats = $pdo->query("
    SELECT
        COUNT(*) AS runs,
        COALESCE(SUM(good_quantity),0) AS good,
        COALESCE(SUM(rejected_quantity),0) AS rejected,
        COALESCE(SUM(produced_quantity),0) AS produced,
        COALESCE(SUM(production_cost),0) AS cost
    FROM production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch();

$rejectRate = $stats['produced'] > 0 ? ($stats['rejected'] / $stats['produced']) * 100 : 0;
$costPerBlock = $stats['good'] > 0 ? $stats['cost'] / $stats['good'] : 0;

$topRuns = $pdo->query("
    SELECT p.*, bt.name, bt.size
    FROM production p
    JOIN block_types bt ON p.block_type_id = bt.id
    ORDER BY p.production_date DESC, p.id DESC
    LIMIT 10
")->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Production Analytics</h1>
    <div class="header-actions">
        <a href="bom.php" class="btn btn-secondary">BOM</a>
        <a href="schedule.php" class="btn btn-secondary">Schedule</a>
        <a href="planning.php" class="btn btn-primary">Planning</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#b8481b;">🏭</div>
        <div><div class="stat-value"><?= (int) $stats['runs'] ?></div><div class="stat-label">Runs (30d)</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2f7d4f;">✓</div>
        <div><div class="stat-value"><?= number_format($stats['good']) ?></div><div class="stat-label">Good blocks</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e74c3c;">✗</div>
        <div><div class="stat-value"><?= number_format($rejectRate, 1) ?>%</div><div class="stat-label">Rejection rate</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f39c12;">💰</div>
        <div><div class="stat-value"><?= currency_symbol() . money($costPerBlock) ?></div><div class="stat-label">Cost / good block</div></div>
    </div>
</div>

<div class="grid-2">
    <div class="panel"><h3>Daily output (14 days)</h3><canvas id="chartProductionDaily" height="200"></canvas></div>
    <div class="panel"><h3>Output by block (30d)</h3><canvas id="chartProductionByBlock" height="200"></canvas></div>
</div>
<div class="panel"><h3>Production cost trend</h3><canvas id="chartProductionCost" height="100"></canvas></div>

<div class="panel">
    <h3>Recent production runs</h3>
    <div class="table-container" style="box-shadow:none;border:0;">
        <table class="data-table">
            <thead><tr><th>No.</th><th>Date</th><th>Block</th><th>Good</th><th>Rejected</th><th>Cost</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($topRuns as $r): ?>
                <tr>
                    <td><?= e($r['production_no']) ?></td>
                    <td><?= format_date($r['production_date']) ?></td>
                    <td><?= e($r['name'] . ' ' . $r['size']) ?></td>
                    <td><?= number_format($r['good_quantity']) ?></td>
                    <td><?= number_format($r['rejected_quantity']) ?></td>
                    <td><?= currency_symbol() . money($r['production_cost']) ?></td>
                    <td><a class="btn-sm" href="view.php?id=<?= (int) $r['id'] ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/production_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
