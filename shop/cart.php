<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    $action = $_POST['action'] ?? '';
    $cart = shop_cart();

    if ($action === 'update') {
        $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
        foreach ($quantities as $id => $qty) {
            $id = (int) $id;
            $qty = max(0, min(999999, (int) $qty));
            if ($qty <= 0) {
                unset($cart[$id]);
            } else {
                $cart[$id] = $qty;
            }
        }
        shop_cart_set($cart);
        shop_flash('success', shop_t('Cart updated.'));
    } elseif ($action === 'remove') {
        unset($cart[(int) ($_POST['block_type_id'] ?? 0)]);
        shop_cart_set($cart);
        shop_flash('success', shop_t('Item removed from your cart.'));
    } elseif ($action === 'clear') {
        shop_cart_set([]);
        shop_flash('success', shop_t('Cart cleared.'));
    }
    shop_redirect('cart.php');
}

$items = shop_cart_items();
$totals = shop_cart_totals($items);
$shopTitle = shop_t('Your cart');
$shopActive = 'cart';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div>
                <h2><?= e(shop_t('Your cart')) ?></h2>
                <p><?= e(shop_t('Delivery is arranged after we confirm your order.')) ?></p>
            </div>
            <a class="btn btn-outline btn-sm" href="<?= e(shop_url('index.php')) ?>">← <?= e(shop_t('Continue shopping')) ?></a>
        </div>

        <?php if (!$items): ?>
        <div class="card card-pad empty">
            <div class="big">🛒</div>
            <h3><?= e(shop_t('Your cart is empty.')) ?></h3>
            <p class="muted"><?= e(shop_t('Browse our blocks and add what you need.')) ?></p>
            <a class="btn btn-primary" href="<?= e(shop_url('index.php')) ?>"><?= e(shop_t('Browse products')) ?></a>
        </div>
        <?php else: ?>
        <div class="cart-layout">
            <div class="card">
                <form method="post" action="<?= e(shop_url('cart.php')) ?>">
                    <?= shop_csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <div class="table-wrap">
                        <table class="shop-table">
                            <thead>
                                <tr>
                                    <th><?= e(shop_t('Product')) ?></th>
                                    <th><?= e(shop_t('Unit price')) ?></th>
                                    <th><?= e(shop_t('Quantity')) ?></th>
                                    <th class="num"><?= e(shop_t('Line total')) ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($item['name']) ?></strong>
                                        <div class="muted small"><?= e($item['size']) ?></div>
                                    </td>
                                    <td class="num"><?= e(shop_money($item['selling_price'])) ?></td>
                                    <td>
                                        <input class="qty-input" type="number" name="quantity[<?= (int) $item['id'] ?>]" value="<?= (int) $item['quantity'] ?>" min="0" max="999999" step="1" aria-label="<?= e(shop_t('Quantity')) ?>">
                                    </td>
                                    <td class="num"><strong><?= e(shop_money($item['line_total'])) ?></strong></td>
                                    <td class="num">
                                        <button type="submit" form="remove-<?= (int) $item['id'] ?>" class="btn btn-danger btn-sm" title="<?= e(shop_t('Remove')) ?>">✕</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-pad" style="display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center;border-top:1px solid var(--line);">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-soft"><?= e(shop_t('Update cart')) ?></button>
                            <button type="submit" form="clear-cart" class="btn btn-danger" onclick="return confirm(<?= e(json_encode(shop_t('Remove all items from your cart?'))) ?>);"><?= e(shop_t('Clear cart')) ?></button>
                        </div>
                        <a class="btn btn-outline" href="<?= e(shop_url('index.php')) ?>">← <?= e(shop_t('Continue shopping')) ?></a>
                    </div>
                </form>
                <?php foreach ($items as $item): ?>
                <form method="post" action="<?= e(shop_url('cart.php')) ?>" id="remove-<?= (int) $item['id'] ?>" style="display:none;">
                    <?= shop_csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="block_type_id" value="<?= (int) $item['id'] ?>">
                </form>
                <?php endforeach; ?>
                <form method="post" action="<?= e(shop_url('cart.php')) ?>" id="clear-cart" style="display:none;">
                    <?= shop_csrf_field() ?>
                    <input type="hidden" name="action" value="clear">
                </form>
            </div>

            <aside class="card card-pad">
                <h3 style="margin:0 0 6px;"><?= e(shop_t('Order summary')) ?></h3>
                <div class="summary-line"><span><?= e(shop_t('Subtotal')) ?></span><strong><?= e(shop_money($totals['subtotal'])) ?></strong></div>
                <div class="summary-line"><span><?= e(shop_t('Delivery')) ?></span><strong><?= e(shop_t('Arranged after confirmation')) ?></strong></div>
                <div class="summary-total"><span><?= e(shop_t('Total')) ?></span><span><?= e(shop_money($totals['total'])) ?></span></div>
                <p class="summary-note"><?= e(shop_t('Final price is confirmed by our team.')) ?></p>
                <a class="btn btn-primary btn-block" style="margin-top:16px;" href="<?= e(shop_url('checkout.php')) ?>"><?= e(shop_t('Proceed to checkout')) ?> →</a>
                <p class="summary-note" style="text-align:center;"><?= e(shop_t('No payment is taken online. We confirm your order first.')) ?></p>
            </aside>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
