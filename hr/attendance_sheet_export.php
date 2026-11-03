<?php
/**
 * Excel export of the printable employee attendance sheet.
 *
 * Mirrors hr/attendance_sheet.php: the same Ethiopian payroll period, the same
 * department grouping and the same company heading, columns, legend and
 * signature lines. The file is an HTML table served as .xls, which Excel and
 * LibreOffice open as a spreadsheet.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

[$previousFrom, $previousTo] = ethiopian_payroll_period(-1);

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
$generatedAt = format_datetime(date('Y-m-d H:i:s'));

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

$filenameBase = 'attendance_sheet_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filenameBase . '.xls"');
header('Cache-Control: max-age=0, must-revalidate');
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; font-family: "Nyala", "Abyssinica SIL", "Noto Sans Ethiopic", Arial, sans-serif; font-size: 11px; }
        th, td { border: 1px solid #111827; padding: 4px 6px; vertical-align: middle; }
        th { background: #e2e8f0; font-weight: bold; text-align: center; }
        .company { border: 0; text-align: center; font-size: 15px; font-weight: bold; }
        .company-alt { border: 0; text-align: center; font-size: 12px; font-weight: bold; }
        .title { border: 0; text-align: center; font-size: 14px; font-weight: bold; }
        .meta { border: 1px solid #111827; font-weight: bold; }
        .meta-label { border: 0; }
        .num { text-align: center; mso-number-format: "0.##"; }
        .text { mso-number-format: "\@"; }
        .note { border: 0; font-size: 10px; }
        .signature { border: 0; font-size: 10px; padding-top: 14px; }
        .generated { border: 0; color: #94a3b8; font-size: 9px; text-align: center; }
        .gap { border: 0; height: 12px; }
    </style>
</head>
<body>
<?php if (!$groups): ?>
<table>
    <tr><td class="company"><?= e($companyName) ?></td></tr>
    <?php if ($companyAlt !== '' && $companyAlt !== $companyName): ?>
    <tr><td class="company-alt"><?= e($companyAlt) ?></td></tr>
    <?php endif; ?>
    <tr><td class="title">የሠራተኞች ቀሪ መለኪያ ቅጽ</td></tr>
    <tr><td class="note"><?= e(t('attendance_sheet_empty')) ?></td></tr>
</table>
<?php else: ?>
    <?php $columnCount = $showDepartmentColumn ? 8 : 7; ?>
    <?php $sheetTotal = count($groups); $sheetIndex = 0; ?>
    <?php foreach ($groups as $departmentName => $rows): $sheetIndex++; ?>
    <table>
        <tr><td colspan="<?= $columnCount ?>" class="company"><?= e($companyName) ?></td></tr>
        <?php if ($companyAlt !== '' && $companyAlt !== $companyName): ?>
        <tr><td colspan="<?= $columnCount ?>" class="company-alt"><?= e($companyAlt) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="<?= $columnCount ?>" class="title">የሠራተኞች ቀሪ መለኪያ ቅጽ</td></tr>
        <tr>
            <td colspan="<?= $columnCount - 2 ?>" class="meta"><strong>የስራ ክፍል</strong>&nbsp; <?= e($departmentName === '__all__' ? ($department !== '' ? $department : 'ሁሉም ክፍሎች') : $departmentName) ?></td>
            <td colspan="2" class="meta" dir="ltr"><?= e($dateLabel) ?></td>
        </tr>
        <tr>
            <th rowspan="2">ተ.ቁ.</th>
            <th rowspan="2">ስም</th>
            <?php if ($showDepartmentColumn): ?>
            <th rowspan="2">የስራ ክፍል</th>
            <?php endif; ?>
            <th rowspan="2">በስራ ላይ የተገኙበት ቀን</th>
            <th rowspan="2">ያለ ማስረጃ የቀሩበት ቀን</th>
            <th rowspan="2">በአርፋጅ የተመዘገቡበት ሰዓት</th>
            <th colspan="2">ከስራ የቀሩበት</th>
        </tr>
        <tr>
            <th>ቀን</th>
            <th>ምክንያት</th>
        </tr>
        <?php foreach ($rows as $index => $row): ?>
        <?php $lateCell = $formatLateTime($row['late_minutes'] ?? 0); ?>
        <tr>
            <td class="num" x:num="<?= $index + 1 ?>"><?= number_format($index + 1) ?></td>
            <td class="text" x:str><?= e($row['name']) ?></td>
            <?php if ($showDepartmentColumn): ?>
            <td class="text" x:str><?= e($row['department'] ?? '') ?></td>
            <?php endif; ?>
            <td class="num" x:num="<?= e($formatNumber($row['present_days'])) ?>"><?= e($formatNumber($row['present_days'])) ?></td>
            <?php if ($row['absent_days'] > 0): ?>
            <td class="num" x:num="<?= e($formatNumber($row['absent_days'])) ?>"><?= e($formatNumber($row['absent_days'])) ?></td>
            <?php else: ?>
            <td></td>
            <?php endif; ?>
            <?php if ($lateCell !== ''): ?>
            <td class="text" x:str><?= e($lateCell) ?></td>
            <?php else: ?>
            <td></td>
            <?php endif; ?>
            <?php if ($row['leave_days'] > 0): ?>
            <td class="num" x:num="<?= e($formatNumber($row['leave_days'])) ?>"><?= e($formatNumber($row['leave_days'])) ?></td>
            <?php else: ?>
            <td></td>
            <?php endif; ?>
            <td class="text" x:str><?= e($row['reason']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="<?= $columnCount ?>" class="note">ማሳሰቢያ፡- ከስራ ገበታቸው ላይ ለሚቀሩ ሠራተኞች የሚከተሉትን ምህጻረ ቃል ይደረግ</td></tr>
        <tr><td colspan="<?= $columnCount ?>" class="note">ዓመት ፈቃድ(Annual Leave)...... ልዩ ፈቃድ(Casual Leave)...... ሕመም ፈቃድ(Sick Leave).....</td></tr>
        <tr><td colspan="<?= $columnCount ?>" class="note">በአርፋጅ የተመዘገቡበት ሰዓት ከበስራ ላይ ከተገኙበት ቀን ይቀነሳል (8 ሰዓት = 1 ቀን)።</td></tr>
        <tr>
            <td colspan="<?= (int) ceil($columnCount / 2) ?>" class="signature">የአሰፈራሚው ስምና ፊርማ ........................................</td>
            <td colspan="<?= (int) floor($columnCount / 2) ?>" class="signature">የአፀደቀው ኃላፊ ስምና ፊርማ ........................................</td>
        </tr>
        <tr><td colspan="<?= $columnCount ?>" class="generated"><?= e(t('attendance_report_generated')) ?>: <?= e($generatedAt) ?></td></tr>
        <?php if ($sheetIndex < $sheetTotal): ?>
        <tr><td colspan="<?= $columnCount ?>" class="gap"></td></tr>
        <?php endif; ?>
    </table>
    <?php if ($sheetIndex < $sheetTotal): ?>
    <table><tr><td class="gap"></td></tr></table>
    <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
