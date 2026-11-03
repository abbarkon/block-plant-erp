<?php
// Lightweight JSON endpoint backing the Ctrl+K command palette.
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$term = '%' . $q . '%';
$results = [];

if (has_permission('customers', 'view')) {
    $s = $pdo->prepare("SELECT id, name, customer_code FROM customers WHERE status = 1 AND (name LIKE ? OR customer_code LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 5");
    $s->execute([$term, $term, $term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Customers', 'icon' => '👤', 'title' => $r['name'], 'sub' => $r['customer_code'], 'url' => '/block_plant/customers/view.php?id=' . (int) $r['id']];
    }
}

if (has_permission('suppliers', 'view')) {
    $s = $pdo->prepare("SELECT id, name, supplier_code FROM suppliers WHERE status = 1 AND (name LIKE ? OR supplier_code LIKE ?) ORDER BY name LIMIT 5");
    $s->execute([$term, $term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Suppliers', 'icon' => '🏭', 'title' => $r['name'], 'sub' => $r['supplier_code'], 'url' => '/block_plant/suppliers/view.php?id=' . (int) $r['id']];
    }
}

if (has_permission('sales', 'view')) {
    $s = $pdo->prepare("SELECT s.id, s.invoice_no, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.invoice_no LIKE ? OR c.name LIKE ? ORDER BY s.sale_date DESC LIMIT 5");
    $s->execute([$term, $term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Sales', 'icon' => '💰', 'title' => $r['invoice_no'], 'sub' => $r['customer_name'] ?? 'Walk-in', 'url' => '/block_plant/sales/view.php?id=' . (int) $r['id']];
    }
}

if (has_permission('purchases', 'view')) {
    $s = $pdo->prepare("SELECT p.id, p.purchase_no, sp.name AS supplier_name FROM purchases p LEFT JOIN suppliers sp ON sp.id = p.supplier_id WHERE p.purchase_no LIKE ? OR sp.name LIKE ? ORDER BY p.purchase_date DESC LIMIT 5");
    $s->execute([$term, $term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Purchases', 'icon' => '🛒', 'title' => $r['purchase_no'], 'sub' => $r['supplier_name'] ?? '', 'url' => '/block_plant/purchases/view.php?id=' . (int) $r['id']];
    }
}

if (has_permission('production', 'view')) {
    $s = $pdo->prepare("SELECT p.id, p.production_no, bt.name AS block_name FROM production p LEFT JOIN block_types bt ON bt.id = p.block_type_id WHERE p.production_no LIKE ? OR bt.name LIKE ? ORDER BY p.production_date DESC LIMIT 5");
    $s->execute([$term, $term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Production', 'icon' => '📦', 'title' => $r['production_no'], 'sub' => $r['block_name'] ?? '', 'url' => '/block_plant/production/view.php?id=' . (int) $r['id']];
    }
}

if (has_permission('inventory', 'view')) {
    $s = $pdo->prepare("SELECT id, name, unit FROM raw_materials WHERE status = 1 AND name LIKE ? ORDER BY name LIMIT 5");
    $s->execute([$term]);
    foreach ($s->fetchAll() as $r) {
        $results[] = ['group' => 'Materials', 'icon' => '🧱', 'title' => $r['name'], 'sub' => $r['unit'], 'url' => '/block_plant/inventory/materials.php'];
    }
}

echo json_encode(['results' => array_slice($results, 0, 20)]);
