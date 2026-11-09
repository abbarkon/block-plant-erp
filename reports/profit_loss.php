<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_expense_approval_schema();
ensure_manufacturing_costing_schema();

$pageTitle = 'Profit & Loss';
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$revenueStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0), COUNT(*) FROM sales WHERE sale_date BETWEEN ? AND ?");
$revenueStmt->execute([$from, $to]);
[$totalRevenue, $saleCount] = $revenueStmt->fetch(PDO::FETCH_NUM);

$cogsStmt = $pdo->prepare("SELECT COALESCE(SUM(cogs_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?");
$cogsStmt->execute([$from, $to]);
$totalCogs = (float) $cogsStmt->fetchColumn();

$productionStmt = $pdo->prepare("SELECT COALESCE(SUM(production_cost), 0), COALESCE(SUM(good_quantity), 0) FROM production WHERE production_date BETWEEN ? AND ?");
$productionStmt->execute([$from, $to]);
[$productionSpend, $blocksProduced] = $productionStmt->fetch(PDO::FETCH_NUM);

$expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'");
$expenseStmt->execute([$from, $to]);
[$totalExpenses, $expenseCount] = $expenseStmt->fetch(PDO::FETCH_NUM);

$grossProfit = (float) $totalRevenue - (float) $totalCogs;
$netProfit = $grossProfit - (float) $totalExpenses;
$grossMargin = (float) $totalRevenue > 0 ? ($grossProfit / (float) $totalRevenue) * 100 : 0.0;
$netMargin = (float) $totalRevenue > 0 ? ($netProfit / (float) $totalRevenue) * 100 : 0.0;

$expenseBreakdownStmt = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved' GROUP BY category ORDER BY total DESC");
$expenseBreakdownStmt->execute([$from, $to]);
$expenseBreakdown = $expenseBreakdownStmt->fetchAll();

require_once "../partials/header.php";
?>

<style>
    .pl-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width:950px) { .pl-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .pl-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .pl-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .pl-kpi strong { display:block; margin-top:4px; color:#1f2933; font-size:1.3rem; }
    .pl-kpi.good { border-left-color:#d9742f; }
    .pl-kpi.bad { border-left-color:#b91c1c; }
    .amount-pos { color:#166534; }
    .amount-neg { color:#991b1b; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .pl-kpi, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>Profit &amp; Loss</h1>
        <p style="color:#64748b; margin:6px 0 0;">Operating results for <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>, with cost of goods sold matched to the sales that generated it.</p>
    </div>
    <div class="header-actions no-print">
        <a href="export_csv.php?type=profit_loss&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-secondary">Export CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
        <a href="financial.php" class="btn btn-secondary">Financial Reports</a>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div class="grid-2">
        <div>
            <label>From</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
    </div>
    <div style="margin-top:12px; display:flex; gap:8px;">
        <button type="submit" class="btn btn-accent">Refresh</button>
        <a href="profit_loss.php" class="btn btn-secondary">This month</a>
    </div>
</form>

<div class="pl-kpis">
    <div class="pl-kpi"><span>Revenue</span><strong><?= e(currency_symbol()) . money($totalRevenue) ?></strong><small style="color:#64748b;"><?= number_format((int) $saleCount) ?> invoices</small></div>
    <div class="pl-kpi"><span>Gross profit</span><strong class="<?= $grossProfit >= 0 ? 'amount-pos' : 'amount-neg' ?>"><?= e(currency_symbol()) . money($grossProfit) ?></strong><small style="color:#64748b;"><?= number_format($grossMargin, 1) ?>% margin</small></div>
    <div class="pl-kpi"><span>Operating expenses</span><strong><?= e(currency_symbol()) . money($totalExpenses) ?></strong><small style="color:#64748b;"><?= number_format((int) $expenseCount) ?> approved vouchers</small></div>
    <div class="pl-kpi <?= $netProfit >= 0 ? 'good' : 'bad' ?>"><span>Net profit / loss</span><strong class="<?= $netProfit >= 0 ? 'amount-pos' : 'amount-neg' ?>"><?= e(currency_symbol()) . money($netProfit) ?></strong><small style="color:#64748b;"><?= number_format($netMargin, 1) ?>% net margin</small></div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Income statement</h3>
        <table class="data-table">
            <thead><tr><th>Item</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
                <tr><td>Revenue</td><td style="text-align:right;"><?= e(currency_symbol()) . money($totalRevenue) ?></td></tr>
                <tr><td>Cost of goods sold <span style="color:#64748b;font-size:.78rem;">(weighted-average cost of blocks sold)</span></td><td style="text-align:right;">(<?= e(currency_symbol()) . money($totalCogs) ?>)</td></tr>
                <tr style="font-weight:bold;background:#f8f9fa;"><td>Gross profit</td><td style="text-align:right;" class="<?= $grossProfit >= 0 ? 'amount-pos' : 'amount-neg' ?>"><?= e(currency_symbol()) . money($grossProfit) ?></td></tr>
                <tr><td>Operating expenses</td><td style="text-align:right;">(<?= e(currency_symbol()) . money($totalExpenses) ?>)</td></tr>
                <tr style="font-weight:bold;background:#f8f9fa;"><td>Net profit / loss</td><td style="text-align:right;" class="<?= $netProfit >= 0 ? 'amount-pos' : 'amount-neg' ?>"><?= e(currency_symbol()) . money($netProfit) ?></td></tr>
            </tbody>
        </table>
        <p style="color:#64748b;font-size:.78rem;margin-top:12px;">
            COGS comes from each invoice's posted weighted-average cost, not from production spend in the same period.
            Production spend for the period was <?= e(currency_symbol()) . money($productionSpend) ?>
            for <?= number_format((int) $blocksProduced) ?> good blocks and is a separate manufacturing metric.
        </p>
    </div>

    <div class="panel">
        <h3>Expense breakdown</h3>
        <?php if (!$expenseBreakdown): ?>
            <p style="margin:0;color:#64748b;">No approved expenses recorded for this period.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Category</th><th style="text-align:right;">Amount</th><th style="text-align:right;">% of expenses</th></tr></thead>
                <tbody>
                    <?php foreach ($expenseBreakdown as $row): ?>
                    <tr>
                        <td><?= e($row['category']) ?></td>
                        <td style="text-align:right;"><?= e(currency_symbol()) . money($row['total']) ?></td>
                        <td style="text-align:right;"><?= (float) $totalExpenses > 0 ? number_format(((float) $row['total'] / (float) $totalExpenses) * 100, 1) : '0.0' ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:bold;background:#f8f9fa;"><td>Total</td><td style="text-align:right;"><?= e(currency_symbol()) . money($totalExpenses) ?></td><td style="text-align:right;">100.0%</td></tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
