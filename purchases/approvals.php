<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('purchases', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    requested_by INT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    approval_level VARCHAR(50) DEFAULT 'manager',
    notes TEXT,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
)");

ensure_notifications_schema();
ensure_purchase_payment_schema();

$pageTitle = t('purchase_approvals');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_approval' && has_permission('purchases', 'create')) {
        $purchaseId = (int) ($_POST['purchase_id'] ?? 0);
        if ($purchaseId > 0) {
            $purchaseStmt = $pdo->prepare("SELECT approval_status FROM purchases WHERE id = ?");
            $purchaseStmt->execute([$purchaseId]);
            $purchaseStatus = $purchaseStmt->fetchColumn();

            if ($purchaseStatus === false) {
                set_flash('error', t('purchase_not_found'));
            } elseif ($purchaseStatus === 'approved') {
                set_flash('error', t('already_approved_cannot_request'));
            } else {
            $check = $pdo->prepare("SELECT id FROM purchase_approvals WHERE purchase_id = ? AND status = 'pending'");
            $check->execute([$purchaseId]);
            if (!$check->fetch()) {
                $ins = $pdo->prepare("INSERT INTO purchase_approvals(purchase_id, requested_by, status, approval_level, notes) VALUES(?,?,?,?,?)");
                $ins->execute([$purchaseId, $_SESSION['user_id'], 'pending', 'manager', trim($_POST['notes'] ?? '')]);
                    set_flash('success', t('approval_request_submitted'));
                } else {
                    set_flash('error', t('already_pending_request'));
                }
            }
        }
        header('Location: approvals.php');
        exit;
    }

    if ($_POST['action'] === 'approve' && has_permission('purchases', 'approve')) {
        $id = (int) ($_POST['approval_id'] ?? 0);
        $status = in_array($_POST['status'] ?? 'approved', ['approved', 'rejected'], true) ? $_POST['status'] : 'approved';
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("SELECT pa.purchase_id, pa.status, p.approval_status FROM purchase_approvals pa JOIN purchases p ON p.id = pa.purchase_id WHERE pa.id = ? FOR UPDATE");
            $lockStmt->execute([$id]);
            $lockedApproval = $lockStmt->fetch();
            if (!$lockedApproval || $lockedApproval['status'] !== 'pending' || $lockedApproval['approval_status'] === 'approved') {
                $pdo->rollBack();
                set_flash('error', t('approval_already_processed'));
                header('Location: approvals.php');
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE purchase_approvals SET status = ?, notes = COALESCE(NULLIF(?, ''), notes), approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([$status, $notes, $_SESSION['user_id'], $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(t('approval_already_processed'));
            }
            
            if ($status === 'approved') {
                // Get purchase details
                $approvalStmt = $pdo->prepare("SELECT purchase_id FROM purchase_approvals WHERE id = ?");
                $approvalStmt->execute([$id]);
                $purchaseId = (int) $approvalStmt->fetchColumn();
                
                $purchaseStmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
                $purchaseStmt->execute([$purchaseId]);
                $purchase = $purchaseStmt->fetch();
                
                if ($purchase) {
                    // Update purchase approval_status
                    $pdo->prepare("UPDATE purchases SET approval_status = 'approved' WHERE id = ?")
                        ->execute([$purchaseId]);
                    
                    // Process inventory update
                    $itemsStmt = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
                    $itemsStmt->execute([$purchaseId]);
                    $items = $itemsStmt->fetchAll();
                    
                    $inventoryDebit = 0.0;
                    foreach ($items as $item) {
                        $materialStmt = $pdo->prepare("SELECT current_stock, unit_cost FROM raw_materials WHERE id = ? AND status = 1 FOR UPDATE");
                        $materialStmt->execute([$item['material_id']]);
                        $materialStock = $materialStmt->fetch();
                        
                        if ($materialStock) {
                            $oldQuantity = (float) $materialStock['current_stock'];
                            $oldValue = $oldQuantity * (float) $materialStock['unit_cost'];
                            $newQuantity = $oldQuantity + (float) $item['quantity'];
                            $weightedUnitCost = $newQuantity > 0 ? round(($oldValue + (float) $item['total_cost']) / $newQuantity, 4) : 0;
                            
                            $pdo->prepare("UPDATE raw_materials SET current_stock = ?, unit_cost = ? WHERE id = ?")
                                ->execute([$newQuantity, $weightedUnitCost, $item['material_id']]);
                            
                            $inventoryDebit += (float) $item['total_cost'];
                            
                            $pdo->prepare("INSERT INTO stock_transactions(material_id, transaction_type, quantity, reference_no, notes, transaction_date, created_by) VALUES (?, 'purchase_receipt', ?, ?, 'Purchase receipt (approved)', ?, ?)")
                                ->execute([$item['material_id'], $item['quantity'], $purchase['purchase_no'], $purchase['purchase_date'], $_SESSION['user_id']]);
                        }
                    }
                    
                    // Post accounting journal
                    $total = (float) $purchase['total_amount'];
                    $logisticsCost = (float) $purchase['loading_cost'] + (float) $purchase['unloading_cost'];
                    $logisticsIncludedInTotal = abs($total - ((float) $purchase['subtotal'] + $logisticsCost)) < 0.01;
                    $requestedSupplierPaid = (float) ($purchase['requested_paid_amount'] ?? $purchase['paid_amount']);
                    $supplierPaid = min(max(0, $requestedSupplierPaid), max(0, (float) $purchase['subtotal']));
                    $paid = $supplierPaid + ($logisticsIncludedInTotal ? $logisticsCost : 0);
                    $bal = max(0, $total - $paid);
                    $paymentStatus = $bal < 0.01 ? 'paid' : ($paid > 0 ? 'partial' : 'credit');
                    $pdo->prepare("UPDATE purchases SET paid_amount = ?, balance = ?, payment_status = ? WHERE id = ?")
                        ->execute([$paid, $bal, $paymentStatus, $purchaseId]);
                    
                    if ($total > 0) {
                        $journalLines = [
                            ['1130', $inventoryDebit, 0, 'Raw materials received at weighted-average inventory cost'],
                            ['1110', 0, $paid, 'Cash paid to supplier'],
                            ['2110', 0, $bal, 'Supplier payable for purchase'],
                        ];
                        if ($logisticsIncludedInTotal && $logisticsCost > 0) {
                            $journalLines[] = ['6000', $logisticsCost, 0, 'Loading and unloading costs'];
                        }
                        $journalLines[1][3] = 'Cash paid for supplier and logistics costs';
                        post_accounting_journal('PUR-' . $purchase['purchase_no'], $purchase['purchase_date'], 'Raw material purchase received', $purchase['purchase_no'], $journalLines, $_SESSION['user_id']);
                    }
                    
                    // Process payment if any
                    if ($supplierPaid > 0) {
                        $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES (?, 'expense', ?, 'Purchase payment (approved)', ?, 'cash', ?)")
                            ->execute([$purchase['purchase_date'], $purchase['purchase_no'], $supplierPaid, $_SESSION['user_id']]);
                        
                        try {
                            $pdo->prepare("INSERT INTO supplier_payments(purchase_id, supplier_id, payment_date, amount, payment_method, reference_no, created_by) VALUES (?, ?, ?, ?, 'cash', ?, ?)")
                                ->execute([$purchaseId, $purchase['supplier_id'], $purchase['purchase_date'], $supplierPaid, $purchase['purchase_no'], $_SESSION['user_id']]);
                        } catch (PDOException $e) {}
                    }
                    
                    // Process logistics costs
                    if ($logisticsCost > 0) {
                        $expenseNo = 'EXP-' . date('YmdHis') . '-' . random_int(100, 999);
                        $pdo->prepare("INSERT INTO expenses(voucher_no, expense_date, category, description, amount, payment_method, reference_no, created_by) VALUES (?, ?, 'Loading and Unloading', ?, ?, 'cash', ?, ?)")
                            ->execute([$expenseNo, $purchase['purchase_date'], "Logistics cost for purchase {$purchase['purchase_no']}", $logisticsCost, $purchase['purchase_no'], $_SESSION['user_id']]);
                        $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES (?, 'expense', ?, ?, ?, 'cash', ?)")
                            ->execute([$purchase['purchase_date'], $expenseNo, "Logistics cost for purchase {$purchase['purchase_no']}", $logisticsCost, $_SESSION['user_id']]);
                    }
                    
                    // Mark purchase schedule as completed
                    try {
                        $pdo->prepare("UPDATE purchase_schedules SET status = 'completed' WHERE purchase_id = ? AND status = 'ordered'")
                            ->execute([$purchaseId]);
                    } catch (PDOException $e) {}
                }
            } else {
                // Rejected: a rejected PO is not a liability — clear the balance and
                // flag payment as cancelled so it stops inflating payables/aging.
                $pdo->prepare("UPDATE purchases SET approval_status = 'rejected', paid_amount = 0, balance = 0, payment_status = 'cancelled' WHERE id = (SELECT purchase_id FROM purchase_approvals WHERE id = ?)")
                    ->execute([$id]);
            }
            
            // Notify the user who created the order about the decision
            $decStmt = $pdo->prepare("SELECT pa.purchase_id, pa.requested_by, p.purchase_no, p.created_by, s.name AS supplier_name
                                      FROM purchase_approvals pa
                                      JOIN purchases p ON p.id = pa.purchase_id
                                      LEFT JOIN suppliers s ON s.id = p.supplier_id
                                      WHERE pa.id = ?");
            $decStmt->execute([$id]);
            $dec = $decStmt->fetch();
            if ($dec) {
                $creatorId = (int) ($dec['created_by'] ?: $dec['requested_by']);
                if ($creatorId && $creatorId != $_SESSION['user_id']) {
                    $verb = $status === 'approved' ? 'approved' : 'rejected';
                    $decMsg = "Your purchase order {$dec['purchase_no']}"
                        . ($dec['supplier_name'] ? " from {$dec['supplier_name']}" : "")
                        . " was {$verb} by " . ($_SESSION['user_name'] ?? 'a manager')
                        . ($notes ? ". Comment: {$notes}" : ".");
                    notify_user($creatorId, $decMsg, '/block_plant/purchases/view.php?id=' . (int) $dec['purchase_id']);
                }
            }
            
            $pdo->commit();
            set_flash('success', $status === 'approved' ? 'Purchase approved successfully.' : 'Purchase rejected.');
            header('Location: approvals.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            die('Approval failed: ' . $e->getMessage());
        }
    }
}

$approvals = $pdo->query("SELECT pa.*, p.purchase_no, p.total_amount, p.requested_paid_amount, p.loading_cost, p.unloading_cost, s.name AS supplier_name, req.name AS requested_by_name, app.name AS approved_by_name FROM purchase_approvals pa JOIN purchases p ON p.id = pa.purchase_id LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN users req ON req.id = pa.requested_by LEFT JOIN users app ON app.id = pa.approved_by WHERE pa.status = 'pending' AND COALESCE(p.approval_status, 'pending') NOT IN ('approved','rejected') ORDER BY pa.created_at DESC")->fetchAll();

$purchaseRequests = $pdo->query("SELECT p.id, p.purchase_no, p.purchase_date, p.total_amount, p.requested_paid_amount, p.loading_cost, p.unloading_cost, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN purchase_approvals pa ON pa.purchase_id = p.id AND pa.status = 'pending' WHERE pa.id IS NULL AND COALESCE(p.approval_status, 'pending') NOT IN ('approved','rejected') ORDER BY p.purchase_date DESC LIMIT 50")->fetchAll();
$flash = get_flash();

require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1><?= t('purchase_approvals') ?></h1>
        <p style="color:#64748b; margin:6px 0 0;"><?= t('manager_purchase_approval_help') ?></p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary"><?= t('back_to_purchases') ?></a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <h3><?= t('request_purchase_approval') ?></h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="action" value="request_approval">
        <select name="purchase_id" required>
            <option value="">Select purchase order</option>
            <?php foreach ($purchaseRequests as $purchase): ?>
                <?php $requestLogistics = (float) $purchase['loading_cost'] + (float) $purchase['unloading_cost']; ?>
                <option value="<?= (int) $purchase['id'] ?>"><?= e($purchase['purchase_no']) ?> - <?= e($purchase['supplier_name'] ?? 'N/A') ?> (<?= currency_symbol() . money($purchase['total_amount']) ?><?= $requestLogistics > 0 ? ' ' . e(t('includes_logistics')) . ' ' . currency_symbol() . money($requestLogistics) : '' ?>; <?= e(t('supplier_payment_requested')) ?> <?= currency_symbol() . money($purchase['requested_paid_amount']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="notes" placeholder="<?= e(t('approval_notes')) ?>">
        <button type="submit" class="btn btn-accent"><?= t('submit') ?></button>
    </form>
</div>

<div class="panel">
    <h3><?= t('approval_queue') ?></h3>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('purchase_number') ?></th>
                <th><?= t('suppliers') ?></th>
                <th><?= t('amount') ?></th>
                <th><?= t('requested_by') ?></th>
                <th><?= t('status') ?></th>
                <th><?= t('decision') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$approvals): ?>
                <tr><td colspan="7"><?= t('no_purchases_awaiting') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($approvals as $approval): ?>
            <tr>
                <td><?= e($approval['purchase_no']) ?></td>
                <td><?= e($approval['supplier_name'] ?? t('not_available')) ?></td>
                <td><?= currency_symbol() . money($approval['total_amount']) ?><?php $approvalLogistics = (float) $approval['loading_cost'] + (float) $approval['unloading_cost']; ?><?php if ($approvalLogistics > 0): ?><small style="display:block;color:#64748b;">Includes <?= currency_symbol() . money($approvalLogistics) ?> logistics</small><?php endif; ?><small style="display:block;color:#64748b;">Supplier payment requested: <?= currency_symbol() . money($approval['requested_paid_amount']) ?></small></td>
                <td><?= e($approval['requested_by_name'] ?? t('unknown_status')) ?></td>
                <td>
                    <span class="badge badge-<?= $approval['status'] === 'approved' ? 'success' : ($approval['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(t('status_' . $approval['status'])) ?></span>
                </td>
                <td>
                    <?php if (has_permission('purchases', 'approve') && $approval['status'] === 'pending'): ?>
                        <form method="post" style="display:flex; gap:8px; align-items:center; margin:0;">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="approval_id" value="<?= (int) $approval['id'] ?>">
                            <select name="status">
                                <option value="approved">Approve</option>
                                <option value="rejected">Reject</option>
                            </select>
                            <input type="text" name="notes" placeholder="Comment" style="max-width:140px;">
                            <button type="submit" class="btn-sm">Save</button>
                        </form>
                    <?php else: ?>
                        <?= e($approval['approved_by_name'] ?? '—') ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
