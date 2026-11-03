<?php
/**
 * Payslip document.
 *
 * Renders the printable earnings / deductions / net-pay breakdown for one
 * payroll record, or for a whole period at once (batch run) so the entire
 * payroll can be printed to PDF in a single pass. Payslips can also be sent to
 * employees through the application mailer from the batch run.
 * Available to HR, finance and reports viewers.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_login();

if (!has_permission('hr', 'view') && !has_permission('finance', 'view') && !has_permission('reports', 'view')) {
    require_permission('hr', 'view'); // renders the standard access-denied page
    exit;
}
$canEmail = has_permission('hr', 'create') || has_permission('finance', 'create');

$id = (int) ($_GET['id'] ?? 0);
$employeeId = (int) ($_GET['employee_id'] ?? 0);
$reqMonth = (int) ($_GET['month'] ?? 0);
$reqYear = (int) ($_GET['year'] ?? 0);

$batch = ($id === 0 && $employeeId === 0 && $reqMonth >= 1 && $reqMonth <= 12 && $reqYear > 0);
$month = ($batch || $employeeId > 0) ? $reqMonth : 0;
$year = ($batch || $employeeId > 0) ? $reqYear : 0;

$select = "SELECT p.*, e.employee_code, e.name AS employee_name, e.email, e.department, e.designation, e.bank_account,
                  u.name AS generated_by, fu.name AS approved_by
           FROM payroll p
           JOIN employees e ON e.id = p.employee_id
           LEFT JOIN users u ON u.id = p.created_by
           LEFT JOIN users fu ON fu.id = p.finance_approved_by";

$records = [];
if ($id > 0) {
    $stmt = $pdo->prepare($select . " WHERE p.id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if ($record) {
        $records[] = $record;
    }
} elseif ($employeeId > 0 && $reqMonth >= 1 && $reqMonth <= 12 && $reqYear > 0) {
    $stmt = $pdo->prepare($select . " WHERE p.employee_id = ? AND p.month = ? AND p.year = ?");
    $stmt->execute([$employeeId, $reqMonth, $reqYear]);
    $record = $stmt->fetch();
    if ($record) {
        $records[] = $record;
    }
} elseif ($batch) {
    $stmt = $pdo->prepare($select . " WHERE p.month = ? AND p.year = ? ORDER BY e.department ASC, e.name ASC");
    $stmt->execute([$reqMonth, $reqYear]);
    $records = $stmt->fetchAll();
}

/* No period requested yet: offer a picker instead of a dead end. This is what
   the "Payslip Run" navigation entry opens. */
if ($id === 0 && $employeeId === 0 && !$batch) {
    $periods = $pdo->query(
        "SELECT p.month, p.year, COUNT(*) AS employees
         FROM payroll p
         GROUP BY p.year, p.month
         ORDER BY p.year DESC, p.month DESC"
    )->fetchAll();
    $pickerSym = currency_symbol();
    $pickerCompany = company_display_name();
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="<?= app_lang() === 'am' ? 'am' : 'en' ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e(t('payslip_run')) ?></title>
        <style>
            body { margin: 0; background: #edf1f3; color: #18252c; font-family: Arial, Helvetica, sans-serif; font-size: 13px; }
            .wrap { width: min(760px, calc(100% - 32px)); margin: 40px auto; }
            .card { background: #fff; border-radius: 10px; padding: 26px; box-shadow: 0 8px 28px rgba(20, 39, 47, .12); }
            h1 { margin: 0 0 6px; color: #183b56; font-size: 22px; }
            .sub { color: #607078; margin-bottom: 20px; }
            ul { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
            a.row { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; border: 1px solid #dbe4e6; border-radius: 6px; text-decoration: none; color: #183b56; font-weight: 700; }
            a.row:hover { background: #f2f7f8; border-color: #246a73; }
            a.row span { color: #64748b; font-weight: 400; font-size: 12px; }
            .empty { color: #64748b; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="card">
                <h1><?= e(t('payslip_run')) ?></h1>
                <div class="sub"><?= e($pickerCompany) ?> — choose a payroll period to print or email its payslips. All amounts in <?= e($pickerSym) ?>.</div>
                <?php if (!$periods): ?>
                <p class="empty"><?= e(t('no_payroll_for_period')) ?></p>
                <?php else: ?>
                <ul>
                    <?php foreach ($periods as $period): ?>
                    <li>
                        <a class="row" href="payslip.php?month=<?= (int) $period['month'] ?>&year=<?= (int) $period['year'] ?>">
                            <?= e(format_app_month_year((int) $period['month'], (int) $period['year'])) ?>
                            <span><?= number_format((int) $period['employees']) ?> · <?= e(t('payslip')) ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!$records) {
    http_response_code(404);
    exit(e(t('no_records_found')));
}

/* ------------------------------------------------------- build the sheets */
$sym = currency_symbol();
$co = company_settings();
$companyName = company_display_name();
$companyTagline = company_tagline();

$sheets = [];
foreach ($records as $row) {
    $rowMonth = (int) $row['month'];
    $rowYear = (int) $row['year'];

    /* Loan recovery applied through this payroll run. It is a memo line only: the
       amount is already included in the stored deductions column. Manual
       repayments (source = 'manual') are intentionally excluded. */
    $loanRecovery = 0.0;
    try {
        $loanStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(lr.amount_deducted), 0)
             FROM loan_repayments lr
             JOIN employee_loans el ON el.id = lr.loan_id
             WHERE el.employee_id = ? AND lr.payroll_month = ? AND lr.payroll_year = ? AND lr.source = 'payroll'"
        );
        $loanStmt->execute([(int) $row['employee_id'], $rowMonth, $rowYear]);
        $loanRecovery = (float) $loanStmt->fetchColumn();
    } catch (PDOException $e) {
        try {
            $loanStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(lr.amount_deducted), 0)
                 FROM loan_repayments lr
                 JOIN employee_loans el ON el.id = lr.loan_id
                 WHERE el.employee_id = ? AND lr.payroll_month = ? AND lr.payroll_year = ?"
            );
            $loanStmt->execute([(int) $row['employee_id'], $rowMonth, $rowYear]);
            $loanRecovery = (float) $loanStmt->fetchColumn();
        } catch (PDOException $e2) {
            $loanRecovery = 0.0;
        }
    }

    $basic = (float) $row['basic_salary'];
    $allowances = (float) $row['allowances'];
    $overtimeHours = (float) $row['overtime_hours'];
    $overtimePay = (float) $row['overtime_payment'];
    $deductions = (float) $row['deductions'];
    $attendanceDeduction = (float) ($row['attendance_deduction'] ?? 0);
    $unpaidDays = (float) ($row['unpaid_days'] ?? 0);
    $net = (float) $row['net_salary'];

    // Keep the memo loan line inside the stored total so the payslip lines add up.
    $loanRecovery = min($loanRecovery, max(0.0, $deductions - $attendanceDeduction));

    $paymentStatus = strtolower((string) ($row['payment_status'] ?: 'pending'));

    $sheets[] = [
        'row' => $row,
        'periodLabel' => format_app_month_year($rowMonth, $rowYear),
        'basic' => $basic,
        'allowances' => $allowances,
        'overtimeHours' => $overtimeHours,
        'overtimePay' => $overtimePay,
        'deductions' => $deductions,
        'net' => $net,
        'gross' => $basic + $allowances + $overtimePay,
        'loanRecovery' => $loanRecovery,
        'attendanceDeduction' => $attendanceDeduction,
        'unpaidDays' => $unpaidDays,
        'otherDeductions' => max(0.0, $deductions - $loanRecovery - $attendanceDeduction),
        'statusLabel' => t('status_' . $paymentStatus),
    ];
}

$isBatch = count($sheets) > 1;
$periodLabel = $sheets[0]['periodLabel'];

/* ---------------------------------------------------------------- emailing */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_payslips']) && $canEmail && $batch) {
    $emailed = 0;
    $skipped = 0;
    foreach ($sheets as $sheet) {
        $to = trim((string) $sheet['row']['email']);
        if ($to === '') {
            $skipped++;
            continue;
        }
        $lines = [
            $companyName . ' — ' . t('employee_payslip') . ' (' . $sheet['periodLabel'] . ')',
            '',
            t('employee_name') . ': ' . $sheet['row']['employee_name'],
            t('employee_code') . ': ' . $sheet['row']['employee_code'],
            t('department') . ': ' . ($sheet['row']['department'] ?: '—'),
            '',
            t('gross_pay') . ': ' . $sym . money($sheet['gross']),
            t('loan_deduction') . ': ' . $sym . money($sheet['loanRecovery']),
            t('attendance_deduction') . ': ' . $sym . money($sheet['attendanceDeduction']),
            t('other_deductions') . ': ' . $sym . money($sheet['otherDeductions']),
            t('total_deductions') . ': ' . $sym . money($sheet['deductions']),
            t('net_pay') . ': ' . $sym . money($sheet['net']),
            '',
            t('all_amounts_in') . ' ' . $sym,
        ];
        send_email_notification(
            $to,
            $companyName . ' - ' . t('employee_payslip') . ' - ' . $sheet['periodLabel'],
            implode("\n", $lines)
        );
        $emailed++;
    }
    $target = 'payslip.php?' . http_build_query([
        'month' => $reqMonth,
        'year' => $reqYear,
        'emailed' => $emailed,
        'skipped' => $skipped,
    ]);
    header('Location: ' . $target);
    exit;
}

$flash = null;
if (isset($_GET['emailed'])) {
    $flash = t('payslips_emailed') . ': ' . (int) $_GET['emailed'] . ' · ' . t('payslips_skipped_no_email') . ': ' . (int) ($_GET['skipped'] ?? 0);
}
?>
<!doctype html>
<html lang="<?= app_lang() === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($isBatch ? t('payslip_run') . ' - ' . $periodLabel : t('payslip') . ' - ' . $sheets[0]['row']['employee_name'] . ' - ' . $periodLabel) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f3; color: #18252c; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .toolbar { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding: 16px max(16px, calc((100vw - 820px) / 2)); flex-wrap: wrap; }
        .toolbar button, .toolbar .note { border: 0; border-radius: 5px; padding: 10px 14px; font: inherit; font-weight: 700; cursor: pointer; }
        .toolbar button { background: #246a73; color: #fff; }
        .toolbar button:first-child { background: #66757c; }
        .toolbar button:disabled { background: #b7c2c6; cursor: not-allowed; }
        .flash { padding: 10px 14px; border-radius: 5px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; font-weight: 700; }
        .sheet { width: min(820px, calc(100% - 32px)); margin: 0 auto 30px; padding: 32px; background: #fff; box-shadow: 0 8px 28px rgba(20, 39, 47, .12); break-after: page; }
        .sheet:last-of-type { break-after: auto; }
        .sheet-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding-bottom: 16px; border-bottom: 2px solid #246a73; }
        .company { color: #246a73; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .tagline { margin-top: 2px; color: #607078; }
        .muted { color: #607078; }
        h1 { margin: 8px 0 4px; color: #183b56; font-size: 22px; }
        .period { min-width: 190px; padding: 12px 14px; border: 1px solid #d6e0e3; border-radius: 5px; text-align: right; }
        .period span { color: #607078; text-transform: uppercase; font-size: 10px; letter-spacing: .05em; }
        .period strong { display: block; margin: 4px 0 8px; font-size: 16px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #eef4f4; color: #24656d; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .employee { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px 26px; margin: 18px 0; padding: 16px; border: 1px solid #e0e8ea; border-radius: 5px; background: #f8fafb; }
        .label { display: block; color: #71818a; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .employee strong, .detail-grid strong { color: #20343d; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .box { border: 1px solid #d7e0e3; border-radius: 5px; overflow: hidden; }
        .box h2 { margin: 0; padding: 9px 12px; background: #eaf1f2; color: #193c47; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        .box table { width: 100%; border-collapse: collapse; }
        .box td { padding: 8px 12px; border-top: 1px solid #e6edee; }
        .num { text-align: right; white-space: nowrap; }
        .box tr.total td { background: #f3f7f8; font-weight: 700; }
        .net { display: flex; justify-content: space-between; align-items: center; margin: 18px 0; padding: 16px 20px; border-radius: 6px; background: #183b56; color: #fff; }
        .net span { text-transform: uppercase; font-size: 11px; letter-spacing: .06em; opacity: .85; }
        .net strong { font-size: 24px; }
        .details .detail-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 18px; padding: 14px; }
        .signoff { margin-top: 24px; padding: 16px; border: 1px solid #cbd8db; border-radius: 5px; break-inside: avoid; }
        .declaration { margin: 0 0 22px; color: #42555d; }
        .signature-grid { display: grid; grid-template-columns: 1.4fr 1.4fr 1fr; gap: 22px; }
        .signature-field { min-height: 40px; border-bottom: 1px solid #33464e; }
        .signature-label { margin-top: 6px; color: #61727a; font-size: 10px; }
        @media (max-width: 640px) { .sheet { padding: 18px; } .sheet-header { flex-direction: column; } .cols, .employee, .details .detail-grid { grid-template-columns: 1fr; } .period { width: 100%; text-align: left; } }
        @media print {
            @page { size: A4 portrait; margin: 12mm; }
            body { background: #fff; font-size: 11px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .toolbar, .flash { display: none !important; }
            .sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .box, .signoff, tr, .net { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <form method="post" class="toolbar">
        <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <button type="button" onclick="history.back()">← <?= e(t('back')) ?></button>
        <?php endif; ?>
        <button type="button" onclick="window.print()"><?= $isBatch ? e(t('print_all_payslips')) : e(t('print')) ?></button>
        <?php if ($batch): ?>
        <a href="payroll.php?month=<?= (int) $reqMonth ?>&year=<?= (int) $reqYear ?>" style="background:#66757c; color:#fff; border-radius:5px; padding:10px 14px; font-weight:700; text-decoration:none;"><?= e(t('payroll_generation')) ?></a>
        <?php endif; ?>
        <?php if ($batch && $canEmail): ?>
        <button type="submit" name="email_payslips" value="1">✉️ <?= e(t('email_payslips')) ?></button>
        <?php elseif ($batch): ?>
        <span class="note muted"><?= e(t('email_payslips_hint')) ?></span>
        <?php endif; ?>
        <?php if ($flash !== null): ?>
        <span class="flash">✅ <?= e($flash) ?></span>
        <?php endif; ?>
    </form>
    <?php foreach ($sheets as $sheet): ?>
    <?php
        $row = $sheet['row'];
        $periodLabel = $sheet['periodLabel'];
    ?>
    <main class="sheet">
        <header class="sheet-header">
            <div>
                <div class="company"><?= e($companyName) ?></div>
                <?php if ($companyTagline !== ''): ?><div class="tagline"><?= e($companyTagline) ?></div><?php endif; ?>
                <?php if (!empty($co['address'])): ?><div class="muted"><?= e($co['address']) ?></div><?php endif; ?>
                <?php if (!empty($co['phone']) || !empty($co['email'])): ?><div class="muted"><?= e(trim(($co['phone'] ?? '') . '  ' . ($co['email'] ?? ''))) ?></div><?php endif; ?>
                <h1><?= e($isBatch ? t('payslip') : t('employee_payslip')) ?></h1>
                <div class="muted"><?= e(t('all_amounts_in')) ?> <?= e($sym) ?></div>
            </div>
            <div class="period">
                <span><?= e(t('payroll_period')) ?></span>
                <strong><?= e($periodLabel) ?></strong>
                <span class="badge"><?= e($sheet['statusLabel']) ?></span>
            </div>
        </header>

        <section class="employee">
            <div><span class="label"><?= e(t('employee_name')) ?></span><strong><?= e($row['employee_name']) ?></strong></div>
            <div><span class="label"><?= e(t('employee_code')) ?></span><strong><?= e($row['employee_code']) ?></strong></div>
            <div><span class="label"><?= e(t('department')) ?></span><strong><?= $row['department'] ? e($row['department']) : '—' ?></strong></div>
            <div><span class="label"><?= e(t('designation')) ?></span><strong><?= $row['designation'] ? e($row['designation']) : '—' ?></strong></div>
            <div><span class="label"><?= e(t('bank_account')) ?></span><strong><?= $row['bank_account'] ? e($row['bank_account']) : '—' ?></strong></div>
            <div><span class="label"><?= e(t('payment_status')) ?></span><strong><?= e($sheet['statusLabel']) ?></strong></div>
        </section>

        <div class="cols">
            <section class="box">
                <h2><?= e(t('earnings')) ?></h2>
                <table>
                    <tr><td><?= e(t('basic_salary')) ?></td><td class="num"><?= money($sheet['basic']) ?></td></tr>
                    <tr><td><?= e(t('allowances')) ?></td><td class="num"><?= money($sheet['allowances']) ?></td></tr>
                    <tr>
                        <td><?= e(t('overtime_payment')) ?><?php if ($sheet['overtimeHours'] > 0): ?> <span class="muted">(<?= number_format($sheet['overtimeHours'], 1) ?> h)</span><?php endif; ?></td>
                        <td class="num"><?= money($sheet['overtimePay']) ?></td>
                    </tr>
                    <tr class="total"><td><?= e(t('gross_pay')) ?></td><td class="num"><?= money($sheet['gross']) ?></td></tr>
                </table>
            </section>

            <section class="box">
                <h2><?= e(t('deductions')) ?></h2>
                <table>
                    <tr><td><?= e(t('other_deductions')) ?></td><td class="num"><?= money($sheet['otherDeductions']) ?></td></tr>
                    <?php if ($sheet['attendanceDeduction'] > 0): ?>
                    <tr>
                        <td><?= e(t('attendance_deduction')) ?><?php if ($sheet['unpaidDays'] > 0): ?> <span class="muted">(<?= number_format($sheet['unpaidDays'], 1) ?> <?= e(t('unpaid_days')) ?>)</span><?php endif; ?></td>
                        <td class="num"><?= money($sheet['attendanceDeduction']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($sheet['loanRecovery'] > 0): ?>
                    <tr><td><?= e(t('loan_deduction')) ?></td><td class="num"><?= money($sheet['loanRecovery']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="total"><td><?= e(t('total_deductions')) ?></td><td class="num"><?= money($sheet['deductions']) ?></td></tr>
                </table>
            </section>
        </div>

        <div class="net">
            <span><?= e(t('net_pay')) ?></span>
            <strong><?= e($sym) ?><?= money($sheet['net']) ?></strong>
        </div>

        <section class="box details">
            <h2><?= e(t('payment_details')) ?></h2>
            <div class="detail-grid">
                <div><span class="label"><?= e(t('payment_date')) ?></span><strong><?= $row['payment_date'] ? e(format_app_date($row['payment_date'])) : '—' ?></strong></div>
                <div><span class="label"><?= e(t('transfer_date')) ?></span><strong><?= $row['transfer_date'] ? e(format_app_date($row['transfer_date'])) : '—' ?></strong></div>
                <div><span class="label"><?= e(t('bank_reference')) ?></span><strong><?= $row['bank_reference'] ? e($row['bank_reference']) : '—' ?></strong></div>
                <div><span class="label"><?= e(t('prepared_by')) ?></span><strong><?= $row['generated_by'] ? e($row['generated_by']) : '—' ?></strong></div>
                <div><span class="label"><?= e(t('approved_by_finance')) ?></span><strong><?= $row['approved_by'] ? e($row['approved_by']) : '—' ?></strong></div>
                <div><span class="label"><?= e(t('payment_method')) ?></span><strong><?= $row['payment_method'] ? e($row['payment_method']) : '—' ?></strong></div>
            </div>
        </section>

        <section class="signoff">
            <p class="declaration"><?= e(t('sign_before_filing')) ?></p>
            <div class="signature-grid">
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('employee_signature')) ?></div></div>
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('received_by')) ?></div></div>
                <div><div class="signature-field"></div><div class="signature-label"><?= e(t('date')) ?></div></div>
            </div>
        </section>
    </main>
    <?php endforeach; ?>
    <?php if (($_GET['auto_print'] ?? '') === '1'): ?>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
