<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'view');
ensure_notifications_schema();
ensure_expense_approval_schema();

$pdo->exec("CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    description TEXT,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
    next_due_date DATE NOT NULL,
    payment_method VARCHAR(30) DEFAULT 'cash',
    status VARCHAR(20) DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

$columns = array_column($pdo->query('SHOW COLUMNS FROM recurring_expenses')->fetchAll(), 'Field');
if (!in_array('last_generated_at', $columns, true)) {
    $pdo->exec("ALTER TABLE recurring_expenses ADD COLUMN last_generated_at DATETIME NULL");
}

$pageTitle = 'Recurring Expenses';
$frequencies = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
$methods = expense_payment_methods();
$categories = array_values(array_unique(array_merge(
    expense_categories(),
    $pdo->query("SELECT DISTINCT category FROM recurring_expenses")->fetchAll(PDO::FETCH_COLUMN),
    $pdo->query("SELECT DISTINCT category FROM expenses")->fetchAll(PDO::FETCH_COLUMN)
)));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

function recurring_add_frequency_date($dateString, $frequency) {
    $date = new DateTime($dateString);
    switch ($frequency) {
        case 'weekly':
            $date->modify('+7 days');
            break;
        case 'quarterly':
            $date->modify('+3 months');
            break;
        case 'yearly':
            $date->modify('+1 year');
            break;
        case 'monthly':
        default:
            $date->modify('+1 month');
            break;
    }
    return $date->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!has_permission('expenses', 'create')) {
        set_flash('danger', 'You do not have permission to manage recurring expenses.');
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'save_template') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amountRaw = trim($_POST['amount'] ?? '');
        $frequency = $_POST['frequency'] ?? 'monthly';
        $nextDue = trim($_POST['next_due_date'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';

        $errors = [];
        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Title is required (maximum 150 characters).';
        }
        if (!in_array($category, $categories, true)) {
            $errors[] = 'Choose a valid category.';
        }
        if ($description !== '' && mb_strlen($description) > 2000) {
            $errors[] = 'Description must be 2,000 characters or fewer.';
        }
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0 || (float) $amountRaw > 99999999.99) {
            $errors[] = 'Amount must be greater than zero and below 100,000,000.';
        }
        if (!isset($frequencies[$frequency])) {
            $errors[] = 'Choose a valid frequency.';
        }
        if (!is_valid_date_string($nextDue)) {
            $errors[] = 'Choose a valid next due date.';
        }
        if (!isset($methods[$paymentMethod])) {
            $errors[] = 'Choose a valid payment method.';
        }

        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: recurring.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        $payload = [$title, $category, $description ?: null, round((float) $amountRaw, 2), $frequency, $nextDue, $paymentMethod];
        try {
            if ($id > 0) {
                $check = $pdo->prepare('SELECT id FROM recurring_expenses WHERE id = ?');
                $check->execute([$id]);
                if (!$check->fetchColumn()) {
                    set_flash('danger', 'That recurring template no longer exists.');
                    header('Location: recurring.php');
                    exit;
                }
                $pdo->prepare("UPDATE recurring_expenses SET title = ?, category = ?, description = ?, amount = ?, frequency = ?, next_due_date = ?, payment_method = ? WHERE id = ?")
                    ->execute(array_merge($payload, [$id]));
                log_activity('update', 'expenses', $id, "Recurring expense updated: {$title}");
                set_flash('success', 'Recurring expense updated.');
            } else {
                $pdo->prepare("INSERT INTO recurring_expenses(title, category, description, amount, frequency, next_due_date, payment_method, created_by) VALUES(?,?,?,?,?,?,?,?)")
                    ->execute(array_merge($payload, [$_SESSION['user_id']]));
                $newId = (int) $pdo->lastInsertId();
                log_activity('create', 'expenses', $newId, "Recurring expense created: {$title}");
                set_flash('success', 'Recurring expense added.');
            }
        } catch (Throwable $e) {
            set_flash('danger', 'The recurring expense could not be saved.');
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT title, status FROM recurring_expenses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = ($row['status'] ?? 'active') === 'active' ? 'paused' : 'active';
            $pdo->prepare('UPDATE recurring_expenses SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            log_activity('update', 'expenses', $id, "Recurring expense {$newStatus}: {$row['title']}");
            set_flash('success', 'Recurring expense ' . ($newStatus === 'active' ? 'resumed' : 'paused') . '.');
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'delete_template') {
        if (!has_permission('expenses', 'delete')) {
            set_flash('danger', 'You do not have permission to delete recurring expenses.');
            header('Location: recurring.php');
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT title FROM recurring_expenses WHERE id = ?');
        $stmt->execute([$id]);
        $title = $stmt->fetchColumn();
        if ($title !== false) {
            $pdo->prepare('DELETE FROM recurring_expenses WHERE id = ?')->execute([$id]);
            log_activity('delete', 'expenses', $id, "Recurring expense deleted: {$title}");
            set_flash('success', 'Recurring expense deleted.');
        }
        header('Location: recurring.php');
        exit;
    }

    if ($action === 'generate') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM recurring_expenses WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $row = $lock->fetch();
            if (!$row) {
                throw new Exception('Recurring template not found.');
            }
            if (($row['status'] ?? 'active') !== 'active') {
                throw new Exception('This recurring expense is paused. Resume it before generating.');
            }
            if (!empty($row['last_generated_at']) && date('Y-m-d', strtotime($row['last_generated_at'])) === date('Y-m-d')) {
                throw new Exception('This template was already generated today. Adjust the next due date before generating again.');
            }

            $duplicate = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE reference_no = ? AND expense_date = ?");
            $duplicate->execute(['REC-' . $id, $row['next_due_date']]);
            if ((int) $duplicate->fetchColumn() > 0) {
                throw new Exception('An expense for this template and due date already exists.');
            }

            $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $roleStmt->execute([$_SESSION['user_id']]);
            $creatorRole = strtolower(trim((string) $roleStmt->fetchColumn()));
            $isAdmin = in_array($creatorRole, ['admin', 'administrator'], true);
            $approvalStatus = $isAdmin ? 'approved' : 'pending';

            $voucherNo = 'RECUR-' . date('YmdHis') . '-' . random_int(100, 999);
            $description = $row['description'] ?: $row['title'];
            $insert = $pdo->prepare("INSERT INTO expenses(voucher_no, expense_date, category, description, amount, payment_method, reference_no, notes, approval_status, approved_by, approved_at, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
            $insert->execute([
                $voucherNo,
                $row['next_due_date'],
                $row['category'],
                mb_substr($description, 0, 255),
                $row['amount'],
                $row['payment_method'],
                'REC-' . $id,
                'Generated from recurring expense: ' . $row['title'],
                $approvalStatus,
                $isAdmin ? $_SESSION['user_id'] : null,
                $isAdmin ? date('Y-m-d H:i:s') : null,
                $_SESSION['user_id'],
            ]);
            $expenseId = (int) $pdo->lastInsertId();

            if ($isAdmin) {
                post_expense_cashbook($voucherNo, $row['next_due_date'], $description, $row['amount'], $row['payment_method'], (int) $_SESSION['user_id']);
            } else {
                notify_admins(
                    "Recurring expense {$voucherNo} ({$row['title']}, " . currency_symbol() . money($row['amount']) . ") is awaiting approval.",
                    '/block_plant/expenses/approvals.php',
                    $_SESSION['user_id']
                );
            }

            $nextDate = recurring_add_frequency_date($row['next_due_date'], $row['frequency']);
            $pdo->prepare('UPDATE recurring_expenses SET next_due_date = ?, last_generated_at = NOW() WHERE id = ?')->execute([$nextDate, $id]);
            log_activity('create', 'expenses', $expenseId, "Recurring expense generated: {$row['title']} ({$voucherNo})");
            $pdo->commit();

            set_flash('success', $isAdmin
                ? "Generated {$voucherNo} and posted it to the cashbook. Next due " . format_date($nextDate) . '.'
                : "Generated {$voucherNo} and submitted it for approval. Next due " . format_date($nextDate) . '.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', $e->getMessage());
        }
        header('Location: recurring.php');
        exit;
    }

    header('Location: recurring.php');
    exit;
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM recurring_expenses WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

$rows = $pdo->query('SELECT * FROM recurring_expenses ORDER BY status ASC, next_due_date ASC, created_at DESC')->fetchAll();
$today = date('Y-m-d');
$activeCount = 0;
$dueCount = 0;
$pausedCount = 0;
$monthlyCommitment = 0.0;
foreach ($rows as $row) {
    if (($row['status'] ?? 'active') === 'active') {
        $activeCount++;
        if ($row['next_due_date'] <= $today) {
            $dueCount++;
        }
    } else {
        $pausedCount++;
    }
    $factor = ['weekly' => 52 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'yearly' => 1 / 12][$row['frequency']] ?? 1;
    $monthlyCommitment += (float) $row['amount'] * $factor;
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .rec-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .rec-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .rec-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .rec-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .rec-kpi strong { display:block; margin-top:4px; color:#1f2933; font-size:1.3rem; }
    .rec-kpi.due { border-left-color:#b45309; }
    .rec-form label { margin-top:12px; }
    .rec-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .rec-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .rec-actions form { margin:0; }
    .due-badge { font-size:.74rem; margin-left:6px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .rec-kpi, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🔁 Recurring Expenses</h1>
        <p style="color:#64748b;margin:6px 0 0;">Schedule regular plant costs such as rent, utilities, security, and wages.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="index.php">Back to expenses</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="rec-kpis">
    <div class="rec-kpi"><span>Active templates</span><strong><?= number_format($activeCount) ?></strong></div>
    <div class="rec-kpi due"><span>Due / overdue</span><strong><?= number_format($dueCount) ?></strong></div>
    <div class="rec-kpi"><span>Paused</span><strong><?= number_format($pausedCount) ?></strong></div>
    <div class="rec-kpi"><span>Monthly commitment</span><strong><?= e(currency_symbol()) . money($monthlyCommitment) ?></strong></div>
</div>

<?php if (has_permission('expenses', 'create')): ?>
<div class="panel rec-form no-print" style="margin-bottom:20px;">
    <h3><?= $edit ? 'Edit recurring expense' : 'Add recurring expense' ?></h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
        <div>
            <label>Title *</label>
            <input type="text" name="title" maxlength="150" required value="<?= e($edit['title'] ?? '') ?>" placeholder="e.g., Monthly rent">
        </div>
        <div>
            <label>Category *</label>
            <select name="category" required>
                <?php foreach ($categories as $category): ?>
                <option value="<?= e($category) ?>" <?= ($edit['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Amount (<?= e(currency_symbol()) ?>) *</label>
            <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" required value="<?= e($edit['amount'] ?? '') ?>">
        </div>
        <div>
            <label>Frequency *</label>
            <select name="frequency">
                <?php foreach ($frequencies as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($edit['frequency'] ?? 'monthly') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Next due date *</label>
            <input type="date" name="next_due_date" required value="<?= e($edit['next_due_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div>
            <label>Payment method</label>
            <select name="payment_method">
                <?php foreach ($methods as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($edit['payment_method'] ?? 'cash') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Description</label>
            <textarea name="description" rows="2" maxlength="2000"><?= e($edit['description'] ?? '') ?></textarea>
            <p class="hint">Used as the expense description when generated. Falls back to the title if empty.</p>
        </div>
        <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-accent"><?= $edit ? 'Save changes' : 'Save recurring expense' ?></button>
            <?php if ($edit): ?><a href="recurring.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <h3 style="margin:18px 0 10px;">Scheduled expenses (<?= number_format(count($rows)) ?>)</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Category</th>
                <th style="text-align:right;">Amount</th>
                <th>Frequency</th>
                <th>Next due</th>
                <th>Payment</th>
                <th>Last generated</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?><tr><td colspan="9">No recurring expense schedules yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <?php
                $isActive = ($row['status'] ?? 'active') === 'active';
                $days = (int) floor((strtotime($row['next_due_date']) - strtotime($today)) / 86400);
            ?>
            <tr>
                <td><strong><?= e($row['title']) ?></strong><?php if ($row['description']): ?><div style="font-size:.78rem;color:#64748b;margin-top:2px;"><?= e($row['description']) ?></div><?php endif; ?></td>
                <td><?= e($row['category']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['amount']) ?></td>
                <td><?= e($frequencies[$row['frequency']] ?? ucfirst($row['frequency'])) ?></td>
                <td style="white-space:nowrap;">
                    <?= e(format_date($row['next_due_date'])) ?>
                    <?php if ($isActive && $days < 0): ?><span class="badge badge-danger due-badge"><?= abs($days) ?>d overdue</span>
                    <?php elseif ($isActive && $days === 0): ?><span class="badge badge-warning due-badge">Due today</span>
                    <?php elseif ($isActive && $days <= 7): ?><span class="badge badge-warning due-badge">in <?= $days ?>d</span><?php endif; ?>
                </td>
                <td><?= e($methods[$row['payment_method']] ?? ucfirst($row['payment_method'])) ?></td>
                <td><?= e(!empty($row['last_generated_at']) ? format_datetime($row['last_generated_at']) : 'Never') ?></td>
                <td><span class="badge <?= $isActive ? 'badge-success' : 'badge-muted' ?>"><?= $isActive ? 'Active' : 'Paused' ?></span></td>
                <td class="no-print">
                    <?php if (has_permission('expenses', 'create')): ?>
                    <div class="rec-actions">
                        <?php if ($isActive): ?>
                        <form method="post" onsubmit="return confirm('Generate this recurring expense now?');">
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-accent">Generate now</button>
                        </form>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn-sm"><?= $isActive ? 'Pause' : 'Resume' ?></button>
                        </form>
                        <a href="recurring.php?edit=<?= (int) $row['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                        <?php if (has_permission('expenses', 'delete')): ?>
                        <form method="post" onsubmit="return confirm('Delete this recurring template? Past expenses are kept.');">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:#64748b;font-size:.82rem;">View only</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
