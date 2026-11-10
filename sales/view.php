<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'view');
ensure_gate_schema();

$saleId = (int) ($_GET['id'] ?? 0);
$saleStmt = $pdo->prepare("SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email, c.address AS customer_address, u.name AS salesperson_name, gc.id AS clearance_id, gc.clearance_number, gc.status AS clearance_status FROM sales s LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN users u ON u.id = s.salesperson_id LEFT JOIN gate_clearances gc ON gc.id = (SELECT g2.id FROM gate_clearances g2 WHERE g2.sale_id = s.id ORDER BY g2.id DESC LIMIT 1) WHERE s.id = ?");
$saleStmt->execute([$saleId]);
$sale = $saleStmt->fetch();
if (!$sale) {
    render_not_found('Sale not found', 'This invoice does not exist or was removed.', 'index.php', 'Back to sales');
}

$itemStmt = $pdo->prepare("SELECT si.quantity, si.unit_price, si.total_price, bt.name, bt.size FROM sale_items si JOIN block_types bt ON bt.id = si.block_type_id WHERE si.sale_id = ? ORDER BY si.id");
$itemStmt->execute([$saleId]);
$items = $itemStmt->fetchAll();

$paymentStmt = $pdo->prepare("SELECT p.*, u.name AS recorded_by_name FROM payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.sale_id = ? ORDER BY p.payment_date DESC, p.id DESC");
$paymentStmt->execute([$saleId]);
$payments = $paymentStmt->fetchAll();

$pageTitle = 'Sale ' . $sale['invoice_no'];
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1><?= e($sale['invoice_no']) ?></h1>
        <p class="sale-subtitle">Sale details · <?= e(format_date($sale['sale_date'])) ?></p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">All sales</a>
        <a href="invoice_print.php?id=<?= (int) $sale['id'] ?>" class="btn btn-secondary" target="_blank">Print invoice</a>
        <?php if ((float) $sale['balance'] > 0): ?>
        <a href="../payments/customer.php?customer_id=<?= (int) ($sale['customer_id'] ?? 0) ?>&amp;sale_id=<?= (int) $sale['id'] ?>" class="btn btn-accent">Record payment</a>
        <?php elseif (!$sale['clearance_id']): ?>
        <a href="vehicle.php?id=<?= (int) $sale['id'] ?>" class="btn btn-accent">Add vehicle</a>
        <?php endif; ?>
    </div>
</div>

<div class="sale-detail-grid">
    <section class="panel">
        <div class="sale-section-heading"><h2>Invoice summary</h2><span class="badge <?= strtolower($sale['payment_status']) === 'paid' ? 'badge-success' : 'badge-warning' ?>"><?= e(ucfirst($sale['payment_status'])) ?></span></div>
        <dl class="sale-details">
            <div><dt>Invoice date</dt><dd><?= e(format_date($sale['sale_date'])) ?></dd></div>
            <div><dt>Created</dt><dd><?= e(format_datetime($sale['created_at'])) ?></dd></div>
            <div><dt>Sold by</dt><dd><?= e($sale['salesperson_name'] ?: '—') ?></dd></div>
            <div><dt>Payment method</dt><dd><?= e($sale['payment_method'] ?: '—') ?></dd></div>
            <div><dt>Payment reference</dt><dd><?= e($sale['payment_reference'] ?: '—') ?></dd></div>
            <div><dt>Vehicle plate</dt><dd><?= e($sale['vehicle_plate'] ?: 'Not recorded') ?></dd></div>
            <?php if ($sale['driver_name'] || $sale['driver_phone']): ?>
            <div><dt>Driver</dt><dd><?= e($sale['driver_name'] ?: '—') ?><?= $sale['driver_phone'] ? ' · ' . e($sale['driver_phone']) : '' ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($sale['notes']): ?><p class="sale-notes"><strong>Notes:</strong> <?= nl2br(e($sale['notes'])) ?></p><?php endif; ?>
    </section>

    <section class="panel">
        <h2>Customer</h2>
        <div class="sale-customer-name"><?= e($sale['customer_name'] ?: 'Walk-in customer') ?></div>
        <?php if ($sale['customer_phone']): ?><p><?= e($sale['customer_phone']) ?></p><?php endif; ?>
        <?php if ($sale['customer_email']): ?><p><?= e($sale['customer_email']) ?></p><?php endif; ?>
        <?php if ($sale['customer_address']): ?><p><?= nl2br(e($sale['customer_address'])) ?></p><?php endif; ?>
        <?php if (!$sale['customer_id']): ?><p class="sale-muted">This sale was recorded without a customer account.</p><?php endif; ?>
    </section>
</div>

<section class="panel sale-panel">
    <div class="sale-section-heading"><h2>Items sold</h2><span><?= number_format(count($items)) ?> line items</span></div>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead>
            <tbody>
            <?php if (!$items): ?><tr><td colspan="4">No item details were recorded for this sale.</td></tr><?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?> <span class="sale-muted"><?= e($item['size']) ?></span></td>
                    <td><?= number_format((float) $item['quantity']) ?> blocks</td>
                    <td><?= e(currency_symbol()) . money($item['unit_price']) ?></td>
                    <td><strong><?= e(currency_symbol()) . money($item['total_price']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <dl class="sale-totals">
        <div><dt>Subtotal</dt><dd><?= e(currency_symbol()) . money($sale['subtotal']) ?></dd></div>
        <?php if ((float) $sale['discount'] > 0): ?><div><dt>Discount</dt><dd>-<?= e(currency_symbol()) . money($sale['discount']) ?></dd></div><?php endif; ?>
        <?php if ((float) $sale['loading_cost'] > 0): ?><div><dt>Loading</dt><dd><?= e(currency_symbol()) . money($sale['loading_cost']) ?></dd></div><?php endif; ?>
        <?php if ((float) $sale['unloading_cost'] > 0): ?><div><dt>Unloading</dt><dd><?= e(currency_symbol()) . money($sale['unloading_cost']) ?></dd></div><?php endif; ?>
        <div class="sale-total"><dt>Total</dt><dd><?= e(currency_symbol()) . money($sale['total_amount']) ?></dd></div>
        <div><dt>Paid</dt><dd><?= e(currency_symbol()) . money($sale['paid_amount']) ?></dd></div>
        <div class="sale-balance"><dt>Balance due</dt><dd><?= e(currency_symbol()) . money($sale['balance']) ?></dd></div>
    </dl>
</section>

<section class="panel sale-panel">
    <div class="sale-section-heading"><h2>Payment history</h2><span><?= number_format(count($payments)) ?> payments</span></div>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Notes</th><th>Recorded by</th></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="5">No payment transactions are linked to this sale.</td></tr><?php endif; ?>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= e(format_date($payment['payment_date'])) ?></td>
                    <td><?= e($payment['payment_method']) ?></td>
                    <td><strong><?= e(currency_symbol()) . money($payment['amount']) ?></strong></td>
                    <td><?= e($payment['notes'] ?: '—') ?></td>
                    <td><?= e($payment['recorded_by_name'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel sale-panel sale-gate-panel">
    <div class="sale-section-heading"><h2>Gate clearance</h2>
        <?php if ($sale['clearance_number']): ?>
        <span class="badge <?= $sale['clearance_status'] === 'EXITED' ? 'badge-success' : ($sale['clearance_status'] === 'REJECTED' ? 'badge-danger' : 'badge-info') ?>"><?= e(str_replace('_', ' ', $sale['clearance_status'])) ?></span>
        <?php else: ?><span class="badge badge-muted">No clearance</span><?php endif; ?>
    </div>
    <?php if ($sale['clearance_number']): ?>
    <div class="sale-gate-details"><div><dt>Clearance number</dt><dd><?= e($sale['clearance_number']) ?></dd></div><div><dt>Vehicle</dt><dd><?= e($sale['vehicle_plate'] ?: 'Not recorded') ?></dd></div></div>
    <div class="header-actions" style="margin-top:14px;">
        <a href="../gate/index.php?clearance=<?= urlencode($sale['clearance_number']) ?>" class="btn btn-secondary">Open gate record</a>
        <a href="../gate/clearance_print.php?id=<?= (int) $sale['clearance_id'] ?>" class="btn btn-secondary" target="_blank">Print gate pass</a>
    </div>
    <?php elseif ((float) $sale['balance'] > 0): ?>
    <p class="sale-muted">A gate clearance becomes available after the sale is fully paid and vehicle details are recorded.</p>
    <?php else: ?>
    <p class="sale-muted">No gate clearance has been created for this sale yet.</p>
    <?php endif; ?>
</section>

<style>
.sale-subtitle{margin:4px 0 0;color:#646b8c}.sale-detail-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.sale-detail-grid .panel h2,.sale-section-heading h2{margin:0;font-size:1.05rem}.sale-section-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.sale-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px;margin:14px 0 0}.sale-details div,.sale-gate-details div{padding:10px 0;border-bottom:1px solid var(--bp-border)}.sale-details dt,.sale-gate-details dt,.sale-totals dt{color:#646b8c;font-size:.78rem}.sale-details dd,.sale-gate-details dd,.sale-totals dd{margin:4px 0 0;font-weight:600;overflow-wrap:anywhere}.sale-customer-name{font-size:1.15rem;font-weight:700;color:#1c2250;margin:16px 0 8px}.sale-customer-name+p,.sale-customer-name+p+p{margin:5px 0;color:#52616b}.sale-notes{margin:14px 0 0;padding:10px;background:#f5f8f7;border-left:3px solid #7a5cd6}.sale-muted{color:#646b8c;font-size:.86rem}.sale-panel{margin-top:18px}.sale-totals{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));max-width:500px;margin:16px 0 0 auto}.sale-totals div{display:flex;justify-content:space-between;gap:12px;padding:7px 10px;border-bottom:1px solid var(--bp-border)}.sale-totals .sale-total{font-weight:800;background:#f1f8f5;color:#1c2250}.sale-totals .sale-balance{font-weight:800;color:#a8432d}.sale-gate-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.sale-gate-panel .header-actions{justify-content:flex-start}@media(max-width:800px){.sale-detail-grid{grid-template-columns:1fr}}@media(max-width:520px){.sale-details,.sale-gate-details{grid-template-columns:1fr}.sale-totals{grid-template-columns:1fr}.sale-section-heading{align-items:flex-start;flex-wrap:wrap}}
</style>

<?php require_once "../partials/footer.php"; ?>