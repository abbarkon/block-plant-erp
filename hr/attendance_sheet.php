<?php
/**
 * Printable employee attendance sheet (Ethiopian payroll form).
 *
 * One sheet is rendered per department by default. The "all employees" layout
 * puts everyone on a single sheet with a department column. Attendance counts
 * are pre-filled from the register.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$pageTitle = t('attendance_sheet');

[$previousFrom, $previousTo] = ethiopian_payroll_period(-1);
[$currentFrom, $currentTo] = ethiopian_payroll_period(0);

$from = trim((string) ($_GET['from'] ?? $previousFrom));
$to = trim((string) ($_GET['to'] ?? $previousTo));
[$from, $to] = attendance_report_bounds($from, $to);

$department = trim((string) ($_GET['department'] ?? ''));
$departments = $pdo->query(
    "SELECT DISTINCT department FROM employees
     WHERE status = 1 AND department IS NOT NULL AND department <> ''
     ORDER BY department"
)->fetchAll(PDO::FETCH_COLUMN);
if ($department !== '' && !in_array($department, $departments, true)) {
    $department = '';
}

$groupMode = trim((string) ($_GET['group'] ?? 'department'));
if (!in_array($groupMode, ['department', 'all'], true)) {
    $groupMode = 'department';
}
$showDepartmentColumn = $groupMode === 'all';

$groups = attendance_sheet_rows($from, $to, $department);
if ($showDepartmentColumn && $groups) {
    $allRows = [];
    foreach ($groups as $departmentRows) {
        foreach ($departmentRows as $departmentRow) {
            $allRows[] = $departmentRow;
        }
    }
    $groups = ['__all__' => $allRows];
}
$company = company_settings();
$companyAm = trim((string) ($company['company_name_am'] ?? ''));
$companyEn = trim((string) ($company['company_name'] ?? ''));
$companyName = $companyAm !== '' ? $companyAm : company_display_name();
$companyAlt = $companyAm !== '' ? $companyEn : '';
$dateLabel = format_ethiopian_short_date($from) . '-' . format_ethiopian_short_date($to);
$ethiopianRange = format_ethiopian_date($from) . ' – ' . format_ethiopian_date($to);
$periodIsCurrent = ($from === $currentFrom && $to === $currentTo);

// The sheet opens on the previous complete payroll period. When the newest
// saved attendance belongs to the current period, point HR there so a freshly
// filled register is not mistaken for missing data.
$showCurrentPeriodHint = false;
if (!$periodIsCurrent) {
    try {
        // Ignore auto-created leave rows. Only a real register entry saved
        // recently should point HR at the current period.
        $stmt = $pdo->prepare(
            "SELECT MAX(attendance_date) FROM attendance
             WHERE status <> 'leave' AND attendance_date BETWEEN ? AND ?"
        );
        $stmt->execute([$currentFrom, $currentTo]);
        $currentLast = (string) $stmt->fetchColumn();
        $showCurrentPeriodHint = $currentLast !== ''
            && $currentLast >= date('Y-m-d', strtotime('-3 days'));
    } catch (Throwable $e) {
        $showCurrentPeriodHint = false;
    }
}
$formatNumber = static function ($value) {
    $value = (float) $value;
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
};
// Late time is reported as hours:minutes (47 minutes -> "0:47", 90 -> "1:30").
$formatLateTime = static function ($minutes) {
    $minutes = max(0, (int) round((float) $minutes));
    if ($minutes === 0) {
        return '';
    }
    return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
};

require_once "../partials/header.php";
?>

<style>
    .attendance-sheet-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .attendance-sheet-toolbar h1 { margin: 0 0 4px; }
    .attendance-sheet-meta { color: #64748b; font-size: .88rem; }
    .attendance-sheet-filters { padding: 16px; margin-bottom: 18px; }
    .attendance-sheet-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        align-items: end;
    }
    .attendance-sheet-filter-grid label { margin-top: 0; }
    .attendance-sheet-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; align-items: center; }

    .attendance-form-sheet {
        background: #fff;
        border: 1px solid #111827;
        padding: 12px 14px 10px;
        margin: 0 auto 22px;
        max-width: 1120px;
        color: #111827;
        font-family: "Nyala", "Abyssinica SIL", "Noto Sans Ethiopic", "Segoe UI", Arial, sans-serif;
    }
    .attendance-form-company { text-align: center; font-weight: 700; font-size: 1.05rem; line-height: 1.5; }
    .attendance-form-company .company-alt { font-size: .95rem; font-weight: 600; }
    .attendance-form-title { text-align: center; font-weight: 700; margin: 3px 0 10px; }
    .attendance-form-meta {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #111827;
        border-bottom: 0;
        padding: 5px 8px;
        font-weight: 700;
        font-size: .92rem;
    }
    .attendance-form-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .attendance-form-table th,
    .attendance-form-table td {
        border: 1px solid #111827;
        padding: 5px 6px;
        vertical-align: middle;
        font-size: .9rem;
    }
    .attendance-form-table thead th { text-align: center; font-weight: 700; line-height: 1.35; }
    .attendance-form-table tbody td { height: 27px; }
    .attendance-form-table .col-no { width: 5.5%; text-align: center; }
    .attendance-form-table .col-name { width: 23%; }
    .attendance-form-table .col-dept { width: 12%; }
    .attendance-form-table .col-days { width: 13%; text-align: center; }
    .attendance-form-table .col-leave { width: 15%; text-align: center; }
    .attendance-form-table .col-absent-day { width: 10%; text-align: center; }
    .attendance-form-table .col-reason { width: 18%; }
    .attendance-form-table.with-dept .col-name { width: 19.5%; }
    .attendance-form-table.with-dept .col-days { width: 11.5%; }
    .attendance-form-table.with-dept .col-leave { width: 14%; }
    .attendance-form-table.with-dept .col-absent-day { width: 9%; }
    .attendance-form-table.with-dept .col-reason { width: 16.5%; }
    .attendance-form-table .cell-name { text-align: left; }
    .attendance-form-table .cell-dept { text-align: left; font-size: .82rem; color: #374151; }
    .attendance-form-table .cell-num { text-align: center; font-variant-numeric: tabular-nums; }
    .attendance-form-table .cell-reason { text-align: left; font-size: .82rem; color: #374151; }
    .attendance-form-note {
        border: 1px solid #111827;
        border-top: 0;
        padding: 7px 9px 9px;
        font-size: .82rem;
        line-height: 1.55;
    }
    .attendance-form-note .note-line { white-space: pre-wrap; }
    .attendance-form-signatures {
        display: flex;
        justify-content: space-between;
        gap: 24px;
        flex-wrap: wrap;
        margin-top: 12px;
    }
    .attendance-form-signatures span { white-space: nowrap; }
    .attendance-form-empty { padding: 26px; text-align: center; color: #64748b; }
    .attendance-form-generated { color: #94a3b8; font-size: .76rem; text-align: center; margin-top: 8px; }

    @media print {
        @page { size: A4 landscape; margin: 8mm; }
        .topbar, .navbar, #appSidebar, footer, .fab-wrap, .cmdk-overlay, .kbd-overlay,
        .sidebar-toggle, .sidebar-backdrop, .no-print { display: none !important; }
        .container { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
        body { background: #fff; }
        .attendance-sheet-toolbar,
        .attendance-sheet-filters { display: none !important; }
        .attendance-form-sheet {
            border: 1px solid #111827;
            padding: 6px 8px 4px;
            margin: 0;
            max-width: none;
            page-break-after: always;
        }
        .attendance-form-sheet-last { page-break-after: auto; }
        .attendance-form-table { font-size: 8.5pt; }
        .attendance-form-table thead th { background: #fff !important; border-radius: 0 !important; }
        .attendance-form-table th,
        .attendance-form-table td { padding: 3px 4px; }
        .attendance-form-table tbody td { height: 21px; }
        .attendance-form-table thead { display: table-header-group; }
        .attendance-form-table tr { page-break-inside: avoid; }
        .attendance-form-note { font-size: 7.8pt; }
        .attendance-form-generated { display: none; }
    }
</style>

<div class="attendance-sheet-toolbar">
    <div>
        <h1>🖨 <?= e(t('attendance_sheet')) ?></h1>
        <div class="attendance-sheet-meta">
            <?= e(t('attendance_report_period')) ?>: <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?>
            · <?= e($ethiopianRange) ?>
            <?php if ($department !== ''): ?>
            · <?= e(t('department')) ?>: <?= e($department) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="attendance-sheet-actions no-print">
        <a href="attendance_report.php?from=<?= e($from) ?>&to=<?= e($to) ?>&department=<?= e($department) ?>" class="btn btn-secondary">📊 <?= e(t('attendance_report')) ?></a>
        <a href="attendance.php" class="btn btn-secondary">← <?= e(t('attendance_report_back')) ?></a>
        <a href="attendance_sheet_export.php?from=<?= e(urlencode($from)) ?>&to=<?= e(urlencode($to)) ?>&department=<?= e(urlencode($department)) ?>&group=<?= e(urlencode($groupMode)) ?>" class="btn btn-secondary">📥 <?= e(t('attendance_report_export_excel')) ?></a>
        <button type="button" class="btn" onclick="window.print()">🖨 <?= e(t('print')) ?></button>
    </div>
</div>

<form method="get" class="panel attendance-sheet-filters no-print">
    <div class="attendance-sheet-filter-grid">
        <div>
            <label for="from"><?= e(t('attendance_report_from')) ?></label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" required>
        </div>
        <div>
            <label for="to"><?= e(t('attendance_report_to')) ?></label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" required>
        </div>
        <div>
            <label for="department"><?= e(t('attendance_report_department')) ?></label>
            <select id="department" name="department">
                <option value=""><?= e(t('attendance_report_all_departments')) ?></option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?= e($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="group"><?= e(t('attendance_sheet_layout')) ?></label>
            <select id="group" name="group">
                <option value="department" <?= $groupMode === 'department' ? 'selected' : '' ?>><?= e(t('attendance_sheet_layout_department')) ?></option>
                <option value="all" <?= $groupMode === 'all' ? 'selected' : '' ?>><?= e(t('attendance_sheet_layout_all')) ?></option>
            </select>
        </div>
    </div>
    <div class="attendance-sheet-actions">
        <button type="submit" class="btn"><?= e(t('attendance_report_apply')) ?></button>
        <a href="attendance_sheet.php?from=<?= e($currentFrom) ?>&to=<?= e($currentTo) ?>&department=<?= e($department) ?>&group=<?= e($groupMode) ?>" class="btn btn-secondary"><?= e(t('attendance_sheet_current')) ?></a>
        <a href="attendance_sheet.php?from=<?= e($previousFrom) ?>&to=<?= e($previousTo) ?>&department=<?= e($department) ?>&group=<?= e($groupMode) ?>" class="btn btn-secondary"><?= e(t('attendance_sheet_previous')) ?></a>
        <?php if ($periodIsCurrent): ?>
        <span class="attendance-sheet-meta"><?= e(t('attendance_sheet_current_hint')) ?></span>
        <?php endif; ?>
        <span class="attendance-sheet-meta"><?= e(t('attendance_sheet_max_days_hint')) ?></span>
        <span class="attendance-sheet-meta"><?= e(t('attendance_sheet_paid_leave_hint')) ?></span>
    </div>
</form>

<?php if ($showCurrentPeriodHint): ?>
<div class="flash no-print">
    🗓️ The latest saved attendance is in the current period (<?= e(format_date($currentFrom)) ?> – <?= e(format_date($currentTo)) ?>).
    <a href="attendance_sheet.php?from=<?= e($currentFrom) ?>&to=<?= e($currentTo) ?>&department=<?= e($department) ?>&group=<?= e($groupMode) ?>">Show the current period</a>
</div>
<?php endif; ?>

<?php if (!$groups): ?>
<div class="panel attendance-form-empty"><?= e(t('attendance_sheet_empty')) ?></div>
<?php else: ?>
    <?php $sheetTotal = count($groups); $sheetIndex = 0; ?>
    <?php foreach ($groups as $departmentName => $rows): $sheetIndex++; ?>
    <div class="attendance-form-sheet<?= $sheetIndex === $sheetTotal ? ' attendance-form-sheet-last' : '' ?>">
        <div class="attendance-form-company">
            <div><?= e($companyName) ?></div>
            <?php if ($companyAlt !== '' && $companyAlt !== $companyName): ?>
            <div class="company-alt"><?= e($companyAlt) ?></div>
            <?php endif; ?>
        </div>
        <div class="attendance-form-title">የሠራተኞች ቀሪ መለኪያ ቅጽ</div>

        <div class="attendance-form-meta">
            <div><strong>የስራ ክፍል</strong>&nbsp; <?= e($departmentName === '__all__' ? ($department !== '' ? $department : 'ሁሉም ክፍሎች') : $departmentName) ?></div>
            <div dir="ltr"><?= e($dateLabel) ?></div>
        </div>

        <table class="attendance-form-table<?= $showDepartmentColumn ? ' with-dept' : '' ?>">
            <thead>
                <tr>
                    <th rowspan="2" class="col-no">ተ.ቁ.</th>
                    <th rowspan="2" class="col-name">ስም</th>
                    <?php if ($showDepartmentColumn): ?>
                    <th rowspan="2" class="col-dept">የስራ ክፍል</th>
                    <?php endif; ?>
                    <th rowspan="2" class="col-days">በስራ ላይ<br>የተገኙበት<br>ቀን</th>
                    <th rowspan="2" class="col-days">ያለ ማስረጃ<br>የቀሩበት<br>ቀን</th>
                    <th rowspan="2" class="col-leave">በአርፋጅ<br>የተመዘገቡበት<br>ሰዓት</th>
                    <th colspan="2">ከስራ የቀሩበት</th>
                </tr>
                <tr>
                    <th class="col-absent-day">ቀን</th>
                    <th class="col-reason">ምክንያት</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $index => $row): ?>
                <?php
                // The "በአርፋጅ የተመዘገቡበት ሰዓት" column shows the total time the
                // employee clocked in late for the period as hours:minutes.
                // That time is already deducted from the days present
                // (8 working hours = 1 day), see attendance_sheet_rows().
                $lateCell = $formatLateTime($row['late_minutes'] ?? 0);
                ?>
                <tr>
                    <td class="cell-num"><?= number_format($index + 1) ?></td>
                    <td class="cell-name"><?= e($row['name']) ?></td>
                    <?php if ($showDepartmentColumn): ?>
                    <td class="cell-dept"><?= e($row['department'] ?? '') ?></td>
                    <?php endif; ?>
                    <td class="cell-num"><?= e($formatNumber($row['present_days'])) ?></td>
                    <td class="cell-num"><?= $row['absent_days'] > 0 ? e($formatNumber($row['absent_days'])) : '' ?></td>
                    <td class="cell-num"><?= e($lateCell) ?></td>
                    <td class="cell-num"><?= $row['leave_days'] > 0 ? e($formatNumber($row['leave_days'])) : '' ?></td>
                    <td class="cell-reason"><?= e($row['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="attendance-form-note">
            <div class="note-line">ማሳሰቢያ፡- ከስራ ገበታቸው ላይ ለሚቀሩ ሠራተኞች የሚከተሉትን ምህጻረ ቃል ይደረግ</div>
            <div class="note-line">ዓመት ፈቃድ(Annual Leave)...... ልዩ ፈቃድ(Casual Leave)...... ሕመም ፈቃድ(Sick Leave).....</div>
            <div class="note-line">በአርፋጅ የተመዘገቡበት ሰዓት ከበስራ ላይ ከተገኙበት ቀን ይቀነሳል (8 ሰዓት = 1 ቀን)።</div>
            <div class="attendance-form-signatures">
                <span>የአሰፈራሚው ስምና ፊርማ ........................................</span>
                <span>የአፀደቀው ኃላፊ ስምና ፊርማ ........................................</span>
            </div>
        </div>
        <div class="attendance-form-generated">
            <?= e(t('attendance_report_generated')) ?>: <?= e(format_datetime(date('Y-m-d H:i:s'))) ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
