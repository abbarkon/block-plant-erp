<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

$account = shop_current_account();
$items = shop_cart_items();
if (!$items) {
    shop_flash('info', shop_t('Your cart is empty.'));
    shop_redirect('cart.php');
}
$totals = shop_cart_totals($items);
$paymentMethods = ['cash' => shop_t('Cash on delivery'), 'bank' => shop_t('Bank transfer'), 'telebirr' => shop_t('Telebirr')];

$form = [
    'name' => $account['name'] ?? '',
    'phone' => ($account['customer_phone'] ?? '') ?: ($account['phone'] ?? ''),
    'email' => ($account['customer_email'] ?? '') ?: ($account['email'] ?? ''),
    'address' => $account['customer_address'] ?? '',
    'delivery_date' => '',
    'notes' => '',
    'payment_method' => 'cash',
    'create_account' => '0',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    foreach ($form as $key => $_) {
        if (isset($_POST[$key])) {
            $form[$key] = trim((string) $_POST[$key]);
        }
    }
    $form['create_account'] = empty($_POST['create_account']) ? '0' : '1';
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors[] = shop_t('Name is required.');
    }
    if ($form['phone'] === '' || mb_strlen($form['phone']) > 50) {
        $errors[] = shop_t('Phone number is required.');
    }
    if ($form['email'] !== '' && (mb_strlen($form['email']) > 100 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL))) {
        $errors[] = shop_t('Enter a valid email address.');
    }
    if (mb_strlen($form['address']) > 255) {
        $errors[] = shop_t('Address must be 255 characters or fewer.');
    }
    if ($form['delivery_date'] !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $form['delivery_date']);
        if (!$d || $d->format('Y-m-d') !== $form['delivery_date'] || $form['delivery_date'] < date('Y-m-d')) {
            $errors[] = shop_t('Enter a valid delivery date.');
        }
    }
    if (mb_strlen($form['notes']) > 1000) {
        $errors[] = shop_t('Order notes must be 1,000 characters or fewer.');
    }
    if (!isset($paymentMethods[$form['payment_method']])) {
        $form['payment_method'] = 'cash';
    }
    if ($form['create_account'] === '1' && !$account) {
        if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = shop_t('Email is required to create an account.');
        }
        if (strlen($password) < 6) {
            $errors[] = shop_t('Password must be at least 6 characters.');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = shop_t('Passwords do not match.');
        } else {
            $check = $pdo->prepare("SELECT id FROM shop_accounts WHERE email = ? LIMIT 1");
            $check->execute([$form['email']]);
            if ($check->fetchColumn()) {
                $errors[] = shop_t('An account with this email already exists. Please sign in.');
            }
        }
    }

    // Re-check the cart against live products and prices.
    $items = shop_cart_items();
    if (!$items) {
        $errors[] = shop_t('Some products in your cart are no longer available. Please review your cart.');
    }
    $totals = shop_cart_totals($items);

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $customerId = $account ? (int) $account['customer_id'] : shop_find_or_create_customer($form['name'], $form['phone'], $form['email'], $form['address']);
            $accountId = $account ? (int) $account['id'] : null;

            $orderNo = shop_generate_order_no();
            $stmt = $pdo->prepare("INSERT INTO online_orders(order_no, customer_id, account_id, customer_name, phone, email, address, delivery_date, notes, subtotal, total_amount, payment_method, status)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'pending')");
            $stmt->execute([
                $orderNo,
                $customerId,
                $accountId,
                $form['name'],
                $form['phone'],
                $form['email'] !== '' ? $form['email'] : null,
                $form['address'] !== '' ? $form['address'] : null,
                $form['delivery_date'] !== '' ? $form['delivery_date'] : null,
                $form['notes'] !== '' ? $form['notes'] : null,
                $totals['subtotal'],
                $totals['total'],
                $form['payment_method'],
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO online_order_items(order_id, block_type_id, quantity, unit_price, total_price) VALUES(?,?,?,?,?)");
            foreach ($items as $item) {
                $itemStmt->execute([$orderId, (int) $item['id'], (int) $item['quantity'], (float) $item['selling_price'], (float) $item['line_total']]);
            }

            if ($form['create_account'] === '1' && !$account) {
                $newAccountId = shop_create_account($customerId, $form['email'], $form['phone'], $password);
                if (!$newAccountId) {
                    throw new RuntimeException(shop_t('An account already exists for this phone or email. Please sign in.'));
                }
                $pdo->prepare("UPDATE online_orders SET account_id = ? WHERE id = ?")->execute([$newAccountId, $orderId]);
            }

            $pdo->commit();

            shop_cart_set([]);
            $_SESSION['shop_last_order_no'] = $orderNo;
            $_SESSION['shop_last_order_phone'] = $form['phone'];
            try {
                ensure_notifications_schema();
                notify_role(['admin', 'administrator', 'sales'], 'New online order ' . $orderNo . ' from ' . $form['name'] . ' (' . $form['phone'] . ')', '/block_plant/sales/online_order.php?id=' . $orderId);
                log_activity('create', 'sales', $orderId, 'Online order ' . $orderNo . ' placed by ' . $form['name']);
            } catch (Throwable $ignored) {
            }
            shop_redirect('success.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : shop_t('Your order could not be placed. Please try again.');
        }
    }
}

$shopTitle = shop_t('Checkout');
$shopActive = 'cart';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div>
                <h2><?= e(shop_t('Checkout')) ?></h2>
                <p><?= e(shop_t('No payment is taken online. We confirm your order first.')) ?></p>
            </div>
            <a class="btn btn-outline btn-sm" href="<?= e(shop_url('cart.php')) ?>">← <?= e(shop_t('Back to cart')) ?></a>
        </div>

        <?php if ($errors): ?>
        <div class="flash flash-error">
            <strong><?= e(shop_t('Please fix the following:')) ?></strong>
            <ul style="margin:8px 0 0;padding-left:20px;">
                <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($account): ?>
        <div class="account-banner">
            <span><?= e(shop_t('Ordering as')) ?> <strong><?= e($account['name']) ?></strong> · <?= e($account['email']) ?></span>
            <span class="small"><?= e(shop_t('Not you?')) ?> <a href="<?= e(shop_url('logout.php')) ?>"><?= e(shop_t('Log out')) ?></a></span>
        </div>
        <?php else: ?>
        <div class="account-banner">
            <span><?= e(shop_t('Already have an account?')) ?> <a href="<?= e(shop_url('login.php')) ?>"><?= e(shop_t('Sign in')) ?></a></span>
            <span class="small"><?= e(shop_t('Or continue as a guest below.')) ?></span>
        </div>
        <?php endif; ?>

        <form method="post" action="<?= e(shop_url('checkout.php')) ?>">
            <?= shop_csrf_field() ?>
            <div class="two-col">
                <div class="card card-pad">
                    <h3 style="margin:0 0 4px;"><?= e(shop_t('Contact details')) ?></h3>
                    <p class="muted small" style="margin:0 0 6px;"><?= e(shop_t('We use these details to confirm and deliver your order.')) ?></p>
                    <div class="form-grid">
                        <div class="full">
                            <label for="name"><?= e(shop_t('Full name')) ?> *</label>
                            <input id="name" type="text" name="name" maxlength="150" required value="<?= e($form['name']) ?>" autocomplete="name">
                        </div>
                        <div>
                            <label for="phone"><?= e(shop_t('Phone number')) ?> *</label>
                            <input id="phone" type="tel" name="phone" maxlength="50" required value="<?= e($form['phone']) ?>" autocomplete="tel" placeholder="09xx xxx xxx">
                        </div>
                        <div>
                            <label for="email"><?= e(shop_t('Email (optional)')) ?></label>
                            <input id="email" type="email" name="email" maxlength="100" value="<?= e($form['email']) ?>" autocomplete="email">
                        </div>
                        <div class="full">
                            <label for="address"><?= e(shop_t('Delivery address')) ?></label>
                            <input id="address" type="text" name="address" maxlength="255" value="<?= e($form['address']) ?>" autocomplete="street-address" placeholder="<?= e(shop_t('Area, street, landmark')) ?>">
                        </div>
                        <div>
                            <label for="delivery_date"><?= e(shop_t('Preferred delivery date')) ?></label>
                            <input id="delivery_date" type="date" name="delivery_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['delivery_date']) ?>">
                        </div>
                        <div>
                            <label for="payment_method"><?= e(shop_t('Payment method')) ?></label>
                            <select id="payment_method" name="payment_method">
                                <?php foreach ($paymentMethods as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['payment_method'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full">
                            <label for="notes"><?= e(shop_t('Order notes')) ?></label>
                            <textarea id="notes" name="notes" maxlength="1000" placeholder="<?= e(shop_t('Anything we should know? (optional)')) ?>"><?= e($form['notes']) ?></textarea>
                        </div>
                    </div>

                    <?php if (!$account): ?>
                    <label class="check" for="create_account">
                        <input type="checkbox" id="create_account" name="create_account" value="1" <?= $form['create_account'] === '1' ? 'checked' : '' ?> onchange="document.getElementById('accountFields').style.display = this.checked ? 'block' : 'none';">
                        <span><strong><?= e(shop_t('Create an account for faster checkout')) ?></strong><br><span class="muted small"><?= e(shop_t('Save your details and follow your orders. Optional.')) ?></span></span>
                    </label>
                    <div id="accountFields" style="display:<?= $form['create_account'] === '1' ? 'block' : 'none' ?>;">
                        <div class="form-grid">
                            <div>
                                <label for="password"><?= e(shop_t('Password')) ?></label>
                                <input id="password" type="password" name="password" minlength="6" autocomplete="new-password">
                            </div>
                            <div>
                                <label for="password_confirm"><?= e(shop_t('Confirm password')) ?></label>
                                <input id="password_confirm" type="password" name="password_confirm" minlength="6" autocomplete="new-password">
                            </div>
                        </div>
                        <p class="muted small" style="margin:8px 0 0;"><?= e(shop_t('Your email is used to sign in.')) ?></p>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:22px;">
                        <button type="submit" class="btn btn-primary"><?= e(shop_t('Place order')) ?></button>
                        <a class="btn btn-outline" href="<?= e(shop_url('cart.php')) ?>"><?= e(shop_t('Back to cart')) ?></a>
                    </div>
                </div>

                <aside class="card card-pad">
                    <h3 style="margin:0 0 12px;"><?= e(shop_t('Order summary')) ?></h3>
                    <?php foreach ($items as $item): ?>
                    <div class="summary-line">
                        <span><?= e($item['name']) ?> <span class="muted small"><?= e($item['size']) ?> × <?= number_format((int) $item['quantity']) ?></span></span>
                        <strong><?= e(shop_money($item['line_total'])) ?></strong>
                    </div>
                    <?php endforeach; ?>
                    <div class="summary-line"><span><?= e(shop_t('Subtotal')) ?></span><strong><?= e(shop_money($totals['subtotal'])) ?></strong></div>
                    <div class="summary-line"><span><?= e(shop_t('Delivery')) ?></span><strong><?= e(shop_t('Arranged after confirmation')) ?></strong></div>
                    <div class="summary-total"><span><?= e(shop_t('Total')) ?></span><span><?= e(shop_money($totals['total'])) ?></span></div>
                    <p class="summary-note"><?= e(shop_t('Delivery is arranged after we confirm your order.')) ?></p>
                    <p class="summary-note"><?= e(shop_t('Final price is confirmed by our team.')) ?></p>
                </aside>
            </div>
        </form>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
