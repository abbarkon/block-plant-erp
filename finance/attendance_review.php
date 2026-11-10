<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

// Attendance submissions are created by HR (hr/attendance_submit.php); finance only reviews them here.
ensure_attendance_submission_schema();
ensure_notifications_schema();

$canReview = has_permission('finance', 'approve');

$selectedMonth = (int) ($_GET['month'] ?? date('m'));
$selectedYear = (int) ($_GET['year'] ?? date('Y'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int) date('m');
}
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int) date('Y');
}
$selfUrl = 'attendance_review.php?month=' . $selectedMonth . '&year=' . $selectedYear;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['approve_submission']) || isset($_POST['reject_submission']))) {
    require_permission('finance', 'approve');

    $action = isset($_POST['approve_submission']) ? 'approve' : 'reject';
    $submissionId = (int) ($_POST['submission_id'] ?? 0);

    if ($submissionId <= 0) {
        set_flash('error', 'Choose an attendance submission to review.');
    } else {
        $stmt = $pdo->prepare("SELECT id, month, year, status FROM attendance_submissions WHERE id = ?");
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch();

        if (!$submission) {
            set_flash('error', 'That attendance submission no longer exists.');
        } elseif ($action === 'approve' && mktime(0, 0, 0, (int) $submission['month'], 1, (int) $submission['year']) > mktime(0, 0, 0, (int) date('m'), 1, (int) date('Y'))) {
            set_flash('error', 'Attendance for ' . format_app_month_year($submission['month'], $submission['year']) . ' cannot be approved because that period has not started yet.');
        } elseif ($action === 'approve' && $submission['status'] === 'approved') {
            set_flash('error', 'That attendance submission is already approved.');
        } elseif ($action === 'reject' && $submission['status'] !== 'pending') {
            set_flash('error', 'Only pending submissions can be rejected. This one is already ' . $submission['status'] . '.');
        } else {
            $sql = $action === 'approve'
                ? "UPDATE attendance_submissions SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status <> 'approved'"
                : "UPDATE attendance_submissions SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'";
            $update = $pdo->prepare($sql);
            $update->execute([(int) $_SESSION['user_id'], $submissionId]);

            if ($update->rowCount() === 0) {
                set_flash('error', 'The submission changed while you were reviewing it. Refresh and try again.');
            } else {
                $periodLabel = format_app_month_year($submission['month'], $submission['year']);
                $link = "/block_plant/hr/attendance_submit.php?month=" . (int) $submission['month'] . '&year=' . (int) $submission['year'];

                if ($action === 'approve') {
                    notify_role(['admin', 'hr'], "Attendance for {$periodLabel} approved by finance - payroll can now be generated.", $link, $_SESSION['user_id']);
                    log_activity('approve', 'finance', $submissionId, "Approved attendance submission for {$periodLabel}");
                    set_flash('success', "Attendance for {$periodLabel} approved. Payroll can now be generated.");
                } else {
                    notify_role(['admin', 'hr'], "Attendance for {$periodLabel} was rejected by finance. Please correct and resubmit.", $link, $_SESSION['user_id']);
                    log_activity('reject', 'finance', $submissionId, "Rejected attendance submission for {$periodLabel}");
                    set_flash('success', "Attendance for {$periodLabel} rejected. HR has been notified to resubmit.");
                }
            }
        }
    }

    header('Location: ' . $selfUrl);
    exit;
}

try {
    $submissions = $pdo->query("SELECT asu.*, u.name AS submitted_by_name, ua.name AS approved_by_name
        FROM attendance_submissions asu
        LEFT JOIN users u ON asu.submitted_by = u.id
        LEFT JOIN users ua ON asu.approved_by = ua.id
        ORDER BY asu.year DESC, asu.month DESC, asu.id DESC
        LIMIT 24")->fetchAll();
} catch (PDOException $ex) {
    $submissions = [];
}

$periodStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$periodEnd = date('Y-m-t', strtotime($periodStart));

try {
    $stmt = $pdo->prepare("SELECT a.*, e.name AS employee_name, e.employee_code
        FROM attendance a JOIN employees e ON a.employee_id = e.id
        WHERE a.attendance_date BETWEEN ? AND ?
          AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)
        ORDER BY e.name, a.attendance_date");
    $stmt->execute([$periodStart, $periodEnd]);
    $attendance = $stmt->fetchAll();
} catch (PDOException $ex) {
    $attendance = [];
}

$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($submissions as $row) {
    $key = strtolower((string) $row['status']);
    if (!isset($statusCounts[$key])) {
        $statusCounts[$key] = 0;
    }
    $statusCounts[$key]++;
}

$attendanceCounts = [];
$attendanceHours = 0.0;
foreach ($attendance as $row) {
    $key = strtolower((string) $row['status']);
    $attendanceCounts[$key] = ($attendanceCounts[$key] ?? 0) + 1;
    $attendanceHours += (float) $row['working_hours'];
}
$pendingCount = $statusCounts['pending'];

$flash = get_flash();
$pageTitle = "Attendance Review";
require_once "../partials/header.php";
$periodLabel = format_app_month_year($selectedMonth, $selectedYear);
?>

<div class="ar-hero">
    <div>
        <span class="ar-eyebrow">FINANCE · PAYROLL INPUT CONTROL</span>
        <h1>📊 Attendance Review</h1>
        <p>Approve the attendance HR submits each period. Approval unlocks payroll generation.</p>
    </div>
    <div class="ar-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="ar-filter panel no-print">
    <div>
        <label for="month">Month</label>
        <select id="month" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label for="year">Year</label>
        <select id="year" name="year">
            <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
            <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-accent">Show period</button>
</form>

<div class="ar-summary">
    <div><span>Pending submissions</span><strong class="<?= $pendingCount > 0 ? 'ar-warn' : 'ar-ok' ?>"><?= number_format($pendingCount) ?></strong></div>
    <div><span>Approved</span><strong class="ar-ok"><?= number_format($statusCounts['approved']) ?></strong></div>
    <div><span>Rejected</span><strong class="<?= $statusCounts['rejected'] > 0 ? 'ar-bad' : '' ?>"><?= number_format($statusCounts['rejected']) ?></strong></div>
    <div><span><?= e($periodLabel) ?> records</span><strong><?= number_format(count($attendance)) ?></strong></div>
</div>

<?php if (!$canReview): ?>
<div class="flash">You can review attendance here, but approving or rejecting submissions requires the <strong>finance approve</strong> permission.</div>
<?php endif; ?>

<div class="panel ar-panel">
    <div class="ar-panel-head">
        <h3>Attendance submissions from HR</h3>
        <span class="ar-count">latest <?= number_format(count($submissions)) ?></span>
    </div>
    <div class="table-container">
        <table class="data-table ar-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Submitted by</th>
                    <th>Submitted at</th>
                    <th>Status</th>
                    <th>Reviewed by</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$submissions): ?>
                <tr><td colspan="6" class="ar-empty">No attendance submissions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($submissions as $sub): ?>
                <?php $status = strtolower((string) $sub['status']); ?>
                <tr>
                    <td><strong><?= e(format_app_month_year($sub['month'], $sub['year'])) ?></strong></td>
                    <td><?= e($sub['submitted_by_name'] ?? '—') ?></td>
                    <td><?= $sub['submitted_at'] ? e(date('d M Y H:i', strtotime($sub['submitted_at']))) : '—' ?></td>
                    <td><span class="ar-status ar-status-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span></td>
                    <td><?= e($sub['approved_by_name'] ?: '—') ?></td>
                    <td class="no-print">
                        <?php if ($status === 'pending' && $canReview): ?>
                        <div class="ar-actions">
                            <form method="post" class="ar-inline">
                                <input type="hidden" name="approve_submission" value="1">
                                <input type="hidden" name="submission_id" value="<?= (int) $sub['id'] ?>">
                                <button type="submit" class="btn btn-sm ar-approve">Approve</button>
                            </form>
                            <form method="post" class="ar-inline">
                                <input type="hidden" name="reject_submission" value="1">
                                <input type="hidden" name="submission_id" value="<?= (int) $sub['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                            </form>
                        </div>
                        <?php elseif ($status === 'approved'): ?>
                        <span class="ar-done ar-done-ok">✓ Approved</span>
                        <?php elseif ($status === 'rejected'): ?>
                        <span class="ar-done ar-done-bad">✗ Rejected</span>
                        <?php else: ?>
                        <span class="ar-done">Awaiting finance review</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel ar-panel">
    <div class="ar-panel-head">
        <h3>Attendance details: <?= e($periodLabel) ?></h3>
        <span class="ar-count"><?= number_format(count($attendance)) ?> record<?= count($attendance) === 1 ? '' : 's' ?> · <?= e(number_format($attendanceHours, 1)) ?> hours</span>
    </div>
    <div class="table-container">
        <table class="data-table ar-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="ar-num">Working hours</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$attendance): ?>
                <tr><td colspan="4" class="ar-empty">No attendance records for this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($attendance as $att): ?>
                <?php $attStatus = strtolower((string) $att['status']); ?>
                <tr>
                    <td><?= e($att['employee_name']) ?> <small class="ar-code"><?= e($att['employee_code']) ?></small></td>
                    <td><?= e(date('d M Y', strtotime($att['attendance_date']))) ?></td>
                    <td><span class="ar-status ar-status-<?= e($attStatus) ?>"><?= e(ucfirst($attStatus)) ?></span></td>
                    <td class="ar-num"><?= $att['working_hours'] !== null ? e(number_format((float) $att['working_hours'], 2)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ar-help no-print">
    <h3>How this works</h3>
    <p>Attendance is submitted by HR from <a href="/block_plant/hr/attendance_submit.php">HR → Submit Attendance to Finance</a>.
        Once you approve a submission here, payroll can be generated for that period from <a href="payroll.php">Payroll Generation</a>.
        If the attendance is wrong, reject it so HR can correct and resubmit.</p>
</div>

<style>
.ar-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1c2250,#4f4fc9 65%,#7a5cd6);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.ar-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.ar-hero p{margin:0;color:rgba(255,255,255,.76)}
.ar-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.ar-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.ar-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.ar-filter{padding:18px 20px;display:grid;grid-template-columns:repeat(2,minmax(150px,220px)) auto;gap:14px;align-items:end;margin-bottom:14px}
.ar-filter label{display:block;margin:0 0 5px}
.ar-filter select{margin:0}
.ar-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.ar-summary div{padding:15px 17px;background:#fff;border:1px solid #dde1f0;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.ar-summary span{display:block;font-size:.76rem;color:#646b8c}
.ar-summary strong{display:block;margin-top:5px;font-size:1.3rem;color:#1c2250}
.ar-summary strong.ar-ok{color:#1d7a4f}
.ar-summary strong.ar-warn{color:#a86a12}
.ar-summary strong.ar-bad{color:#c2364a}
.ar-panel{margin-bottom:18px}
.ar-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.ar-panel-head h3{margin:0}
.ar-count{font-size:.8rem;color:#646b8c;font-weight:700}
.ar-table th.ar-num,.ar-table td.ar-num{text-align:right;font-variant-numeric:tabular-nums}
.ar-status{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.74rem;font-weight:800;background:#eef2f5;color:#4a5f6b}
.ar-status-pending{background:#fff4e0;color:#a86a12}
.ar-status-approved,.ar-status-present{background:#e6f4ec;color:#1d7a4f}
.ar-status-rejected,.ar-status-absent{background:#fdeee9;color:#c2364a}
.ar-status-late,.ar-status-leave,.ar-status-half_day{background:#eef3ff;color:#35558f}
.ar-actions{display:flex;gap:6px;align-items:center}
.ar-inline{display:inline;margin:0}
.ar-approve{background:#1d7a4f!important}
.ar-done{font-size:.8rem;font-weight:700;color:#646b8c}
.ar-done-ok{color:#1d7a4f}
.ar-done-bad{color:#c2364a}
.ar-code{color:#8b98a1;font-size:.78rem;margin-left:4px}
.ar-empty{text-align:center;padding:34px!important;color:#646b8c}
.ar-help{margin-top:4px;padding:20px;background:#f4f7f8;border:1px solid #dde1f0;border-radius:12px}
.ar-help h3{margin:0 0 8px}
.ar-help p{margin:0;color:#5b6b73;line-height:1.6}
@media(max-width:850px){.ar-summary{grid-template-columns:repeat(2,1fr)}.ar-filter{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.ar-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.ar-hero h1{font-size:1.75rem}.ar-summary{grid-template-columns:1fr 1fr;gap:8px}.ar-filter{grid-template-columns:1fr}}
@media print {
    .no-print, .ar-hero-actions, .ar-filter, .ar-help { display: none !important; }
    .ar-hero { background: #1c2250 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; }
    .ar-table { min-width: 0; }
    .table-container { overflow: visible; }
}
</style>

<?php require_once "../partials/footer.php"; ?>
