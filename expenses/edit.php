<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'edit');
ensure_expense_approval_schema();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    render_not_found('Expense not found', 'That expense voucher does not exist or has been deleted.', '/block_plant/expenses/index.php', 'Back to expenses');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->execute([$id]);
$expense = $stmt->fetch();
if (!$expense) {
    render_not_found('Expense not found', 'That expense voucher does not exist or has been deleted.', '/block_plant/expenses/index.php', 'Back to expenses');
    exit;
}

$pageTitle = 'Edit Expense';
$flash = get_flash();
$categories = array_values(array_unique(array_merge(
    expense_categories(),
    $pdo->query("SELECT DISTINCT category FROM expenses ORDER BY category")->fetchAll(PDO::FETCH_COLUMN)
)));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$methods = expense_payment_methods();
require_once "../partials/header.php";
?>

<div style="max-width: 860px; margin: 0 auto 15px;">
    <a href="view.php?id=<?= (int) $id ?>" style="color:#64748b;text-decoration:none;font-weight:600;">← Back to expense</a>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>" style="max-width: 860px; margin: 0 auto 15px;"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="compact-form">
    <div class="compact-header">
        <h2>✏️ Edit Expense Voucher</h2>
        <p>Update the saved expense while preserving the original voucher reference.</p>
    </div>

    <form method="post" action="save.php" id="expenseForm">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="expense_id" value="<?= (int) $id ?>">
        <div class="compact-grid">
            <div class="c-group">
                <label for="expense_date">Expense date *</label>
                <input type="date" id="expense_date" name="expense_date" max="<?= date('Y-m-d') ?>" value="<?= e($expense['expense_date']) ?>" required>
            </div>
            <div class="c-group">
                <label for="category">Category *</label>
                <select id="category" name="category" required>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>" <?= $expense['category'] === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="c-group full">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="2" maxlength="255" placeholder="What was this expense for?" required><?= e($expense['description']) ?></textarea>
            </div>

            <div class="c-group">
                <label for="amount">Amount (<?= e(currency_symbol()) ?>) *</label>
                <input type="number" id="amount" name="amount" min="0.01" max="99999999.99" step="0.01" placeholder="0.00" value="<?= e($expense['amount']) ?>" required>
            </div>
            <div class="c-group">
                <label for="payment_method">Payment method</label>
                <select id="payment_method" name="payment_method">
                    <?php foreach ($methods as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($expense['payment_method'] ?? 'cash') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="c-group">
                <label for="reference_no">Reference number</label>
                <input type="text" id="reference_no" name="reference_no" maxlength="80" placeholder="Receipt or transfer no." value="<?= e($expense['reference_no'] ?? '') ?>">
            </div>
            <div class="c-group">
                <label for="notes">Notes</label>
                <input type="text" id="notes" name="notes" maxlength="2000" placeholder="Additional internal note" value="<?= e($expense['notes'] ?? '') ?>">
            </div>

            <div class="c-group full" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn-compact-save">Update Expense Voucher</button>
            </div>
        </div>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>
