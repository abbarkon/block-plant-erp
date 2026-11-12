<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

$orderNo = (string) ($_SESSION['shop_last_order_no'] ?? '');
$order = $orderNo !== '' ? shop_order($orderNo) : null;
if (!$order) {
    shop_redirect('index.php');
}
$items = shop_order_items((int) $order['id']);
$account = shop_current_account();
$shopTitle = shop_t('Thank you! Your order has been received.');
$shopActive = 'home';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap" style="max-width:820px;">
        <div class="card card-pad" style="text-align:center;">
            <div style="font-size:3rem;line-height:1;">✅</div>
            <h1 style="margin:10px 0 6px;font-size:1.8rem;"><?= e(shop_t('Thank you! Your order has been received.')) ?></h1>
            <p class="muted" style="margin:0 0 18px;"><?= e(shop_t('We will call you to confirm availability, payment and delivery.')) ?></p>
            <div style="display:inline-flex;flex-direction:column;gap:4px;background:color-mix(in srgb, var(--p) 7%, #fff);border:1px dashed color-mix(in srgb, var(--p) 35%, #fff);border-radius:14px;padding:14px 26px;">
                <span class="muted small"><?= e(shop_t('Order number')) ?></span>
                <strong style="font-size:1.35rem;letter-spacing:.04em;"><?= e($order['order_no']) ?></strong>
            </div>
            <div style="margin-top:14px;">
                <span class="badge-status <?= e(shop_status_class($order['status'])) ?>"><?= e(shop_status_label($order['status'])) ?></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:22px;">
                <a class="btn btn-primary" href="<?= e(shop_url('track.php?no=' . urlencode($order['order_no']))) ?>"><?= e(shop_t('Track this order')) ?></a>
                <a class="btn btn-outline" href="<?= e(shop_url('index.php')) ?>"><?= e(shop_t('Continue shopping')) ?></a>
                <?php if ($account): ?>
                <a class="btn btn-soft" href="<?= e(shop_url('account.php')) ?>"><?= e(shop_t('My orders')) ?></a>
                <?php endif; ?>
            </div>
            <p class="muted small" style="margin:18px 0 0;"><?= e(shop_t('A confirmation has been saved for our sales team.')) ?></p>
        </div>

        <div class="card card-pad" style="margin-top:18px;">
            <h3 style="margin:0 0 12px;"><?= e(shop_t('Order details')) ?></h3>
            <div class="table-wrap">
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
            <div style="display:flex;justify-content:space-between;gap:20px;padding-top:14px;font-size:1.2rem;font-weight:800;color:var(--p);">
                <span><?= e(shop_t('Total')) ?></span><span><?= e(shop_money($order['total_amount'])) ?></span>
            </div>
            <?php if (!empty($order['address'])): ?>
            <p class="muted small" style="margin:12px 0 0;"><strong><?= e(shop_t('Delivery address')) ?>:</strong> <?= e($order['address']) ?></p>
            <?php endif; ?>
            <?php if (!empty($order['delivery_date'])): ?>
            <p class="muted small" style="margin:4px 0 0;"><strong><?= e(shop_t('Delivery date')) ?>:</strong> <?= e(format_app_date($order['delivery_date'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
