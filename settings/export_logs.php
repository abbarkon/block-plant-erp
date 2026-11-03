<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$module = trim($_GET['module'] ?? '');
$action = trim($_GET['action'] ?? '');
$q = trim($_GET['q'] ?? '');
$userId = (int) ($_GET['user'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$limit = min(5000, max(1, (int) ($_GET['limit'] ?? 500)));

$validDate = static function ($value) {
    if ($value === '') {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
};
if (!$validDate($from)) {
    $from = '';
}
if (!$validDate($to)) {
    $to = '';
}

$sql = "SELECT al.created_at, u.name AS user_name, al.action, al.module, al.record_id, al.description, al.ip_address
    FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1";
$params = [];
if ($module !== '') {
    $sql .= " AND al.module = ?";
    $params[] = $module;
}
if ($action !== '') {
    $sql .= " AND al.action = ?";
    $params[] = $action;
}
if ($userId > 0) {
    $sql .= " AND al.user_id = ?";
    $params[] = $userId;
}
if ($from !== '') {
    $sql .= " AND al.created_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= " AND al.created_at <= ?";
    $params[] = $to . ' 23:59:59';
}
if ($q !== '') {
    $sql .= " AND (al.description LIKE ? OR u.name LIKE ? OR al.ip_address LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$sql .= ' ORDER BY al.created_at DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'activity_log_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Amharic names correctly.
fputcsv($fp, ['Date', 'User', 'Action', 'Module', 'Record ID', 'Description', 'IP Address']);
foreach ($rows as $row) {
    fputcsv($fp, [
        $row['created_at'],
        $row['user_name'] ?? 'System',
        $row['action'],
        $row['module'],
        $row['record_id'] ?? '',
        str_replace(["\r\n", "\n", "\r"], ' ', (string) ($row['description'] ?? '')),
        $row['ip_address'] ?? '',
    ]);
}
fclose($fp);
log_activity('export', 'settings', null, 'Activity log exported (' . count($rows) . ' rows)');
exit;
