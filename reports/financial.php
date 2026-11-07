<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_expense_approval_schema();
ensure_manufacturing_costing_schema();

$pageTitle = 'Financial Reports';
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

$expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'");
$expenseStmt->execute([$from, $to]);
[$totalExpenses, $expenseCount] = $expenseStmt->fetch(PDO::FETCH_NUM);

$productionStmt = $pdo->prepare("SELECT COALESCE(SUM(production_cost), 0) FROM production WHERE production_date BETWEEN ? AND ?");
$productionStmt->execute([$from, $to]);
$totalProductionCost = (float) $productionStmt->fetchColumn();

$purchaseStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ? AND (approval_status IS NULL OR approval_status <> 'rejected')");
$purchaseStmt->execute([$from, $to]);
$totalPurchases = (float) $purchaseStmt->fetchColumn();

$grossProfit = (float) $totalRevenue - $totalCogs;
$netProfit = $grossProfit - (float) $totalExpenses;
$grossMargin = (float) $totalRevenue > 0 ? ($grossProfit / (float) $totalRevenue) * 100 : 0.0;
$netMargin = (float) $totalRevenue > 0 ? ($netProfit / (float) $totalRevenue) * 100 : 0.0;

$expenseBreakdownStmt = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved' GROUP BY category ORDER BY total DESC");
$expenseBreakdownStmt->execute([$from, $to]);
$expenseCategories = $expenseBreakdownStmt->fetchAll();

require_once "../partials/header.php";
?>

<style>
    .fin-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width:950px) { .fin-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .fin-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .fin-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .fin-kpi strong { display:block; margin-top:4px; color:#183b56; font-size:1.3rem; }
    .fin-kpi.good { border-left-color:#2a9d8f; }
    .fin-kpi.bad { border-left-color:#b91c1c; }
    .pos { color:#166534; }
    .neg { color:#991b1b; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .fin-kpi, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>💰 Financial Reports</h1>
        <p style="color:#64748b; margin:6px 0 0;">Income statement and cost overview for <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>.</p>
    </div>
    <div class="header-actions no-print">
        <a href="profit_loss.php?from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-secondary">Full P&amp;L</a>
        <a href="balance_sheet.php?as_of=<?= e($to) ?>" class="btn btn-secondary">Balance sheet</a>
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div class="grid-3">
        <div>
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-accent">Filter</button>
            <a href="financial.php" class="btn btn-secondary">This month</a>
        </div>
    </div>
</form>

<div class="fin-kpis">
    <div class="fin-kpi"><span>Revenue</span><strong><?= e(currency_symbol()) . money($totalRevenue) ?></strong><small style="color:#64748b;"><?= number_format((int) $saleCount) ?> invoices</small></div>
    <div class="fin-kpi"><span>Gross profit</span><strong class="<?= $grossProfit >= 0 ? 'pos' : 'neg' ?>"><?= e(currency_symbol()) . money($grossProfit) ?></strong><small style="color:#64748b;"><?= number_format($grossMargin, 1) ?>% margin</small></div>
    <div class="fin-kpi"><span>Expenses</span><strong><?= e(currency_symbol()) . money($totalExpenses) ?></strong><small style="color:#64748b;"><?= number_format((int) $expenseCount) ?> approved</small></div>
    <div class="fin-kpi <?= $netProfit >= 0 ? 'good' : 'bad' ?>"><span>Net profit</span><strong class="<?= $netProfit >= 0 ? 'pos' : 'neg' ?>"><?= e(currency_symbol()) . money($netProfit) ?></strong><small style="color:#64748b;"><?= number_format($netMargin, 1) ?>% net margin</small></div>
</div>

<div class="grid-2">
    <div class="panel">
        <h2 style="margin:0 0 16px;">Income statement</h2>
        <table class="data-table">
            <tbody>
                <tr><td><strong>Revenue</strong></td><td style="text-align:right;"><?= e(currency_symbol()) . money($totalRevenue) ?></td></tr>
                <tr><td><strong>Cost of goods sold</strong><div style="color:#64748b;font-size:.78rem;">Weighted-average cost of blocks sold</div></td><td style="text-align:right;">(<?= e(currency_symbol()) . money($totalCogs) ?>)</td></tr>
                <tr style="font-weight:bold;background:#f8f9fa;"><td>Gross profit</td><td style="text-align:right;" class="<?= $grossProfit >= 0 ? 'pos' : 'neg' ?>"><?= e(currency_symbol()) . money($grossProfit) ?></td></tr>
                <tr><td><strong>Operating expenses</strong></td><td style="text-align:right;">(<?= e(currency_symbol()) . money($totalExpenses) ?>)</td></tr>
                <tr style="font-weight:bold;background:#f8f9fa;"><td>Net profit</td><td style="text-align:right;" class="<?= $netProfit >= 0 ? 'pos' : 'neg' ?>"><?= e(currency_symbol()) . money($netProfit) ?></td></tr>
            </tbody>
        </table>
        <p style="color:#64748b;font-size:.78rem;margin-top:12px;">
            COGS is matched to the sales period. Production spend (<?= e(currency_symbol()) . money($totalProductionCost) ?>) and purchases
            (<?= e(currency_symbol()) . money($totalPurchases) ?>) are shown as operational metrics, not as cost of sales.
        </p>
    </div>

    <div class="panel">
        <h2 style="margin:0 0 16px;">Expense breakdown</h2>
        <?php if (!$expenseCategories): ?>
        <p style="color:#64748b;">No approved expenses recorded for this period.</p>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Category</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Share</th></tr></thead>
            <tbody>
                <?php foreach ($expenseCategories as $cat): ?>
                <tr>
                    <td><?= e($cat['category']) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($cat['total']) ?></td>
                    <td style="text-align:right;color:#64748b;"><?= (float) $totalExpenses > 0 ? number_format(((float) $cat['total'] / (float) $totalExpenses) * 100, 1) : '0.0' ?>%</td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold;background:#f8f9fa;"><td>Total</td><td style="text-align:right;"><?= e(currency_symbol()) . money($totalExpenses) ?></td><td style="text-align:right;">100.0%</td></tr>
            </tbody>
        </table>
        <?php endif; ?>
        <div style="margin-top:18px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="background:#eaf3f0;border-radius:8px;padding:10px 12px;"><span style="display:block;color:#64748b;font-size:.74rem;text-transform:uppercase;">Production spend</span><strong><?= e(currency_symbol()) . money($totalProductionCost) ?></strong></div>
            <div style="background:#eaf3f0;border-radius:8px;padding:10px 12px;"><span style="display:block;color:#64748b;font-size:.74rem;text-transform:uppercase;">Purchases</span><strong><?= e(currency_symbol()) . money($totalPurchases) ?></strong></div>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
