<?php
/**
 * Printable annual-leave request form.
 *
 * Renders a signed employee copy for a leave request. Annual-leave requests
 * also show the year-by-year balance (entitlement, carry-forward, usage and the
 * balance after approval) so the employee can confirm it before signing.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$leaveId = (int) ($_GET['id'] ?? 0);
if ($leaveId <= 0) {
    header('Location: leaves.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT lr.*, e.name AS employee_name, e.employee_code, e.designation, e.department,
            e.date_of_joining, e.phone, e.email,
            u.name AS approver_name
     FROM leave_requests lr
     JOIN employees e ON e.id = lr.employee_id
     LEFT JOIN users u ON u.id = lr.approved_by
     WHERE lr.id = ?"
);
$stmt->execute([$leaveId]);
$leave = $stmt->fetch();
if (!$leave) {
    header('Location: leaves.php');
    exit;
}

$isAnnual = stripos((string) $leave['leave_type'], 'annual') !== false;
$isApproved = $leave['status'] === 'approved';
$created = isset($_GET['created']);
$autoPrint = isset($_GET['print']);
$formCode = $isAnnual ? 'ALF' : 'LVF';
$formTitleDisplay = $isAnnual
    ? t('leave_print_title_annual')
    : sprintf(t('leave_print_title_for'), leave_type_label($leave['leave_type']));
$formTitle = app_lang() === 'am' ? $formTitleDisplay : mb_strtoupper($formTitleDisplay);
$formSubtitle = $isAnnual
    ? t('leave_print_subtitle_annual')
    : t('leave_print_subtitle');
$langBase = 'leave_print.php?id=' . $leaveId . ($created ? '&created=1' : '') . ($autoPrint ? '&print=1' : '');

$startYear = (int) date('Y', strtotime($leave['from_date']));
$endYear = (int) date('Y', strtotime($leave['to_date']));
if ($endYear < $startYear) {
    $endYear = $startYear;
}
if ($endYear - $startYear > 5) {
    $endYear = $startYear + 5;
}

$yearRows = [];
if ($isAnnual) {
    $simulatedCarry = 0.0;
    for ($year = $startYear; $year <= $endYear; $year++) {
        $balance = annual_leave_balance((int) $leave['employee_id'], $year);

        // Days of this request that fall inside the year (requests can cross
        // a year boundary).
        $rangeStart = max(strtotime($leave['from_date']), strtotime($year . '-01-01'));
        $rangeEnd = min(strtotime($leave['to_date']), strtotime($year . '-12-31'));
        $requestedDays = ($rangeStart !== false && $rangeStart <= $rangeEnd)
            ? (int) (($rangeEnd - $rangeStart) / 86400) + 1
            : 0;

        // Pending requests are not yet counted in "used", so simulate the
        // carry-forward chain year by year as if this request were approved.
        $displayCarry = (float) $balance['carried_forward_days'];
        if (!$isApproved && $year > $startYear) {
            $displayCarry = $simulatedCarry;
        }

        if ($isApproved) {
            $afterApproval = (float) $balance['available_days'];
        } else {
            $afterApproval = (float) $balance['entitlement_days']
                + $displayCarry
                + (float) $balance['adjustment_days']
                - (float) $balance['used_days']
                - $requestedDays;
        }
        $simulatedCarry = max(0.0, $afterApproval);

        $yearRows[] = [
            'year' => $year,
            'balance' => $balance,
            'display_carry' => $displayCarry,
            'requested_days' => $requestedDays,
            'after_approval' => $afterApproval,
        ];
    }
}

$company = company_settings();
$companyName = company_display_name();
$tagline = company_tagline();
$statusColor = $isApproved ? '#166534' : ($leave['status'] === 'rejected' ? '#991b1b' : '#92400e');
$statusBackground = $isApproved ? '#dcfce7' : ($leave['status'] === 'rejected' ? '#fee2e2' : '#fef3c7');
?>
<!DOCTYPE html>
<html lang="<?= app_lang() === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($formTitleDisplay) ?> - <?= e($leave['employee_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: <?= app_lang() === 'am' ? "'Nyala', 'Ebrima', 'Abyssinica SIL', serif" : "'Times New Roman', Times, serif" ?>;
            font-size: 12pt;
            line-height: 1.45;
            color: #111;
            background: #f1f5f9;
            padding: 24px;
        }
        .paper {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm;
            box-shadow: 0 0 12px rgba(15, 23, 42, .15);
        }
        @media print {
            body { background: #fff; padding: 0; }
            .paper { box-shadow: none; margin: 0; width: auto; min-height: 0; padding: 12mm; }
            .no-print { display: none !important; }
            .banner { display: none !important; }
            .section, .signature-grid, .hr-use { page-break-inside: avoid; }
        }
        .toolbar {
            max-width: 210mm;
            margin: 0 auto 14px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
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
        .btn:hover { opacity: .9; }
        .banner {
            max-width: 210mm;
            margin: 0 auto 14px;
            padding: 12px 16px;
            border-radius: 8px;
            background: #ecfdf5;
            border-left: 5px solid #10b981;
            color: #065f46;
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }
        .form-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 9pt;
            color: #475569;
            margin-bottom: 12px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #111;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header h1 { font-size: 19pt; letter-spacing: .3px; margin-bottom: 3px; }
        .header .tagline { font-size: 10pt; color: #475569; }
        .header .contact { font-size: 9pt; color: #64748b; margin-top: 5px; }
        .form-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: .6px;
            margin: 14px 0 6px;
        }
        .form-subtitle {
            text-align: center;
            font-size: 10pt;
            color: #475569;
            margin-bottom: 16px;
        }
        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 999px;
            font-family: Arial, sans-serif;
            font-size: 9pt;
            font-weight: bold;
            color: <?= $statusColor ?>;
            background: <?= $statusBackground ?>;
        }
        .section { margin-bottom: 16px; }
        .section-title {
            font-weight: bold;
            font-size: 10.5pt;
            letter-spacing: .5px;
            margin-bottom: 8px;
            padding: 4px 9px;
            background: #f1f5f9;
            border-left: 4px solid #0f766e;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px 22px;
        }
        .info-item { display: flex; gap: 6px; }
        .info-label { font-weight: bold; min-width: 118px; }
        .info-value { flex: 1; border-bottom: 1px dotted #64748b; min-height: 20px; }
        .reason-box {
            border: 1px solid #cbd5e1;
            min-height: 60px;
            padding: 9px 11px;
            background: #fcfcfd;
            white-space: pre-wrap;
        }
        table.balance {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }
        table.balance th, table.balance td {
            border: 1px solid #94a3b8;
            padding: 5px 7px;
        }
        table.balance th { background: #f1f5f9; text-align: left; }
        table.balance td.num, table.balance th.num { text-align: right; }
        .note {
            font-size: 9.5pt;
            color: #475569;
            margin-top: 6px;
        }
        .declaration {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 10px 13px;
            font-size: 10pt;
            margin-top: 4px;
        }
        .declaration ol { margin-left: 18px; margin-top: 4px; }
        .declaration li { margin-bottom: 4px; }
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 30px;
            margin-top: 8px;
        }
        .signature-box { font-size: 9.5pt; }
        .signature-space { height: 52px; }
        .signature-line { border-top: 1px solid #111; padding-top: 4px; }
        .signature-meta { margin-top: 6px; color: #475569; }
        .hr-use {
            border: 1.5px dashed #94a3b8;
            padding: 10px 13px;
            margin-top: 18px;
            font-size: 9.5pt;
        }
        .hr-use .line { display: inline-block; border-bottom: 1px solid #64748b; min-width: 150px; }
        .footer {
            margin-top: 18px;
            padding-top: 8px;
            border-top: 1px solid #cbd5e1;
            text-align: center;
            font-size: 8.5pt;
            color: #64748b;
        }
        .warning {
            margin-top: 8px;
            padding: 8px 11px;
            background: #fef2f2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
            font-size: 9.5pt;
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="leaves.php" class="btn btn-secondary">← <?= e(t('leave_print_back')) ?></a>
            <a href="<?= e($langBase . '&lang=en') ?>" class="btn btn-secondary" title="English">EN</a>
            <a href="<?= e($langBase . '&lang=am') ?>" class="btn btn-secondary" title="አማርኛ">አማ</a>
        </div>
        <button type="button" class="btn" onclick="window.print()">🖨 <?= e(t('leave_print_save_pdf')) ?></button>
    </div>

    <?php if ($created): ?>
    <div class="banner no-print">
        <?= e(t('leave_print_submitted_banner')) ?>
    </div>
    <?php endif; ?>

    <div class="paper">
        <div class="form-meta">
            <span><?= e(t('leave_print_form_no')) ?>: <strong><?= e($formCode) ?>-<?= (int) $startYear ?>-<?= str_pad((string) $leave['id'], 5, '0', STR_PAD_LEFT) ?></strong></span>
            <span><?= e(t('leave_print_printed')) ?>: <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></span>
        </div>

        <div class="header">
            <h1><?= e($companyName) ?></h1>
            <?php if ($tagline): ?><div class="tagline"><?= e($tagline) ?></div><?php endif; ?>
            <div class="contact">
                <?= e($company['address'] ?? '') ?>
                <?php if (!empty($company['phone'])): ?> &nbsp;|&nbsp; Tel: <?= e($company['phone']) ?><?php endif; ?>
                <?php if (!empty($company['email'])): ?> &nbsp;|&nbsp; <?= e($company['email']) ?><?php endif; ?>
            </div>
        </div>

        <div class="form-title"><?= e($formTitle) ?></div>
        <div class="form-subtitle"><?= e($formSubtitle) ?></div>

        <div class="section">
            <div class="section-title"><?= e(t('leave_print_employee_info')) ?></div>
            <div class="info-grid">
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_full_name')) ?>:</span><span class="info-value"><?= e($leave['employee_name']) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('employee_code')) ?>:</span><span class="info-value"><?= e($leave['employee_code']) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('department')) ?>:</span><span class="info-value"><?= e($leave['department'] ?: t('leave_print_not_applicable')) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('designation')) ?>:</span><span class="info-value"><?= e($leave['designation'] ?: t('leave_print_not_applicable')) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_joining_date')) ?>:</span><span class="info-value"><?= $leave['date_of_joining'] ? e(format_date($leave['date_of_joining'])) : e(t('leave_print_not_applicable')) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_phone_email')) ?>:</span><span class="info-value"><?= e(trim(($leave['phone'] ?: '') . ($leave['email'] ? ' / ' . $leave['email'] : ''), ' /')) ?: e(t('leave_print_not_applicable')) ?></span></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title"><?= e(t('leave_print_leave_details')) ?></div>
            <div class="info-grid">
                <div class="info-item"><span class="info-label"><?= e(t('leave_leave_type')) ?>:</span><span class="info-value"><?= e(leave_type_label($leave['leave_type'])) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_total_days')) ?>:</span><span class="info-value"><?= (int) $leave['total_days'] ?> <?= e(t('days')) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_from_date')) ?>:</span><span class="info-value"><?= e(format_date($leave['from_date'])) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_to_date')) ?>:</span><span class="info-value"><?= e(format_date($leave['to_date'])) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_requested_on')) ?>:</span><span class="info-value"><?= e(format_date($leave['created_at'])) ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('status')) ?>:</span><span class="info-value"><span class="status-pill"><?= e(leave_status_label($leave['status'])) ?></span></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_approved_by')) ?>:</span><span class="info-value"><?= e($leave['approver_name'] ?: '________________________') ?></span></div>
                <div class="info-item"><span class="info-label"><?= e(t('leave_print_approval_date')) ?>:</span><span class="info-value"><?= $leave['approved_date'] ? e(format_date($leave['approved_date'])) : '________________' ?></span></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title"><?= e(t('leave_print_reason')) ?></div>
            <div class="reason-box"><?= e($leave['reason'] ?: '—') ?></div>
        </div>

        <?php if ($isAnnual): ?>
        <div class="section">
            <div class="section-title"><?= e(t('leave_print_balance')) ?></div>
            <table class="balance">
                <thead>
                    <tr>
                        <th><?= e(t('year')) ?></th>
                        <th class="num"><?= e(t('leave_entitlement')) ?></th>
                        <th class="num"><?= e(t('leave_carried_forward')) ?></th>
                        <th class="num"><?= e(t('leave_adjustment')) ?></th>
                        <th class="num"><?= e(t('leave_print_used_approved')) ?></th>
                        <th class="num"><?= e(t('leave_print_this_request')) ?></th>
                        <th class="num"><?= e($isApproved ? t('leave_print_remaining_balance') : t('leave_print_balance_if_approved')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yearRows as $row):
                        $balance = $row['balance'];
                    ?>
                    <tr>
                        <td><?= (int) $row['year'] ?></td>
                        <td class="num"><?= number_format((float) $balance['entitlement_days'], 1) ?></td>
                        <td class="num"><?= number_format((float) $row['display_carry'], 1) ?></td>
                        <td class="num"><?= number_format((float) $balance['adjustment_days'], 1) ?></td>
                        <td class="num"><?= number_format((float) $balance['used_days'], 1) ?></td>
                        <td class="num"><?= number_format((int) $row['requested_days'], 1) ?></td>
                        <td class="num" style="font-weight:bold; color:<?= $row['after_approval'] < 0 ? '#b91c1c' : '#166534' ?>;">
                            <?= number_format((float) $row['after_approval'], 1) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="note">
                <?php if ($isApproved): ?>
                <?= e(t('leave_print_approved_note')) ?>
                <?php else: ?>
                <?= sprintf(t('leave_print_pending_note'), '<strong>' . e(leave_status_label($leave['status'])) . '</strong>') ?>
                <?php endif; ?>
                <?= e(t('leave_print_carry_note')) ?>
            </div>
            <?php
            $negativeAfter = false;
            foreach ($yearRows as $row) {
                if ((float) $row['after_approval'] < 0) {
                    $negativeAfter = true;
                }
            }
            if ($negativeAfter): ?>
            <div class="warning">
                <strong><?= e(t('leave_print_balance_warning')) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-title"><?= e(t('leave_print_declaration')) ?></div>
            <div class="declaration">
                <?= e(t('leave_print_declaration_intro')) ?>
                <ol>
                    <li><?= e(t('leave_print_decl_item_1')) ?></li>
                    <li><?= e(t('leave_print_decl_item_2')) ?></li>
                    <li><?= e(t('leave_print_decl_item_3')) ?></li>
                    <li><?= e(t('leave_print_decl_item_4')) ?></li>
                </ol>
            </div>
        </div>

        <div class="section">
            <div class="section-title"><?= e(t('leave_print_signatures')) ?></div>
            <div class="signature-grid">
                <div class="signature-box">
                    <div class="signature-space"></div>
                    <div class="signature-line"><?= e(t('leave_print_employee_signature')) ?></div>
                    <div class="signature-meta"><?= e(t('leave_print_name')) ?>: <?= e($leave['employee_name']) ?><br><?= e(t('leave_print_date')) ?>: ____________________</div>
                </div>
                <div class="signature-box">
                    <div class="signature-space"></div>
                    <div class="signature-line"><?= e(t('leave_print_supervisor_signature')) ?></div>
                    <div class="signature-meta"><?= e(t('leave_print_name')) ?>: ____________________<br><?= e(t('leave_print_date')) ?>: ____________________</div>
                </div>
                <div class="signature-box">
                    <div class="signature-space"></div>
                    <div class="signature-line"><?= e(t('leave_print_hr_signature')) ?></div>
                    <div class="signature-meta"><?= e(t('leave_print_name')) ?>: <?= e($leave['approver_name'] ?: '____________________') ?><br><?= e(t('leave_print_date')) ?>: <?= $leave['approved_date'] ? e(format_date($leave['approved_date'])) : '____________________' ?></div>
                </div>
                <div class="signature-box">
                    <div class="signature-space"></div>
                    <div class="signature-line"><?= e(t('leave_print_handover_signature')) ?></div>
                    <div class="signature-meta"><?= e(t('leave_print_name')) ?>: ____________________<br><?= e(t('leave_print_date')) ?>: ____________________</div>
                </div>
            </div>
        </div>

        <div class="hr-use">
            <strong><?= e(t('leave_print_hr_use')) ?></strong><br><br>
            <?= e(t('leave_print_approved_days')) ?>: <span class="line">&nbsp;</span> &nbsp;&nbsp; <?= e(t('leave_print_rejected')) ?>: <span class="line">&nbsp;</span><br><br>
            <?= e(t('leave_print_remarks')) ?>: <span class="line" style="min-width:430px;">&nbsp;</span><br><br>
            <?= e(t('leave_print_recorded')) ?>: ☐ <?= e(t('leave_print_yes')) ?> &nbsp;&nbsp; ☐ <?= e(t('leave_print_no')) ?> &nbsp;&nbsp; <?= e(t('leave_print_initials')) ?>: <span class="line" style="min-width:90px;">&nbsp;</span>
        </div>

        <div class="footer">
            <?= e($companyName) ?> · <?= e($formTitleDisplay) ?> · <?= e(t('leave_print_form_no')) ?> <?= e($formCode) ?>-<?= (int) $startYear ?>-<?= str_pad((string) $leave['id'], 5, '0', STR_PAD_LEFT) ?><br>
            <?= e(t('leave_print_footer_note')) ?>
        </div>
    </div>

    <?php if ($autoPrint): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
    <?php endif; ?>
</body>
</html>
