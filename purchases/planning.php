<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');

$pageTitle = t('purchase_planning');

$materials = $pdo->query("
    SELECT rm.*, rl.reorder_point, rl.reorder_quantity, rl.min_level,
           (SELECT smp.supplier_id FROM supplier_material_prices smp WHERE smp.material_id=rm.id AND smp.is_preferred=1 LIMIT 1) AS preferred_supplier_id,
           (SELECT s.name FROM supplier_material_prices smp JOIN suppliers s ON smp.supplier_id=s.id WHERE smp.material_id=rm.id AND smp.is_preferred=1 LIMIT 1) AS preferred_supplier
    FROM raw_materials rm
    LEFT JOIN reorder_levels rl ON rl.material_id = rm.id
    WHERE rm.status = 1
    ORDER BY rm.name
")->fetchAll();

$critical = 0;
foreach ($materials as $m) {
    $rop = (float) ($m['reorder_point'] ?? 0);
    if ($rop > 0 && (float) $m['current_stock'] <= $rop) {
        $critical++;
    }
}

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('purchase_planning') ?></h1>
    <div class="header-actions">
        <a href="analytics.php" class="btn btn-accent"><?= t('analytics') ?></a>
        <a href="schedule.php" class="btn btn-primary"><?= t('schedule') ?></a>
        <a href="create.php" class="btn btn-secondary"><?= t('new_po') ?></a>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
    <div class="panel"><h3 style="margin-top:0;"><?= t('spend_trend_6m') ?></h3><canvas id="chartPurchaseMonthly" height="160"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;"><?= t('spend_by_material_30d') ?></h3><canvas id="chartPurchaseByMaterial" height="160"></canvas></div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e74c3c;">⚠</div>
        <div><div class="stat-value"><?= $critical ?></div><div class="stat-label"><?= t('at_or_below_reorder') ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#3949ab;">🧱</div>
        <div><div class="stat-value"><?= count($materials) ?></div><div class="stat-label"><?= t('materials_tracked') ?></div></div>
    </div>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('material') ?></th>
                <th><?= t('stock') ?></th>
                <th><?= t('reorder_point') ?></th>
                <th><?= t('suggested_quantity') ?></th>
                <th><?= t('preferred_supplier') ?></th>
                <th><?= t('status') ?></th>
                <th><?= t('action') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($materials as $m):
                $rop = (float) ($m['reorder_point'] ?? 0);
                $suggest = (float) ($m['reorder_quantity'] ?? max(0, $rop - (float) $m['current_stock']));
                $low = $rop > 0 && (float) $m['current_stock'] <= $rop;
            ?>
            <tr>
                <td><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</td>
                <td><strong><?= number_format($m['current_stock'], 2) ?></strong></td>
                <td><?= number_format($rop, 2) ?></td>
                <td><?= $low ? number_format($suggest, 2) : '—' ?></td>
                <td><?= e($m['preferred_supplier'] ?? '—') ?></td>
                <td>
                    <?php if ($low): ?>
                    <span class="badge badge-danger"><?= t('reorder') ?></span>
                    <?php else: ?>
                    <span class="badge badge-success"><?= t('ok') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($low): ?>
                    <a class="btn-sm" href="create.php?material_id=<?= (int) $m['id'] ?>&supplier_id=<?= (int) ($m['preferred_supplier_id'] ?? 0) ?>&qty=<?= urlencode($suggest) ?>"><?= t('create_po') ?></a>
                    <a class="btn-sm" href="schedule.php?material_id=<?= (int) $m['id'] ?>"><?= t('schedule') ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/purchasing_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
