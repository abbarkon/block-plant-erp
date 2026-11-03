<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'create');
ensure_expense_approval_schema();

$pageTitle = 'New Expense';
$flash = get_flash();
$old = $_SESSION['expense_form_old'] ?? [];
unset($_SESSION['expense_form_old']);

$categories = array_values(array_unique(array_merge(
    expense_categories(),
    $pdo->query("SELECT DISTINCT category FROM expenses ORDER BY category")->fetchAll(PDO::FETCH_COLUMN)
)));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$methods = expense_payment_methods();

$isAdmin = in_array(strtolower(trim((string) ($_SESSION['role'] ?? ''))), ['admin', 'administrator'], true);
$value = static function ($key, $fallback = '') use ($old) {
    return $old[$key] ?? $fallback;
};
require_once "../partials/header.php";
?>

<div style="max-width: 860px; margin: 0 auto 15px;">
    <a href="index.php" style="color:#64748b;text-decoration:none;font-weight:600;">← Back to Expenses</a>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>" style="max-width: 860px; margin: 0 auto 15px;"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="compact-form">
    <div class="compact-header">
        <h2>📉 Record Expense</h2>
        <p>Capture the cost, payment method, and supporting reference<?= $isAdmin ? '.' : '. A manager will review it before it posts to the cashbook.' ?></p>
    </div>

    <form method="post" action="save.php" id="expenseForm">
        <div class="compact-grid">
            <div class="c-group">
                <label for="expense_date">Expense date *</label>
                <input type="date" id="expense_date" name="expense_date" max="<?= date('Y-m-d') ?>" value="<?= e($value('expense_date', date('Y-m-d'))) ?>" required>
            </div>
            <div class="c-group">
                <label for="category">Category *</label>
                <select id="category" name="category" required>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>" <?= $value('category') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="c-group full">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="2" maxlength="255" placeholder="What was this expense for?" required><?= e($value('description')) ?></textarea>
            </div>

            <div class="c-group">
                <label for="amount">Amount (<?= e(currency_symbol()) ?>) *</label>
                <input type="number" id="amount" name="amount" min="0.01" max="99999999.99" step="0.01" placeholder="0.00" value="<?= e($value('amount')) ?>" required>
            </div>
            <div class="c-group">
                <label for="payment_method">Payment method</label>
                <select id="payment_method" name="payment_method">
                    <?php foreach ($methods as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $value('payment_method', 'cash') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="c-group">
                <label for="reference_no">Reference number</label>
                <input type="text" id="reference_no" name="reference_no" maxlength="80" placeholder="Receipt or transfer no." value="<?= e($value('reference_no')) ?>">
            </div>
            <div class="c-group">
                <label for="notes">Notes</label>
                <input type="text" id="notes" name="notes" maxlength="2000" placeholder="Additional internal note" value="<?= e($value('notes')) ?>">
            </div>

            <div class="c-group full" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn-compact-save">Save Expense Voucher</button>
                <label style="display:flex;align-items:center;gap:7px;font-weight:500;margin:0;">
                    <input type="checkbox" name="add_another" value="1" style="width:auto;margin:0;"> Save and add another
                </label>
            </div>
        </div>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>
