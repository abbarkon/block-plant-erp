<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'create');
ensure_logistics_cost_columns();
ensure_manufacturing_costing_schema();

// Add approval_status column if missing
try {
    $pdo->exec("ALTER TABLE purchases ADD COLUMN approval_status VARCHAR(20) DEFAULT 'pending'");
} catch (PDOException $e) {}

// Ensure approvals table exists (DDL runs outside the transaction to avoid implicit commit)
$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    requested_by INT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    approval_level VARCHAR(50) DEFAULT 'manager',
    notes TEXT,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure notifications table exists (DDL runs outside the transaction to avoid implicit commit)
ensure_notifications_schema();
ensure_purchase_payment_schema();

$sid = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
$date = $_POST['purchase_date'];
$ids = $_POST['material_id'] ?? [];
$qs = $_POST['quantity'] ?? [];
$cs = $_POST['unit_cost'] ?? [];
$loadingCost = max(0, (float) ($_POST['loading_cost'] ?? 0));
$unloadingCost = max(0, (float) ($_POST['unloading_cost'] ?? 0));
$logisticsCost = $loadingCost + $unloadingCost;
$total = 0;
$lines = [];
for ($i = 0; $i < count($ids); $i++) {
    $q = (float) ($qs[$i] ?? 0);
    $c = (float) ($cs[$i] ?? 0);
    if ($q <= 0) {
        continue;
    }
    $lineTotal = $q * $c;
    $total += $lineTotal;
    $lines[] = ['material_id' => (int) $ids[$i], 'quantity' => $q, 'unit_cost' => $c, 'total_cost' => $lineTotal];
}
if (!$lines) {
    die('Add at least one line item.');
}

$subtotal = $total;
$total += $logisticsCost;
$supplierPaid = min(max(0, (float) ($_POST['paid_amount'] ?? 0)), $subtotal);
$paid = 0;
$bal = $total;
$status = 'credit';

try {
    $pdo->beginTransaction();
    $no = 'PUR-' . date('YmdHis');
    $pdo->prepare("INSERT INTO purchases(purchase_no,supplier_id,purchase_date,subtotal,total_amount,paid_amount,requested_paid_amount,balance,payment_status,notes,loading_cost,unloading_cost,approval_status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$no, $sid, $date, $subtotal, $total, $paid, $supplierPaid, $bal, $status, $_POST['notes'] ?? '', $loadingCost, $unloadingCost, 'pending', $_SESSION['user_id']]);
    $pid = (int) $pdo->lastInsertId();

    foreach ($lines as $line) {
        $pdo->prepare("INSERT INTO purchase_items(purchase_id,material_id,quantity,unit_cost,total_cost) VALUES(?,?,?,?,?)")
            ->execute([$pid, $line['material_id'], $line['quantity'], $line['unit_cost'], $line['total_cost']]);
    }

    // Auto-create approval request so the PO appears in the approval queue
    $pdo->prepare("INSERT INTO purchase_approvals(purchase_id,requested_by,status,approval_level,notes) VALUES(?,?,?,'manager','Auto-created on PO submission')")
        ->execute([$pid, $_SESSION['user_id'], 'pending']);

    // Notify all admins (except the requester) about the new purchase order
    $supplierName = '';
    if ($sid) {
        $supStmt = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
        $supStmt->execute([$sid]);
        $supplierName = (string) $supStmt->fetchColumn();
    }
    $notifMsg = "New purchase order {$no}" . ($supplierName ? " from {$supplierName}" : "") . " (" . currency_symbol() . money($total) . " total; supplier payment " . currency_symbol() . money($supplierPaid) . ") is awaiting approval.";
    notify_admins($notifMsg, '/block_plant/purchases/view.php?id=' . $pid, $_SESSION['user_id']);

    try {
        $pdo->prepare("UPDATE purchase_schedules SET status='ordered', purchase_id=? WHERE material_id=? AND status='planned' AND schedule_date<=? ORDER BY schedule_date DESC LIMIT 1")
            ->execute([$pid, $lines[0]['material_id'], $date]);
    } catch (PDOException $e) {}

    $pdo->commit();
    log_activity('create', 'purchases', $pid, "Purchase {$no}");
    header('Location: view.php?id=' . $pid);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die($e->getMessage());
}
