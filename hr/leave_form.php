<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'create');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$leaveTypes = ['Sick Leave', 'Casual Leave', 'Annual Leave', 'Unpaid Leave', 'Emergency Leave'];
$form = [
    'employee_id' => (int) ($_POST['employee_id'] ?? 0),
    'leave_type' => (string) ($_POST['leave_type'] ?? ''),
    'from_date' => trim((string) ($_POST['from_date'] ?? '')),
    'to_date' => trim((string) ($_POST['to_date'] ?? '')),
    'reason' => trim((string) ($_POST['reason'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND status = 1");
    $employeeCheck->execute([$form['employee_id']]);
    if (!$employeeCheck->fetchColumn()) {
        $errors[] = t('leave_select_active_employee');
    }
    if (!in_array($form['leave_type'], $leaveTypes, true)) {
        $errors[] = t('leave_select_valid_type');
    }
    if (!is_valid_date_string($form['from_date']) || !is_valid_date_string($form['to_date'])) {
        $errors[] = t('leave_enter_valid_dates');
    } elseif ($form['to_date'] < $form['from_date']) {
        $errors[] = t('leave_to_before_from');
    } else {
        $days = (int) ((strtotime($form['to_date']) - strtotime($form['from_date'])) / 86400) + 1;
        if ($days > 365) {
            $errors[] = t('leave_max_365');
        }
    }
    if (mb_strlen($form['reason']) > 2000) {
        $errors[] = t('leave_reason_max_2000');
    }

    if (!$errors) {
        $overlapStmt = $pdo->prepare("SELECT from_date, to_date, status FROM leave_requests WHERE employee_id = ? AND status IN ('pending', 'approved') AND from_date <= ? AND to_date >= ? LIMIT 1");
        $overlapStmt->execute([$form['employee_id'], $form['to_date'], $form['from_date']]);
        $existing = $overlapStmt->fetch();
        if ($existing) {
            $errors[] = sprintf(
                t('leave_overlap'),
                leave_status_label($existing['status']),
                format_date($existing['from_date']),
                format_date($existing['to_date'])
            );
        }
    }

    if (!$errors && $form['leave_type'] === 'Annual Leave') {
        $annualExcess = annual_leave_request_excess(
            $form['employee_id'],
            $form['from_date'],
            $form['to_date']
        );
        if ($annualExcess !== null) {
            $errors[] = sprintf(
                t('leave_annual_exceeds_available'),
                $annualExcess['year'],
                number_format($annualExcess['requested'], 1),
                number_format(max(0.0, $annualExcess['available']), 1)
            );
        }
    }

    if (!$errors) {
        $days = (int) ((strtotime($form['to_date']) - strtotime($form['from_date'])) / 86400) + 1;
        $stmt = $pdo->prepare("INSERT INTO leave_requests(employee_id, leave_type, from_date, to_date, total_days, reason) VALUES(?,?,?,?,?,?)");
        $stmt->execute([$form['employee_id'], $form['leave_type'], $form['from_date'], $form['to_date'], $days, $form['reason']]);
        $newLeaveId = (int) $pdo->lastInsertId();
        log_activity('create', 'hr', $newLeaveId, 'Leave request submitted for employee #' . $form['employee_id']);

        $balanceNote = '';
        if ($form['leave_type'] === 'Annual Leave') {
            $balance = annual_leave_balance($form['employee_id'], (int) date('Y', strtotime($form['from_date'])));
            if ($days > $balance['available_days']) {
                $balanceNote = ' ' . sprintf(t('leave_exceeds_balance'), number_format($balance['available_days'], 1));
            }
        }
        set_flash('success', t('leave_submitted_success') . $balanceNote);
        // Take HR straight to the printable form so the employee can sign it.
        header('Location: leave_print.php?id=' . $newLeaveId . '&created=1');
        exit;
    }

    set_flash('error', implode(' ', $errors));
}

$pageTitle = t('leave_request_form');
require_once "../partials/header.php";

$stmt = $pdo->query("SELECT * FROM employees WHERE status=1 ORDER BY name");
$employees = $stmt->fetchAll();
$flash = get_flash();

$balanceYear = (int) date('Y');
$annualBalances = [];
foreach ($employees as $emp) {
    $annualBalances[(int) $emp['id']] = annual_leave_balance((int) $emp['id'], $balanceYear);
}
?>

<h1><?= e(t('leave_new_request')) ?></h1>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<form method="post" style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 600px;">
    <div style="margin-bottom: 15px;">
        <label><?= e(t('leave_employee')) ?> *</label>
        <select name="employee_id" id="employee_id" required>
            <option value=""><?= e(t('leave_select_employee')) ?></option>
            <?php foreach($employees as $emp): ?>
            <option value="<?= (int) $emp['id'] ?>" <?= $form['employee_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?> (<?= e($emp['employee_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <small id="annual-balance-hint" style="display:block;margin-top:5px;color:#0f766e;font-weight:600;"></small>
    </div>
    
    <div style="margin-bottom: 15px;">
        <label><?= e(t('leave_leave_type')) ?> *</label>
        <select name="leave_type" id="leave_type" required>
            <?php foreach ($leaveTypes as $leaveType): ?>
            <option value="<?= e($leaveType) ?>" <?= $form['leave_type'] === $leaveType ? 'selected' : '' ?>><?= e(leave_type_label($leaveType)) ?></option>
            <?php endforeach; ?>
        </select>
        <small style="color:#64748b;display:block;margin-top:4px;"><?= e(t('leave_type_hint')) ?></small>
    </div>
    
    <div style="margin-bottom: 15px;">
        <label><?= e(t('leave_from_date')) ?> *</label>
        <input type="date" name="from_date" value="<?= e($form['from_date']) ?>" required>
    </div>
    
    <div style="margin-bottom: 15px;">
        <label><?= e(t('leave_to_date')) ?> *</label>
        <input type="date" name="to_date" value="<?= e($form['to_date']) ?>" required>
    </div>
    
    <div style="margin-bottom: 15px;">
        <label><?= e(t('leave_reason')) ?> *</label>
        <textarea name="reason" rows="4" maxlength="2000" required><?= e($form['reason']) ?></textarea>
    </div>
    
    <div style="display: flex; gap: 10px;">
        <button type="submit" class="btn"><?= e(t('leave_submit_request')) ?></button>
        <a href="leaves.php" class="btn" style="background: #6c757d;"><?= e(t('cancel')) ?></a>
    </div>
</form>

<?php
$balanceJson = [];
foreach ($annualBalances as $empId => $balance) {
    $balanceJson[$empId] = [
        'available' => (float) $balance['available_days'],
        'entitlement' => (float) $balance['entitlement_days'],
        'carry' => (float) $balance['carried_forward_days'],
        'used' => (float) $balance['used_days'],
    ];
}
?>
<script>
(function () {
    var balances = <?= json_encode($balanceJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var hintTemplate = <?= json_encode(t('leave_annual_hint_js'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var year = <?= (int) $balanceYear ?>;
    var employeeSelect = document.getElementById('employee_id');
    var typeSelect = document.getElementById('leave_type');
    var hint = document.getElementById('annual-balance-hint');
    function updateHint() {
        if (!hint) return;
        hint.textContent = '';
        hint.style.color = '#0f766e';
        if (!employeeSelect || !typeSelect || typeSelect.value !== 'Annual Leave' || !employeeSelect.value) {
            return;
        }
        var balance = balances[employeeSelect.value];
        if (!balance) return;
        var values = [
            year,
            balance.available.toFixed(1),
            balance.entitlement.toFixed(1),
            balance.carry.toFixed(1),
            balance.used.toFixed(1)
        ];
        var valueIndex = 0;
        hint.textContent = hintTemplate.replace(/%s/g, function () { return values[valueIndex++]; });
        if (balance.available < 0) hint.style.color = '#dc2626';
    }
    if (employeeSelect) employeeSelect.addEventListener('change', updateHint);
    if (typeSelect) typeSelect.addEventListener('change', updateHint);
    updateHint();
})();
</script>

<?php require_once "../partials/footer.php"; ?>
