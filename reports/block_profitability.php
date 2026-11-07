<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

$days = max(7, min(365, (int) ($_GET['days'] ?? 30)));
$since = date('Y-m-d', strtotime("-{$days} days"));

$rows = [];
try {
    $st = $pdo->prepare("SELECT bt.id, bt.name, bt.size, bt.selling_price,
            COALESCE(fs.quantity,0) AS stock_qty, COALESCE(fs.average_unit_cost,0) AS avg_cost,
            (SELECT COALESCE(SUM(p.good_quantity),0) FROM production p WHERE p.block_type_id=bt.id AND p.production_date >= ?) AS produced,
            (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.block_type_id=bt.id AND s.sale_date >= ?) AS sold_qty,
            (SELECT COALESCE(SUM(si.total_price),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.block_type_id=bt.id AND s.sale_date >= ?) AS sold_value
        FROM block_types bt LEFT JOIN finished_stock fs ON fs.block_type_id=bt.id
        WHERE bt.status=1 ORDER BY bt.name, bt.size");
    $st->execute([$since, $since, $since]);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    $rows = [];
}

$pageTitle = 'Block Profitability';
require_once "../partials/header.php";
$cs = currency_symbol();
?>
<div class="page-header">
    <h1>Block Profitability</h1>
    <form method="get" class="no-print" style="display:flex;gap:8px;align-items:center;">
        <select name="days" onchange="this.form.submit()">
            <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label): ?>
            <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-accent" type="button" onclick="window.print()">Print</button>
    </form>
</div>
<p style="color:#62767e;margin:-6px 0 16px;">Selling price against the average production cost in stock. Sales and production cover the last <?= $days ?> days.</p>
<div class="panel"><div class="table-container">
<table class="data-table">
    <thead><tr>
        <th>Block</th><th style="text-align:right">Selling price</th><th style="text-align:right">Cost / block</th>
        <th style="text-align:right">Profit / block</th><th style="text-align:right">Margin</th>
        <th style="text-align:right">In stock</th><th style="text-align:right">Produced</th><th style="text-align:right">Sold</th><th style="text-align:right">Sales value</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $price = (float) $r['selling_price'];
        $cost = (float) $r['avg_cost'];
        $profit = $price - $cost;
        $margin = $price > 0 ? ($profit / $price) * 100 : 0;
        $hasCost = $cost > 0;
        $color = !$hasCost ? '#62767e' : ($profit < 0 ? '#c0392b' : ($margin < 10 ? '#b45309' : '#15803d'));
    ?>
        <tr>
            <td><strong><?= e($r['name'] . ' ' . $r['size']) ?></strong></td>
            <td style="text-align:right"><?= $cs . money($price) ?></td>
            <td style="text-align:right"><?= $hasCost ? $cs . money($cost) : '—' ?></td>
            <td style="text-align:right;color:<?= $color ?>;font-weight:700"><?= $hasCost ? $cs . money($profit) : '—' ?></td>
            <td style="text-align:right;color:<?= $color ?>;font-weight:700"><?= $hasCost ? number_format($margin, 1) . '%' : '—' ?></td>
            <td style="text-align:right"><?= number_format((int) $r['stock_qty']) ?></td>
            <td style="text-align:right"><?= number_format((int) $r['produced']) ?></td>
            <td style="text-align:right"><?= number_format((int) $r['sold_qty']) ?></td>
            <td style="text-align:right"><?= $cs . money($r['sold_value']) ?></td>
        </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9">No data yet.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<p style="font-size:.82rem;color:#62767e;">Red means selling below cost, amber means a margin under 10%. "—" means no production cost has been recorded for that block yet.</p>
<style>@media print{.no-print,.navbar,.sidebar,.topbar{display:none!important}body{background:#fff}}</style>
<?php require_once "../partials/footer.php"; ?>
