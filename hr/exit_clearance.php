<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

ensure_employee_exit_clearance_schema();

if ($pdo->query("SHOW TABLES LIKE 'employee_loans'")->fetchColumn()) {
    ensure_loan_repayment_schema();
}

$pageTitle = 'Employee Exit Clearance';
$error = '';
$success = '';

$getOutstanding = function (int $employeeId) use ($pdo): array {
    $loans = [];
    $loanTableExists = $pdo->query("SHOW TABLES LIKE 'employee_loans'")->fetchColumn();
    if ($loanTableExists) {
        $loanStmt = $pdo->prepare("SELECT id, purpose, remaining_balance FROM employee_loans WHERE employee_id = ? AND approval_status = 'approved' AND remaining_balance > 0.009 ORDER BY request_date");
        $loanStmt->execute([$employeeId]);
        $loans = $loanStmt->fetchAll();
    }

    $payrollStmt = $pdo->prepare("SELECT p.id, p.month, p.year, p.net_salary, p.payment_status, e.date_of_joining FROM payroll p JOIN employees e ON e.id = p.employee_id WHERE p.employee_id = ? AND LOWER(COALESCE(p.payment_status, 'pending')) <> 'paid' AND p.net_salary > 0 ORDER BY p.year, p.month");
    $payrollStmt->execute([$employeeId]);
    $unpaidPayrollRaw = $payrollStmt->fetchAll();
    
    // Filter out payroll periods where employee wasn't employed
    $unpaidPayroll = [];
    foreach($unpaidPayrollRaw as $pay) {
        $skipPayroll = false;
        if(!empty($pay['date_of_joining'])) {
            $joinDate = strtotime($pay['date_of_joining']);
            $periodEndDate = date('Y-m-t', mktime(0, 0, 0, $pay['month'], 1, $pay['year']));
            if($joinDate > strtotime($periodEndDate)) {
                $skipPayroll = true;
            }
        }
        if(!$skipPayroll) {
            $unpaidPayroll[] = $pay;
        }
    }

    // Check for outstanding assets
    $assets = [];
    $assetTableExists = $pdo->query("SHOW TABLES LIKE 'employee_assets'")->fetchColumn();
    if ($assetTableExists) {
        $assetStmt = $pdo->prepare("SELECT id, asset_name, asset_type, serial_number, issue_date FROM employee_assets WHERE employee_id = ? AND status = 'issued' ORDER BY issue_date");
        $assetStmt->execute([$employeeId]);
        $assets = $assetStmt->fetchAll();
    }

    return ['loans' => $loans, 'payroll' => $unpaidPayroll, 'assets' => $assets];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'settle_loan') {
        require_permission('finance', 'edit');
    } elseif ($action === 'request_exit') {
        require_permission('hr', 'create');
    } elseif ($action === 'approve_exit' || $action === 'complete_exit') {
        require_permission('hr', 'approve');
    } else {
        require_permission('hr', 'edit');
    }

    try {
        if ($action === 'request_exit') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $lastWorkingDay = trim($_POST['last_working_day'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $parsedDate = DateTime::createFromFormat('Y-m-d', $lastWorkingDay);

            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $lastWorkingDay || $reason === '') {
                throw new InvalidArgumentException('Enter a valid last working day and a reason for departure.');
            }

            $employeeStmt = $pdo->prepare("SELECT id, status FROM employees WHERE id = ?");
            $employeeStmt->execute([$employeeId]);
            $employee = $employeeStmt->fetch();
            if (!$employee || (int) $employee['status'] !== 1) {
                throw new InvalidArgumentException('Only active employees can begin exit clearance.');
            }

            $pendingStmt = $pdo->prepare("SELECT id FROM employee_exit_clearances WHERE employee_id = ? AND status IN ('pending', 'approved') LIMIT 1");
            $pendingStmt->execute([$employeeId]);
            if ($pendingStmt->fetchColumn()) {
                throw new InvalidArgumentException('This employee already has a pending exit clearance.');
            }

            $insert = $pdo->prepare("INSERT INTO employee_exit_clearances (employee_id, last_working_day, reason, requested_by) VALUES (?, ?, ?, ?)");
            $insert->execute([$employeeId, $lastWorkingDay, $reason, (int) $_SESSION['user_id']]);
            $success = 'Exit clearance request created. The employee remains active until clearance is complete.';
        } elseif ($action === 'settle_loan') {
            $clearanceId = (int) ($_POST['clearance_id'] ?? 0);
            $loanId = (int) ($_POST['loan_id'] ?? 0);
            $reference = trim($_POST['reference_no'] ?? '');
            $paymentMethod = $_POST['payment_method'] ?? '';
            $paymentMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

            if ($reference === '' || !in_array($paymentMethod, $paymentMethods, true)) {
                throw new InvalidArgumentException('Enter a receipt reference and select a valid payment method.');
            }

            $pdo->beginTransaction();
            $clearanceStmt = $pdo->prepare("SELECT c.employee_id, e.name AS employee_name FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.id = ? AND c.status IN ('pending', 'approved') FOR UPDATE");
            $clearanceStmt->execute([$clearanceId]);
            $clearance = $clearanceStmt->fetch();
            if (!$clearance) {
                throw new InvalidArgumentException('The pending exit clearance was not found.');
            }

            $loanStmt = $pdo->prepare("SELECT employee_id, approval_status, remaining_balance FROM employee_loans WHERE id = ? FOR UPDATE");
            $loanStmt->execute([$loanId]);
            $loan = $loanStmt->fetch();
            if (!$loan || (int) $loan['employee_id'] !== (int) $clearance['employee_id'] || $loan['approval_status'] !== 'approved') {
                throw new InvalidArgumentException('This approved loan does not belong to the employee on the pending clearance.');
            }

            $amount = round((float) $loan['remaining_balance'], 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('This loan has no outstanding balance to settle.');
            }

            $cashbookStmt = $pdo->prepare("INSERT INTO cashbook (transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES (CURDATE(), 'receipt', ?, ?, ?, ?, ?)");
            $cashbookStmt->execute([
                $reference,
                'Full employee loan settlement - ' . $clearance['employee_name'],
                $amount,
                $paymentMethod,
                (int) $_SESSION['user_id']
            ]);

            $repaymentStmt = $pdo->prepare("INSERT INTO loan_repayments (loan_id, payroll_month, payroll_year, amount_deducted, deduction_date, source, reference_no, payment_method) VALUES (?, ?, ?, ?, CURDATE(), 'manual', ?, ?)");
            $repaymentStmt->execute([$loanId, (int) date('n'), (int) date('Y'), $amount, $reference, $paymentMethod]);

            $pdo->prepare("UPDATE employee_loans SET remaining_balance = 0 WHERE id = ?")->execute([$loanId]);
            $pdo->commit();
            $success = 'Full loan payoff recorded as a cashbook receipt. Exit clearance has been refreshed.';
        } elseif ($action === 'approve_exit') {
            $clearanceId = (int) ($_POST['clearance_id'] ?? 0);
            $pdo->beginTransaction();
            $clearanceStmt = $pdo->prepare("SELECT c.employee_id, e.status AS employee_status FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.id = ? AND c.status = 'pending' FOR UPDATE");
            $clearanceStmt->execute([$clearanceId]);
            $clearance = $clearanceStmt->fetch();
            if (!$clearance || (int) $clearance['employee_status'] !== 1) {
                throw new InvalidArgumentException('Pending exit request was not found for an active employee.');
            }

            $outstanding = $getOutstanding((int) $clearance['employee_id']);
            if ($outstanding['loans'] || $outstanding['payroll'] || $outstanding['assets']) {
                $items = [];
                if($outstanding['loans']) $items[] = count($outstanding['loans']) . ' outstanding loan(s)';
                if($outstanding['payroll']) $items[] = count($outstanding['payroll']) . ' unpaid payroll period(s)';
                if($outstanding['assets']) $items[] = count($outstanding['assets']) . ' unreturned asset(s)';
                $itemsStr = implode(', ', $items);
                throw new InvalidArgumentException("Please clear the following before approving this exit: $itemsStr");
            }

            $approve = $pdo->prepare("UPDATE employee_exit_clearances SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
            $approve->execute([(int) $_SESSION['user_id'], $clearanceId]);
            if ($approve->rowCount() !== 1) {
                throw new InvalidArgumentException('Exit request could not be approved. Refresh and try again.');
            }
            $pdo->commit();
            $success = 'Exit approved. The employee remains active until the last working day.';
        } elseif ($action === 'complete_exit') {
            $clearanceId = (int) ($_POST['clearance_id'] ?? 0);
            $clearanceStmt = $pdo->prepare("SELECT c.*, e.status AS employee_status FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.id = ? AND c.status = 'approved'");
            $clearanceStmt->execute([$clearanceId]);
            $clearance = $clearanceStmt->fetch();

            if (!$clearance || (int) $clearance['employee_status'] !== 1) {
                throw new InvalidArgumentException('Approved exit was not found for an active employee.');
            }
            if ($clearance['last_working_day'] > date('Y-m-d')) {
                throw new InvalidArgumentException('Exit clearance can only be finalized on or after the last working day.');
            }

            $outstanding = $getOutstanding((int) $clearance['employee_id']);
            if ($outstanding['loans'] || $outstanding['payroll'] || $outstanding['assets']) {
                $items = [];
                if($outstanding['loans']) $items[] = count($outstanding['loans']) . ' outstanding loan(s)';
                if($outstanding['payroll']) $items[] = count($outstanding['payroll']) . ' unpaid payroll period(s)';
                if($outstanding['assets']) $items[] = count($outstanding['assets']) . ' unreturned asset(s)';
                $itemsStr = implode(', ', $items);
                throw new InvalidArgumentException("Please clear the following before finalizing this exit: $itemsStr");
            }

            $pdo->beginTransaction();
            $deactivate = $pdo->prepare("UPDATE employees SET status = 0 WHERE id = ? AND status = 1");
            $deactivate->execute([(int) $clearance['employee_id']]);
            if ($deactivate->rowCount() !== 1) {
                throw new InvalidArgumentException('Employee status changed before exit could be finalized.');
            }
            $complete = $pdo->prepare("UPDATE employee_exit_clearances SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ? AND status = 'approved'");
            $complete->execute([(int) $_SESSION['user_id'], $clearanceId]);
            if ($complete->rowCount() !== 1) {
                throw new InvalidArgumentException('Approved exit changed before it could be finalized.');
            }
            $pdo->commit();
            $success = 'Exit clearance completed. The employee is now inactive.';
        } elseif ($action === 'cancel_exit') {
            $clearanceId = (int) ($_POST['clearance_id'] ?? 0);
            $cancel = $pdo->prepare("UPDATE employee_exit_clearances SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'approved')");
            $cancel->execute([$clearanceId]);
            if ($cancel->rowCount() !== 1) {
                throw new InvalidArgumentException('Pending exit clearance was not found.');
            }
            $success = 'Exit clearance request cancelled.';
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Unable to save the exit clearance. Please try again or contact an administrator.';
    }
}

$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$selectedClearanceId = (int) ($_GET['clearance_id'] ?? 0);
$selectedEmployee = null;
if ($selectedEmployeeId > 0) {
    $selectedEmployeeStmt = $pdo->prepare("SELECT id, employee_code, name, status FROM employees WHERE id = ?");
    $selectedEmployeeStmt->execute([$selectedEmployeeId]);
    $selectedEmployee = $selectedEmployeeStmt->fetch() ?: null;
}

$pendingClearances = $pdo->query("SELECT c.*, e.name AS employee_name, e.employee_code FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.status IN ('pending', 'approved') ORDER BY c.last_working_day, e.name")->fetchAll();
$history = $pdo->query("SELECT c.*, e.name AS employee_name, e.employee_code FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.status NOT IN ('pending', 'approved') ORDER BY c.requested_at DESC LIMIT 25")->fetchAll();

$selectedClearance = null;
if ($selectedClearanceId > 0) {
    $selectedClearanceStmt = $pdo->prepare("SELECT c.*, e.name AS employee_name, e.employee_code FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.id = ? AND c.status IN ('pending', 'approved')");
    $selectedClearanceStmt->execute([$selectedClearanceId]);
    $selectedClearance = $selectedClearanceStmt->fetch() ?: null;
} elseif ($selectedEmployeeId > 0) {
    $selectedClearanceStmt = $pdo->prepare("SELECT c.*, e.name AS employee_name, e.employee_code FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id WHERE c.employee_id = ? AND c.status IN ('pending', 'approved') LIMIT 1");
    $selectedClearanceStmt->execute([$selectedEmployeeId]);
    $selectedClearance = $selectedClearanceStmt->fetch() ?: null;
}

$outstanding = $selectedClearance ? $getOutstanding((int) $selectedClearance['employee_id']) : ['loans' => [], 'payroll' => []];
$activeEmployees = $pdo->query("SELECT e.id, e.name, e.employee_code FROM employees e WHERE e.status = 1 AND NOT EXISTS (SELECT 1 FROM employee_exit_clearances c WHERE c.employee_id = e.id AND c.status IN ('pending', 'approved')) ORDER BY e.name")->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Employee Exit Clearance</h1>
    <a href="employees.php" class="btn btn-secondary">Back to employees</a>
</div>

<?php if ($error !== ''): ?>
<div class="panel" role="alert" style="border-left:4px solid #dc3545;color:#842029;"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="panel" role="status" style="border-left:4px solid #198754;color:#146c43;"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($selectedClearance): ?>
<?php
$hasOutstanding = !empty($outstanding['loans']) || !empty($outstanding['payroll']) || !empty($outstanding['assets']);
$holdReasons = [];
if(!empty($outstanding['loans'])) $holdReasons[] = count($outstanding['loans']) . ' loan(s)';
if(!empty($outstanding['payroll'])) $holdReasons[] = count($outstanding['payroll']) . ' payroll period(s)';
if(!empty($outstanding['assets'])) $holdReasons[] = count($outstanding['assets']) . ' asset(s)';
$holdReason = $hasOutstanding ? 'On hold: ' . implode(', ', $holdReasons) : '';
$clearanceStatusLabel = $hasOutstanding ? $holdReason : ($selectedClearance['status'] === 'approved' ? 'Exit approved' : 'Awaiting HR approval');
?>
<section class="panel">
    <div class="exit-heading">
        <div>
            <h2><?= e($selectedClearance['employee_name']) ?> <span class="exit-code"><?= e($selectedClearance['employee_code']) ?></span></h2>
            <p>Last working day: <strong><?= e(date('d M Y', strtotime($selectedClearance['last_working_day']))) ?></strong></p>
        </div>
        <a href="exit_clearance_print.php?id=<?= (int) $selectedClearance['id'] ?>" class="btn btn-secondary" target="_blank">Print clearance form</a>
        <span class="badge <?= $hasOutstanding ? 'badge-warning' : ($selectedClearance['status'] === 'approved' ? 'badge-success' : 'badge-warning') ?>"><?= e($clearanceStatusLabel) ?></span>
    </div>
    <p><?= nl2br(e($selectedClearance['reason'])) ?></p>

    <?php if ($hasOutstanding): ?>
    <div class="exit-holds">
        <?php if ($outstanding['loans']): ?>
        <div>
            <h3>Outstanding employee loans</h3>
            <table class="data-table">
                <thead><tr><th>Purpose</th><th>Remaining balance</th></tr></thead>
                <tbody>
                <?php foreach ($outstanding['loans'] as $loan): ?>
                    <tr><td><?= e($loan['purpose'] ?: 'Employee loan') ?></td><td><?= e(currency_symbol()) . number_format((float) $loan['remaining_balance'], 2) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <a href="loans.php" class="btn btn-secondary">Review loans</a>
            <?php if (has_permission('finance', 'edit')): ?>
            <?php foreach ($outstanding['loans'] as $loan): ?>
            <form method="post" class="loan-payoff-form" onsubmit="return confirm('Confirm receipt of the full <?= e(currency_symbol()) . number_format((float) $loan['remaining_balance'], 2) ?> loan balance?')">
                <input type="hidden" name="action" value="settle_loan">
                <input type="hidden" name="clearance_id" value="<?= (int) $selectedClearance['id'] ?>">
                <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                <label>Receipt reference
                    <input type="text" name="reference_no" maxlength="100" required>
                </label>
                <label>Payment method
                    <select name="payment_method" required>
                        <option value="">Select method</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="mobile_money">Mobile money</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Record full payoff</button>
                <small>The full current balance is recorded; this does not accept partial payments.</small>
            </form>
            <?php endforeach; ?>
            <?php else: ?>
            <p class="exit-note">A finance user with edit permission must record the received payoff.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($outstanding['payroll']): ?>
        <div>
            <h3>Unpaid payroll</h3>
            <table class="data-table">
                <thead><tr><th>Period</th><th>Status</th><th>Net pay</th></tr></thead>
                <tbody>
                <?php foreach ($outstanding['payroll'] as $payroll): ?>
                    <tr>
                        <td><?= e(date('F Y', mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year']))) ?></td>
                        <td><?= e(ucfirst($payroll['payment_status'] ?: 'pending')) ?></td>
                        <td><?= e(currency_symbol()) . number_format((float) $payroll['net_salary'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <a href="payroll.php" class="btn btn-secondary">Review payroll</a>
        </div>
        <?php endif; ?>

        <?php if ($outstanding['assets']): ?>
        <div>
            <h3>Outstanding assets</h3>
            <table class="data-table">
                <thead><tr><th>Asset Name</th><th>Type</th><th>Serial Number</th><th>Issue Date</th></tr></thead>
                <tbody>
                <?php foreach ($outstanding['assets'] as $asset): ?>
                    <tr>
                        <td><?= e($asset['asset_name']) ?></td>
                        <td><?= e($asset['asset_type']) ?></td>
                        <td><?= e($asset['serial_number'] ?: '-') ?></td>
                        <td><?= e(date('d M Y', strtotime($asset['issue_date']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <a href="asset_clearance.php?employee_id=<?= (int) $selectedClearance['employee_id'] ?>" class="btn btn-secondary">Clear assets</a>
        </div>
        <?php endif; ?>
    </div>
    <p class="exit-note">The employee remains active while these balances are unsettled. Finance can record a full loan payoff here after receiving it, or let payroll deductions clear the balance. Unpaid salary must be settled through payroll before exit. All assets must be returned before exit.</p>
    <?php else: ?>
    <p class="exit-note">No outstanding approved loan balance, unpaid payroll, or unreturned assets were found.</p>
    <?php endif; ?>

    <?php if (has_permission('hr', 'approve')): ?>
    <div class="header-actions">
        <?php if ($selectedClearance['status'] === 'pending' && !$hasOutstanding): ?>
        <form method="post">
            <input type="hidden" name="action" value="approve_exit">
            <input type="hidden" name="clearance_id" value="<?= (int) $selectedClearance['id'] ?>">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Approve this exit? The employee will remain active until the last working day.')">Approve exit</button>
        </form>
        <?php elseif ($selectedClearance['status'] === 'pending'): ?>
        <span class="exit-note">Resolve <?= $hasOutstanding ? implode(', ', $holdReasons) : 'all holds' ?> before HR can approve this exit.</span>
        <?php elseif (!$hasOutstanding && $selectedClearance['last_working_day'] <= date('Y-m-d')): ?>
        <form method="post">
            <input type="hidden" name="action" value="complete_exit">
            <input type="hidden" name="clearance_id" value="<?= (int) $selectedClearance['id'] ?>">
            <button type="submit" class="btn btn-danger" onclick="return confirm('Finalize this approved exit and mark the employee inactive?')">Finalize exit</button>
        </form>
        <?php elseif ($hasOutstanding): ?>
        <span class="exit-note">Resolve <?= implode(', ', $holdReasons) ?> before finalizing this approved exit.</span>
        <?php else: ?>
        <span class="exit-note">Exit approved. Finalization is available on or after the last working day.</span>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="cancel_exit">
            <input type="hidden" name="clearance_id" value="<?= (int) $selectedClearance['id'] ?>">
            <button type="submit" class="btn btn-secondary" onclick="return confirm('Cancel this exit request?')">Cancel request</button>
        </form>
    </div>
    <?php else: ?>
    <p class="exit-note">This exit is awaiting an HR manager's decision.</p>
    <?php endif; ?>
</section>
<?php elseif ($selectedEmployee && (int) $selectedEmployee['status'] === 1): ?>
<section class="panel">
    <h2>Start exit clearance for <?= e($selectedEmployee['name']) ?> <span class="exit-code"><?= e($selectedEmployee['employee_code']) ?></span></h2>
    <?php if (has_permission('hr', 'create')): ?>
    <form method="post" class="exit-form">
        <input type="hidden" name="action" value="request_exit">
        <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
        <div>
            <label for="last_working_day">Last working day</label>
            <input type="date" id="last_working_day" name="last_working_day" required>
        </div>
        <div class="exit-reason">
            <label for="reason">Reason for departure</label>
            <textarea id="reason" name="reason" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Begin exit clearance</button>
    </form>
    <?php else: ?>
    <p>You have view-only access to HR exit clearance.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Pending clearances</h2>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Employee</th><th>Last working day</th><th>Status</th><th>Reason</th><th>Requested</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$pendingClearances): ?><tr><td colspan="6">No open exit clearances.</td></tr><?php endif; ?>
            <?php foreach ($pendingClearances as $clearance): ?>
                <tr>
                    <td><?= e($clearance['employee_name']) ?> (<?= e($clearance['employee_code']) ?>)</td>
                    <td><?= e(date('d M Y', strtotime($clearance['last_working_day']))) ?></td>
                    <td><?= e(ucfirst($clearance['status'])) ?></td>
                    <td><?= e($clearance['reason']) ?></td>
                    <td><?= e(date('d M Y', strtotime($clearance['requested_at']))) ?></td>
                    <td>
                        <a class="btn-sm" href="exit_clearance.php?clearance_id=<?= (int) $clearance['id'] ?>">Review</a>
                        <a class="btn-sm" href="exit_clearance_print.php?id=<?= (int) $clearance['id'] ?>" target="_blank">Print</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Start clearance</h2>
    <?php if ($activeEmployees && has_permission('hr', 'edit')): ?>
    <form method="get" class="exit-start-form">
        <label for="employee_id">Active employee</label>
        <select id="employee_id" name="employee_id" required>
            <option value="">Select employee</option>
            <?php foreach ($activeEmployees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($employee['name']) ?> (<?= e($employee['employee_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Continue</button>
    </form>
    <?php elseif (!$activeEmployees): ?>
    <p>All active employees already have a pending exit request, or there are no active employees.</p>
    <?php else: ?>
    <p>You have view-only access to HR exit clearance.</p>
    <?php endif; ?>
</section>

<?php if ($history): ?>
<section class="panel">
    <h2>Recent exit history</h2>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Employee</th><th>Last working day</th><th>Status</th><th>Requested</th><th>Completed</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($history as $record): ?>
                <tr>
                    <td><?= e($record['employee_name']) ?> (<?= e($record['employee_code']) ?>)</td>
                    <td><?= e(date('d M Y', strtotime($record['last_working_day']))) ?></td>
                    <td><?= e(ucfirst($record['status'])) ?></td>
                    <td><?= e(date('d M Y', strtotime($record['requested_at']))) ?></td>
                    <td><?= $record['completed_at'] ? e(date('d M Y', strtotime($record['completed_at']))) : '-' ?></td>
                    <td><a class="btn-sm" href="exit_clearance_print.php?id=<?= (int) $record['id'] ?>" target="_blank">Print</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<style>
.exit-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.exit-heading h2{margin:0}.exit-heading p{margin:8px 0 0;color:#64748b}.exit-code{font-size:.82rem;color:#64748b;font-weight:500}.exit-holds{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:20px}.exit-holds h3{margin:0 0 10px}.exit-holds .btn{margin-top:12px}.exit-note{color:#64748b}.exit-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:end}.exit-form label,.exit-start-form label,.loan-payoff-form label{display:block;margin-bottom:6px;font-weight:600}.exit-form input,.exit-form textarea,.exit-start-form select,.loan-payoff-form input,.loan-payoff-form select{width:100%}.exit-reason{grid-column:1/-1}.exit-start-form{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.exit-start-form select{min-width:260px}.exit-start-form label{margin:0}.loan-payoff-form{display:grid;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid var(--bp-border)}.header-actions form{margin:0}@media(max-width:600px){.exit-form{grid-template-columns:1fr}.exit-reason{grid-column:auto}.exit-start-form{align-items:stretch;flex-direction:column}.exit-start-form select{min-width:0}}
</style>

<?php require_once "../partials/footer.php"; ?>