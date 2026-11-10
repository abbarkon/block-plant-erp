<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$pageTitle = t('attendance_report');

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
[$from, $to] = attendance_report_bounds($_GET['from'] ?? $defaultFrom, $_GET['to'] ?? $defaultTo);

$employeeId = max(0, (int) ($_GET['employee_id'] ?? 0));
$department = trim((string) ($_GET['department'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['present', 'absent', 'half-day', 'leave', 'late'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}

$employees = $pdo->query("SELECT id, name, employee_code, department FROM employees WHERE status = 1 ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE status = 1 AND department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$selectedEmployee = null;
foreach ($employees as $employee) {
    if ((int) $employee['id'] === $employeeId) {
        $selectedEmployee = $employee;
        break;
    }
}

$records = attendance_report_records($from, $to, $employeeId, $department, $status);
$summary = attendance_report_summary($records);
$employeeSummary = attendance_report_employee_summary($records);

$filterParts = [];
if ($selectedEmployee) {
    $filterParts[] = t('attendance_report_employee') . ': ' . $selectedEmployee['name'];
}
if ($department !== '') {
    $filterParts[] = t('attendance_report_department') . ': ' . $department;
}
if ($status !== '') {
    $filterParts[] = t('attendance_report_status') . ': ' . attendance_status_label($status);
}

$exportParams = [
    'from' => $from,
    'to' => $to,
    'employee_id' => $employeeId > 0 ? $employeeId : '',
    'department' => $department,
    'status' => $status,
];
$exportBase = 'attendance_export.php?' . http_build_query($exportParams);

$companyName = company_display_name();
$statusClasses = [
    'present' => 'status-present',
    'absent' => 'status-absent',
    'half-day' => 'status-half',
    'leave' => 'status-leave',
    'late' => 'status-late',
];

require_once "../partials/header.php";
?>

<style>
    .attendance-report-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .attendance-report-head h1 { margin: 0 0 4px; }
    .attendance-report-company { font-size: 1.15rem; font-weight: 700; color: #1c2250; }
    .attendance-report-meta { color: #64748b; font-size: .88rem; margin-top: 4px; }
    .attendance-report-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .attendance-report-filters { padding: 18px; margin-bottom: 18px; }
    .attendance-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
        align-items: end;
    }
    .attendance-filter-grid label { margin-top: 0; }
    .attendance-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .attendance-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .attendance-kpi {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: 3px solid #0f766e;
        border-radius: 10px;
        padding: 14px 12px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,.05);
    }
    .attendance-kpi strong { display: block; font-size: 1.45rem; color: #1c2250; }
    .attendance-kpi span { color: #64748b; font-size: .78rem; }
    .attendance-kpi.kpi-present { border-top-color: #15803d; }
    .attendance-kpi.kpi-absent { border-top-color: #dc2626; }
    .attendance-kpi.kpi-half { border-top-color: #d97706; }
    .attendance-kpi.kpi-leave { border-top-color: #7c3aed; }
    .attendance-kpi.kpi-late { border-top-color: #ea580c; }
    .attendance-kpi.kpi-hours { border-top-color: #2563eb; }
    .attendance-report-table { width: 100%; border-collapse: collapse; }
    .attendance-report-table th,
    .attendance-report-table td { padding: 8px 9px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    .attendance-report-table th { background: #f1f5f9; color: #334155; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
    .attendance-report-table td.num,
    .attendance-report-table th.num { text-align: right; white-space: nowrap; }
    .attendance-report-table tr.total-row td { background: #f8fafc; font-weight: 700; }
    .attendance-report-table td.notes-cell { color: #475569; max-width: 280px; }
    .status-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
    .status-present { background: #dcfce7; color: #166534; }
    .status-absent { background: #fee2e2; color: #991b1b; }
    .status-half { background: #fef3c7; color: #92400e; }
    .status-leave { background: #ede9fe; color: #5b21b6; }
    .status-late { background: #ffedd5; color: #9a3412; }
    .status-neutral { background: #f1f5f9; color: #475569; }
    .attendance-report-empty { text-align: center; padding: 26px; color: #64748b; }
    @media print {
        .topbar, .navbar, footer, .fab-wrap, .cmdk-overlay, .kbd-overlay, .no-print { display: none !important; }
        .container { max-width: none; margin: 0; padding: 0; }
        body { background: #fff; color: #000; }
        .panel { box-shadow: none !important; border: 0 !important; padding: 0 !important; margin: 0 0 14px !important; }
        .attendance-report-head { margin-bottom: 10px; }
        .attendance-report-head h1 { font-size: 18pt; }
        .attendance-kpis { grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 12px; }
        .attendance-kpi { box-shadow: none; padding: 8px 6px; }
        .attendance-kpi strong { font-size: 13pt; }
        .attendance-report-table { font-size: 8.5pt; }
        .attendance-report-table th,
        .attendance-report-table td { padding: 4px 5px; }
        .attendance-report-table thead { display: table-header-group; }
        .attendance-report-table tr { page-break-inside: avoid; }
        .status-badge { padding: 1px 6px; font-size: 7.5pt; }
    }
</style>

<div class="attendance-report-head">
    <div>
        <div class="attendance-report-company"><?= e($companyName) ?></div>
        <h1>📊 <?= e(t('attendance_report')) ?></h1>
        <div class="attendance-report-meta">
            <?= e(t('attendance_report_period')) ?>: <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>
            <?php if ($filterParts): ?>
            <br><?= e(implode(' · ', $filterParts)) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="attendance-report-actions no-print">
        <a href="attendance.php" class="btn btn-secondary">← <?= e(t('attendance_report_back')) ?></a>
        <a href="attendance_warnings.php?from=<?= e($from) ?>&to=<?= e($to) ?>&department=<?= e($department) ?>" class="btn btn-secondary">⚠ <?= e(attendance_warning_text('menu')) ?></a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">🖨 <?= e(t('print')) ?></button>
        <a href="attendance_sheet.php?from=<?= e($from) ?>&to=<?= e($to) ?>&department=<?= e($department) ?>" class="btn btn-secondary">🧾 <?= e(t('attendance_sheet')) ?></a>
        <a href="<?= e($exportBase . '&format=xls') ?>" class="btn">📥 <?= e(t('attendance_report_export_excel')) ?></a>
        <a href="<?= e($exportBase . '&format=csv') ?>" class="btn btn-secondary">📄 <?= e(t('export_csv')) ?></a>
    </div>
</div>

<p class="no-print" style="color:#64748b; margin:-6px 0 16px;"><?= e(t('attendance_report_hint')) ?></p>

<form method="get" class="panel attendance-report-filters no-print">
    <div class="attendance-filter-grid">
        <div>
            <label for="from"><?= e(t('attendance_report_from')) ?></label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" required>
        </div>
        <div>
            <label for="to"><?= e(t('attendance_report_to')) ?></label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" required>
        </div>
        <div>
            <label for="employee_id"><?= e(t('attendance_report_employee')) ?></label>
            <select id="employee_id" name="employee_id">
                <option value=""><?= e(t('attendance_report_all_employees')) ?></option>
                <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>" <?= $employeeId === (int) $employee['id'] ? 'selected' : '' ?>>
                    <?= e($employee['name']) ?> (<?= e($employee['employee_code']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="department"><?= e(t('attendance_report_department')) ?></label>
            <select id="department" name="department">
                <option value=""><?= e(t('attendance_report_all_departments')) ?></option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status"><?= e(t('attendance_report_status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(t('attendance_report_all_statuses')) ?></option>
                <?php foreach ($validStatuses as $validStatus): ?>
                <option value="<?= e($validStatus) ?>" <?= $status === $validStatus ? 'selected' : '' ?>><?= e(attendance_status_label($validStatus)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="attendance-filter-actions">
        <button type="submit" class="btn"><?= e(t('attendance_report_apply')) ?></button>
        <a href="attendance_report.php" class="btn btn-secondary"><?= e(t('attendance_report_reset')) ?></a>
    </div>
</form>

<div class="attendance-kpis">
    <div class="attendance-kpi"><strong><?= number_format($summary['records']) ?></strong><span><?= e(t('attendance_report_records')) ?></span></div>
    <div class="attendance-kpi"><strong><?= number_format($summary['employees']) ?></strong><span><?= e(t('employees')) ?></span></div>
    <div class="attendance-kpi kpi-present"><strong><?= number_format($summary['present']) ?></strong><span><?= e(t('attendance_report_present')) ?></span></div>
    <div class="attendance-kpi kpi-absent"><strong><?= number_format($summary['absent']) ?></strong><span><?= e(t('attendance_report_absent')) ?></span></div>
    <div class="attendance-kpi kpi-half"><strong><?= number_format($summary['half_day']) ?></strong><span><?= e(t('attendance_report_half_day')) ?></span></div>
    <div class="attendance-kpi kpi-leave"><strong><?= number_format($summary['leave']) ?></strong><span><?= e(t('attendance_report_leave')) ?></span></div>
    <div class="attendance-kpi kpi-late"><strong><?= number_format($summary['late']) ?></strong><span><?= e(t('attendance_report_late')) ?></span></div>
    <div class="attendance-kpi kpi-hours"><strong><?= number_format($summary['hours'], 2) ?></strong><span><?= e(t('attendance_report_total_hours')) ?></span></div>
</div>
<p style="margin:-4px 0 18px;color:#64748b;font-size:.85rem;"><?= e(t('attendance_report_paid_leave_note')) ?></p>

<?php if (!$records): ?>
<div class="panel attendance-report-empty"><?= e(t('attendance_report_no_records')) ?></div>
<?php else: ?>

<div class="panel">
    <h2 style="margin-top:0;"><?= e(t('attendance_report_summary_by_employee')) ?></h2>
    <div style="overflow-x:auto;">
        <table class="attendance-report-table">
            <thead>
                <tr>
                    <th><?= e(t('attendance_report_employee')) ?></th>
                    <th><?= e(t('attendance_report_code')) ?></th>
                    <th><?= e(t('attendance_report_department')) ?></th>
                    <th class="num"><?= e(t('attendance_report_records')) ?></th>
                    <th class="num"><?= e(t('attendance_report_present')) ?></th>
                    <th class="num"><?= e(t('attendance_report_absent')) ?></th>
                    <th class="num"><?= e(t('attendance_report_half_day')) ?></th>
                    <th class="num"><?= e(t('attendance_report_leave')) ?></th>
                    <th class="num"><?= e(t('attendance_report_late')) ?></th>
                    <th class="num"><?= e(t('attendance_report_total_hours')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employeeSummary as $row): ?>
                <tr>
                    <td><?= e($row['employee_name']) ?></td>
                    <td><?= e($row['employee_code']) ?></td>
                    <td><?= e($row['department'] ?: '—') ?></td>
                    <td class="num"><?= number_format($row['records']) ?></td>
                    <td class="num"><?= number_format($row['present']) ?></td>
                    <td class="num"><?= number_format($row['absent']) ?></td>
                    <td class="num"><?= number_format($row['half_day']) ?></td>
                    <td class="num"><?= number_format($row['leave']) ?></td>
                    <td class="num"><?= number_format($row['late']) ?></td>
                    <td class="num"><?= number_format($row['hours'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td><?= e(t('total')) ?></td>
                    <td></td>
                    <td></td>
                    <td class="num"><?= number_format($summary['records']) ?></td>
                    <td class="num"><?= number_format($summary['present']) ?></td>
                    <td class="num"><?= number_format($summary['absent']) ?></td>
                    <td class="num"><?= number_format($summary['half_day']) ?></td>
                    <td class="num"><?= number_format($summary['leave']) ?></td>
                    <td class="num"><?= number_format($summary['late']) ?></td>
                    <td class="num"><?= number_format($summary['hours'], 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <h2 style="margin-top:0;"><?= e(t('attendance_report_details')) ?></h2>
    <div style="overflow-x:auto;">
        <table class="attendance-report-table">
            <thead>
                <tr>
                    <th><?= e(t('attendance_report_date')) ?></th>
                    <th><?= e(t('attendance_report_employee')) ?></th>
                    <th><?= e(t('attendance_report_code')) ?></th>
                    <th><?= e(t('attendance_report_department')) ?></th>
                    <th><?= e(t('attendance_report_clock_in')) ?></th>
                    <th><?= e(t('attendance_report_clock_out')) ?></th>
                    <th class="num"><?= e(t('attendance_report_working_hours')) ?></th>
                    <th><?= e(t('attendance_report_status')) ?></th>
                    <th><?= e(t('attendance_report_notes')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $row):
                    $rowStatus = (string) ($row['status'] ?? '');
                    $statusClass = $statusClasses[$rowStatus] ?? 'status-neutral';
                    $clockIn = !empty($row['clock_in']) ? substr((string) $row['clock_in'], 0, 5) : '—';
                    $clockOut = !empty($row['clock_out']) ? substr((string) $row['clock_out'], 0, 5) : '—';
                ?>
                <tr>
                    <td><?= e(format_date($row['attendance_date'])) ?></td>
                    <td><?= e($row['employee_name']) ?></td>
                    <td><?= e($row['employee_code']) ?></td>
                    <td><?= e($row['department'] ?: '—') ?></td>
                    <td><?= e($clockIn) ?></td>
                    <td><?= e($clockOut) ?></td>
                    <td class="num"><?= number_format((float) ($row['working_hours'] ?? 0), 2) ?></td>
                    <td><span class="status-badge <?= e($statusClass) ?>"><?= e(attendance_status_label($rowStatus)) ?></span></td>
                    <td class="notes-cell"><?= e($row['notes'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<p style="color:#94a3b8; font-size:.78rem; margin-top:14px;">
    <?= e(t('attendance_report_generated')) ?>: <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
</p>

<?php require_once "../partials/footer.php"; ?>
