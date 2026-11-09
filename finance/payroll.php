<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = t('payroll');
require_once "../partials/header.php";

// Add workflow columns if they don't exist
$columns = $pdo->query("SHOW COLUMNS FROM payroll")->fetchAll();
$columnNames = array_column($columns, 'Field');

if (!in_array('payment_date', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN payment_date DATE");
}
if (!in_array('paid_by', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN paid_by INT");
}
if (!in_array('workflow_status', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN workflow_status ENUM('draft','pending_finance','approved','rejected','transferred','paid') DEFAULT 'draft'");
}
if (!in_array('finance_approved_by', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN finance_approved_by INT");
}
if (!in_array('finance_approved_date', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN finance_approved_date DATE");
}
if (!in_array('bank_reference', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN bank_reference VARCHAR(100)");
}
if (!in_array('transfer_date', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN transfer_date DATE");
}
if (!in_array('overtime_hours', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN overtime_hours DECIMAL(5,2) DEFAULT 0");
}
if (!in_array('overtime_payment', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN overtime_payment DECIMAL(10,2) DEFAULT 0");
}
if (!in_array('unpaid_days', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN unpaid_days DECIMAL(5,2) NOT NULL DEFAULT 0");
    $columnNames[] = 'unpaid_days';
}
if (!in_array('attendance_deduction', $columnNames)) {
    $pdo->exec("ALTER TABLE payroll ADD COLUMN attendance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0");
    $columnNames[] = 'attendance_deduction';
}

$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? date('Y'));
$month = $month >= 1 && $month <= 12 ? $month : (int) date('m');
$year = $year >= 1900 && $year <= (int) date('Y') ? $year : (int) date('Y');
$payrollEndDate = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

// Payroll cannot be generated for a period that has not started yet.
$currentPeriodStart = mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'));
$requestedPeriodStart = mktime(0, 0, 0, $month, 1, $year);
$isFuturePeriod = $requestedPeriodStart > $currentPeriodStart;

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if($isFuturePeriod) {
        // Guard: never allow payroll for a month that has not begun.
        $returnUrl = "payroll.php?month=$month&year=$year";
        $showModal = true;
        $modalType = 'error';
        $modalTitle = '🚫 Future Pay Period';
        $modalMessage = 'Payroll for ' . format_app_month_year($month, $year) . ' cannot be generated because that period has not started yet.';
        $modalRedirect = $returnUrl;
    }
    // Gate: HR must submit attendance for this period (hr/attendance_submit.php)
    // and finance must approve it before payroll can be generated.
    elseif(!attendance_approved_for_period($month, $year)) {
        $returnUrl = "payroll.php?month=$month&year=$year";
        $showModal = true;
        $modalType = 'error';
        $modalTitle = '🚫 Attendance Not Approved';
        $modalMessage = 'HR must submit attendance for ' . format_app_month_year($month, $year) . ' and finance must approve it before payroll can be generated.';
        $modalRedirect = $returnUrl;
    } else {
    // Check if payroll for this month/year already exists and is in final stages
    $checkPaid = $pdo->prepare("SELECT COUNT(*) FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.month=? AND p.year=? AND p.workflow_status IN ('transferred', 'approved', 'paid') AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?)");
    $checkPaid->execute([$month, $year, $payrollEndDate]);
    $paidCount = $checkPaid->fetchColumn();
    
    if($paidCount > 0) {
        // Get the actual status to show appropriate message
        $statusStmt = $pdo->prepare("SELECT DISTINCT p.workflow_status FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.month=? AND p.year=? AND p.workflow_status IN ('transferred', 'approved', 'paid') AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?) LIMIT 1");
        $statusStmt->execute([$month, $year, $payrollEndDate]);
        $currentStatus = $statusStmt->fetchColumn();
        
        $statusMessages = [
            'transferred' => 'Payroll has been transferred to finance and cannot be regenerated.',
            'approved' => 'Payroll has been approved by finance and cannot be regenerated.', 
            'paid' => 'Payroll has been paid and cannot be regenerated.'
        ];
        
        $alertMessage = $statusMessages[$currentStatus] ?? 'Payroll is in final stages and cannot be regenerated.';
        $returnUrl = "payroll.php?month=$month&year=$year";
        $showModal = true;
        $modalType = 'error';
        $modalTitle = '🚫 Payroll Cannot Be Regenerated';
        $modalMessage = $alertMessage . ' Month: ' . format_app_month_year($month, $year);
        $modalRedirect = $returnUrl;
        // Stop here: generation only runs when no final payroll exists.
    } else {

    $stmt = $pdo->prepare("SELECT * FROM employees WHERE status=1 AND (date_of_joining IS NULL OR date_of_joining <= ?)");
    $stmt->execute([$payrollEndDate]);
    while($emp = $stmt->fetch()) {
        // Check if employee was employed during this payroll period
        $skipEmployee = false;
        if(!empty($emp['date_of_joining'])) {
            $joinDate = strtotime($emp['date_of_joining']);
            $periodEndDate = strtotime($payrollEndDate);
            if($joinDate > $periodEndDate) {
                $skipEmployee = true;
            }
        }
        
        if($skipEmployee) {
            continue; // Skip this employee if they joined after the payroll period
        }
        
        $basic = $emp['basic_salary'];
        $allowances = $_POST['allowances'][$emp['id']] ?? 0;
        $deductions = $_POST['deductions'][$emp['id']] ?? 0;
        
        // Calculate overtime payment for this employee
        $overtimePayment = 0;
        $overtimeStmt = $pdo->prepare("SELECT SUM(hours) as total_hours FROM overtime_requests WHERE employee_id=? AND status='approved' AND MONTH(overtime_date)=? AND YEAR(overtime_date)=?");
        $overtimeStmt->execute([$emp['id'], $month, $year]);
        $overtimeResult = $overtimeStmt->fetch();
        $overtimeHours = $overtimeResult['total_hours'] ?? 0;
        
        // Calculate overtime rate (1.5x regular hourly rate)
        // Assuming 30 working days per month and 8 hours per day = 240 hours per month
        $regularHourlyRate = $emp['basic_salary'] / 240;
        $overtimeRate = $regularHourlyRate * 1.5;
        $overtimePayment = $overtimeHours * $overtimeRate;
        
        // Calculate loan deductions for this employee
        $loanDeduction = 0;
        $loanStmt = $pdo->prepare("SELECT id, monthly_payment, remaining_balance, start_deduction_date, end_deduction_date FROM employee_loans WHERE employee_id=? AND approval_status='approved' AND remaining_balance > 0");
        $loanStmt->execute([$emp['id']]);
        $activeLoans = $loanStmt->fetchAll();
        
        foreach($activeLoans as $loan) {
            // Check if this payroll month is within the loan deduction period
            $payrollDate = mktime(0,0,0,$month,1,$year);
            $startDate = strtotime($loan['start_deduction_date']);
            $endDate = strtotime($loan['end_deduction_date']);
            
            if($payrollDate >= $startDate && $payrollDate <= $endDate) {
                // Check if this month's payroll deduction has already been made.
                // Manual repayments are separate from payroll instalments.
                $checkDeduction = $pdo->prepare("SELECT amount_deducted FROM loan_repayments WHERE loan_id=? AND payroll_month=? AND payroll_year=? AND source='payroll' ORDER BY id DESC LIMIT 1");
                $checkDeduction->execute([$loan['id'], $month, $year]);
                $alreadyDeducted = $checkDeduction->fetchColumn();
                
                if($alreadyDeducted === false) {
                    // Calculate this month's deduction (don't exceed remaining balance)
                    $thisMonthDeduction = min($loan['monthly_payment'], $loan['remaining_balance']);
                    $loanDeduction += $thisMonthDeduction;
                } else {
                    // A previous run already recovered this period's instalment;
                    // keep the actual amount in total deductions when payroll is regenerated.
                    $loanDeduction += (float) $alreadyDeducted;
                }
            }
        }
        
        $totalDeductions = $deductions + $loanDeduction;
        
        // Attendance and leave: absences, half-days and approved unpaid leave
        // reduce pay. Paid leave types do not.
        $attendanceSummary = payroll_attendance_summary($emp['id'], $month, $year, $basic);
        $attendanceDeduction = (float) $attendanceSummary['deduction'];
        $unpaidDays = (float) $attendanceSummary['unpaid_days'];
        $totalDeductions += $attendanceDeduction;
        $net = $basic + $allowances + $overtimePayment - $totalDeductions;
        if ($net < 0) {
            $net = 0.0;
        }
        
        $sql = "INSERT INTO payroll(employee_id, month, year, basic_salary, allowances, overtime_hours, overtime_payment, unpaid_days, attendance_deduction, deductions, net_salary, created_by, workflow_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), allowances=VALUES(allowances), overtime_hours=VALUES(overtime_hours), overtime_payment=VALUES(overtime_payment), unpaid_days=VALUES(unpaid_days), attendance_deduction=VALUES(attendance_deduction), deductions=VALUES(deductions), net_salary=VALUES(net_salary), workflow_status='transferred'";
        $pdo->prepare($sql)->execute([$emp['id'], $month, $year, $basic, $allowances, $overtimeHours, $overtimePayment, $unpaidDays, $attendanceDeduction, $totalDeductions, $net, $_SESSION['user_id'], 'transferred']);
        
        // Record loan repayments
        foreach($activeLoans as $loan) {
            $payrollDate = mktime(0,0,0,$month,1,$year);
            $startDate = strtotime($loan['start_deduction_date']);
            $endDate = strtotime($loan['end_deduction_date']);
            
            if($payrollDate >= $startDate && $payrollDate <= $endDate) {
                $checkDeduction = $pdo->prepare("SELECT id FROM loan_repayments WHERE loan_id=? AND payroll_month=? AND payroll_year=? AND source='payroll' LIMIT 1");
                $checkDeduction->execute([$loan['id'], $month, $year]);
                $alreadyDeducted = $checkDeduction->fetchColumn();
                
                if(!$alreadyDeducted) {
                    $thisMonthDeduction = min($loan['monthly_payment'], $loan['remaining_balance']);
                    
                    // Record repayment
                    $pdo->prepare("INSERT INTO loan_repayments(loan_id, payroll_month, payroll_year, amount_deducted, deduction_date) VALUES(?,?,?,?,CURDATE())")->execute([
                        $loan['id'], $month, $year, $thisMonthDeduction
                    ]);
                    
                    // Update remaining balance
                    $newBalance = $loan['remaining_balance'] - $thisMonthDeduction;
                    $pdo->prepare("UPDATE employee_loans SET remaining_balance=? WHERE id=?")->execute([$newBalance, $loan['id']]);
                }
            }
        }
    }
    
    // Create notification for finance users
    $financeUsers = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'accountant', 'finance')")->fetchAll();
    foreach($financeUsers as $financeUser) {
        $pdo->prepare("INSERT INTO user_notifications(user_id, message, link, created_at) VALUES(?,?,?,NOW())")->execute([
            $financeUser['id'],
            t('payroll_ready_finance') . ' ' . format_app_month_year($month, $year),
            "/block_plant/finance/payroll_approval.php?month=$month&year=$year"
        ]);
    }
    
    $printUrl = "payroll_print.php?month=$month&year=$year&auto_print=1";
    $showModal = true;
    $modalType = 'success';
    $modalTitle = '✅ Payroll Generated Successfully';
    $modalMessage = t('payroll_generated_success');
    $modalRedirect = $printUrl;
    } // End of: no final payroll exists for this period
    } // End of: attendance submission approved for this period
} // End of generate handler

// Send back to finance for revision
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_back'])) {
    $stmt = $pdo->prepare("UPDATE payroll SET workflow_status='draft', payment_status='pending', payment_date=NULL, paid_by=NULL, bank_reference=NULL, transfer_date=NULL WHERE month=? AND year=? AND employee_id IN (SELECT id FROM employees WHERE status=1 AND (date_of_joining IS NULL OR date_of_joining <= ?))");
    $stmt->execute([$month, $year, $payrollEndDate]);
    $returnUrl = "payroll.php?month=$month&year=$year";
    $showModal = true;
    $modalType = 'success';
    $modalTitle = '📤 Payroll Sent Back';
    $modalMessage = t('payroll_sent_back_success');
    $modalRedirect = $returnUrl;
}

$stmt = $pdo->prepare("SELECT e.*, p.basic_salary as pay_basic, p.allowances, p.overtime_hours, p.overtime_payment, p.deductions, p.unpaid_days, p.attendance_deduction, p.net_salary, p.payment_status, p.payment_date, p.paid_by, p.workflow_status, p.finance_approved_by, p.finance_approved_date, p.bank_reference, p.transfer_date FROM employees e LEFT JOIN payroll p ON e.id=p.employee_id AND p.month=? AND p.year=? WHERE e.status=1 AND (e.date_of_joining IS NULL OR e.date_of_joining <= ?) ORDER BY e.name");
$stmt->execute([$month, $year, $payrollEndDate]);
$employees = $stmt->fetchAll();
$generatedPayrollStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE month = ? AND year = ?");
$generatedPayrollStmt->execute([$month, $year]);
$generatedPayrollCount = (int) $generatedPayrollStmt->fetchColumn();

// Get overall workflow status for the month
$workflowStatus = $pdo->prepare("SELECT p.workflow_status FROM payroll p JOIN employees e ON e.id = p.employee_id WHERE p.month=? AND p.year=? AND e.status=1 LIMIT 1");
$workflowStatus->execute([$month, $year]);
$overallStatus = $workflowStatus->fetchColumn() ?: 'draft';

// Attendance submission status for this period (HR -> Finance hand-off)
$attendanceSubmission = get_attendance_submission($month, $year);
$attendanceApproved = $attendanceSubmission && $attendanceSubmission['status'] === 'approved';
?>

<h1>💰 <?= t('payroll_management') ?></h1>

<?php if($isFuturePeriod): ?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid #dc2626; background:#fef2f2;">
    <strong style="font-size:1.1rem;">📅 Future Pay Period - <?= e(format_app_month_year($month, $year)) ?></strong>
    <div style="color:#991b1b; font-size:0.9rem; margin-top:4px;">
        This period has not started yet (today is <?= e(date('d M Y')) ?>). Payroll cannot be generated for future months.
        <?php if($generatedPayrollCount > 0): ?>
        <br><strong><?= number_format($generatedPayrollCount) ?> payroll record(s) already exist for this future period.</strong> Review them below - they should not be paid until the period begins.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Attendance Submission Gate Banner -->
<?php if(!$attendanceApproved): ?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid #f59e0b; background:#fffbeb;">
    <strong style="font-size:1.1rem;">⏳ Attendance Not Approved for <?= e(format_app_month_year($month, $year)) ?></strong>
    <div style="color:#92400e; font-size:0.9rem; margin-top:4px;">
        <?php if(!$attendanceSubmission): ?>
        HR has not submitted attendance for this period yet. Payroll generation is blocked until HR submits and finance approves it.
        <?php elseif($attendanceSubmission['status'] === 'pending'): ?>
        Submitted by <?= e($attendanceSubmission['submitted_by_name'] ?? 'Unknown') ?> on <?= date('d M Y H:i', strtotime($attendanceSubmission['submitted_at'])) ?> - waiting for finance approval.
        <?php else: ?>
        Finance rejected this submission. HR must correct the attendance and resubmit it.
        <?php endif; ?>
        <a href="attendance_review.php?month=<?= $month ?>&year=<?= $year ?>" style="margin-left:6px;">Review attendance</a>
        &middot;
        <a href="/block_plant/hr/attendance_submit.php?month=<?= $month ?>&year=<?= $year ?>">HR submission page</a>
    </div>
</div>
<?php else: ?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid #10b981; background:#f0fdf4;">
    <strong style="font-size:1.1rem;">✅ Attendance Approved for <?= e(format_app_month_year($month, $year)) ?></strong>
    <div style="color:#065f46; font-size:0.9rem; margin-top:4px;">
        Approved by <?= e($attendanceSubmission['approved_by_name'] ?? 'Unknown') ?> on <?= date('d M Y H:i', strtotime($attendanceSubmission['approved_at'])) ?>. Payroll can be generated for this period.
    </div>
</div>
<?php endif; ?>

<!-- Workflow Status Banner -->
<?php if($overallStatus !== 'draft'): ?>
<?php
$statusTitle = t('unknown_status');
$statusMessage = t('status_unknown');
$statusBg = '#f8fafc';
$statusColor = '#64748b';

if($overallStatus === 'transferred') {
    $statusTitle = '🏦 ' . t('transferred_to_finance');
    $statusMessage = t('payroll_ready_finance');
    $statusBg = '#e0e7ff';
    $statusColor = '#6366f1';
} elseif($overallStatus === 'paid') {
    $statusTitle = '✅ ' . t('approved_paid');
    $statusMessage = t('payroll_paid_message');
    $statusBg = '#d1fae5';
    $statusColor = '#10b981';
} elseif($overallStatus === 'rejected') {
    $statusTitle = '❌ ' . t('rejected_by_finance');
    $statusMessage = t('payroll_rejected_message');
    $statusBg = '#fee2e2';
    $statusColor = '#ef4444';
}
?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid <?= $statusColor ?>; background: <?= $statusBg ?>;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <strong style="font-size:1.1rem;"><?= $statusTitle ?></strong>
            <div style="color:#6a6259; font-size:0.9rem; margin-top:4px;"><?= $statusMessage ?></div>
        </div>
        <?php if($overallStatus === 'rejected'): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="send_back" value="1">
            <button type="submit" class="btn btn-sm" style="background:#64748b;"><?= t('send_back_revision') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <label><?= t('month') ?>:</label>
    <select name="month" onchange="this.form.submit()">
        <?php for($m=1; $m<=12; $m++): ?>
        <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= e(format_app_month_name($m)) ?></option>
        <?php endfor; ?>
    </select>
    
    <label><?= t('year') ?>:</label>
    <select name="year" onchange="this.form.submit()">
        <?php for($y=date('Y'); $y>=date('Y')-5; $y--): ?>
        <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
</form>

<form method="post">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <table>
            <thead>
                <tr>
                    <th><?= t('employee_name') ?></th>
                    <th><?= t('basic_salary') ?></th>
                    <th><?= t('allowances') ?></th>
                    <th><?= t('overtime_hours') ?></th>
                    <th><?= t('overtime_payment') ?></th>
                    <th><?= t('other_deductions') ?></th>
                    <th><?= t('loan_deduction') ?></th>
                    <th><?= t('unpaid_days') ?></th>
                    <th><?= t('attendance_deduction') ?></th>
                    <th><?= t('total_deductions') ?></th>
                    <th><?= t('net_salary') ?></th>
                    <th><?= t('status') ?></th>
                    <th><?= t('payment_date') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($employees as $emp): ?>
                <?php
                // Calculate loan deduction for display
                $loanDeduction = 0;
                $loanStmt = $pdo->prepare("SELECT SUM(monthly_payment) as total FROM employee_loans WHERE employee_id=? AND approval_status='approved' AND remaining_balance > 0 AND (start_deduction_date <= ? AND end_deduction_date >= ?)");
                $payrollDate = date('Y-m-d', mktime(0,0,0,$month,1,$year));
                $loanStmt->execute([$emp['id'], $payrollDate, $payrollDate]);
                $loanResult = $loanStmt->fetch();
                $loanDeduction = $loanResult['total'] ?? 0;
                
                // Calculate overtime for display (if not already processed)
                $overtimeHours = $emp['overtime_hours'] ?? 0;
                $overtimePayment = $emp['overtime_payment'] ?? 0;
                if (!$overtimeHours) {
                    $overtimeStmt = $pdo->prepare("SELECT SUM(hours) as total_hours FROM overtime_requests WHERE employee_id=? AND status='approved' AND MONTH(overtime_date)=? AND YEAR(overtime_date)=?");
                    $overtimeStmt->execute([$emp['id'], $month, $year]);
                    $overtimeResult = $overtimeStmt->fetch();
                    $overtimeHours = $overtimeResult['total_hours'] ?? 0;
                    
                    // Calculate overtime payment
                    $regularHourlyRate = $emp['basic_salary'] / 240; // 30 days * 8 hours
                    $overtimeRate = $regularHourlyRate * 1.5;
                    $overtimePayment = $overtimeHours * $overtimeRate;
                }
                
                // Check if loan deduction was already applied
                $checkApplied = $pdo->prepare("SELECT SUM(lr.amount_deducted) as applied FROM loan_repayments lr JOIN employee_loans el ON lr.loan_id = el.id WHERE el.employee_id=? AND lr.payroll_month=? AND lr.payroll_year=? AND lr.source='payroll'");
                $checkApplied->execute([$emp['id'], $month, $year]);
                $appliedResult = $checkApplied->fetch();
                $appliedLoanDeduction = $appliedResult['applied'] ?? 0;

                // Attendance + leave deduction for this period. Generated rows
                // keep the stored snapshot; ungenerated rows show a live estimate.
                $attendanceSummary = payroll_attendance_summary($emp['id'], $month, $year, (float) $emp['basic_salary']);
                $hasPayrollRow = $emp['pay_basic'] !== null;
                $storedDeductions = (float) ($emp['deductions'] ?? 0);
                $grossDisplay = (float) ($emp['pay_basic'] ?? $emp['basic_salary']) + (float) ($emp['allowances'] ?? 0) + (float) $overtimePayment;
                if ($hasPayrollRow) {
                    // Stored deductions/net are authoritative once a payroll row exists.
                    $attendanceDeductionDisplay = (float) ($emp['attendance_deduction'] ?? 0);
                    $unpaidDaysDisplay = (float) ($emp['unpaid_days'] ?? 0);
                    $totalDeductionsDisplay = $storedDeductions;
                    $loanDeductionDisplay = min((float) $appliedLoanDeduction, max(0.0, $storedDeductions - $attendanceDeductionDisplay));
                    $otherDeductionsDisplay = max(0.0, $storedDeductions - $attendanceDeductionDisplay - $loanDeductionDisplay);
                    $netDisplay = (float) $emp['net_salary'];
                } else {
                    $attendanceDeductionDisplay = (float) $attendanceSummary['deduction'];
                    $unpaidDaysDisplay = (float) $attendanceSummary['unpaid_days'];
                    $loanDeductionDisplay = (float) $loanDeduction;
                    $otherDeductionsDisplay = 0.0;
                    $totalDeductionsDisplay = $loanDeductionDisplay + $attendanceDeductionDisplay;
                    $netDisplay = $grossDisplay - $totalDeductionsDisplay;
                }
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($emp['name']) ?>
                        <?php if ($emp['pay_basic'] !== null): ?>
                        <div style="font-size:0.8rem;"><a href="payslip.php?employee_id=<?= (int) $emp['id'] ?>&month=<?= $month ?>&year=<?= $year ?>" target="_blank" rel="noopener"><?= t('payslip') ?></a></div>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($emp['basic_salary'], 2) ?></td>
                    <td><input type="number" step="0.01" name="allowances[<?= $emp['id'] ?>]" value="<?= $emp['allowances'] ?? 0 ?>" style="width: 100px;"></td>
                    <td style="background:#e6f7ff; text-align:center;"><?= number_format($overtimeHours, 1) ?>h</td>
                    <td style="background:#e6f7ff;"><?= number_format($overtimePayment, 2) ?></td>
                    <td><input type="number" step="0.01" name="deductions[<?= $emp['id'] ?>]" value="<?= number_format($otherDeductionsDisplay, 2, '.', '') ?>" style="width: 100px;"></td>
                    <td style="background:#fff3cd;"><?= number_format($loanDeductionDisplay, 2) ?></td>
                    <td style="background:#fee2e2; text-align:center;" title="<?= e($attendanceSummary['details']) ?>"><?= $unpaidDaysDisplay > 0 ? number_format($unpaidDaysDisplay, 1) : '—' ?></td>
                    <td style="background:#fee2e2;"><?= number_format($attendanceDeductionDisplay, 2) ?></td>
                    <td><strong><?= number_format($totalDeductionsDisplay, 2) ?></strong></td>
                    <td><strong><?= number_format($netDisplay, 2) ?></strong></td>
                    <td>
                        <?php if($emp['payment_status']): ?>
                        <span style="padding: 5px 10px; border-radius: 5px; background: <?= $emp['payment_status'] === 'paid' ? '#d4edda' : '#fff3cd' ?>;">
                            <?= e(t('status_' . strtolower($emp['payment_status']))) ?>
                        </span>
                        <?php else: ?>
                        <span style="padding: 5px 10px; border-radius: 5px; background: #fff3cd;"><?= t('status_pending') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $emp['payment_date'] ? e(format_date($emp['payment_date'])) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 20px; display:flex; gap:10px; align-items:center;">
            <?php 
            $isPayrollFinal = in_array($overallStatus, ['transferred', 'approved', 'paid']);
            $attendanceBlocked = !$attendanceApproved;
            $futureBlocked = $isFuturePeriod;
            $buttonDisabled = ($isPayrollFinal || $attendanceBlocked || $futureBlocked) ? 'disabled' : '';
            $buttonClass = ($isPayrollFinal || $attendanceBlocked || $futureBlocked) ? 'btn btn-disabled' : 'btn';
            $buttonText = $isPayrollFinal ? 'Payroll Finalized' : ($futureBlocked ? 'Future Period - Not Available' : ($attendanceBlocked ? 'Awaiting Attendance Approval' : t('generate_transfer_finance')));
            $buttonTitle = $isPayrollFinal
                ? 'Payroll has been finalized and cannot be regenerated'
                : ($futureBlocked ? 'Payroll cannot be generated for a period that has not started yet' : ($attendanceBlocked ? 'HR must submit attendance for this period and finance must approve it first' : ''));
            ?>
            <button type="submit" name="generate" class="<?= $buttonClass ?>" <?= $buttonDisabled ?><?= $buttonTitle ? ' title="' . e($buttonTitle) . '"' : '' ?>>
                <?= $isPayrollFinal ? '🔒 ' : ($futureBlocked ? '📅 ' : ($attendanceBlocked ? '⏳ ' : '')) ?><?= $buttonText ?>
            </button>
            <?php if ($generatedPayrollCount > 0): ?>
            <a href="payroll_print.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary" target="_blank" rel="noopener"><?= t('print_admin_signoff') ?></a>
            <?php if (has_permission('reports', 'view')): ?>
            <a href="/block_plant/reports/payroll.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary"><?= t('payroll_reports') ?></a>
            <?php endif; ?>
            <a href="payroll_bank.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary">🏦 <?= t('bank_transfer_schedule') ?></a>
            <a href="payslip.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary" target="_blank" rel="noopener">🧾 <?= t('print_all_payslips') ?></a>
            <a href="payroll_journal.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary">📒 <?= t('payroll_journal') ?></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Payroll Summary -->
<?php 
$totalBasic = 0;
$totalAllowances = 0;
$totalOvertimePayment = 0;
$totalDeductions = 0;
$totalLoanDeductions = 0;
$totalAttendanceDeductions = 0;
$totalUnpaidDays = 0;
$totalNet = 0;
foreach($employees as $emp) {
    $totalBasic += ($emp['pay_basic'] ?? $emp['basic_salary']);
    $totalAllowances += ($emp['allowances'] ?? 0);
    
    // Calculate overtime payment for summary
    $overtimePayment = $emp['overtime_payment'] ?? 0;
    if (!$overtimePayment) {
        $overtimeStmt = $pdo->prepare("SELECT SUM(hours) as total_hours FROM overtime_requests WHERE employee_id=? AND status='approved' AND MONTH(overtime_date)=? AND YEAR(overtime_date)=?");
        $overtimeStmt->execute([$emp['id'], $month, $year]);
        $overtimeResult = $overtimeStmt->fetch();
        $overtimeHours = $overtimeResult['total_hours'] ?? 0;
        $regularHourlyRate = $emp['basic_salary'] / 240;
        $overtimeRate = $regularHourlyRate * 1.5;
        $overtimePayment = $overtimeHours * $overtimeRate;
    }
    $totalOvertimePayment += $overtimePayment;
    
    // Calculate loan deduction
    $loanStmt = $pdo->prepare("SELECT SUM(monthly_payment) as total FROM employee_loans WHERE employee_id=? AND approval_status='approved' AND remaining_balance > 0 AND (start_deduction_date <= ? AND end_deduction_date >= ?)");
    $payrollDate = date('Y-m-d', mktime(0,0,0,$month,1,$year));
    $loanStmt->execute([$emp['id'], $payrollDate, $payrollDate]);
    $loanResult = $loanStmt->fetch();
    $empLoanDeduction = (float) ($loanResult['total'] ?? 0);
    
    $attendanceSummary = payroll_attendance_summary($emp['id'], $month, $year, (float) $emp['basic_salary']);
    $hasPayrollRow = $emp['pay_basic'] !== null;
    $storedDeductions = (float) ($emp['deductions'] ?? 0);
    $empGross = (float) ($emp['pay_basic'] ?? $emp['basic_salary']) + (float) ($emp['allowances'] ?? 0) + (float) $overtimePayment;
    if ($hasPayrollRow) {
        $appliedStmt = $pdo->prepare("SELECT COALESCE(SUM(lr.amount_deducted),0) FROM loan_repayments lr JOIN employee_loans el ON el.id=lr.loan_id WHERE el.employee_id=? AND lr.payroll_month=? AND lr.payroll_year=? AND lr.source='payroll'");
        $appliedStmt->execute([$emp['id'], $month, $year]);
        $appliedLoan = (float) $appliedStmt->fetchColumn();
        $empAttendanceDeduction = (float) ($emp['attendance_deduction'] ?? 0);
        $empUnpaidDays = (float) ($emp['unpaid_days'] ?? 0);
        $empLoanDeduction = min($appliedLoan, max(0.0, $storedDeductions - $empAttendanceDeduction));
        $empOtherDeductions = max(0.0, $storedDeductions - $empAttendanceDeduction - $empLoanDeduction);
        $empNet = (float) $emp['net_salary'];
    } else {
        $empAttendanceDeduction = (float) $attendanceSummary['deduction'];
        $empUnpaidDays = (float) $attendanceSummary['unpaid_days'];
        $empOtherDeductions = 0.0;
        $empNet = $empGross - $empLoanDeduction - $empAttendanceDeduction;
    }
    
    $totalDeductions += $empOtherDeductions;
    $totalLoanDeductions += $empLoanDeduction;
    $totalAttendanceDeductions += $empAttendanceDeduction;
    $totalUnpaidDays += $empUnpaidDays;
    $totalNet += $empNet;
}
?>
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px;">
    <h3><?= t('payroll_summary_for') ?> <?= e(format_app_month_year($month, $year)) ?></h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1f2933;"><?= e(currency_symbol()) . number_format($totalBasic, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_basic_salary') ?></div>
        </div>
        <div style="text-align:center; padding:15px; background:#f0fdf4; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= e(currency_symbol()) . number_format($totalAllowances, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_allowances') ?></div>
        </div>
        <div style="text-align:center; padding:15px; background:#e6f7ff; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1890ff;"><?= e(currency_symbol()) . number_format($totalOvertimePayment, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_overtime_payment') ?></div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef2f2; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#ef4444;"><?= e(currency_symbol()) . number_format($totalDeductions, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_other_deductions') ?></div>
        </div>
        <div style="text-align:center; padding:15px; background:#fff3cd; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= e(currency_symbol()) . number_format($totalLoanDeductions, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_loan_deductions') ?></div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef2f2; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#dc2626;"><?= e(currency_symbol()) . number_format($totalAttendanceDeductions, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('attendance_deduction') ?> (<?= number_format($totalUnpaidDays, 1) ?> <?= e(t('unpaid_days')) ?>)</div>
        </div>
        <div style="text-align:center; padding:15px; background:#eff6ff; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#3b82f6;"><?= e(currency_symbol()) . number_format($totalNet, 2) ?></div>
            <div style="color:#6a6259; font-size:0.85rem;"><?= t('total_net_payroll') ?></div>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>

<!-- Beautiful Modal Popup System -->
<?php if (isset($showModal) && $showModal): ?>
<div id="beautifulModal" class="modal-overlay">
    <div class="modal-content <?= $modalType ?>">
        <div class="modal-header">
            <h3><?= htmlspecialchars($modalTitle) ?></h3>
            <button onclick="handleModalClose()" class="modal-close-btn" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p><?= htmlspecialchars($modalMessage) ?></p>
        </div>
        <div class="modal-footer">
            <button onclick="handleModalClose()" class="modal-btn modal-btn-primary">OK</button>
        </div>
    </div>
</div>

<script>
function handleModalClose() {
    const modal = document.getElementById('beautifulModal');
    modal.style.opacity = '0';
    setTimeout(() => {
        <?php if (isset($modalRedirect)): ?>
        window.location.href = <?= json_encode($modalRedirect) ?>;
        <?php else: ?>
        modal.style.display = 'none';
        <?php endif; ?>
    }, 200);
}

// Auto-show modal
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('beautifulModal');
    setTimeout(() => {
        modal.style.opacity = '1';
    }, 100);
});

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        handleModalClose();
    }
});
</script>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-content {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    transform: scale(0.9);
    transition: all 0.3s ease;
    animation: modalSlideIn 0.4s ease-out forwards;
}

@keyframes modalSlideIn {
    0% {
        transform: translateY(-30px) scale(0.9);
        opacity: 0;
    }
    100% {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

.modal-content.success {
    border-top: 6px solid #10b981;
}

.modal-content.error {
    border-top: 6px solid #ef4444;
}

.modal-content.warning {
    border-top: 6px solid #f59e0b;
}

.modal-content.info {
    border-top: 6px solid #3b82f6;
}

.modal-header {
    padding: 24px 24px 16px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: #1e293b;
}

.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    line-height: 1;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close-btn:hover {
    background: #f1f5f9;
    color: #64748b;
    transform: scale(1.1);
}

.modal-close-btn:active {
    transform: scale(0.95);
}

.modal-body {
    padding: 20px 24px;
    color: #475569;
    line-height: 1.6;
}

.modal-body p {
    margin: 0;
    font-size: 1rem;
}

.modal-footer {
    padding: 16px 24px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.modal-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn-primary {
    background: #3b82f6;
    color: white;
}

.modal-btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
}

.modal-content.success .modal-btn-primary {
    background: #10b981;
}

.modal-content.success .modal-btn-primary:hover {
    background: #059669;
}

.modal-content.error .modal-btn-primary {
    background: #ef4444;
}

.modal-content.error .modal-btn-primary:hover {
    background: #dc2626;
}

.modal-content.warning .modal-btn-primary {
    background: #f59e0b;
}

.modal-content.warning .modal-btn-primary:hover {
    background: #d97706;
}

@media (max-width: 640px) {
    .modal-content {
        margin: 20px;
        width: calc(100% - 40px);
    }
    
    .modal-header, .modal-body, .modal-footer {
        padding-left: 20px;
        padding-right: 20px;
    }
    
    .modal-header h3 {
        font-size: 1.1rem;
    }
    
    .modal-close-btn {
        font-size: 1.3rem;
        width: 28px;
        height: 28px;
    }
}

/* Disabled button styles */
.btn-disabled {
    background: #e5e7eb !important;
    color: #9ca3af !important;
    cursor: not-allowed !important;
    opacity: 0.7;
}

.btn-disabled:hover {
    background: #e5e7eb !important;
    transform: none !important;
}
</style>
<?php endif; ?>
