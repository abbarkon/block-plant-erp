<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_login();
ensure_notifications_schema();

$userId = (int) $_SESSION['user_id'];

// Handle actions: mark all read, mark single read, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'mark_all_read') {
        $pdo->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
        set_flash('success', 'All notifications marked as read.');
    } elseif ($action === 'mark_read') {
        $nid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$nid, $userId]);
    } elseif ($action === 'delete') {
        $nid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM user_notifications WHERE id = ? AND user_id = ?")->execute([$nid, $userId]);
        set_flash('success', 'Notification removed.');
    }
    header('Location: index.php' . (isset($_POST['return_to']) ? '?' . http_build_query(['filter' => $_POST['return_to']]) : ''));
    exit;
}

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$where = 'WHERE user_id = ?';
$params = [$userId];
if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($filter === 'read') {
    $where .= ' AND is_read = 1';
}

$stmt = $pdo->prepare("SELECT id, message, link, is_read, created_at FROM user_notifications {$where} ORDER BY created_at DESC LIMIT 300");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

$unreadCount = unread_notification_count($userId);
$cStmt = $pdo->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ?");
$cStmt->execute([$userId]);
$totalCount = (int) $cStmt->fetchColumn();

$flash = get_flash();
$pageTitle = 'Notifications';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <p style="color:#64748b;margin:6px 0 0;"><?= $unreadCount ?> unread of <?= $totalCount ?> total.</p>
    </div>
    <div class="header-actions">
        <?php if ($unreadCount > 0): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="return_to" value="<?= e($filter) ?>">
            <button class="btn btn-secondary" type="submit">Mark all read</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="notif-filters">
    <a href="?filter=all" class="notif-chip <?= $filter === 'all' ? 'active' : '' ?>">All</a>
    <a href="?filter=unread" class="notif-chip <?= $filter === 'unread' ? 'active' : '' ?>">Unread (<?= $unreadCount ?>)</a>
    <a href="?filter=read" class="notif-chip <?= $filter === 'read' ? 'active' : '' ?>">Read</a>
</div>

<div class="panel notif-list">
    <?php if (!$notifications): ?>
        <div class="notif-empty">
            <div style="font-size:2.4rem;margin-bottom:10px;">🎉</div>
            <p>No notifications here. You're all caught up.</p>
        </div>
    <?php else: ?>
        <?php foreach ($notifications as $n): ?>
        <?php $safeLink = safe_notification_link($n['link'] ?? ''); ?>
        <div class="notif-item <?= $n['is_read'] ? 'read' : 'unread' ?>">
            <div class="notif-body">
                <?php if ($safeLink !== ''): ?>
                    <a class="notif-msg" href="<?= e($safeLink) ?>" onclick="markNotificationRead(<?= (int) $n['id'] ?>)"><?= e($n['message']) ?></a>
                <?php else: ?>
                    <span class="notif-msg"><?= e($n['message']) ?></span>
                <?php endif; ?>
                <div class="notif-time"><?= e(format_datetime($n['created_at'])) ?></div>
            </div>
            <div class="notif-actions">
                <?php if (!$n['is_read']): ?>
                <form method="post">
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($filter) ?>">
                    <button class="notif-btn" type="submit" title="Mark as read">✓</button>
                </form>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($filter) ?>">
                    <button class="notif-btn danger" type="submit" title="Delete">✕</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.notif-filters{display:flex;gap:8px;margin:16px 0;flex-wrap:wrap}
.notif-chip{padding:7px 14px;border-radius:999px;border:1px solid #dde1f0;background:#fff;color:#475569;text-decoration:none;font-size:.85rem;font-weight:600;transition:all .15s}
.notif-chip:hover{border-color:#7a5cd6;color:#7a5cd6}
.notif-chip.active{background:#1c2250;color:#fff;border-color:#1c2250}
.notif-list{padding:0;overflow:hidden;max-width:860px}
.notif-item{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:15px 18px;border-bottom:1px solid #eef2f4}
.notif-item:last-child{border-bottom:0}
.notif-item.unread{background:#f2fbf9;border-left:4px solid #7a5cd6}
.notif-item.read{border-left:4px solid transparent}
.notif-body{flex:1;min-width:0}
.notif-msg{display:block;color:#1e293b;font-size:.95rem;text-decoration:none;word-wrap:break-word}
a.notif-msg:hover{color:#7a5cd6;text-decoration:underline}
.notif-item.read .notif-msg{color:#64748b}
.notif-time{color:#94a3b8;font-size:.78rem;margin-top:5px}
.notif-actions{display:flex;gap:6px;flex-shrink:0}
.notif-btn{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:6px;width:30px;height:30px;cursor:pointer;font-size:.85rem;line-height:1}
.notif-btn:hover{border-color:#7a5cd6;color:#7a5cd6}
.notif-btn.danger:hover{border-color:#b91c1c;color:#b91c1c}
.notif-empty{text-align:center;padding:50px 20px;color:#94a3b8}
</style>
<script>
function markNotificationRead(notificationId) {
    fetch('/block_plant/notifications/mark_read.php?id=' + notificationId, {
        method: 'GET'
    }).then(response => {
        if(response.ok) {
            // Reload the page to show updated state
            location.reload();
        }
    }).catch(error => {
        console.error('Error marking notification as read:', error);
    });
}
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
