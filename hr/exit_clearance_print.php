<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

ensure_employee_exit_clearance_schema();

if ($pdo->query("SHOW TABLES LIKE 'employee_loans'")->fetchColumn()) {
    ensure_loan_repayment_schema();
}

$id = (int) ($_GET['id'] ?? 0);
$clearanceStmt = $pdo->prepare("SELECT c.*, e.employee_code, e.name AS employee_name, e.designation, e.department, e.phone, e.date_of_joining, requested.name AS requested_by_name, approver.name AS approved_by_name, completed.name AS completed_by_name FROM employee_exit_clearances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users requested ON requested.id = c.requested_by LEFT JOIN users approver ON approver.id = c.approved_by LEFT JOIN users completed ON completed.id = c.completed_by WHERE c.id = ?");
$clearanceStmt->execute([$id]);
$clearance = $clearanceStmt->fetch();
if (!$clearance) {
    http_response_code(404);
    exit('Exit clearance not found.');
}

$loans = [];
$manualSettlements = [];
if ($pdo->query("SHOW TABLES LIKE 'employee_loans'")->fetchColumn()) {
    $loanStmt = $pdo->prepare("SELECT id, purpose, loan_amount, remaining_balance FROM employee_loans WHERE employee_id = ? AND approval_status = 'approved' ORDER BY request_date");
    $loanStmt->execute([(int) $clearance['employee_id']]);
    $loans = $loanStmt->fetchAll();

    $repaymentsExist = $pdo->query("SHOW TABLES LIKE 'loan_repayments'")->fetchColumn();
    if ($repaymentsExist) {
        $settlementStmt = $pdo->prepare("SELECT lr.amount_deducted, lr.deduction_date, lr.reference_no, lr.payment_method, el.purpose FROM loan_repayments lr JOIN employee_loans el ON el.id = lr.loan_id WHERE el.employee_id = ? AND lr.source = 'manual' ORDER BY lr.deduction_date DESC");
        $settlementStmt->execute([(int) $clearance['employee_id']]);
        $manualSettlements = $settlementStmt->fetchAll();
    }
}

$payrollStmt = $pdo->prepare("SELECT month, year, net_salary, payment_status FROM payroll WHERE employee_id = ? AND LOWER(COALESCE(payment_status, 'pending')) <> 'paid' AND net_salary > 0 ORDER BY year, month");
$payrollStmt->execute([(int) $clearance['employee_id']]);
$unpaidPayroll = $payrollStmt->fetchAll();

$loanOutstanding = 0.0;
$loanPaid = 0.0;
foreach ($loans as $loan) {
    $loanOutstanding += max(0, (float) $loan['remaining_balance']);
    $loanPaid += max(0, (float) $loan['loan_amount'] - (float) $loan['remaining_balance']);
}
$unpaidPayrollTotal = array_sum(array_map(static function ($row) {
    return (float) $row['net_salary'];
}, $unpaidPayroll));
$company = company_settings();
$pageTitle = 'Employee Exit Clearance Form';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e($clearance['employee_name']) ?></title>
    <style>
        :root{font-family:Arial,sans-serif;color:#1c2250;font-size:13px}*{box-sizing:border-box}body{margin:0;background:#edf2f1}.toolbar{max-width:900px;margin:18px auto;display:flex;justify-content:space-between;gap:10px}.toolbar a,.toolbar button{padding:10px 14px;border:0;border-radius:4px;background:#4f4fc9;color:#fff;text-decoration:none;font-size:14px;cursor:pointer}.toolbar .back{background:#52616b}.sheet{max-width:900px;margin:0 auto 28px;padding:34px;background:#fff;box-shadow:0 4px 18px #19323c1a}.company-head{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #4f4fc9;padding-bottom:15px}.company-head h1{margin:0;color:#1c2250;font-size:22px}.company-head p{margin:5px 0 0;color:#64748b}.doc-meta{text-align:right;color:#64748b}.doc-meta strong{display:block;color:#1c2250}.form-title{text-align:center;margin:22px 0 16px;font-size:20px;text-transform:uppercase;color:#1c2250}.status{padding:10px 14px;margin-bottom:18px;border:1px solid #cbd5d1;background:#f4f7f6;font-weight:bold;text-align:center;text-transform:uppercase}.status.completed{background:#eaf6ee;color:#17643a}.status.pending{background:#fff7e6;color:#8b5a00}.status.cancelled{background:#fceeee;color:#8b2525}.section{margin-top:20px}.section h2{margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid #9aada9;color:#4f4fc9;font-size:14px;text-transform:uppercase}.details{display:grid;grid-template-columns:repeat(3,1fr);gap:0 18px}.detail{padding:8px 0;border-bottom:1px solid #e1e8e6}.detail label{display:block;color:#646b8c;font-size:10px;text-transform:uppercase}.detail strong{display:block;margin-top:3px;font-size:13px;overflow-wrap:anywhere}.reason{min-height:50px;white-space:pre-wrap}.data{width:100%;border-collapse:collapse;margin:8px 0}.data th,.data td{padding:8px;text-align:left;border:1px solid #d4dfdc;vertical-align:top}.data th{background:#edf4f2;color:#1c2250}.amount{text-align:right!important;white-space:nowrap}.empty{color:#646b8c;font-style:italic}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:10px}.summary div{padding:10px;border:1px solid #d4dfdc}.summary label{display:block;color:#646b8c;font-size:10px;text-transform:uppercase}.summary strong{display:block;margin-top:4px;font-size:15px}.checklist{display:grid;grid-template-columns:1fr 1fr;gap:12px 24px}.check{display:flex;align-items:flex-start;gap:8px;min-height:22px}.box{width:14px;height:14px;border:1px solid #52616b;flex:0 0 14px;margin-top:1px}.signatures{display:grid;grid-template-columns:repeat(2,1fr);gap:34px 30px;margin-top:32px}.signature{height:48px;border-bottom:1px solid #52616b;display:flex;align-items:flex-end;padding-bottom:6px;color:#52616b}.signature-label{display:flex;justify-content:space-between;gap:12px;margin-top:5px;font-size:11px}.ack{margin-top:22px;line-height:1.5}.foot{margin-top:24px;padding-top:9px;border-top:1px solid #d4dfdc;color:#646b8c;font-size:10px;display:flex;justify-content:space-between;gap:12px}@media print{@page{size:A4;margin:12mm}body{background:#fff}.toolbar{display:none}.sheet{max-width:none;margin:0;padding:0;box-shadow:none}.status,.section,.data tr,.detail,.summary div,.signature{break-inside:avoid;page-break-inside:avoid}thead{display:table-header-group}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}@media(max-width:650px){.toolbar,.company-head{margin-left:12px;margin-right:12px;flex-direction:column}.sheet{margin:0 12px 20px;padding:20px}.doc-meta{text-align:left}.details{grid-template-columns:repeat(2,1fr)}.summary{grid-template-columns:1fr}.signatures{grid-template-columns:1fr}.checklist{grid-template-columns:1fr}}
    </style>
</head>
<body>
<nav class="toolbar no-print">
    <a class="back" href="exit_clearance.php?<?= $clearance['status'] === 'pending' ? 'clearance_id=' . (int) $clearance['id'] : '' ?>">Back to exit clearance</a>
    <button type="button" onclick="window.print()">Print clearance form</button>
</nav>
<main class="sheet">
    <header class="company-head">
        <div>
            <h1><?= e(company_display_name()) ?></h1>
            <?php if (!empty($company['address'])): ?><p><?= e($company['address']) ?></p><?php endif; ?>
            <?php if (!empty($company['phone'])): ?><p><?= e($company['phone']) ?></p><?php endif; ?>
        </div>
        <div class="doc-meta">
            <strong>EXIT CLEARANCE</strong>
            Form No. EC-<?= str_pad((string) $clearance['id'], 6, '0', STR_PAD_LEFT) ?><br>
            Printed <?= e(date('d M Y H:i')) ?>
        </div>
    </header>

    <h1 class="form-title">Employee Separation &amp; Clearance Form</h1>
    <div class="status <?= e($clearance['status']) ?>">Status: <?= e($clearance['status']) ?></div>

    <section class="section">
        <h2>Employee and separation details</h2>
        <div class="details">
            <div class="detail"><label>Employee name</label><strong><?= e($clearance['employee_name']) ?></strong></div>
            <div class="detail"><label>Employee code</label><strong><?= e($clearance['employee_code']) ?></strong></div>
            <div class="detail"><label>Designation</label><strong><?= e($clearance['designation'] ?: '-') ?></strong></div>
            <div class="detail"><label>Department</label><strong><?= e($clearance['department'] ?: '-') ?></strong></div>
            <div class="detail"><label>Phone</label><strong><?= e($clearance['phone'] ?: '-') ?></strong></div>
            <div class="detail"><label>Date joined</label><strong><?= $clearance['date_of_joining'] ? e(date('d M Y', strtotime($clearance['date_of_joining']))) : '-' ?></strong></div>
            <div class="detail"><label>Last working day</label><strong><?= e(date('d M Y', strtotime($clearance['last_working_day']))) ?></strong></div>
            <div class="detail"><label>Request date</label><strong><?= e(date('d M Y', strtotime($clearance['requested_at']))) ?></strong></div>
            <div class="detail"><label>Requested by</label><strong><?= e($clearance['requested_by_name'] ?: '-') ?></strong></div>
            <div class="detail"><label>Approved date</label><strong><?= $clearance['approved_at'] ? e(date('d M Y', strtotime($clearance['approved_at']))) : '-' ?></strong></div>
            <div class="detail"><label>Approved by</label><strong><?= e($clearance['approved_by_name'] ?: '-') ?></strong></div>
            <div class="detail"><label>Completed date</label><strong><?= $clearance['completed_at'] ? e(date('d M Y', strtotime($clearance['completed_at']))) : '-' ?></strong></div>
            <div class="detail"><label>Completed by</label><strong><?= e($clearance['completed_by_name'] ?: '-') ?></strong></div>
        </div>
        <div class="detail reason"><label>Reason for departure</label><strong><?= e($clearance['reason']) ?></strong></div>
    </section>

    <section class="section">
        <h2>Financial clearance</h2>
        <table class="data">
            <thead><tr><th>Employee loan</th><th class="amount">Original amount</th><th class="amount">Amount repaid</th><th class="amount">Balance due</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$loans): ?><tr><td class="empty" colspan="5">No approved employee loans.</td></tr><?php endif; ?>
            <?php foreach ($loans as $loan): ?>
                <?php $balance = max(0, (float) $loan['remaining_balance']); $paid = max(0, (float) $loan['loan_amount'] - $balance); ?>
                <tr>
                    <td><?= e($loan['purpose'] ?: 'Employee loan') ?></td>
                    <td class="amount"><?= e(currency_symbol()) . number_format((float) $loan['loan_amount'], 2) ?></td>
                    <td class="amount"><?= e(currency_symbol()) . number_format($paid, 2) ?></td>
                    <td class="amount"><?= e(currency_symbol()) . number_format($balance, 2) ?></td>
                    <td><?= $balance <= 0.009 ? 'Settled' : 'Outstanding' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($manualSettlements): ?>
        <table class="data">
            <thead><tr><th>Full payoff receipt</th><th>Payment method</th><th>Reference</th><th>Date</th><th class="amount">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($manualSettlements as $settlement): ?>
                <tr>
                    <td><?= e($settlement['purpose'] ?: 'Employee loan') ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', $settlement['payment_method'] ?: '-'))) ?></td>
                    <td><?= e($settlement['reference_no'] ?: '-') ?></td>
                    <td><?= e(date('d M Y', strtotime($settlement['deduction_date']))) ?></td>
                    <td class="amount"><?= e(currency_symbol()) . number_format((float) $settlement['amount_deducted'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <table class="data">
            <thead><tr><th>Unpaid payroll period</th><th>Status</th><th class="amount">Net pay due to employee</th></tr></thead>
            <tbody>
            <?php if (!$unpaidPayroll): ?><tr><td class="empty" colspan="3">No unpaid payroll records.</td></tr><?php endif; ?>
            <?php foreach ($unpaidPayroll as $payroll): ?>
                <tr>
                    <td><?= e(date('F Y', mktime(0, 0, 0, (int) $payroll['month'], 1, (int) $payroll['year']))) ?></td>
                    <td><?= e(ucfirst($payroll['payment_status'] ?: 'pending')) ?></td>
                    <td class="amount"><?= e(currency_symbol()) . number_format((float) $payroll['net_salary'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="summary">
            <div><label>Outstanding loan balance</label><strong><?= e(currency_symbol()) . number_format($loanOutstanding, 2) ?></strong></div>
            <div><label>Unpaid payroll due to employee</label><strong><?= e(currency_symbol()) . number_format($unpaidPayrollTotal, 2) ?></strong></div>
            <div><label>Financial clearance</label><strong><?= $loanOutstanding <= 0.009 && !$unpaidPayroll ? 'Clear' : 'Pending' ?></strong></div>
        </div>
    </section>

    <section class="section">
        <h2>Department and property clearance</h2>
        <div class="checklist">
            <div class="check"><span class="box"></span><span>Finance: loans and advances settled</span></div>
            <div class="check"><span class="box"></span><span>Payroll: salary and final pay settled</span></div>
            <div class="check"><span class="box"></span><span>IT: equipment and access returned/closed</span></div>
            <div class="check"><span class="box"></span><span>Stores: tools, keys, and property returned</span></div>
            <div class="check"><span class="box"></span><span>Department manager: handover completed</span></div>
            <div class="check"><span class="box"></span><span>HR: records and exit interview completed</span></div>
        </div>
    </section>

    <p class="ack">I acknowledge the clearance items recorded above and confirm the return of company property and completion of the required handover.</p>
    <section class="signatures">
        <div><div class="signature"></div><div class="signature-label"><span>Employee signature</span><span>Date: __________</span></div></div>
        <div><div class="signature"></div><div class="signature-label"><span>Finance officer</span><span>Date: __________</span></div></div>
        <div><div class="signature"></div><div class="signature-label"><span>Department manager</span><span>Date: __________</span></div></div>
        <div><div class="signature"></div><div class="signature-label"><span>HR officer</span><span>Date: __________</span></div></div>
    </section>
    <footer class="foot"><span>Employee exit clearance record EC-<?= str_pad((string) $clearance['id'], 6, '0', STR_PAD_LEFT) ?></span><span>Generated <?= e(date('d M Y H:i')) ?></span></footer>
</main>
</body>
</html>