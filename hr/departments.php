<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');
ensure_department_management_schema();

$pageTitle = "Department Management";
$error = null;
$success = null;
$editingDepartment = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department'])) {
    $id = (int) ($_POST['department_id'] ?? 0);
    require_permission('hr', $id > 0 ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $error = "Department name is required.";
        $editingDepartment = $id > 0 ? ['id' => $id, 'name' => $name] : null;
    } else {
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $existingDepartment = $stmt->fetch();
                if (!$existingDepartment) {
                    throw new RuntimeException("Department not found.");
                }

                $stmt = $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                if ($existingDepartment['name'] !== $name) {
                    $stmt = $pdo->prepare("UPDATE employees SET department = ? WHERE TRIM(department) = ?");
                    $stmt->execute([$name, $existingDepartment['name']]);
                }
                $success = "Department updated successfully.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
                $stmt->execute([$name]);
                $success = "Department added successfully.";
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception instanceof PDOException
                ? "Could not save department. The name may already exist."
                : $exception->getMessage();
            $editingDepartment = $id > 0 ? ['id' => $id, 'name' => $name] : null;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_department'])) {
    require_permission('hr', 'edit');
    $id = (int) ($_POST['department_id'] ?? 0);
    $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE departments SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    $success = $status ? "Department reactivated." : "Department deactivated. Current employee assignments were retained.";
}

if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    require_permission('hr', 'edit');
    $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editingDepartment = $stmt->fetch();
    if (!$editingDepartment) {
        $error = "Department not found.";
    }
}

$departments = $pdo->query("SELECT d.*, COUNT(e.id) AS employee_count FROM departments d LEFT JOIN employees e ON e.department = d.name GROUP BY d.id ORDER BY d.status DESC, d.name")->fetchAll();
require_once "../partials/header.php";
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h1>Department Management</h1>
        <p style="color:#64748b;margin:6px 0 0;">Manage departments and employee assignments.</p>
    </div>
    <a href="employees.php" class="btn btn-secondary">Employees</a>
</div>

<?php if ($success): ?>
<div class="flash flash-success" style="background:#d4edda;color:#155724;padding:14px;border-radius:5px;margin-bottom:18px;">
    <?= e($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:#f8d7da;color:#721c24;padding:14px;border-radius:5px;margin-bottom:18px;">
    <?= e($error) ?>
</div>
<?php endif; ?>

<?php if (($editingDepartment && has_permission('hr', 'edit')) || (!$editingDepartment && has_permission('hr', 'create'))): ?>
<div class="panel" style="margin-bottom:20px;">
    <h2><?= $editingDepartment ? 'Edit Department' : 'Add Department' ?></h2>
    <form method="post" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <?php if ($editingDepartment): ?>
        <input type="hidden" name="department_id" value="<?= (int) $editingDepartment['id'] ?>">
        <?php endif; ?>
        <div style="min-width:260px;">
            <label for="departmentName">Department Name *</label>
            <input id="departmentName" type="text" name="name" maxlength="100" value="<?= e($editingDepartment['name'] ?? '') ?>" required>
        </div>
        <button type="submit" name="save_department" class="btn"><?= $editingDepartment ? 'Save Changes' : 'Add Department' ?></button>
        <?php if ($editingDepartment): ?><a href="departments.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Employees</th>
                    <th>Status</th>
                    <?php if (has_permission('hr', 'edit')): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $department): ?>
                <tr>
                    <td><?= e($department['name']) ?></td>
                    <td><?= number_format((int) $department['employee_count']) ?></td>
                    <td><?= $department['status'] ? 'Active' : 'Inactive' ?></td>
                    <?php if (has_permission('hr', 'edit')): ?>
                    <td style="display:flex;gap:8px;align-items:center;">
                        <a href="?edit=<?= (int) $department['id'] ?>" class="btn btn-sm">Edit</a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>">
                            <input type="hidden" name="status" value="<?= $department['status'] ? 0 : 1 ?>">
                            <button type="submit" name="toggle_department" class="btn btn-sm btn-secondary"><?= $department['status'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$departments): ?>
                <tr><td colspan="4" style="text-align:center;color:#64748b;padding:24px;">No departments have been added.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>