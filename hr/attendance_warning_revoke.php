<?php
/**
 * Revoke or restore a printed attendance warning letter.
 *
 * A warning is generated from the attendance register, so revocation is stored
 * separately using the employee and exact warning period. Revoked warnings are
 * removed from the active register and cannot be printed again until restored.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
ensure_department_access_schema();
if (!has_permission('hr', 'approve')) {
    http_response_code(403);
    echo '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied | Block Plant ERP</title>
    <style>
        body { margin: 0; font-family: Segoe UI, Arial, sans-serif; background: linear-gradient(135deg, #f8fafc, #fef2f2); color: #1f2937; display: grid; place-items: center; min-height: 100vh; }
        .card { background: #fff; border: 1px solid #fecaca; border-radius: 12px; box-shadow: 0 20px 40px rgba(153, 27, 27, .08); padding: 36px 40px; text-align: center; max-width: 420px; }
        h1 { color: #991b1b; margin: 0 0 10px; }
        p { color: #475569; margin: 0 0 18px; line-height: 1.6; }
        a { display: inline-block; padding: 10px 16px; border-radius: 8px; background: #991b1b; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Access denied</h1>
        <p>You need <strong>Approve</strong> permission for HR to revoke or restore attendance warning letters.</p>
        <a href="attendance_warnings.php">Back to Attendance Warnings</a>
    </div>
</body>
</html>';
    exit;
}

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$wt = function ($key) {
    return attendance_warning_text($key);
};

$warningThreshold = 3;
$today = date('Y-m-d');
$employeeId = (int) ($_REQUEST['employee_id'] ?? 0);
[$from, $to] = attendance_report_bounds($_REQUEST['from'] ?? date('Y-m-01'), $_REQUEST['to'] ?? $today);
if ($to > $today) {
    $to = $today;
}
if ($from > $to) {
    $from = date('Y-m-01', strtotime($to));
}
$department = trim((string) ($_REQUEST['department'] ?? ''));

$backParams = ['from' => $from, 'to' => $to];
if ($department !== '') {
    $backParams['department'] = $department;
}
$backUrl = 'attendance_warnings.php?' . http_build_query($backParams);
$selfParams = ['employee_id' => $employeeId, 'from' => $from, 'to' => $to];
if ($department !== '') {
    $selfParams['department'] = $department;
}
$selfUrl = 'attendance_warning_revoke.php?' . http_build_query($selfParams);

ensure_attendance_warning_schema();

$employeeStmt = $pdo->prepare(
    "SELECT id, name, employee_code, department, designation, date_of_joining
     FROM employees
     WHERE id = ?"
);
$employeeStmt->execute([$employeeId]);
$employee = $employeeStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$employee) {
        set_flash('error', $wt('employee_not_found_text'));
        header('Location: ' . $backUrl);
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'revoke');

    if ($action === 'restore') {
        if (restore_attendance_warning($employeeId, $from, $to)) {
            log_activity('update', 'hr', $employeeId, 'Attendance warning restored for ' . $from . ' to ' . $to);
            set_flash('success', sprintf($wt('restore_success'), $employee['name']));
        } else {
            set_flash('error', 'Could not restore the warning letter. Please try again.');
        }
        header('Location: ' . $backUrl);
        exit;
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    $evidenceReference = trim((string) ($_POST['evidence_reference'] ?? ''));
    if ($reason === '') {
        set_flash('error', 'Please describe the reason or evidence before revoking the warning.');
        header('Location: ' . $selfUrl);
        exit;
    }

    $days = attendance_absence_days($employeeId, $from, $to);
    $summary = attendance_absence_summary($days);
    if (attendance_warning_effective_revocation($employeeId, $from, $to, $summary['dates'])) {
        set_flash('warning', $wt('revoke_already'));
        header('Location: ' . $backUrl);
        exit;
    }

    if ($summary['total'] < $warningThreshold) {
        set_flash('error', $wt('revoke_no_warning'));
        header('Location: ' . $backUrl);
        exit;
    }

    $revocationId = revoke_attendance_warning($employeeId, $from, $to, $reason, $evidenceReference, (int) ($_SESSION['user_id'] ?? 0));
    if ($revocationId <= 0) {
        set_flash('error', 'Could not revoke the warning letter. Please try again.');
        header('Location: ' . $backUrl);
        exit;
    }

    $evidenceErrors = [];
    $attachedCount = 0;
    if (isset($_FILES['evidence_files'])) {
        $files = $_FILES['evidence_files'];
        if (is_array($files['name'])) {
            $count = count($files['name']);
            if ($count > 5) {
                $evidenceErrors[] = $wt('evidence_too_many');
                $count = 5;
            }
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $file = [
                    'name' => $files['name'][$i] ?? '',
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$i] ?? 0,
                ];
                $result = store_attendance_warning_evidence($revocationId, $file, (int) ($_SESSION['user_id'] ?? 0));
                if ($result['ok']) {
                    $attachedCount++;
                } else {
                    $evidenceErrors[] = $result['error'] ?? $wt('evidence_upload_failed');
                }
            }
        }
    }

    log_activity(
        'update',
        'hr',
        $employeeId,
        'Attendance warning revoked for ' . $from . ' to ' . $to
            . '. Reason: ' . mb_substr($reason, 0, 200)
    );

    if ($attachedCount > 0 && empty($evidenceErrors)) {
        set_flash('success', sprintf($wt('revoke_success'), $employee['name']) . ' ' . sprintf($wt('evidence_attached'), $attachedCount));
    } elseif ($attachedCount > 0 && $evidenceErrors) {
        set_flash('warning', sprintf($wt('evidence_partial'), implode(' ', $evidenceErrors)));
    } elseif ($evidenceErrors) {
        set_flash('warning', sprintf($wt('revoke_success'), $employee['name']) . ' ' . $wt('evidence_upload_failed') . ' ' . implode(' ', $evidenceErrors));
    } else {
        set_flash('success', sprintf($wt('revoke_success'), $employee['name']));
    }
    header('Location: ' . $backUrl);
    exit;
}

if (!$employee) {
    render_not_found(
        $wt('employee_not_found_title'),
        $wt('employee_not_found_text'),
        $backUrl,
        $wt('back_to_warnings_label')
    );
}

$days = attendance_absence_days($employeeId, $from, $to);
$summary = attendance_absence_summary($days);
$revocation = attendance_warning_effective_revocation($employeeId, $from, $to, $summary['dates']);
$revocationEvidence = [];
if ($revocation && isset($revocation['id'])) {
    $revocationEvidence = attendance_warning_evidence_files((int) $revocation['id']);
}
$pageTitle = $wt('revoke_title');
$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .revoke-wrap { max-width: 900px; }
    .revoke-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
    .revoke-head h1 { margin: 0 0 4px; }
    .revoke-meta { color: #64748b; font-size: .9rem; }
    .revoke-panel { padding: 20px; margin-bottom: 18px; }
    .revoke-employee { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 14px 0 18px; }
    .revoke-field { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
    .revoke-field span { display: block; color: #64748b; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
    .revoke-field strong { color: #1c2250; }
    .revoke-dates { color: #475569; font-size: .9rem; line-height: 1.65; }
    .revoke-note { border-left: 4px solid #dc2626; background: #fef2f2; color: #7f1d1d; padding: 12px 14px; border-radius: 8px; margin: 16px 0; }
    .revoke-warning-note { border-left: 4px solid #d97706; background: #fffbeb; color: #78350f; padding: 12px 14px; border-radius: 8px; margin: 16px 0; }
    .revoke-form label { margin-top: 0; }
    .revoke-form textarea { width: 100%; min-height: 120px; resize: vertical; }
    .revoke-form input[type="text"] { width: 100%; }
    .revoke-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 16px; }
    .revoke-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: .8rem; font-weight: 700; }
</style>

<div class="revoke-wrap">
    <div class="revoke-head">
        <div>
            <h1>🚫 <?= e($wt('revoke_title')) ?></h1>
            <div class="revoke-meta">
                <?= e($employee['name']) ?> · <?= e($employee['employee_code']) ?>
                <?php if (!empty($employee['department'])): ?>
                · <?= e($employee['department']) ?>
                <?php endif; ?>
                · <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>
            </div>
        </div>
        <div>
            <a href="<?= e($backUrl) ?>" class="btn btn-secondary">← <?= e($wt('back_to_warnings')) ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
    <?php $flashClass = in_array($flash['type'] ?? 'success', ['error', 'danger'], true) ? 'error' : (in_array($flash['type'] ?? '', ['warning', 'warn'], true) ? 'warning' : 'success'); ?>
    <div class="flash flash-<?= e($flashClass) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel revoke-panel">
        <div class="revoke-employee">
            <div class="revoke-field"><span><?= e($wt('employee')) ?></span><strong><?= e($employee['name']) ?></strong></div>
            <div class="revoke-field"><span><?= e($wt('employee_code')) ?></span><strong><?= e($employee['employee_code']) ?></strong></div>
            <div class="revoke-field"><span><?= e($wt('department')) ?></span><strong><?= e($employee['department'] ?: '—') ?></strong></div>
            <div class="revoke-field"><span><?= e($wt('absent_days')) ?></span><strong><?= number_format((int) $summary['total']) ?></strong></div>
        </div>

        <?php if ($revocation): ?>
        <div>
            <span class="revoke-badge"><?= e($wt('revoked_badge')) ?></span>
            <h2 style="margin:12px 0 6px;"><?= e($wt('revoked_title')) ?></h2>
            <p style="color:#475569; margin:0 0 12px;"><?= e($wt('revoked_text')) ?></p>
        </div>
        <div class="revoke-employee">
            <div class="revoke-field"><span><?= e($wt('warning_period')) ?></span><strong><?= e(format_date($revocation['period_from'])) ?> – <?= e(format_date($revocation['period_to'])) ?></strong></div>
            <div class="revoke-field"><span><?= e($wt('revoked_reason')) ?></span><strong><?= e($revocation['reason']) ?></strong></div>
            <?php if (!empty($revocation['evidence_reference'])): ?>
            <div class="revoke-field"><span><?= e($wt('revoke_evidence')) ?></span><strong><?= e($revocation['evidence_reference']) ?></strong></div>
            <?php endif; ?>
            <div class="revoke-field"><span><?= e($wt('revoked_by')) ?></span><strong><?= e($revocation['revoked_by_name'] ?? '—') ?></strong></div>
            <div class="revoke-field"><span><?= e($wt('revoked_at')) ?></span><strong><?= e(format_datetime($revocation['revoked_at'])) ?></strong></div>
        </div>
        <?php if ($revocationEvidence): ?>
        <div style="margin:18px 0;">
            <h3 style="margin:0 0 10px;"><?= e($wt('evidence_files')) ?></h3>
            <div style="display:grid; gap:10px;">
                <?php foreach ($revocationEvidence as $evidenceFile): ?>
                <div style="display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:center; padding:10px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc;">
                    <div>
                        <div style="font-weight:600; color:#1c2250;"><?= e($evidenceFile['original_name']) ?></div>
                        <div style="color:#64748b; font-size:.85rem;">
                            <?= e(format_bytes((int) $evidenceFile['file_size'])) ?>
                            <?php if (!empty($evidenceFile['uploaded_by_name'])): ?>· <?= e($wt('uploaded_by')) ?>: <?= e($evidenceFile['uploaded_by_name']) ?><?php endif; ?>
                            <?php if (!empty($evidenceFile['uploaded_at'])): ?>· <?= e($wt('uploaded_at')) ?>: <?= e(format_datetime($evidenceFile['uploaded_at'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <a href="attendance_warning_evidence.php?file_id=<?= (int) $evidenceFile['id'] ?>" class="btn btn-secondary"><?= e($wt('download')) ?></a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm(<?= e(json_encode($wt('restore_confirm'))) ?>);">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
            <input type="hidden" name="from" value="<?= e($revocation['period_from']) ?>">
            <input type="hidden" name="to" value="<?= e($revocation['period_to']) ?>">
            <input type="hidden" name="department" value="<?= e($department) ?>">
            <div class="revoke-actions">
                <button type="submit" class="btn"><?= e($wt('restore_button')) ?></button>
                <a href="<?= e($backUrl) ?>" class="btn btn-secondary"><?= e($wt('back_to_warnings')) ?></a>
            </div>
        </form>
        <?php elseif ($summary['total'] < $warningThreshold): ?>
        <div class="revoke-warning-note"><?= e($wt('revoke_no_warning')) ?></div>
        <a href="<?= e($backUrl) ?>" class="btn btn-secondary">← <?= e($wt('back_to_warnings')) ?></a>
        <?php else: ?>
        <p style="color:#475569; margin:0 0 12px;"><?= e($wt('revoke_intro')) ?></p>
        <div class="revoke-dates">
            <strong><?= e($wt('absence_dates')) ?>:</strong>
            <?= e(implode(', ', array_map('format_date', $summary['dates']))) ?>
        </div>
        <div class="revoke-note"><?= e($wt('revoked_text')) ?></div>
        <form method="post" class="revoke-form" enctype="multipart/form-data" onsubmit="return confirm(<?= e(json_encode($wt('revoke_confirm'))) ?>);">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
            <input type="hidden" name="from" value="<?= e($from) ?>">
            <input type="hidden" name="to" value="<?= e($to) ?>">
            <input type="hidden" name="department" value="<?= e($department) ?>">
            <div>
                <label for="reason"><?= e($wt('revoke_reason')) ?> *</label>
                <textarea id="reason" name="reason" required maxlength="4000" placeholder="<?= e($wt('revoke_reason_placeholder')) ?>"></textarea>
            </div>
            <div style="margin-top:12px;">
                <label for="evidence_reference"><?= e($wt('revoke_evidence')) ?></label>
                <input type="text" id="evidence_reference" name="evidence_reference" maxlength="255" placeholder="<?= e($wt('revoke_evidence_placeholder')) ?>">
            </div>
            <div style="margin-top:16px;">
                <label for="evidence_files"><?= e($wt('evidence_files')) ?></label>
                <input type="file" id="evidence_files" name="evidence_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                <div style="color:#64748b; font-size:.85rem; margin-top:6px;"><?= e($wt('evidence_files_help')) ?></div>
            </div>
            <div class="revoke-actions">
                <button type="submit" class="btn"><?= e($wt('revoke_submit')) ?></button>
                <a href="<?= e($backUrl) ?>" class="btn btn-secondary"><?= e($wt('back_to_warnings')) ?></a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
