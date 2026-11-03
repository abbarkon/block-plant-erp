<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_manufacturing_costing_schema();

$pageTitle = 'Inventory Reports';

$rawMaterials = $pdo->query("SELECT * FROM raw_materials WHERE status = 1 ORDER BY name")->fetchAll();
$finishedStock = $pdo->query("SELECT fs.*, bt.name, bt.size, bt.selling_price,
        COALESCE(fs.average_unit_cost, 0) AS avg_cost
    FROM finished_stock fs
    JOIN block_types bt ON bt.id = fs.block_type_id
    ORDER BY bt.name")->fetchAll();

$totalRawValue = 0.0;
foreach ($rawMaterials as $mat) {
    $totalRawValue += (float) $mat['current_stock'] * (float) $mat['unit_cost'];
}
$totalFinishedCost = 0.0;
$totalFinishedRetail = 0.0;
$totalFinishedQty = 0.0;
foreach ($finishedStock as $stock) {
    $totalFinishedCost += (float) $stock['quantity'] * (float) $stock['avg_cost'];
    $totalFinishedRetail += (float) $stock['quantity'] * (float) $stock['selling_price'];
    $totalFinishedQty += (float) $stock['quantity'];
}
$totalInventoryCost = $totalRawValue + $totalFinishedCost;
$unrealisedMargin = $totalFinishedRetail - $totalFinishedCost;

require_once "../partials/header.php";
?>

<style>
    .inv-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width:950px) { .inv-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .inv-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .inv-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .inv-kpi strong { display:block; margin-top:4px; color:#183b56; font-size:1.3rem; }
    .inv-kpi.cost { border-left-color:#2a9d8f; }
    .inv-kpi.retail { border-left-color:#4f46e5; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .inv-kpi, .panel, div[style*="box-shadow"] { box-shadow:none !important; } }
</style>

<div class="page-header">
    <div>
        <h1>📦 Inventory Reports</h1>
        <p style="color:#64748b;margin:6px 0 0;">Stock quantities with raw materials and finished goods valued at cost; retail value is shown separately.</p>
    </div>
    <div class="header-actions no-print">
        <a href="export_csv.php?type=inventory" class="btn btn-secondary">Export CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
    </div>
</div>

<div class="inv-kpis">
    <div class="inv-kpi cost"><span>Raw materials at cost</span><strong><?= e(currency_symbol()) . money($totalRawValue) ?></strong><small style="color:#64748b;"><?= number_format(count($rawMaterials)) ?> materials</small></div>
    <div class="inv-kpi cost"><span>Finished goods at cost</span><strong><?= e(currency_symbol()) . money($totalFinishedCost) ?></strong><small style="color:#64748b;"><?= number_format($totalFinishedQty) ?> blocks</small></div>
    <div class="inv-kpi"><span>Total inventory at cost</span><strong><?= e(currency_symbol()) . money($totalInventoryCost) ?></strong></div>
    <div class="inv-kpi retail"><span>Finished goods retail value</span><strong><?= e(currency_symbol()) . money($totalFinishedRetail) ?></strong><small style="color:#64748b;"><?= e(currency_symbol()) . money($unrealisedMargin) ?> unrealised margin</small></div>
</div>

<div class="panel" style="margin-bottom:20px;">
    <h3>Raw materials stock</h3>
    <table class="data-table">
        <thead><tr><th>Material</th><th>Unit</th><th style="text-align:right;">Current stock</th><th style="text-align:right;">Unit cost</th><th style="text-align:right;">Stock value (cost)</th></tr></thead>
        <tbody>
            <?php if (!$rawMaterials): ?><tr><td colspan="5">No active raw materials.</td></tr><?php endif; ?>
            <?php foreach ($rawMaterials as $mat): ?>
            <tr>
                <td><?= e($mat['name']) ?></td>
                <td><?= e($mat['unit']) ?></td>
                <td style="text-align:right;"><?= number_format((float) $mat['current_stock'], 2) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($mat['unit_cost']) ?></td>
                <td style="text-align:right;"><strong><?= e(currency_symbol()) . money((float) $mat['current_stock'] * (float) $mat['unit_cost']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f8f9fa;font-weight:bold;"><td colspan="4">Total raw materials</td><td style="text-align:right;"><?= e(currency_symbol()) . money($totalRawValue) ?></td></tr>
        </tbody>
    </table>
</div>

<div class="panel">
    <h3>Finished goods stock</h3>
    <table class="data-table">
        <thead><tr><th>Block type</th><th>Size</th><th style="text-align:right;">Quantity</th><th style="text-align:right;">Avg cost</th><th style="text-align:right;">Cost value</th><th style="text-align:right;">Selling price</th><th style="text-align:right;">Retail value</th></tr></thead>
        <tbody>
            <?php if (!$finishedStock): ?><tr><td colspan="7">No finished stock records.</td></tr><?php endif; ?>
            <?php foreach ($finishedStock as $stock): ?>
            <tr>
                <td><?= e($stock['name']) ?></td>
                <td><?= e($stock['size']) ?></td>
                <td style="text-align:right;"><?= number_format((float) $stock['quantity']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($stock['avg_cost']) ?></td>
                <td style="text-align:right;"><strong><?= e(currency_symbol()) . money((float) $stock['quantity'] * (float) $stock['avg_cost']) ?></strong></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($stock['selling_price']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money((float) $stock['quantity'] * (float) $stock['selling_price']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f8f9fa;font-weight:bold;">
                <td colspan="4">Total finished goods</td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totalFinishedCost) ?></td>
                <td></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totalFinishedRetail) ?></td>
            </tr>
        </tbody>
    </table>
    <p style="color:#64748b;font-size:.78rem;margin-top:10px;">Cost value uses the weighted-average manufacturing cost stored on each finished-stock record. Retail value is the current selling price × quantity and is not the accounting inventory value.</p>
</div>

<?php require_once "../partials/footer.php"; ?>
