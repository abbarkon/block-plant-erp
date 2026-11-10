<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Attendance";
$date = trim((string) ($_POST['attendance_date'] ?? $_GET['date'] ?? date('Y-m-d')));
if (!is_valid_date_string($date)) {
    $date = date('Y-m-d');
}
$canEditAttendance = has_permission('hr', 'edit');
ensure_attendance_shift_schema();
$workSessions = attendance_work_sessions($date);
$shiftOptions = attendance_shift_options();

// One token per rendered form. A double-click or duplicate POST cannot save the
// same form twice; the second request sees that the token was already used.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_SESSION['attendance_form_token'])) {
    $_SESSION['attendance_form_token'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEditAttendance) {
        http_response_code(403);
        exit('You do not have permission to edit attendance.');
    }

    $postedToken = (string) ($_POST['form_token'] ?? '');
    $sessionToken = (string) ($_SESSION['attendance_form_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        set_flash('success', 'This attendance form was already submitted. The latest saved values are shown below.');
        header('Location: attendance.php?date=' . urlencode($date));
        exit;
    }
    unset($_SESSION['attendance_form_token']);

    $validStatuses = ['present', 'absent', 'half-day', 'leave', 'late'];
    $submittedAttendance = (array) ($_POST['attendance'] ?? []);
    $eligibleStmt = $pdo->prepare("SELECT id, name, shift FROM employees WHERE status = 1 AND (date_of_joining IS NULL OR date_of_joining <= ?)");
    $eligibleStmt->execute([$date]);
    $employeeMeta = [];
    foreach ($eligibleStmt->fetchAll() as $eligibleRow) {
        $employeeMeta[(int) $eligibleRow['id']] = [
            'name' => (string) $eligibleRow['name'],
            'shift' => strtolower(trim((string) ($eligibleRow['shift'] ?? 'morning'))),
        ];
    }
    $activeIds = array_keys($employeeMeta);

    $existingStmt = $pdo->prepare("SELECT employee_id, shift, clock_in, clock_out, working_hours, status, notes FROM attendance WHERE attendance_date = ?");
    $existingStmt->execute([$date]);
    $existingRows = [];
    foreach ($existingStmt->fetchAll() as $existingRow) {
        $existingRows[(int) $existingRow['employee_id']] = $existingRow;
    }

    $normalizeTime = static function ($value) {
        $value = trim((string) $value);
        return $value === '' ? '' : substr($value, 0, 5);
    };

    // Validate and normalise every submitted row before writing anything, so a
    // missing clock-in cannot leave half of the register saved.
    $rowsToSave = [];
    $missingClockIns = [];
    foreach ($submittedAttendance as $empId => $data) {
        $empId = (int) $empId;
        $status = (string) ($data['status'] ?? '');
        if (!in_array($empId, $activeIds, true) || !in_array($status, $validStatuses, true)) {
            continue;
        }
        $clockIn = trim((string) ($data['clock_in'] ?? ''));
        $clockOut = trim((string) ($data['clock_out'] ?? ''));
        if ($clockIn !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $clockIn)) {
            $clockIn = '';
        }
        if ($clockOut !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $clockOut)) {
            $clockOut = '';
        }

        $shift = strtolower(trim((string) ($data['shift'] ?? '')));
        if (!isset($shiftOptions[$shift])) {
            $shift = $employeeMeta[$empId]['shift'] ?? 'morning';
        }
        if (!isset($shiftOptions[$shift])) {
            $shift = 'morning';
        }

        // Present and half-day are accepted without a clock-in. Only a late
        // row needs the time that justifies the status.
        if ($status === 'late' && $clockIn === '') {
            $missingClockIns[] = $employeeMeta[$empId]['name'] ?? ('#' . $empId);
            continue;
        }

        // The recorded time decides lateness: present/late follow the company
        // grace time (2:45 morning, 7:45 afternoon local time).
        $status = attendance_status_with_clock($status, $clockIn, $shift, $date);

        $hours = round((float) ($data['working_hours'] ?? 0), 2);
        $hours = max(0, min(24, $hours));
        $notes = mb_substr(trim((string) ($data['notes'] ?? '')), 0, 255);

        $rowsToSave[$empId] = [
            'shift' => $shift,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'working_hours' => $hours,
            'status' => $status,
            'notes' => $notes,
        ];
    }

    if ($missingClockIns) {
        $_SESSION['attendance_form_token'] = bin2hex(random_bytes(16));
        $names = array_slice($missingClockIns, 0, 8);
        set_flash('error', 'Record the clock-in time for ' . implode(', ', $names)
            . (count($missingClockIns) > 8 ? ' and ' . (count($missingClockIns) - 8) . ' more' : '')
            . '. Attendance was not saved.');
        header('Location: attendance.php?date=' . urlencode($date));
        exit;
    }

    $saved = 0;
    $unchanged = 0;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO attendance(employee_id, attendance_date, shift, clock_in, clock_out, working_hours, status, notes) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shift=VALUES(shift), clock_in=VALUES(clock_in), clock_out=VALUES(clock_out), working_hours=VALUES(working_hours), status=VALUES(status), notes=VALUES(notes)");
        foreach ($rowsToSave as $empId => $row) {
            $existing = $existingRows[$empId] ?? null;
            if ($existing) {
                $same = strtolower(trim((string) ($existing['shift'] ?? ''))) === $row['shift']
                    && $normalizeTime($existing['clock_in'] ?? '') === $normalizeTime($row['clock_in'])
                    && $normalizeTime($existing['clock_out'] ?? '') === $normalizeTime($row['clock_out'])
                    && abs((float) ($existing['working_hours'] ?? 0) - $row['working_hours']) < 0.001
                    && (string) ($existing['status'] ?? '') === $row['status']
                    && (string) ($existing['notes'] ?? '') === $row['notes'];
                if ($same) {
                    $unchanged++;
                    continue;
                }
            }

            $stmt->execute([
                $empId,
                $date,
                $row['shift'],
                $row['clock_in'] !== '' ? $row['clock_in'] : null,
                $row['clock_out'] !== '' ? $row['clock_out'] : null,
                $row['working_hours'],
                $row['status'],
                $row['notes'],
            ]);
            $saved++;
        }
        $pdo->commit();

        if ($saved > 0) {
            log_activity('update', 'hr', null, 'Attendance saved for ' . $date . ' (' . $saved . ' employees)');
            set_flash('success', 'Attendance saved for ' . format_date($date) . '.');
        } else {
            set_flash('success', 'Attendance for ' . format_date($date) . ' was already saved. No changes were made.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Could not save attendance: ' . $e->getMessage());
    }
    header('Location: attendance.php?date=' . urlencode($date));
    exit;
}

$attendanceFormToken = (string) ($_SESSION['attendance_form_token'] ?? '');

$employeesStmt = $pdo->prepare("SELECT * FROM employees WHERE status=1 AND (date_of_joining IS NULL OR date_of_joining <= ?) ORDER BY name");
$employeesStmt->execute([$date]);
$employees = $employeesStmt->fetchAll();
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE attendance_date = ?");
$stmt->execute([$date]);
$attendance_records = [];
foreach ($stmt->fetchAll() as $rec) {
    $attendance_records[$rec['employee_id']] = $rec;
}

// Approved leave covering the selected date, so the register can prefill it.
$leaveByEmployee = [];
try {
    $leaveStmt = $pdo->prepare("SELECT employee_id, leave_type FROM leave_requests
        WHERE status = 'approved' AND from_date <= ? AND to_date >= ?");
    $leaveStmt->execute([$date, $date]);
    foreach ($leaveStmt->fetchAll() as $leaveRow) {
        $leaveByEmployee[(int) $leaveRow['employee_id']] = $leaveRow['leave_type'];
    }
} catch (PDOException $e) {
    $leaveByEmployee = [];
}
$flash = get_flash();
require_once "../partials/header.php";
?>

<h1>📅 Daily Attendance</h1>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php if (!$canEditAttendance): ?><div class="flash flash-error">You can view attendance, but editing requires HR edit permission.</div><?php endif; ?>
<?php if ($leaveByEmployee): ?>
<div class="flash">🏖️ <?= number_format(count($leaveByEmployee)) ?> employee<?= count($leaveByEmployee) === 1 ? '' : 's' ?> on approved leave for <?= e(format_date($date)) ?> — pre-marked below. <a href="leaves.php">Open leave management</a></div>
<?php endif; ?>

<div style="display:flex; gap:10px; align-items:center; margin-bottom:15px; flex-wrap:wrap;">
    <a href="attendance_submit.php" class="btn btn-primary">📤 Submit Attendance to Finance</a>
    <a href="attendance_report.php?from=<?= e(date('Y-m-01')) ?>&to=<?= e($date) ?>" class="btn btn-secondary">📊 <?= e(t('attendance_report')) ?></a>
    <a href="attendance_sheet.php" class="btn btn-secondary">🧾 <?= e(t('attendance_sheet')) ?></a>
    <a href="attendance_warnings.php?from=<?= e(date('Y-m-01')) ?>&to=<?= e($date) ?>" class="btn btn-secondary">⚠ <?= e(attendance_warning_text('menu')) ?></a>
    <a href="leaves.php" class="btn btn-secondary">🏖️ Leave Management</a>
    <span style="color:#64748b; font-size:0.9rem;">Absences, half-days and approved unpaid leave are deducted automatically when payroll is generated.</span>
</div>

<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <label>Select Date:</label>
    <input type="date" name="date" value="<?= $date ?>" onchange="this.form.submit()">
    <div style="color:#64748b; font-size:0.88rem; margin-top:8px;">Only employees who joined on or before <?= e(format_date($date)) ?> are listed.</div>
</form>

<div class="shift-banner">
    <div class="shift-banner-title">🕒 Company working hours <span class="shift-banner-note">(first time = Ethiopian local time)</span></div>
    <div class="shift-banner-grid">
        <div>
            <strong><?= e(attendance_shift_label('morning')) ?></strong>
            <?= e(attendance_local_time_label($workSessions['morning']['start'])) ?> – <?= e(attendance_local_time_label($workSessions['morning']['end'])) ?>
            <span class="shift-banner-std">(<?= e($workSessions['morning']['start']) ?> – <?= e($workSessions['morning']['end']) ?>)</span>
            <div class="shift-banner-late">Late after <?= e(attendance_local_time_label($workSessions['morning']['grace'])) ?> (<?= e($workSessions['morning']['grace']) ?>)</div>
        </div>
        <div>
            <strong><?= e(attendance_shift_label('afternoon')) ?></strong>
            <?= e(attendance_local_time_label($workSessions['afternoon']['start'])) ?> – <?= e(attendance_local_time_label($workSessions['afternoon']['end'])) ?>
            <span class="shift-banner-std">(<?= e($workSessions['afternoon']['start']) ?> – <?= e($workSessions['afternoon']['end']) ?>)</span>
            <div class="shift-banner-late">Late after <?= e(attendance_local_time_label($workSessions['afternoon']['grace'])) ?> (<?= e($workSessions['afternoon']['grace']) ?>)</div>
        </div>
    </div>
    <div class="shift-banner-note">Friday morning ends at <?= e(attendance_local_time_label('11:30')) ?> (11:30). Present and half-day are accepted without a clock-in; a time is required only for late rows.</div>
</div>

<form method="post" class="attendance-form">
    <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
    <input type="hidden" name="form_token" value="<?= e($attendanceFormToken) ?>">
    <fieldset <?= $canEditAttendance ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Shift</th>
                    <th>Clock In</th>
                    <th>Clock Out</th>
                    <th>Working Hours</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($employees as $emp):
                    $att = $attendance_records[$emp['id']] ?? null;
                    $onLeaveType = $leaveByEmployee[(int) $emp['id']] ?? null;
                    $rowStatus = $att['status'] ?? ($onLeaveType ? 'leave' : '');
                    $rowNotes = $att['notes'] ?? ($onLeaveType ? 'Approved ' . $onLeaveType . ' leave' : '');
                    $rowHours = $att['working_hours'] ?? ($onLeaveType ? 0 : 8);
                    $rowShift = strtolower(trim((string) ($att['shift'] ?? ($emp['shift'] ?? 'morning'))));
                    if (!isset($shiftOptions[$rowShift])) {
                        $rowShift = 'morning';
                    }
                    $rowClockIn = substr((string) ($att['clock_in'] ?? ''), 0, 5);
                    $rowLate = $rowClockIn !== '' ? attendance_late_minutes($rowClockIn, $rowShift, $date) : -1;
                ?>
                <tr class="attendance-row" data-employee="<?= e($emp['name']) ?>">
                    <td><?= htmlspecialchars($emp['name']) ?><?php if ($onLeaveType && !$att): ?> <small style="color:#7c5cbf;">(on leave)</small><?php endif; ?></td>
                    <td>
                        <select name="attendance[<?= $emp['id'] ?>][shift]" class="shift-select" title="<?= e(attendance_shift_label('morning')) ?> <?= e($workSessions['morning']['start']) ?>, <?= e(attendance_shift_label('afternoon')) ?> <?= e($workSessions['afternoon']['start']) ?>">
                            <?php foreach ($shiftOptions as $shiftKey => $shiftLabels): ?>
                            <option value="<?= e($shiftKey) ?>" <?= $rowShift === $shiftKey ? 'selected' : '' ?>><?= e(app_lang() === 'am' ? $shiftLabels['am'] : $shiftLabels['en']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="time" class="clock-in" name="attendance[<?= $emp['id'] ?>][clock_in]" value="<?= e($rowClockIn) ?>">
                        <div class="late-hint <?= $rowLate > 0 ? 'late' : ($rowLate === 0 ? 'ok' : '') ?>"><?= $rowLate > 0 ? 'Late by ' . (int) $rowLate . ' min' : ($rowLate === 0 ? 'On time' : '') ?></div>
                    </td>
                    <td><input type="time" class="clock-out" name="attendance[<?= $emp['id'] ?>][clock_out]" value="<?= e(substr((string) ($att['clock_out'] ?? ''), 0, 5)) ?>"></td>
                    <td><input type="number" step="0.5" name="attendance[<?= $emp['id'] ?>][working_hours]" value="<?= $rowHours ?>" style="width: 80px;"></td>
                    <td>
                        <select name="attendance[<?= $emp['id'] ?>][status]" class="status-select">
                            <option value="">-</option>
                            <option value="present" <?= $rowStatus === 'present' ? 'selected' : '' ?>>present</option>
                            <option value="absent" <?= $rowStatus === 'absent' ? 'selected' : '' ?>>absent</option>
                            <option value="half-day" <?= $rowStatus === 'half-day' ? 'selected' : '' ?>>half-day</option>
                            <option value="leave" <?= $rowStatus === 'leave' ? 'selected' : '' ?>>leave</option>
                            <option value="late" <?= $rowStatus === 'late' ? 'selected' : '' ?>>late</option>
                        </select>
                    </td>
                    <td><input type="text" name="attendance[<?= $emp['id'] ?>][notes]" value="<?= htmlspecialchars($rowNotes) ?>"></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 20px;">
            <button type="submit" class="btn">Save Attendance</button>
        </div>
    </div>
    </fieldset>
</form>

<div class="late-modal" id="lateModal" aria-hidden="true">
    <div class="late-modal-backdrop" data-late-close></div>
    <div class="late-modal-card" role="dialog" aria-modal="true" aria-labelledby="lateModalTitle">
        <div class="late-modal-head">
            <div class="late-modal-badge">⏰</div>
            <div class="late-modal-heading">
                <h2 id="lateModalTitle">Record late time</h2>
                <p id="lateModalEmployee">&nbsp;</p>
            </div>
            <button type="button" class="late-modal-x" data-late-close aria-label="Close">&times;</button>
        </div>
        <div class="late-modal-body">
            <div class="late-modal-meta" id="lateModalMeta"></div>
            <label class="late-field late-field-time">
                <span>Clock-in time <em>*</em></span>
                <input type="time" id="lateModalClockIn" required>
            </label>
            <div class="late-modal-grid">
                <label class="late-field">
                    <span>Shift</span>
                    <select id="lateModalShift">
                        <?php foreach ($shiftOptions as $shiftKey => $shiftLabels): ?>
                        <option value="<?= e($shiftKey) ?>"><?= e(app_lang() === 'am' ? $shiftLabels['am'] : $shiftLabels['en']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="late-field">
                    <span>Clock out <small>(optional)</small></span>
                    <input type="time" id="lateModalClockOut">
                </label>
            </div>
            <div class="late-modal-result empty" id="lateModalResult" aria-live="polite"></div>
            <p class="late-modal-note">The time is written to the employee's row. Click <strong>Save Attendance</strong> to store the day.</p>
        </div>
        <div class="late-modal-foot">
            <button type="button" class="late-btn-ghost" data-late-close>Cancel</button>
            <button type="button" class="btn" id="lateModalSave" disabled>Save time</button>
        </div>
    </div>
</div>

<style>
.shift-banner{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 18px;margin-bottom:18px;color:#0c4a6e}
.shift-banner-title{font-weight:700;margin-bottom:8px}
.shift-banner-note{font-weight:400;color:#0369a1;font-size:.85rem}
.shift-banner-grid{display:flex;gap:36px;flex-wrap:wrap}
.shift-banner-grid>div{font-size:.95rem}
.shift-banner-std{color:#0369a1;font-size:.85rem}
.shift-banner-late{color:#b45309;font-size:.82rem;margin-top:2px}
.late-hint{font-size:.75rem;margin-top:2px;min-height:14px}
.late-hint.late{color:#b91c1c;font-weight:600}
.late-hint.ok{color:#15803d}
.late-modal{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:18px}
.late-modal.open{display:flex}
.late-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px)}
.late-modal-card{position:relative;width:min(460px,100%);background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden;animation:latePop .18s ease-out}
@keyframes latePop{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
.late-modal-head{display:flex;gap:12px;align-items:center;padding:18px 20px;background:linear-gradient(135deg,#fff7ed,#ffedd5);border-bottom:1px solid #fed7aa}
.late-modal-badge{width:42px;height:42px;border-radius:12px;background:#ea580c;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex:none;box-shadow:0 6px 14px rgba(234,88,12,.35)}
.late-modal-heading h2{margin:0;font-size:1.05rem;color:#6a4cc4}
.late-modal-heading p{margin:2px 0 0;font-size:.85rem;color:#9a3412}
.late-modal-x{margin-left:auto;border:0;background:transparent;font-size:1.5rem;line-height:1;color:#9a3412;cursor:pointer;padding:4px 8px;border-radius:8px}
.late-modal-x:hover{background:rgba(154,52,18,.1)}
.late-modal-body{padding:20px}
.late-modal-meta{font-size:.82rem;color:#64748b;margin-bottom:14px}
.late-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
.late-field{display:flex;flex-direction:column;gap:6px;font-size:.85rem;color:#334155;font-weight:600}
.late-field em{color:#dc2626;font-style:normal}
.late-field small{font-weight:400;color:#94a3b8}
.late-field input,.late-field select{padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit;font-weight:400;color:#0f172a;background:#fff;width:100%;box-sizing:border-box}
.late-field input:focus,.late-field select:focus{outline:none;border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.15)}
.late-field-time input{font-size:1.15rem;letter-spacing:.04em}
.late-modal-result{min-height:22px;margin-top:12px;font-size:.88rem;font-weight:600}
.late-modal-result.late{color:#b91c1c}
.late-modal-result.ok{color:#15803d}
.late-modal-result.empty{color:#94a3b8;font-weight:400}
.late-modal-note{margin:6px 0 0;font-size:.78rem;color:#94a3b8;line-height:1.4}
.late-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #f1f5f9;background:#f8fafc}
.late-btn-ghost{padding:10px 16px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#475569;font:inherit;font-weight:600;cursor:pointer}
.late-btn-ghost:hover{background:#f1f5f9}
body.late-modal-open{overflow:hidden}
.attendance-row.row-flash td{animation:rowFlash 1.2s ease-out}
@keyframes rowFlash{from{background:#fef3c7}to{background:transparent}}
@media (max-width:520px){.late-modal-grid{grid-template-columns:1fr}}
</style>

<script>
(function () {
    var GRACE = <?= json_encode(array_map(static function ($session) {
        return $session['grace'];
    }, $workSessions)) ?>;
    // Present is on time by default and half-day needs no time either. Only a
    // late row must carry the clock-in that justifies it.
    var NEEDS_TIME = ['late'];
    var ATT_DATE = <?= json_encode(format_date($date)) ?>;

    var modal = document.getElementById('lateModal');
    var modalEmployee = document.getElementById('lateModalEmployee');
    var modalMeta = document.getElementById('lateModalMeta');
    var modalShift = document.getElementById('lateModalShift');
    var modalClockIn = document.getElementById('lateModalClockIn');
    var modalClockOut = document.getElementById('lateModalClockOut');
    var modalResult = document.getElementById('lateModalResult');
    var modalSave = document.getElementById('lateModalSave');
    var modalCard = modal ? modal.querySelector('.late-modal-card') : null;
    var modalRow = null;
    var lastFocused = null;
    var submitting = false;

    function minutesLate(time, shift) {
        if (!time) { return 0; }
        var parts = time.split(':');
        var total = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
        var grace = (GRACE[shift] || GRACE.morning).split(':');
        var limit = parseInt(grace[0], 10) * 60 + parseInt(grace[1], 10);
        return Math.max(0, total - limit);
    }

    function syncRow(row) {
        var clockIn = row.querySelector('.clock-in');
        var shift = row.querySelector('.shift-select');
        var status = row.querySelector('.status-select');
        var hint = row.querySelector('.late-hint');
        if (!clockIn || !shift || !status || !hint) { return; }
        var time = clockIn.value;
        var late = minutesLate(time, shift.value);

        if (time && (status.value === 'present' || status.value === 'late' || status.value === '')) {
            status.value = late > 0 ? 'late' : 'present';
        }
        if (!time) {
            hint.textContent = '';
            hint.className = 'late-hint';
        } else if (late > 0) {
            hint.textContent = 'Late by ' + late + ' min';
            hint.className = 'late-hint late';
        } else {
            hint.textContent = 'On time';
            hint.className = 'late-hint ok';
        }
        clockIn.required = NEEDS_TIME.indexOf(status.value) !== -1;
    }

    function shiftLabel() {
        return modalShift.options[modalShift.selectedIndex]
            ? modalShift.options[modalShift.selectedIndex].text
            : '';
    }

    function updateModalResult() {
        var time = modalClockIn.value;
        var late = minutesLate(time, modalShift.value);
        modalMeta.textContent = ATT_DATE + ' · ' + shiftLabel();
        if (!time) {
            modalResult.textContent = 'Enter the clock-in time to continue.';
            modalResult.className = 'late-modal-result empty';
        } else if (late > 0) {
            modalResult.textContent = 'Late by ' + late + ' min';
            modalResult.className = 'late-modal-result late';
        } else {
            modalResult.textContent = 'On time — this will be saved as present.';
            modalResult.className = 'late-modal-result ok';
        }
        modalSave.disabled = !time;
    }

    function openLateModal(row) {
        if (!modal || !row) { return; }
        modalRow = row;
        lastFocused = document.activeElement;
        var shift = row.querySelector('.shift-select');
        var clockIn = row.querySelector('.clock-in');
        var clockOut = row.querySelector('.clock-out');
        modalEmployee.textContent = row.getAttribute('data-employee') || '';
        modalShift.value = shift ? shift.value : 'morning';
        modalClockIn.value = clockIn ? clockIn.value : '';
        modalClockOut.value = clockOut ? clockOut.value : '';
        updateModalResult();
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('late-modal-open');
        setTimeout(function () { modalClockIn.focus(); }, 40);
    }

    function closeLateModal(refocusRow) {
        if (!modal || !modal.classList.contains('open')) { return; }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('late-modal-open');
        var row = modalRow;
        modalRow = null;
        if (refocusRow && row) {
            var target = row.querySelector('.clock-in');
            if (target) { target.focus(); return; }
        }
        if (lastFocused && lastFocused.focus) { lastFocused.focus(); }
    }

    function saveLateModal() {
        if (!modalRow || !modalClockIn.value) { return; }
        var row = modalRow;
        var clockIn = row.querySelector('.clock-in');
        var clockOut = row.querySelector('.clock-out');
        var shift = row.querySelector('.shift-select');
        var status = row.querySelector('.status-select');
        if (clockIn) { clockIn.value = modalClockIn.value; }
        if (clockOut) { clockOut.value = modalClockOut.value; }
        if (shift) { shift.value = modalShift.value; }
        syncRow(row);
        row.dataset.wasLate = status && status.value === 'late' ? '1' : '0';
        row.classList.remove('row-flash');
        void row.offsetWidth;
        row.classList.add('row-flash');
        setTimeout(function () { row.classList.remove('row-flash'); }, 1200);
        closeLateModal(false);
        if (clockIn) { clockIn.focus(); }
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-late-close]')) {
                closeLateModal(true);
            }
        });
        modalClockIn.addEventListener('input', updateModalResult);
        modalClockIn.addEventListener('change', updateModalResult);
        modalShift.addEventListener('change', updateModalResult);
        modalSave.addEventListener('click', saveLateModal);
        modalClockIn.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveLateModal();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeLateModal(true);
                return;
            }
            if (event.key === 'Tab' && modal.classList.contains('open') && modalCard) {
                var focusables = modalCard.querySelectorAll('button, input, select, [tabindex]:not([tabindex="-1"])');
                if (!focusables.length) { return; }
                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    function markLateState(row, status) {
        row.dataset.wasLate = status && status.value === 'late' ? '1' : '0';
    }

    document.querySelectorAll('.attendance-row').forEach(function (row) {
        var clockIn = row.querySelector('.clock-in');
        var shift = row.querySelector('.shift-select');
        var status = row.querySelector('.status-select');
        markLateState(row, status);

        if (clockIn) {
            clockIn.addEventListener('input', function () { syncRow(row); });
            clockIn.addEventListener('change', function () {
                syncRow(row);
                var becameLate = status && status.value === 'late' && row.dataset.wasLate !== '1';
                markLateState(row, status);
                if (!becameLate) { return; }
                // If the user is on their way to "Save Attendance", the time is
                // already recorded, so do not interrupt the submit with a popup.
                setTimeout(function () {
                    if (submitting) { return; }
                    var active = document.activeElement;
                    if (active && (active.type === 'submit'
                        || (active.closest && active.closest('button[type="submit"]')))) {
                        return;
                    }
                    openLateModal(row);
                }, 0);
            });
        }
        if (shift) {
            shift.addEventListener('change', function () { syncRow(row); });
        }
        if (status) {
            status.addEventListener('change', function () {
                syncRow(row);
                var becameLate = status.value === 'late' && row.dataset.wasLate !== '1';
                markLateState(row, status);
                if (becameLate) { openLateModal(row); }
            });
        }
        syncRow(row);
    });

    var form = document.querySelector('form.attendance-form');
    if (form) {
        form.addEventListener('submit', function (event) {
            var missing = [];
            var firstLateWithoutTime = null;
            document.querySelectorAll('.attendance-row').forEach(function (row) {
                var status = row.querySelector('.status-select');
                var clockIn = row.querySelector('.clock-in');
                if (!status || !clockIn) { return; }
                if (NEEDS_TIME.indexOf(status.value) !== -1 && !clockIn.value) {
                    missing.push(row.getAttribute('data-employee') || 'employee');
                    if (!firstLateWithoutTime && status.value === 'late') {
                        firstLateWithoutTime = row;
                    }
                }
            });
            if (missing.length) {
                event.preventDefault();
                alert('Record the clock-in time for: ' + missing.join(', '));
                if (firstLateWithoutTime) { openLateModal(firstLateWithoutTime); }
                return;
            }
            submitting = true;
            var btn = form.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        });
    }
})();
</script>

<?php require_once "../partials/footer.php"; ?>
