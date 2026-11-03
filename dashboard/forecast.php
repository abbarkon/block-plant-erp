<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');
ensure_expense_approval_schema();

$pageTitle = 'Budget & Forecast';

$months = [];
$monthLabels = [];
$expenses = [];
$sales = [];

for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m-01', strtotime("-$i month"));
    $label = date('M Y', strtotime($date));
    $monthLabels[] = $label;

    $salesVal = (float) $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= ? AND sale_date < DATE_ADD(?, INTERVAL 1 MONTH)")->execute([$date, $date]) ? 0 : 0;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= ? AND sale_date < DATE_ADD(?, INTERVAL 1 MONTH)");
    $stmt->execute([$date, $date]);
    $sales[] = (float) $stmt->fetchColumn();

    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < DATE_ADD(?, INTERVAL 1 MONTH) AND (approval_status = 'approved' OR approval_status IS NULL)");
    $expStmt->execute([$date, $date]);
    $expenses[] = (float) $expStmt->fetchColumn();
}

$averageSales = array_sum($sales) / max(1, count($sales));
$averageExpenses = array_sum($expenses) / max(1, count($expenses));
$nextMonthSales = $averageSales * 1.05;
$nextMonthExpenses = $averageExpenses * 1.03;
$nextMonthNet = $nextMonthSales - $nextMonthExpenses;

$budget = [
    'target_sales' => $averageSales * 1.1,
    'target_expenses' => $averageExpenses * 1.08,
    'target_profit' => ($averageSales * 1.1) - ($averageExpenses * 1.08),
];

$categoryBreakdown = $pdo->query("SELECT category, COALESCE(SUM(amount),0) AS total FROM expenses WHERE approval_status = 'approved' OR approval_status IS NULL GROUP BY category ORDER BY total DESC LIMIT 6")->fetchAll();
$productionTrend = $pdo->query("SELECT DATE_FORMAT(production_date, '%Y-%m') AS month_name, COALESCE(SUM(good_quantity),0) AS produced FROM production WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY DATE_FORMAT(production_date, '%Y-%m') ORDER BY month_name ASC")->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Budget & Forecast</h1>
        <p style="color:#64748b; margin:6px 0 0;">Revenue and cost projection for the next operating cycle</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to dashboard</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#16a34a;">📈</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($budget['target_sales']) ?></div>
            <div class="stat-label">Projected sales</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#dc2626;">💸</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($budget['target_expenses']) ?></div>
            <div class="stat-label">Projected expenses</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2563eb;">📊</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($budget['target_profit']) ?></div>
            <div class="stat-label">Projected profit</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f59e0b;">🔮</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($nextMonthNet) ?></div>
            <div class="stat-label">Next month net</div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:24px;">
    <div class="panel">
        <h3>Monthly trend</h3>
        <table class="data-table">
            <thead><tr><th>Month</th><th>Sales</th><th>Expenses</th></tr></thead>
            <tbody>
                <?php foreach ($monthLabels as $index => $label): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><?= currency_symbol() . money($sales[$index]) ?></td>
                    <td><?= currency_symbol() . money($expenses[$index]) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h3>Expense mix</h3>
        <table class="data-table">
            <thead><tr><th>Category</th><th>Cost</th></tr></thead>
            <tbody>
                <?php foreach ($categoryBreakdown as $category): ?>
                <tr>
                    <td><?= e($category['category']) ?></td>
                    <td><?= currency_symbol() . money($category['total']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:20px;">
    <h3>Production forecast</h3>
    <table class="data-table">
        <thead><tr><th>Month</th><th>Blocks produced</th></tr></thead>
        <tbody>
            <?php foreach ($productionTrend as $row): ?>
            <tr>
                <td><?= e($row['month_name']) ?></td>
                <td><?= number_format((float) $row['produced']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
