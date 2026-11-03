<?php
// Read-only JSON endpoint used by dashboard widgets.
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
// Company-wide financial data is only exposed to users who can see reports.
if (!has_permission('dashboard', 'view') || !has_permission('reports', 'view')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

ensure_expense_approval_schema();
ensure_purchase_payment_schema();
header('Content-Type: application/json');

function v($p, $q, $a = []) {
    $s = $p->prepare($q);
    $s->execute($a);
    return $s->fetchColumn();
}

$d = date('Y-m-d');
$m = date('Y-m-01');

$monthSales = (float) v($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= ?", [$m]);
$monthCogs = (float) v($pdo, "SELECT COALESCE(SUM(cogs_amount),0) FROM sales WHERE sale_date >= ?", [$m]);
$monthExpenses = (float) v($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND approval_status = 'approved'", [$m]);

$data = [
    'today' => [
        'production' => (int) v($pdo, "SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date=?", [$d]),
        'sales' => (float) v($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=?", [$d]),
        'paid' => (float) v($pdo, "SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE sale_date=?", [$d]),
        'expenses' => (float) v($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=? AND approval_status = 'approved'", [$d]),
    ],
    'month' => [
        'sales' => $monthSales,
        'cogs' => $monthCogs,
        'expenses' => $monthExpenses,
        'prod_cost' => (float) v($pdo, "SELECT COALESCE(SUM(production_cost),0) FROM production WHERE production_date>=?", [$m]),
    ],
    'inventory' => [
        'finished_stock' => (int) v($pdo, "SELECT COALESCE(SUM(quantity),0) FROM finished_stock"),
        'low_stock_count' => (int) v($pdo, "SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id=rl.material_id WHERE rm.current_stock<=rl.reorder_point"),
    ],
    'accounts' => [
        'receivables' => (float) v($pdo, "SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0"),
        'payables' => (float) v($pdo, "SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance>0 AND (approval_status IS NULL OR approval_status <> 'rejected')"),
    ],
    'counts' => [
        'customers' => (int) v($pdo, "SELECT COUNT(*) FROM customers WHERE status=1"),
        'employees' => (int) v($pdo, "SELECT COUNT(*) FROM employees WHERE status=1"),
        'suppliers' => (int) v($pdo, "SELECT COUNT(*) FROM suppliers WHERE status=1"),
    ],
];

// Profit uses the same COGS basis as reports/profit_loss.php (not production spend).
$data['month']['profit'] = round($monthSales - $monthCogs - $monthExpenses, 2);
$data['accounts']['net_position'] = $data['accounts']['receivables'] - $data['accounts']['payables'];

echo json_encode($data, JSON_PRETTY_PRINT);
