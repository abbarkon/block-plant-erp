<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_login();
require_permission('hr', 'view');

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$month = $month >= 1 && $month <= 12 ? $month : (int) date('n');
$year = $year >= 1900 && $year <= (int) date('Y') ? $year : (int) date('Y');

$stmt = $pdo->prepare("SELECT p.employee_id, p.month, p.year, p.basic_salary, p.allowances, p.deductions, p.net_salary, p.payment_status, p.workflow_status, p.created_by, p.created_at, e.employee_code, e.name AS employee_name, e.department, u.name AS generated_by_name FROM payroll p JOIN employees e ON e.id = p.employee_id LEFT JOIN users u ON u.id = p.created_by WHERE p.month = ? AND p.year = ? ORDER BY e.name");
$stmt->execute([$month, $year]);
$payrollRows = $stmt->fetchAll();

if (!$payrollRows) {
    http_response_code(404);
    exit(e(t('no_payroll_found')));
}

$totals = ['basic' => 0.0, 'allowances' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
$generatedBy = [];
$workflowStatuses = [];
foreach ($payrollRows as $row) {
    $totals['basic'] += (float) $row['basic_salary'];
    $totals['allowances'] += (float) $row['allowances'];
    $totals['deductions'] += (float) $row['deductions'];
    $totals['net'] += (float) $row['net_salary'];
    if (!empty($row['generated_by_name'])) {
        $generatedBy[$row['generated_by_name']] = true;
    }
    if (!empty($row['workflow_status'])) {
        $workflowStatuses[$row['workflow_status']] = true;
    }
}
$generatedByLabel = $generatedBy ? implode(', ', array_keys($generatedBy)) : 'Not recorded';
$workflowKey = count($workflowStatuses) === 1 ? 'status_' . (string) array_key_first($workflowStatuses) : 'status_mixed';
$workflowLabel = t($workflowKey);
$periodLabel = format_app_month_year($month, $year);
$companyName = function_exists('company_display_name') ? company_display_name() : 'Block Plant';
?>
<!doctype html>
<html lang="<?= app_lang() === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('payroll_signoff')) ?> - <?= e($periodLabel) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f3; color: #18252c; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .toolbar { display: flex; justify-content: flex-end; gap: 10px; padding: 16px max(16px, calc((100vw - 1100px) / 2)); }
        .toolbar a, .toolbar button { border: 0; border-radius: 5px; padding: 10px 14px; background: #246a73; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #66757c; }
        .sheet { width: min(1100px, calc(100% - 32px)); margin: 0 auto 30px; padding: 30px; background: #fff; box-shadow: 0 8px 28px rgba(20, 39, 47, .12); }
        .sheet-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #246a73; }
        .company { color: #246a73; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        h1 { margin: 6px 0 4px; color: #183b56; font-size: 24px; }
        .subtitle { color: #607078; }
        .period { min-width: 180px; padding: 12px 14px; border: 1px solid #d6e0e3; border-radius: 5px; }
        .period strong { display: block; margin-top: 4px; font-size: 16px; }
        .meta { display: flex; flex-wrap: wrap; gap: 22px; margin: 18px 0; color: #465860; }
        .meta strong { color: #20343d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 9px; border: 1px solid #d7e0e3; text-align: left; }
        th { background: #eaf1f2; color: #193c47; font-size: 10px; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tbody tr:nth-child(even) { background: #f8fafb; }
        tfoot td { background: #eef4f4; font-weight: 700; }
        .signoff { margin-top: 28px; padding: 18px; border: 1px solid #cbd8db; border-radius: 5px; break-inside: avoid; }
        .signoff h2 { margin: 0 0 8px; color: #183b56; font-size: 16px; }
        .declaration { margin: 0 0 26px; color: #42555d; line-height: 1.5; }
        .signature-grid { display: grid; grid-template-columns: 1.4fr 1.4fr 1fr; gap: 22px; }
        .signature-field { min-height: 42px; border-bottom: 1px solid #33464e; }
        .signature-label { margin-top: 6px; color: #61727a; font-size: 10px; }
        .note { margin-top: 16px; color: #607078; font-size: 10px; }
        @media (max-width: 650px) { .sheet { padding: 18px; } .sheet-header { flex-direction: column; } .period { width: 100%; } .table-wrap { overflow-x: auto; } table { min-width: 760px; } }
        @media print {
            @page { size: A4 landscape; margin: 12mm; }
            body { background: #fff; font-size: 10px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .toolbar { display: none !important; }
            .sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            h1 { font-size: 20px; }
            th, td { padding: 6px 7px; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="/block_plant/finance/payroll.php?month=<?= $month ?>&year=<?= $year ?>"><?= e(t('back_to_payroll')) ?></a>
        <button type="button" onclick="window.print()"><?= e(t('print_signoff')) ?></button>
    </div>
    <main class="sheet">
        <header class="sheet-header">
            <div>
                <div class="company"><?= e($companyName) ?></div>
                <h1><?= e(t('payroll_signoff')) ?></h1>
                <div class="subtitle"><?= e(t('confidential_payroll')) ?></div>
            </div>
            <div class="period"><span><?= e(t('payroll_period')) ?></span><strong><?= e($periodLabel) ?></strong></div>
        </header>

        <div class="meta">
            <span><strong><?= e(t('employees')) ?>:</strong> <?= number_format(count($payrollRows)) ?></span>
            <span><strong><?= e(t('prepared_by')) ?>:</strong> <?= e($generatedByLabel) ?></span>
            <span><strong><?= e(t('printed')) ?>:</strong> <?= e(format_datetime('now')) ?></span>
            <span><strong><?= e(t('workflow')) ?>:</strong> <?= e($workflowLabel) ?></span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><?= e(t('employee_code')) ?></th>
                        <th><?= e(t('employee_name')) ?></th>
                        <th><?= e(t('department')) ?></th>
                        <th class="num"><?= e(t('basic_salary')) ?></th>
                        <th class="num"><?= e(t('allowances')) ?></th>
                        <th class="num"><?= e(t('deductions')) ?></th>
                        <th class="num"><?= e(t('net_salary')) ?></th>
                        <th><?= e(t('payment_status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payrollRows as $row): ?>
                    <tr>
                        <td><?= e($row['employee_code'] ?? '') ?></td>
                        <td><?= e($row['employee_name'] ?? '') ?></td>
                        <td><?= e($row['department'] ?? '') ?></td>
                        <td class="num"><?= number_format((float) $row['basic_salary'], 2) ?></td>
                        <td class="num"><?= number_format((float) $row['allowances'], 2) ?></td>
                        <td class="num"><?= number_format((float) $row['deductions'], 2) ?></td>
                        <td class="num"><?= number_format((float) $row['net_salary'], 2) ?></td>
                        <?php $rowStatus = strtolower((string) ($row['payment_status'] ?: 'pending')); ?>
                        <td><?= e(t('status_' . $rowStatus)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><?= e(t('payroll_totals')) ?></td>
                        <td class="num"><?= number_format($totals['basic'], 2) ?></td>
                        <td class="num"><?= number_format($totals['allowances'], 2) ?></td>
                        <td class="num"><?= number_format($totals['deductions'], 2) ?></td>
                        <td class="num"><?= number_format($totals['net'], 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <section class="signoff">
            <h2><?= e(t('administrator_authorization')) ?></h2>
            <p class="declaration"><?= e(t('admin_review_declaration')) ?> <?= e($periodLabel) ?></p>
            <div class="signature-grid">
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('administrator_name')) ?></div></div>
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('signature')) ?></div></div>
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('date')) ?></div></div>
            </div>
            <div class="note"><?= e(t('sign_before_filing')) ?></div>
        </section>
    </main>
    <?php if (($_GET['auto_print'] ?? '') === '1'): ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
