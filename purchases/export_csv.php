<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_login();
require_permission('purchases', 'view');

if (($_GET['export'] ?? '') === 'csv') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $approval = trim((string) ($_GET['approval'] ?? ''));
    $supplierId = (int) ($_GET['supplier_id'] ?? 0);
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));

    $conditions = [];
    $params = [];
    if ($search !== '') {
        $conditions[] = "(p.purchase_no LIKE ? OR s.name LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($status !== '') {
        $conditions[] = "p.payment_status = ?";
        $params[] = $status;
    }
    if ($approval !== '') {
        $conditions[] = "COALESCE(p.approval_status, 'pending') = ?";
        $params[] = $approval;
    }
    if ($supplierId > 0) {
        $conditions[] = "p.supplier_id = ?";
        $params[] = $supplierId;
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $conditions[] = "p.purchase_date >= ?";
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $conditions[] = "p.purchase_date <= ?";
        $params[] = $to;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("SELECT p.purchase_no, p.purchase_date, s.name AS supplier_name, p.subtotal, p.total_amount, p.paid_amount, p.balance, p.payment_status, p.approval_status FROM purchases p LEFT JOIN suppliers s ON p.supplier_id = s.id $where ORDER BY p.purchase_date DESC, p.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="purchases_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Purchase No', 'Date', 'Supplier', 'Subtotal', 'Total', 'Paid', 'Balance', 'Payment Status', 'Approval Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['purchase_no'],
            $r['purchase_date'],
            $r['supplier_name'],
            $r['subtotal'],
            $r['total_amount'],
            $r['paid_amount'],
            $r['balance'],
            $r['payment_status'],
            $r['approval_status'] ?? 'pending',
        ]);
    }
    fclose($out);
    exit;
}
header('Location: index.php');
exit;
