<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'view');

$pageTitle = 'Inventory Analytics';

$rawValue = (float) $pdo->query("SELECT COALESCE(SUM(current_stock * unit_cost),0) FROM raw_materials WHERE status=1")->fetchColumn();
$finishedCost = (float) $pdo->query("
    SELECT COALESCE(SUM(fs.quantity * fs.average_unit_cost),0)
    FROM finished_stock fs JOIN block_types bt ON fs.block_type_id = bt.id WHERE bt.status=1
")->fetchColumn();
$finishedRetail = (float) $pdo->query("
    SELECT COALESCE(SUM(fs.quantity * bt.selling_price),0)
    FROM finished_stock fs JOIN block_types bt ON fs.block_type_id = bt.id WHERE bt.status=1
")->fetchColumn();
$totalCost = $rawValue + $finishedCost;
$totalRetail = $rawValue + $finishedRetail;
$potentialMargin = $finishedRetail - $finishedCost;
$marginPct = $finishedRetail > 0 ? ($potentialMargin / $finishedRetail) * 100 : 0.0;

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Inventory Analytics</h1>
    <a href="overview.php" class="btn btn-secondary">Overview</a>
</div>

<div class="module-kpis">
    <div class="module-kpi">
        <div class="stat-icon" style="background:#4f4fc9;">🧱</div>
        <div><div class="stat-value"><?= currency_symbol() . money($rawValue) ?></div><div class="stat-label">Raw materials (at cost)</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#2f7d4f;">📦</div>
        <div><div class="stat-value"><?= currency_symbol() . money($finishedCost) ?></div><div class="stat-label">Finished goods (at cost)</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#1c2250;">🧾</div>
        <div><div class="stat-value"><?= currency_symbol() . money($totalCost) ?></div><div class="stat-label">Total inventory at cost</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#22b8cf;">🏷</div>
        <div><div class="stat-value"><?= currency_symbol() . money($finishedRetail) ?></div><div class="stat-label">Finished goods at retail</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#7a5cd6;">📈</div>
        <div><div class="stat-value"><?= currency_symbol() . money($potentialMargin) ?></div><div class="stat-label">Unrealised margin (<?= number_format($marginPct, 1) ?>%)</div></div>
    </div>
</div>

<div class="stat-card panel" style="margin-bottom:20px;">
    <strong>Total inventory value:</strong>
    <?= currency_symbol() . money($totalCost) ?>
    <span style="color:#64748b; font-weight:400;">
        (raw materials + finished goods, both at cost). At selling price it would be
        <?= currency_symbol() . money($totalRetail) ?>.
    </span>
</div>

<div class="grid-2">
    <div class="panel"><h3>Valuation split</h3><canvas id="chartInventoryValuation" height="180"></canvas></div>
    <div class="panel"><h3>Movement by type (30d)</h3><canvas id="chartInventoryByType" height="180"></canvas></div>
</div>
<div class="panel"><h3>Raw material value by item</h3><canvas id="chartRawValue" height="120"></canvas></div>
<div class="panel"><h3>Daily material movement (14d)</h3><canvas id="chartInventoryMovement" height="100"></canvas></div>

<style>
.module-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}
.module-kpi{display:flex;align-items:center;gap:14px;padding:19px;background:#fff;border:1px solid #dde1f0;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);border-top:4px solid #7a5cd6}
.module-kpi .stat-icon{width:46px;height:46px;border-radius:13px;font-size:1.2rem;display:flex;align-items:center;justify-content:center;color:#fff}
.module-kpi .stat-value{font-size:1.4rem;color:#1c2250}
.module-kpi .stat-label{font-size:.78rem;color:#64748b}
</style>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/inventory_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
