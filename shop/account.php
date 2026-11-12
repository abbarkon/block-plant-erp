<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

$account = shop_current_account();
if (!$account) {
    shop_redirect('login.php');
}

$stmt = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM online_order_items i WHERE i.order_id = o.id) AS item_count
    FROM online_orders o
    WHERE o.account_id = ? OR o.customer_id = ?
    ORDER BY o.id DESC
    LIMIT 100");
$stmt->execute([(int) $account['id'], (int) $account['customer_id']]);
$orders = $stmt->fetchAll();

$shopTitle = shop_t('My account');
$shopActive = 'account';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div>
                <h2><?= e(shop_t('My account')) ?></h2>
                <p><?= e(shop_t('Welcome back')) ?>, <?= e($account['name']) ?>.</p>
            </div>
            <div class="toolbar">
                <a class="btn btn-outline btn-sm" href="<?= e(shop_url('index.php')) ?>"><?= e(shop_t('Continue shopping')) ?></a>
                <a class="btn btn-danger btn-sm" href="<?= e(shop_url('logout.php')) ?>"><?= e(shop_t('Log out')) ?></a>
            </div>
        </div>

        <div class="two-col">
            <div class="card card-pad">
                <h3 style="margin:0 0 12px;"><?= e(shop_t('My orders')) ?></h3>
                <?php if (!$orders): ?>
                <div class="empty" style="padding:34px 10px;">
                    <div class="big">🧱</div>
                    <h3><?= e(shop_t('No orders yet.')) ?></h3>
                    <a class="btn btn-primary" href="<?= e(shop_url('index.php')) ?>"><?= e(shop_t('Start shopping')) ?></a>
                </div>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="shop-table">
                        <thead>
                            <tr>
                                <th><?= e(shop_t('Order')) ?></th>
                                <th><?= e(shop_t('Date')) ?></th>
                                <th class="num"><?= e(shop_t('Items')) ?></th>
                                <th class="num"><?= e(shop_t('Total')) ?></th>
                                <th><?= e(shop_t('Status')) ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_no']) ?></strong></td>
                                <td><?= e(format_app_date($order['created_at'])) ?></td>
                                <td class="num"><?= number_format((int) $order['item_count']) ?></td>
                                <td class="num"><?= e(shop_money($order['total_amount'])) ?></td>
                                <td><span class="badge-status <?= e(shop_status_class($order['status'])) ?>"><?= e(shop_status_label($order['status'])) ?></span></td>
                                <td class="num"><a class="btn btn-soft btn-sm" href="<?= e(shop_url('track.php?no=' . urlencode($order['order_no']))) ?>"><?= e(shop_t('View order')) ?></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <aside class="card card-pad">
                <h3 style="margin:0 0 12px;"><?= e(shop_t('Profile')) ?></h3>
                <div class="summary-line"><span><?= e(shop_t('Name')) ?></span><strong><?= e($account['name']) ?></strong></div>
                <div class="summary-line"><span><?= e(shop_t('Phone')) ?></span><strong><?= e($account['customer_phone'] ?: '—') ?></strong></div>
                <div class="summary-line"><span><?= e(shop_t('Email')) ?></span><strong><?= e($account['email']) ?></strong></div>
                <?php if (!empty($account['customer_address'])): ?>
                <div class="summary-line"><span><?= e(shop_t('Address')) ?></span><strong><?= e($account['customer_address']) ?></strong></div>
                <?php endif; ?>
                <div class="summary-line"><span><?= e(shop_t('Customer code')) ?></span><strong><?= e($account['customer_code']) ?></strong></div>
                <p class="summary-note"><?= e(shop_t('Need to change your details? Contact us and we will update your profile.')) ?></p>
            </aside>
        </div>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
