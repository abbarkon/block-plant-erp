<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'view');

$pageTitle = 'Inventory Overview';

$rawValue = (float) $pdo->query("SELECT COALESCE(SUM(current_stock * unit_cost),0) FROM raw_materials WHERE status=1")->fetchColumn();
$finishedQty = (int) $pdo->query("SELECT COALESCE(SUM(fs.quantity),0) FROM finished_stock fs JOIN block_types bt ON fs.block_type_id=bt.id WHERE bt.status=1")->fetchColumn();
// Cost basis — must match the valuation doughnut fed by inventory_charts_data.php
$finishedValue = (float) $pdo->query("SELECT COALESCE(SUM(fs.quantity * fs.average_unit_cost),0) FROM finished_stock fs JOIN block_types bt ON fs.block_type_id=bt.id WHERE bt.status=1")->fetchColumn();
$finishedRetail = (float) $pdo->query("SELECT COALESCE(SUM(fs.quantity * bt.selling_price),0) FROM finished_stock fs JOIN block_types bt ON fs.block_type_id=bt.id WHERE bt.status=1")->fetchColumn();
$lowStock = (int) $pdo->query("SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id=rl.material_id WHERE rm.status=1 AND rm.current_stock<=rl.reorder_point")->fetchColumn();
$adjustments30 = (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE adjustment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

require_once "../partials/header.php";
?>

<div class="module-hero inventory-hero">
    <div><span class="module-eyebrow">MATERIAL CONTROL</span><h1>Inventory Overview</h1><p>Track material value, finished stock, movement, and reorder risk.</p></div>
    <div class="header-actions">
        <a href="analytics.php" class="btn btn-accent">Analytics</a>
        <a href="../purchases/planning.php" class="btn btn-primary">Purchase planning</a>
        <a href="../production/planning.php" class="btn btn-secondary">Production planning</a>
    </div>
</div>

<div class="module-kpis">
    <div class="module-kpi">
        <div class="stat-icon" style="background:#4f4fc9;">🧱</div>
        <div><div class="stat-value"><?= currency_symbol() . money($rawValue) ?></div><div class="stat-label">Raw materials (at cost)</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#2f7d4f;">📦</div>
        <div><div class="stat-value"><?= currency_symbol() . money($finishedValue) ?></div><div class="stat-label">Finished goods (at cost)</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#22b8cf;">🏷</div>
        <div><div class="stat-value"><?= currency_symbol() . money($finishedRetail) ?></div><div class="stat-label">Finished goods (at retail)</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#f39c12;">🔢</div>
        <div><div class="stat-value"><?= number_format($finishedQty) ?></div><div class="stat-label">Blocks in stock</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#e74c3c;">⚠</div>
        <div><div class="stat-value"><?= $lowStock ?></div><div class="stat-label">Low raw materials</div></div>
    </div>
</div>

<div class="module-section-heading"><div><h2>Inventory intelligence</h2><p>Value and movement signals across the plant.</p></div><span class="badge <?= $lowStock > 0 ? 'badge-danger' : 'badge-success' ?>"><?= $lowStock ?> low-stock alerts</span></div>
<div class="grid-2 module-charts">
    <div class="panel"><h3>Stock valuation split</h3><canvas id="chartInventoryValuation" height="200"></canvas></div>
    <div class="panel"><h3>Material movement (14 days)</h3><canvas id="chartInventoryMovement" height="200"></canvas></div>
</div>

<div class="panel module-quick-links">
    <h3>Quick links</h3>
    <div class="header-actions">
        <a href="materials.php" class="btn btn-secondary">Raw materials</a>
        <a href="index.php" class="btn btn-secondary">Finished stock</a>
        <a href="movement.php" class="btn btn-secondary">Stock movement</a>
        <a href="adjustments.php" class="btn btn-secondary">Adjustments (<?= $adjustments30 ?> / 30d)</a>
        <a href="reorder.php" class="btn btn-secondary">Reorder levels</a>
        <a href="low_stock_alert.php" class="btn btn-accent">Low stock alerts</a>
    </div>
</div>

<style>
.module-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:22px;margin-bottom:24px;padding:28px 32px;border-radius:18px;color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.inventory-hero{background:linear-gradient(120deg,#1c2250,#4f4fc9 62%,#7a5cd6)}.module-hero h1{margin:3px 0 5px;color:#fff;font-size:2.1rem}.module-hero p{margin:0;color:rgba(255,255,255,.76)}.module-eyebrow{font-size:.7rem;font-weight:800;letter-spacing:.14em;color:#bce6df}.module-hero .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);box-shadow:none}.module-hero .btn-accent{background:#22b8cf!important;border-color:#22b8cf}.module-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}.module-kpi{display:flex;align-items:center;gap:14px;padding:19px;background:#fff;border:1px solid #dde1f0;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);border-top:4px solid #7a5cd6}.module-kpi .stat-icon{width:46px;height:46px;border-radius:13px;font-size:1.2rem}.module-kpi .stat-value{font-size:1.4rem;color:#1c2250}.module-kpi .stat-label{font-size:.78rem}.module-section-heading{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:28px 0 12px}.module-section-heading h2{margin:0;font-size:1.25rem}.module-section-heading p{margin:4px 0 0;color:#646b8c;font-size:.84rem}.module-charts .panel{margin-bottom:0}.module-quick-links{margin-top:20px;background:linear-gradient(145deg,#fff,#f1f8f5)}
@media(max-width:800px){.module-hero{align-items:flex-start;flex-direction:column}.module-hero .header-actions{justify-content:flex-start}}@media(max-width:500px){.module-hero{padding:24px 20px}.module-hero h1{font-size:1.75rem}.module-section-heading{align-items:flex-start;flex-direction:column}}
</style>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/inventory_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
