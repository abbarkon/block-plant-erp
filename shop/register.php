<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

if (shop_current_account()) {
    shop_redirect('account.php');
}

$form = ['name' => '', 'phone' => '', 'email' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
        $errors[] = shop_t('Name is required.');
    }
    if ($form['phone'] === '' || mb_strlen($form['phone']) > 50) {
        $errors[] = shop_t('Phone number is required.');
    }
    if ($form['email'] === '' || mb_strlen($form['email']) > 100 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = shop_t('Enter a valid email address.');
    }
    if (strlen($password) < 6) {
        $errors[] = shop_t('Password must be at least 6 characters.');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = shop_t('Passwords do not match.');
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM shop_accounts WHERE email = ? LIMIT 1");
        $stmt->execute([$form['email']]);
        if ($stmt->fetchColumn()) {
            $errors[] = shop_t('An account with this email already exists. Please sign in.');
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $customerId = shop_find_or_create_customer($form['name'], $form['phone'], $form['email'], '');
            $accountId = shop_create_account($customerId, $form['email'], $form['phone'], $password);
            if (!$accountId) {
                throw new RuntimeException(shop_t('An account already exists for this phone or email. Please sign in.'));
            }
            $pdo->commit();
            shop_flash('success', shop_t('Account created. Welcome!'));
            shop_redirect('account.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : shop_t('We could not create your account. Please try again.');
        }
    }
}

$shopTitle = shop_t('Create your account');
$shopActive = 'register';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap">
        <div class="card card-pad auth-card">
            <h1><?= e(shop_t('Create your account')) ?></h1>
            <p class="sub"><?= e(shop_t('Sign up to check out faster and follow your orders.')) ?></p>

            <?php if ($errors): ?>
            <div class="flash flash-error">
                <strong><?= e(shop_t('Please fix the following:')) ?></strong>
                <ul style="margin:8px 0 0;padding-left:20px;">
                    <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= e(shop_url('register.php')) ?>">
                <?= shop_csrf_field() ?>
                <label for="name"><?= e(shop_t('Full name')) ?></label>
                <input id="name" type="text" name="name" maxlength="150" required value="<?= e($form['name']) ?>" autocomplete="name">
                <label for="phone"><?= e(shop_t('Phone number')) ?></label>
                <input id="phone" type="tel" name="phone" maxlength="50" required value="<?= e($form['phone']) ?>" autocomplete="tel">
                <label for="email"><?= e(shop_t('Email')) ?></label>
                <input id="email" type="email" name="email" maxlength="100" required value="<?= e($form['email']) ?>" autocomplete="email">
                <div class="form-grid">
                    <div>
                        <label for="password"><?= e(shop_t('Password')) ?></label>
                        <input id="password" type="password" name="password" minlength="6" required autocomplete="new-password">
                    </div>
                    <div>
                        <label for="password_confirm"><?= e(shop_t('Confirm password')) ?></label>
                        <input id="password_confirm" type="password" name="password_confirm" minlength="6" required autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;"><?= e(shop_t('Create account')) ?></button>
            </form>
            <div class="auth-alt">
                <?= e(shop_t('Already have an account?')) ?> <a href="<?= e(shop_url('login.php')) ?>"><?= e(shop_t('Sign in')) ?></a>
                <br><a href="<?= e(shop_url('index.php')) ?>">← <?= e(shop_t('Continue shopping')) ?></a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
