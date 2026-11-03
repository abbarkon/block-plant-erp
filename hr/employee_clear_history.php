<?php
/**
 * Clear an employee's history while keeping the employee record.
 *
 * Attendance, payroll, leave, loans and repayments, overtime, performance
 * reviews, asset assignments, documents, training, exit clearances and warning
 * letters are deleted permanently. The employee profile (code, name,
 * department, salary, bank details, shift and status) is kept so attendance can
 * start again from now.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'delete');

$employeeId = (int) ($_GET['id'] ?? $_POST['employee_id'] ?? 0);
if ($employeeId <= 0) {
    set_flash('error', 'No employee was selected.');
    header('Location: employees.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, employee_code, name, designation, department, status FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) {
    set_flash('error', 'That employee no longer exists.');
    header('Location: employees.php');
    exit;
}

$tableExists = static function (string $table) use ($pdo): bool {
    $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool) $check->fetchColumn();
};

$countRows = static function (string $table, string $where, array $params) use ($pdo, $tableExists): int {
    if (!$tableExists($table)) {
        return 0;
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $countStmt->execute($params);
    return (int) $countStmt->fetchColumn();
};

// Every per-employee table that is cleared. Order matters only for the deletes
// (children first); the counts can be read in any order.
$historyItems = [
    ['key' => 'attendance', 'label' => 'Attendance register rows', 'table' => 'attendance', 'where' => 'employee_id = ?'],
    ['key' => 'payroll', 'label' => 'Payroll records', 'table' => 'payroll', 'where' => 'employee_id = ?'],
    ['key' => 'leave_requests', 'label' => 'Leave requests', 'table' => 'leave_requests', 'where' => 'employee_id = ?'],
    ['key' => 'leave_balances', 'label' => 'Leave balances', 'table' => 'leave_balances', 'where' => 'employee_id = ?'],
    ['key' => 'loans', 'label' => 'Loans', 'table' => 'employee_loans', 'where' => 'employee_id = ?'],
    ['key' => 'loan_repayments', 'label' => 'Loan repayments', 'table' => 'loan_repayments', 'where' => 'loan_id IN (SELECT id FROM employee_loans WHERE employee_id = ?)'],
    ['key' => 'overtime_requests', 'label' => 'Overtime requests', 'table' => 'overtime_requests', 'where' => 'employee_id = ?'],
    ['key' => 'performance_reviews', 'label' => 'Performance reviews', 'table' => 'performance_reviews', 'where' => 'employee_id = ?'],
    ['key' => 'employee_assets', 'label' => 'Asset assignments', 'table' => 'employee_assets', 'where' => 'employee_id = ?'],
    ['key' => 'employee_documents', 'label' => 'Documents', 'table' => 'employee_documents', 'where' => 'employee_id = ?'],
    ['key' => 'employee_training', 'label' => 'Training records', 'table' => 'employee_training', 'where' => 'employee_id = ?'],
    ['key' => 'employee_exit_clearances', 'label' => 'Exit clearances', 'table' => 'employee_exit_clearances', 'where' => 'employee_id = ?'],
    ['key' => 'attendance_warning_issues', 'label' => 'Warning letters', 'table' => 'attendance_warning_issues', 'where' => 'employee_id = ?'],
    ['key' => 'attendance_warning_revocations', 'label' => 'Warning revocations', 'table' => 'attendance_warning_revocations', 'where' => 'employee_id = ?'],
    ['key' => 'attendance_warning_revocation_files', 'label' => 'Warning evidence files', 'table' => 'attendance_warning_revocation_files', 'where' => 'revocation_id IN (SELECT id FROM attendance_warning_revocations WHERE employee_id = ?)'],
    ['key' => 'employee_shifts', 'label' => 'Shift assignments', 'table' => 'employee_shifts', 'where' => 'employee_id = ?'],
];

$counts = [];
$totalRows = 0;
foreach ($historyItems as $item) {
    $count = $countRows($item['table'], $item['where'], [$employeeId]);
    $counts[$item['key']] = $count;
    $totalRows += $count;
}

/**
 * Resolve a stored document path to a real file inside uploads/ so a purge can
 * never delete anything outside that folder.
 */
$resolveUpload = static function (string $storedPath): string {
    $root = dirname(__DIR__);
    $uploadsRoot = realpath($root . '/uploads');
    if ($uploadsRoot === false) {
        return '';
    }
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $storedPath)) {
        $parsed = parse_url($storedPath, PHP_URL_PATH);
        $storedPath = is_string($parsed) ? $parsed : '';
    }
    // Employee documents are stored as /block_plant/uploads/..., warning
    // evidence as uploads/....
    if (strpos($storedPath, '/block_plant/') === 0) {
        $storedPath = substr($storedPath, strlen('/block_plant/'));
    }
    $storedPath = ltrim($storedPath, '/');
    if ($storedPath === '') {
        return '';
    }
    $full = $root . '/' . $storedPath;
    $real = realpath($full);
    if ($real === false || strpos($real, $uploadsRoot) !== 0) {
        return '';
    }
    return $real;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['form_token'] ?? '');
    $sessionToken = (string) ($_SESSION['employee_clear_history_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        set_flash('error', 'This request could not be verified. Please reload the page and try again.');
        header('Location: employee_clear_history.php?id=' . $employeeId);
        exit;
    }

    $confirmCode = trim((string) ($_POST['confirm_code'] ?? ''));
    if (!hash_equals((string) $employee['employee_code'], $confirmCode)) {
        set_flash('error', 'The employee code did not match. History was not cleared.');
        header('Location: employee_clear_history.php?id=' . $employeeId);
        exit;
    }

    // Collect files before deleting their rows, then remove them from disk only
    // after the database transaction has committed.
    $filesToDelete = [];
    $warningDirs = [];
    if ($tableExists('employee_documents')) {
        $docStmt = $pdo->prepare("SELECT file_path FROM employee_documents WHERE employee_id = ?");
        $docStmt->execute([$employeeId]);
        foreach ($docStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $filesToDelete[] = (string) $path;
        }
    }
    if ($tableExists('attendance_warning_revocations') && $tableExists('attendance_warning_revocation_files')) {
        $fileStmt = $pdo->prepare(
            "SELECT f.file_path, f.revocation_id
             FROM attendance_warning_revocation_files f
             JOIN attendance_warning_revocations r ON r.id = f.revocation_id
             WHERE r.employee_id = ?"
        );
        $fileStmt->execute([$employeeId]);
        foreach ($fileStmt->fetchAll() as $fileRow) {
            $filesToDelete[] = (string) $fileRow['file_path'];
            $warningDirs[(int) $fileRow['revocation_id']] = true;
        }
    }

    // Children before parents so foreign keys never block the purge.
    $deleteOrder = [
        'attendance_warning_revocation_files',
        'attendance_warning_revocations',
        'attendance_warning_issues',
        'loan_repayments',
        'employee_loans',
        'employee_documents',
        'employee_assets',
        'employee_training',
        'employee_exit_clearances',
        'performance_reviews',
        'overtime_requests',
        'leave_requests',
        'leave_balances',
        'employee_shifts',
        'payroll',
        'attendance',
    ];
    $deleteWhere = [
        'attendance_warning_revocation_files' => 'revocation_id IN (SELECT id FROM attendance_warning_revocations WHERE employee_id = ?)',
        'loan_repayments' => 'loan_id IN (SELECT id FROM employee_loans WHERE employee_id = ?)',
    ];

    try {
        $pdo->beginTransaction();
        // Assets issued to the employee are linked to the company asset record.
        // Mark those fixed assets available again before the assignment rows go.
        if ($tableExists('employee_assets') && $tableExists('assets')) {
            $restoreAssets = $pdo->prepare(
                "UPDATE assets SET status = 1
                 WHERE id IN (
                     SELECT fixed_asset_id FROM employee_assets
                     WHERE employee_id = ? AND fixed_asset_id IS NOT NULL AND status = 'issued'
                 )"
            );
            $restoreAssets->execute([$employeeId]);
        }
        foreach ($deleteOrder as $table) {
            if (!$tableExists($table)) {
                continue;
            }
            $where = $deleteWhere[$table] ?? 'employee_id = ?';
            $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE {$where}");
            $deleteStmt->execute([$employeeId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Could not clear the history for ' . $employee['employee_code'] . '. No records were changed.');
        header('Location: employee_clear_history.php?id=' . $employeeId);
        exit;
    }

    // Best-effort file cleanup after the records are gone.
    $removedFiles = 0;
    foreach (array_unique($filesToDelete) as $storedPath) {
        $full = $resolveUpload($storedPath);
        if ($full !== '' && is_file($full) && @unlink($full)) {
            $removedFiles++;
        }
    }
    $evidenceBase = realpath(dirname(__DIR__) . '/uploads/warning_evidence');
    if ($evidenceBase !== false) {
        foreach (array_keys($warningDirs) as $revocationId) {
            $dir = $evidenceBase . DIRECTORY_SEPARATOR . (int) $revocationId;
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    $removedParts = [];
    foreach ($historyItems as $item) {
        if (($counts[$item['key']] ?? 0) > 0) {
            $removedParts[] = $item['label'] . ' (' . number_format($counts[$item['key']]) . ')';
        }
    }
    $summary = $removedParts ? implode(', ', $removedParts) : 'no linked records';
    if ($removedFiles > 0) {
        $summary .= ', files (' . number_format($removedFiles) . ')';
    }

    log_activity(
        'delete',
        'hr',
        $employeeId,
        'Employee history cleared (employee kept): ' . $employee['employee_code'] . ' ' . $employee['name'] . ' — ' . $summary
    );
    // Invalidate the token so the same purge cannot be replayed.
    unset($_SESSION['employee_clear_history_token']);
    set_flash(
        'success',
        'History cleared for ' . $employee['employee_code'] . ' ' . $employee['name'] . ': ' . $summary
        . '. The employee record was kept and is ready for new attendance.'
    );
    header('Location: employees.php');
    exit;
}

if (empty($_SESSION['employee_clear_history_token'])) {
    $_SESSION['employee_clear_history_token'] = bin2hex(random_bytes(16));
}
$formToken = (string) $_SESSION['employee_clear_history_token'];

$pageTitle = 'Clear Employee History';
$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .clear-history-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 18px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
    }
    .clear-history-employee { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
    .clear-history-avatar {
        width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center;
        background: #e0e7ff; color: #3730a3; font-weight: 700; font-size: 1.1rem;
    }
    .clear-history-name { font-size: 1.15rem; font-weight: 700; }
    .clear-history-meta { color: #64748b; font-size: .9rem; }
    .clear-history-warning {
        border-left: 4px solid #b91c1c;
        background: #fef2f2;
        color: #7f1d1d;
        padding: 12px 14px;
        border-radius: 8px;
        margin: 16px 0;
        line-height: 1.55;
    }
    .clear-history-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px 18px; margin: 12px 0 0; padding: 0; list-style: none; }
    .clear-history-list li { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; border-bottom: 1px dashed #e2e8f0; }
    .clear-history-list .count { font-weight: 700; font-variant-numeric: tabular-nums; }
    .clear-history-list .count.zero { color: #94a3b8; font-weight: 500; }
    .clear-history-kept { color: #166534; background: #f0fdf4; border-left: 4px solid #16a34a; padding: 12px 14px; border-radius: 8px; line-height: 1.55; }
    .clear-history-confirm { margin-top: 18px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .clear-history-confirm input[type="text"] { max-width: 280px; }
    .clear-history-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
    .clear-history-actions .btn-danger:disabled { opacity: .45; cursor: not-allowed; }
    .clear-history-hint { color: #64748b; font-size: .85rem; }
</style>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="clear-history-card">
    <div class="clear-history-employee">
        <div class="clear-history-avatar"><?= e(strtoupper(substr((string) $employee['name'], 0, 1))) ?></div>
        <div>
            <div class="clear-history-name"><?= e($employee['name']) ?></div>
            <div class="clear-history-meta">
                <?= e($employee['employee_code']) ?>
                <?php if (!empty($employee['designation'])): ?> · <?= e($employee['designation']) ?><?php endif; ?>
                <?php if (!empty($employee['department'])): ?> · <?= e($employee['department']) ?><?php endif; ?>
                · <?= (int) $employee['status'] === 1 ? 'Active' : 'Inactive' ?>
            </div>
        </div>
    </div>

    <div class="clear-history-warning">
        <strong>This permanently deletes the employee's history.</strong>
        Attendance, payroll, leave, loans and repayments, overtime, performance reviews, asset assignments,
        documents, training, exit clearances and warning letters will be removed. Uploaded document and
        evidence files are deleted too. This cannot be undone.
    </div>

    <h2 style="font-size:1rem;margin:0 0 4px;">What will be deleted</h2>
    <ul class="clear-history-list">
        <?php foreach ($historyItems as $item): ?>
        <?php $count = $counts[$item['key']] ?? 0; ?>
        <li>
            <span><?= e($item['label']) ?></span>
            <span class="count<?= $count === 0 ? ' zero' : '' ?>"><?= number_format($count) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <p class="clear-history-hint" style="margin-top:10px;"><?= number_format($totalRows) ?> record<?= $totalRows === 1 ? '' : 's' ?> in total.</p>

    <div class="clear-history-kept" style="margin-top:14px;">
        <strong>The employee record is kept.</strong>
        Name, employee code, department, designation, salary, bank details, shift and status stay unchanged.
        Company asset records stay in the system and fixed assets issued to this employee are marked available again.
        Cashbook and accounting entries are not touched — only the employee's assignments and history are removed.
    </div>

    <form method="post" class="clear-history-confirm" data-code="<?= e($employee['employee_code']) ?>">
        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
        <input type="hidden" name="form_token" value="<?= e($formToken) ?>">
        <label for="confirm_code">Type the employee code <strong><?= e($employee['employee_code']) ?></strong> to confirm</label>
        <input type="text" id="confirm_code" name="confirm_code" autocomplete="off" spellcheck="false" placeholder="<?= e($employee['employee_code']) ?>" required>
        <div class="clear-history-actions">
            <button type="submit" id="clear-history-submit" class="btn btn-danger" disabled>Clear history</button>
            <a href="employees.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.querySelector('.clear-history-confirm');
    var input = document.getElementById('confirm_code');
    var button = document.getElementById('clear-history-submit');
    if (!form || !input || !button) {
        return;
    }
    var expected = form.getAttribute('data-code') || '';
    var sync = function () {
        button.disabled = input.value.trim() !== expected;
    };
    input.addEventListener('input', sync);
    sync();
})();
</script>

<?php require_once "../partials/footer.php"; ?>
