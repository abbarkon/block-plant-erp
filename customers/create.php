<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('customers', 'create');

$pageTitle = "New Customer";
$categories = $pdo->query("SELECT id, name FROM customer_categories WHERE status = 1 ORDER BY name")->fetchAll();
$errors = [];
$form = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'category_id' => (int) ($_POST['category_id'] ?? 0),
    'credit_limit' => (string) ($_POST['credit_limit'] ?? '0.00'),
    'opening_balance' => (string) ($_POST['opening_balance'] ?? '0.00'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($form['name'] === '') {
        $errors[] = 'Customer name is required.';
    } elseif (mb_strlen($form['name']) > 150) {
        $errors[] = 'Customer name must be 150 characters or fewer.';
    }
    if (mb_strlen($form['phone']) > 50) {
        $errors[] = 'Phone number must be 50 characters or fewer.';
    }
    if ($form['email'] !== '' && (mb_strlen($form['email']) > 100 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'Enter a valid email address (100 characters or fewer).';
    }
    if (mb_strlen($form['address']) > 255) {
        $errors[] = 'Address must be 255 characters or fewer.';
    }
    if ($form['category_id'] > 0) {
        $catCheck = $pdo->prepare("SELECT id FROM customer_categories WHERE id = ? AND status = 1");
        $catCheck->execute([$form['category_id']]);
        if (!$catCheck->fetchColumn()) {
            $errors[] = 'Select a valid customer category.';
        }
    }
    $credit = (float) $form['credit_limit'];
    $opening = (float) $form['opening_balance'];
    if ($credit < 0 || $credit > 9999999999.99) {
        $errors[] = 'Credit limit must be between 0 and 9,999,999,999.99.';
    }
    if ($opening < 0 || $opening > 9999999999.99) {
        $errors[] = 'Opening balance must be between 0 and 9,999,999,999.99.';
    }

    if (!$errors) {
        $code = '';
        for ($attempt = 0; $attempt < 5 && $code === ''; $attempt++) {
            $candidate = 'CUS-' . date('YmdHis') . random_int(10, 99);
            $exists = $pdo->prepare("SELECT id FROM customers WHERE customer_code = ?");
            $exists->execute([$candidate]);
            if (!$exists->fetchColumn()) {
                $code = $candidate;
            }
        }
        if ($code === '') {
            $errors[] = 'Could not generate a unique customer code. Please try again.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO customers(customer_code, name, phone, email, address, category_id, credit_limit, opening_balance) VALUES(?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $code,
            $form['name'],
            $form['phone'] !== '' ? $form['phone'] : null,
            $form['email'] !== '' ? $form['email'] : null,
            $form['address'] !== '' ? $form['address'] : null,
            $form['category_id'] > 0 ? $form['category_id'] : null,
            $credit,
            $opening,
        ]);
        $customerId = (int) $pdo->lastInsertId();
        log_activity('create', 'customers', $customerId, 'Created customer ' . $form['name']);
        set_flash('success', 'Customer created successfully.');
        header('Location: view.php?id=' . $customerId);
        exit;
    }
}

require_once "../partials/header.php";
?>

<div style="max-width: 800px; margin: 0 auto 15px;">
    <a href="index.php" style="color:#64748b;text-decoration:none;font-weight:600;">← Back to Customers</a>
</div>

<?php if ($errors): ?>
<div class="flash flash-error">
    <strong>Please fix the following:</strong>
    <ul style="margin:8px 0 0;padding-left:20px;">
        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="compact-form">
    <div class="compact-header">
        <h2>👤 New Customer</h2>
        <p>Register a new client profile</p>
    </div>
    <form method="post">
        <div class="compact-grid">
            <div class="c-group full">
                <label>Name *</label>
                <input name="name" required maxlength="150" value="<?= e($form['name']) ?>" placeholder="Full name or company name">
            </div>
            <div class="c-group">
                <label>Category</label>
                <select name="category_id">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $form['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="c-group">
                <label>Phone</label>
                <input name="phone" maxlength="50" value="<?= e($form['phone']) ?>" placeholder="e.g. +123456789">
            </div>
            <div class="c-group full">
                <label>Email</label>
                <input type="email" name="email" maxlength="100" value="<?= e($form['email']) ?>" placeholder="contact@company.com">
            </div>
            <div class="c-group full">
                <label>Address</label>
                <textarea name="address" rows="2" maxlength="255" placeholder="Full physical address"><?= e($form['address']) ?></textarea>
            </div>
            <div class="c-group">
                <label>Credit limit (<?= e(currency_symbol()) ?>)</label>
                <input type="number" step="0.01" min="0" name="credit_limit" value="<?= e($form['credit_limit']) ?>">
            </div>
            <div class="c-group">
                <label>Opening balance (<?= e(currency_symbol()) ?>)</label>
                <input type="number" step="0.01" min="0" name="opening_balance" value="<?= e($form['opening_balance']) ?>">
            </div>
            <div class="c-group full">
                <button type="submit" class="btn-compact-save">Save Customer Profile</button>
            </div>
        </div>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>
