<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();

$canRecordRepayment = has_permission('hr', 'edit') || has_permission('finance', 'create');
$canViewLoans = has_permission('hr', 'view') || has_permission('finance', 'view') || $canRecordRepayment;
if (!$canViewLoans) {
    http_response_code(403);
    exit('You do not have permission to view loan repayments.');
}

ensure_loan_repayment_schema();

$employeeId = max(0, (int) ($_GET['employee_id'] ?? 0));
$paymentMethods = expense_payment_methods();
$paymentMethods['cheque'] = 'Cheque';
$paymentMethods['mobile_money'] = 'Mobile money';

// One-use token: protects the financial POST from CSRF and double submission.
$loanRepaymentToken = (string) ($_SESSION['loan_repayment_token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $loanRepaymentToken === '') {
    $loanRepaymentToken = bin2hex(random_bytes(16));
    $_SESSION['loan_repayment_token'] = $loanRepaymentToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_repayment'])) {
    if (!$canRecordRepayment) {
        http_response_code(403);
        exit('You do not have permission to record loan repayments.');
    }

    $postedToken = (string) ($_POST['loan_repayment_token'] ?? '');
    if ($postedToken === '' || $loanRepaymentToken === '' || !hash_equals($loanRepaymentToken, $postedToken)) {
        $failedEmployeeId = (int) ($_POST['employee_id'] ?? $employeeId);
        set_flash('error', 'This repayment form has expired or was already submitted. Please review the balance and submit again.');
        header('Location: loan_repayments.php' . ($failedEmployeeId > 0 ? '?employee_id=' . $failedEmployeeId : ''));
        exit;
    }
    unset($_SESSION['loan_repayment_token']);

    $loanId = (int) ($_POST['loan_id'] ?? 0);
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
    $redirectEmployeeId = (int) ($_POST['employee_id'] ?? $employeeId);
    $errors = [];

    $loan = null;
    if ($loanId <= 0) {
        $errors[] = 'Select an active loan.';
    } else {
        $loanStmt = $pdo->prepare(
            "SELECT el.id, el.employee_id, el.loan_amount, el.monthly_payment, el.remaining_balance,
                    el.approval_status, e.name AS employee_name
             FROM employee_loans el
             JOIN employees e ON e.id = el.employee_id
             WHERE el.id = ?"
        );
        $loanStmt->execute([$loanId]);
        $loan = $loanStmt->fetch();
        if (!$loan) {
            $errors[] = 'The selected loan was not found.';
        } elseif ($loan['approval_status'] !== 'approved') {
            $errors[] = 'Only approved loans can receive repayments.';
        } elseif ((float) $loan['remaining_balance'] <= 0.005) {
            $errors[] = 'That loan has already been fully repaid.';
        } else {
            $redirectEmployeeId = (int) $loan['employee_id'];
            if ($amount <= 0) {
                $errors[] = 'Enter a repayment amount greater than zero.';
            } elseif ($amount > (float) $loan['remaining_balance'] + 0.005) {
                $errors[] = 'Repayment cannot exceed the remaining balance (' . currency_symbol() . number_format((float) $loan['remaining_balance'], 2) . ').';
            }
        }
    }

    if (!is_valid_date_string($paymentDate) || $paymentDate > date('Y-m-d')) {
        $errors[] = 'Enter a valid payment date that is not in the future.';
    }
    if (!array_key_exists($paymentMethod, $paymentMethods)) {
        $errors[] = 'Select a valid payment method.';
    }
    if (mb_strlen($referenceNo) > 100) {
        $errors[] = 'Receipt / reference number must be 100 characters or fewer.';
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        header('Location: loan_repayments.php' . ($redirectEmployeeId > 0 ? '?employee_id=' . $redirectEmployeeId : ''));
        exit;
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare(
            "SELECT el.id, el.employee_id, el.remaining_balance, e.name AS employee_name
             FROM employee_loans el
             JOIN employees e ON e.id = el.employee_id
             WHERE el.id = ? AND el.approval_status = 'approved'
             FOR UPDATE"
        );
        $lockStmt->execute([$loanId]);
        $lockedLoan = $lockStmt->fetch();
        if (!$lockedLoan) {
            throw new RuntimeException('That loan is no longer active.');
        }

        $remaining = round((float) $lockedLoan['remaining_balance'], 2);
        if ($remaining <= 0.005) {
            throw new RuntimeException('That loan has already been fully repaid.');
        }
        if ($amount > $remaining + 0.005) {
            throw new RuntimeException('Repayment cannot exceed the remaining balance (' . currency_symbol() . number_format($remaining, 2) . ').');
        }

        $amount = min(round($amount, 2), $remaining);
        $isFullSettlement = $amount >= $remaining - 0.005;
        $newBalance = max(0.0, round($remaining - $amount, 2));

        $insert = $pdo->prepare(
            "INSERT INTO loan_repayments
                (loan_id, payroll_month, payroll_year, amount_deducted, deduction_date, source, reference_no, payment_method, notes)
             VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, ?)"
        );
        $insert->execute([
            $loanId,
            (int) date('n', strtotime($paymentDate)),
            (int) date('Y', strtotime($paymentDate)),
            $amount,
            $paymentDate,
            $referenceNo !== '' ? $referenceNo : null,
            $paymentMethod,
            $isFullSettlement ? 'Full settlement' : 'Partial payment',
        ]);
        $repaymentId = (int) $pdo->lastInsertId();

        $pdo->prepare("UPDATE employee_loans SET remaining_balance = ? WHERE id = ?")->execute([$newBalance, $loanId]);

        // Keep the cashbook in step with the loan subledger. The cashbook
        // reference column is shorter than the loan receipt field.
        $cashbookReference = $referenceNo !== '' ? mb_substr($referenceNo, 0, 60) : 'LOAN-REPAY-' . $repaymentId;
        $pdo->prepare(
            "INSERT INTO cashbook
                (transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by)
             VALUES (?, 'receipt', ?, ?, ?, ?, ?)"
        )->execute([
            $paymentDate,
            $cashbookReference,
            'Employee loan repayment - ' . $lockedLoan['employee_name']
                . ($isFullSettlement ? ' (full settlement)' : ' (partial payment)'),
            $amount,
            $paymentMethod,
            (int) $_SESSION['user_id'],
        ]);

        // Debit cash and reduce the employee-loan receivable in the ledger.
        $payrollAccounts = ensure_payroll_journal_accounts();
        post_accounting_journal(
            'LOAN-REPAY-' . $repaymentId,
            $paymentDate,
            'Employee loan repayment - ' . $lockedLoan['employee_name'],
            $cashbookReference,
            [
                ['1110', $amount, 0, 'Cash received for employee loan repayment'],
                [$payrollAccounts['loan'], 0, $amount, 'Employee loan receivable reduced'],
            ],
            (int) $_SESSION['user_id']
        );

        log_activity(
            'update',
            'hr',
            $loanId,
            'Manual loan repayment of ' . number_format($amount, 2) . ' recorded'
                . ($isFullSettlement ? ' (full settlement)' : ' (partial payment)')
        );

        $pdo->commit();
        $redirectEmployeeId = (int) $lockedLoan['employee_id'];
        set_flash(
            'success',
            'Loan repayment of ' . currency_symbol() . number_format($amount, 2) . ' recorded'
                . ($isFullSettlement
                    ? ' — the loan is fully settled.'
                    : '. Remaining balance: ' . currency_symbol() . number_format($newBalance, 2) . '.')
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Could not record the loan repayment: ' . $e->getMessage());
    }

    header('Location: loan_repayments.php' . ($redirectEmployeeId > 0 ? '?employee_id=' . $redirectEmployeeId : ''));
    exit;
}

$activeLoanQuery = "SELECT el.id, el.employee_id, el.loan_amount, el.monthly_payment, el.remaining_balance,
                           el.start_deduction_date, el.end_deduction_date,
                           e.name AS employee_name, e.employee_code
                    FROM employee_loans el
                    JOIN employees e ON e.id = el.employee_id
                    WHERE el.approval_status = 'approved' AND el.remaining_balance > 0.005";
$activeLoanParams = [];
if ($employeeId > 0) {
    $activeLoanQuery .= " AND el.employee_id = ?";
    $activeLoanParams[] = $employeeId;
}
$activeLoanQuery .= " ORDER BY e.name, el.id";
$activeLoanStmt = $pdo->prepare($activeLoanQuery);
$activeLoanStmt->execute($activeLoanParams);
$activeLoans = $activeLoanStmt->fetchAll();

// Get loan repayment history
if ($employeeId > 0) {
    $stmt = $pdo->prepare("SELECT lr.*, el.loan_amount, el.monthly_payment, e.name as employee_name, e.employee_code FROM loan_repayments lr JOIN employee_loans el ON lr.loan_id = el.id JOIN employees e ON el.employee_id = e.id WHERE el.employee_id=? ORDER BY lr.deduction_date DESC, lr.id DESC");
    $stmt->execute([$employeeId]);
    $repayments = $stmt->fetchAll();
} else {
    $repayments = $pdo->query("SELECT lr.*, el.loan_amount, el.monthly_payment, e.name as employee_name, e.employee_code FROM loan_repayments lr JOIN employee_loans el ON lr.loan_id = el.id JOIN employees e ON el.employee_id = e.id ORDER BY lr.deduction_date DESC, lr.id DESC LIMIT 100")->fetchAll();
}

$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();
$flash = get_flash();
$pageTitle = 'Loan Repayment History';
require_once "../partials/header.php";
?>

<h1>📊 Loan Repayment History</h1>

<?php if ($flash): ?>
<div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($canRecordRepayment): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3 style="margin-top:0;">Record Loan Repayment</h3>
    <p style="color:#64748b;margin:-4px 0 16px;">Record an early repayment, a partial payment, or settle the full remaining balance. This updates the loan immediately and stops future payroll deductions when the balance reaches zero.</p>

    <?php if (!$activeLoans): ?>
    <div style="padding:14px 16px;border-left:4px solid #0f766e;background:#f0fdfa;color:#115e59;">
        No active approved loans with a remaining balance<?= $employeeId > 0 ? ' for the selected employee' : '' ?>.
    </div>
    <?php else: ?>
    <form method="post" id="loan-repayment-form" style="display:grid;gap:14px;">
        <input type="hidden" name="record_repayment" value="1">
        <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
        <input type="hidden" name="loan_repayment_token" value="<?= e($loanRepaymentToken) ?>">

        <div>
            <label for="loan_id">Select Active Loan *</label>
            <select name="loan_id" id="loan_id" required>
                <option value="">— Select an active loan —</option>
                <?php foreach ($activeLoans as $loan): ?>
                <option
                    value="<?= (int) $loan['id'] ?>"
                    data-employee="<?= e($loan['employee_name'] . ' (' . $loan['employee_code'] . ')') ?>"
                    data-remaining="<?= number_format((float) $loan['remaining_balance'], 2, '.', '') ?>"
                    data-monthly="<?= number_format((float) $loan['monthly_payment'], 2, '.', '') ?>"
                >
                    <?= e($loan['employee_name']) ?> (<?= e($loan['employee_code']) ?>) —
                    <?= e(currency_symbol()) ?><?= number_format((float) $loan['remaining_balance'], 2) ?> remaining
                </option>
                <?php endforeach; ?>
            </select>
            <small id="loan-summary" style="display:block;margin-top:6px;color:#0f766e;font-weight:600;"></small>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;">
            <div>
                <label for="amount">Repayment Amount *</label>
                <input type="number" name="amount" id="amount" min="0.01" step="0.01" required>
                <button type="button" id="use-full-balance" class="btn btn-secondary" style="margin-top:8px;padding:8px 12px;">Use full balance</button>
            </div>
            <div>
                <label for="payment_date">Payment Date *</label>
                <input type="date" name="payment_date" id="payment_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div>
                <label for="payment_method">Payment Method *</label>
                <select name="payment_method" id="payment_method" required>
                    <option value="">Select method</option>
                    <?php foreach ($paymentMethods as $methodKey => $methodLabel): ?>
                    <option value="<?= e($methodKey) ?>"><?= e($methodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="reference_no">Receipt / Reference No.</label>
                <input type="text" name="reference_no" id="reference_no" maxlength="100" placeholder="e.g. bank slip or receipt number">
            </div>
        </div>

        <div>
            <button type="submit" class="btn">Record Repayment</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filter Form -->
<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <label>Filter by Employee:</label>
    <select name="employee_id" onchange="this.form.submit()">
        <option value="">All Employees</option>
        <?php foreach($employees as $emp): ?>
        <option value="<?= (int) $emp['id'] ?>" <?= $employeeId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
        <?php endforeach; ?>
    </select>
</form>

<!-- Repayment History -->
<div class="panel">
    <h3>Loan Repayment Records</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Loan Amount</th>
                    <th>Monthly Payment</th>
                    <th>Amount Paid</th>
                    <th>Type / Period</th>
                    <th>Payment Method</th>
                    <th>Receipt Reference</th>
                    <th>Deduction Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$repayments): ?>
                <tr><td colspan="8">No repayment records found.</td></tr>
                <?php endif; ?>
                <?php foreach($repayments as $repayment): ?>
                <?php
                    $isManual = ($repayment['source'] ?? 'payroll') === 'manual';
                    if ($isManual) {
                        $typeLabel = !empty($repayment['notes']) ? $repayment['notes'] : 'Manual repayment';
                    } else {
                        $typeLabel = 'Payroll - ' . date('F Y', mktime(0, 0, 0, (int) $repayment['payroll_month'], 1, (int) $repayment['payroll_year']));
                    }
                    $methodKey = (string) ($repayment['payment_method'] ?? '');
                    $methodLabel = $paymentMethods[$methodKey] ?? ($methodKey !== '' ? ucwords(str_replace('_', ' ', $methodKey)) : 'Payroll deduction');
                ?>
                <tr>
                    <td><?= e($repayment['employee_name']) ?> (<?= e($repayment['employee_code']) ?>)</td>
                    <td><?= e(currency_symbol()) . number_format((float) $repayment['loan_amount'], 2) ?></td>
                    <td><?= e(currency_symbol()) . number_format((float) $repayment['monthly_payment'], 2) ?></td>
                    <td><strong><?= e(currency_symbol()) . number_format((float) $repayment['amount_deducted'], 2) ?></strong></td>
                    <td><?= e($typeLabel) ?></td>
                    <td><?= e($methodLabel) ?></td>
                    <td><?= e($repayment['reference_no'] ?: '-') ?></td>
                    <td><?= e(format_date($repayment['deduction_date'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var loanSelect = document.getElementById('loan_id');
    var amountInput = document.getElementById('amount');
    var summary = document.getElementById('loan-summary');
    var fullButton = document.getElementById('use-full-balance');
    if (!loanSelect || !amountInput || !summary) return;

    var currency = <?= json_encode(currency_symbol(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function selectedLoan() {
        var option = loanSelect.options[loanSelect.selectedIndex];
        if (!option || !option.value) return null;
        return {
            employee: option.getAttribute('data-employee') || '',
            remaining: parseFloat(option.getAttribute('data-remaining') || '0')
        };
    }

    function updateSummary() {
        var loan = selectedLoan();
        if (!loan) {
            summary.textContent = '';
            amountInput.removeAttribute('max');
            return;
        }
        summary.textContent = loan.employee + ' — remaining balance: ' + currency + loan.remaining.toFixed(2);
        amountInput.max = loan.remaining.toFixed(2);
    }

    loanSelect.addEventListener('change', updateSummary);
    if (fullButton) {
        fullButton.addEventListener('click', function () {
            var loan = selectedLoan();
            if (loan && loan.remaining > 0) {
                amountInput.value = loan.remaining.toFixed(2);
                amountInput.focus();
            }
        });
    }
    updateSummary();

    var repaymentForm = document.getElementById('loan-repayment-form');
    if (repaymentForm) {
        repaymentForm.addEventListener('submit', function () {
            var submitButton = repaymentForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Recording…';
            }
        });
    }
})();
</script>

<?php require_once "../partials/footer.php"; ?>
