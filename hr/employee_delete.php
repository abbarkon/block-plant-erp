<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'delete');

// Deletion is a POST-only action. Opening this file directly just returns to
// the employee list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: employees.php');
    exit;
}

$token = (string) ($_POST['form_token'] ?? '');
$sessionToken = (string) ($_SESSION['employee_delete_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    set_flash('error', 'This delete request could not be verified. Please reload the employee list and try again.');
    header('Location: employees.php');
    exit;
}

$employeeId = (int) ($_POST['employee_id'] ?? 0);
if ($employeeId <= 0) {
    set_flash('error', 'No employee was selected for deletion.');
    header('Location: employees.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, employee_code, name FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) {
    set_flash('error', 'That employee no longer exists.');
    header('Location: employees.php');
    exit;
}

$label = trim((string) $employee['employee_code'] . ' ' . (string) $employee['name']);

/**
 * Rows in these tables are history: attendance, payroll, leave, loans, assets,
 * documents, training, warnings and exit clearances. Deleting the employee
 * would erase audit and payroll records, so deletion is refused instead.
 */
$historyTables = [
    'attendance' => 'attendance',
    'payroll' => 'payroll',
    'leave_requests' => 'leave requests',
    'employee_loans' => 'loans',
    'overtime_requests' => 'overtime requests',
    'performance_reviews' => 'performance reviews',
    'employee_assets' => 'assigned assets',
    'employee_documents' => 'documents',
    'employee_training' => 'training records',
    'employee_exit_clearances' => 'exit clearances',
    'attendance_warning_issues' => 'warning letters',
    'attendance_warning_revocations' => 'warning revocations',
];

/**
 * These tables only hold per-employee configuration. When an employee has no
 * history at all they are removed together with the employee.
 */
$configTables = ['leave_balances', 'employee_shifts'];

$tableExists = static function (string $table) use ($pdo): bool {
    // SHOW TABLES does not accept a placeholder in every MariaDB version, so the
    // hardcoded table name is quoted instead.
    $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool) $check->fetchColumn();
};

$blockers = [];
foreach ($historyTables as $table => $labelText) {
    if (!$tableExists($table)) {
        continue;
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE employee_id = ?");
    $countStmt->execute([$employeeId]);
    $count = (int) $countStmt->fetchColumn();
    if ($count > 0) {
        $blockers[] = $labelText . ' (' . number_format($count) . ')';
    }
}

if ($blockers) {
    set_flash(
        'error',
        'Cannot delete ' . $label . ': linked records exist in ' . implode(', ', $blockers)
        . '. Employees with history are kept for payroll and audit. Use Clear history to remove their records while keeping the employee, or Exit clearance to deactivate the employee instead.'
    );
    header('Location: employees.php');
    exit;
}

try {
    $pdo->beginTransaction();
    foreach ($configTables as $table) {
        if (!$tableExists($table)) {
            continue;
        }
        $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE employee_id = ?");
        $deleteStmt->execute([$employeeId]);
    }
    $deleteStmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
    $deleteStmt->execute([$employeeId]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Could not delete ' . $label . '. No records were changed.');
    header('Location: employees.php');
    exit;
}

log_activity('delete', 'hr', $employeeId, 'Employee deleted: ' . $label);
set_flash('success', $label . ' was deleted.');
header('Location: employees.php');
exit;
