<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('dashboard', 'view');
require_permission('reports', 'view');
ensure_expense_approval_schema();

$pageTitle = 'Operational Insights';
$monthStart = date('Y-m-01');

$stats = [
    'sales_mtd' => (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= '$monthStart'")->fetchColumn(),
    'expenses_mtd' => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= '$monthStart' AND (approval_status = 'approved' OR approval_status IS NULL)")->fetchColumn(),
    'production_mtd' => (float) $pdo->query("SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date >= '$monthStart'")->fetchColumn(),
    'receivables' => (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance > 0")->fetchColumn(),
    'payables' => (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance > 0")->fetchColumn(),
    'low_stock' => (int) $pdo->query("SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point")->fetchColumn(),
    'customers_due' => (int) $pdo->query("SELECT COUNT(*) FROM sales WHERE balance > 0")->fetchColumn(),
    'suppliers_due' => (int) $pdo->query("SELECT COUNT(*) FROM purchases WHERE balance > 0")->fetchColumn(),
];

$topCustomers = $pdo->query("SELECT c.name, COALESCE(SUM(s.total_amount),0) AS total_amount, COALESCE(SUM(s.balance),0) AS balance FROM sales s JOIN customers c ON c.id = s.customer_id GROUP BY c.id, c.name ORDER BY total_amount DESC LIMIT 5")->fetchAll();
$topSuppliers = $pdo->query("SELECT sp.name, COALESCE(SUM(p.total_amount),0) AS total_amount, COALESCE(SUM(p.balance),0) AS balance FROM purchases p JOIN suppliers sp ON sp.id = p.supplier_id GROUP BY sp.id, sp.name ORDER BY total_amount DESC LIMIT 5")->fetchAll();
$lowStockItems = $pdo->query("SELECT rm.name, rm.current_stock, rl.reorder_point, rm.unit FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point ORDER BY rm.current_stock ASC LIMIT 10")->fetchAll();
// Build the six-month sales/expense trend from independent aggregates; the
// previous correlated alias subquery could not reliably resolve month_name.
$salesFlow = $pdo->query("SELECT DATE_FORMAT(sale_date, '%Y-%m') AS month_name, COALESCE(SUM(total_amount),0) AS sales FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY DATE_FORMAT(sale_date, '%Y-%m')")->fetchAll();
$expenseFlow = $pdo->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_name, COALESCE(SUM(amount),0) AS expenses FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) AND approval_status = 'approved' GROUP BY DATE_FORMAT(expense_date, '%Y-%m')")->fetchAll();
$cashFlowByMonth = [];
foreach ($salesFlow as $row) {
    $cashFlowByMonth[$row['month_name']] = ['month_name' => $row['month_name'], 'sales' => (float) $row['sales'], 'expenses' => 0.0];
}
foreach ($expenseFlow as $row) {
    if (!isset($cashFlowByMonth[$row['month_name']])) {
        $cashFlowByMonth[$row['month_name']] = ['month_name' => $row['month_name'], 'sales' => 0.0, 'expenses' => 0.0];
    }
    $cashFlowByMonth[$row['month_name']]['expenses'] = (float) $row['expenses'];
}
ksort($cashFlowByMonth);
$cashFlow = array_values($cashFlowByMonth);

require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Operational Insights</h1>
        <p style="color:#64748b; margin:6px 0 0;">Plant performance and action items</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to dashboard</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#2563eb;">💰</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($stats['sales_mtd']) ?></div>
            <div class="stat-label">Sales MTD</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#dc2626;">📉</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($stats['expenses_mtd']) ?></div>
            <div class="stat-label">Expenses MTD</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#16a34a;">🏭</div>
        <div>
            <div class="stat-value"><?= number_format($stats['production_mtd']) ?></div>
            <div class="stat-label">Blocks produced</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f59e0b;">⚠️</div>
        <div>
            <div class="stat-value"><?= $stats['low_stock'] ?></div>
            <div class="stat-label">Low stock alerts</div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:24px;">
    <div class="panel">
        <h3>Cash position</h3>
        <p><strong>Receivables:</strong> <?= currency_symbol() . money($stats['receivables']) ?></p>
        <p><strong>Payables:</strong> <?= currency_symbol() . money($stats['payables']) ?></p>
        <p><strong>Customer balances open:</strong> <?= $stats['customers_due'] ?></p>
        <p><strong>Supplier balances open:</strong> <?= $stats['suppliers_due'] ?></p>
    </div>
    <div class="panel">
        <h3>Action center</h3>
        <ul style="margin:0; padding-left:18px; line-height:1.9;">
            <li>Follow up on overdue customer balances.</li>
            <li>Review materials below reorder threshold.</li>
            <li>Check production output against targets.</li>
            <li>Confirm upcoming supplier payment schedules.</li>
        </ul>
    </div>
</div>

<div class="grid-2" style="margin-top:20px;">
    <div class="panel">
        <h3>Top customers</h3>
        <table class="data-table">
            <thead><tr><th>Customer</th><th>Total sales</th><th>Open balance</th></tr></thead>
            <tbody>
                <?php foreach ($topCustomers as $customer): ?>
                <tr>
                    <td><?= e($customer['name']) ?></td>
                    <td><?= currency_symbol() . money($customer['total_amount']) ?></td>
                    <td><?= currency_symbol() . money($customer['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h3>Top suppliers</h3>
        <table class="data-table">
            <thead><tr><th>Supplier</th><th>Purchases</th><th>Open balance</th></tr></thead>
            <tbody>
                <?php foreach ($topSuppliers as $supplier): ?>
                <tr>
                    <td><?= e($supplier['name']) ?></td>
                    <td><?= currency_symbol() . money($supplier['total_amount']) ?></td>
                    <td><?= currency_symbol() . money($supplier['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid-2" style="margin-top:20px;">
    <div class="panel">
        <h3>Low stock items</h3>
        <table class="data-table">
            <thead><tr><th>Material</th><th>Current</th><th>Reorder point</th></tr></thead>
            <tbody>
                <?php foreach ($lowStockItems as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td><?= number_format((float) $item['current_stock'], 2) ?> <?= e($item['unit']) ?></td>
                    <td><?= number_format((float) $item['reorder_point'], 2) ?> <?= e($item['unit']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel">
        <h3>Recent cash flow trend</h3>
        <table class="data-table">
            <thead><tr><th>Month</th><th>Sales</th><th>Expenses</th></tr></thead>
            <tbody>
                <?php foreach ($cashFlow as $row): ?>
                <tr>
                    <td><?= e($row['month_name']) ?></td>
                    <td><?= currency_symbol() . money($row['sales']) ?></td>
                    <td><?= currency_symbol() . money($row['expenses']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
