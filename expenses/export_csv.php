<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'view');
ensure_expense_approval_schema();

$category = trim($_GET['category'] ?? '');
$method = trim($_GET['payment_method'] ?? '');
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if (!isset(expense_payment_methods()[$method])) {
    $method = '';
}
if (!isset(expense_statuses()[$status])) {
    $status = '';
}

$where = ['e.expense_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($category !== '') {
    $where[] = 'e.category = ?';
    $params[] = $category;
}
if ($method !== '') {
    $where[] = 'e.payment_method = ?';
    $params[] = $method;
}
if ($status !== '') {
    $where[] = 'e.approval_status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(e.voucher_no LIKE ? OR e.description LIKE ? OR e.reference_no LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT e.*, u.name AS created_by_name, a.name AS approved_by_name
    FROM expenses e
    LEFT JOIN users u ON u.id = e.created_by
    LEFT JOIN users a ON a.id = e.approved_by
    {$whereSql}
    ORDER BY e.expense_date DESC, e.id DESC LIMIT 10000");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'expenses_' . $from . '_to_' . $to . '_' . date('His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Voucher', 'Expense date', 'Category', 'Description', 'Amount', 'Payment method', 'Status', 'Reference', 'Submitted by', 'Submitted at', 'Approved by', 'Approved at', 'Approval notes']);

$total = 0.0;
$approved = 0.0;
$pending = 0.0;
foreach ($rows as $row) {
    $total += (float) $row['amount'];
    if ($row['approval_status'] === 'approved') {
        $approved += (float) $row['amount'];
    } elseif ($row['approval_status'] === 'pending') {
        $pending += (float) $row['amount'];
    }
    fputcsv($fp, [
        $row['voucher_no'],
        $row['expense_date'],
        $row['category'],
        str_replace(["\r\n", "\n", "\r"], ' ', (string) $row['description']),
        number_format((float) $row['amount'], 2, '.', ''),
        expense_payment_methods()[$row['payment_method']] ?? $row['payment_method'],
        $row['approval_status'],
        $row['reference_no'] ?? '',
        $row['created_by_name'] ?? 'System',
        $row['created_at'] ?? '',
        $row['approved_by_name'] ?? '',
        $row['approved_at'] ?? '',
        str_replace(["\r\n", "\n", "\r"], ' ', (string) ($row['approval_notes'] ?? '')),
    ]);
}
fputcsv($fp, []);
fputcsv($fp, ['TOTAL', '', '', '', number_format($total, 2, '.', ''), '', '', '', '', '', '', '', '']);
fputcsv($fp, ['APPROVED', '', '', '', number_format($approved, 2, '.', ''), '', '', '', '', '', '', '', '']);
fputcsv($fp, ['PENDING', '', '', '', number_format($pending, 2, '.', ''), '', '', '', '', '', '', '', '']);
fclose($fp);

log_activity('export', 'expenses', null, 'Expense register exported (' . count($rows) . ' rows, ' . $from . ' to ' . $to . ')');
exit;
