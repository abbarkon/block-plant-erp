<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Employees";

// One token protects every delete form on this page.
if (empty($_SESSION['employee_delete_token'])) {
    $_SESSION['employee_delete_token'] = bin2hex(random_bytes(16));
}
$deleteToken = (string) $_SESSION['employee_delete_token'];
$canDeleteEmployees = has_permission('hr', 'delete');
$flash = get_flash();

require_once "../partials/header.php";

$stmt = $pdo->query("SELECT * FROM employees ORDER BY name");
$employees = $stmt->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>👥 Employees</h1>
    <?php if (has_permission('hr', 'create')): ?>
    <a href="employee_form.php" class="btn">+ Add Employee</a>
    <?php endif; ?>
</div>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <table>
        <thead>
            <tr>
                <th>Employee Code</th>
                <th>Name</th>
                <th>Designation</th>
                <th>Department</th>
                <th>Shift</th>
                <th>Phone</th>
                <th>Date of Joining</th>
                <th>Basic Salary</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($employees as $emp): ?>
            <tr>
                <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                <td><?= htmlspecialchars($emp['name']) ?></td>
                <td><?= htmlspecialchars($emp['designation']) ?></td>
                <td><?= htmlspecialchars($emp['department']) ?></td>
                <td><?= e(attendance_shift_label($emp['shift'] ?? 'morning')) ?></td>
                <td><?= htmlspecialchars($emp['phone']) ?></td>
                <td><?= date('d M Y', strtotime($emp['date_of_joining'])) ?></td>
                <td><?= number_format($emp['basic_salary'], 2) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $emp['status'] ? '#d4edda' : '#f8d7da' ?>; color: <?= $emp['status'] ? '#155724' : '#721c24' ?>">
                        <?= $emp['status'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <?php if (has_permission('hr', 'edit')): ?>
                    <a href="employee_form.php?id=<?= $emp['id'] ?>" class="btn btn-sm">Edit</a>
                    <?php endif; ?>
                    <?php if ((int) $emp['status'] === 1): ?>
                    <a href="employee_id_card.php?id=<?= (int) $emp['id'] ?>" class="btn-sm">Generate ID</a>
                    <?php endif; ?>
                    <a href="exit_clearance.php?employee_id=<?= (int) $emp['id'] ?>" class="btn-sm"><?= $emp['status'] ? 'Start exit clearance' : 'Exit history' ?></a>
                    <?php if ($canDeleteEmployees): ?>
                    <a href="employee_clear_history.php?id=<?= (int) $emp['id'] ?>" class="btn-sm" style="background: #b45309; margin-left: 4px;" title="Delete attendance, payroll, leave, loans and other history but keep this employee">Clear history</a>
                    <?php $deleteConfirm = 'Delete ' . $emp['employee_code'] . ' ' . $emp['name'] . ' permanently? This cannot be undone.'; ?>
                    <form method="post" action="employee_delete.php" style="display: inline;" onsubmit="return confirm(<?= e(json_encode($deleteConfirm)) ?>);">
                        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
                        <input type="hidden" name="form_token" value="<?= e($deleteToken) ?>">
                        <button type="submit" class="btn-sm btn-danger" style="margin-left: 4px;">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
