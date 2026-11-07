<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('purchases', 'delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id, purchase_no, approval_status FROM purchases WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        throw new RuntimeException('Purchase not found.');
    }
    // Only rejected orders may be deleted: they never touched stock or the books.
    if (($purchase['approval_status'] ?? '') !== 'rejected') {
        throw new RuntimeException('Only rejected purchases can be deleted.');
    }
    $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM purchase_approvals WHERE purchase_id = ?")->execute([$id]);
    foreach ([
        "UPDATE purchase_schedules SET purchase_id = NULL, status = 'planned' WHERE purchase_id = ?",
        "UPDATE supplier_deliveries SET purchase_id = NULL WHERE purchase_id = ?",
        "DELETE FROM supplier_payments WHERE purchase_id = ?",
    ] as $sql) {
        try { $pdo->prepare($sql)->execute([$id]); } catch (PDOException $e) { /* table may not exist */ }
    }
    $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$id]);
    $pdo->commit();
    log_activity('delete', 'purchases', $id, 'Deleted rejected purchase ' . $purchase['purchase_no']);
    set_flash('success', 'Rejected purchase ' . $purchase['purchase_no'] . ' deleted.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $e->getMessage());
}
header('Location: index.php');
exit;
