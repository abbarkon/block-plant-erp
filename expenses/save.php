<?php
session_start();
require_once "../config/database.php";
require_once __DIR__ . "/../config/helpers.php";
require_login();
ensure_notifications_schema();
ensure_expense_approval_schema();

$action = $_POST['action'] ?? 'create';

if ($action === 'delete') {
    require_permission('expenses', 'delete');

    $expenseId = (int) ($_POST['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        set_flash('danger', 'Select an expense to delete.');
        header('Location: index.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch();
        if (!$expense) {
            throw new RuntimeException('Expense not found.');
        }

        $voucherNo = (string) $expense['voucher_no'];
        $pdo->prepare("DELETE FROM cashbook WHERE transaction_type = 'expense' AND reference_no = ?")->execute([$voucherNo]);
        $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$expenseId]);

        $pdo->commit();
        log_activity('delete', 'expenses', $expenseId, "Expense {$voucherNo} deleted");
        set_flash('success', 'Expense voucher deleted.');
        header('Location: index.php');
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('danger', $error->getMessage());
        header('Location: index.php');
        exit;
    }
}

if ($action === 'update') {
    require_permission('expenses', 'edit');

    $expenseId = (int) ($_POST['expense_id'] ?? 0);
    if ($expenseId <= 0) {
        set_flash('danger', 'Select an expense to update.');
        header('Location: index.php');
        exit;
    }

    $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amountRaw = trim($_POST['amount'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $referenceNo = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (!is_valid_date_string($expenseDate)) {
        $errors[] = 'Enter a valid expense date.';
    } elseif ($expenseDate > date('Y-m-d')) {
        $errors[] = 'Expense date cannot be in the future.';
    }
    if ($category === '' || !in_array($category, expense_categories(), true)) {
        $errors[] = 'Choose a valid expense category.';
    }
    if ($description === '' || mb_strlen($description) > 255) {
        $errors[] = 'Description is required (maximum 255 characters).';
    }
    if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0 || (float) $amountRaw > 99999999.99) {
        $errors[] = 'Amount must be greater than zero and below 100,000,000.';
    }
    if (!isset(expense_payment_methods()[$paymentMethod])) {
        $errors[] = 'Choose a valid payment method.';
    }
    if (mb_strlen($referenceNo) > 80) {
        $errors[] = 'Reference number must be 80 characters or fewer.';
    }
    if (mb_strlen($notes) > 2000) {
        $errors[] = 'Notes must be 2,000 characters or fewer.';
    }

    if ($errors) {
        set_flash('danger', implode(' ', $errors));
        header('Location: edit.php?id=' . $expenseId);
        exit;
    }

    $amount = round((float) $amountRaw, 2);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch();
        if (!$expense) {
            throw new RuntimeException('Expense not found.');
        }

        $voucherNo = (string) $expense['voucher_no'];
        $pdo->prepare("UPDATE expenses SET expense_date = ?, category = ?, description = ?, amount = ?, payment_method = ?, reference_no = ?, notes = ? WHERE id = ?")
            ->execute([$expenseDate, $category, $description, $amount, $paymentMethod, $referenceNo ?: null, $notes ?: null, $expenseId]);

        $cashEntry = $pdo->prepare("SELECT id FROM cashbook WHERE transaction_type = 'expense' AND reference_no = ? LIMIT 1");
        $cashEntry->execute([$voucherNo]);
        $cashRow = $cashEntry->fetch();

        if ($cashRow) {
            $pdo->prepare("UPDATE cashbook SET transaction_date = ?, description = ?, amount = ?, payment_method = ? WHERE id = ?")
                ->execute([$expenseDate, mb_substr((string) $description, 0, 255), $amount, $paymentMethod, (int) $cashRow['id']]);
        } elseif ((string) ($expense['approval_status'] ?? '') === 'approved') {
            post_expense_cashbook($voucherNo, $expenseDate, $description, $amount, $paymentMethod, (int) ($_SESSION['user_id'] ?? 0));
        }

        $pdo->commit();
        log_activity('update', 'expenses', $expenseId, "Expense {$voucherNo} updated");
        set_flash('success', 'Expense voucher updated successfully.');
        header('Location: view.php?id=' . $expenseId);
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('danger', $error->getMessage());
        header('Location: edit.php?id=' . $expenseId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

require_permission('expenses', 'create');

$expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
$category = trim($_POST['category'] ?? '');
$description = trim($_POST['description'] ?? '');
$amountRaw = trim($_POST['amount'] ?? '');
$paymentMethod = $_POST['payment_method'] ?? 'cash';
$referenceNo = trim($_POST['reference_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$errors = [];
if (!is_valid_date_string($expenseDate)) {
    $errors[] = 'Enter a valid expense date.';
} elseif ($expenseDate > date('Y-m-d')) {
    $errors[] = 'Expense date cannot be in the future.';
}
if ($category === '' || !in_array($category, expense_categories(), true)) {
    $errors[] = 'Choose a valid expense category.';
}
if ($description === '' || mb_strlen($description) > 255) {
    $errors[] = 'Description is required (maximum 255 characters).';
}
if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0 || (float) $amountRaw > 99999999.99) {
    $errors[] = 'Amount must be greater than zero and below 100,000,000.';
}
if (!isset(expense_payment_methods()[$paymentMethod])) {
    $errors[] = 'Choose a valid payment method.';
}
if (mb_strlen($referenceNo) > 80) {
    $errors[] = 'Reference number must be 80 characters or fewer.';
}
if (mb_strlen($notes) > 2000) {
    $errors[] = 'Notes must be 2,000 characters or fewer.';
}

if ($errors) {
    $_SESSION['expense_form_old'] = [
        'expense_date' => $expenseDate,
        'category' => $category,
        'description' => $description,
        'amount' => $amountRaw,
        'payment_method' => $paymentMethod,
        'reference_no' => $referenceNo,
        'notes' => $notes,
    ];
    set_flash('danger', implode(' ', $errors));
    header('Location: create.php');
    exit;
}

$amount = round((float) $amountRaw, 2);

// Admins auto-approve their own expenses; everyone else needs manager sign-off.
$roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$roleStmt->execute([$_SESSION['user_id']]);
$creatorRole = strtolower(trim((string) $roleStmt->fetchColumn()));
$isCreatorAdmin = in_array($creatorRole, ['admin', 'administrator'], true);
$approvalStatus = $isCreatorAdmin ? 'approved' : 'pending';

try {
    $pdo->beginTransaction();
    $voucherNo = 'EXP-' . date('YmdHis') . '-' . random_int(100, 999);
    $expenseStmt = $pdo->prepare("INSERT INTO expenses(voucher_no, expense_date, category, description, amount, payment_method, reference_no, notes, approval_status, approved_by, approved_at, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $expenseStmt->execute([
        $voucherNo,
        $expenseDate,
        $category,
        $description,
        $amount,
        $paymentMethod,
        $referenceNo ?: null,
        $notes ?: null,
        $approvalStatus,
        $isCreatorAdmin ? $_SESSION['user_id'] : null,
        $isCreatorAdmin ? date('Y-m-d H:i:s') : null,
        $_SESSION['user_id'],
    ]);
    $expenseId = (int) $pdo->lastInsertId();

    if ($isCreatorAdmin) {
        post_expense_cashbook($voucherNo, $expenseDate, $description, $amount, $paymentMethod, (int) $_SESSION['user_id']);
    } else {
        $msg = "New expense {$voucherNo} ({$category}, " . currency_symbol() . money($amount) . ") is awaiting approval.";
        notify_admins($msg, '/block_plant/expenses/approvals.php', $_SESSION['user_id']);
    }

    log_activity('create', 'expenses', $expenseId, "Expense {$voucherNo} recorded: {$category} " . currency_symbol() . money($amount));
    $pdo->commit();

    if ($isCreatorAdmin) {
        set_flash('success', "Expense voucher {$voucherNo} was saved and posted to the cashbook.");
    } else {
        set_flash('success', "Expense voucher {$voucherNo} was submitted for approval.");
    }
    unset($_SESSION['expense_form_old']);
    header('Location: ' . (!empty($_POST['add_another']) ? 'create.php' : 'index.php'));
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('danger', 'The expense could not be saved. Please try again.');
    header('Location: create.php');
    exit;
}
