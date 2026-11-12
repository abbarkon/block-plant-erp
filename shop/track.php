<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

$account = shop_current_account();
$orderNo = trim((string) ($_GET['no'] ?? ''));
$phone = '';
$error = '';
$order = null;
$items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    $orderNo = trim((string) ($_POST['order_no'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $candidate = $orderNo !== '' ? shop_order($orderNo) : null;

    if (!$candidate) {
        $error = shop_t('Order not found. Check the order number and phone number.');
    } else {
        $ownsOrder = $account && ((int) $candidate['account_id'] === (int) $account['id'] || (int) $candidate['customer_id'] === (int) $account['customer_id']);
        $phoneKey = shop_phone_key($phone);
        $orderPhoneKey = shop_phone_key($candidate['phone']);
        $phoneMatches = $phoneKey !== '' && $orderPhoneKey !== '' && substr($phoneKey, -9) === substr($orderPhoneKey, -9);
        if ($ownsOrder || $phoneMatches) {
            $order = $candidate;
            $items = shop_order_items((int) $order['id']);
        } else {
            $error = shop_t('Order not found. Check the order number and phone number.');
        }
    }
} elseif ($orderNo !== '' && $account) {
    $candidate = shop_order($orderNo);
    if ($candidate && ((int) $candidate['account_id'] === (int) $account['id'] || (int) $candidate['customer_id'] === (int) $account['customer_id'])) {
        $order = $candidate;
        $items = shop_order_items((int) $order['id']);
    }
}

$company = company_settings();
$shopTitle = shop_t('Track your order');
$shopActive = 'track';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap" style="max-width:900px;">
        <div class="section-head">
            <div>
                <h2><?= e(shop_t('Track your order')) ?></h2>
                <p><?= e(shop_t('Enter the order number and the phone number used at checkout.')) ?></p>
            </div>
        </div>

        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

        <div class="card card-pad">
            <form method="post" action="<?= e(shop_url('track.php')) ?>">
                <?= shop_csrf_field() ?>
                <div class="form-grid">
                    <div>
                        <label for="order_no"><?= e(shop_t('Order number')) ?></label>
                        <input id="order_no" type="text" name="order_no" required value="<?= e($orderNo) ?>" placeholder="WEB-000000-0000" autocomplete="off">
                    </div>
                    <div>
                        <label for="phone"><?= e(shop_t('Phone number')) ?></label>
                        <input id="phone" type="tel" name="phone" value="<?= e($phone) ?>" placeholder="09xx xxx xxx" autocomplete="tel">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= e(shop_t('Track order')) ?></button>
                <?php if (!$account): ?>
                <span class="muted small" style="margin-left:12px;"><?= e(shop_t('Or')) ?> <a href="<?= e(shop_url('login.php')) ?>"><?= e(shop_t('Sign in')) ?></a> <?= e(shop_t('to see all your orders.')) ?></span>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($order): ?>
        <div class="card card-pad" style="margin-top:18px;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:center;">
                <div>
                    <div class="muted small"><?= e(shop_t('Order number')) ?></div>
                    <strong style="font-size:1.2rem;"><?= e($order['order_no']) ?></strong>
                </div>
                <span class="badge-status <?= e(shop_status_class($order['status'])) ?>"><?= e(shop_status_label($order['status'])) ?></span>
            </div>

            <ul class="timeline" style="margin-top:18px;">
                <li class="done"><span class="dot"></span><div><strong><?= e(shop_t('Placed')) ?></strong><span class="muted small"><?= e(format_datetime($order['created_at'])) ?></span></div></li>
                <li class="<?= in_array($order['status'], ['confirmed', 'ready', 'completed'], true) ? 'done' : ($order['status'] === 'pending' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Confirmed')) ?></strong><?php if (!empty($order['confirmed_at'])): ?><span class="muted small"><?= e(format_datetime($order['confirmed_at'])) ?></span><?php endif; ?></div></li>
                <li class="<?= in_array($order['status'], ['ready', 'completed'], true) ? 'done' : ($order['status'] === 'confirmed' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Ready')) ?></strong></div></li>
                <li class="<?= $order['status'] === 'completed' ? 'done' : ($order['status'] === 'ready' ? 'now' : '') ?>"><span class="dot"></span><div><strong><?= e(shop_t('Completed')) ?></strong></div></li>
                <?php if ($order['status'] === 'cancelled'): ?>
                <li class="now"><span class="dot"></span><div><strong><?= e(shop_t('Cancelled')) ?></strong><?php if (!empty($order['cancelled_at'])): ?><span class="muted small"><?= e(format_datetime($order['cancelled_at'])) ?></span><?php endif; ?></div></li>
                <?php endif; ?>
            </ul>

            <div class="table-wrap" style="margin-top:18px;">
                <table class="shop-table">
                    <thead><tr><th><?= e(shop_t('Product')) ?></th><th class="num"><?= e(shop_t('Qty')) ?></th><th class="num"><?= e(shop_t('Unit price')) ?></th><th class="num"><?= e(shop_t('Line total')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?= e($item['name'] ?? '') ?></strong> <span class="muted small"><?= e($item['size'] ?? '') ?></span></td>
                            <td class="num"><?= number_format((int) $item['quantity']) ?></td>
                            <td class="num"><?= e(shop_money($item['unit_price'])) ?></td>
                            <td class="num"><strong><?= e(shop_money($item['total_price'])) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="summary-line" style="border-bottom:0;"><span><?= e(shop_t('Placed on')) ?></span><strong><?= e(format_datetime($order['created_at'])) ?></strong></div>
            <?php if (!empty($order['delivery_date'])): ?>
            <div class="summary-line" style="border-bottom:0;"><span><?= e(shop_t('Delivery date')) ?></span><strong><?= e(format_app_date($order['delivery_date'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($order['address'])): ?>
            <div class="summary-line" style="border-bottom:0;"><span><?= e(shop_t('Delivery address')) ?></span><strong><?= e($order['address']) ?></strong></div>
            <?php endif; ?>
            <div class="summary-total"><span><?= e(shop_t('Total')) ?></span><span><?= e(shop_money($order['total_amount'])) ?></span></div>
            <?php if (!empty($order['notes'])): ?>
            <p class="muted small" style="margin:8px 0 0;"><strong><?= e(shop_t('Notes')) ?>:</strong> <?= e($order['notes']) ?></p>
            <?php endif; ?>
            <?php if (!empty($company['phone'])): ?>
            <p class="muted small" style="margin:12px 0 0;"><?= e(shop_t('Need help? Call us at')) ?> <a href="tel:<?= e($company['phone']) ?>"><?= e($company['phone']) ?></a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
