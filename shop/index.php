<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    shop_check_csrf();
    $blockId = (int) ($_POST['block_type_id'] ?? 0);
    $qty = max(1, min(999999, (int) ($_POST['quantity'] ?? 1)));

    $stmt = $pdo->prepare("SELECT id, name, size FROM block_types WHERE id = ? AND status = 1 AND selling_price > 0");
    $stmt->execute([$blockId]);
    $product = $stmt->fetch();
    if (!$product) {
        shop_flash('error', shop_t('That product is no longer available.'));
    } else {
        $cart = shop_cart();
        $cart[$blockId] = min(999999, ($cart[$blockId] ?? 0) + $qty);
        shop_cart_set($cart);
        shop_flash('success', sprintf(shop_t('%s added to your cart.'), $product['name'] . ' ' . $product['size']));
    }
    shop_redirect('index.php#products');
}

$products = shop_products();
$items = shop_cart_items();
$totals = shop_cart_totals($items);
$cartCount = shop_cart_count();
$company = company_settings();
$shopTitle = shop_t('Online Shop');
$shopActive = 'home';
require __DIR__ . '/_header.php';
?>
<section class="hero">
    <div class="wrap hero-inner">
        <div>
            <h1><?= e(shop_t('Order blocks online')) ?></h1>
            <p><?= e(shop_t('Browse our block range, add what you need to the cart, and place your order. Sign in for faster checkout or continue as a guest.')) ?></p>
            <div class="hero-actions">
                <a class="btn btn-light" href="#products"><?= e(shop_t('Browse products')) ?></a>
                <?php if ($cartCount > 0): ?>
                <a class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,.55);" href="<?= e(shop_url('cart.php')) ?>"><?= e(shop_t('Cart')) ?> · <?= number_format($cartCount) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <aside class="hero-card">
            <h3><?= e(shop_t('How it works')) ?></h3>
            <ol>
                <li><strong><?= e(shop_t('Choose your blocks')) ?></strong><?= e(shop_t('Pick the sizes you need and add them to your cart.')) ?></li>
                <li><strong><?= e(shop_t('Place your order')) ?></strong><?= e(shop_t('Check out as a guest or sign in to your account.')) ?></li>
                <li><strong><?= e(shop_t('We confirm and deliver')) ?></strong><?= e(shop_t('Our team calls you to confirm availability, payment and delivery.')) ?></li>
            </ol>
        </aside>
    </div>
</section>

<section class="section" id="products">
    <div class="wrap">
        <div class="section-head">
            <div>
                <h2><?= e(shop_t('Products')) ?></h2>
                <p><?= e(shop_t('Price per block')) ?> · <?= e(shop_t('Delivery is arranged after we confirm your order.')) ?></p>
            </div>
            <?php if ($cartCount > 0): ?>
            <a class="btn btn-soft btn-sm" href="<?= e(shop_url('cart.php')) ?>">🛒 <?= e(shop_t('Your cart')) ?> · <?= e(shop_money($totals['total'])) ?></a>
            <?php endif; ?>
        </div>

        <?php if (!$products): ?>
        <div class="card card-pad empty">
            <div class="big">🧱</div>
            <h3><?= e(shop_t('No products are available right now.')) ?></h3>
            <p class="muted"><?= e(shop_t('Please check back soon or contact us directly.')) ?></p>
            <?php if (!empty($company['phone'])): ?>
            <a class="btn btn-primary" href="tel:<?= e($company['phone']) ?>"><?= e(shop_t('Need help? Call us at')) ?> <?= e($company['phone']) ?></a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="grid-products">
            <?php foreach ($products as $p): ?>
            <?php $stock = (int) $p['stock']; ?>
            <article class="product">
                <div class="product-art" aria-hidden="true">
                    <div class="brick-wall">
                        <?php for ($r = 0; $r < 3; $r++): ?>
                        <div class="brick-row<?= $r % 2 ? ' off' : '' ?>">
                            <?php for ($b = 0; $b < 4; $b++): ?><span class="brick"></span><?php endfor; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="product-body">
                    <h3 class="product-name"><?= e($p['name']) ?></h3>
                    <div class="product-size"><?= e($p['size']) ?></div>
                    <div class="product-price"><?= e(shop_money($p['selling_price'])) ?> <small>/ <?= e(shop_t('block')) ?></small></div>
                    <?php if ($stock > 0): ?>
                    <div class="stock-line"><?= e(sprintf(shop_t('In stock: %s'), number_format($stock))) ?></div>
                    <?php else: ?>
                    <div class="stock-line out"><?= e(shop_t('Out of stock — made to order')) ?></div>
                    <?php endif; ?>
                    <?php if ((int) ($p['curing'] ?? 0) > 0): ?>
                    <div class="stock-line" style="color:#b45309;"><?= e(sprintf(shop_t('Curing: %s'), number_format((int) $p['curing']))) ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(shop_url('index.php')) ?>">
                        <?= shop_csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="block_type_id" value="<?= (int) $p['id'] ?>">
                        <input type="number" name="quantity" value="1" min="1" max="999999" step="1" aria-label="<?= e(shop_t('Quantity')) ?>">
                        <button type="submit" class="btn btn-primary"><?= e(shop_t('Add to cart')) ?></button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
