<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');

$pageTitle = t('supplier_price_list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('purchases', 'edit')) {
    $stmt = $pdo->prepare("INSERT INTO supplier_material_prices(supplier_id, material_id, unit_price, lead_time_days, is_preferred, notes) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE unit_price=VALUES(unit_price), lead_time_days=VALUES(lead_time_days), is_preferred=VALUES(is_preferred), notes=VALUES(notes)");
    $stmt->execute([
        (int) $_POST['supplier_id'],
        (int) $_POST['material_id'],
        (float) $_POST['unit_price'],
        (int) $_POST['lead_time_days'],
        isset($_POST['is_preferred']) ? 1 : 0,
        trim($_POST['notes'] ?? ''),
    ]);
    if (!empty($_POST['is_preferred'])) {
        $pdo->prepare("UPDATE supplier_material_prices SET is_preferred=0 WHERE material_id=? AND supplier_id!=?")
            ->execute([(int) $_POST['material_id'], (int) $_POST['supplier_id']]);
    }
    header('Location: supplier_prices.php');
    exit;
}

$lines = $pdo->query("
    SELECT smp.*, s.name AS supplier_name, rm.name AS material_name, rm.unit
    FROM supplier_material_prices smp
    JOIN suppliers s ON smp.supplier_id = s.id
    JOIN raw_materials rm ON smp.material_id = rm.id
    ORDER BY rm.name, s.name
")->fetchAll();

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status=1 ORDER BY name")->fetchAll();
$materials = $pdo->query("SELECT id, name, unit FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('supplier_price_list') ?></h1>
    <a href="planning.php" class="btn btn-secondary"><?= t('planning') ?></a>
</div>

<?php if (has_permission('purchases', 'edit')): ?>
<div class="panel">
    <h3><?= t('add_update_price') ?></h3>
    <form method="post" class="filter-form">
        <label><?= t('supplier') ?></label>
        <select name="supplier_id" required>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label><?= t('material') ?></label>
        <select name="material_id" required>
            <?php foreach ($materials as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="unit_price" placeholder="<?= e(t('unit_price')) ?>" required>
        <input type="number" name="lead_time_days" value="7" min="1" placeholder="<?= e(t('lead_days')) ?>">
        <label style="display:flex;align-items:center;gap:6px;margin:0;"><input type="checkbox" name="is_preferred"> <?= t('preferred') ?></label>
        <input type="text" name="notes" placeholder="<?= e(t('notes')) ?>">
        <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr><th><?= t('material') ?></th><th><?= t('supplier') ?></th><th><?= t('unit_price') ?></th><th><?= t('lead_time') ?></th><th><?= t('preferred') ?></th><th><?= t('notes') ?></th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $l): ?>
            <tr>
                <td><?= e($l['material_name']) ?> (<?= e($l['unit']) ?>)</td>
                <td><?= e($l['supplier_name']) ?></td>
                <td><?= currency_symbol() . money($l['unit_price']) ?></td>
                <td><?= (int) $l['lead_time_days'] ?> <?= t('days') ?></td>
                <td><?= $l['is_preferred'] ? '★' : '' ?></td>
                <td><?= e($l['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
