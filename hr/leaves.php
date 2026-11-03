<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

ensure_leave_balances_schema();

/**
 * Save the editable annual-leave fields (entitlement + manual adjustment) for
 * every active employee in one submission.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_leave_balances'])) {
    if (!has_permission('hr', 'edit')) {
        http_response_code(403);
        exit(t('leave_no_permission_balances'));
    }
    $balanceYear = (int) ($_POST['balance_year'] ?? date('Y'));
    if ($balanceYear < 2000 || $balanceYear > (int) date('Y') + 1) {
        set_flash('error', t('leave_select_valid_year'));
        header('Location: leaves.php');
        exit;
    }

    $entitlements = (array) ($_POST['entitlement'] ?? []);
    $adjustments = (array) ($_POST['adjustment'] ?? []);
    $activeIds = array_map('intval', $pdo->query("SELECT id FROM employees WHERE status = 1")->fetchAll(PDO::FETCH_COLUMN));
    $upsert = $pdo->prepare(
        "INSERT INTO leave_balances (employee_id, year, leave_type, entitlement_days, adjustment_days)
         VALUES (?, ?, 'Annual Leave', ?, ?)
         ON DUPLICATE KEY UPDATE entitlement_days = VALUES(entitlement_days), adjustment_days = VALUES(adjustment_days)"
    );

    $saved = 0;
    foreach ($activeIds as $empId) {
        if (!array_key_exists($empId, $entitlements)) {
            continue;
        }
        $entitlement = round((float) $entitlements[$empId], 1);
        $adjustment = round((float) ($adjustments[$empId] ?? 0), 1);
        if ($entitlement < 0 || $entitlement > 365 || $adjustment < -365 || $adjustment > 365) {
            continue;
        }
        $upsert->execute([$empId, $balanceYear, $entitlement, $adjustment]);
        $saved++;
    }

    log_activity('update', 'hr', null, "Annual leave balances saved for {$balanceYear} ({$saved} employee(s))");
    set_flash($saved > 0 ? 'success' : 'error', $saved > 0
        ? sprintf(t('leave_balances_saved'), $saved, $balanceYear)
        : t('leave_no_valid_balance_rows'));
    header('Location: leaves.php?balance_year=' . $balanceYear);
    exit;
}

/**
 * Seed the same entitlement for active employees that do not have a row for
 * the selected year yet. Existing rows are left untouched.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave_default'])) {
    if (!has_permission('hr', 'edit')) {
        http_response_code(403);
        exit(t('leave_no_permission_balances'));
    }
    $balanceYear = (int) ($_POST['balance_year'] ?? date('Y'));
    $defaultDays = round((float) ($_POST['default_days'] ?? 0), 1);
    if ($balanceYear < 2000 || $balanceYear > (int) date('Y') + 1 || $defaultDays < 0 || $defaultDays > 365) {
        set_flash('error', t('leave_enter_valid_year_default'));
        header('Location: leaves.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO leave_balances (employee_id, year, leave_type, entitlement_days)
         SELECT id, ?, 'Annual Leave', ? FROM employees WHERE status = 1"
    );
    $stmt->execute([$balanceYear, $defaultDays]);
    $added = $stmt->rowCount();

    log_activity('update', 'hr', null, "Annual leave default of {$defaultDays} day(s) applied to {$added} employee(s) for {$balanceYear}");
    set_flash('success', $added > 0
        ? sprintf(t('leave_default_applied'), $added, $defaultDays, $balanceYear)
        : t('leave_all_have_rows'));
    header('Location: leaves.php?balance_year=' . $balanceYear);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('hr', 'approve')) {
        http_response_code(403);
        exit(t('leave_no_permission_review'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = in_array($_POST['action'], ['approved', 'rejected'], true) ? $_POST['action'] : '';
    $requestStmt = $pdo->prepare("SELECT employee_id, leave_type, from_date, to_date, status FROM leave_requests WHERE id = ?");
    $requestStmt->execute([$id]);
    $request = $requestStmt->fetch();
    if (!$request) {
        set_flash('error', t('leave_request_not_found'));
    } elseif ($request['status'] !== 'pending') {
        set_flash('error', t('leave_only_pending'));
    } elseif ($status === '') {
        set_flash('error', t('leave_select_valid_action'));
    } elseif (!employee_is_active($request['employee_id'])) {
        set_flash('error', t('leave_action_inactive'));
    } elseif (
        $status === 'approved'
        && stripos($request['leave_type'], 'annual') !== false
        && ($annualExcess = annual_leave_request_excess($request['employee_id'], $request['from_date'], $request['to_date'], $id)) !== null
    ) {
        set_flash('error', sprintf(
            t('leave_annual_approval_blocked'),
            $annualExcess['year'],
            number_format($annualExcess['requested'], 1),
            number_format(max(0.0, $annualExcess['available']), 1)
        ));
    } else {
        $stmt = $pdo->prepare("UPDATE leave_requests SET status = ?, approved_by = ?, approved_date = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$status, $_SESSION['user_id'], date('Y-m-d'), $id]);
        if ($stmt->rowCount()) {
            $synced = 0;
            $balanceNote = '';
            if ($status === 'approved') {
                // Reflect the approved leave in the attendance register so the
                // payroll run picks it up (paid leave stays paid, unpaid leave
                // is deducted automatically).
                $synced = sync_leave_to_attendance($request['employee_id'], $request['from_date'], $request['to_date'], $request['leave_type'], $id);

                // Annual leave consumes the employee's yearly balance; warn
                // when the approval pushes it below zero.
                if (stripos($request['leave_type'], 'annual') !== false) {
                    $balance = annual_leave_balance($request['employee_id'], (int) date('Y', strtotime($request['from_date'])));
                    if ($balance['available_days'] < 0) {
                        $balanceNote = ' ' . sprintf(t('leave_balance_warning'), number_format($balance['available_days'], 1));
                    }
                }
            }
            log_activity('update', 'hr', $id, 'Leave request ' . $status . ($synced > 0 ? " (attendance synced for {$synced} day(s))" : '') . $balanceNote);
            $reviewMessage = $status === 'approved' ? t('leave_approved_flash') : t('leave_rejected_flash');
            set_flash('success', $reviewMessage . ($synced > 0 ? ' ' . sprintf(t('leave_attendance_synced'), $synced) : '') . $balanceNote);
        } else {
            set_flash('error', t('leave_already_reviewed'));
        }
    }
    header('Location: leaves.php');
    exit;
}

$pageTitle = t('leave_management');
require_once "../partials/header.php";

$stmt = $pdo->query("SELECT lr.*, e.name as employee_name, e.employee_code, e.status AS employee_status FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id ORDER BY lr.created_at DESC");
$leaves = $stmt->fetchAll();
$flash = get_flash();

// Annual leave balances for the selected year (carry-forward is derived).
$currentYear = (int) date('Y');
$balanceYear = (int) ($_GET['balance_year'] ?? $currentYear);
if ($balanceYear < $currentYear - 5 || $balanceYear > $currentYear + 1) {
    $balanceYear = $currentYear;
}
$canEditBalances = has_permission('hr', 'edit');
$balanceEmployees = $pdo->query("SELECT id, name, employee_code, date_of_joining FROM employees WHERE status = 1 ORDER BY name")->fetchAll();
$balances = [];
$balanceTotals = ['entitlement_days' => 0.0, 'carried_forward_days' => 0.0, 'adjustment_days' => 0.0, 'used_days' => 0.0, 'available_days' => 0.0, 'carry_next' => 0.0];
foreach ($balanceEmployees as $balanceEmployee) {
    $balance = annual_leave_balance($balanceEmployee['id'], $balanceYear);
    $balances[(int) $balanceEmployee['id']] = $balance;
    foreach ($balanceTotals as $key => $value) {
        if ($key === 'carry_next') {
            continue;
        }
        $balanceTotals[$key] += (float) $balance[$key];
    }
    $balanceTotals['carry_next'] += max(0.0, (float) $balance['available_days']);
}
?>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>🏖️ <?= e(t('leave_management')) ?></h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="attendance.php" class="btn" style="background:#64748b;">📅 <?= e(t('leave_attendance_register')) ?></a>
        <a href="#annual-leave" class="btn btn-secondary">🌴 <?= e(t('leave_annual_balances')) ?></a>
        <?php if (has_permission('hr', 'create')): ?>
        <a href="leave_form.php" class="btn">+ <?= e(t('leave_new_request')) ?></a>
        <?php endif; ?>
    </div>
</div>

<p style="color:#64748b; margin:-10px 0 18px;">
    <?= e(t('leave_attendance_note')) ?>
</p>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <table>
        <thead>
            <tr>
                <th><?= e(t('leave_employee')) ?></th>
                <th><?= e(t('leave_leave_type')) ?></th>
                <th><?= e(t('leave_from_date')) ?></th>
                <th><?= e(t('leave_to_date')) ?></th>
                <th><?= e(t('days')) ?></th>
                <th><?= e(t('leave_reason')) ?></th>
                <th><?= e(t('status')) ?></th>
                <th><?= e(t('leave_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($leaves as $leave): ?>
            <tr>
                <td><?= htmlspecialchars($leave['employee_name']) ?> (<?= $leave['employee_code'] ?>)</td>
                <td><?= htmlspecialchars(leave_type_label($leave['leave_type'])) ?></td>
                <td><?= e(format_date($leave['from_date'])) ?></td>
                <td><?= e(format_date($leave['to_date'])) ?></td>
                <td><?= $leave['total_days'] ?></td>
                <td><?= htmlspecialchars($leave['reason']) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $leave['status'] === 'approved' ? '#d4edda' : ($leave['status'] === 'rejected' ? '#f8d7da' : '#fff3cd') ?>;">
                        <?= e(leave_status_label($leave['status'])) ?>
                    </span>
                </td>
                <td>
                    <a href="leave_print.php?id=<?= (int) $leave['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#0f766e;">🖨 <?= e(t('leave_print_form')) ?></a>
                    <?php if($leave['status'] === 'pending' && (int) $leave['employee_status'] === 1 && has_permission('hr', 'approve')): ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="id" value="<?= $leave['id'] ?>">
                        <button type="submit" name="action" value="approved" class="btn btn-sm" style="background: #28a745;"><?= e(t('leave_approve')) ?></button>
                        <button type="submit" name="action" value="rejected" class="btn btn-sm" style="background: #dc3545;"><?= e(t('leave_reject')) ?></button>
                    </form>
                    <?php elseif ($leave['status'] === 'pending' && (int) $leave['employee_status'] !== 1): ?>
                    <small><?= e(t('leave_inactive_action')) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Annual leave balances -->
<div id="annual-leave" style="background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1); margin-top:24px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:15px; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0 0 6px;">🌴 <?= e(t('leave_annual_leave_balances')) ?></h2>
            <p style="color:#64748b; margin:0; max-width:720px;">
                <?= e(t('leave_annual_balances_hint')) ?>
            </p>
        </div>
        <form method="get" style="display:flex; align-items:center; gap:8px;">
            <label for="balance_year" style="margin:0;"><?= e(t('year')) ?></label>
            <select id="balance_year" name="balance_year" onchange="this.form.submit()">
                <?php for ($y = $currentYear + 1; $y >= $currentYear - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $balanceYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <?php if ($canEditBalances): ?>
    <form method="post" style="margin-top:18px;">
        <input type="hidden" name="balance_year" value="<?= $balanceYear ?>">
        <table>
            <thead>
                <tr>
                    <th><?= e(t('leave_employee')) ?></th>
                    <th><?= e(t('leave_entitlement')) ?></th>
                    <th><?= e(t('leave_carried_forward')) ?></th>
                    <th><?= e(t('leave_adjustment')) ?></th>
                    <th><?= e(t('leave_used')) ?></th>
                    <th><?= e(t('leave_available')) ?></th>
                    <th><?= e(sprintf(t('leave_carries_to'), $balanceYear + 1)) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balanceEmployees as $balanceEmployee):
                    $balance = $balances[(int) $balanceEmployee['id']];
                    $available = (float) $balance['available_days'];
                ?>
                <tr>
                    <td><?= e($balanceEmployee['name']) ?> <small style="color:#94a3b8;">(<?= e($balanceEmployee['employee_code']) ?>)</small></td>
                    <td><input type="number" step="0.5" min="0" max="365" name="entitlement[<?= (int) $balanceEmployee['id'] ?>]" value="<?= number_format((float) $balance['entitlement_days'], 1, '.', '') ?>" style="width:90px;"></td>
                    <td><?= number_format((float) $balance['carried_forward_days'], 1) ?></td>
                    <td><input type="number" step="0.5" min="-365" max="365" name="adjustment[<?= (int) $balanceEmployee['id'] ?>]" value="<?= number_format((float) $balance['adjustment_days'], 1, '.', '') ?>" style="width:90px;"></td>
                    <td><?= number_format((float) $balance['used_days'], 1) ?></td>
                    <td style="font-weight:bold; color:<?= $available < 0 ? '#dc2626' : '#15803d' ?>;"><?= number_format($available, 1) ?></td>
                    <td style="color:#64748b;"><?= number_format(max(0.0, $available), 1) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f8fafc; font-weight:bold;">
                    <td><?= e(t('total')) ?></td>
                    <td><?= number_format($balanceTotals['entitlement_days'], 1) ?></td>
                    <td><?= number_format($balanceTotals['carried_forward_days'], 1) ?></td>
                    <td><?= number_format($balanceTotals['adjustment_days'], 1) ?></td>
                    <td><?= number_format($balanceTotals['used_days'], 1) ?></td>
                    <td style="color:<?= $balanceTotals['available_days'] < 0 ? '#dc2626' : '#15803d' ?>;"><?= number_format($balanceTotals['available_days'], 1) ?></td>
                    <td style="color:#64748b;"><?= number_format($balanceTotals['carry_next'], 1) ?></td>
                </tr>
            </tbody>
        </table>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:12px;">
            <button type="submit" name="save_leave_balances" value="1" class="btn">💾 <?= e(t('leave_save_balances')) ?></button>
            <small style="color:#64748b;"><?= e(t('leave_balance_formula')) ?></small>
        </div>
    </form>

    <div style="border-top:1px solid #e2e8f0; margin-top:18px; padding-top:15px;">
        <form method="post" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="balance_year" value="<?= $balanceYear ?>">
            <label for="default_days" style="margin:0;"><?= e(sprintf(t('leave_default_entitlement'), $balanceYear)) ?></label>
            <input type="number" id="default_days" name="default_days" step="0.5" min="0" max="365" value="0" style="width:90px;">
            <button type="submit" name="apply_leave_default" value="1" class="btn btn-secondary"><?= e(t('leave_apply_default')) ?></button>
            <small style="color:#64748b;"><?= e(t('leave_existing_rows_note')) ?></small>
        </form>
    </div>
    <?php else: ?>
    <table style="margin-top:18px;">
        <thead>
            <tr>
                <th><?= e(t('leave_employee')) ?></th>
                <th><?= e(t('leave_entitlement')) ?></th>
                <th><?= e(t('leave_carried_forward')) ?></th>
                <th><?= e(t('leave_adjustment')) ?></th>
                <th><?= e(t('leave_used')) ?></th>
                <th><?= e(t('leave_available')) ?></th>
                <th><?= e(sprintf(t('leave_carries_to'), $balanceYear + 1)) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($balanceEmployees as $balanceEmployee):
                $balance = $balances[(int) $balanceEmployee['id']];
                $available = (float) $balance['available_days'];
            ?>
            <tr>
                <td><?= e($balanceEmployee['name']) ?> <small style="color:#94a3b8;">(<?= e($balanceEmployee['employee_code']) ?>)</small></td>
                <td><?= number_format((float) $balance['entitlement_days'], 1) ?></td>
                <td><?= number_format((float) $balance['carried_forward_days'], 1) ?></td>
                <td><?= number_format((float) $balance['adjustment_days'], 1) ?></td>
                <td><?= number_format((float) $balance['used_days'], 1) ?></td>
                <td style="font-weight:bold; color:<?= $available < 0 ? '#dc2626' : '#15803d' ?>;"><?= number_format($available, 1) ?></td>
                <td style="color:#64748b;"><?= number_format(max(0.0, $available), 1) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc; font-weight:bold;">
                <td><?= e(t('total')) ?></td>
                <td><?= number_format($balanceTotals['entitlement_days'], 1) ?></td>
                <td><?= number_format($balanceTotals['carried_forward_days'], 1) ?></td>
                <td><?= number_format($balanceTotals['adjustment_days'], 1) ?></td>
                <td><?= number_format($balanceTotals['used_days'], 1) ?></td>
                <td style="color:<?= $balanceTotals['available_days'] < 0 ? '#dc2626' : '#15803d' ?>;"><?= number_format($balanceTotals['available_days'], 1) ?></td>
                <td style="color:#64748b;"><?= number_format($balanceTotals['carry_next'], 1) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
