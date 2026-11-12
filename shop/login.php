<?php
require_once __DIR__ . '/bootstrap.php';
ensure_shop_schema();

if (shop_current_account()) {
    shop_redirect('account.php');
}

$identifier = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_check_csrf();
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $now = time();
    $attempts = array_values(array_filter((array) ($_SESSION['shop_login_attempts'] ?? []), static fn($t) => $now - (int) $t < 600));
    $_SESSION['shop_login_attempts'] = $attempts;

    if (count($attempts) >= 5) {
        $error = shop_t('Too many attempts. Please try again in a few minutes.');
    } else {
        $account = shop_login_account($identifier, $password);
        if ($account) {
            unset($_SESSION['shop_login_attempts']);
            shop_flash('success', shop_t('Welcome back') . ', ' . $account['name'] . '!');
            shop_redirect('account.php');
        }
        $attempts[] = $now;
        $_SESSION['shop_login_attempts'] = $attempts;
        $error = shop_t('Invalid email/phone or password.');
    }
}

$shopTitle = shop_t('Sign in');
$shopActive = 'login';
require __DIR__ . '/_header.php';
?>
<section class="section">
    <div class="wrap">
        <div class="card card-pad auth-card">
            <h1><?= e(shop_t('Welcome back')) ?></h1>
            <p class="sub"><?= e(shop_t('Sign in to your account to check out faster and follow your orders.')) ?></p>

            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

            <form method="post" action="<?= e(shop_url('login.php')) ?>">
                <?= shop_csrf_field() ?>
                <label for="identifier"><?= e(shop_t('Email or phone')) ?></label>
                <input id="identifier" type="text" name="identifier" required value="<?= e($identifier) ?>" autocomplete="username" autocapitalize="none" spellcheck="false">
                <label for="password"><?= e(shop_t('Password')) ?></label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:22px;"><?= e(shop_t('Sign in')) ?></button>
            </form>
            <div class="auth-alt">
                <?= e(shop_t('New here?')) ?> <a href="<?= e(shop_url('register.php')) ?>"><?= e(shop_t('Create an account')) ?></a>
                <br><a href="<?= e(shop_url('track.php')) ?>"><?= e(shop_t('Track your order')) ?></a>
                <br><a href="<?= e(shop_url('index.php')) ?>">← <?= e(shop_t('Continue shopping')) ?></a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
