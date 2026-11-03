<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();

$pageTitle = "My Profile";
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            set_flash('danger', 'Full name must be between 2 and 100 characters.');
        } else {
            $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $userId]);
            $_SESSION['name'] = $name;
            log_activity('update', 'settings', $userId, 'Profile name updated');
            set_flash('success', 'Profile updated successfully.');
        }
        header('Location: profile.php');
        exit;
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = (string) $stmt->fetchColumn();

        if ($current === '' || !password_verify($current, $hash)) {
            set_flash('danger', 'Your current password is incorrect.');
        } elseif (strlen($new) < 6) {
            set_flash('danger', 'The new password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            set_flash('danger', 'The new passwords do not match.');
        } elseif (password_verify($new, $hash)) {
            set_flash('danger', 'Choose a new password that is different from your current one.');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            session_regenerate_id(true);
            log_activity('update', 'settings', $userId, 'Password changed from profile');
            set_flash('success', 'Password changed successfully.');
        }
        header('Location: profile.php');
        exit;
    }

    header('Location: profile.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();

if (!$profile) {
    render_not_found('Profile not found', 'Your user account could not be loaded. Please sign in again.', '/block_plant/auth/logout.php', 'Sign out');
}

$counters = [
    'Sales created' => "SELECT COUNT(*) FROM sales WHERE created_by = ?",
    'Production orders' => "SELECT COUNT(*) FROM production WHERE created_by = ?",
    'Purchases' => "SELECT COUNT(*) FROM purchases WHERE created_by = ?",
    'Expenses' => "SELECT COUNT(*) FROM expenses WHERE created_by = ?",
    'Journal entries' => "SELECT COUNT(*) FROM journal_entries WHERE created_by = ?",
];
$stats = [];
foreach ($counters as $label => $sql) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $stats[$label] = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $stats[$label] = 0;
    }
}

$stmt = $pdo->prepare("SELECT action, module, description, created_at FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
$stmt->execute([$userId]);
$recentActivity = $stmt->fetchAll();

$isAdmin = in_array(strtolower(trim((string) $profile['role'])), ['admin', 'administrator'], true);
$visibleModules = [];
if (!$isAdmin) {
    foreach (permission_module_labels() as $key => $label) {
        if (has_permission($key, 'view')) {
            $visibleModules[$key] = $label;
        }
    }
}

$lastActive = $pdo->prepare("SELECT MAX(created_at) FROM activity_log WHERE user_id = ?");
$lastActive->execute([$userId]);
$lastActiveAt = $lastActive->fetchColumn();

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .profile-grid { display:grid; grid-template-columns: minmax(280px, 1fr) minmax(0, 2fr); gap:20px; align-items:start; }
    @media (max-width: 900px) { .profile-grid { grid-template-columns: 1fr; } }
    .profile-card { text-align:center; }
    .avatar { width:108px; height:108px; border-radius:50%; margin:0 auto 16px; display:grid; place-items:center; font-size:2.4rem; font-weight:800; color:#fff; background:linear-gradient(135deg,#183b56,#2a9d8f); box-shadow:0 10px 26px rgba(24,59,86,.22); }
    .profile-card h2 { margin:0 0 4px; color:#183b56; }
    .profile-card .handle { color:#64748b; margin:0 0 12px; }
    .profile-meta { border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; padding:14px 0; margin:14px 0; text-align:left; display:grid; gap:8px; font-size:.86rem; color:#475569; }
    .profile-meta strong { color:#183b56; }
    .stat-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px; text-align:left; }
    .stat-grid div { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 10px; }
    .stat-grid span { display:block; color:#64748b; font-size:.74rem; }
    .stat-grid strong { color:#183b56; font-size:1.05rem; }
    .profile-form h3 { margin-top:0; }
    .profile-form label { margin-top:12px; }
    .profile-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .chip-row { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
    .chip { display:inline-block; padding:4px 9px; border-radius:999px; background:#eaf3f0; color:#246a73; font-size:.76rem; font-weight:600; }
    .activity-list { list-style:none; margin:0; padding:0; }
    .activity-list li { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid #eef2f7; font-size:.86rem; }
    .activity-list li:last-child { border-bottom:none; }
    .activity-list .when { color:#64748b; white-space:nowrap; font-size:.78rem; min-width:112px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>👤 My Profile</h1>
        <p style="color:#64748b;margin:6px 0 0;">Manage your display name and password, and review your recent activity.</p>
    </div>
    <div class="header-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="profile-grid">
    <div class="panel profile-card">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($profile['name'], 0, 2))) ?></div>
        <h2><?= e($profile['name']) ?></h2>
        <p class="handle">@<?= e($profile['username']) ?></p>
        <span class="badge <?= $isAdmin ? 'badge-info' : 'badge-muted' ?>"><?= e(ucfirst($profile['role'])) ?></span>
        <span class="badge <?= $profile['status'] ? 'badge-success' : 'badge-danger' ?>"><?= $profile['status'] ? 'Active' : 'Inactive' ?></span>
        <div class="profile-meta">
            <div><strong>Member since</strong><br><?= e(format_date($profile['created_at'])) ?></div>
            <?php if (!empty($profile['department'])): ?>
            <div><strong>Department</strong><br><?= e(ucfirst($profile['department'])) ?><?= !empty($profile['department_role']) ? ' · ' . e(ucfirst($profile['department_role'])) : '' ?></div>
            <?php endif; ?>
            <div><strong>Last activity</strong><br><?= e($lastActiveAt ? format_datetime($lastActiveAt) : 'No activity yet') ?></div>
        </div>
        <h4 style="margin:0 0 8px;text-align:left;color:#183b56;">Activity summary</h4>
        <div class="stat-grid">
            <?php foreach ($stats as $label => $value): ?>
            <div><span><?= e($label) ?></span><strong><?= number_format($value) ?></strong></div>
            <?php endforeach; ?>
        </div>
        <div style="text-align:left;margin-top:16px;">
            <h4 style="margin:0 0 4px;color:#183b56;">Module access</h4>
            <?php if ($isAdmin): ?>
                <p style="color:#64748b;font-size:.84rem;margin:0;">Full access to every module as a system administrator.</p>
            <?php elseif ($visibleModules): ?>
                <div class="chip-row">
                    <?php foreach ($visibleModules as $label): ?><span class="chip"><?= e($label) ?></span><?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color:#64748b;font-size:.84rem;margin:0;">No module access assigned. Contact an administrator.</p>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="panel profile-form no-print" style="margin-bottom:20px;">
            <h3>Update profile</h3>
            <form method="post">
                <input type="hidden" name="action" value="update_profile">
                <label>Full name *</label>
                <input type="text" name="name" maxlength="100" minlength="2" required value="<?= e($profile['name']) ?>">
                <label>Username</label>
                <input type="text" value="<?= e($profile['username']) ?>" disabled>
                <p class="hint">Usernames are managed by an administrator.</p>
                <label>Role</label>
                <input type="text" value="<?= e(ucfirst($profile['role'])) ?>" disabled>
                <button type="submit" class="btn btn-accent" style="margin-top:16px;">Save profile</button>
            </form>
        </div>

        <div class="panel profile-form no-print" style="margin-bottom:20px;">
            <h3>Change password</h3>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <label>Current password *</label>
                <input type="password" name="current_password" required autocomplete="current-password">
                <label>New password *</label>
                <input type="password" name="new_password" minlength="6" required autocomplete="new-password">
                <label>Confirm new password *</label>
                <input type="password" name="confirm_password" minlength="6" required autocomplete="new-password">
                <p class="hint">At least 6 characters. Use a mix of letters, numbers, and symbols, and avoid reusing old passwords.</p>
                <button type="submit" class="btn btn-accent" style="margin-top:16px;">Change password</button>
            </form>
        </div>

        <div class="panel">
            <h3>Recent activity</h3>
            <?php if (!$recentActivity): ?>
            <p style="color:#64748b;">No activity recorded yet for your account.</p>
            <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentActivity as $a): ?>
                <li>
                    <span class="when"><?= e(format_datetime($a['created_at'])) ?></span>
                    <span>
                        <span class="badge badge-muted"><?= e($a['module']) ?></span>
                        <strong><?= e($a['action']) ?></strong>
                        <?= e($a['description'] ?? '') ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
