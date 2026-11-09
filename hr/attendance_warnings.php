<?php
/**
 * Attendance warning register.
 *
 * Lists active employees who have more than two recorded full-day absences on
 * working days in the selected period. HR editors, approvers and administrators
 * can open a printable warning letter; revoking or restoring a letter requires
 * the Approve permission.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$canManageWarnings = has_permission('hr', 'edit') || has_permission('hr', 'approve');
$canRevokeWarnings = has_permission('hr', 'approve');
$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
[$from, $to] = attendance_report_bounds($_GET['from'] ?? $defaultFrom, $_GET['to'] ?? $today);
if ($to > $today) {
    $to = $today;
}
if ($from > $to) {
    $from = date('Y-m-01', strtotime($to));
}

$department = trim((string) ($_GET['department'] ?? ''));
$departments = $pdo->query(
    "SELECT DISTINCT department FROM employees
     WHERE status = 1 AND department IS NOT NULL AND department <> ''
     ORDER BY department"
)->fetchAll(PDO::FETCH_COLUMN);
if ($department !== '' && !in_array($department, $departments, true)) {
    $department = '';
}

$warningThreshold = 3; // "more than two days"
ensure_attendance_warning_schema();
$revocations = attendance_warning_revocations($from, $to, $department);
$revocationsByEmployee = [];
foreach ($revocations as $revocationRow) {
    $revocationsByEmployee[(int) $revocationRow['employee_id']][] = $revocationRow;
}
$candidates = array_values(array_filter(
    attendance_warning_candidates($from, $to, $department, $warningThreshold),
    static function ($candidate) use ($revocationsByEmployee) {
        foreach ($revocationsByEmployee[(int) $candidate['id']] ?? [] as $revocationRow) {
            if (attendance_warning_revocation_covers($revocationRow, $candidate['summary']['dates'])) {
                return false;
            }
        }
        return true;
    }
));
$totalAbsentDays = 0;
foreach ($candidates as $candidate) {
    $totalAbsentDays += (int) $candidate['summary']['total'];
}

$companyName = company_display_name();
$pageTitle = attendance_warning_text('title');
$flash = get_flash();
$reportParams = ['from' => $from, 'to' => $to];
if ($department !== '') {
    $reportParams['department'] = $department;
}
$reportUrl = 'attendance_report.php?' . http_build_query($reportParams);

require_once "../partials/header.php";
?>

<style>
    .warning-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .warning-head h1 { margin: 0 0 4px; }
    .warning-company { font-size: 1.15rem; font-weight: 700; color: #1f2933; }
    .warning-meta { color: #64748b; font-size: .88rem; margin-top: 4px; }
    .warning-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .warning-filters { padding: 18px; margin-bottom: 18px; }
    .warning-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        align-items: end;
    }
    .warning-filter-grid label { margin-top: 0; }
    .warning-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .warning-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .warning-kpi {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-top: 3px solid #d97706;
        border-radius: 10px;
        padding: 14px 12px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,.05);
    }
    .warning-kpi strong { display: block; font-size: 1.45rem; color: #1f2933; }
    .warning-kpi span { color: #64748b; font-size: .78rem; }
    .warning-kpi.kpi-days { border-top-color: #dc2626; }
    .warning-kpi.kpi-period { border-top-color: #2563eb; }
    .warning-kpi.kpi-rule { border-top-color: #7c3aed; }
    .warning-table { width: 100%; border-collapse: collapse; }
    .warning-table th,
    .warning-table td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    .warning-table th { background: #f1f5f9; color: #334155; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
    .warning-table td.warning-num { white-space: nowrap; }
    .warning-table .btn { white-space: nowrap; padding: 7px 10px; font-size: .8rem; }
    .warning-table .btn-revoke { background: #b91c1c; color: #fff; }
    .warning-actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
    .warning-revoked { margin-top: 20px; }
    .warning-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        background: #fee2e2;
        color: #991b1b;
        font-size: .8rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .warning-consecutive {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 999px;
        background: #fef3c7;
        color: #92400e;
        font-size: .78rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .warning-dates { color: #475569; max-width: 330px; font-size: .88rem; line-height: 1.5; }
    .warning-dates .more { color: #64748b; font-size: .78rem; }
    .warning-emp-name { font-weight: 700; color: #1f2933; }
    .warning-emp-code { color: #64748b; font-size: .8rem; }
    .warning-emp-sub { color: #64748b; font-size: .8rem; margin-top: 2px; }
    .warning-empty { text-align: center; padding: 30px 20px; color: #64748b; }
    .warning-empty h2 { margin-top: 0; color: #1f2933; }
    .warning-note { border-left: 4px solid #d97706; background: #fffbeb; color: #78350f; padding: 14px 16px; border-radius: 8px; margin-top: 18px; }
    .warning-note strong { color: #92400e; }
    .warning-muted { color: #94a3b8; font-size: .8rem; }
    @media print {
        .topbar, .navbar, footer, .fab-wrap, .cmdk-overlay, .kbd-overlay, .no-print { display: none !important; }
        .container { max-width: none; margin: 0; padding: 0; }
        body { background: #fff; color: #000; }
        .panel { box-shadow: none !important; border: 0 !important; padding: 0 !important; margin: 0 0 14px !important; }
        .warning-kpis { grid-template-columns: repeat(4, 1fr); gap: 6px; }
        .warning-table { font-size: 8.5pt; }
        .warning-table th, .warning-table td { padding: 4px 5px; }
        .warning-table thead { display: table-header-group; }
        .warning-table tr { page-break-inside: avoid; }
    }
</style>

<div class="warning-head">
    <div>
        <div class="warning-company"><?= e($companyName) ?></div>
        <h1>⚠ <?= e(attendance_warning_text('title')) ?></h1>
        <div class="warning-meta">
            <?= e(attendance_warning_text('meta')) ?>
            <?php if ($department !== ''): ?>
            · <?= e(t('department')) ?>: <?= e($department) ?>
            <?php endif; ?>
            · <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>
        </div>
    </div>
    <div class="warning-actions no-print">
        <a href="attendance.php" class="btn btn-secondary">← <?= e(attendance_warning_text('attendance_register')) ?></a>
        <a href="<?= e($reportUrl) ?>" class="btn btn-secondary">📊 <?= e(attendance_warning_text('attendance_report')) ?></a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<p class="no-print" style="color:#64748b; margin:-6px 0 16px;">
    <?= e(attendance_warning_text('intro')) ?>
</p>

<form method="get" class="panel warning-filters no-print">
    <div class="warning-filter-grid">
        <div>
            <label for="from"><?= e(t('date_from')) ?></label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" required>
        </div>
        <div>
            <label for="to"><?= e(t('date_to')) ?></label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" required>
        </div>
        <div>
            <label for="department"><?= e(t('department')) ?></label>
            <select id="department" name="department">
                <option value=""><?= e(t('attendance_report_all_departments')) ?></option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="warning-filter-actions">
        <button type="submit" class="btn"><?= e(t('attendance_report_apply')) ?></button>
        <a href="attendance_warnings.php" class="btn btn-secondary"><?= e(t('reset')) ?></a>
    </div>
</form>

<div class="warning-kpis">
    <div class="warning-kpi"><strong><?= number_format(count($candidates)) ?></strong><span><?= e(attendance_warning_text('employees_flagged')) ?></span></div>
    <div class="warning-kpi kpi-days"><strong><?= number_format($totalAbsentDays) ?></strong><span><?= e(attendance_warning_text('total_absent_days')) ?></span></div>
    <div class="warning-kpi kpi-period"><strong style="font-size:1rem;"><?= e(format_date($from)) ?><br>–<br><?= e(format_date($to)) ?></strong><span><?= e(attendance_warning_text('warning_period')) ?></span></div>
    <div class="warning-kpi kpi-rule"><strong>&gt; 2</strong><span><?= e(attendance_warning_text('threshold')) ?></span></div>
</div>

<?php if (!$candidates): ?>
<div class="panel warning-empty">
    <h2>✅ <?= e(attendance_warning_text('no_warnings_title')) ?></h2>
    <p><?= e(attendance_warning_text('no_warnings_text')) ?></p>
    <p><a href="attendance.php" class="btn btn-secondary"><?= e(attendance_warning_text('open_register')) ?></a></p>
</div>
<?php else: ?>
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
        <h2 style="margin:0;"><?= e(attendance_warning_text('requiring_warning')) ?></h2>
        <span class="warning-muted"><?= number_format(count($candidates)) ?> <?= e(attendance_warning_text(count($candidates) === 1 ? 'employee_singular' : 'employee_plural')) ?></span>
    </div>
    <div style="overflow-x:auto;">
        <table class="warning-table">
            <thead>
                <tr>
                    <th><?= e(attendance_warning_text('employee')) ?></th>
                    <th><?= e(t('department')) ?></th>
                    <th><?= e(attendance_warning_text('absent_days')) ?></th>
                    <th><?= e(attendance_warning_text('longest_consecutive')) ?></th>
                    <th><?= e(attendance_warning_text('absence_dates')) ?></th>
                    <th class="no-print"><?= e(attendance_warning_text('action')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($candidates as $candidate):
                    $summary = $candidate['summary'];
                    $dates = $summary['dates'];
                    $shownDates = array_slice($dates, 0, 6);
                    $allDatesLabel = implode(', ', array_map('format_date', $dates));
                    $printUrl = 'attendance_warning_print.php?' . http_build_query([
                        'employee_id' => (int) $candidate['id'],
                        'from' => $from,
                        'to' => $to,
                        'department' => $department,
                        'print' => 1,
                    ]);
                    $revokeUrl = 'attendance_warning_revoke.php?' . http_build_query([
                        'employee_id' => (int) $candidate['id'],
                        'from' => $from,
                        'to' => $to,
                        'department' => $department,
                    ]);
                ?>
                <tr>
                    <td>
                        <div class="warning-emp-name"><?= e($candidate['name']) ?></div>
                        <div class="warning-emp-code"><?= e($candidate['employee_code']) ?></div>
                        <?php if (!empty($candidate['designation'])): ?>
                        <div class="warning-emp-sub"><?= e($candidate['designation']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($candidate['department'] ?: '—') ?></td>
                    <td class="warning-num">
                        <span class="warning-badge"><?= number_format($summary['total']) ?> <?= e(attendance_warning_text((int) $summary['total'] === 1 ? 'day' : 'days')) ?></span>
                    </td>
                    <td class="warning-num">
                        <span class="warning-consecutive"><?= number_format($summary['longest_consecutive']) ?> <?= e(attendance_warning_text((int) $summary['longest_consecutive'] === 1 ? 'day' : 'days')) ?></span>
                    </td>
                    <td class="warning-dates" title="<?= e($allDatesLabel) ?>">
                        <?= e(implode(', ', array_map('format_date', $shownDates))) ?>
                        <?php if (count($dates) > count($shownDates)): ?>
                        <span class="more">+<?= number_format(count($dates) - count($shownDates)) ?> <?= e(attendance_warning_text('more')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print">
                        <?php if ($canManageWarnings): ?>
                        <div class="warning-actions-cell">
                            <a href="<?= e($printUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary" title="<?= e(attendance_warning_text('warning_letter')) ?>">🖨 <?= e(attendance_warning_text('letter_button')) ?></a>
                            <?php if ($canRevokeWarnings): ?>
                            <a href="<?= e($revokeUrl) ?>" class="btn btn-revoke" title="<?= e(attendance_warning_text('revoke_button')) ?>">🚫 <?= e(attendance_warning_text('revoke_button')) ?></a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="warning-muted"><?= e(attendance_warning_text('hr_editor_required')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($revocations): ?>
<div class="panel warning-revoked no-print">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
        <h2 style="margin:0;"><?= e(attendance_warning_text('revoked_section')) ?></h2>
        <span class="warning-muted"><?= number_format(count($revocations)) ?></span>
    </div>
    <div style="overflow-x:auto;">
        <table class="warning-table">
            <thead>
                <tr>
                    <th><?= e(attendance_warning_text('employee')) ?></th>
                    <th><?= e(attendance_warning_text('revoked_reason')) ?></th>
                    <th><?= e(attendance_warning_text('revoked_by')) ?></th>
                    <th><?= e(attendance_warning_text('revoked_at')) ?></th>
                    <th><?= e(attendance_warning_text('action')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($revocations as $revocationRow): ?>
                <tr>
                    <td>
                        <div class="warning-emp-name"><?= e($revocationRow['employee_name']) ?></div>
                        <div class="warning-emp-code"><?= e($revocationRow['employee_code']) ?></div>
                        <?php if (!empty($revocationRow['department'])): ?>
                        <div class="warning-emp-sub"><?= e($revocationRow['department']) ?></div>
                        <?php endif; ?>
                        <div class="warning-emp-sub">
                            <?= e(attendance_warning_text('warning_period')) ?>:
                            <?= e(format_date($revocationRow['period_from'])) ?> – <?= e(format_date($revocationRow['period_to'])) ?>
                        </div>
                    </td>
                    <td class="warning-dates">
                        <?= e($revocationRow['reason']) ?>
                        <?php if (!empty($revocationRow['evidence_reference'])): ?>
                        <div class="more"><?= e(attendance_warning_text('revoke_evidence')) ?>: <?= e($revocationRow['evidence_reference']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($revocationRow['revoked_by_name'] ?? '—') ?></td>
                    <td><?= e(format_datetime($revocationRow['revoked_at'])) ?></td>
                    <td>
                        <?php if ($canRevokeWarnings): ?>
                        <form method="post" action="attendance_warning_revoke.php" onsubmit="return confirm(<?= e(json_encode(attendance_warning_text('restore_confirm'))) ?>);" style="margin:0;">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="employee_id" value="<?= (int) $revocationRow['employee_id'] ?>">
                            <input type="hidden" name="from" value="<?= e($revocationRow['period_from']) ?>">
                            <input type="hidden" name="to" value="<?= e($revocationRow['period_to']) ?>">
                            <input type="hidden" name="department" value="<?= e($department) ?>">
                            <button type="submit" class="btn btn-secondary"><?= e(attendance_warning_text('restore_button')) ?></button>
                        </form>
                        <?php else: ?>
                        <span class="warning-muted"><?= e(attendance_warning_text('hr_approver_required')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="warning-note no-print">
    <strong><?= e(attendance_warning_text('policy_check')) ?></strong>
    <?= e(attendance_warning_text('policy_note')) ?>
</div>

<p style="color:#94a3b8; font-size:.78rem; margin-top:14px;">
    <?= e(attendance_warning_text('generated')) ?>: <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
</p>

<?php require_once "../partials/footer.php"; ?>
