<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$pageTitle = 'Fixed Assets';

$q = trim($_GET['q'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$condition = trim($_GET['condition'] ?? '');
$state = trim($_GET['state'] ?? '');
$conditions = ['good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'damaged' => 'Damaged', 'critical' => 'Critical'];
if (!isset($conditions[$condition])) {
    $condition = '';
}
if (!in_array($state, ['active', 'inactive'], true)) {
    $state = '';
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.asset_code LIKE ? OR a.name LIKE ? OR a.location LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
if ($category > 0) {
    $where[] = 'a.category_id = ?';
    $params[] = $category;
}
if ($condition !== '') {
    $where[] = 'a.condition_status = ?';
    $params[] = $condition;
}
if ($state === 'active') {
    $where[] = 'a.status = 1';
} elseif ($state === 'inactive') {
    $where[] = 'a.status = 0';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT a.*, ac.name AS category_name,
        am.next_maintenance_date,
        DATEDIFF(am.next_maintenance_date, CURDATE()) AS maintenance_days
    FROM assets a
    JOIN asset_categories ac ON ac.id = a.category_id
    LEFT JOIN asset_maintenance am ON am.id = (
        SELECT am2.id FROM asset_maintenance am2
        WHERE am2.asset_id = a.id
        ORDER BY am2.maintenance_date DESC, am2.id DESC LIMIT 1
    )
    {$whereSql}
    ORDER BY a.name");
$stmt->execute($params);
$assets = $stmt->fetchAll();

$totalCost = 0.0;
$totalValue = 0.0;
$activeCount = 0;
$dueCount = 0;
foreach ($assets as $asset) {
    $totalCost += (float) $asset['purchase_cost'];
    $totalValue += (float) $asset['current_value'];
    if ((int) $asset['status'] === 1) {
        $activeCount++;
        if (!empty($asset['next_maintenance_date']) && $asset['next_maintenance_date'] <= date('Y-m-d', strtotime('+30 days'))) {
            $dueCount++;
        }
    }
}

$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll();
$filterParams = array_filter([
    'q' => $q,
    'category' => $category ?: '',
    'condition' => $condition,
    'state' => $state,
], static fn($v) => $v !== '');
$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .asset-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .asset-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .asset-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .asset-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .asset-kpi strong { display:block; margin-top:4px; color:#183b56; font-size:1.3rem; }
    .asset-kpi.due { border-left-color:#b45309; }
    .asset-kpi.value { border-left-color:#2a9d8f; }
    .asset-actions { display:flex; gap:5px; flex-wrap:wrap; }
    .cond-good { background:#dcfce7; color:#166534; }
    .cond-fair { background:#fef3c7; color:#92400e; }
    .cond-poor, .cond-damaged, .cond-critical { background:#fee2e2; color:#991b1b; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .asset-kpi, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🏭 Fixed Assets</h1>
        <p style="color:#64748b;margin:6px 0 0;">Plant machinery, equipment, vehicles, and their condition.</p>
    </div>
    <div class="header-actions no-print">
        <?php if (has_permission('assets', 'create')): ?>
        <a class="btn btn-accent" href="asset_form.php">+ Add asset</a>
        <a class="btn btn-secondary" href="journal.php">📒 Asset journal</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="depreciation.php">Depreciation</a>
        <a class="btn btn-secondary" href="alerts.php">Alerts</a>
        <a class="btn btn-secondary" href="export_csv.php?<?= e(http_build_query($filterParams)) ?>">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="asset-kpis">
    <div class="asset-kpi"><span>Assets listed</span><strong><?= number_format(count($assets)) ?></strong><small style="color:#64748b;"><?= number_format($activeCount) ?> active</small></div>
    <div class="asset-kpi"><span>Purchase cost</span><strong><?= e(currency_symbol()) . money($totalCost) ?></strong></div>
    <div class="asset-kpi value"><span>Current value</span><strong><?= e(currency_symbol()) . money($totalValue) ?></strong></div>
    <div class="asset-kpi due"><span>Maintenance due (30d)</span><strong><?= number_format($dueCount) ?></strong></div>
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search code, name, location" style="min-width:210px;">
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= $category === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="condition">
            <option value="">All conditions</option>
            <?php foreach ($conditions as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $condition === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="state">
            <option value="">All statuses</option>
            <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($filterParams): ?><a href="index.php" class="btn btn-secondary">Reset</a><?php endif; ?>
    </form>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Asset code</th>
                <th>Name</th>
                <th>Category</th>
                <th>Purchased</th>
                <th style="text-align:right;">Purchase cost</th>
                <th style="text-align:right;">Current value</th>
                <th>Location</th>
                <th>Condition</th>
                <th>Next maintenance</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$assets): ?><tr><td colspan="11">No assets match the selected filters.</td></tr><?php endif; ?>
            <?php foreach ($assets as $asset): ?>
            <?php
                $cond = $asset['condition_status'] ?: 'good';
                $condClass = in_array($cond, ['poor', 'damaged', 'critical'], true) ? 'cond-poor' : 'cond-' . $cond;
                $days = $asset['maintenance_days'];
            ?>
            <tr>
                <td><strong><?= e($asset['asset_code']) ?></strong></td>
                <td><?= e($asset['name']) ?></td>
                <td><?= e($asset['category_name']) ?></td>
                <td style="white-space:nowrap;"><?= e(format_date($asset['purchase_date'])) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($asset['purchase_cost']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($asset['current_value']) ?></td>
                <td><?= e($asset['location'] ?: '—') ?></td>
                <td><span class="badge <?= e($condClass) ?>"><?= e(ucfirst($cond)) ?></span></td>
                <td style="white-space:nowrap;">
                    <?= !empty($asset['next_maintenance_date']) ? e(format_date($asset['next_maintenance_date'])) : '—' ?>
                    <?php if ($days !== null && (int) $asset['status'] === 1): ?>
                        <?php if ((int) $days < 0): ?><span class="badge badge-danger" style="margin-left:4px;"><?= abs((int) $days) ?>d late</span>
                        <?php elseif ((int) $days <= 30): ?><span class="badge badge-warning" style="margin-left:4px;">in <?= (int) $days ?>d</span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= (int) $asset['status'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $asset['status'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                <td class="no-print">
                    <div class="asset-actions">
                        <?php if (has_permission('assets', 'edit')): ?>
                        <a class="btn btn-sm btn-secondary" href="asset_form.php?id=<?= (int) $asset['id'] ?>">Edit</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-secondary" href="maintenance.php?asset_id=<?= (int) $asset['id'] ?>">Maintenance</a>
                        <?php if (has_permission('assets', 'create')): ?>
                        <a class="btn btn-sm btn-secondary" href="journal.php?asset_id=<?= (int) $asset['id'] ?>">Journal</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($assets): ?>
        <tfoot>
            <tr style="font-weight:700;background:#f8fafc;">
                <td colspan="4">TOTAL (<?= number_format(count($assets)) ?> assets)</td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totalCost) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totalValue) ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<p style="color:#64748b;font-size:.8rem;margin-top:10px;">Current value reflects recorded revaluations and posted depreciation, not a live depreciation calculation. See the <a href="depreciation.php">depreciation report</a> for the computed book value.</p>

<?php require_once "../partials/footer.php"; ?>
