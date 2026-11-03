<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

[$from, $to] = attendance_report_bounds($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? date('Y-m-d'));
$employeeId = max(0, (int) ($_GET['employee_id'] ?? 0));
$department = trim((string) ($_GET['department'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['present', 'absent', 'half-day', 'leave', 'late'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}

$records = attendance_report_records($from, $to, $employeeId, $department, $status);
$summary = attendance_report_summary($records);
$employeeSummary = attendance_report_employee_summary($records);

$selectedEmployeeName = '';
if ($employeeId > 0) {
    $employeeStmt = $pdo->prepare("SELECT name, employee_code FROM employees WHERE id = ?");
    $employeeStmt->execute([$employeeId]);
    $selectedEmployee = $employeeStmt->fetch();
    if ($selectedEmployee) {
        $selectedEmployeeName = $selectedEmployee['name'] . ' (' . $selectedEmployee['employee_code'] . ')';
    }
}

$filterParts = [];
if ($selectedEmployeeName !== '') {
    $filterParts[] = t('attendance_report_employee') . ': ' . $selectedEmployeeName;
}
if ($department !== '') {
    $filterParts[] = t('attendance_report_department') . ': ' . $department;
}
if ($status !== '') {
    $filterParts[] = t('attendance_report_status') . ': ' . attendance_status_label($status);
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'xls')));
if ($format !== 'csv') {
    $format = 'xls';
}

$filenameBase = 'attendance_report_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to);
$companyName = company_display_name();
$generatedAt = format_datetime(date('Y-m-d H:i:s'));

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    header('Cache-Control: max-age=0, must-revalidate');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [t('attendance_report'), $companyName]);
    fputcsv($out, [t('attendance_report_period'), format_date($from) . ' - ' . format_date($to)]);
    if ($filterParts) {
        fputcsv($out, [implode(' | ', $filterParts)]);
    }
    fputcsv($out, [t('attendance_report_generated'), $generatedAt]);
    fputcsv($out, []);

    fputcsv($out, [t('attendance_report_summary_by_employee')]);
    fputcsv($out, [
        t('attendance_report_employee'),
        t('attendance_report_code'),
        t('attendance_report_department'),
        t('attendance_report_records'),
        t('attendance_report_present'),
        t('attendance_report_absent'),
        t('attendance_report_half_day'),
        t('attendance_report_leave'),
        t('attendance_report_late'),
        t('attendance_report_total_hours'),
    ]);
    foreach ($employeeSummary as $row) {
        fputcsv($out, [
            $row['employee_name'],
            $row['employee_code'],
            $row['department'],
            $row['records'],
            $row['present'],
            $row['absent'],
            $row['half_day'],
            $row['leave'],
            $row['late'],
            number_format((float) $row['hours'], 2, '.', ''),
        ]);
    }
    fputcsv($out, [
        t('total'),
        '',
        '',
        $summary['records'],
        $summary['present'],
        $summary['absent'],
        $summary['half_day'],
        $summary['leave'],
        $summary['late'],
        number_format((float) $summary['hours'], 2, '.', ''),
    ]);
    fputcsv($out, []);

    fputcsv($out, [t('attendance_report_details')]);
    fputcsv($out, [
        t('attendance_report_date'),
        t('attendance_report_employee'),
        t('attendance_report_code'),
        t('attendance_report_department'),
        t('attendance_report_clock_in'),
        t('attendance_report_clock_out'),
        t('attendance_report_working_hours'),
        t('attendance_report_status'),
        t('attendance_report_notes'),
    ]);
    foreach ($records as $row) {
        fputcsv($out, [
            $row['attendance_date'],
            $row['employee_name'],
            $row['employee_code'],
            $row['department'],
            !empty($row['clock_in']) ? substr((string) $row['clock_in'], 0, 5) : '',
            !empty($row['clock_out']) ? substr((string) $row['clock_out'], 0, 5) : '',
            number_format((float) ($row['working_hours'] ?? 0), 2, '.', ''),
            attendance_status_label($row['status']),
            $row['notes'],
        ]);
    }
    if (!$records) {
        fputcsv($out, [t('attendance_report_no_records')]);
    }

    fclose($out);
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
header('Cache-Control: max-age=0, must-revalidate');
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, "Noto Sans Ethiopic", sans-serif; font-size: 11px; }
        th, td { border: 1px solid #94a3b8; padding: 4px 6px; vertical-align: top; }
        th { background: #e2e8f0; font-weight: bold; }
        .title { font-size: 16px; font-weight: bold; border: 0; padding-top: 2px; }
        .meta { border: 0; padding: 2px 0; }
        .section { font-size: 13px; font-weight: bold; background: #f1f5f9; }
        .num { mso-number-format: "0.00"; text-align: right; }
        .right { text-align: right; }
    </style>
</head>
<body>
<table>
    <tr><td colspan="10" class="title"><?= e($companyName) ?> — <?= e(t('attendance_report')) ?></td></tr>
    <tr><td colspan="10" class="meta"><?= e(t('attendance_report_period')) ?>: <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?></td></tr>
    <?php if ($filterParts): ?>
    <tr><td colspan="10" class="meta"><?= e(implode(' | ', $filterParts)) ?></td></tr>
    <?php endif; ?>
    <tr><td colspan="10" class="meta"><?= e(t('attendance_report_generated')) ?>: <?= e($generatedAt) ?></td></tr>
</table>
<br>

<table>
    <tr><td colspan="10" class="section"><?= e(t('attendance_report_summary_by_employee')) ?></td></tr>
    <tr>
        <th><?= e(t('attendance_report_employee')) ?></th>
        <th><?= e(t('attendance_report_code')) ?></th>
        <th><?= e(t('attendance_report_department')) ?></th>
        <th><?= e(t('attendance_report_records')) ?></th>
        <th><?= e(t('attendance_report_present')) ?></th>
        <th><?= e(t('attendance_report_absent')) ?></th>
        <th><?= e(t('attendance_report_half_day')) ?></th>
        <th><?= e(t('attendance_report_leave')) ?></th>
        <th><?= e(t('attendance_report_late')) ?></th>
        <th><?= e(t('attendance_report_total_hours')) ?></th>
    </tr>
    <?php foreach ($employeeSummary as $row): ?>
    <tr>
        <td><?= e($row['employee_name']) ?></td>
        <td><?= e($row['employee_code']) ?></td>
        <td><?= e($row['department']) ?></td>
        <td class="right" x:num="<?= (int) $row['records'] ?>"><?= (int) $row['records'] ?></td>
        <td class="right" x:num="<?= (int) $row['present'] ?>"><?= (int) $row['present'] ?></td>
        <td class="right" x:num="<?= (int) $row['absent'] ?>"><?= (int) $row['absent'] ?></td>
        <td class="right" x:num="<?= (int) $row['half_day'] ?>"><?= (int) $row['half_day'] ?></td>
        <td class="right" x:num="<?= (int) $row['leave'] ?>"><?= (int) $row['leave'] ?></td>
        <td class="right" x:num="<?= (int) $row['late'] ?>"><?= (int) $row['late'] ?></td>
        <td class="num" x:num="<?= number_format((float) $row['hours'], 2, '.', '') ?>"><?= number_format((float) $row['hours'], 2, '.', '') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr>
        <th colspan="3"><?= e(t('total')) ?></th>
        <th class="right" x:num="<?= (int) $summary['records'] ?>"><?= (int) $summary['records'] ?></th>
        <th class="right" x:num="<?= (int) $summary['present'] ?>"><?= (int) $summary['present'] ?></th>
        <th class="right" x:num="<?= (int) $summary['absent'] ?>"><?= (int) $summary['absent'] ?></th>
        <th class="right" x:num="<?= (int) $summary['half_day'] ?>"><?= (int) $summary['half_day'] ?></th>
        <th class="right" x:num="<?= (int) $summary['leave'] ?>"><?= (int) $summary['leave'] ?></th>
        <th class="right" x:num="<?= (int) $summary['late'] ?>"><?= (int) $summary['late'] ?></th>
        <th class="num" x:num="<?= number_format((float) $summary['hours'], 2, '.', '') ?>"><?= number_format((float) $summary['hours'], 2, '.', '') ?></th>
    </tr>
</table>
<br>

<table>
    <tr><td colspan="10" class="section"><?= e(t('attendance_report_details')) ?></td></tr>
    <tr>
        <th><?= e(t('attendance_report_date')) ?></th>
        <th><?= e(t('attendance_report_employee')) ?></th>
        <th><?= e(t('attendance_report_code')) ?></th>
        <th><?= e(t('attendance_report_department')) ?></th>
        <th><?= e(t('attendance_report_clock_in')) ?></th>
        <th><?= e(t('attendance_report_clock_out')) ?></th>
        <th><?= e(t('attendance_report_working_hours')) ?></th>
        <th><?= e(t('attendance_report_status')) ?></th>
        <th><?= e(t('attendance_report_notes')) ?></th>
    </tr>
    <?php foreach ($records as $row): ?>
    <tr>
        <td><?= e($row['attendance_date']) ?></td>
        <td><?= e($row['employee_name']) ?></td>
        <td><?= e($row['employee_code']) ?></td>
        <td><?= e($row['department']) ?></td>
        <td><?= e(!empty($row['clock_in']) ? substr((string) $row['clock_in'], 0, 5) : '') ?></td>
        <td><?= e(!empty($row['clock_out']) ? substr((string) $row['clock_out'], 0, 5) : '') ?></td>
        <td class="num" x:num="<?= number_format((float) ($row['working_hours'] ?? 0), 2, '.', '') ?>"><?= number_format((float) ($row['working_hours'] ?? 0), 2, '.', '') ?></td>
        <td><?= e(attendance_status_label($row['status'])) ?></td>
        <td><?= e($row['notes']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$records): ?>
    <tr><td colspan="10"><?= e(t('attendance_report_no_records')) ?></td></tr>
    <?php endif; ?>
</table>
</body>
</html>
