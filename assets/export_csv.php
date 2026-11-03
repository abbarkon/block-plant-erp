<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$q = trim($_GET['q'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$condition = trim($_GET['condition'] ?? '');
$state = trim($_GET['state'] ?? '');
$conditions = ['good', 'fair', 'poor', 'damaged', 'critical'];
if (!in_array($condition, $conditions, true)) {
    $condition = '';
}
if (!in_array($state, ['active', 'inactive'], true)) {
    $state = '';
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.asset_code LIKE ? OR a.name LIKE ? OR a.location LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($category > 0) {
    $where[] = 'a.category_id = ?';
    $params[] = $category;
}
if ($condition !== '') {
    $where[] = 'a.condition_status = ?';
    $params[] = $condition;
}
if ($state === 'active') {
    $where[] = 'a.status = 1';
} elseif ($state === 'inactive') {
    $where[] = 'a.status = 0';
}

$stmt = $pdo->prepare("SELECT a.*, ac.name AS category_name, s.name AS supplier_name,
        am.next_maintenance_date
    FROM assets a
    JOIN asset_categories ac ON ac.id = a.category_id
    LEFT JOIN suppliers s ON s.id = a.supplier_id
    LEFT JOIN asset_maintenance am ON am.id = (
        SELECT am2.id FROM asset_maintenance am2
        WHERE am2.asset_id = a.id
        ORDER BY am2.maintenance_date DESC, am2.id DESC LIMIT 1
    )
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.name");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'assets_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Asset code', 'Name', 'Category', 'Purchase date', 'Purchase cost', 'Current value', 'Accumulated depreciation', 'Supplier', 'Location', 'Condition', 'Useful life (years)', 'Salvage value', 'Next maintenance', 'Status', 'Notes']);
$totalCost = 0.0;
$totalValue = 0.0;
foreach ($rows as $row) {
    $totalCost += (float) $row['purchase_cost'];
    $totalValue += (float) $row['current_value'];
    fputcsv($fp, [
        $row['asset_code'],
        $row['name'],
        $row['category_name'],
        $row['purchase_date'],
        number_format((float) $row['purchase_cost'], 2, '.', ''),
        number_format((float) $row['current_value'], 2, '.', ''),
        number_format(max(0, (float) $row['purchase_cost'] - (float) $row['current_value']), 2, '.', ''),
        $row['supplier_name'] ?? '',
        $row['location'] ?? '',
        $row['condition_status'] ?? '',
        (int) $row['useful_life'],
        number_format((float) $row['salvage_value'], 2, '.', ''),
        $row['next_maintenance_date'] ?? '',
        (int) $row['status'] === 1 ? 'Active' : 'Inactive',
        str_replace(["\r\n", "\n", "\r"], ' ', (string) ($row['notes'] ?? '')),
    ]);
}
fputcsv($fp, []);
fputcsv($fp, ['TOTAL', '', '', '', number_format($totalCost, 2, '.', ''), number_format($totalValue, 2, '.', ''), '', '', '', '', '', '', '', '', '']);
fclose($fp);

log_activity('export', 'assets', null, 'Asset register exported (' . count($rows) . ' rows)');
exit;
