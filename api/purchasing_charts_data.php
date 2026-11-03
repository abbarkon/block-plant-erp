<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}
require_permission('purchases', 'view');

header('Content-Type: application/json');

$monthly = $pdo->query("
    SELECT DATE_FORMAT(purchase_date, '%Y-%m') AS label, SUM(total_amount) AS value
    FROM purchases
    WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY label ORDER BY label
")->fetchAll(PDO::FETCH_ASSOC);

$byMaterial = $pdo->query("
    SELECT rm.name AS label, SUM(pi.total_cost) AS value
    FROM purchase_items pi
    JOIN raw_materials rm ON pi.material_id = rm.id
    JOIN purchases p ON pi.purchase_id = p.id
    WHERE p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY rm.id ORDER BY value DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$bySupplier = $pdo->query("
    SELECT COALESCE(s.name, 'No supplier') AS label, SUM(p.total_amount) AS value
    FROM purchases p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY p.supplier_id ORDER BY value DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$payables = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance>0")->fetchColumn();

echo json_encode([
    'monthly' => $monthly,
    'by_material' => $byMaterial,
    'by_supplier' => $bySupplier,
    'total_payables' => $payables,
    'currency' => currency_symbol(),
], JSON_PRETTY_PRINT);
