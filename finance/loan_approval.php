<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

ensure_notifications_schema();

// The loan table is normally created by HR first, but finance must not break on a fresh database.
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    loan_amount DECIMAL(10,2) NOT NULL,
    duration_months INT NOT NULL,
    monthly_payment DECIMAL(10,2) NOT NULL,
    purpose TEXT,
    request_date DATE NOT NULL,
    approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    approval_date DATE,
    start_deduction_date DATE,
    end_deduction_date DATE,
    remaining_balance DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
)");
$loanColumnNames = array_column($pdo->query("SHOW COLUMNS FROM employee_loans")->fetchAll(), 'Field');
if (!in_array('rejection_reason', $loanColumnNames, true)) {
    $pdo->exec("ALTER TABLE employee_loans ADD COLUMN rejection_reason VARCHAR(255) NULL");
}

$canApprove = has_permission('finance', 'approve');

/* ---------------------------------------------------------------
   Approve / reject a pending loan request
   --------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_loan']) || isset($_POST['reject_loan']))) {
    require_permission('finance', 'approve');

    $action = isset($_POST['approve_loan']) ? 'approve' : 'reject';
    $loanId = (int) ($_POST['loan_id'] ?? 0);
    $isValid = true;

    if ($loanId <= 0) {
        set_flash('error', 'Choose a loan request to review.');
        $isValid = false;
    }

    $loan = null;
    if ($isValid) {
        $stmt = $pdo->prepare("SELECT el.*, e.name AS employee_name, e.status AS employee_status
            FROM employee_loans el
            JOIN employees e ON el.employee_id = e.id
            WHERE el.id = ?");
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            set_flash('error', 'That loan request no longer exists.');
            $isValid = false;
        } elseif ($loan['approval_status'] !== 'pending') {
            set_flash('error', 'That loan request is already ' . $loan['approval_status'] . '. Only pending requests can be reviewed.');
            $isValid = false;
        } elseif ((int) $loan['employee_status'] !== 1) {
            set_flash('error', 'The employee for this loan is inactive, so the request cannot be approved or rejected.');
            $isValid = false;
        }
    }

    if ($isValid && $action === 'reject') {
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        if ($reason === '') {
            set_flash('error', 'A rejection reason is required so HR knows what to correct.');
            $isValid = false;
        } elseif (mb_strlen($reason) > 255) {
            set_flash('error', 'The rejection reason must be 255 characters or fewer.');
            $isValid = false;
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
            set_flash('error', 'The rejection reason contains characters that are not allowed.');
            $isValid = false;
        }
    }

    if ($isValid) {
        try {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                $duration = max(1, (int) $loan['duration_months']);
                // Deductions start with next month's payroll and run for exactly $duration months.
                $startTs = mktime(0, 0, 0, (int) date('n') + 1, 1, (int) date('Y'));
                $startDate = date('Y-m-01', $startTs);
                $endDate = date('Y-m-01', strtotime($startDate . ' +' . ($duration - 1) . ' months'));

                $update = $pdo->prepare("UPDATE employee_loans
                    SET approval_status = 'approved', approved_by = ?, approval_date = CURDATE(),
                        start_deduction_date = ?, end_deduction_date = ?, rejection_reason = NULL
                    WHERE id = ? AND approval_status = 'pending'");
                $update->execute([(int) $_SESSION['user_id'], $startDate, $endDate, $loanId]);
            } else {
                $update = $pdo->prepare("UPDATE employee_loans
                    SET approval_status = 'rejected', approved_by = ?, approval_date = CURDATE(),
                        rejection_reason = ?
                    WHERE id = ? AND approval_status = 'pending'");
                $update->execute([(int) $_SESSION['user_id'], $reason, $loanId]);
            }

            if ($update->rowCount() === 0) {
                $pdo->rollBack();
                set_flash('error', 'That loan request was already reviewed by someone else. Refresh to see its current status.');
            } else {
                $pdo->commit();

                $employeeName = (string) $loan['employee_name'];
                if ($action === 'approve') {
                    $startLabel = format_app_month_year((int) date('n', $startTs), (int) date('Y', $startTs));
                    $message = "Loan for {$employeeName} approved by finance. Deductions of "
                        . currency_symbol() . number_format((float) $loan['monthly_payment'], 2)
                        . "/month start {$startLabel} for {$duration} month" . ($duration === 1 ? '' : 's') . '.';
                    notify_role(['admin', 'hr'], mb_substr($message, 0, 255), '/block_plant/hr/loans.php');
                    log_activity('approve', 'finance', $loanId, "Approved loan #{$loanId} for {$employeeName}: {$startDate} to {$endDate}");
                    set_flash('success', "Loan for {$employeeName} approved. Deductions start {$startLabel} and run for {$duration} month" . ($duration === 1 ? '' : 's') . '.');
                } else {
                    $message = "Loan for {$employeeName} was rejected by finance. Reason: {$reason}";
                    notify_role(['admin', 'hr'], mb_substr($message, 0, 255), '/block_plant/hr/loans.php');
                    log_activity('reject', 'finance', $loanId, "Rejected loan #{$loanId} for {$employeeName}: {$reason}");
                    set_flash('success', "Loan request for {$employeeName} rejected. HR has been notified.");
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('loan_approval: ' . $ex->getMessage());
            set_flash('error', 'The loan request could not be updated. Nothing was changed. Please try again.');
        }
    }

    header('Location: loan_approval.php');
    exit;
}

/* ---------------------------------------------------------------
   Load the pipeline
   --------------------------------------------------------------- */
try {
    $pendingLoans = $pdo->query("SELECT el.*, e.name AS employee_name, e.employee_code, e.basic_salary,
            e.status AS employee_status, e.designation, e.department
        FROM employee_loans el
        JOIN employees e ON el.employee_id = e.id
        WHERE el.approval_status = 'pending'
        ORDER BY el.request_date ASC, el.id ASC")->fetchAll();
} catch (PDOException $ex) {
    $pendingLoans = [];
}

try {
    $approvedLoans = $pdo->query("SELECT el.*, e.name AS employee_name, e.employee_code, e.status AS employee_status,
            u.name AS approved_by_name
        FROM employee_loans el
        JOIN employees e ON el.employee_id = e.id
        LEFT JOIN users u ON el.approved_by = u.id
        WHERE el.approval_status = 'approved'
        ORDER BY el.approval_date DESC, el.id DESC
        LIMIT 100")->fetchAll();
} catch (PDOException $ex) {
    $approvedLoans = [];
}

try {
    $rejectedLoans = $pdo->query("SELECT el.*, e.name AS employee_name, e.employee_code,
            u.name AS reviewed_by_name
        FROM employee_loans el
        JOIN employees e ON el.employee_id = e.id
        LEFT JOIN users u ON el.approved_by = u.id
        WHERE el.approval_status = 'rejected'
        ORDER BY el.approval_date DESC, el.id DESC
        LIMIT 50")->fetchAll();
} catch (PDOException $ex) {
    $rejectedLoans = [];
}

$pendingAmount = 0.0;
foreach ($pendingLoans as $loan) {
    $pendingAmount += (float) $loan['loan_amount'];
}

$outstandingAmount = 0.0;
$monthlyExposure = 0.0;
foreach ($approvedLoans as $loan) {
    $balance = (float) $loan['remaining_balance'];
    $outstandingAmount += $balance;
    if ($balance > 0.009) {
        $monthlyExposure += (float) $loan['monthly_payment'];
    }
}

$flash = get_flash();
$pageTitle = "Loan Approval";
require_once "../partials/header.php";
?>

<div class="la-hero">
    <div>
        <span class="la-eyebrow">FINANCE · EMPLOYEE LOANS</span>
        <h1>💰 Loan Approvals</h1>
        <p>Review employee loan requests. Approved loans are deducted automatically from payroll starting next month.</p>
    </div>
    <div class="la-hero-actions no-print">
        <a href="/block_plant/hr/loan_repayments.php" class="btn btn-secondary"><?= e(t('loan_repayment_history')) ?></a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<div class="la-summary">
    <div><span>Pending requests</span><strong class="<?= count($pendingLoans) > 0 ? 'la-warn' : 'la-ok' ?>"><?= number_format(count($pendingLoans)) ?></strong></div>
    <div><span>Requested amount</span><strong><?= e(currency_symbol()) . number_format($pendingAmount, 2) ?></strong></div>
    <div><span>Approved outstanding</span><strong><?= e(currency_symbol()) . number_format($outstandingAmount, 2) ?></strong></div>
    <div><span>Monthly deduction</span><strong><?= e(currency_symbol()) . number_format($monthlyExposure, 2) ?></strong></div>
</div>

<?php if (!$canApprove): ?>
<div class="flash">You can review loan requests here, but approving or rejecting them requires the <strong>finance approve</strong> permission.</div>
<?php endif; ?>

<div class="panel la-panel">
    <div class="la-panel-head">
        <h3>Pending loan requests</h3>
        <span class="la-count"><?= number_format(count($pendingLoans)) ?></span>
    </div>
    <div class="table-container">
        <table class="data-table la-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="la-num">Loan amount</th>
                    <th class="la-num">Duration</th>
                    <th class="la-num">Monthly payment</th>
                    <th>Purpose</th>
                    <th>Requested</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pendingLoans): ?>
                <tr><td colspan="7" class="la-empty">No pending loan requests.</td></tr>
            <?php endif; ?>
            <?php foreach ($pendingLoans as $loan): ?>
                <?php $employeeActive = (int) $loan['employee_status'] === 1; ?>
                <tr>
                    <td>
                        <strong><?= e($loan['employee_name']) ?></strong>
                        <?php if (!empty($loan['employee_code'])): ?>
                        <small class="la-code"><?= e($loan['employee_code']) ?></small>
                        <?php endif; ?>
                        <?php if (!$employeeActive): ?>
                        <div class="la-warn la-small">Employee inactive</div>
                        <?php endif; ?>
                    </td>
                    <td class="la-num"><strong><?= e(currency_symbol()) . number_format((float) $loan['loan_amount'], 2) ?></strong></td>
                    <td class="la-num"><?= (int) $loan['duration_months'] ?> month<?= (int) $loan['duration_months'] === 1 ? '' : 's' ?></td>
                    <td class="la-num"><?= e(currency_symbol()) . number_format((float) $loan['monthly_payment'], 2) ?></td>
                    <td><?= e($loan['purpose'] ?: '—') ?></td>
                    <td><?= $loan['request_date'] ? e(format_app_date($loan['request_date'])) : '—' ?></td>
                    <td class="no-print">
                        <div class="la-actions">
                            <?php if (has_permission('hr', 'view')): ?>
                            <a href="/block_plant/hr/loan_request_form.php?loan_id=<?= (int) $loan['id'] ?>" target="_blank" class="btn-sm la-btn-neutral">📄 Form</a>
                            <?php endif; ?>
                            <?php if ($canApprove && $employeeActive): ?>
                            <button type="button" class="btn-sm la-btn-approve la-approve"
                                    data-loan-id="<?= (int) $loan['id'] ?>"
                                    data-employee="<?= e($loan['employee_name']) ?>"
                                    data-amount="<?= e(currency_symbol()) . number_format((float) $loan['loan_amount'], 2) ?>"
                                    data-monthly="<?= e(currency_symbol()) . number_format((float) $loan['monthly_payment'], 2) ?>"
                                    data-duration="<?= (int) $loan['duration_months'] ?>">Approve</button>
                            <button type="button" class="btn-sm la-btn-reject la-reject"
                                    data-loan-id="<?= (int) $loan['id'] ?>"
                                    data-employee="<?= e($loan['employee_name']) ?>">Reject</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel la-panel">
    <div class="la-panel-head">
        <h3>Approved loans</h3>
        <span class="la-count"><?= number_format(count($approvedLoans)) ?> most recent</span>
    </div>
    <div class="table-container">
        <table class="data-table la-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="la-num">Loan amount</th>
                    <th class="la-num">Monthly</th>
                    <th class="la-num">Remaining</th>
                    <th>Deduction window</th>
                    <th>Approved by</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$approvedLoans): ?>
                <tr><td colspan="6" class="la-empty">No approved loans yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($approvedLoans as $loan): ?>
                <?php
                $balance = (float) $loan['remaining_balance'];
                $repaidPct = (float) $loan['loan_amount'] > 0
                    ? min(100, max(0, round(((float) $loan['loan_amount'] - $balance) / (float) $loan['loan_amount'] * 100)))
                    : 0;
                ?>
                <tr>
                    <td>
                        <strong><?= e($loan['employee_name']) ?></strong>
                        <?php if (!empty($loan['employee_code'])): ?>
                        <small class="la-code"><?= e($loan['employee_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="la-num"><?= e(currency_symbol()) . number_format((float) $loan['loan_amount'], 2) ?></td>
                    <td class="la-num"><?= e(currency_symbol()) . number_format((float) $loan['monthly_payment'], 2) ?></td>
                    <td class="la-num">
                        <strong class="<?= $balance > 0.009 ? 'la-warn' : 'la-ok' ?>"><?= e(currency_symbol()) . number_format($balance, 2) ?></strong>
                        <div class="la-progress" title="<?= $repaidPct ?>% repaid"><span style="width:<?= $repaidPct ?>%"></span></div>
                    </td>
                    <td>
                        <?php if (!empty($loan['start_deduction_date']) || !empty($loan['end_deduction_date'])): ?>
                            <?= $loan['start_deduction_date'] ? e(format_app_date($loan['start_deduction_date'])) : '?' ?>
                            →
                            <?= $loan['end_deduction_date'] ? e(format_app_date($loan['end_deduction_date'])) : '?' ?>
                        <?php else: ?>
                            <span class="la-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($loan['approved_by_name'] ?: '—') ?>
                        <?php if (!empty($loan['approval_date'])): ?>
                        <div class="la-small la-muted"><?= e(format_app_date($loan['approval_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($rejectedLoans): ?>
<div class="panel la-panel">
    <div class="la-panel-head">
        <h3>Rejected requests</h3>
        <span class="la-count"><?= number_format(count($rejectedLoans)) ?></span>
    </div>
    <div class="table-container">
        <table class="data-table la-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th class="la-num">Loan amount</th>
                    <th>Purpose</th>
                    <th>Reason</th>
                    <th>Reviewed by</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rejectedLoans as $loan): ?>
                <tr>
                    <td>
                        <strong><?= e($loan['employee_name']) ?></strong>
                        <?php if (!empty($loan['employee_code'])): ?>
                        <small class="la-code"><?= e($loan['employee_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="la-num"><?= e(currency_symbol()) . number_format((float) $loan['loan_amount'], 2) ?></td>
                    <td><?= e($loan['purpose'] ?: '—') ?></td>
                    <td><?= e($loan['rejection_reason'] ?: '—') ?></td>
                    <td>
                        <?= e($loan['reviewed_by_name'] ?: '—') ?>
                        <?php if (!empty($loan['approval_date'])): ?>
                        <div class="la-small la-muted"><?= e(format_app_date($loan['approval_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="la-help no-print">
    <h3>How this works</h3>
    <p>HR submits loan requests from <a href="/block_plant/hr/loans.php">HR → Loans</a> (maximum 2 months' salary, 1–12 months).
        Approving a request schedules equal monthly deductions from next month's payroll until the balance reaches zero.
        Rejecting it sends the request back to HR with your reason.</p>
</div>

<?php if ($canApprove): ?>
<div id="laApproveModal" class="la-modal" hidden>
    <div class="la-modal-card" role="dialog" aria-modal="true" aria-labelledby="laApproveTitle">
        <div class="la-modal-head">
            <h2 id="laApproveTitle">Approve loan</h2>
            <button type="button" class="la-modal-close" data-close="laApproveModal" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="la-modal-form">
            <input type="hidden" name="approve_loan" value="1">
            <input type="hidden" name="loan_id" id="laApproveLoanId" value="">
            <div class="la-modal-summary">
                <div><span>Employee</span><strong id="laApproveEmployee">—</strong></div>
                <div><span>Loan amount</span><strong id="laApproveAmount">—</strong></div>
                <div><span>Monthly deduction</span><strong id="laApproveMonthly">—</strong></div>
                <div><span>Duration</span><strong id="laApproveDuration">—</strong></div>
            </div>
            <p class="la-modal-note">⚡ Deductions start automatically with next month's payroll and stop once the balance is repaid.</p>
            <button type="submit" class="btn btn-primary la-modal-submit">Approve loan</button>
        </form>
    </div>
</div>

<div id="laRejectModal" class="la-modal" hidden>
    <div class="la-modal-card" role="dialog" aria-modal="true" aria-labelledby="laRejectTitle">
        <div class="la-modal-head">
            <h2 id="laRejectTitle">Reject loan</h2>
            <button type="button" class="la-modal-close" data-close="laRejectModal" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="la-modal-form">
            <input type="hidden" name="reject_loan" value="1">
            <input type="hidden" name="loan_id" id="laRejectLoanId" value="">
            <p class="la-modal-note">Rejecting <strong id="laRejectEmployee">this request</strong> sends it back to HR. The reason is shown to HR and recorded in the audit log.</p>
            <div class="la-field">
                <label for="rejection_reason">Rejection reason</label>
                <textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="255" required
                          placeholder="Explain why this request cannot be approved"></textarea>
            </div>
            <button type="submit" class="btn btn-danger la-modal-submit">Reject loan</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.la-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#183b56,#246a73 65%,#2a9d8f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.la-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.la-hero p{margin:0;color:rgba(255,255,255,.76)}
.la-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.la-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.la-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.la-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.la-summary div{padding:15px 17px;background:#fff;border:1px solid #dce7e5;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.la-summary span{display:block;font-size:.76rem;color:#667a80}
.la-summary strong{display:block;margin-top:5px;font-size:1.28rem;color:#183b56;font-variant-numeric:tabular-nums}
.la-summary strong.la-ok{color:#1d7a4f}
.la-summary strong.la-warn{color:#a86a12}
.la-panel{margin-bottom:18px}
.la-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.la-panel-head h3{margin:0}
.la-count{font-size:.8rem;color:#667a80;font-weight:700}
.la-table th.la-num,.la-table td.la-num{text-align:right;font-variant-numeric:tabular-nums}
.la-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.btn-sm.la-btn-approve{background:#1d7a4f!important;color:#fff!important}
.btn-sm.la-btn-reject{background:#b3452f!important;color:#fff!important}
.btn-sm.la-btn-neutral{background:#eef3f5!important;color:#35505e!important}
.la-code{color:#8b98a1;font-size:.76rem;margin-left:4px}
.la-small{font-size:.75rem}
.la-muted{color:#8b98a1}
.la-warn{color:#b3452f;font-weight:700}
.la-ok{color:#1d7a4f}
.la-empty{text-align:center;padding:34px!important;color:#667a80}
.la-progress{height:4px;margin-top:5px;border-radius:99px;background:#e8eef0;overflow:hidden}
.la-progress span{display:block;height:100%;background:#2a9d8f}
.la-help{margin-top:4px;padding:20px;background:#f4f7f8;border:1px solid #dce7e5;border-radius:12px}
.la-help h3{margin:0 0 8px}
.la-help p{margin:0;color:#5b6b73;line-height:1.6}
.la-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(16,35,51,.55)}
.la-modal[hidden]{display:none}
.la-modal-card{width:100%;max-width:520px;background:#fff;border-radius:14px;box-shadow:0 28px 70px rgba(16,35,51,.32);overflow:hidden}
.la-modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:20px 24px 0}
.la-modal-head h2{margin:0;font-size:1.2rem;color:#183b56}
.la-modal-close{background:none;border:none;font-size:1.5rem;line-height:1;cursor:pointer;color:#667a80}
.la-modal-form{padding:16px 24px 24px}
.la-modal-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.la-modal-summary div{padding:12px 14px;border-radius:10px;background:#f4f7f8;border:1px solid #dce7e5}
.la-modal-summary span{display:block;font-size:.74rem;color:#667a80}
.la-modal-summary strong{display:block;margin-top:3px;color:#183b56;font-variant-numeric:tabular-nums}
.la-modal-note{margin:0 0 14px;padding:12px 14px;border-radius:10px;background:#eaf3f0;color:#2c5a52;font-size:.86rem;line-height:1.5}
.la-field{margin-bottom:14px}
.la-field label{display:block;margin-bottom:5px;font-weight:600;color:#183b56}
.la-field textarea{width:100%;box-sizing:border-box}
.la-modal-submit{width:100%}
@media(max-width:850px){.la-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.la-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.la-hero h1{font-size:1.75rem}.la-summary{grid-template-columns:1fr 1fr;gap:8px}.la-modal-summary{grid-template-columns:1fr}}
@media print{
    .no-print,.la-hero-actions,.la-help,.flash{display:none!important}
    .la-hero{background:#183b56!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:none}
    .la-table{min-width:0}
    .table-container{overflow:visible}
}
</style>

<?php if ($canApprove): ?>
<script>
(function () {
    var approveModal = document.getElementById('laApproveModal');
    var rejectModal = document.getElementById('laRejectModal');
    if (!approveModal || !rejectModal) return;

    function openModal(modal) { modal.hidden = false; }
    function closeModal(modal) { modal.hidden = true; }

    document.querySelectorAll('.la-approve').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('laApproveLoanId').value = button.getAttribute('data-loan-id') || '';
            document.getElementById('laApproveEmployee').textContent = button.getAttribute('data-employee') || '—';
            document.getElementById('laApproveAmount').textContent = button.getAttribute('data-amount') || '—';
            document.getElementById('laApproveMonthly').textContent = button.getAttribute('data-monthly') || '—';
            var duration = button.getAttribute('data-duration') || '';
            document.getElementById('laApproveDuration').textContent = duration ? duration + ' month' + (duration === '1' ? '' : 's') : '—';
            openModal(approveModal);
        });
    });

    document.querySelectorAll('.la-reject').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('laRejectLoanId').value = button.getAttribute('data-loan-id') || '';
            document.getElementById('laRejectEmployee').textContent = button.getAttribute('data-employee') || 'this request';
            openModal(rejectModal);
        });
    });

    document.querySelectorAll('.la-modal-close').forEach(function (button) {
        button.addEventListener('click', function () {
            var modal = document.getElementById(button.getAttribute('data-close'));
            if (modal) closeModal(modal);
        });
    });

    [approveModal, rejectModal].forEach(function (modal) {
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) closeModal(modal);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (!approveModal.hidden) closeModal(approveModal);
        if (!rejectModal.hidden) closeModal(rejectModal);
    });
})();
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
