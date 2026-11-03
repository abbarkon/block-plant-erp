<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_payroll_report_schema();

$pageTitle = t('payroll_reports');

/* ---------------------------------------------------------------- filters */
$month = (int) ($_GET['month'] ?? date('n'));
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');

$year = (int) ($_GET['year'] ?? date('Y'));
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$department = trim((string) ($_GET['department'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$statusMeta = [
    'draft'           => ['label' => 'Draft',           'badge' => 'badge-muted'],
    'pending_finance' => ['label' => 'Pending Finance', 'badge' => 'badge-warning'],
    'approved'        => ['label' => 'Approved',        'badge' => 'badge-info'],
    'rejected'        => ['label' => 'Rejected',        'badge' => 'badge-danger'],
    'transferred'     => ['label' => 'Transferred',     'badge' => 'badge-info'],
    'paid'            => ['label' => 'Paid',            'badge' => 'badge-success'],
];

/* ------------------------------------------------------ filter source data */
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE status = 1 AND department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$payrollYears = array_map('intval', $pdo->query("SELECT DISTINCT year FROM payroll ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN));
if (!in_array((int) date('Y'), $payrollYears, true)) {
    $payrollYears[] = (int) date('Y');
}
if (!in_array($year, $payrollYears, true)) {
    $payrollYears[] = $year;
}
rsort($payrollYears);

/* ---------------------------------------------------------- detail records */
$where = ['p.month = ?', 'p.year = ?'];
$params = [$month, $year];
if ($department !== '') {
    $where[] = 'e.department = ?';
    $params[] = $department;
}
if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.employee_code LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($statusFilter !== '' && isset($statusMeta[$statusFilter])) {
    $where[] = 'p.workflow_status = ?';
    $params[] = $statusFilter;
}

$detailStmt = $pdo->prepare(
    "SELECT p.id, p.employee_id, p.basic_salary, p.allowances, p.overtime_hours, p.overtime_payment,
            p.deductions, p.net_salary, p.payment_status, p.workflow_status, p.payment_date,
            p.transfer_date, p.bank_reference,
            e.employee_code, e.name AS employee_name, e.department, e.designation,
            u.name AS generated_by
     FROM payroll p
     JOIN employees e ON e.id = p.employee_id
     LEFT JOIN users u ON u.id = p.created_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY e.department ASC, e.name ASC"
);
$detailStmt->execute($params);
$rows = $detailStmt->fetchAll();

/* Loan recoveries actually applied through payroll for this period (memo).
   Manual repayments (source = 'manual') are not part of payroll deductions. */
$loanStmt = $pdo->prepare(
    "SELECT el.employee_id, SUM(lr.amount_deducted) AS loan_applied
     FROM loan_repayments lr
     JOIN employee_loans el ON el.id = lr.loan_id
     WHERE lr.payroll_month = ? AND lr.payroll_year = ? AND lr.source = 'payroll'
     GROUP BY el.employee_id"
);
$loanStmt->execute([$month, $year]);
$loanApplied = [];
foreach ($loanStmt->fetchAll() as $loanRow) {
    $loanApplied[(int) $loanRow['employee_id']] = (float) $loanRow['loan_applied'];
}

/* Group by department and build totals. */
$byDepartment = [];
$totals = ['employees' => 0, 'basic' => 0.0, 'allowances' => 0.0, 'overtime_hours' => 0.0, 'overtime' => 0.0, 'deductions' => 0.0, 'loan' => 0.0, 'net' => 0.0];
foreach ($rows as $row) {
    $dept = ($row['department'] !== null && $row['department'] !== '') ? $row['department'] : 'Unassigned';
    $byDepartment[$dept][] = $row;

    $totals['employees']++;
    $totals['basic'] += (float) $row['basic_salary'];
    $totals['allowances'] += (float) $row['allowances'];
    $totals['overtime_hours'] += (float) $row['overtime_hours'];
    $totals['overtime'] += (float) $row['overtime_payment'];
    $totals['deductions'] += (float) $row['deductions'];
    $totals['loan'] += $loanApplied[(int) $row['employee_id']] ?? 0.0;
    $totals['net'] += (float) $row['net_salary'];
}
$grossPay = $totals['basic'] + $totals['allowances'] + $totals['overtime'];

/* --------------------------------------------------- monthly summary (year) */
$sumWhere = ['p.year = ?'];
$sumParams = [$year];
if ($department !== '') {
    $sumWhere[] = 'e.department = ?';
    $sumParams[] = $department;
}
if ($search !== '') {
    $sumWhere[] = '(e.name LIKE ? OR e.employee_code LIKE ?)';
    $sumParams[] = '%' . $search . '%';
    $sumParams[] = '%' . $search . '%';
}

$sumStmt = $pdo->prepare(
    "SELECT p.month,
            COUNT(*) AS employees,
            SUM(p.basic_salary) AS basic,
            SUM(p.allowances) AS allowances,
            SUM(p.overtime_hours) AS overtime_hours,
            SUM(p.overtime_payment) AS overtime,
            SUM(p.deductions) AS deductions,
            SUM(p.net_salary) AS net,
            SUM(CASE WHEN p.workflow_status = 'paid' THEN 1 ELSE 0 END) AS paid_employees,
            GROUP_CONCAT(DISTINCT p.workflow_status ORDER BY p.workflow_status SEPARATOR ',') AS statuses
     FROM payroll p
     JOIN employees e ON e.id = p.employee_id
     WHERE " . implode(' AND ', $sumWhere) . "
     GROUP BY p.month
     ORDER BY p.month"
);
$sumStmt->execute($sumParams);
$monthlySummary = $sumStmt->fetchAll();

$yearTotals = ['employees' => 0, 'basic' => 0.0, 'allowances' => 0.0, 'overtime' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
foreach ($monthlySummary as $summaryRow) {
    $yearTotals['employees'] += (int) $summaryRow['employees'];
    $yearTotals['basic'] += (float) $summaryRow['basic'];
    $yearTotals['allowances'] += (float) $summaryRow['allowances'];
    $yearTotals['overtime'] += (float) $summaryRow['overtime'];
    $yearTotals['deductions'] += (float) $summaryRow['deductions'];
    $yearTotals['net'] += (float) $summaryRow['net'];
}

/* Attendance hand-off context for the selected period. */
$submission = get_attendance_submission($month, $year);
$attendanceApproved = $submission && $submission['status'] === 'approved';

$csvQuery = http_build_query([
    'type' => 'payroll',
    'month' => $month,
    'year' => $year,
    'department' => $department,
    'q' => $search,
    'status' => $statusFilter,
]);

require_once "../partials/header.php";
?>
<style>
    @media print {
        .no-print { display: none !important; }
        .panel { box-shadow: none; border: 1px solid #cbd5e1; page-break-inside: avoid; }
        .data-table th { background: #f1f5f9 !important; }
    }
</style>

<div class="page-header">
    <div>
        <h1>💵 <?= e(t('payroll_reports')) ?></h1>
        <p style="color:#64748b; margin:6px 0 0;">
            Per-employee payroll detail with department subtotals, plus a month-by-month summary for <?= e((string) $year) ?>.
            All amounts in <?= e(currency_symbol()) ?>.
        </p>
    </div>
    <div class="header-actions">
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
        <a href="export_csv.php?<?= e($csvQuery) ?>" class="btn btn-secondary">⬇️ Export CSV</a>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:15px;">
        <div>
            <label><?= e(t('month')) ?></label>
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= e(format_app_month_name($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label><?= e(t('year')) ?></label>
            <select name="year">
                <?php foreach ($payrollYears as $y): ?>
                <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Department</label>
            <select name="department">
                <option value="">All departments</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusMeta as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Employee</label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name or employee code">
        </div>
        <div style="display:flex; align-items:flex-end; gap:10px;">
            <button type="submit" class="btn btn-accent">Apply</button>
            <a href="payroll.php" class="btn btn-secondary">Reset</a>
        </div>
    </div>
</form>

<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:16px;">
    <span class="badge <?= $attendanceApproved ? 'badge-success' : 'badge-warning' ?>">
        <?= $attendanceApproved ? '✅ Attendance approved' : '⏳ Attendance not approved' ?>
    </span>
    <span style="color:#64748b; font-size:0.85rem;">
        <?php if ($attendanceApproved): ?>
        Finance approved HR's attendance submission for <?= e(format_app_month_year($month, $year)) ?>; payroll for this period is unlocked.
        <?php else: ?>
        Payroll generation for <?= e(format_app_month_year($month, $year)) ?> is blocked until HR submits attendance and finance approves it.
        <?php endif; ?>
    </span>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#6366f1;">👥</div>
        <div>
            <div class="stat-value"><?= number_format($totals['employees']) ?></div>
            <div class="stat-label">Employees on payroll</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0ea5e9;">Σ</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) . money($grossPay) ?></div>
            <div class="stat-label">Gross pay (basic + allowances + OT)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#10b981;">➕</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) . money($totals['allowances']) ?></div>
            <div class="stat-label">Allowances</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f59e0b;">⏱</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) . money($totals['overtime']) ?></div>
            <div class="stat-label">Overtime (<?= number_format($totals['overtime_hours'], 1) ?> h)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#ef4444;">➖</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) . money($totals['deductions']) ?></div>
            <div class="stat-label">Total deductions<?= $totals['loan'] > 0 ? ' · loan ' . e(currency_symbol()) . money($totals['loan']) : '' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0d9488;">💰</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) . money($totals['net']) ?></div>
            <div class="stat-label">Net pay</div>
        </div>
    </div>
</div>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <h3 style="margin:0;">Payroll detail — <?= e(format_app_month_year($month, $year)) ?></h3>
        <span style="color:#64748b; font-size:0.85rem;"><?= number_format(count($rows)) ?> record(s)</span>
    </div>

    <?php if (empty($rows)): ?>
        <p style="margin:0; color:#64748b;">No payroll records match the selected filters for <?= e(format_app_month_year($month, $year)) ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Designation</th>
                    <th style="text-align:right;">Basic</th>
                    <th style="text-align:right;">Allowances</th>
                    <th style="text-align:right;">Overtime</th>
                    <th style="text-align:right;">Deductions</th>
                    <th style="text-align:right;">Net Pay</th>
                    <th>Status</th>
                    <th>Paid / Transferred</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byDepartment as $dept => $deptRows): ?>
                    <?php
                    $deptTotals = ['basic' => 0.0, 'allowances' => 0.0, 'overtime' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
                    foreach ($deptRows as $deptRow) {
                        $deptTotals['basic'] += (float) $deptRow['basic_salary'];
                        $deptTotals['allowances'] += (float) $deptRow['allowances'];
                        $deptTotals['overtime'] += (float) $deptRow['overtime_payment'];
                        $deptTotals['deductions'] += (float) $deptRow['deductions'];
                        $deptTotals['net'] += (float) $deptRow['net_salary'];
                    }
                    ?>
                    <tr style="background:#eef2f7;">
                        <td colspan="9" style="font-weight:700;"><?= e($dept) ?></td>
                    </tr>
                    <?php foreach ($deptRows as $row): ?>
                    <?php
                    $meta = $statusMeta[$row['workflow_status']] ?? ['label' => $row['workflow_status'], 'badge' => 'badge-muted'];
                    $loanAmount = $loanApplied[(int) $row['employee_id']] ?? 0.0;
                    $settledLabel = '';
                    if (!empty($row['payment_date'])) {
                        $settledLabel = 'Paid ' . format_app_date($row['payment_date']);
                    } elseif (!empty($row['transfer_date'])) {
                        $settledLabel = 'Transferred ' . format_app_date($row['transfer_date']);
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($row['employee_name']) ?></strong>
                            <?php if (!empty($row['employee_code'])): ?>
                            <div style="color:#64748b; font-size:0.8rem;"><?= e($row['employee_code']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:0.8rem;"><a href="/block_plant/finance/payslip.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener"><?= e(t('payslip')) ?></a></div>
                        </td>
                        <td><?= e($row['designation'] ?? '') ?></td>
                        <td style="text-align:right;"><?= money($row['basic_salary']) ?></td>
                        <td style="text-align:right;"><?= money($row['allowances']) ?></td>
                        <td style="text-align:right;">
                            <?= money($row['overtime_payment']) ?>
                            <?php if ((float) $row['overtime_hours'] > 0): ?>
                            <div style="color:#64748b; font-size:0.8rem;"><?= number_format((float) $row['overtime_hours'], 1) ?> h</div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?= money($row['deductions']) ?>
                            <?php if ($loanAmount > 0): ?>
                            <div style="color:#64748b; font-size:0.8rem;">loan <?= money($loanAmount) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;"><strong><?= money($row['net_salary']) ?></strong></td>
                        <td><span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span></td>
                        <td><?= $settledLabel !== '' ? e($settledLabel) : '<span style="color:#94a3b8;">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f8fafc; font-weight:600;">
                        <td colspan="2"><?= e($dept) ?> subtotal (<?= number_format(count($deptRows)) ?>)</td>
                        <td style="text-align:right;"><?= money($deptTotals['basic']) ?></td>
                        <td style="text-align:right;"><?= money($deptTotals['allowances']) ?></td>
                        <td style="text-align:right;"><?= money($deptTotals['overtime']) ?></td>
                        <td style="text-align:right;"><?= money($deptTotals['deductions']) ?></td>
                        <td style="text-align:right;"><?= money($deptTotals['net']) ?></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background:#e2e8f0; font-weight:700;">
                    <td colspan="2">GRAND TOTAL (<?= number_format($totals['employees']) ?>)</td>
                    <td style="text-align:right;"><?= money($totals['basic']) ?></td>
                    <td style="text-align:right;"><?= money($totals['allowances']) ?></td>
                    <td style="text-align:right;"><?= money($totals['overtime']) ?></td>
                    <td style="text-align:right;"><?= money($totals['deductions']) ?></td>
                    <td style="text-align:right;"><?= money($totals['net']) ?></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>Year summary — <?= e((string) $year) ?></h3>
    <?php if (empty($monthlySummary)): ?>
        <p style="margin:0; color:#64748b;">No payroll recorded for <?= e((string) $year) ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right;">Records</th>
                    <th style="text-align:right;">Basic</th>
                    <th style="text-align:right;">Allowances</th>
                    <th style="text-align:right;">Overtime</th>
                    <th style="text-align:right;">Deductions</th>
                    <th style="text-align:right;">Net Pay</th>
                    <th style="text-align:right;">Paid</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlySummary as $summaryRow): ?>
                <tr style="<?= (int) $summaryRow['month'] === $month ? 'background:#eef6ff;' : '' ?>">
                    <td>
                        <a href="payroll.php?month=<?= (int) $summaryRow['month'] ?>&year=<?= (int) $year ?>&department=<?= urlencode($department) ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                            <?= e(format_app_month_name((int) $summaryRow['month'])) ?>
                        </a>
                    </td>
                    <td style="text-align:right;"><?= number_format((int) $summaryRow['employees']) ?></td>
                    <td style="text-align:right;"><?= money($summaryRow['basic']) ?></td>
                    <td style="text-align:right;"><?= money($summaryRow['allowances']) ?></td>
                    <td style="text-align:right;"><?= money($summaryRow['overtime']) ?></td>
                    <td style="text-align:right;"><?= money($summaryRow['deductions']) ?></td>
                    <td style="text-align:right;"><strong><?= money($summaryRow['net']) ?></strong></td>
                    <td style="text-align:right;"><?= number_format((int) $summaryRow['paid_employees']) ?></td>
                    <td style="font-size:0.8rem; color:#64748b;"><?= e(str_replace(',', ', ', (string) $summaryRow['statuses'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#e2e8f0; font-weight:700;">
                    <td>Total</td>
                    <td style="text-align:right;"><?= number_format($yearTotals['employees']) ?></td>
                    <td style="text-align:right;"><?= money($yearTotals['basic']) ?></td>
                    <td style="text-align:right;"><?= money($yearTotals['allowances']) ?></td>
                    <td style="text-align:right;"><?= money($yearTotals['overtime']) ?></td>
                    <td style="text-align:right;"><?= money($yearTotals['deductions']) ?></td>
                    <td style="text-align:right;"><?= money($yearTotals['net']) ?></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
    <p style="margin:12px 0 0; color:#64748b; font-size:0.8rem;">
        The year summary respects the department and employee filters, but always shows every month of <?= e((string) $year) ?>.
        "Deductions" is the amount stored on the payroll record and already includes loan recoveries applied through payroll.
    </p>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
