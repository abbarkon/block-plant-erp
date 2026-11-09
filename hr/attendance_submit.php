<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

ensure_attendance_submission_schema();
ensure_notifications_schema();

$selectedMonth = (int) ($_GET['month'] ?? date('m'));
$selectedYear = (int) ($_GET['year'] ?? date('Y'));
$selectedMonth = ($selectedMonth >= 1 && $selectedMonth <= 12) ? $selectedMonth : (int) date('m');
$selectedYear = ($selectedYear >= 1900 && $selectedYear <= (int) date('Y')) ? $selectedYear : (int) date('Y');

// A period that has not started yet cannot be submitted for payroll.
$currentPeriodStart = mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'));
$selectedPeriodStart = mktime(0, 0, 0, $selectedMonth, 1, $selectedYear);
$isFuturePeriod = $selectedPeriodStart > $currentPeriodStart;

// Submit attendance to Finance so payroll can be generated for the period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to submit attendance.');
    }
    $month = (int) ($_POST['month'] ?? 0);
    $year = (int) ($_POST['year'] ?? 0);
    $returnUrl = "attendance_submit.php?month=$month&year=$year";
    $postPeriodStart = mktime(0, 0, 0, $month, 1, $year);

    if ($month < 1 || $month > 12 || $year < 1900 || $year > (int) date('Y')) {
        set_modal('error', 'Invalid Period', 'Please choose a valid month and year.', $returnUrl);
    } elseif ($postPeriodStart > mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'))) {
        set_modal('error', 'Future Period', 'Attendance for ' . format_app_month_year($month, $year) . ' cannot be submitted because that period has not started yet.', $returnUrl);
    } else {
        $periodLabel = format_app_month_year($month, $year);

        $recordCount = 0;
        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date)=? AND YEAR(a.attendance_date)=? AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)");
            $countStmt->execute([$month, $year]);
            $recordCount = (int) $countStmt->fetchColumn();
        } catch (PDOException $e) {
            $recordCount = 0;
        }

        $existing = get_attendance_submission($month, $year);

        if ($existing && in_array($existing['status'], ['pending', 'approved'], true)) {
            $state = $existing['status'] === 'pending'
                ? 'awaiting finance review'
                : 'already approved by finance';
            set_modal('warning', 'Already Submitted', "Attendance for {$periodLabel} was submitted and is {$state}.", $returnUrl);
        } elseif ($recordCount === 0) {
            set_modal('error', 'No Attendance Records', "There are no attendance records for {$periodLabel}. Record attendance first, then submit.", $returnUrl);
        } else {
            if ($existing) {
                // Previously rejected by finance: resubmit the same period.
                $stmt = $pdo->prepare("UPDATE attendance_submissions SET status='pending', submitted_by=?, submitted_at=NOW(), approved_by=NULL, approved_at=NULL WHERE id=?");
                $stmt->execute([$_SESSION['user_id'], $existing['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO attendance_submissions(month, year, submitted_by, submitted_at, status) VALUES(?,?,?,NOW(),'pending')");
                $stmt->execute([$month, $year, $_SESSION['user_id']]);
            }

            notify_role(
                ['admin', 'accountant', 'finance'],
                "HR submitted attendance for {$periodLabel} - pending finance review.",
                "/block_plant/finance/attendance_review.php?month={$month}&year={$year}",
                $_SESSION['user_id']
            );

            set_modal(
                'success',
                'Attendance Submitted',
                "Attendance for {$periodLabel} was submitted to Finance. Payroll can be generated once finance approves it.",
                $returnUrl
            );
        }
    }
}

$pageTitle = "Submit Attendance to Finance";
require_once "../partials/header.php";

$submission = get_attendance_submission($selectedMonth, $selectedYear);
$periodLabel = format_app_month_year($selectedMonth, $selectedYear);

// Approved unpaid leave days that will be deducted in the payroll run.
$approvedUnpaidLeaveDays = 0;
try {
    $unpaidStmt = $pdo->prepare("SELECT from_date, to_date FROM leave_requests
        WHERE status = 'approved' AND LOWER(leave_type) LIKE '%unpaid%'
          AND from_date <= ? AND to_date >= ?");
    $periodStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $unpaidStmt->execute([$periodEnd, $periodStart]);
    foreach ($unpaidStmt->fetchAll() as $leave) {
        $cursor = max(strtotime($leave['from_date']), strtotime($periodStart));
        $last = min(strtotime($leave['to_date']), strtotime($periodEnd));
        if ($cursor !== false && $cursor <= $last) {
            $approvedUnpaidLeaveDays += (int) (($last - $cursor) / 86400) + 1;
        }
    }
} catch (PDOException $e) {
    $approvedUnpaidLeaveDays = 0;
}

// Attendance summary for the selected period
$summary = ['present' => 0, 'absent' => 0, 'half-day' => 0, 'leave' => 0, 'late' => 0, 'other' => 0];
$recordCount = 0;
$employeeCount = 0;
$dayCount = 0;
try {
    $stmt = $pdo->prepare("SELECT a.status, COUNT(*) AS total FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date)=? AND YEAR(a.attendance_date)=? AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining) GROUP BY a.status");
    $stmt->execute([$selectedMonth, $selectedYear]);
    foreach ($stmt->fetchAll() as $row) {
        $recordCount += (int) $row['total'];
        if (isset($summary[$row['status']])) {
            $summary[$row['status']] = (int) $row['total'];
        } else {
            $summary['other'] += (int) $row['total'];
        }
    }

    // Approved paid leave counts as present at work. Unpaid leave stays in the
    // leave bucket because it is deducted by payroll.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date)=? AND YEAR(a.attendance_date)=? AND " . attendance_paid_leave_predicate('a', 'e') . " AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $summary['present'] += (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id) AS employees, COUNT(DISTINCT a.attendance_date) AS days FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE MONTH(a.attendance_date)=? AND YEAR(a.attendance_date)=? AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)");
    $stmt->execute([$selectedMonth, $selectedYear]);
    $totals = $stmt->fetch();
    $employeeCount = (int) ($totals['employees'] ?? 0);
    $dayCount = (int) ($totals['days'] ?? 0);
} catch (PDOException $e) {
    // Attendance table not available yet - shown as empty summary below.
}

// Submission history
try {
    $submissions = $pdo->query(
        "SELECT asu.*, u.name AS submitted_by_name, ua.name AS approved_by_name
         FROM attendance_submissions asu
         LEFT JOIN users u ON u.id = asu.submitted_by
         LEFT JOIN users ua ON ua.id = asu.approved_by
         ORDER BY asu.year DESC, asu.month DESC LIMIT 12"
    )->fetchAll();
} catch (PDOException $e) {
    $submissions = [];
}

$statusColors = [
    'pending' => ['#fef3c7', '#92400e'],
    'approved' => ['#d1fae5', '#065f46'],
    'rejected' => ['#fee2e2', '#991b1b'],
];
?>

<h1>📤 Submit Attendance to Finance</h1>

<p style="color:#64748b; margin:-6px 0 20px;">
    Before payroll can be generated for a month, HR must submit that month's attendance here and finance must approve it.
</p>

<?php if ($isFuturePeriod): ?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid #dc2626; background:#fef2f2;">
    <strong>📅 Future period - <?= e(format_app_month_year($selectedMonth, $selectedYear)) ?></strong>
    <div style="color:#991b1b; font-size:0.9rem; margin-top:4px;">
        That month has not started yet (today is <?= e(date('d M Y')) ?>), so its attendance cannot be submitted for payroll.
    </div>
</div>
<?php endif; ?>

<!-- Period filter -->
<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
    <div>
        <label>Month:</label>
        <select name="month" onchange="this.form.submit()" style="padding:10px; border:1px solid #ddd; border-radius:6px;">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $selectedMonth == $m ? 'selected' : '' ?>><?= e(format_app_month_name($m)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label>Year:</label>
        <select name="year" onchange="this.form.submit()" style="padding:10px; border:1px solid #ddd; border-radius:6px;">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $selectedYear == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <a href="attendance.php" class="btn btn-secondary">Record daily attendance</a>
</form>

<!-- Submission status -->
<?php
$badge = $statusColors[$submission['status'] ?? ''] ?? ['#e2e8f0', '#334155'];
$statusTitle = '🚫 Not Submitted';
$statusMessage = "Attendance for {$periodLabel} has not been submitted to finance yet.";
$statusColor = '#64748b';
if ($submission && $submission['status'] === 'pending') {
    $statusTitle = '⏳ Awaiting Finance Review';
    $statusMessage = 'Submitted by ' . e($submission['submitted_by_name'] ?? 'Unknown') . ' on ' . date('d M Y H:i', strtotime($submission['submitted_at'])) . '. Finance must approve it before payroll can be generated.';
    $statusColor = '#f59e0b';
} elseif ($submission && $submission['status'] === 'approved') {
    $statusTitle = '✅ Approved by Finance';
    $statusMessage = 'Approved by ' . e($submission['approved_by_name'] ?? 'Unknown') . ' on ' . date('d M Y H:i', strtotime($submission['approved_at'])) . '. Payroll can now be generated for this period.';
    $statusColor = '#10b981';
} elseif ($submission && $submission['status'] === 'rejected') {
    $statusTitle = '❌ Rejected by Finance';
    $statusMessage = 'Finance rejected this submission. Correct the attendance records and resubmit.';
    $statusColor = '#ef4444';
}
?>
<div style="margin-bottom:20px; padding:15px 20px; border-radius:12px; border-left:5px solid <?= $statusColor ?>; background:#fff;">
    <strong style="font-size:1.1rem;"><?= $statusTitle ?></strong>
    <div style="color:#6a6259; font-size:0.9rem; margin-top:4px;"><?= $statusMessage ?></div>
</div>

<!-- Attendance summary for period -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Attendance Summary: <?= e($periodLabel) ?></h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-top:15px;">
        <div style="text-align:center; padding:12px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold;"><?= $recordCount ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Records</div>
        </div>
        <div style="text-align:center; padding:12px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold;"><?= $employeeCount ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Employees</div>
        </div>
        <div style="text-align:center; padding:12px; background:#f8fafc; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold;"><?= $dayCount ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Days Covered</div>
        </div>
        <div style="text-align:center; padding:12px; background:#f0fdf4; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold; color:#10b981;"><?= $summary['present'] ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Present</div>
        </div>
        <div style="text-align:center; padding:12px; background:#fff7ed; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold; color:#ea580c;"><?= $summary['late'] ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Late</div>
        </div>
        <div style="text-align:center; padding:12px; background:#fff3cd; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold; color:#f59e0b;"><?= $summary['half-day'] ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Half Day</div>
        </div>
        <div style="text-align:center; padding:12px; background:#e0f2fe; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold; color:#0284c7;"><?= $summary['leave'] ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Leave</div>
        </div>
        <div style="text-align:center; padding:12px; background:#fef2f2; border-radius:8px;">
            <div style="font-size:1.2rem; font-weight:bold; color:#ef4444;"><?= $summary['absent'] ?></div>
            <div style="color:#6a6259; font-size:0.85rem;">Absent</div>
        </div>
    </div>
    <p style="color:#475569; background:#f8fafc; padding:10px 14px; border-radius:8px; margin-top:15px; font-size:0.88rem;">
        Payroll deducts recorded <strong>absent</strong> days, <strong>half-days</strong> (0.5 day) and approved <strong>Unpaid Leave</strong> at the daily rate (basic ÷ 30). Paid leave types are not deducted.
        <?php if ($approvedUnpaidLeaveDays > 0): ?>
        This period has <strong><?= number_format($approvedUnpaidLeaveDays) ?> approved unpaid leave day(s)</strong>.
        <?php endif; ?>
    </p>
    <?php if ($recordCount === 0): ?>
    <p style="color:#92400e; background:#fef3c7; padding:10px 14px; border-radius:8px; margin-top:15px;">
        No attendance has been recorded for <?= e($periodLabel) ?> yet. <a href="attendance.php">Record attendance</a> before submitting.
    </p>
    <?php endif; ?>
</div>

<!-- Submit form -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Submit to Finance</h3>
    <form method="post" style="margin-top:15px;">
        <input type="hidden" name="month" value="<?= $selectedMonth ?>">
        <input type="hidden" name="year" value="<?= $selectedYear ?>">
        <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <div>
                <label>Period:</label>
                <strong><?= e($periodLabel) ?></strong>
            </div>
            <?php
            $alreadySent = $submission && in_array($submission['status'], ['pending', 'approved'], true);
            $canSubmit = !$alreadySent && $recordCount > 0 && !$isFuturePeriod;
            if ($isFuturePeriod): ?>
            <span style="padding:6px 12px; border-radius:6px; background:#fee2e2; color:#991b1b;">
                Future period - cannot submit yet
            </span>
            <?php elseif ($alreadySent): ?>
            <span style="padding:6px 12px; border-radius:6px; background:<?= $badge[0] ?>; color:<?= $badge[1] ?>;">
                Already submitted - <?= e(ucfirst($submission['status'])) ?>
            </span>
            <?php else: ?>
            <button type="submit" name="submit_attendance" value="1" class="btn btn-primary" <?= $canSubmit ? '' : 'disabled' ?>>
                <?= $submission && $submission['status'] === 'rejected' ? 'Resubmit to Finance' : 'Submit to Finance' ?>
            </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Submission history -->
<div class="panel">
    <h3>Submission History</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Submitted By</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$submissions): ?>
                <tr><td colspan="5">No attendance submissions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($submissions as $sub):
                    $badge = $statusColors[$sub['status']] ?? ['#e2e8f0', '#334155'];
                ?>
                <tr>
                    <td><?= e(format_app_month_year($sub['month'], $sub['year'])) ?></td>
                    <td><?= e($sub['submitted_by_name'] ?? '-') ?></td>
                    <td><?= date('d M Y H:i', strtotime($sub['submitted_at'])) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $badge[0] ?>; color:<?= $badge[1] ?>;">
                            <?= e(ucfirst($sub['status'])) ?>
                        </span>
                    </td>
                    <td><?= e($sub['approved_by_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
