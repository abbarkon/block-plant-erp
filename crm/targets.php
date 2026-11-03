<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('crm', 'view');

$pageTitle = "Sales Targets";
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('crm', 'create')) {
    $stmt = $pdo->prepare("INSERT INTO sales_targets(user_id, month, year, target_amount) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)");
    $stmt->execute([
        (int) $_POST['user_id'],
        (int) $_POST['month'],
        (int) $_POST['year'],
        (float) $_POST['target_amount'],
    ]);
    set_flash('success', 'Target saved.');
    header("Location: targets.php?month={$month}&year={$year}");
    exit;
}

$users = $pdo->query("SELECT id, name FROM users WHERE status=1 ORDER BY name")->fetchAll();

$stmt = $pdo->prepare("
    SELECT st.*, u.name AS user_name,
        (SELECT COALESCE(SUM(total_amount),0) FROM sales s
         WHERE s.created_by = st.user_id AND MONTH(s.sale_date)=st.month AND YEAR(s.sale_date)=st.year) AS achieved_amount
    FROM sales_targets st
    LEFT JOIN users u ON st.user_id = u.id
    WHERE st.month = ? AND st.year = ?
    ORDER BY u.name
");
$stmt->execute([$month, $year]);
$targets = $stmt->fetchAll();

foreach ($targets as &$t) {
    $achieved = (float) $t['achieved_amount'];
    $target = (float) $t['target_amount'];
    $t['pct'] = $target > 0 ? min(100, ($achieved / $target) * 100) : 0;
}
unset($t);

$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Sales Targets</h1>
</div>

<?php if ($flash): ?>
<div class="flash flash-success"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="filter-section">
    <form method="get" class="filter-form">
        <select name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
            <?php endfor; ?>
        </select>
        <input type="number" name="year" value="<?= $year ?>" min="2020" max="2100">
        <button type="submit" class="btn btn-primary">Show</button>
    </form>
</div>

<?php if (has_permission('crm', 'create')): ?>
<div class="panel">
    <h3>Set target</h3>
    <form method="post" class="filter-form">
        <select name="user_id" required>
            <option value="">Sales user</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="month" value="<?= $month ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="number" step="0.01" name="target_amount" placeholder="Target amount" required>
        <button type="submit" class="btn btn-accent">Save target</button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Target</th>
                <th>Achieved</th>
                <th>Progress</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$targets): ?>
            <tr><td colspan="5">No targets for this period. Add one above.</td></tr>
            <?php endif; ?>
            <?php foreach ($targets as $t): ?>
            <tr>
                <td><?= e($t['user_name'] ?? '—') ?></td>
                <td><?= currency_symbol() . money($t['target_amount']) ?></td>
                <td><?= currency_symbol() . money($t['achieved_amount']) ?></td>
                <td style="min-width:180px;">
                    <div class="bar-chart-row" style="margin:0;">
                        <div class="bar-wrap" style="flex:1;">
                            <div class="bar-fill" style="width:<?= number_format($t['pct'], 1) ?>%;"></div>
                        </div>
                        <span style="font-size:0.85rem;"><?= number_format($t['pct'], 0) ?>%</span>
                    </div>
                </td>
                <td>
                    <?php if ($t['pct'] >= 100): ?>
                    <span class="badge badge-success">Met</span>
                    <?php elseif ($t['pct'] >= 75): ?>
                    <span class="badge badge-info">On track</span>
                    <?php else: ?>
                    <span class="badge badge-warning">Behind</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
