<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');

$pageTitle = 'Purchasing Analytics';

$stats = $pdo->query("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total_amount),0) AS spend,
           COALESCE(SUM(paid_amount),0) AS paid,
           COALESCE(SUM(balance),0) AS balance
    FROM purchases
    WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (approval_status IS NULL OR approval_status <> 'rejected')
")->fetch();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Purchasing Analytics</h1>
    <div class="header-actions">
        <a href="planning.php" class="btn btn-primary">Planning</a>
        <a href="supplier_prices.php" class="btn btn-secondary">Prices</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#3949ab;">📦</div><div><div class="stat-value"><?= (int) $stats['orders'] ?></div><div class="stat-label">POs (30d)</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#00897b;">💰</div><div><div class="stat-value"><?= currency_symbol() . money($stats['spend']) ?></div><div class="stat-label">Spend</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e74c3c;">📮</div><div><div class="stat-value"><?= currency_symbol() . money($stats['balance']) ?></div><div class="stat-label">Open payables (30d POs)</div></div></div>
</div>

<div class="grid-2">
    <div class="panel"><h3>Monthly spend</h3><canvas id="chartPurchaseMonthly" height="200"></canvas></div>
    <div class="panel"><h3>By supplier (30d)</h3><canvas id="chartPurchaseBySupplier" height="200"></canvas></div>
</div>
<div class="panel"><h3>By material (30d)</h3><canvas id="chartPurchaseByMaterial" height="120"></canvas></div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/purchasing_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
