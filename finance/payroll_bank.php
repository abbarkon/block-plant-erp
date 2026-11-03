<?php
/**
 * Payroll bank transfer schedule.
 *
 * Finance-facing payment instruction list for a payroll period: who is paid,
 * how much, to which bank account, and the transfer reference. Supports CSV
 * export for uploading to the bank and printing for sign-off.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
if (!has_permission('finance', 'view') && !has_permission('hr', 'view')) {
    require_permission('finance', 'view'); // renders the standard access-denied page
    exit;
}

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$stmt = $pdo->prepare(
    "SELECT p.id, p.basic_salary, p.allowances, p.overtime_payment, p.deductions, p.net_salary,
            p.payment_status, p.payment_date, p.transfer_date, p.bank_reference, p.workflow_status,
            e.employee_code, e.name AS employee_name, e.department, e.bank_account
     FROM payroll p
     JOIN employees e ON e.id = p.employee_id
     WHERE p.month = ? AND p.year = ?
     ORDER BY e.name ASC"
);
$stmt->execute([$month, $year]);
$rows = $stmt->fetchAll();

$totalNet = 0.0;
$withAccount = 0;
foreach ($rows as $row) {
    $totalNet += (float) $row['net_salary'];
    if (trim((string) $row['bank_account']) !== '') {
        $withAccount++;
    }
}
$missingAccount = count($rows) - $withAccount;

if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'payroll_bank_schedule_' . $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee Code', 'Employee Name', 'Department', 'Bank Account', 'Net Pay', 'Payment Status', 'Payment Date', 'Transfer Date', 'Bank Reference']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['employee_code'],
            $row['employee_name'],
            $row['department'],
            $row['bank_account'],
            number_format((float) $row['net_salary'], 2, '.', ''),
            $row['payment_status'] ?: 'pending',
            $row['payment_date'] ?: '',
            $row['transfer_date'] ?: '',
            $row['bank_reference'] ?: '',
        ]);
    }
    fputcsv($out, ['', '', '', 'TOTAL', number_format($totalNet, 2, '.', ''), '', '', '', '']);
    fclose($out);
    exit;
}

$csvQuery = http_build_query(['month' => $month, 'year' => $year, 'export' => 'csv']);
$pageTitle = t('bank_transfer_schedule');
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
        <h1>🏦 <?= e(t('bank_transfer_schedule')) ?></h1>
        <p style="color:#64748b; margin:6px 0 0;">
            Payment instruction list for <?= e(format_app_month_year($month, $year)) ?>. Net pay is the amount to transfer to each employee's bank account. All amounts in <?= e(currency_symbol()) ?>.
        </p>
    </div>
    <div class="header-actions">
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ <?= e(t('print')) ?></button>
        <a href="payroll_bank.php?<?= e($csvQuery) ?>" class="btn btn-secondary">⬇️ <?= e(t('export_csv')) ?></a>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:15px; align-items:end;">
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
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-accent">Apply</button>
            <a href="payroll.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary"><?= e(t('payroll_generation')) ?></a>
        </div>
    </div>
</form>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#6366f1;">👥</div>
        <div>
            <div class="stat-value"><?= number_format(count($rows)) ?></div>
            <div class="stat-label">Employees to pay</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0d9488;">💰</div>
        <div>
            <div class="stat-value"><?= e(currency_symbol()) ?><?= money($totalNet) ?></div>
            <div class="stat-label"><?= e(t('total_net_pay')) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#10b981;">🏦</div>
        <div>
            <div class="stat-value"><?= number_format($withAccount) ?></div>
            <div class="stat-label">With bank account</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?= $missingAccount > 0 ? '#ef4444' : '#94a3b8' ?>;">⚠️</div>
        <div>
            <div class="stat-value"><?= number_format($missingAccount) ?></div>
            <div class="stat-label">Missing bank account</div>
        </div>
    </div>
</div>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <h3 style="margin:0;"><?= e(t('bank_transfer_schedule')) ?> — <?= e(format_app_month_year($month, $year)) ?></h3>
        <span style="color:#64748b; font-size:0.85rem;"><?= number_format(count($rows)) ?> record(s)</span>
    </div>

    <?php if (empty($rows)): ?>
        <p style="margin:0; color:#64748b;"><?= e(t('no_payroll_found')) ?></p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e(t('employee_code')) ?></th>
                    <th><?= e(t('employee_name')) ?></th>
                    <th><?= e(t('department')) ?></th>
                    <th><?= e(t('bank_account')) ?></th>
                    <th style="text-align:right;"><?= e(t('net_pay')) ?></th>
                    <th><?= e(t('payment_status')) ?></th>
                    <th><?= e(t('transfer_date')) ?></th>
                    <th><?= e(t('bank_reference')) ?></th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $rowStatus = strtolower((string) ($row['payment_status'] ?: 'pending')); ?>
                <tr>
                    <td><?= e($row['employee_code']) ?></td>
                    <td><strong><?= e($row['employee_name']) ?></strong></td>
                    <td><?= e($row['department'] ?: '—') ?></td>
                    <td>
                        <?php if (trim((string) $row['bank_account']) !== ''): ?>
                        <?= e($row['bank_account']) ?>
                        <?php else: ?>
                        <span class="badge badge-warning">Missing</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;"><strong><?= money($row['net_salary']) ?></strong></td>
                    <td><span class="badge <?= $rowStatus === 'paid' ? 'badge-success' : 'badge-warning' ?>"><?= e(t('status_' . $rowStatus)) ?></span></td>
                    <td><?= $row['transfer_date'] ? e(format_app_date($row['transfer_date'])) : '—' ?></td>
                    <td><?= e($row['bank_reference'] ?: '—') ?></td>
                    <td class="no-print"><a href="payslip.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener"><?= e(t('payslip')) ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#e2e8f0; font-weight:700;">
                    <td colspan="4"><?= e(t('total_net_pay')) ?> (<?= number_format(count($rows)) ?>)</td>
                    <td style="text-align:right;"><?= money($totalNet) ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
