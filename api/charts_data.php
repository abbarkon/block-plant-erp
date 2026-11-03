<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}
require_permission('dashboard', 'view');
// The chart payload contains company-wide sales, costs and product mix.
require_permission('reports', 'view');
ensure_expense_approval_schema();

header('Content-Type: application/json');

$salesMonthly = $pdo->query("
    SELECT DATE_FORMAT(sale_date, '%Y-%m') AS label, SUM(total_amount) AS value
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY label ORDER BY label
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($salesMonthly as &$monthRow) {
    [$labelYear, $labelMonth] = explode('-', $monthRow['label']);
    $monthRow['label'] = format_app_month_year($labelMonth, $labelYear);
}
unset($monthRow);

$productionDaily = $pdo->query("
    SELECT production_date AS label, SUM(good_quantity) AS value
    FROM production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY production_date ORDER BY production_date
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($productionDaily as &$productionRow) {
    $productionRow['label'] = format_app_date($productionRow['label'], true);
}
unset($productionRow);

$topProducts = $pdo->query("
    SELECT CONCAT(bt.name, ' ', bt.size) AS label, SUM(si.quantity) AS value
    FROM sale_items si
    JOIN block_types bt ON si.block_type_id = bt.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY bt.id ORDER BY value DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$expenseVsSales = $pdo->query("
    SELECT
        (SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS sales_mtd,
        (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND (approval_status = 'approved' OR approval_status IS NULL)) AS expenses_mtd
")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'sales_monthly' => $salesMonthly,
    'production_daily' => $productionDaily,
    'top_products' => $topProducts,
    'expense_vs_sales' => $expenseVsSales,
    'currency' => currency_symbol(),
], JSON_PRETTY_PRINT);
