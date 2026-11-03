<?php
session_start();
require_once "../config/database.php";
require_once __DIR__ . "/../config/helpers.php";
require_login();
require_permission('expenses', 'create');
ensure_notifications_schema();
ensure_expense_approval_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
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
