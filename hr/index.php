<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');
ensure_employee_exit_clearance_schema();

$pageTitle = "HR Overview";
$today = date('Y-m-d');
$month = (int) date('m');
$year = (int) date('Y');

$paidLeaveSql = attendance_paid_leave_predicate('a', 'e');
$stats = [
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 1")->fetchColumn(),
    'inactive' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 0")->fetchColumn(),
    'present' => (int) $pdo->query("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.attendance_date = " . $pdo->quote($today) . " AND (a.status = 'present' OR $paidLeaveSql) AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn(),
    'pending_leave' => (int) $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn(),
    'pending_payroll' => (int) $pdo->query("SELECT COUNT(*) FROM payroll WHERE month = {$month} AND year = {$year} AND payment_status = 'pending'")->fetchColumn(),
    'payroll_total' => (float) $pdo->query("SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE month = {$month} AND year = {$year}")->fetchColumn(),
];

$openExits = $pdo->query("SELECT c.id, c.employee_id, c.last_working_day, c.status, e.name AS employee_name, e.employee_code, approver.name AS approved_by_name FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users approver ON approver.id = c.approved_by WHERE c.status IN ('pending', 'approved') ORDER BY c.last_working_day, e.name")->fetchAll();
$loanBalances = [];
if ($pdo->query("SHOW TABLES LIKE 'employee_loans'")->fetchColumn()) {
    $loanRows = $pdo->query("SELECT employee_id, SUM(remaining_balance) AS balance FROM employee_loans WHERE approval_status = 'approved' AND remaining_balance > 0.009 GROUP BY employee_id")->fetchAll();
    foreach ($loanRows as $loanRow) {
        $loanBalances[(int) $loanRow['employee_id']] = (float) $loanRow['balance'];
    }
}
$unpaidPayroll = [];
$payrollRows = $pdo->query("SELECT employee_id, COUNT(*) AS item_count, SUM(net_salary) AS amount FROM payroll WHERE LOWER(COALESCE(payment_status, 'pending')) <> 'paid' AND net_salary > 0 GROUP BY employee_id")->fetchAll();
foreach ($payrollRows as $payrollRow) {
    $unpaidPayroll[(int) $payrollRow['employee_id']] = [
        'count' => (int) $payrollRow['item_count'],
        'amount' => (float) $payrollRow['amount'],
    ];
}

$stats['pending_exit_approval'] = 0;
$stats['approved_exits'] = 0;
$stats['exit_holds'] = 0;
foreach ($openExits as &$exit) {
    $exit['loan_balance'] = $loanBalances[(int) $exit['employee_id']] ?? 0.0;
    $exit['unpaid_payroll'] = $unpaidPayroll[(int) $exit['employee_id']] ?? ['count' => 0, 'amount' => 0.0];
    $exit['has_financial_hold'] = $exit['loan_balance'] > 0.009 || $exit['unpaid_payroll']['count'] > 0;
    if ($exit['status'] === 'pending') {
        $stats['pending_exit_approval']++;
    } else {
        $stats['approved_exits']++;
    }
    if ($exit['has_financial_hold']) {
        $stats['exit_holds']++;
    }
}
unset($exit);
$openExits = array_slice($openExits, 0, 8);

$departments = $pdo->query("SELECT COALESCE(NULLIF(department, ''), 'Unassigned') AS department, COUNT(*) AS total FROM employees WHERE status = 1 GROUP BY department ORDER BY total DESC, department")->fetchAll();
$pendingLeaves = $pdo->query("SELECT lr.*, e.name AS employee_name FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE lr.status = 'pending' ORDER BY lr.from_date LIMIT 6")->fetchAll();
$attendanceSnapshot = $pdo->prepare("SELECT COALESCE(SUM(a.status = 'present' OR $paidLeaveSql), 0) AS present, COALESCE(SUM(a.status = 'absent'), 0) AS absent, COALESCE(SUM(a.status = 'half-day'), 0) AS half_day, COALESCE(SUM(a.status = 'leave'), 0) AS leave_count, COALESCE(SUM(a.status = 'late'), 0) AS late_count, COALESCE(SUM(a.id IS NULL), 0) AS unmarked FROM employees e LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = ? WHERE e.status = 1 AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?)");
$attendanceSnapshot->execute([$today, $today]);
$attendanceSnapshot = $attendanceSnapshot->fetch() ?: ['present' => 0, 'absent' => 0, 'half_day' => 0, 'leave_count' => 0, 'late_count' => 0, 'unmarked' => 0];
$attendanceEligible = (int) $attendanceSnapshot['present'] + (int) $attendanceSnapshot['absent']
    + (int) $attendanceSnapshot['half_day'] + (int) $attendanceSnapshot['leave_count']
    + (int) $attendanceSnapshot['late_count']
    + (int) $attendanceSnapshot['unmarked'];

// Working-day reminder when the daily register has not been completed.
$attendanceReminder = null;
if (is_working_day($today)) {
    $attendanceReminder = [
        'missing_count' => (int) ($attendanceSnapshot['unmarked'] ?? 0),
        'active_count' => $attendanceEligible,
    ];
}

require_once "../partials/header.php";
?>

<div class="hr-hero">
    <div>
        <h1>HR Overview</h1>
        <p style="color:#64748b;margin:6px 0 0;">Workforce health, attendance, leave approvals, and payroll readiness.</p>
    </div>
    <div class="header-actions">
        <a href="employee_form.php" class="btn btn-accent">+ Add employee</a>
        <a href="attendance.php" class="btn btn-secondary">Record attendance</a>
    </div>
</div>

<?php if ($attendanceReminder && $attendanceReminder['missing_count'] > 0): ?>
<div style="margin:0 0 18px;padding:14px 18px;border-left:5px solid #f59e0b;background:#fffbeb;border-radius:10px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
    <div>
        <strong>⏰ Attendance is not fully recorded for today.</strong>
        <div style="color:#92400e;font-size:.9rem;margin-top:4px;">
            <?= number_format($attendanceReminder['missing_count']) ?> of <?= number_format($attendanceReminder['active_count']) ?> active employees have no record for <?= e(format_date($today)) ?>.
        </div>
    </div>
    <a href="attendance.php?date=<?= e($today) ?>" class="btn btn-accent">Record attendance</a>
</div>
<?php endif; ?>

<div class="hr-kpis">
    <div class="hr-kpi"><span class="hr-kpi-icon">👥</span><div><div class="hr-kpi-value"><?= number_format($stats['active']) ?></div><div class="hr-kpi-label">Active employees</div></div></div>
    <div class="hr-kpi"><span class="hr-kpi-icon">✓</span><div><div class="hr-kpi-value"><?= number_format($stats['present']) ?></div><div class="hr-kpi-label">Present today</div></div></div>
    <div class="hr-kpi"><span class="hr-kpi-icon">🏖</span><div><div class="hr-kpi-value"><?= number_format($stats['pending_leave']) ?></div><div class="hr-kpi-label">Pending leave requests</div></div></div>
    <div class="hr-kpi"><span class="hr-kpi-icon">$</span><div><div class="hr-kpi-value"><?= e(currency_symbol()) . money($stats['payroll_total']) ?></div><div class="hr-kpi-label">Current month payroll</div></div></div>
    <div class="hr-kpi hr-kpi-exit"><span class="hr-kpi-icon">↗</span><div><div class="hr-kpi-value"><?= number_format($stats['pending_exit_approval']) ?></div><div class="hr-kpi-label">Exits awaiting approval</div></div></div>
    <div class="hr-kpi hr-kpi-exit"><span class="hr-kpi-icon">✓</span><div><div class="hr-kpi-value"><?= number_format($stats['approved_exits']) ?></div><div class="hr-kpi-label">Approved exits in progress</div></div></div>
    <div class="hr-kpi hr-kpi-hold"><span class="hr-kpi-icon">!</span><div><div class="hr-kpi-value"><?= number_format($stats['exit_holds']) ?></div><div class="hr-kpi-label">Exits with financial holds</div></div></div>
</div>

<div class="hr-grid">
    <div class="panel hr-panel">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <h3>Department headcount</h3>
            <a href="employees.php" class="btn-sm">View employees</a>
        </div>
        <?php if (!$departments): ?><p>No employees have been added yet.</p><?php endif; ?>
        <?php foreach ($departments as $department): ?>
        <div class="hr-department">
            <span><i></i><?= e($department['department']) ?></span><strong><?= number_format((int) $department['total']) ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="panel hr-panel hr-actions-panel">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <h3>HR actions</h3>
            <span class="badge <?= $stats['pending_payroll'] ? 'badge-warning' : 'badge-success' ?>"><?= number_format($stats['pending_payroll']) ?> payroll pending</span>
        </div>
        <div class="header-actions hr-action-buttons" style="margin-top:16px;">
            <a href="payroll.php" class="btn btn-primary">Open payroll</a>
            <a href="leaves.php" class="btn btn-secondary">Review leave</a>
            <a href="attendance.php" class="btn btn-secondary">Attendance register</a>
            <a href="attendance_report.php" class="btn btn-secondary">Attendance report</a>
            <a href="attendance_warnings.php" class="btn btn-secondary">⚠ <?= e(attendance_warning_text('menu')) ?></a>
            <a href="attendance_submit.php" class="btn btn-secondary">Submit attendance to finance</a>
        </div>
        <p style="color:#64748b;margin:20px 0 0;">Today: <?= e(date('d M Y')) ?>. Active staff: <?= number_format($stats['active']) ?>.</p>
    </div>
</div>

<div class="panel hr-exits-panel">
    <div class="hr-panel-heading">
        <div>
            <h3>Employee exits</h3>
            <p>Approval, last working day, and financial clearance status</p>
        </div>
        <a href="exit_clearance.php" class="btn btn-secondary">Open exit clearance</a>
    </div>
    <div class="table-container" style="margin-top:14px;">
        <table class="data-table">
            <thead><tr><th>Employee</th><th>Last working day</th><th>Workflow</th><th>Financial clearance</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (!$openExits): ?><tr><td colspan="5">No open employee exit requests.</td></tr><?php endif; ?>
                <?php foreach ($openExits as $exit): ?>
                <tr>
                    <td><?= e($exit['employee_name']) ?> (<?= e($exit['employee_code']) ?>)</td>
                    <td><?= e(date('d M Y', strtotime($exit['last_working_day']))) ?></td>
                    <td><span class="hr-exit-status <?= e($exit['status']) ?>"><?= $exit['status'] === 'pending' ? 'Awaiting approval' : 'Approved' ?></span><?php if ($exit['status'] === 'approved' && $exit['approved_by_name']): ?><small class="hr-exit-approver">by <?= e($exit['approved_by_name']) ?></small><?php endif; ?></td>
                    <td>
                        <?php if ($exit['has_financial_hold']): ?>
                        <span class="hr-hold-text">On hold</span>
                        <?php if ($exit['loan_balance'] > 0.009): ?><small class="hr-exit-approver">Loan: <?= e(currency_symbol()) . number_format($exit['loan_balance'], 2) ?></small><?php endif; ?>
                        <?php if ($exit['unpaid_payroll']['count'] > 0): ?><small class="hr-exit-approver">Unpaid payroll: <?= e(currency_symbol()) . number_format($exit['unpaid_payroll']['amount'], 2) ?></small><?php endif; ?>
                        <?php else: ?>
                        <span class="hr-clear-text">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="exit_clearance.php?clearance_id=<?= (int) $exit['id'] ?>" class="btn-sm">Review</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel hr-attendance-panel">
    <div class="hr-panel-heading">
        <div>
            <h3>Today’s attendance snapshot</h3>
            <p><?= e(date('d M Y')) ?> · quick view of the daily register</p>
        </div>
        <a href="attendance.php" class="btn btn-accent">Complete register</a>
    </div>
    <div class="hr-attendance-grid">
        <div class="hr-attendance-item present"><strong><?= number_format((int) $attendanceSnapshot['present']) ?></strong><span>Present</span></div>
        <div class="hr-attendance-item absent"><strong><?= number_format((int) $attendanceSnapshot['absent']) ?></strong><span>Absent</span></div>
        <div class="hr-attendance-item half"><strong><?= number_format((int) $attendanceSnapshot['half_day']) ?></strong><span>Half-day</span></div>
        <div class="hr-attendance-item leave"><strong><?= number_format((int) $attendanceSnapshot['leave_count']) ?></strong><span>On leave</span></div>
        <div class="hr-attendance-item late"><strong><?= number_format((int) $attendanceSnapshot['late_count']) ?></strong><span>Late</span></div>
        <div class="hr-attendance-item unmarked"><strong><?= number_format((int) $attendanceSnapshot['unmarked']) ?></strong><span>Not marked</span></div>
    </div>
    <p style="margin:12px 0 0;color:#6a6259;font-size:.8rem;">Approved paid leave (annual, sick, casual, emergency) is counted as present. “On leave” still lists every leave day for reference.</p>
</div>

<div class="panel hr-leave-panel" style="margin-top:20px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <h3>Pending leave approvals</h3>
        <a href="leaves.php" class="btn-sm">Open leave management</a>
    </div>
    <div class="table-container" style="margin-top:14px;">
        <table class="data-table">
            <thead><tr><th>Employee</th><th>Leave type</th><th>Dates</th><th>Days</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (!$pendingLeaves): ?><tr><td colspan="5">No pending leave requests.</td></tr><?php endif; ?>
                <?php foreach ($pendingLeaves as $leave): ?>
                <tr>
                    <td><?= e($leave['employee_name']) ?></td>
                    <td><?= e($leave['leave_type']) ?></td>
                    <td><?= e(date('d M Y', strtotime($leave['from_date']))) ?> - <?= e(date('d M Y', strtotime($leave['to_date']))) ?></td>
                    <td><?= (int) $leave['total_days'] ?></td>
                    <td><a href="leaves.php" class="btn-sm">Review</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.hr-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:22px;margin-bottom:24px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 64%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.18)}
.hr-hero h1{margin:0;color:#fff;font-size:2.1rem}.hr-hero p{margin:6px 0 0;color:rgba(255,255,255,.76)!important}.hr-hero .header-actions{justify-content:flex-end}.hr-hero .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);box-shadow:none}.hr-hero .btn-accent{background:#e8a33d!important;border-color:#e8a33d}
.hr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}.hr-kpi{display:flex;align-items:center;gap:14px;padding:19px;background:#fff;border:1px solid #e3ddd4;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);border-top:4px solid #d9742f}.hr-kpi-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;background:#eaf5f1;color:#b8481b;font-size:1.3rem}.hr-kpi-value{font-size:1.45rem;font-weight:800;color:#1f2933;line-height:1.2}.hr-kpi-label{font-size:.78rem;color:#6a6259;margin-top:3px}
.hr-kpi-exit{border-top-color:#e8a33d}.hr-kpi-exit .hr-kpi-icon{background:#fff0ea;color:#a8432d}.hr-kpi-hold{border-top-color:#d97706}.hr-kpi-hold .hr-kpi-icon{background:#fff5e5;color:#9a5a00}.hr-exits-panel{margin:0 0 20px;border-top:4px solid #e8a33d}.hr-exit-status{display:inline-block;padding:4px 8px;border-radius:4px;font-size:.78rem;font-weight:700}.hr-exit-status.pending{background:#fff3d6;color:#845500}.hr-exit-status.approved{background:#e5f5eb;color:#17643a}.hr-exit-approver{display:block;margin-top:4px;color:#64748b}.hr-hold-text{color:#a8432d;font-weight:700}.hr-clear-text{color:#17643a;font-weight:700}
.hr-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.hr-panel{margin-bottom:0}.hr-panel h3{margin-bottom:16px}.hr-department{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--bp-border)}.hr-department:last-child{border-bottom:0}.hr-department span{display:flex;align-items:center;gap:9px}.hr-department i{width:8px;height:8px;border-radius:50%;background:#e8a33d;display:inline-block}.hr-department strong{color:#b8481b}.hr-actions-panel{background:linear-gradient(145deg,#fff,#f1f8f5)}.hr-action-buttons{margin-top:16px}.hr-leave-panel{border-top:4px solid #e8a33d}
.hr-attendance-panel{margin-top:20px;border-top:4px solid #d9742f}.hr-panel-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.hr-panel-heading h3{margin:0}.hr-panel-heading p{margin:4px 0 0;color:#6a6259;font-size:.82rem}.hr-attendance-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:18px}.hr-attendance-item{padding:14px 12px;border-radius:10px;background:#f5f8f7;border:1px solid var(--bp-border);text-align:center}.hr-attendance-item strong{display:block;font-size:1.45rem;color:#1f2933}.hr-attendance-item span{font-size:.78rem;color:#6a6259}.hr-attendance-item.present{border-top:3px solid #d9742f}.hr-attendance-item.absent{border-top:3px solid #e8a33d}.hr-attendance-item.half{border-top:3px solid #d97706}.hr-attendance-item.leave{border-top:3px solid #7c5cbf}.hr-attendance-item.late{border-top:3px solid #ea580c}.hr-attendance-item.unmarked{border-top:3px solid #94a3b8}
@media(max-width:850px){.hr-hero{align-items:flex-start;flex-direction:column}.hr-hero .header-actions{justify-content:flex-start}.hr-grid{grid-template-columns:1fr}}
@media(max-width:700px){.hr-attendance-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:500px){.hr-hero{padding:24px 20px}.hr-hero h1{font-size:1.75rem}.hr-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.hr-kpi{padding:14px;gap:9px}.hr-kpi-icon{width:38px;height:38px;font-size:1rem}.hr-kpi-value{font-size:1.15rem}.hr-attendance-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<?php require_once "../partials/footer.php"; ?>
