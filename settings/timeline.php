<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$pageTitle = 'Activity Timeline';

$validDate = static function ($value) {
    if ($value === '') {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
};

$module = trim($_GET['module'] ?? '');
$action = trim($_GET['action'] ?? '');
$q = trim($_GET['q'] ?? '');
$userId = (int) ($_GET['user'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$limit = min(500, max(25, (int) ($_GET['limit'] ?? 100)));
if (!$validDate($from)) {
    $from = '';
}
if (!$validDate($to)) {
    $to = '';
}

$sql = "SELECT al.*, u.name AS user_name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1";
$params = [];
if ($module !== '') { $sql .= " AND al.module = ?"; $params[] = $module; }
if ($action !== '') { $sql .= " AND al.action = ?"; $params[] = $action; }
if ($userId > 0) { $sql .= " AND al.user_id = ?"; $params[] = $userId; }
if ($from !== '') { $sql .= " AND al.created_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $sql .= " AND al.created_at <= ?"; $params[] = $to . ' 23:59:59'; }
if ($q !== '') {
    $sql .= " AND (al.description LIKE ? OR u.name LIKE ? OR al.ip_address LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$sql .= ' ORDER BY al.created_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = $pdo->query("SELECT DISTINCT module FROM activity_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

$todayEvents = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE created_at >= CURDATE()")->fetchColumn();
$weekEvents = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND user_id IS NOT NULL")->fetchColumn();

function timeline_icon($action) {
    $a = strtolower((string) $action);
    foreach ([
        ['delete', '🗑️'], ['remove', '🗑️'],
        ['approve', '✅'], ['accept', '✅'], ['reinstate', '♻️'],
        ['reject', '⛔'], ['cancel', '⛔'], ['hold', '⏸️'],
        ['create', '➕'], ['add', '➕'], ['insert', '➕'], ['new', '➕'],
        ['update', '✏️'], ['edit', '✏️'], ['change', '✏️'],
        ['login', '🔑'], ['logout', '🚪'],
        ['export', '📤'], ['print', '🖨️'],
        ['pay', '💵'], ['payment', '💵'],
        ['release', '🚪'], ['exit', '🚪'],
    ] as $pair) {
        if (strpos($a, $pair[0]) !== false) return $pair[1];
    }
    return '•';
}

$groups = [];
foreach ($logs as $log) {
    $day = date('Y-m-d', strtotime($log['created_at']));
    $groups[$day][] = $log;
}

$exportParams = array_filter([
    'module' => $module,
    'action' => $action,
    'q' => $q,
    'user' => $userId ?: '',
    'from' => $from,
    'to' => $to,
    'limit' => $limit,
], static fn($v) => $v !== '' && $v !== 0);
$exportUrl = 'export_logs.php' . ($exportParams ? '?' . http_build_query($exportParams) : '');
$hasFilters = $module !== '' || $action !== '' || $q !== '' || $userId > 0 || $from !== '' || $to !== '';

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .timeline-kpis { display:flex; gap:10px 22px; flex-wrap:wrap; color:#475569; font-size:.86rem; margin:0 0 16px; }
    .timeline-kpis strong { color:#183b56; }
    .timeline-summary { color:#64748b; font-size:.84rem; margin:0 0 10px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .timeline { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🕘 Activity Timeline</h1>
        <p style="color:#64748b;margin:6px 0 0;">A visual, day-by-day audit of who did what across the system.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="activity_log.php">Table view</a>
        <a class="btn btn-secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="timeline-kpis no-print">
    <span>Today: <strong><?= number_format($todayEvents) ?></strong></span>
    <span>Last 7 days: <strong><?= number_format($weekEvents) ?></strong></span>
    <span>Active users (7d): <strong><?= number_format($activeUsers) ?></strong></span>
    <span>Events shown: <strong><?= number_format(count($logs)) ?></strong></span>
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <input type="text" name="q" placeholder="Search description, user, IP" value="<?= e($q) ?>" style="min-width:190px;">
        <select name="module">
            <option value="">All modules</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?= e($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user">
            <option value="0">All users</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
        <select name="limit">
            <?php foreach ([25, 50, 100, 200, 500] as $n): ?>
            <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?> events</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a class="btn btn-secondary" href="timeline.php">Reset</a><?php endif; ?>
    </form>
</div>

<?php if (!$groups): ?>
<div class="panel">
    <p>No activity recorded for the selected filters. Actions across the system will appear here as a timeline.</p>
</div>
<?php else: ?>
<div class="timeline">
    <?php foreach ($groups as $day => $entries): ?>
    <div class="timeline-day">
        <div class="timeline-day-label">
            <?= $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l, d M Y', strtotime($day))) ?>
            <span style="font-weight:500;color:#64748b;font-size:.8rem;"> · <?= count($entries) ?> event<?= count($entries) === 1 ? '' : 's' ?></span>
        </div>
        <?php foreach ($entries as $log): ?>
        <div class="timeline-item">
            <div class="timeline-ico"><?= timeline_icon($log['action']) ?></div>
            <div class="timeline-body">
                <div class="timeline-title">
                    <?= e($log['user_name'] ?? 'System') ?>
                    <span class="badge badge-info" style="margin-left:6px;"><?= e($log['action']) ?></span>
                    <span class="badge badge-muted" style="margin-left:4px;"><?= e($log['module']) ?></span>
                </div>
                <div class="timeline-meta">
                    <span>🕒 <?= e(date('h:i A', strtotime($log['created_at']))) ?></span>
                    <?php if (!empty($log['record_id'])): ?><span>#<?= (int) $log['record_id'] ?></span><?php endif; ?>
                    <?php if (!empty($log['ip_address'])): ?><span>🌐 <?= e($log['ip_address']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($log['description'])): ?>
                <div class="timeline-desc"><?= e($log['description']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
