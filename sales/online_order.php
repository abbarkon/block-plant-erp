<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../shop/bootstrap.php";
require_login();
require_permission('sales', 'view');
ensure_shop_schema();

$orderId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT o.*, c.customer_code, c.name AS customer_record_name
    FROM online_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    render_not_found('Order not found', 'That online order could not be found.', 'online_orders.php', shop_t('Back to online orders'));
}
$items = shop_order_items($orderId);

$paymentLabels = ['cash' => shop_t('Cash on delivery'), 'bank' => shop_t('Bank transfer'), 'telebirr' => shop_t('Telebirr')];
$badgeFor = static function (string $s): string {
    return ['pending' => 'warning', 'confirmed' => 'info', 'ready' => 'info', 'completed' => 'success', 'cancelled' => 'danger'][$s] ?? 'muted';
};
$closed = in_array($order['status'], ['completed', 'cancelled'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_notes') {
        require_permission('sales', 'edit');
        $notes = trim((string) ($_POST['staff_notes'] ?? ''));
        $pdo->prepare("UPDATE online_orders SET staff_notes = ? WHERE id = ?")->execute([mb_substr($notes, 0, 255), $orderId]);
        log_activity('update', 'sales', $orderId, 'Updated staff notes on online order ' . $order['order_no']);
        set_flash('success', shop_t('Notes saved.'));
    } elseif ($action === 'confirm') {
        require_permission('sales', 'edit');
        if ($order['status'] === 'pending') {
            $pdo->prepare("UPDATE online_orders SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?")
                ->execute([(int) $_SESSION['user_id'], $orderId]);
            log_activity('update', 'sales', $orderId, 'Confirmed online order ' . $order['order_no']);
            set_flash('success', shop_t('Order confirmed.'));
        } else {
            set_flash('danger', shop_t('This order is already completed or cancelled.'));
        }
    } elseif ($action === 'ready') {
        require_permission('sales', 'edit');
        if ($order['status'] === 'confirmed') {
            $pdo->prepare("UPDATE online_orders SET status = 'ready' WHERE id = ?")->execute([$orderId]);
            log_activity('update', 'sales', $orderId, 'Marked online order ' . $order['order_no'] . ' ready');
            set_flash('success', shop_t('Order marked ready.'));
        } else {
            set_flash('danger', shop_t('This order is already completed or cancelled.'));
        }
    } elseif ($action === 'cancel') {
        require_permission('sales', 'edit');
        if (!$closed) {
            $reason = trim((string) ($_POST['staff_notes'] ?? ''));
            $pdo->prepare("UPDATE online_orders SET status = 'cancelled', cancelled_at = NOW(), staff_notes = ? WHERE id = ?")
                ->execute([$reason !== '' ? mb_substr($reason, 0, 255) : shop_t('Order cancelled by staff.'), $orderId]);
            log_activity('update', 'sales', $orderId, 'Cancelled online order ' . $order['order_no']);
            set_flash('success', shop_t('Order cancelled.'));
        } else {
            set_flash('danger', shop_t('This order is already completed or cancelled.'));
        }
    }
    header('Location: online_order.php?id=' . $orderId);
    exit;
}

$shortItems = [];
foreach ($items as $item) {
    if ((int) $item['quantity'] > (int) $item['stock']) {
        $shortItems[] = $item;
    }
}
$flash = get_flash();
$pageTitle = shop_t('Order details') . ' ' . $order['order_no'];
require_once "../partials/header.php";
?>
<style>
    .oo-detail-hero { background: linear-gradient(120deg, var(--bp-primary), var(--bp-accent)); color: #fff; border-radius: 14px; padding: 22px 26px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; align-items: center; }
    .oo-detail-hero h1 { margin: 0; font-size: 1.55rem; letter-spacing: .02em; }
    .oo-detail-hero p { margin: 5px 0 0; opacity: .85; }
    .oo-detail-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(300px, .8fr); gap: 18px; align-items: start; }
    .oo-panel { background: #fff; border: 1px solid var(--bp-border); border-radius: 12px; padding: 20px; box-shadow: 0 4px 16px rgba(15,23,42,.04); margin-bottom: 16px; }
    .oo-panel h3 { margin: 0 0 14px; font-size: 1.02rem; }
    .oo-line { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #eef2f3; font-size: .9rem; }
    .oo-line:last-child { border-bottom: 0; }
    .oo-line span:first-child { color: var(--bp-muted); }
    .oo-total { display: flex; justify-content: space-between; align-items: baseline; padding: 14px 0 2px; font-size: 1.3rem; font-weight: 800; color: var(--bp-primary); }
    .oo-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
    .oo-timeline { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
    .oo-timeline li { display: flex; gap: 10px; font-size: .88rem; }
    .oo-timeline .dot { flex: none; width: 11px; height: 11px; border-radius: 50%; margin-top: 4px; background: #cbd5e1; }
    .oo-timeline li.done .dot { background: #16a34a; }
    .oo-timeline li.now .dot { background: #d97706; box-shadow: 0 0 0 4px #fef3c7; }
    .oo-timeline strong { display: block; }
    .stock-ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; border-radius: 8px; padding: 9px 11px; font-size: .84rem; margin-bottom: 12px; }
    .stock-short { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: 9px 11px; font-size: .84rem; margin-bottom: 12px; }
    @media (max-width: 900px) { .oo-detail-grid { grid-template-columns: 1fr; } }
</style>

<div class="oo-detail-hero">
    <div>
        <h1><?= e(shop_t('Order')) ?> <?= e($order['order_no']) ?></h1>
        <p><?= e(shop_t('Placed on')) ?> <?= e(format_datetime($order['created_at'])) ?> · <span class="badge badge-<?= e($badgeFor($order['status'])) ?>" style="color:#1f2937;"><?= e(shop_status_label($order['status'])) ?></span></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" href="online_orders.php">← <?= e(shop_t('Back to online orders')) ?></a>
        <a class="btn" style="background:#fff;color:var(--bp-primary);" href="/block_plant/shop/index.php" target="_blank" rel="noopener">↗ <?= e(shop_t('Open shop')) ?></a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (!$closed): ?>
<div class="flash flash-info" style="display:block;"><?= e(shop_t('This order was placed on the public shop. Confirm it, then create an invoice when the customer collects or is dispatched.')) ?></div>
<?php endif; ?>

<div class="oo-detail-grid">
    <div>
        <div class="oo-panel">
            <h3><?= e(shop_t('Items')) ?></h3>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= e(shop_t('Product')) ?></th>
                            <th style="text-align:right;"><?= e(shop_t('Qty')) ?></th>
                            <th style="text-align:right;"><?= e(shop_t('Unit price')) ?></th>
                            <th style="text-align:right;"><?= e(shop_t('Line total')) ?></th>
                            <th style="text-align:right;"><?= e(shop_t('Ready to sell')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?= e($item['name'] ?? '') ?></strong> <span style="color:var(--bp-muted);"><?= e($item['size'] ?? '') ?></span></td>
                            <td style="text-align:right;"><?= number_format((int) $item['quantity']) ?></td>
                            <td style="text-align:right;"><?= e(currency_symbol()) ?><?= e(money($item['unit_price'])) ?></td>
                            <td style="text-align:right;"><?= e(currency_symbol()) ?><?= e(money($item['total_price'])) ?></td>
                            <td style="text-align:right;<?= (int) $item['quantity'] > (int) $item['stock'] ? 'color:#b91c1c;font-weight:700;' : '' ?>">
                                <?= number_format((int) $item['stock']) ?>
                                <?php if ((int) ($item['curing'] ?? 0) > 0): ?>
                                <div style="font-size:.72rem;color:#b45309;"><?= e(shop_t('Curing')) ?>: <?= number_format((int) $item['curing']) ?></div>
                                <?php endif; ?>
                                <?php if ((int) $item['quantity'] > (int) $item['stock']): ?>
                                <div style="font-size:.72rem;font-weight:600;"><?= e(shop_t('Short by')) ?> <?= number_format((int) $item['quantity'] - (int) $item['stock']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="oo-line" style="margin-top:12px;"><span><?= e(shop_t('Subtotal')) ?></span><strong><?= e(currency_symbol()) ?><?= e(money($order['subtotal'])) ?></strong></div>
            <div class="oo-total"><span><?= e(shop_t('Order total')) ?></span><span><?= e(currency_symbol()) ?><?= e(money($order['total_amount'])) ?></span></div>
        </div>

        <div class="oo-panel">
            <h3><?= e(shop_t('Order notes')) ?></h3>
            <?php if (!empty($order['notes'])): ?>
            <p style="margin:0;white-space:pre-wrap;"><?= e($order['notes']) ?></p>
            <?php else: ?>
            <p style="margin:0;color:var(--bp-muted);">—</p>
            <?php endif; ?>
        </div>

        <div class="oo-panel">
            <h3><?= e(shop_t('Staff notes')) ?></h3>
            <?php if (has_permission('sales', 'edit')): ?>
            <form method="post">
                <?= shop_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="action" value="save_notes">
                <textarea name="staff_notes" rows="3" maxlength="255" placeholder="<?= e(shop_t('Internal notes for the sales team')) ?>"><?= e($order['staff_notes'] ?? '') ?></textarea>
                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:8px;"><?= e(shop_t('Save notes')) ?></button>
            </form>
            <?php else: ?>
            <p style="margin:0;white-space:pre-wrap;"><?= e($order['staff_notes'] ?: '—') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <aside>
        <div class="oo-panel">
            <h3><?= e(shop_t('Customer details')) ?></h3>
            <div class="oo-line"><span><?= e(shop_t('Name')) ?></span><strong><?= e($order['customer_name']) ?></strong></div>
            <div class="oo-line"><span><?= e(shop_t('Phone')) ?></span><strong><a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a></strong></div>
            <?php if (!empty($order['email'])): ?>
            <div class="oo-line"><span><?= e(shop_t('Email')) ?></span><strong><?= e($order['email']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($order['address'])): ?>
            <div class="oo-line"><span><?= e(shop_t('Address')) ?></span><strong><?= e($order['address']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($order['customer_code'])): ?>
            <div class="oo-line"><span><?= e(shop_t('Customer code')) ?></span><strong><?= e($order['customer_code']) ?></strong></div>
            <?php endif; ?>
            <div class="oo-line"><span><?= e(shop_t('Customer account')) ?></span><strong><?= !empty($order['account_id']) ? e(shop_t('Registered customer')) : e(shop_t('Guest checkout')) ?></strong></div>
            <div class="oo-line"><span><?= e(shop_t('Payment method')) ?></span><strong><?= e($paymentLabels[$order['payment_method']] ?? ucfirst((string) $order['payment_method'])) ?></strong></div>
            <?php if (!empty($order['delivery_date'])): ?>
            <div class="oo-line"><span><?= e(shop_t('Delivery date')) ?></span><strong><?= e(format_app_date($order['delivery_date'])) ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="oo-panel">
            <h3><?= e(shop_t('Order timeline')) ?></h3>
            <ul class="oo-timeline">
                <li class="done"><span class="dot"></span><div><strong><?= e(shop_t('Placed')) ?></strong><span style="color:var(--bp-muted);"><?= e(format_datetime($order['created_at'])) ?></span></div></li>
                <li class="<?= in_array($order['status'], ['confirmed', 'ready', 'completed'], true) ? 'done' : ($order['status'] === 'pending' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Confirmed')) ?></strong><?php if (!empty($order['confirmed_at'])): ?><span style="color:var(--bp-muted);"><?= e(format_datetime($order['confirmed_at'])) ?></span><?php endif; ?></div></li>
                <li class="<?= in_array($order['status'], ['ready', 'completed'], true) ? 'done' : ($order['status'] === 'confirmed' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Ready')) ?></strong></div></li>
                <li class="<?= $order['status'] === 'completed' ? 'done' : ($order['status'] === 'ready' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Completed')) ?></strong></div></li>
                <?php if ($order['status'] === 'cancelled'): ?>
                <li class="now"><span class="dot"></span><div><strong><?= e(shop_t('Cancelled')) ?></strong><?php if (!empty($order['cancelled_at'])): ?><span style="color:var(--bp-muted);"><?= e(format_datetime($order['cancelled_at'])) ?></span><?php endif; ?></div></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="oo-panel">
            <h3><?= e(shop_t('Actions')) ?></h3>
            <?php if ($shortItems): ?>
            <div class="stock-short"><?= e(shop_t('Stock is short for one or more items. Confirm production before dispatch.')) ?></div>
            <?php else: ?>
            <div class="stock-ok"><?= e(shop_t('Stock is sufficient for this order.')) ?></div>
            <?php endif; ?>

            <div class="oo-actions">
                <?php if ($order['status'] === 'pending' && has_permission('sales', 'edit')): ?>
                <form method="post">
                    <?= shop_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-accent"><?= e(shop_t('Confirm order')) ?></button>
                </form>
                <?php endif; ?>
                <?php if ($order['status'] === 'confirmed' && has_permission('sales', 'edit')): ?>
                <form method="post">
                    <?= shop_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="action" value="ready">
                    <button type="submit" class="btn btn-accent"><?= e(shop_t('Mark as ready')) ?></button>
                </form>
                <?php endif; ?>
                <?php if (!$closed && has_permission('sales', 'create')): ?>
                <a class="btn btn-primary" href="create.php?order=<?= (int) $order['id'] ?>"><?= e(shop_t('Create invoice')) ?></a>
                <?php endif; ?>
                <?php if ($order['status'] === 'completed' && !empty($order['sale_id'])): ?>
                <a class="btn btn-primary" href="view.php?id=<?= (int) $order['sale_id'] ?>"><?= e(shop_t('View invoice')) ?></a>
                <?php endif; ?>
            </div>

            <?php if (!$closed && has_permission('sales', 'edit')): ?>
            <form method="post" style="margin-top:14px;" onsubmit="return confirm(<?= e(json_encode(shop_t('Cancel this online order?'))) ?>);">
                <?= shop_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <label style="margin-top:0;"><?= e(shop_t('Reason for cancellation')) ?></label>
                <input type="text" name="staff_notes" maxlength="255" placeholder="<?= e(shop_t('Optional')) ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="margin-top:8px;"><?= e(shop_t('Cancel order')) ?></button>
            </form>
            <?php endif; ?>

            <?php if (!$closed && has_permission('sales', 'create')): ?>
            <p style="color:var(--bp-muted);font-size:.8rem;margin:12px 0 0;"><?= e(shop_t('Create invoice to fulfil this order. The invoice will deduct stock and create the gate clearance.')) ?></p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require_once "../partials/footer.php"; ?>
