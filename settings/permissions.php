<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'edit');
ensure_department_access_schema();

$pageTitle = "Role Permissions";
$modules = permission_module_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $uid = (int) $_POST['user_id'];

    $stmt = $pdo->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $target = $stmt->fetch();

    if (!$target) {
        set_flash('danger', 'That user no longer exists.');
        header('Location: permissions.php');
        exit;
    }
    if (in_array(strtolower(trim((string) $target['role'])), ['admin', 'administrator'], true)) {
        set_flash('danger', 'System administrators always have full access. Their permissions cannot be limited.');
        header('Location: permissions.php?user_id=' . $uid);
        exit;
    }

    $before = [];
    $existing = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = ?");
    $existing->execute([$uid]);
    foreach ($existing->fetchAll() as $row) {
        $before[$row['module']] = $row;
    }

    $rows = [];
    foreach ($modules as $key => $label) {
        $view = isset($_POST["view_{$key}"]) ? 1 : 0;
        $create = isset($_POST["create_{$key}"]) ? 1 : 0;
        $edit = isset($_POST["edit_{$key}"]) ? 1 : 0;
        $delete = isset($_POST["delete_{$key}"]) ? 1 : 0;
        $approve = isset($_POST["approve_{$key}"]) ? 1 : 0;
        // Any granted action implies visibility; otherwise the user would hit a
        // permission wall on the page they are allowed to act on.
        if ($create || $edit || $delete || $approve) {
            $view = 1;
        }
        $rows[$key] = compact('view', 'create', 'edit', 'delete', 'approve');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$uid]);
        $ins = $pdo->prepare("INSERT INTO user_permissions(user_id, module, can_view, can_create, can_edit, can_delete, can_approve) VALUES(?,?,?,?,?,?,?)");
        foreach ($rows as $key => $p) {
            $ins->execute([$uid, $key, $p['view'], $p['create'], $p['edit'], $p['delete'], $p['approve']]);
        }
        $pdo->commit();

        $changed = [];
        foreach ($rows as $key => $p) {
            $old = $before[$key] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0];
            if ((int) $old['can_view'] !== $p['view'] || (int) $old['can_create'] !== $p['create']
                || (int) $old['can_edit'] !== $p['edit'] || (int) $old['can_delete'] !== $p['delete']
                || (int) ($old['can_approve'] ?? 0) !== $p['approve']) {
                $changed[] = $modules[$key] ?? $key;
            }
        }

        $summary = $changed
            ? 'Permissions updated for ' . $target['username'] . ' (' . count($changed) . ' module' . (count($changed) === 1 ? '' : 's') . ': ' . implode(', ', $changed) . ')'
            : 'Permissions saved with no changes for ' . $target['username'];
        log_activity('update', 'settings', $uid, $summary);
        set_flash('success', 'Permissions saved.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('danger', 'Could not save permissions. Please try again.');
    }
    header("Location: permissions.php?user_id={$uid}");
    exit;
}

$user_id = (int) ($_GET['user_id'] ?? 0);

$users = $pdo->query("SELECT id, name, username, role, department, department_role FROM users WHERE status = 1 ORDER BY name")->fetchAll();

$target = null;
$current = [];
$targetIsAdmin = false;
if ($user_id) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $user_id) {
            $target = $u;
            break;
        }
    }
    if (!$target) {
        $stmt = $pdo->prepare("SELECT id, name, username, role, department, department_role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $target = $stmt->fetch() ?: null;
    }
    if ($target) {
        $targetIsAdmin = in_array(strtolower(trim((string) $target['role'])), ['admin', 'administrator'], true);
        $stmt = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        foreach ($stmt->fetchAll() as $row) {
            $current[$row['module']] = $row;
        }
    }
}

$grantedModules = 0;
foreach ($modules as $key => $label) {
    if (!empty($current[$key]['can_view'])) {
        $grantedModules++;
    }
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .perm-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
    .perm-table td:not(:first-child), .perm-table th:not(:first-child) { text-align:center; width:92px; }
    .perm-table tr.is-granted td:first-child { border-left:3px solid #7a5cd6; }
    .perm-summary { display:flex; gap:10px 22px; flex-wrap:wrap; color:#475569; font-size:.86rem; margin-bottom:14px; }
    .perm-summary strong { color:#1c2250; }
    .perm-note { border-left:4px solid #4f4fc9; background:#eef0fa; color:#1c2250; padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:.88rem; }
    .perm-note.warn { border-left-color:#b45309; background:#fffbeb; color:#78350f; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>🔐 Role Permissions</h1>
        <p style="color:#64748b;margin:6px 0 0;">Configure module-level access for individual users. System administrators always have full access.</p>
    </div>
    <div class="header-actions no-print">
        <a href="users.php" class="btn btn-secondary">User management</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <select name="user_id" required onchange="this.form.submit()">
            <option value="">Select a user…</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $user_id === (int) $u['id'] ? 'selected' : '' ?>>
                <?= e($u['name']) ?> (@<?= e($u['username']) ?> · <?= e($u['role']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Open</button>
    </form>
</div>

<?php if ($user_id && !$target): ?>
<div class="flash flash-danger">That user could not be found.</div>
<?php elseif ($target && $targetIsAdmin): ?>
<div class="perm-note">
    <strong><?= e($target['name']) ?></strong> is a <strong>System Admin</strong>. Administrators bypass this matrix and always have full access to every module. Change their access type in <a href="users.php">User Management</a> to configure granular permissions.
</div>
<?php elseif ($target): ?>

<div class="perm-note <?= !empty($target['department_role']) ? 'warn' : '' ?>">
    <?php if (!empty($target['department_role'])): ?>
    <strong>Department access takes precedence.</strong> <?= e($target['name']) ?> is a <strong><?= e(ucfirst($target['department_role'])) ?></strong> in <strong><?= e(ucfirst($target['department'] ?? '—')) ?></strong>. Department users can only reach their department’s modules (managers may edit and approve; users may view and create). Set their access type to a non-department role in <a href="users.php">User Management</a> to apply this matrix.
    <?php else: ?>
    This matrix applies to <strong><?= e($target['name']) ?></strong> (@<?= e($target['username']) ?>). Any granted action automatically enables <em>View</em> for that module.
    <?php endif; ?>
</div>

<div class="perm-summary">
    <span>Modules visible: <strong><?= $grantedModules ?> / <?= count($modules) ?></strong></span>
    <span>Role: <strong><?= e(ucfirst($target['role'])) ?></strong></span>
    <span>Department: <strong><?= e($target['department'] ?? '—') ?></strong></span>
</div>

<div class="table-container">
    <form method="post">
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
        <div class="perm-actions no-print" style="padding:14px 14px 0;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="setAll(true)">Grant all</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="setAll(false)">Clear all</button>
            <span style="color:#64748b;font-size:.82rem;">Tick individual actions, then save.</span>
        </div>
        <table class="data-table perm-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>View</th>
                    <th>Create</th>
                    <th>Edit</th>
                    <th>Delete</th>
                    <th>Approve</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $key => $label):
                    $p = $current[$key] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0];
                    $granted = !empty($p['can_view']);
                ?>
                <tr class="<?= $granted ? 'is-granted' : '' ?>">
                    <td><?= e($label) ?></td>
                    <td><input type="checkbox" name="view_<?= e($key) ?>" aria-label="View <?= e($label) ?>" <?= !empty($p['can_view']) ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="create_<?= e($key) ?>" aria-label="Create <?= e($label) ?>" <?= !empty($p['can_create']) ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="edit_<?= e($key) ?>" aria-label="Edit <?= e($label) ?>" <?= !empty($p['can_edit']) ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="delete_<?= e($key) ?>" aria-label="Delete <?= e($label) ?>" <?= !empty($p['can_delete']) ? 'checked' : '' ?>></td>
                    <td><input type="checkbox" name="approve_<?= e($key) ?>" aria-label="Approve <?= e($label) ?>" <?= !empty($p['can_approve']) ? 'checked' : '' ?>></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="padding:16px;">
            <button type="submit" class="btn btn-accent">Save permissions</button>
        </p>
    </form>
</div>
<?php endif; ?>

<?php if ($target && !$targetIsAdmin): ?>
<script>
function setAll(checked) {
    document.querySelectorAll('.perm-table input[type="checkbox"]').forEach(function (box) {
        box.checked = checked;
    });
}
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
