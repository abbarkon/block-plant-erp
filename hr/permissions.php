<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
ensure_department_access_schema();

if (!can_manage_hr_permissions()) {
    http_response_code(403);
    exit('Access denied. HR managers only.');
}

$pageTitle = "HR Permissions";
$me = current_user();
$isAdmin = in_array(strtolower(trim((string) ($me['role'] ?? ''))), ['admin', 'administrator'], true);

$hrUsers = $pdo->query("
    SELECT id, name, username, role, department, department_role
    FROM users
    WHERE status = 1
      AND LOWER(TRIM(COALESCE(department, ''))) = 'hr'
      AND LOWER(TRIM(COALESCE(role, ''))) NOT IN ('admin', 'administrator')
    ORDER BY name
")->fetchAll();

$hrUserIds = array_map(static function ($row) {
    return (int) $row['id'];
}, $hrUsers);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $uid = (int) $_POST['user_id'];

    if (!in_array($uid, $hrUserIds, true)) {
        set_flash('danger', 'That user is not an HR department user.');
        header('Location: permissions.php');
        exit;
    }

    $target = null;
    foreach ($hrUsers as $candidate) {
        if ((int) $candidate['id'] === $uid) {
            $target = $candidate;
            break;
        }
    }

    if (strtolower(trim((string) ($target['department_role'] ?? ''))) === 'manager') {
        set_flash('danger', 'HR managers already have full HR access. Choose an HR user to grant extra actions.');
        header('Location: permissions.php?user_id=' . $uid);
        exit;
    }

    $edit = isset($_POST['edit']) ? 1 : 0;
    $delete = isset($_POST['delete']) ? 1 : 0;
    $approve = isset($_POST['approve']) ? 1 : 0;

    try {
        ensure_hr_permission_schema();
        $stmt = $pdo->prepare("
            INSERT INTO hr_permission_grants(user_id, can_edit, can_delete, can_approve, updated_by)
            VALUES(?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                can_edit = VALUES(can_edit),
                can_delete = VALUES(can_delete),
                can_approve = VALUES(can_approve),
                updated_by = VALUES(updated_by)
        ");
        $stmt->execute([$uid, $edit, $delete, $approve, (int) ($_SESSION['user_id'] ?? 0)]);

        $granted = [];
        if ($edit) {
            $granted[] = 'edit';
        }
        if ($delete) {
            $granted[] = 'delete';
        }
        if ($approve) {
            $granted[] = 'approve';
        }
        $summary = $granted
            ? 'extra HR actions granted to ' . $target['username'] . ': ' . implode(', ', $granted)
            : 'extra HR actions cleared for ' . $target['username'];
        log_activity('update', 'hr', $uid, 'HR permissions updated — ' . $summary);

        set_flash('success', 'HR permissions saved.');
    } catch (PDOException $e) {
        set_flash('danger', 'Could not save HR permissions. Please try again.');
    }

    header('Location: permissions.php?user_id=' . $uid);
    exit;
}

$user_id = (int) ($_GET['user_id'] ?? 0);
$target = null;
$current = [
    'can_view' => 0,
    'can_create' => 0,
    'can_edit' => 0,
    'can_delete' => 0,
    'can_approve' => 0,
];

if ($user_id) {
    foreach ($hrUsers as $candidate) {
        if ((int) $candidate['id'] === $user_id) {
            $target = $candidate;
            break;
        }
    }
    if ($target) {
        ensure_hr_permission_schema();
        $stmt = $pdo->prepare("SELECT * FROM hr_permission_grants WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if ($row) {
            $current = $row;
        }
    }
}

$targetIsManager = $target
    && strtolower(trim((string) ($target['department_role'] ?? ''))) === 'manager';

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .perm-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
    .perm-table td:not(:first-child), .perm-table th:not(:first-child) { text-align:center; width:110px; }
    .perm-table tr.is-granted td:first-child { border-left:3px solid #7a5cd6; }
    .perm-summary { display:flex; gap:10px 22px; flex-wrap:wrap; color:#475569; font-size:.86rem; margin-bottom:14px; }
    .perm-summary strong { color:#1c2250; }
    .perm-note { border-left:4px solid #4f4fc9; background:#eef0fa; color:#1c2250; padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:.88rem; }
    .perm-note.warn { border-left-color:#b45309; background:#fffbeb; color:#78350f; }
    .perm-locked { color:#7a5cd6; font-weight:700; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>🔑 HR Permissions</h1>
        <p style="color:#64748b;margin:6px 0 0;">Grant extra HR actions to HR department users. This page can only manage HR accounts.</p>
    </div>
    <div class="header-actions no-print">
        <a href="index.php" class="btn btn-secondary">HR overview</a>
        <?php if ($isAdmin): ?>
        <a href="../settings/permissions.php" class="btn btn-secondary">All role permissions</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="perm-note">
    <strong>Scope.</strong> HR users always keep <em>View</em> and <em>Create</em> access inside HR. Managers can grant
    <em>Edit</em>, <em>Delete</em>, and <em>Approve</em> to individual HR users below. Revoking or restoring a warning
    letter requires <em>Approve</em>. Actions outside the HR department cannot be granted from this screen.
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <select name="user_id" required onchange="this.form.submit()">
            <option value="">Select an HR user…</option>
            <?php foreach ($hrUsers as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $user_id === (int) $u['id'] ? 'selected' : '' ?>>
                <?= e($u['name']) ?> (@<?= e($u['username']) ?> · <?= e(ucfirst($u['department_role'] ?? 'user')) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Open</button>
    </form>
</div>

<?php if (!$hrUsers): ?>
<div class="perm-note warn">There are no active HR department users yet. Create one in User Management first.</div>
<?php elseif ($user_id && !$target): ?>
<div class="flash flash-danger">That HR user could not be found.</div>
<?php elseif ($targetIsManager): ?>
<div class="perm-note warn">
    <strong><?= e($target['name']) ?></strong> is an <strong>HR Manager</strong> and already has full HR access.
    Choose an HR <em>User</em> from the list above to grant extra actions.
</div>
<?php elseif ($target): ?>

<div class="perm-summary">
    <span>User: <strong><?= e($target['name']) ?></strong> (@<?= e($target['username']) ?>)</span>
    <span>Department: <strong>HR</strong></span>
    <span>Access type: <strong><?= e(ucfirst($target['department_role'] ?? 'user')) ?></strong></span>
</div>

<div class="table-container">
    <form method="post">
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>">
        <table class="data-table perm-table">
            <thead>
                <tr>
                    <th>HR action</th>
                    <th>Allowed</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <tr class="is-granted">
                    <td>View HR records</td>
                    <td><span class="perm-locked">Always</span></td>
                    <td style="text-align:left;color:#64748b;">Baseline access for every HR user.</td>
                </tr>
                <tr class="is-granted">
                    <td>Create HR records</td>
                    <td><span class="perm-locked">Always</span></td>
                    <td style="text-align:left;color:#64748b;">Baseline access for every HR user.</td>
                </tr>
                <tr class="<?= !empty($current['can_edit']) ? 'is-granted' : '' ?>">
                    <td>Edit HR records</td>
                    <td><input type="checkbox" name="edit" aria-label="Allow editing HR records" <?= !empty($current['can_edit']) ? 'checked' : '' ?>></td>
                    <td style="text-align:left;color:#64748b;">Update attendance, employees, leave, loans, and other HR data.</td>
                </tr>
                <tr class="<?= !empty($current['can_delete']) ? 'is-granted' : '' ?>">
                    <td>Delete HR records</td>
                    <td><input type="checkbox" name="delete" aria-label="Allow deleting HR records" <?= !empty($current['can_delete']) ? 'checked' : '' ?>></td>
                    <td style="text-align:left;color:#64748b;">Remove HR records. Grant sparingly.</td>
                </tr>
                <tr class="<?= !empty($current['can_approve']) ? 'is-granted' : '' ?>">
                    <td>Approve HR requests</td>
                    <td><input type="checkbox" name="approve" aria-label="Allow approving HR requests" <?= !empty($current['can_approve']) ? 'checked' : '' ?>></td>
                    <td style="text-align:left;color:#64748b;">Approve leave, overtime, loans, and other HR workflows. Also required to revoke or restore attendance warning letters.</td>
                </tr>
            </tbody>
        </table>
        <div class="perm-actions" style="padding:16px;">
            <button type="submit" class="btn btn-accent">Save HR permissions</button>
            <a href="permissions.php" class="btn btn-secondary">Clear selection</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
