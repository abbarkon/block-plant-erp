<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');

$pageTitle = 'Purchase Schedule';
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+14 days'));
$preMaterial = (int) ($_GET['material_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('purchases', 'create')) {
    if (isset($_POST['add_schedule'])) {
        $pdo->prepare("INSERT INTO purchase_schedules(schedule_date, supplier_id, material_id, target_quantity, priority, notes, created_by) VALUES(?,?,?,?,?,?,?)")
            ->execute([
                $_POST['schedule_date'],
                $_POST['supplier_id'] ?: null,
                (int) $_POST['material_id'],
                (float) $_POST['target_quantity'],
                $_POST['priority'] ?? 'normal',
                trim($_POST['notes'] ?? ''),
                $_SESSION['user_id'],
            ]);
        log_activity('create', 'purchases', (int) $pdo->lastInsertId(), 'Scheduled purchase');
        header("Location: schedule.php?from={$from}&to={$to}");
        exit;
    }
    if (isset($_POST['update_status']) && has_permission('purchases', 'edit')) {
        $pdo->prepare("UPDATE purchase_schedules SET status=? WHERE id=?")->execute([$_POST['status'], (int) $_POST['id']]);
        header("Location: schedule.php?from={$from}&to={$to}");
        exit;
    }
}

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status=1 ORDER BY name")->fetchAll();
$materials = $pdo->query("SELECT id, name, unit FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();

$stmt = $pdo->prepare("
    SELECT ps.*, rm.name AS material_name, rm.unit, s.name AS supplier_name
    FROM purchase_schedules ps
    JOIN raw_materials rm ON ps.material_id = rm.id
    LEFT JOIN suppliers s ON ps.supplier_id = s.id
    WHERE ps.schedule_date BETWEEN ? AND ?
    ORDER BY ps.schedule_date, FIELD(ps.priority,'urgent','high','normal','low')
");
$stmt->execute([$from, $to]);
$schedules = $stmt->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('purchase_schedule') ?></h1>
    <a href="planning.php" class="btn btn-secondary"><?= t('planning') ?></a>
</div>

<div class="filter-section">
    <form method="get" class="filter-form">
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-primary"><?= t('show') ?></button>
    </form>
</div>

<?php if (has_permission('purchases', 'create')): ?>
<div class="panel">
    <h3><?= t('schedule_purchase') ?></h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="add_schedule" value="1">
        <input type="date" name="schedule_date" value="<?= date('Y-m-d') ?>" required>
        <select name="supplier_id">
            <option value=""><?= t('supplier_optional') ?></option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="material_id" required>
            <?php foreach ($materials as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= $preMaterial === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="target_quantity" required min="0.01">
        <select name="priority">
            <option value="normal"><?= t('normal') ?></option>
            <option value="high"><?= t('high') ?></option>
            <option value="urgent"><?= t('urgent') ?></option>
        </select>
        <input type="text" name="notes" placeholder="<?= e(t('notes')) ?>">
        <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr><th><?= t('date') ?></th><th><?= t('material') ?></th><th><?= t('quantity') ?></th><th><?= t('supplier') ?></th><th><?= t('priority') ?></th><th><?= t('status') ?></th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($schedules as $s): ?>
            <tr>
                <td><?= format_date($s['schedule_date']) ?></td>
                <td><?= e($s['material_name']) ?> (<?= e($s['unit']) ?>)</td>
                <td><?= number_format($s['target_quantity'], 2) ?></td>
                <td><?= e($s['supplier_name'] ?? '—') ?></td>
                <td><span class="badge badge-warning"><?= e(t(strtolower($s['priority']))) ?></span></td>
                <td><?= e(t('status_' . strtolower($s['status']))) ?></td>
                <td>
                    <a class="btn-sm" href="create.php?material_id=<?= (int) $s['material_id'] ?>&supplier_id=<?= (int) ($s['supplier_id'] ?? 0) ?>&qty=<?= urlencode($s['target_quantity']) ?>"><?= t('order') ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
