<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');
ensure_purchase_payment_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name, s.phone FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) {
    require_once '../config/helpers.php';
    require_once '../partials/header.php';
    echo render_not_found('Purchase order not found', 'The purchase order you requested does not exist.', 'index.php', 'Return to purchase orders');
    require_once '../partials/footer.php';
    exit;
}

$items = $pdo->prepare("SELECT pi.*, rm.name, rm.unit FROM purchase_items pi JOIN raw_materials rm ON pi.material_id=rm.id WHERE pi.purchase_id=?");
$items->execute([$id]);
$items = $items->fetchAll();
$logisticsCost = (float) $p['loading_cost'] + (float) $p['unloading_cost'];
$logisticsIncludedInTotal = abs((float) $p['total_amount'] - ((float) $p['subtotal'] + $logisticsCost)) < 0.01;
$paymentApproved = ($p['approval_status'] ?? '') === 'approved';
$supplierPaid = $paymentApproved ? ($logisticsIncludedInTotal ? max(0, (float) $p['paid_amount'] - $logisticsCost) : (float) $p['paid_amount']) : 0;

$pageTitle = 'PO ' . $p['purchase_no'];
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= e($p['purchase_no']) ?></h1>
    <div class="header-actions">
        <?php if ($paymentApproved && (float) $p['balance'] > 0 && has_permission('purchases', 'edit')): ?>
        <a href="../payments/supplier.php?purchase_id=<?= (int) $p['id'] ?>" class="btn btn-accent">Pay Balance</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary">← All POs</a>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Order info</h3>
        <p><strong>Date:</strong> <?= format_date($p['purchase_date']) ?></p>
        <p><strong>Supplier:</strong> <?= e($p['supplier_name'] ?? '—') ?> <?= $p['phone'] ? '· ' . e($p['phone']) : '' ?></p>
        <p><strong>Materials subtotal:</strong> <?= currency_symbol() . money($p['subtotal']) ?></p>
        <?php if ((float) $p['loading_cost'] > 0): ?><p><strong>Loading:</strong> <?= currency_symbol() . money($p['loading_cost']) ?></p><?php endif; ?>
        <?php if ((float) $p['unloading_cost'] > 0): ?><p><strong>Unloading:</strong> <?= currency_symbol() . money($p['unloading_cost']) ?></p><?php endif; ?>
        <?php if ($logisticsCost > 0 && !$logisticsIncludedInTotal): ?><p style="color:#64748b;"><small>Logistics were recorded separately from this order total.</small></p><?php endif; ?>
        <p><strong>Total:</strong> <?= currency_symbol() . money($p['total_amount']) ?></p>
        <p><strong>Paid:</strong> <?= currency_symbol() . money($p['paid_amount']) ?><?php if ($logisticsIncludedInTotal && $logisticsCost > 0): ?> <small>(supplier <?= currency_symbol() . money($supplierPaid) ?> + logistics <?= currency_symbol() . money($logisticsCost) ?>)</small><?php endif; ?> · <strong>Balance:</strong> <?= currency_symbol() . money($p['balance']) ?></p>
        <?php if (!$paymentApproved): ?>
        <p><strong>Supplier payment requested:</strong> <?= currency_symbol() . money($p['requested_paid_amount']) ?></p>
        <p style="color:#856404;"><small>No payment is released until this purchase order is approved.</small></p>
        <?php endif; ?>
        <p><span class="badge badge-info"><?= e($p['payment_status']) ?></span>
           <span class="badge badge-<?= ($p['approval_status'] ?? 'pending') === 'approved' ? 'success' : (($p['approval_status'] ?? 'pending') === 'rejected' ? 'danger' : 'warning') ?>"><?= e($p['approval_status'] ?? 'pending') ?></span>
        </p>
        <?php if ($p['notes']): ?><p><strong>Notes:</strong> <?= e($p['notes']) ?></p><?php endif; ?>
    </div>
    <div class="panel">
        <h3>Line items</h3>
        <table class="data-table">
            <thead><tr><th>Material</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</td>
                    <td><?= number_format($i['quantity'], 2) ?></td>
                    <td><?= currency_symbol() . money($i['unit_cost']) ?></td>
                    <td><?= currency_symbol() . money($i['total_cost']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
