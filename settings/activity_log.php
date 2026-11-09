<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$pageTitle = "Activity Log";

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
$limit = min(500, max(25, (int) ($_GET['limit'] ?? 50)));
if (!$validDate($from)) {
    $from = '';
}
if (!$validDate($to)) {
    $to = '';
}

function activity_where($module, $action, $q, $userId, $from, $to, array &$params) {
    $sql = '';
    if ($module !== '') {
        $sql .= " AND al.module = ?";
        $params[] = $module;
    }
    if ($action !== '') {
        $sql .= " AND al.action = ?";
        $params[] = $action;
    }
    if ($userId > 0) {
        $sql .= " AND al.user_id = ?";
        $params[] = $userId;
    }
    if ($from !== '') {
        $sql .= " AND al.created_at >= ?";
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $sql .= " AND al.created_at <= ?";
        $params[] = $to . ' 23:59:59';
    }
    if ($q !== '') {
        $sql .= " AND (al.description LIKE ? OR u.name LIKE ? OR al.ip_address LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    return $sql;
}

$params = [];
$whereSql = activity_where($module, $action, $q, $userId, $from, $to, $params);
$sql = "SELECT al.*, u.name AS user_name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE 1=1{$whereSql} ORDER BY al.created_at DESC LIMIT " . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$countSql = "SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE 1=1{$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$filteredCount = (int) $countStmt->fetchColumn();

$modules = $pdo->query("SELECT DISTINCT module FROM activity_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

$totalEvents = (int) $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
$todayEvents = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE created_at >= CURDATE()")->fetchColumn();
$weekEvents = (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND user_id IS NOT NULL")->fetchColumn();

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
    .kpi-row { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    .kpi-card { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .kpi-card span { display:block; color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-card strong { display:block; margin-top:4px; color:#1f2933; font-size:1.35rem; }
    .log-desc { max-width:420px; }
    .filter-form .date-input { min-width:140px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .kpi-card, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>📋 Activity Log</h1>
        <p style="color:#64748b;margin:6px 0 0;">Every create, update, approval, export, and deletion recorded by the ERP.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="timeline.php">🕘 Timeline view</a>
        <a class="btn btn-secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="kpi-row no-print">
    <div class="kpi-card"><span>Total events</span><strong><?= number_format($totalEvents) ?></strong></div>
    <div class="kpi-card"><span>Today</span><strong><?= number_format($todayEvents) ?></strong></div>
    <div class="kpi-card"><span>Last 7 days</span><strong><?= number_format($weekEvents) ?></strong></div>
    <div class="kpi-card"><span>Active users (7d)</span><strong><?= number_format($activeUsers) ?></strong></div>
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
        <input type="date" class="date-input" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" class="date-input" name="to" value="<?= e($to) ?>" aria-label="To date">
        <select name="limit">
            <?php foreach ([25, 50, 100, 200, 500] as $n): ?>
            <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="activity_log.php" class="btn btn-secondary">Reset</a><?php endif; ?>
    </form>
</div>

<p style="color:#64748b;font-size:.84rem;margin:0 0 10px;">
    Showing <strong><?= number_format(count($logs)) ?></strong> of <strong><?= number_format($filteredCount) ?></strong> matching event<?= $filteredCount === 1 ? '' : 's' ?><?= $hasFilters ? ' (filters applied)' : '' ?>.
</p>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>Module</th>
                <th>Record</th>
                <th>Description</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$logs): ?>
            <tr><td colspan="7">No activity matches the selected filters. Actions across the ERP will appear here.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td style="white-space:nowrap;"><?= e(format_datetime($log['created_at'])) ?></td>
                <td><?= e($log['user_name'] ?? '—') ?></td>
                <td><span class="badge badge-info"><?= e($log['action']) ?></span></td>
                <td><span class="badge badge-muted"><?= e($log['module']) ?></span></td>
                <td><?= $log['record_id'] ? '#' . (int) $log['record_id'] : '—' ?></td>
                <td class="log-desc"><?= e($log['description'] ?? '') ?></td>
                <td style="font-size:.8rem;white-space:nowrap;"><?= e($log['ip_address']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
