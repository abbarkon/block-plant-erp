<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "HR Department Report";
require_once "../partials/header.php";

$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');

// Employee Statistics
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status=1")->fetchColumn();
$activeEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status=1")->fetchColumn();
$inactiveEmployees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status=0")->fetchColumn();
$newHiresThisYear = 0; // TODO: Add date_joined column to employees table for tracking

// Department Distribution
$departments = $pdo->query("SELECT COALESCE(NULLIF(department, ''), 'Unassigned') AS department, COUNT(*) AS total FROM employees WHERE status=1 GROUP BY department ORDER BY total DESC")->fetchAll();

// Attendance Statistics
try {
    $paidLeaveSql = attendance_paid_leave_predicate('a', 'e');
    $avgAttendance = $pdo->query("SELECT AVG(CASE WHEN a.status IN ('present','late') OR $paidLeaveSql THEN 1 ELSE 0 END) * 100 as avg FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date) = $selectedMonth AND YEAR(a.attendance_date) = $selectedYear AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn();
    $avgAttendance = round($avgAttendance ?: 0, 2);

    $attendanceByStatus = $pdo->query("SELECT a.status, COUNT(*) as count FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date) = $selectedMonth AND YEAR(a.attendance_date) = $selectedYear AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining) GROUP BY a.status")->fetchAll();
    $attendanceStats = ['present' => 0, 'absent' => 0, 'half-day' => 0, 'leave' => 0, 'late' => 0];
    foreach($attendanceByStatus as $stat) {
        $attendanceStats[$stat['status']] = $stat['count'];
    }
    // Approved paid leave counts as present at work.
    $attendanceStats['present'] += (int) $pdo->query("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date) = $selectedMonth AND YEAR(a.attendance_date) = $selectedYear AND $paidLeaveSql AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)")->fetchColumn();
} catch (PDOException $e) {
    $avgAttendance = 0;
    $attendanceStats = ['present' => 0, 'absent' => 0, 'half-day' => 0, 'leave' => 0, 'late' => 0];
}

// Leave Statistics
try {
    $totalLeaveRequests = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE YEAR(from_date) = $selectedYear")->fetchColumn();
    $approvedLeaves = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='approved' AND YEAR(from_date) = $selectedYear")->fetchColumn();
    $rejectedLeaves = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='rejected' AND YEAR(from_date) = $selectedYear")->fetchColumn();
    $pendingLeaves = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending' AND YEAR(from_date) = $selectedYear")->fetchColumn();

    $leaveByType = $pdo->query("SELECT leave_type, COUNT(*) as count FROM leave_requests WHERE status='approved' AND YEAR(from_date) = $selectedYear GROUP BY leave_type")->fetchAll();
} catch (PDOException $e) {
    $totalLeaveRequests = 0;
    $approvedLeaves = 0;
    $rejectedLeaves = 0;
    $pendingLeaves = 0;
    $leaveByType = [];
}

// Payroll Statistics
$totalPayroll = $pdo->query("SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE month = $selectedMonth AND year = $selectedYear")->fetchColumn();
$avgSalary = $pdo->query("SELECT COALESCE(AVG(basic_salary), 0) FROM employees WHERE status=1")->fetchColumn();

// Loan Statistics
try {
    $totalLoans = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE YEAR(request_date) = $selectedYear")->fetchColumn();
    $approvedLoans = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE approval_status='approved' AND YEAR(request_date) = $selectedYear")->fetchColumn();
    $totalLoanAmount = $pdo->query("SELECT COALESCE(SUM(loan_amount), 0) FROM employee_loans WHERE approval_status='approved' AND YEAR(request_date) = $selectedYear")->fetchColumn();
    $outstandingBalance = $pdo->query("SELECT COALESCE(SUM(remaining_balance), 0) FROM employee_loans WHERE approval_status='approved' AND remaining_balance > 0")->fetchColumn();
} catch (PDOException $e) {
    $totalLoans = 0;
    $approvedLoans = 0;
    $totalLoanAmount = 0;
    $outstandingBalance = 0;
}

// Performance Statistics
try {
    $totalReviews = $pdo->query("SELECT COUNT(*) FROM performance_reviews WHERE YEAR(review_date) = $selectedYear")->fetchColumn();
    $avgRating = $pdo->query("SELECT COALESCE(AVG(rating), 0) FROM performance_reviews WHERE YEAR(review_date) = $selectedYear")->fetchColumn();
    $avgRating = round($avgRating ?: 0, 2);
} catch (PDOException $e) {
    $totalReviews = 0;
    $avgRating = 0;
}

// Training Statistics
try {
    $totalTraining = $pdo->query("SELECT COUNT(*) FROM training_programs WHERE YEAR(start_date) = $selectedYear")->fetchColumn();
    $completedTraining = $pdo->query("SELECT COUNT(*) FROM training_enrollments WHERE status='completed' AND YEAR(enrollment_date) = $selectedYear")->fetchColumn();
} catch (PDOException $e) {
    $totalTraining = 0;
    $completedTraining = 0;
}

// Shift Statistics
try {
    $totalShifts = $pdo->query("SELECT COUNT(*) FROM shifts WHERE status='active'")->fetchColumn();
    $activeShifts = $pdo->query("SELECT COUNT(*) FROM shifts WHERE status='active'")->fetchColumn();
} catch (PDOException $e) {
    $totalShifts = 0;
    $activeShifts = 0;
}

// Overtime Statistics
try {
    $totalOvertimeRequests = $pdo->query("SELECT COUNT(*) FROM overtime_requests WHERE YEAR(request_date) = $selectedYear")->fetchColumn();
    $approvedOvertime = $pdo->query("SELECT COUNT(*) FROM overtime_requests WHERE status='approved' AND YEAR(request_date) = $selectedYear")->fetchColumn();
    $totalOvertimeHours = $pdo->query("SELECT COALESCE(SUM(hours), 0) FROM overtime_requests WHERE status='approved' AND YEAR(request_date) = $selectedYear")->fetchColumn();
} catch (PDOException $e) {
    $totalOvertimeRequests = 0;
    $approvedOvertime = 0;
    $totalOvertimeHours = 0;
}

// Document Statistics
try {
    $totalDocuments = $pdo->query("SELECT COUNT(*) FROM employee_documents")->fetchColumn();
    $expiringDocuments = $pdo->query("SELECT COUNT(*) FROM employee_documents WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
} catch (PDOException $e) {
    $totalDocuments = 0;
    $expiringDocuments = 0;
}
?>

<h1>📊 HR Department Report</h1>

<!-- Month/Year Filter -->
<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <label>Month:</label>
    <select name="month" onchange="this.form.submit()">
        <?php for($m=1; $m<=12; $m++): ?>
        <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
    </select>
    
    <label>Year:</label>
    <select name="year" onchange="this.form.submit()">
        <?php for($y=date('Y'); $y>=date('Y')-5; $y--): ?>
        <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    
    <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print Report</button>
</form>

<!-- Employee Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Employee Overview</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#1c2250;"><?= number_format($totalEmployees) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Employees</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#10b981;"><?= number_format($activeEmployees) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Active Employees</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fee2e2; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#ef4444;"><?= number_format($inactiveEmployees) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Inactive Employees</div>
        </div>
        <div style="text-align:center; padding:15px; background:#e0e7ff; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#6366f1;"><?= number_format($newHiresThisYear) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">New Hires (<?= $selectedYear ?>)</div>
        </div>
    </div>
</div>

<!-- Department Distribution -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Department Distribution</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Employee Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($departments as $dept): ?>
                <tr>
                    <td><?= htmlspecialchars($dept['department']) ?></td>
                    <td><?= number_format($dept['total']) ?></td>
                    <td><?= round(($dept['total'] / $totalEmployees) * 100, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Attendance Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Attendance Overview (<?= date('F Y', mktime(0,0,0,$selectedMonth,1,$selectedYear)) ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($attendanceStats['present']) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Present Days</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fee2e2; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#ef4444;"><?= number_format($attendanceStats['absent']) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Absent Days</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef3c7; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= number_format($attendanceStats['half-day']) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Half-Days</div>
        </div>
        <div style="text-align:center; padding:15px; background:#e0e7ff; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#6366f1;"><?= number_format($attendanceStats['leave']) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Leave Days</div>
        </div>
        <div style="text-align:center; padding:15px; background:#ffedd5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#ea580c;"><?= number_format($attendanceStats['late'] ?? 0) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Late Days</div>
        </div>
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= $avgAttendance ?>%</div>
            <div style="color:#646b8c; font-size:0.85rem;">Avg Attendance</div>
        </div>
    </div>
</div>

<!-- Leave Statistics -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Leave Statistics (<?= $selectedYear ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalLeaveRequests) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Requests</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($approvedLeaves) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Approved</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fee2e2; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#ef4444;"><?= number_format($rejectedLeaves) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Rejected</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef3c7; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= number_format($pendingLeaves) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Pending</div>
        </div>
    </div>
    
    <?php if($leaveByType): ?>
    <h4 style="margin-top:20px;">Leave by Type</h4>
    <div class="table-container" style="margin-top:10px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($leaveByType as $type): ?>
                <tr>
                    <td><?= htmlspecialchars($type['leave_type']) ?></td>
                    <td><?= number_format($type['count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Payroll Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Payroll Overview (<?= date('F Y', mktime(0,0,0,$selectedMonth,1,$selectedYear)) ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#eff6ff; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#3b82f6;"><?= e(currency_symbol()) . number_format($totalPayroll, 2) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Payroll</div>
        </div>
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.5rem; font-weight:bold; color:#1c2250;"><?= e(currency_symbol()) . number_format($avgSalary, 2) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Avg Salary</div>
        </div>
    </div>
</div>

<!-- Loan Statistics -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Loan & Advance Statistics (<?= $selectedYear ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalLoans) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Requests</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($approvedLoans) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Approved Loans</div>
        </div>
        <div style="text-align:center; padding:15px; background:#e0e7ff; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#6366f1;"><?= e(currency_symbol()) . number_format($totalLoanAmount, 2) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Loan Amount</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef3c7; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= e(currency_symbol()) . number_format($outstandingBalance, 2) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Outstanding Balance</div>
        </div>
    </div>
</div>

<!-- Performance Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Performance Overview (<?= $selectedYear ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalReviews) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Reviews</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= $avgRating ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Avg Rating (1-5)</div>
        </div>
    </div>
</div>

<!-- Training Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Training Overview (<?= $selectedYear ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalTraining) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Training Programs</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($completedTraining) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Completed Enrollments</div>
        </div>
    </div>
</div>

<!-- Shift Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Shift Management Overview</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalShifts) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Shifts</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($activeShifts) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Active Shifts</div>
        </div>
    </div>
</div>

<!-- Overtime Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Overtime Overview (<?= $selectedYear ?>)</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalOvertimeRequests) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Requests</div>
        </div>
        <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($approvedOvertime) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Approved</div>
        </div>
        <div style="text-align:center; padding:15px; background:#e0e7ff; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#6366f1;"><?= number_format($totalOvertimeHours) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Hours</div>
        </div>
    </div>
</div>

<!-- Document Overview -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Employee Documents Overview</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:15px;">
        <div style="text-align:center; padding:15px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#1c2250;"><?= number_format($totalDocuments) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Total Documents</div>
        </div>
        <div style="text-align:center; padding:15px; background:#fef3c7; border-radius:8px;">
            <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= number_format($expiringDocuments) ?></div>
            <div style="color:#646b8c; font-size:0.85rem;">Expiring (30 days)</div>
        </div>
    </div>
</div>

<!-- Report Footer -->
<div style="text-align:center; margin-top:30px; padding:20px; background:#f8fafc; border-radius:8px;">
    <p style="color:#646b8c; font-size:0.9rem;">Report generated on <?= date('d F Y H:i') ?></p>
    <p style="color:#646b8c; font-size:0.85rem;">HR Department Report - Block Plant ERP System</p>
</div>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .panel, .panel *, form, form * {
        visibility: visible;
    }
    .panel {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        page-break-inside: avoid;
    }
    button {
        display: none !important;
    }
}
</style>

<?php require_once "../partials/footer.php"; ?>
