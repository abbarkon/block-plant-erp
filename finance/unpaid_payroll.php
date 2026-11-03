<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

// Payment columns are part of the payroll workflow; add them if this database predates them.
try {
    $columnNames = array_column($pdo->query("SHOW COLUMNS FROM payroll")->fetchAll(), 'Field');
    if (!in_array('payment_method', $columnNames, true)) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN payment_method VARCHAR(30)");
    }
    if (!in_array('payment_reference', $columnNames, true)) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN payment_reference VARCHAR(100)");
    }
    if (!in_array('paid_by', $columnNames, true)) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN paid_by INT");
    }
} catch (PDOException $ex) {
    // Columns already exist or the user cannot alter the table - continue with what is available.
}

$hasWorkflowStatus = in_array('workflow_status', $columnNames ?? [], true);
$canPay = has_permission('finance', 'create');

$paymentMethods = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mobile_money' => 'Mobile Money', 'cheque' => 'Cheque'];

$selectedMonth = (int) ($_GET['month'] ?? date('m'));
$selectedYear = (int) ($_GET['year'] ?? date('Y'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int) date('m');
}
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int) date('Y');
}

$backToList = 'unpaid_payroll.php?month=' . $selectedMonth . '&year=' . $selectedYear;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    require_permission('finance', 'create');

    $payrollId = (int) ($_POST['payroll_id'] ?? 0);
    $paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? '')));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));

    $dateOk = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate);
    if ($dateOk) {
        [$py, $pm, $pd] = array_map('intval', explode('-', $paymentDate));
        $dateOk = checkdate($pm, $pd, $py);
    }

    if ($payrollId <= 0) {
        set_flash('error', 'Choose a payroll record to pay.');
    } elseif (!$dateOk) {
        set_flash('error', 'Payment date must be a valid date.');
    } elseif (!isset($paymentMethods[$paymentMethod])) {
        set_flash('error', 'Choose a valid payment method.');
    } elseif ($reference === '') {
        set_flash('error', 'A payment reference is required.');
    } elseif (strlen($reference) > 100) {
        set_flash('error', 'Payment reference must be 100 characters or fewer.');
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT p.*, e.name AS employee_name FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.id = ? FOR UPDATE");
            $stmt->execute([$payrollId]);
            $payroll = $stmt->fetch();

            if (!$payroll) {
                $pdo->rollBack();
                set_flash('error', 'That payroll record no longer exists.');
            } elseif (mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year']) > mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'))) {
                $pdo->rollBack();
                set_flash('error', 'Payroll for ' . date('F Y', mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year'])) . ' cannot be paid because that period has not started yet.');
            } elseif (!in_array(strtolower(trim((string) ($payroll['payment_status'] ?? ''))), ['', 'pending', 'unpaid'], true)) {
                $pdo->rollBack();
                $status = strtolower(trim((string) $payroll['payment_status']));
                set_flash('error', 'This payroll record is already marked as ' . ($status === '' ? 'unpaid' : $status) . '. Refresh the page to see the current state.');
            } else {
                $set = "payment_status = 'paid', payment_date = ?, payment_method = ?, payment_reference = ?, paid_by = ?";
                $params = [$paymentDate, $paymentMethod, $reference, (int) $_SESSION['user_id']];
                if ($hasWorkflowStatus) {
                    $set .= ", workflow_status = 'paid'";
                }
                $params[] = $payrollId;
                $pdo->prepare("UPDATE payroll SET {$set} WHERE id = ?")->execute($params);

                $period = date('F Y', mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year']));
                $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES(?, 'payment', ?, ?, ?, ?, ?)")
                    ->execute([
                        $paymentDate,
                        $reference,
                        'Payroll payment - ' . $payroll['employee_name'] . ' (' . $period . ')',
                        $payroll['net_salary'],
                        $paymentMethod,
                        (int) $_SESSION['user_id'],
                    ]);

                $pdo->commit();
                log_activity('pay', 'finance', $payrollId, 'Paid payroll #' . $payrollId . ' to ' . $payroll['employee_name'] . ' for ' . $period . ' (' . money($payroll['net_salary']) . ', ref ' . $reference . ')');
                set_flash('success', 'Payroll payment recorded for ' . $payroll['employee_name'] . ' (' . $period . ').');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Unable to record the payment: ' . $ex->getMessage());
        }
    }

    header('Location: ' . $backToList);
    exit;
}

$periodEndDate = date('Y-m-t', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));

// Payroll for a period that has not started yet is not payable and must not be
// counted as "unpaid" - otherwise next year's December shows up as overdue today.
$currentPeriodStart = mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'));
$selectedPeriodStart = mktime(0, 0, 0, $selectedMonth, 1, $selectedYear);
$isFuturePeriod = $selectedPeriodStart > $currentPeriodStart;

if ($isFuturePeriod) {
    $unpaidPayroll = [];
} else {
try {
    $stmt = $pdo->prepare("SELECT p.*, e.name AS employee_name, e.employee_code, e.bank_account, e.date_of_joining
        FROM payroll p JOIN employees e ON p.employee_id = e.id
        WHERE p.month = ? AND p.year = ?
          AND (p.payment_status IS NULL OR p.payment_status = 'pending' OR p.payment_status = 'unpaid')
          AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?)
        ORDER BY e.name");
    $stmt->execute([$selectedMonth, $selectedYear, $periodEndDate]);
    $unpaidPayroll = $stmt->fetchAll();
} catch (PDOException $ex) {
    $unpaidPayroll = [];
}
}

try {
    $allUnpaidRaw = $pdo->prepare("SELECT p.*, e.name AS employee_name, e.employee_code, e.date_of_joining
        FROM payroll p JOIN employees e ON p.employee_id = e.id
        WHERE (p.payment_status IS NULL OR p.payment_status = 'pending' OR p.payment_status = 'unpaid')
          AND (p.year < ? OR (p.year = ? AND p.month <= ?))
        ORDER BY p.year DESC, p.month DESC, e.name");
    $allUnpaidRaw->execute([(int) date('Y'), (int) date('Y'), (int) date('m')]);
    $allUnpaidRaw = $allUnpaidRaw->fetchAll();

    $allUnpaid = [];
    foreach ($allUnpaidRaw as $row) {
        if (!empty($row['date_of_joining'])) {
            $joined = strtotime((string) $row['date_of_joining']);
            $rowPeriodEnd = date('Y-m-t', mktime(0, 0, 0, (int) $row['month'], 1, (int) $row['year']));
            if ($joined !== false && $joined > strtotime($rowPeriodEnd)) {
                continue;
            }
        }
        $allUnpaid[] = $row;
    }
} catch (PDOException $ex) {
    $allUnpaid = [];
}

$totalUnpaidAmount = 0.0;
foreach ($allUnpaid as $row) {
    $totalUnpaidAmount += (float) $row['net_salary'];
}

$selectedPeriodTotal = 0.0;
foreach ($unpaidPayroll as $row) {
    $selectedPeriodTotal += (float) $row['net_salary'];
}

$agedCount = 0;
$today = new DateTimeImmutable('today');
foreach ($allUnpaid as $row) {
    $periodEnd = new DateTimeImmutable(date('Y-m-t', mktime(0, 0, 0, (int) $row['month'], 1, (int) $row['year'])));
    if ($periodEnd < $today->modify('-30 days')) {
        $agedCount++;
    }
}

// Future-period payroll rows are not payable yet, so they are excluded from the
// unpaid totals above. Count them so the team can spot bad data.
$futurePayrollCount = 0;
try {
    $futureStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll p
        WHERE (p.payment_status IS NULL OR p.payment_status = 'pending' OR p.payment_status = 'unpaid')
          AND (p.year > ? OR (p.year = ? AND p.month > ?))");
    $futureStmt->execute([(int) date('Y'), (int) date('Y'), (int) date('m')]);
    $futurePayrollCount = (int) $futureStmt->fetchColumn();
} catch (PDOException $ex) {
    $futurePayrollCount = 0;
}

$flash = get_flash();
$pageTitle = "Unpaid Payroll Management";
require_once "../partials/header.php";
$cur = currency_symbol();
$periodLabel = date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
?>

<div class="up-hero">
    <div>
        <span class="up-eyebrow">FINANCE · PAYROLL SETTLEMENT</span>
        <h1>💳 Unpaid Payroll</h1>
        <p>Outstanding salary payments across every period, with a one-click settlement record.</p>
    </div>
    <div class="up-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="<?= e($backToList) ?>">Reset to this month</a>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<?php if ($isFuturePeriod): ?>
<div class="flash">📅 <strong><?= e($periodLabel) ?></strong> has not started yet (today is <?= e(date('d M Y')) ?>), so its payroll cannot be generated or paid. Future periods are excluded from unpaid totals.</div>
<?php endif; ?>

<?php if ($futurePayrollCount > 0): ?>
<div class="flash">⚠️ <?= number_format($futurePayrollCount) ?> payroll record<?= $futurePayrollCount === 1 ? '' : 's' ?> exist for future periods and are not counted as unpaid. Payroll generation for future months is now blocked - review that data.</div>
<?php endif; ?>

<form method="get" class="up-filter panel no-print">
    <div>
        <label for="month">Month</label>
        <select id="month" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label for="year">Year</label>
        <select id="year" name="year">
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-accent">Show period</button>
</form>

<div class="up-summary">
    <div><span>Unpaid records (all periods)</span><strong><?= number_format(count($allUnpaid)) ?></strong></div>
    <div><span>Outstanding amount (all periods)</span><strong><?= e($cur) . money($totalUnpaidAmount) ?></strong></div>
    <div><span><?= e($periodLabel) ?> outstanding</span><strong><?= e($cur) . money($selectedPeriodTotal) ?></strong></div>
    <div><span>Overdue beyond 30 days</span><strong class="<?= $agedCount > 0 ? 'up-bad' : 'up-ok' ?>"><?= number_format($agedCount) ?></strong></div>
</div>

<?php if (!$canPay): ?>
<div class="flash">You can review unpaid payroll, but recording a payment requires the <strong>finance create</strong> permission.</div>
<?php endif; ?>

<div class="panel up-panel">
    <div class="up-panel-head">
        <h3>Unpaid payroll for <?= e($periodLabel) ?></h3>
        <span class="up-count"><?= number_format(count($unpaidPayroll)) ?> record<?= count($unpaidPayroll) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$unpaidPayroll): ?>
    <p class="up-empty">No unpaid payroll records for this period.</p>
    <?php else: ?>
    <div class="table-container">
        <table class="data-table up-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="up-num">Basic salary</th>
                    <th class="up-num">Allowances</th>
                    <th class="up-num">Deductions</th>
                    <th class="up-num">Net salary</th>
                    <th>Bank account</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unpaidPayroll as $payroll): ?>
                <tr>
                    <td><?= e($payroll['employee_name']) ?> <small class="up-code"><?= e($payroll['employee_code']) ?></small></td>
                    <td class="up-num"><?= e($cur) . money($payroll['basic_salary']) ?></td>
                    <td class="up-num"><?= e($cur) . money($payroll['allowances']) ?></td>
                    <td class="up-num"><?= e($cur) . money($payroll['deductions']) ?></td>
                    <td class="up-num"><strong><?= e($cur) . money($payroll['net_salary']) ?></strong></td>
                    <td><?= e($payroll['bank_account'] ?: 'Not set') ?></td>
                    <td class="no-print">
                        <?php if ($canPay): ?>
                        <button type="button" class="btn btn-sm up-pay-btn js-pay"
                                data-payroll-id="<?= (int) $payroll['id'] ?>"
                                data-employee="<?= e($payroll['employee_name']) ?>"
                                data-amount="<?= e(number_format((float) $payroll['net_salary'], 2, '.', '')) ?>">Process payment</button>
                        <?php else: ?>
                        <span class="up-muted">Read only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4">Period total</th>
                    <th class="up-num"><?= e($cur) . money($selectedPeriodTotal) ?></th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="panel up-panel">
    <div class="up-panel-head">
        <h3>All unpaid payroll records</h3>
        <span class="up-count"><?= number_format(count($allUnpaid)) ?> record<?= count($allUnpaid) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$allUnpaid): ?>
    <p class="up-empty">No unpaid payroll records found.</p>
    <?php else: ?>
    <div class="table-container">
        <table class="data-table up-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Period</th>
                    <th class="up-num">Net salary</th>
                    <th>Status</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allUnpaid as $payroll): ?>
                <tr>
                    <td><?= e($payroll['employee_name']) ?> <small class="up-code"><?= e($payroll['employee_code']) ?></small></td>
                    <td><?= e(date('F Y', mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year']))) ?></td>
                    <td class="up-num"><strong><?= e($cur) . money($payroll['net_salary']) ?></strong></td>
                    <td><span class="up-status"><?= e(ucfirst($payroll['payment_status'] ?: 'Pending')) ?></span></td>
                    <td class="no-print">
                        <?php if ($canPay): ?>
                        <button type="button" class="btn btn-sm up-pay-btn js-pay"
                                data-payroll-id="<?= (int) $payroll['id'] ?>"
                                data-employee="<?= e($payroll['employee_name']) ?>"
                                data-amount="<?= e(number_format((float) $payroll['net_salary'], 2, '.', '')) ?>">Process payment</button>
                        <?php else: ?>
                        <span class="up-muted">Read only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($canPay): ?>
<div id="paymentModal" class="up-modal" hidden>
    <div class="up-modal-card" role="dialog" aria-modal="true" aria-labelledby="upModalTitle">
        <div class="up-modal-head">
            <h2 id="upModalTitle">Process payment</h2>
            <button type="button" class="up-modal-close" id="upModalClose" aria-label="Close">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="process_payment" value="1">
            <input type="hidden" name="payroll_id" id="paymentPayrollId" value="">
            <div class="up-field">
                <label>Employee</label>
                <div id="paymentEmployeeName" class="up-readonly"></div>
            </div>
            <div class="up-field">
                <label>Amount</label>
                <div id="paymentAmount" class="up-amount"></div>
            </div>
            <div class="up-field">
                <label for="payment_date">Payment date</label>
                <input type="date" id="payment_date" name="payment_date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="up-field">
                <label for="payment_method">Payment method</label>
                <select id="payment_method" name="payment_method" required>
                    <?php foreach ($paymentMethods as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="up-field">
                <label for="reference">Reference number</label>
                <input type="text" id="reference" name="reference" maxlength="100" required placeholder="Receipt / bank reference">
            </div>
            <button type="submit" class="btn btn-accent up-submit">Confirm payment</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.up-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#183b56,#246a73 65%,#2a9d8f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.up-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.up-hero p{margin:0;color:rgba(255,255,255,.76)}
.up-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.up-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.up-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.up-filter{padding:18px 20px;display:grid;grid-template-columns:repeat(2,minmax(150px,220px)) auto;gap:14px;align-items:end;margin-bottom:14px}
.up-filter label{display:block;margin:0 0 5px}
.up-filter select{margin:0}
.up-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.up-summary div{padding:15px 17px;background:#fff;border:1px solid #dce7e5;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.up-summary span{display:block;font-size:.76rem;color:#667a80}
.up-summary strong{display:block;margin-top:5px;font-size:1.15rem;color:#183b56;font-variant-numeric:tabular-nums}
.up-summary strong.up-bad{color:#b3452f}
.up-summary strong.up-ok{color:#1d7a4f}
.up-panel{margin-bottom:18px}
.up-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.up-panel-head h3{margin:0}
.up-count{font-size:.8rem;color:#667a80;font-weight:700}
.up-table th.up-num,.up-table td.up-num{text-align:right;font-variant-numeric:tabular-nums}
.up-table tfoot th{padding:13px 12px;background:#eaf3f0;color:#183b56;text-align:right;font-variant-numeric:tabular-nums}
.up-table tfoot th:first-child{text-align:left}
.up-code{color:#8b98a1;font-size:.78rem;margin-left:4px}
.up-status{display:inline-block;padding:3px 9px;border-radius:20px;background:#fff4e0;color:#a86a12;font-size:.74rem;font-weight:800}
.up-pay-btn{background:#1d7a4f!important}
.up-muted{color:#b8c2c8;font-size:.82rem}
.up-empty{text-align:center;padding:34px!important;color:#667a80}
.up-modal{position:fixed;inset:0;background:rgba(10,25,35,.55);display:grid;place-items:center;padding:20px;z-index:9999}
.up-modal[hidden]{display:none}
.up-modal-card{width:min(100%,520px);background:#fff;border-radius:14px;padding:24px;box-shadow:0 30px 70px rgba(10,25,35,.3)}
.up-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.up-modal-head h2{margin:0;font-size:1.2rem}
.up-modal-close{border:none;background:none;font-size:1.6rem;line-height:1;cursor:pointer;color:#667a80}
.up-field{margin-bottom:14px}
.up-field label{display:block;margin:0 0 5px;font-weight:700;font-size:.85rem}
.up-readonly{padding:10px 12px;background:#f4f7f8;border-radius:8px;font-weight:700}
.up-amount{padding:10px 12px;background:#eaf5f1;border-radius:8px;font-size:1.35rem;font-weight:800;color:#1d7a4f}
.up-submit{width:100%}
@media(max-width:850px){.up-summary{grid-template-columns:repeat(2,1fr)}.up-filter{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.up-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.up-hero h1{font-size:1.75rem}.up-summary{grid-template-columns:1fr 1fr;gap:8px}.up-filter{grid-template-columns:1fr}}
@media print {
    .no-print, .up-hero-actions, .up-filter, .up-modal { display: none !important; }
    .up-hero { background: #183b56 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; }
    .up-table { min-width: 0; }
    .table-container { overflow: visible; }
}
</style>

<?php if ($canPay): ?>
<script>
(function () {
    var modal = document.getElementById('paymentModal');
    if (!modal) return;
    var idField = document.getElementById('paymentPayrollId');
    var nameField = document.getElementById('paymentEmployeeName');
    var amountField = document.getElementById('paymentAmount');
    var closeBtn = document.getElementById('upModalClose');
    var currency = <?= json_encode($cur) ?>;

    function money(v) {
        return currency + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.js-pay');
        if (trigger) {
            idField.value = trigger.getAttribute('data-payroll-id');
            nameField.textContent = trigger.getAttribute('data-employee');
            amountField.textContent = money(trigger.getAttribute('data-amount'));
            modal.hidden = false;
            var ref = document.getElementById('reference');
            if (ref) ref.value = '';
            return;
        }
        if (event.target === modal || event.target.closest('#upModalClose')) {
            modal.hidden = true;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') modal.hidden = true;
    });
})();
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
