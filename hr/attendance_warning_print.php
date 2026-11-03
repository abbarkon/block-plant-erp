<?php
/**
 * Printable written warning for excessive absence.
 *
 * The letter is generated from recorded full-day absences on working days for
 * one employee and a selected period. It is available to HR editors, approvers
 * and administrators so a warning cannot be printed by a read-only HR user.
 * Revoking the letter additionally requires the Approve permission.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
// HR editors and approvers may open a warning letter. Revoking it requires the
// Approve permission (checked below and again in attendance_warning_revoke.php).
if (!has_permission('hr', 'approve')) {
    require_permission('hr', 'edit');
}

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$wt = function ($key) {
    return attendance_warning_text($key);
};
$canRevokeWarnings = has_permission('hr', 'approve');
$isAmharic = app_lang() === 'am';
$letterFont = $isAmharic
    ? "'Nyala', 'Ebrima', 'Abyssinica SIL', 'Noto Sans Ethiopic', serif"
    : '"Times New Roman", Times, serif';
$uiFont = $isAmharic
    ? "'Nyala', 'Ebrima', 'Abyssinica SIL', 'Noto Sans Ethiopic', Arial, sans-serif"
    : 'Arial, sans-serif';

$employeeId = (int) ($_GET['employee_id'] ?? 0);
$today = date('Y-m-d');
[$from, $to] = attendance_report_bounds($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? $today);
if ($to > $today) {
    $to = $today;
}
if ($from > $to) {
    $from = date('Y-m-01', strtotime($to));
}

$department = trim((string) ($_GET['department'] ?? ''));
$backParams = ['from' => $from, 'to' => $to];
if ($department !== '') {
    $backParams['department'] = $department;
}
$backUrl = 'attendance_warnings.php?' . http_build_query($backParams);

$languageParams = ['employee_id' => $employeeId, 'from' => $from, 'to' => $to];
if ($department !== '') {
    $languageParams['department'] = $department;
}
$languageBase = 'attendance_warning_print.php?' . http_build_query($languageParams);

if ($employeeId <= 0) {
    header('Location: ' . $backUrl);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, name, employee_code, department, designation, date_of_joining, phone, email
     FROM employees
     WHERE id = ? AND status = 1"
);
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
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
$warningThreshold = 3; // more than two recorded working-day absences
$canGenerate = $summary['total'] >= $warningThreshold;

ensure_attendance_warning_schema();
$revocation = attendance_warning_effective_revocation($employeeId, $from, $to, $summary['dates']);
if ($revocation) {
    render_attendance_warning_revoked(
        $revocation,
        $employee,
        $from,
        $to,
        $backUrl,
        $wt('back_to_warnings_label')
    );
    exit;
}

$company = company_settings();
$companyName = trim((string) company_display_name());
$tagline = trim((string) company_tagline());
$contactParts = array_filter([
    trim((string) ($company['address'] ?? '')),
    trim((string) ($company['phone'] ?? '')),
    trim((string) ($company['email'] ?? '')),
]);
$contactLine = implode('  ·  ', $contactParts);

$currentUser = current_user();
$issuedBy = trim((string) ($currentUser['name'] ?? $wt('footer_hr')));
$employeeCodeReference = preg_replace('/[^A-Za-z0-9-]/', '', (string) $employee['employee_code']);
if ($employeeCodeReference === '') {
    $employeeCodeReference = 'EMP' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT);
}
$reference = 'WRN-' . $employeeCodeReference . '-' . date('Ymd', strtotime($from)) . '-' . date('Ymd', strtotime($to));

// Notify HR managers/admins and the employee's own account the first time this
// letter is generated. attendance_warning_issue() deduplicates by employee and
// absence dates, so re-opening or re-printing the same letter notifies nobody.
if ($canGenerate) {
    attendance_warning_issue(
        $employee,
        $from,
        $to,
        $summary,
        $reference,
        (int) ($_SESSION['user_id'] ?? 0),
        $issuedBy
    );
}

$issueDate = format_app_date(date('Y-m-d'));
$autoPrint = isset($_GET['print']);

$fromLabel = format_app_date($from);
$toLabel = format_app_date($to);
$periodLabel = $fromLabel . ' – ' . $toLabel;
$totalLabel = number_format($summary['total']);
$consecutiveLabel = number_format($summary['longest_consecutive']);
$joiningDateLabel = '';
if (!empty($employee['date_of_joining'])) {
    $joiningDateLabel = format_app_date($employee['date_of_joining']);
}
?>
<!DOCTYPE html>
<html lang="<?= $isAmharic ? 'am' : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($wt('warning_letter')) ?> - <?= e($employee['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: <?= $letterFont ?>;
            font-size: <?= $isAmharic ? '9.8pt' : '10.5pt' ?>;
            line-height: <?= $isAmharic ? '1.42' : '1.38' ?>;
            color: #111;
            background: #eef2f7;
            padding: 24px;
        }
        .toolbar {
            max-width: 210mm;
            margin: 0 auto 14px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            font-family: <?= $uiFont ?>;
        }
        .toolbar-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            padding: 9px 18px;
            border: 0;
            border-radius: 6px;
            background: #0f766e;
            color: #fff;
            font: inherit;
            font-size: 10pt;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-secondary { background: #475569; }
        .btn-danger { background: #b91c1c; }
        .btn:hover { opacity: .9; }
        .paper {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm;
            box-shadow: 0 0 12px rgba(15, 23, 42, .15);
        }
        .company-head {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            border-bottom: 2.5px solid #111;
            padding-bottom: 9px;
            margin-bottom: 12px;
        }
        .company-head h1 { font-size: 17pt; letter-spacing: .4px; line-height: 1.12; }
        .company-head .tagline { font-size: 9.5pt; color: #475569; margin-top: 2px; }
        .company-head .contact { font-size: 8.5pt; color: #64748b; margin-top: 4px; }
        .doc-meta { text-align: right; font-size: 8.8pt; color: #334155; white-space: nowrap; }
        .doc-meta strong { display: block; color: #111; }
        .doc-meta div { margin-bottom: 3px; }
        .doc-title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: <?= $isAmharic ? '.2px' : '1px' ?>;
            margin: 10px 0 1px;
        }
        .doc-subtitle {
            text-align: center;
            font-size: 9.5pt;
            color: #475569;
            margin-bottom: 12px;
        }
        .employee-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 22px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 8px 11px;
            margin-bottom: 12px;
            font-size: 9.5pt;
        }
        .employee-grid div { display: flex; gap: 6px; }
        .employee-grid .label { font-weight: bold; min-width: 100px; color: #334155; }
        .employee-grid .value { flex: 1; }
        .subject {
            font-weight: bold;
            font-size: 10.5pt;
            margin: 0 0 9px;
            text-decoration: underline;
        }
        p { margin-bottom: 8px; text-align: justify; }
        .absence-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 10px;
            font-size: 9.5pt;
        }
        .absence-table th,
        .absence-table td {
            border: 1px solid #94a3b8;
            padding: 4px 7px;
            text-align: left;
            vertical-align: top;
        }
        .absence-table th { background: #f1f5f9; color: #334155; }
        .absence-table th.num,
        .absence-table td.num { width: 38px; text-align: right; }
        .absence-table th.date,
        .absence-table td.date { white-space: nowrap; }
        .summary-line {
            background: #f8fafc;
            border-left: 4px solid #b45309;
            padding: 7px 10px;
            margin: 10px 0 11px;
            font-size: 10pt;
        }
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px 30px;
            margin-top: 16px;
        }
        .signature-box { font-size: 9.5pt; }
        .signature-space { height: 40px; }
        .signature-line { border-top: 1px solid #111; padding-top: 4px; }
        .signature-meta { margin-top: 4px; color: #475569; }
        .footer {
            margin-top: 12px;
            padding-top: 6px;
            border-top: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-family: <?= $uiFont ?>;
            font-size: 8pt;
            color: #64748b;
        }
        .notice {
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #78350f;
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-family: <?= $uiFont ?>;
            font-size: 10pt;
        }
        .notice h2 { margin: 0 0 8px; color: #92400e; }
        @media print {
            @page { size: A4; margin: 14mm; }
            body { background: #fff; padding: 0; }
            .paper { box-shadow: none; margin: 0; width: auto; min-height: 0; padding: 0; }
            .no-print { display: none !important; }
            .absence-table thead { display: table-header-group; }
            .absence-table tr, .signature-grid, .summary-line, .footer { page-break-inside: avoid; }
            .company-head { page-break-after: avoid; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <div class="toolbar-group">
        <a href="<?= e($backUrl) ?>" class="btn btn-secondary">← <?= e($wt('back_to_warnings')) ?></a>
        <a href="<?= e($languageBase . '&lang=en') ?>" class="btn btn-secondary" title="English" <?= !$isAmharic ? 'style="font-weight:700;"' : '' ?>>EN</a>
        <a href="<?= e($languageBase . '&lang=am') ?>" class="btn btn-secondary" title="አማርኛ" <?= $isAmharic ? 'style="font-weight:700;"' : '' ?>>አማ</a>
    </div>
    <?php if ($canGenerate): ?>
    <div class="toolbar-group">
        <?php
        $revokeUrl = 'attendance_warning_revoke.php?' . http_build_query([
            'employee_id' => $employeeId,
            'from' => $from,
            'to' => $to,
            'department' => $department,
        ]);
        ?>
        <?php if ($canRevokeWarnings): ?>
        <a href="<?= e($revokeUrl) ?>" class="btn btn-danger">🚫 <?= e($wt('revoke_button')) ?></a>
        <?php endif; ?>
        <button type="button" class="btn" onclick="window.print()">🖨 <?= e($wt('print_warning_letter')) ?></button>
    </div>
    <?php endif; ?>
</div>

<div class="paper">
    <div class="company-head">
        <div>
            <h1><?= e($companyName) ?></h1>
            <?php if ($tagline !== ''): ?>
            <div class="tagline"><?= e($tagline) ?></div>
            <?php endif; ?>
            <?php if ($contactLine !== ''): ?>
            <div class="contact"><?= e($contactLine) ?></div>
            <?php endif; ?>
        </div>
        <div class="doc-meta">
            <div><strong><?= e($wt('reference')) ?></strong><?= e($reference) ?></div>
            <div><strong><?= e($wt('date')) ?></strong><?= e($issueDate) ?></div>
        </div>
    </div>

    <?php if (!$canGenerate): ?>
    <div class="notice">
        <h2><?= e($wt('not_available_title')) ?></h2>
        <p><?= e(sprintf($wt('not_available_text'), $totalLabel)) ?></p>
        <p style="margin-bottom:0;"><?= e(sprintf($wt('not_available_period'), $periodLabel)) ?></p>
    </div>
    <?php else: ?>
    <div class="doc-title"><?= e($wt('doc_title')) ?></div>
    <div class="doc-subtitle"><?= e($wt('doc_subtitle')) ?></div>

    <div class="employee-grid">
        <div><span class="label"><?= e($wt('employee')) ?></span><span class="value"><?= e($employee['name']) ?></span></div>
        <div><span class="label"><?= e($wt('employee_code')) ?></span><span class="value"><?= e($employee['employee_code']) ?></span></div>
        <div><span class="label"><?= e($wt('department')) ?></span><span class="value"><?= e($employee['department'] ?: '—') ?></span></div>
        <div><span class="label"><?= e($wt('designation')) ?></span><span class="value"><?= e($employee['designation'] ?: '—') ?></span></div>
        <?php if ($joiningDateLabel !== ''): ?>
        <div><span class="label"><?= e($wt('joining_date')) ?></span><span class="value"><?= e($joiningDateLabel) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($employee['phone']) || !empty($employee['email'])): ?>
        <div>
            <span class="label"><?= e($wt('contact')) ?></span>
            <span class="value"><?= e(implode(' · ', array_filter([$employee['phone'] ?? '', $employee['email'] ?? '']))) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="subject"><?= e($wt('subject')) ?></div>

    <p><?= e(sprintf($wt('salutation'), $employee['name'])) ?></p>

    <p><?= e(sprintf($wt('body_intro'), $fromLabel, $toLabel, $totalLabel)) ?></p>

    <table class="absence-table">
        <thead>
            <tr>
                <th class="num"><?= e($wt('table_no')) ?></th>
                <th class="date"><?= e($wt('table_date')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $index => $day): ?>
            <tr>
                <td class="num"><?= $index + 1 ?></td>
                <td class="date"><?= e(format_app_date($day['date'], true)) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="summary-line">
        <strong><?= e($wt('total_label')) ?></strong> <?= e(sprintf($wt('total_text'), $totalLabel)) ?>
        <?php if ($summary['longest_consecutive'] >= 2): ?>
        <strong><?= e($wt('longest_label')) ?></strong> <?= e(sprintf($wt('longest_text'), $consecutiveLabel)) ?>
        <?php endif; ?>
    </div>

    <p><?= e($wt('policy')) ?></p>
    <p><?= e($wt('improve')) ?></p>
    <p><?= e($wt('explanation')) ?></p>
    <p style="margin-bottom:4px;"><strong><?= e($wt('acknowledgement')) ?></strong></p>

    <div class="signature-grid">
        <div class="signature-box">
            <div class="signature-space"></div>
            <div class="signature-line"><strong><?= e($issuedBy) ?></strong></div>
            <div class="signature-meta"><?= e($wt('issued_by')) ?></div>
            <div class="signature-meta"><?= e($wt('date')) ?>: <?= e($issueDate) ?></div>
        </div>
        <div class="signature-box">
            <div class="signature-space"></div>
            <div class="signature-line"><strong><?= e($employee['name']) ?></strong></div>
            <div class="signature-meta"><?= e($wt('employee_signature')) ?></div>
            <div class="signature-meta"><?= e($wt('date_placeholder')) ?></div>
        </div>
    </div>

    <div class="footer">
        <span><?= e($companyName) ?> · <?= e($wt('footer_hr')) ?></span>
        <span><?= e($wt('reference')) ?>: <?= e($reference) ?> · <?= e($wt('generated')) ?> <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($autoPrint && $canGenerate): ?>
<script>
    window.addEventListener('load', function () {
        window.setTimeout(function () { window.print(); }, 250);
    });
</script>
<?php endif; ?>
</body>
</html>
