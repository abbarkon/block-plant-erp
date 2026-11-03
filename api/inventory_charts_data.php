<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}
require_permission('inventory', 'view');

header('Content-Type: application/json');

$rawValue = (float) $pdo->query("SELECT COALESCE(SUM(current_stock * unit_cost),0) FROM raw_materials WHERE status=1")->fetchColumn();

// Finished goods exist at two very different numbers: what they cost us to make
// (weighted-average unit cost) and what they will sell for. Inventory valuation
// must use cost; retail is only potential revenue.
$finishedCost = (float) $pdo->query("
    SELECT COALESCE(SUM(fs.quantity * fs.average_unit_cost),0)
    FROM finished_stock fs JOIN block_types bt ON fs.block_type_id = bt.id WHERE bt.status=1
")->fetchColumn();
$finishedRetail = (float) $pdo->query("
    SELECT COALESCE(SUM(fs.quantity * bt.selling_price),0)
    FROM finished_stock fs JOIN block_types bt ON fs.block_type_id = bt.id WHERE bt.status=1
")->fetchColumn();
$finishedValue = $finishedRetail;

$lowStock = (int) $pdo->query("
    SELECT COUNT(*) FROM raw_materials rm
    JOIN reorder_levels rl ON rm.id = rl.material_id
    WHERE rm.status=1 AND rm.current_stock <= rl.reorder_point
")->fetchColumn();

$movementByType = $pdo->query("
    SELECT transaction_type AS label, COUNT(*) AS cnt, SUM(ABS(quantity)) AS qty
    FROM stock_transactions
    WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY transaction_type
")->fetchAll(PDO::FETCH_ASSOC);

$movementDaily = $pdo->query("
    SELECT transaction_date AS label, SUM(ABS(quantity)) AS value
    FROM stock_transactions
    WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY transaction_date ORDER BY transaction_date
")->fetchAll(PDO::FETCH_ASSOC);

$rawByMaterial = $pdo->query("
    SELECT name AS label, (current_stock * unit_cost) AS value
    FROM raw_materials WHERE status=1 AND current_stock > 0
    ORDER BY value DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$finishedByBlock = $pdo->query("
    SELECT CONCAT(bt.name,' ',bt.size) AS label, (fs.quantity * bt.selling_price) AS value
    FROM finished_stock fs JOIN block_types bt ON fs.block_type_id = bt.id
    WHERE fs.quantity > 0 ORDER BY value DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'valuation' => [
        'raw' => $rawValue,
        'finished' => $finishedValue,
        'finished_cost' => $finishedCost,
        'finished_retail' => $finishedRetail,
        'total' => $rawValue + $finishedValue,
        'total_cost' => $rawValue + $finishedCost,
        'total_retail' => $rawValue + $finishedRetail,
    ],
    'low_stock_count' => $lowStock,
    'movement_by_type' => $movementByType,
    'movement_daily' => $movementDaily,
    'raw_by_material' => $rawByMaterial,
    'finished_by_block' => $finishedByBlock,
    'currency' => currency_symbol(),
], JSON_PRETTY_PRINT);
