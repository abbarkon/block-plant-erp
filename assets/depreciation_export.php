<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
if (!is_valid_date_string($asOf)) {
    $asOf = date('Y-m-d');
}
$includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
$categoryId = (int) ($_GET['category'] ?? 0);

$sql = "SELECT a.*, ac.name AS category_name, ac.depreciation_rate
    FROM assets a
    JOIN asset_categories ac ON ac.id = a.category_id
    WHERE 1=1";
$params = [];
if (!$includeInactive) {
    $sql .= ' AND a.status = 1';
}
if ($categoryId > 0) {
    $sql .= ' AND a.category_id = ?';
    $params[] = $categoryId;
}
$sql .= ' ORDER BY a.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

$filename = 'asset_depreciation_' . $asOf . '_' . date('His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Asset code', 'Asset', 'Category', 'Purchase date', 'Cost', 'Salvage', 'Useful life', 'Years held', 'Method', 'Annual depreciation', 'Accumulated depreciation', 'Book value', 'Recorded value', 'Variance', 'Status']);

$t = ['cost' => 0.0, 'annual' => 0.0, 'accumulated' => 0.0, 'book' => 0.0, 'stored' => 0.0, 'variance' => 0.0];
foreach ($assets as $asset) {
    $calc = calculate_asset_depreciation($asset, $asOf);
    $stored = round((float) $asset['current_value'], 2);
    $variance = round($stored - $calc['book'], 2);
    foreach (['cost', 'annual', 'accumulated', 'book'] as $key) {
        $t[$key] += $calc[$key];
    }
    $t['stored'] += $stored;
    $t['variance'] += $variance;

    fputcsv($fp, [
        $asset['asset_code'],
        $asset['name'],
        $asset['category_name'],
        $asset['purchase_date'],
        number_format($calc['cost'], 2, '.', ''),
        number_format($calc['salvage'], 2, '.', ''),
        (int) $asset['useful_life'],
        number_format($calc['years'], 2, '.', ''),
        $calc['method'],
        number_format($calc['annual'], 2, '.', ''),
        number_format($calc['accumulated'], 2, '.', ''),
        number_format($calc['book'], 2, '.', ''),
        number_format($stored, 2, '.', ''),
        number_format($variance, 2, '.', ''),
        (int) $asset['status'] === 1 ? 'Active' : 'Inactive',
    ]);
}
fputcsv($fp, []);
fputcsv($fp, ['TOTAL', '', '', '', number_format($t['cost'], 2, '.', ''), '', '', '', '', number_format($t['annual'], 2, '.', ''), number_format($t['accumulated'], 2, '.', ''), number_format($t['book'], 2, '.', ''), number_format($t['stored'], 2, '.', ''), number_format($t['variance'], 2, '.', ''), '']);
fclose($fp);

log_activity('export', 'assets', null, 'Asset depreciation exported as of ' . $asOf . ' (' . count($assets) . ' assets)');
exit;
