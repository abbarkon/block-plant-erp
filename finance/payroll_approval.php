<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

ensure_notifications_schema();

// Older databases may lack the columns this page depends on.
$payrollColumnNames = array_column($pdo->query("SHOW COLUMNS FROM payroll")->fetchAll(), 'Field');
$payrollColumnDdl = [
    'workflow_status' => "ALTER TABLE payroll ADD COLUMN workflow_status ENUM('draft','pending_finance','approved','rejected','transferred','paid') DEFAULT 'draft'",
    'payment_status' => "ALTER TABLE payroll ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending'",
    'paid_by' => "ALTER TABLE payroll ADD COLUMN paid_by INT NULL",
    'finance_approved_by' => "ALTER TABLE payroll ADD COLUMN finance_approved_by INT NULL",
    'finance_approved_date' => "ALTER TABLE payroll ADD COLUMN finance_approved_date DATE NULL",
    'bank_reference' => "ALTER TABLE payroll ADD COLUMN bank_reference VARCHAR(100) NULL",
    'transfer_date' => "ALTER TABLE payroll ADD COLUMN transfer_date DATE NULL",
];
foreach ($payrollColumnDdl as $column => $ddl) {
    if (!in_array($column, $payrollColumnNames, true)) {
        $pdo->exec($ddl);
    }
}
$employeeColumnNames = array_column($pdo->query("SHOW COLUMNS FROM employees")->fetchAll(), 'Field');
if (!in_array('bank_account', $employeeColumnNames, true)) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN bank_account VARCHAR(100)");
}

$canApprove = has_permission('finance', 'approve');

$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? date('Y'));
if ($month < 1 || $month > 12) {
    $month = (int) date('m');
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}
$payrollEndDate = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
$periodLabel = format_app_month_year($month, $year);
$selfUrl = 'payroll_approval.php?month=' . $month . '&year=' . $year;
$isFuturePeriod = mktime(0, 0, 0, $month, 1, $year) > mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'));

/* ---------------------------------------------------------------
   Approve & transfer to bank / reject the period's payroll
   --------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_transfer']) || isset($_POST['reject']))) {
    require_permission('finance', 'approve');

    if ($isFuturePeriod) {
        set_flash('error', "Payroll for {$periodLabel} cannot be approved because that period has not started yet.");
        header('Location: ' . $selfUrl);
        exit;
    }

    $action = isset($_POST['approve_transfer']) ? 'approve' : 'reject';
    $adminId = (int) $_SESSION['user_id'];
    $bankRef = '';
    $transferNotes = '';
    $errors = [];

    if ($action === 'approve') {
        $bankRef = trim((string) ($_POST['bank_reference'] ?? ''));
        $transferNotes = trim((string) ($_POST['transfer_notes'] ?? ''));

        if ($bankRef !== '' && mb_strlen($bankRef) > 100) {
            $errors[] = 'The bank reference must be 100 characters or fewer.';
        }
        if ($bankRef !== '' && preg_match('/[\x00-\x1F\x7F]/', $bankRef)) {
            $errors[] = 'The bank reference contains characters that are not allowed.';
        }
        if (mb_strlen($transferNotes) > 500) {
            $errors[] = 'Transfer notes must be 500 characters or fewer.';
        }
        if ($transferNotes !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $transferNotes)) {
            $errors[] = 'Transfer notes contain characters that are not allowed.';
        }
        if ($bankRef === '') {
            $bankRef = 'BANK-' . date('YmdHis');
        }
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
    } else {
        try {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                $stmt = $pdo->prepare("UPDATE payroll
                    SET workflow_status = 'paid', payment_status = 'paid', bank_reference = ?,
                        transfer_date = CURDATE(), payment_date = CURDATE(), paid_by = ?,
                        finance_approved_by = ?, finance_approved_date = CURDATE(),
                        notes = COALESCE(NULLIF(?, ''), notes)
                    WHERE month = ? AND year = ? AND workflow_status = 'transferred'
                      AND employee_id IN (
                          SELECT id FROM employees
                          WHERE status = 1 AND (date_of_joining IS NULL OR date_of_joining <= ?)
                      )");
                $stmt->execute([$bankRef, $adminId, $adminId, $transferNotes, $month, $year, $payrollEndDate]);
            } else {
                $stmt = $pdo->prepare("UPDATE payroll
                    SET workflow_status = 'rejected'
                    WHERE month = ? AND year = ? AND workflow_status = 'transferred'
                      AND employee_id IN (
                          SELECT id FROM employees
                          WHERE status = 1 AND (date_of_joining IS NULL OR date_of_joining <= ?)
                      )");
                $stmt->execute([$month, $year, $payrollEndDate]);
            }

            $affected = $stmt->rowCount();

            if ($affected === 0) {
                $pdo->rollBack();
                set_flash('error', $action === 'approve'
                    ? "Nothing to approve. No payroll rows for {$periodLabel} are waiting for finance transfer."
                    : "Nothing to reject. No payroll rows for {$periodLabel} are waiting for finance.");
            } else {
                $pdo->commit();

                $link = "/block_plant/hr/payroll.php?month={$month}&year={$year}";
                if ($action === 'approve') {
                    notify_role(['admin', 'hr'], "Payroll for {$periodLabel} approved by finance and marked paid ({$affected} employees). Bank reference: {$bankRef}", $link);
                    log_activity('approve', 'finance', null, "Approved and transferred payroll for {$periodLabel} ({$affected} employees), bank reference {$bankRef}");
                    set_flash('success', "Payroll for {$periodLabel} approved and marked paid for {$affected} employees. Bank reference: {$bankRef}");
                } else {
                    notify_role(['admin', 'hr'], "Payroll for {$periodLabel} was rejected by finance. Please review and resubmit.", $link);
                    log_activity('reject', 'finance', null, "Rejected payroll for {$periodLabel} ({$affected} employees)");
                    set_flash('success', "Payroll for {$periodLabel} rejected. HR has been notified to review it.");
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('payroll_approval: ' . $ex->getMessage());
            set_flash('error', 'The payroll could not be updated. Nothing was changed. Please try again.');
        }
    }

    header('Location: ' . $selfUrl);
    exit;
}

/* ---------------------------------------------------------------
   Load the period
   --------------------------------------------------------------- */
try {
    $stmt = $pdo->prepare("SELECT e.id AS employee_id, e.name, e.employee_code, e.designation, e.bank_account,
            p.id AS payroll_id, p.basic_salary, p.allowances, p.overtime_payment, p.deductions, p.net_salary,
            p.workflow_status, p.payment_status, p.finance_approved_by, p.finance_approved_date,
            p.bank_reference, p.transfer_date, p.notes, ua.name AS approved_by_name
        FROM employees e
        LEFT JOIN payroll p ON e.id = p.employee_id AND p.month = ? AND p.year = ?
        LEFT JOIN users ua ON p.finance_approved_by = ua.id
        WHERE e.status = 1 AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?)
        ORDER BY e.name");
    $stmt->execute([$month, $year, $payrollEndDate]);
    $employees = $stmt->fetchAll();
} catch (PDOException $ex) {
    $employees = [];
}

$totalBasic = 0.0;
$totalAllowances = 0.0;
$totalOvertime = 0.0;
$totalDeductions = 0.0;
$totalNet = 0.0;
$totalToTransfer = 0.0;
$statusCounts = [];
$payrollRowCount = 0;
$withoutPayroll = 0;

foreach ($employees as $emp) {
    if ($emp['payroll_id'] === null) {
        $withoutPayroll++;
        continue;
    }

    $payrollRowCount++;
    $status = (string) ($emp['workflow_status'] ?: 'draft');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

    $totalBasic += (float) $emp['basic_salary'];
    $totalAllowances += (float) $emp['allowances'];
    $totalOvertime += (float) $emp['overtime_payment'];
    $totalDeductions += (float) $emp['deductions'];
    $totalNet += (float) $emp['net_salary'];

    if ($status === 'transferred') {
        $totalToTransfer += (float) $emp['net_salary'];
    }
}

$waitingForTransfer = (int) ($statusCounts['transferred'] ?? 0);

if ($payrollRowCount === 0) {
    $overallStatus = 'none';
} elseif (count($statusCounts) === 1) {
    $overallStatus = (string) array_key_first($statusCounts);
} else {
    $overallStatus = 'mixed';
}

$statusLabels = [
    'draft' => 'Draft',
    'pending_finance' => 'Pending finance',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'transferred' => 'Awaiting transfer',
    'paid' => 'Paid',
    'missing' => 'Not generated',
];

$bannerTitle = '';
$bannerMessage = '';
$bannerBg = '#f8fafc';
$bannerColor = '#64748b';

if ($overallStatus === 'none') {
    $bannerTitle = '📝 No payroll generated';
    $bannerMessage = "No payroll has been generated for {$periodLabel}. HR must generate it from Payroll Generation before finance can approve it.";
    $bannerBg = '#fff4e0';
    $bannerColor = '#a86a12';
} elseif ($overallStatus === 'transferred') {
    $bannerTitle = '🏦 Ready for approval & transfer';
    $bannerMessage = "HR has submitted {$periodLabel} payroll. Review the totals, then approve to mark it paid.";
    $bannerBg = '#e0e7ff';
    $bannerColor = '#6366f1';
} elseif ($overallStatus === 'paid') {
    $bannerTitle = '💰 Paid to employees';
    $bannerMessage = "The {$periodLabel} payroll has been approved and recorded as paid.";
    $bannerBg = '#dcfce7';
    $bannerColor = '#16a34a';
} elseif ($overallStatus === 'rejected') {
    $bannerTitle = '❌ Payroll rejected';
    $bannerMessage = "The {$periodLabel} payroll was rejected and needs HR to review and resubmit it.";
    $bannerBg = '#fee2e2';
    $bannerColor = '#ef4444';
} elseif ($overallStatus === 'approved') {
    $bannerTitle = '✅ Approved by finance';
    $bannerMessage = "The {$periodLabel} payroll is approved and waiting to be paid.";
    $bannerBg = '#d1fae5';
    $bannerColor = '#10b981';
} elseif ($overallStatus === 'draft' || $overallStatus === 'pending_finance') {
    $bannerTitle = '🕓 Awaiting HR submission';
    $bannerMessage = "The {$periodLabel} payroll exists but has not been sent to finance yet.";
    $bannerBg = '#fff4e0';
    $bannerColor = '#a86a12';
} else {
    $bannerTitle = '🔀 Mixed payroll statuses';
    $parts = [];
    foreach ($statusCounts as $status => $count) {
        $parts[] = ($statusLabels[$status] ?? ucfirst($status)) . ': ' . $count;
    }
    $bannerMessage = "The {$periodLabel} payroll has more than one status (" . implode(' · ', $parts) . '). Only rows awaiting transfer can be approved or rejected.';
    $bannerBg = '#eef3ff';
    $bannerColor = '#35558f';
}

$flash = get_flash();
$pageTitle = t('finance_payroll_approval');
require_once "../partials/header.php";
?>

<div class="pa-hero">
    <div>
        <span class="pa-eyebrow">FINANCE · PAYROLL DISBURSEMENT</span>
        <h1>🏦 <?= e(t('finance_payroll_approval')) ?></h1>
        <p>Review the payroll HR submitted, then approve it for bank transfer or send it back for correction.</p>
    </div>
    <div class="pa-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<div class="pa-banner" style="border-left-color:<?= e($bannerColor) ?>;background:<?= e($bannerBg) ?>;">
    <strong><?= e($bannerTitle) ?></strong>
    <div><?= e($bannerMessage) ?></div>
</div>

<form method="get" class="pa-filter panel no-print">
    <div>
        <label for="month"><?= e(t('month')) ?></label>
        <select id="month" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= e(format_app_month_name($m)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label for="year"><?= e(t('year')) ?></label>
        <select id="year" name="year">
            <?php
            $years = [];
            for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--) {
                $years[] = $y;
            }
            if (!in_array($year, $years, true)) {
                $years[] = $year;
                rsort($years);
            }
            foreach ($years as $y):
            ?>
            <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-accent"><?= e(t('show')) ?></button>
</form>

<div class="pa-summary">
    <div><span><?= e(t('total_basic_salary')) ?></span><strong><?= e(currency_symbol()) . number_format($totalBasic, 2) ?></strong></div>
    <div><span><?= e(t('total_allowances')) ?></span><strong class="pa-ok"><?= e(currency_symbol()) . number_format($totalAllowances, 2) ?></strong></div>
    <div><span>Overtime</span><strong class="pa-info"><?= e(currency_symbol()) . number_format($totalOvertime, 2) ?></strong></div>
    <div><span><?= e(t('total_deductions')) ?></span><strong class="pa-bad"><?= e(currency_symbol()) . number_format($totalDeductions, 2) ?></strong></div>
    <div><span><?= e(t('total_net_payroll')) ?></span><strong><?= e(currency_symbol()) . number_format($totalNet, 2) ?></strong></div>
</div>

<p class="pa-meta">
    <?= number_format($payrollRowCount) ?> payroll record<?= $payrollRowCount === 1 ? '' : 's' ?> for <?= e($periodLabel) ?>
    <?php if ($withoutPayroll > 0): ?>
        · <span class="pa-warn"><?= number_format($withoutPayroll) ?> active employee<?= $withoutPayroll === 1 ? '' : 's' ?> without a payroll record</span>
    <?php endif; ?>
</p>

<?php if (!$canApprove): ?>
<div class="flash">You can review payroll here, but approving or rejecting it requires the <strong>finance approve</strong> permission.</div>
<?php endif; ?>

<?php if ($isFuturePeriod): ?>
<div class="flash">📅 <strong><?= e($periodLabel) ?></strong> has not started yet (today is <?= e(date('d M Y')) ?>), so its payroll cannot be approved or rejected.</div>
<?php endif; ?>

<?php if ($waitingForTransfer > 0 && $canApprove && !$isFuturePeriod): ?>
<div class="panel pa-action-panel no-print">
    <div>
        <h3><?= e(t('finance_actions')) ?></h3>
        <p class="pa-action-note">
            <?= number_format($waitingForTransfer) ?> payroll record<?= $waitingForTransfer === 1 ? '' : 's' ?> awaiting transfer ·
            total <strong><?= e(currency_symbol()) . number_format($totalToTransfer, 2) ?></strong>
        </p>
    </div>
    <div class="pa-action-buttons">
        <button type="button" class="btn btn-primary" id="paOpenTransfer">✓ <?= e(t('approve_transfer_bank')) ?></button>
        <form method="post" class="pa-inline" data-confirm="Reject the <?= e($periodLabel) ?> payroll? HR will be notified.">
            <input type="hidden" name="reject" value="1">
            <button type="submit" class="btn btn-danger">✗ <?= e(t('reject_payroll')) ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="pa-panel-head">
        <h3><?= e(t('employee_payroll_details')) ?></h3>
        <span class="pa-count"><?= e($periodLabel) ?></span>
    </div>
    <div class="table-container">
        <table class="data-table pa-table">
            <thead>
                <tr>
                    <th><?= e(t('employee_name')) ?></th>
                    <th class="pa-num"><?= e(t('basic_salary')) ?></th>
                    <th class="pa-num"><?= e(t('allowances')) ?></th>
                    <th class="pa-num">Overtime</th>
                    <th class="pa-num"><?= e(t('deductions')) ?></th>
                    <th class="pa-num"><?= e(t('net_salary')) ?></th>
                    <th><?= e(t('bank_account')) ?></th>
                    <th>Status</th>
                    <th class="pa-approval-col">Approved by</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$employees): ?>
                <tr><td colspan="9" class="pa-empty">No active employees for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($employees as $emp): ?>
                <?php
                $hasPayroll = $emp['payroll_id'] !== null;
                $status = $hasPayroll ? (string) ($emp['workflow_status'] ?: 'draft') : 'missing';
                ?>
                <tr class="<?= $hasPayroll ? '' : 'pa-row-missing' ?>">
                    <td>
                        <strong><?= e($emp['name']) ?></strong>
                        <?php if (!empty($emp['employee_code'])): ?>
                        <small class="pa-code"><?= e($emp['employee_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="pa-num"><?= $hasPayroll ? e(number_format((float) $emp['basic_salary'], 2)) : '—' ?></td>
                    <td class="pa-num"><?= $hasPayroll ? e(number_format((float) $emp['allowances'], 2)) : '—' ?></td>
                    <td class="pa-num"><?= $hasPayroll ? e(number_format((float) $emp['overtime_payment'], 2)) : '—' ?></td>
                    <td class="pa-num"><?= $hasPayroll ? e(number_format((float) $emp['deductions'], 2)) : '—' ?></td>
                    <td class="pa-num"><strong><?= $hasPayroll ? e(number_format((float) $emp['net_salary'], 2)) : '—' ?></strong></td>
                    <td>
                        <?php if (!empty($emp['bank_account'])): ?>
                        <?= e($emp['bank_account']) ?>
                        <?php else: ?>
                        <span class="pa-warn"><?= e(t('not_set')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="pa-status pa-status-<?= e($status) ?>"><?= e($statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status))) ?></span></td>
                    <td class="pa-approval-col">
                        <?php if (!empty($emp['approved_by_name']) && !empty($emp['finance_approved_date'])): ?>
                            <?= e($emp['approved_by_name']) ?><br>
                            <small class="pa-code"><?= e(format_app_date($emp['finance_approved_date'])) ?></small>
                            <?php if (!empty($emp['bank_reference'])): ?>
                            <br><small class="pa-code">Ref: <?= e($emp['bank_reference']) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="pa-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($payrollRowCount > 0): ?>
            <tfoot>
                <tr>
                    <th>Total (<?= number_format($payrollRowCount) ?>)</th>
                    <th class="pa-num"><?= e(number_format($totalBasic, 2)) ?></th>
                    <th class="pa-num"><?= e(number_format($totalAllowances, 2)) ?></th>
                    <th class="pa-num"><?= e(number_format($totalOvertime, 2)) ?></th>
                    <th class="pa-num"><?= e(number_format($totalDeductions, 2)) ?></th>
                    <th class="pa-num"><?= e(number_format($totalNet, 2)) ?></th>
                    <th colspan="3"></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="pa-help no-print">
    <h3>How this works</h3>
    <p>HR submits a period's payroll from <a href="/block_plant/hr/payroll.php">HR → Payroll</a>. Rows with the status
        <em>Awaiting transfer</em> appear here for finance. Approving marks every transferred row as paid, records the
        bank reference, transfer date and approver, and notifies HR. Rejecting sends the period back to HR without paying it.</p>
</div>

<?php if ($waitingForTransfer > 0 && $canApprove && !$isFuturePeriod): ?>
<div id="paTransferModal" class="pa-modal" hidden>
    <div class="pa-modal-card" role="dialog" aria-modal="true" aria-labelledby="paTransferTitle">
        <div class="pa-modal-head">
            <h2 id="paTransferTitle"><?= e(t('approve_transfer_title')) ?></h2>
            <button type="button" class="pa-modal-close" id="paCloseTransfer" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="pa-modal-form">
            <input type="hidden" name="approve_transfer" value="1">
            <div class="pa-modal-total">
                <span><?= e(t('total_amount')) ?></span>
                <strong><?= e(currency_symbol()) . number_format($totalToTransfer, 2) ?></strong>
                <small><?= e($periodLabel) ?> · <?= number_format($waitingForTransfer) ?> employee<?= $waitingForTransfer === 1 ? '' : 's' ?></small>
            </div>
            <div class="pa-field">
                <label for="bank_reference"><?= e(t('bank_reference')) ?></label>
                <input type="text" id="bank_reference" name="bank_reference" maxlength="100"
                       placeholder="<?= e(t('enter_bank_reference')) ?>">
                <small>Leave blank to generate a reference automatically.</small>
            </div>
            <div class="pa-field">
                <label for="transfer_notes"><?= e(t('transfer_notes')) ?></label>
                <textarea id="transfer_notes" name="transfer_notes" rows="2" maxlength="500"
                          placeholder="<?= e(t('transfer_notes_placeholder')) ?>"></textarea>
            </div>
            <button type="submit" class="btn btn-primary pa-modal-submit"><?= e(t('approve_transfer_bank')) ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.pa-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 65%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.pa-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.pa-hero p{margin:0;color:rgba(255,255,255,.76)}
.pa-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.pa-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.pa-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.pa-banner{margin-bottom:16px;padding:14px 18px;border-radius:12px;border-left:5px solid #64748b;background:#f8fafc}
.pa-banner strong{display:block;margin-bottom:3px;font-size:1.02rem;color:#1f2933}
.pa-banner div{color:#5b6b73;font-size:.9rem}
.pa-filter{padding:18px 20px;display:grid;grid-template-columns:repeat(2,minmax(150px,220px)) auto;gap:14px;align-items:end;margin-bottom:14px}
.pa-filter label{display:block;margin:0 0 5px}
.pa-filter select{margin:0}
.pa-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:0 0 12px}
.pa-summary div{padding:15px 17px;background:#fff;border:1px solid #e3ddd4;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.pa-summary span{display:block;font-size:.76rem;color:#6a6259}
.pa-summary strong{display:block;margin-top:5px;font-size:1.22rem;color:#1f2933;font-variant-numeric:tabular-nums}
.pa-summary strong.pa-ok{color:#1d7a4f}
.pa-summary strong.pa-bad{color:#b3452f}
.pa-summary strong.pa-info{color:#35558f}
.pa-meta{margin:0 0 18px;color:#6a6259;font-size:.85rem}
.pa-warn{color:#b3452f;font-weight:700}
.pa-action-panel{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.pa-action-panel h3{margin:0 0 4px}
.pa-action-note{margin:0;color:#5b6b73;font-size:.88rem}
.pa-action-buttons{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.pa-inline{display:inline;margin:0}
.pa-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.pa-panel-head h3{margin:0}
.pa-count{font-size:.8rem;color:#6a6259;font-weight:700}
.pa-table th.pa-num,.pa-table td.pa-num{text-align:right;font-variant-numeric:tabular-nums}
.pa-table tfoot th{background:#f4f7f8;border-top:2px solid #e3ddd4}
.pa-status{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:800;background:#eef2f5;color:#4a5f6b;white-space:nowrap}
.pa-status-transferred{background:#eef3ff;color:#35558f}
.pa-status-paid{background:#dcfce7;color:#166534}
.pa-status-approved{background:#e6f4ec;color:#1d7a4f}
.pa-status-rejected{background:#fdeee9;color:#b3452f}
.pa-status-draft,.pa-status-pending_finance{background:#fff4e0;color:#a86a12}
.pa-status-missing{background:#eef2f5;color:#8b98a1}
.pa-row-missing td{color:#8b98a1}
.pa-code{color:#8b98a1;font-size:.76rem;margin-left:4px}
.pa-muted{color:#b6c2c8}
.pa-empty{text-align:center;padding:34px!important;color:#6a6259}
.pa-help{margin-top:4px;padding:20px;background:#f4f7f8;border:1px solid #e3ddd4;border-radius:12px}
.pa-help h3{margin:0 0 8px}
.pa-help p{margin:0;color:#5b6b73;line-height:1.6}
.pa-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(16,35,51,.55)}
.pa-modal[hidden]{display:none}
.pa-modal-card{width:100%;max-width:520px;background:#fff;border-radius:14px;box-shadow:0 28px 70px rgba(16,35,51,.32);overflow:hidden}
.pa-modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 24px 0}
.pa-modal-head h2{margin:0;font-size:1.2rem;color:#1f2933}
.pa-modal-close{background:none;border:none;font-size:1.5rem;line-height:1;cursor:pointer;color:#6a6259}
.pa-modal-form{padding:16px 24px 24px}
.pa-modal-total{padding:14px 16px;margin-bottom:16px;border-radius:10px;background:#f4f7f8;border:1px solid #e3ddd4}
.pa-modal-total span{display:block;font-size:.78rem;color:#6a6259}
.pa-modal-total strong{display:block;margin:4px 0;font-size:1.5rem;color:#1f2933;font-variant-numeric:tabular-nums}
.pa-modal-total small{color:#6a6259}
.pa-field{margin-bottom:14px}
.pa-field label{display:block;margin-bottom:5px;font-weight:600;color:#1f2933}
.pa-field input,.pa-field textarea{width:100%;box-sizing:border-box}
.pa-field small{display:block;margin-top:4px;color:#8b98a1;font-size:.76rem}
.pa-modal-submit{width:100%}
@media(max-width:1000px){.pa-summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:850px){.pa-summary{grid-template-columns:repeat(2,1fr)}.pa-filter{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.pa-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.pa-hero h1{font-size:1.75rem}.pa-summary{grid-template-columns:1fr 1fr;gap:8px}.pa-filter{grid-template-columns:1fr}.pa-action-panel{align-items:flex-start;flex-direction:column}}
@media print{
    .no-print,.pa-hero-actions,.pa-filter,.pa-help,.flash{display:none!important}
    .pa-hero{background:#1f2933!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:none}
    .pa-table{min-width:0}
    .table-container{overflow:visible}
}
</style>

<?php if ($waitingForTransfer > 0 && $canApprove && !$isFuturePeriod): ?>
<script>
(function () {
    var modal = document.getElementById('paTransferModal');
    var openBtn = document.getElementById('paOpenTransfer');
    var closeBtn = document.getElementById('paCloseTransfer');
    if (!modal || !openBtn || !closeBtn) return;

    function openModal() {
        modal.hidden = false;
        var first = modal.querySelector('input[name="bank_reference"]');
        if (first) first.focus();
    }
    function closeModal() { modal.hidden = true; }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('mousedown', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
