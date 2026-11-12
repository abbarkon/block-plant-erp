<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../shop/bootstrap.php";
require_login();
require_permission('sales', 'view');
ensure_shop_schema();

$pageTitle = shop_t('Online Orders');

$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$allowedStatuses = ['all', 'pending', 'confirmed', 'ready', 'completed', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT o.*, c.customer_code,
        (SELECT COUNT(*) FROM online_order_items i WHERE i.order_id = o.id) AS item_count
    FROM online_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    $whereSql
    ORDER BY o.id DESC
    LIMIT 300");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$counts = ['pending' => 0, 'confirmed' => 0, 'ready' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM online_orders GROUP BY status") as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['n'];
    }
}
$pendingValue = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM online_orders WHERE status = 'pending'")->fetchColumn();
$badgeFor = static function (string $s): string {
    return ['pending' => 'warning', 'confirmed' => 'info', 'ready' => 'info', 'completed' => 'success', 'cancelled' => 'danger'][$s] ?? 'muted';
};

$flash = get_flash();
require_once "../partials/header.php";
?>
<style>
    .oo-hero { background: linear-gradient(120deg, var(--bp-primary), var(--bp-accent)); color: #fff; border-radius: 14px; padding: 22px 26px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; align-items: center; }
    .oo-hero h1 { margin: 0 0 4px; font-size: 1.7rem; }
    .oo-hero p { margin: 0; opacity: .85; }
    .oo-hero .btn { background: #fff; color: var(--bp-primary); }
    .oo-kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .oo-kpi { background: #fff; border: 1px solid var(--bp-border); border-left: 4px solid var(--bp-primary); border-radius: 10px; padding: 12px 14px; box-shadow: 0 4px 14px rgba(15,23,42,.04); }
    .oo-kpi span { display: block; color: var(--bp-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
    .oo-kpi strong { display: block; margin-top: 3px; font-size: 1.3rem; }
    .oo-filter { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
    .oo-filter .grow { flex: 1; min-width: 210px; }
    .oo-filter label { margin: 0 0 5px; }
    .oo-status-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
    .oo-status-tabs a { text-decoration: none; padding: 7px 12px; border-radius: 999px; font-size: .82rem; font-weight: 700; color: #475569; background: #eef2f5; }
    .oo-status-tabs a.active { background: var(--bp-primary); color: #fff; }
    @media (max-width: 900px) { .oo-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

<div class="oo-hero">
    <div>
        <h1>🛒 <?= e(shop_t('Online Orders')) ?></h1>
        <p><?= e(shop_t('Orders placed on the public shop.')) ?></p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn" href="/block_plant/shop/index.php" target="_blank" rel="noopener">↗ <?= e(shop_t('Open shop')) ?></a>
        <a class="btn btn-secondary" href="index.php"><?= e(t('sales_invoices')) ?></a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="oo-kpis">
    <div class="oo-kpi"><span><?= e(shop_t('New')) ?></span><strong><?= number_format($counts['pending']) ?></strong></div>
    <div class="oo-kpi"><span><?= e(shop_t('Confirmed')) ?></span><strong><?= number_format($counts['confirmed']) ?></strong></div>
    <div class="oo-kpi"><span><?= e(shop_t('Ready')) ?></span><strong><?= number_format($counts['ready']) ?></strong></div>
    <div class="oo-kpi"><span><?= e(shop_t('Completed')) ?></span><strong><?= number_format($counts['completed']) ?></strong></div>
    <div class="oo-kpi"><span><?= e(shop_t('Pending')) ?> · <?= e(currency_symbol()) ?></span><strong><?= e(money($pendingValue)) ?></strong></div>
</div>

<div class="panel">
    <div class="oo-status-tabs">
        <?php foreach ($allowedStatuses as $s): ?>
        <a class="<?= $status === $s ? 'active' : '' ?>" href="online_orders.php?status=<?= e($s) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
            <?= e($s === 'all' ? shop_t('All') : shop_status_label($s)) ?>
            <?= $s !== 'all' ? ' (' . number_format($counts[$s]) . ')' : '' ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="oo-filter" style="margin-bottom:14px;">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <div class="grow">
            <label for="q"><?= e(shop_t('Search')) ?></label>
            <input id="q" type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(shop_t('Search by order number, customer or phone')) ?>">
        </div>
        <button type="submit" class="btn btn-accent"><?= e(shop_t('Filter')) ?></button>
        <a class="btn btn-secondary" href="online_orders.php"><?= e(shop_t('Reset')) ?></a>
    </form>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e(shop_t('Order')) ?></th>
                    <th><?= e(shop_t('Placed')) ?></th>
                    <th><?= e(shop_t('Customer')) ?></th>
                    <th><?= e(shop_t('Phone')) ?></th>
                    <th style="text-align:right;"><?= e(shop_t('Items')) ?></th>
                    <th style="text-align:right;"><?= e(shop_t('Total')) ?></th>
                    <th><?= e(shop_t('Status')) ?></th>
                    <th><?= e(shop_t('Actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--bp-muted);padding:28px;"><?= e(shop_t('No online orders yet.')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= e($order['order_no']) ?></strong></td>
                    <td><?= e(format_datetime($order['created_at'])) ?></td>
                    <td>
                        <strong><?= e($order['customer_name']) ?></strong>
                        <?php if (!empty($order['customer_code'])): ?><div style="font-size:.75rem;color:var(--bp-muted);"><?= e($order['customer_code']) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($order['phone']) ?></td>
                    <td style="text-align:right;"><?= number_format((int) $order['item_count']) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) ?><?= e(money($order['total_amount'])) ?></td>
                    <td><span class="badge badge-<?= e($badgeFor($order['status'])) ?>"><?= e(shop_status_label($order['status'])) ?></span></td>
                    <td><a class="btn btn-sm btn-secondary" href="online_order.php?id=<?= (int) $order['id'] ?>"><?= e(shop_t('View')) ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
