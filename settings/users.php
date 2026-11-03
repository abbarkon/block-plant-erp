<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'edit');

$pageTitle = "User Management";

function user_is_admin_role($role) {
    return in_array(strtolower(trim((string) $role)), ['admin', 'administrator'], true);
}

function active_admin_count(PDO $pdo, $excludeId = 0) {
    $sql = "SELECT COUNT(*) FROM users WHERE status = 1 AND LOWER(TRIM(role)) IN ('admin', 'administrator')";
    if ($excludeId > 0) {
        $sql .= " AND id <> " . (int) $excludeId;
    }
    return (int) $pdo->query($sql)->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $departmentRole = $_POST['department_role'] ?? '';
        $department = trim($_POST['department'] ?? '');
        $isAdmin = $departmentRole === 'admin';

        $errors = [];
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = 'Full name must be between 2 and 100 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
            $errors[] = 'Username must be 3–30 characters and may contain letters, numbers, dots, underscores, or hyphens.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if (!$isAdmin && (!in_array($departmentRole, ['manager', 'user'], true) || !isset(department_permission_modules()[$department]))) {
            $errors[] = 'Choose a valid department and department role.';
        }

        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: users.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $role = $isAdmin ? 'admin' : $departmentRole;
            $stmt = $pdo->prepare("INSERT INTO users(name, username, password, role, department, department_role, status) VALUES(?,?,?,?,?,?,1)");
            $stmt->execute([
                $name,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $isAdmin ? null : $department,
                $isAdmin ? null : $departmentRole,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();

            ensure_user_permission_defaults($newId);
            log_activity('create', 'settings', $newId, "User created: {$name} ({$username}) as " . ($isAdmin ? 'System Admin' : ucfirst($departmentRole) . ' · ' . $department));
            set_flash('success', 'User added successfully.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', $e->getCode() === '23000' ? 'That username is already in use.' : 'Could not create the user. Please try again.');
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'update_access') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $departmentRole = $_POST['department_role'] ?? '';
        $department = trim($_POST['department'] ?? '');
        $isAdmin = $departmentRole === 'admin';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            set_flash('danger', 'That user no longer exists.');
        } elseif ($id === (int) $_SESSION['user_id']) {
            set_flash('danger', 'You cannot change your own access level. Ask another administrator.');
        } elseif (!$isAdmin && (!in_array($departmentRole, ['manager', 'user'], true) || !isset(department_permission_modules()[$department]))) {
            set_flash('danger', 'Choose a valid department and department role.');
        } elseif (user_is_admin_role($target['role']) && !$isAdmin && (int) $target['status'] === 1 && active_admin_count($pdo, $id) === 0) {
            set_flash('danger', 'At least one active system administrator is required.');
        } else {
            $role = $isAdmin ? 'admin' : $departmentRole;
            $pdo->prepare("UPDATE users SET role = ?, department = ?, department_role = ? WHERE id = ?")
                ->execute([$role, $isAdmin ? null : $department, $isAdmin ? null : $departmentRole, $id]);
            log_activity('update', 'settings', $id, "Access updated for {$target['username']}: " . ($isAdmin ? 'System Admin' : ucfirst($departmentRole) . ' · ' . $department));
            set_flash('success', 'User access updated.');
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'update_status') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            set_flash('danger', 'That user no longer exists.');
        } elseif ($id === (int) $_SESSION['user_id']) {
            set_flash('danger', 'You cannot deactivate your own account.');
        } elseif ($status === 0 && user_is_admin_role($target['role']) && (int) $target['status'] === 1 && active_admin_count($pdo, $id) === 0) {
            set_flash('danger', 'At least one active system administrator is required.');
        } else {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $id]);
            log_activity('update', 'settings', $id, "User {$target['username']} " . ($status ? 'activated' : 'deactivated'));
            set_flash('success', 'User ' . ($status ? 'activated' : 'deactivated') . '.');
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'reset_password') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT name, username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            set_flash('danger', 'That user no longer exists.');
        } elseif (strlen($new) < 6) {
            set_flash('danger', 'The new password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            set_flash('danger', 'The password confirmation does not match.');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
            log_activity('update', 'settings', $id, "Password reset for {$target['username']}");
            set_flash('success', "Password reset for {$target['name']}. Share it securely and ask them to change it.");
        }
        header('Location: users.php');
        exit;
    }

    header('Location: users.php');
    exit;
}

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$deptFilter = trim($_GET['department'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(u.name LIKE ? OR u.username LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($statusFilter === '0' || $statusFilter === '1') {
    $where[] = "u.status = ?";
    $params[] = (int) $statusFilter;
}
if ($deptFilter !== '' && isset(department_permission_modules()[$deptFilter])) {
    $where[] = "u.department = ?";
    $params[] = $deptFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT u.*, (SELECT MAX(al.created_at) FROM activity_log al WHERE al.user_id = u.id) AS last_active
    FROM users u{$whereSql} ORDER BY u.status DESC, u.name");
$stmt->execute($params);
$users = $stmt->fetchAll();

$departments = ['gate' => 'Gate', 'production' => 'Production', 'sales' => 'Sales', 'purchasing' => 'Purchasing', 'hr' => 'HR', 'finance' => 'Finance'];

$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
$adminUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 1 AND LOWER(TRIM(role)) IN ('admin','administrator')")->fetchColumn();
$deptUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 1 AND department IS NOT NULL AND department <> ''")->fetchColumn();

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .user-grid { display:grid; grid-template-columns: minmax(290px, 1fr) minmax(0, 2.4fr); gap:20px; align-items:start; }
    @media (max-width: 980px) { .user-grid { grid-template-columns: 1fr; } }
    .kpi-row { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    .kpi-card { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .kpi-card span { display:block; color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-card strong { display:block; margin-top:4px; color:#183b56; font-size:1.35rem; }
    .user-form label { margin-top:12px; }
    .user-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .access-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .access-form select { min-width:104px; }
    .row-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .reset-box summary { cursor:pointer; color:#246a73; font-weight:600; font-size:.82rem; }
    .reset-box form { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; align-items:center; }
    .reset-box input { max-width:150px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .kpi-card, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>👤 User Management</h1>
        <p style="color:#64748b;margin:6px 0 0;">Create accounts, assign department access, and reset passwords. Administrators always have full access.</p>
    </div>
    <div class="header-actions no-print">
        <a href="permissions.php" class="btn btn-secondary">Role permissions</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="kpi-row">
    <div class="kpi-card"><span>Total users</span><strong><?= number_format($totalUsers) ?></strong></div>
    <div class="kpi-card"><span>Active</span><strong><?= number_format($activeUsers) ?></strong></div>
    <div class="kpi-card"><span>Administrators</span><strong><?= number_format($adminUsers) ?></strong></div>
    <div class="kpi-card"><span>Department users</span><strong><?= number_format($deptUsers) ?></strong></div>
</div>

<div class="user-grid">
    <div class="panel no-print">
        <h3>Add new user</h3>
        <form method="post" class="user-form">
            <input type="hidden" name="action" value="add_user">
            <label>Full name *</label>
            <input type="text" name="name" maxlength="100" required>
            <label>Username *</label>
            <input type="text" name="username" maxlength="30" pattern="[A-Za-z0-9._-]{3,30}" required>
            <p class="hint">3–30 characters: letters, numbers, dots, underscores, hyphens.</p>
            <label>Password *</label>
            <input type="password" name="password" minlength="6" required autocomplete="new-password">
            <p class="hint">At least 6 characters. The user can change it from My Profile.</p>
            <label>Access type *</label>
            <select name="department_role" required>
                <option value="">Select access type</option>
                <option value="user">Department User</option>
                <option value="manager">Department Manager</option>
                <option value="admin">System Admin</option>
            </select>
            <label>Department</label>
            <select name="department">
                <option value="">Not applicable for system admin</option>
                <?php foreach ($departments as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Department users can access only their department’s modules. Managers can also edit and approve.</p>
            <button type="submit" class="btn btn-accent" style="margin-top:16px;">Add user</button>
        </form>
    </div>

    <div class="panel">
        <h3>Users (<?= number_format(count($users)) ?>)</h3>
        <form method="get" class="filter-form no-print" style="margin-bottom:14px;">
            <input type="text" name="q" placeholder="Search name or username" value="<?= e($q) ?>">
            <select name="status">
                <option value="">All statuses</option>
                <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <select name="department">
                <option value="">All departments</option>
                <?php foreach ($departments as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $deptFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="users.php" class="btn btn-secondary">Reset</a>
        </form>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Last active</th>
                        <th class="no-print">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$users): ?>
                    <tr><td colspan="6">No users match the selected filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $user): ?>
                    <?php $isSelf = (int) $user['id'] === (int) $_SESSION['user_id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($user['name']) ?></strong>
                            <?php if ($isSelf): ?><span class="badge badge-info" style="margin-left:6px;">You</span><?php endif; ?>
                            <div style="color:#64748b;font-size:.76rem;">Joined <?= e(format_date($user['created_at'])) ?></div>
                        </td>
                        <td>@<?= e($user['username']) ?></td>
                        <td>
                            <?php if (user_is_admin_role($user['role'])): ?>
                                <span class="badge badge-info">System Admin</span>
                            <?php else: ?>
                                <span class="badge badge-muted"><?= e(ucfirst($user['department_role'] ?? 'User')) ?></span>
                                <div style="color:#64748b;font-size:.76rem;"><?= e($departments[$user['department'] ?? ''] ?? 'No department') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $user['status'] ? 'badge-success' : 'badge-danger' ?>"><?= $user['status'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><?= e(!empty($user['last_active']) ? format_datetime($user['last_active']) : 'Never') ?></td>
                        <td class="no-print">
                            <?php if ($isSelf): ?>
                                <span style="color:#64748b;font-size:.8rem;">Manage from My Profile</span>
                            <?php else: ?>
                            <div class="row-actions">
                                <form method="post" class="access-form">
                                    <input type="hidden" name="action" value="update_access">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <select name="department_role" required>
                                        <option value="user" <?= ($user['department_role'] ?? '') === 'user' && !user_is_admin_role($user['role']) ? 'selected' : '' ?>>User</option>
                                        <option value="manager" <?= ($user['department_role'] ?? '') === 'manager' && !user_is_admin_role($user['role']) ? 'selected' : '' ?>>Manager</option>
                                        <option value="admin" <?= user_is_admin_role($user['role']) ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                    <select name="department">
                                        <?php foreach ($departments as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= ($user['department'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm">Save access</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $user['status'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= $user['status'] ? 'btn-danger' : '' ?>"><?= $user['status'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <details class="reset-box">
                                    <summary>Reset password</summary>
                                    <form method="post">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <input type="password" name="new_password" placeholder="New password" minlength="6" required autocomplete="new-password">
                                        <input type="password" name="confirm_password" placeholder="Confirm" minlength="6" required autocomplete="new-password">
                                        <button type="submit" class="btn btn-sm btn-danger">Set password</button>
                                    </form>
                                </details>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
