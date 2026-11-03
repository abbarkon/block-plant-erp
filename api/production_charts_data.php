<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}
require_permission('production', 'view');

header('Content-Type: application/json');

$daily = $pdo->query("
    SELECT production_date AS label,
           SUM(good_quantity) AS good,
           SUM(rejected_quantity) AS rejected,
           SUM(production_cost) AS cost
    FROM production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY production_date
    ORDER BY production_date
")->fetchAll(PDO::FETCH_ASSOC);

$byBlock = $pdo->query("
    SELECT CONCAT(bt.name, ' ', bt.size) AS label, SUM(p.good_quantity) AS value
    FROM production p
    JOIN block_types bt ON p.block_type_id = bt.id
    WHERE p.production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY bt.id
    ORDER BY value DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$efficiency = $pdo->query("
    SELECT
        COALESCE(SUM(planned_quantity),0) AS planned,
        COALESCE(SUM(produced_quantity),0) AS produced,
        COALESCE(SUM(good_quantity),0) AS good,
        COALESCE(SUM(rejected_quantity),0) AS rejected
    FROM production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'daily' => $daily,
    'by_block' => $byBlock,
    'efficiency_30d' => $efficiency,
], JSON_PRETTY_PRINT);
