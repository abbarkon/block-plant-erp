<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'view');

$pageTitle = 'Production Schedule';
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d', strtotime('+14 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) {
    $from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to)) {
    $to = date('Y-m-d', strtotime('+14 days'));
}
if ($to < $from) {
    [$from, $to] = [$to, $from];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_schedule']) && has_permission('production', 'create')) {
        $schedDate = (string) ($_POST['schedule_date'] ?? '');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) {
            set_flash('error', 'Choose a valid schedule date.');
            header("Location: schedule.php?from={$from}&to={$to}");
            exit;
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        $pdo->prepare("INSERT INTO production_schedules(schedule_date, block_type_id, target_quantity, priority, notes, created_by) VALUES(?,?,?,?,?,?)")
            ->execute([
                $schedDate,
                (int) $_POST['block_type_id'],
                (int) $_POST['target_quantity'],
                $priority,
                trim($_POST['notes'] ?? ''),
                $_SESSION['user_id'],
            ]);
        log_activity('create', 'production', (int) $pdo->lastInsertId(), 'Scheduled production');
        set_flash('success', 'Production run scheduled.');
        header("Location: schedule.php?from={$from}&to={$to}");
        exit;
    }
    if (isset($_POST['update_status'])) {
        if (!has_permission('production', 'edit')) {
            set_flash('error', 'You do not have permission to update schedule status.');
            header("Location: schedule.php?from={$from}&to={$to}");
            exit;
        }
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['planned', 'in_progress', 'completed', 'cancelled'], true)) {
            set_flash('error', 'Invalid schedule status.');
        } else {
            $pdo->prepare("UPDATE production_schedules SET status=? WHERE id=?")
                ->execute([$status, (int) $_POST['id']]);
            set_flash('success', 'Schedule updated.');
        }
        header("Location: schedule.php?from={$from}&to={$to}");
        exit;
    }
}

$blocks = $pdo->query("SELECT id, name, size FROM block_types WHERE status=1 ORDER BY name")->fetchAll();

$stmt = $pdo->prepare("
    SELECT ps.*, bt.name AS block_name, bt.size, u.name AS created_by_name
    FROM production_schedules ps
    JOIN block_types bt ON ps.block_type_id = bt.id
    LEFT JOIN users u ON ps.created_by = u.id
    WHERE ps.schedule_date BETWEEN ? AND ?
    ORDER BY ps.schedule_date ASC, FIELD(ps.priority,'urgent','high','normal','low'), ps.id
");
$stmt->execute([$from, $to]);
$schedules = $stmt->fetchAll();

$planned = count(array_filter($schedules, fn($s) => $s['status'] === 'planned'));
$inProgress = count(array_filter($schedules, fn($s) => $s['status'] === 'in_progress'));
$flash = get_flash();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Production Schedule</h1>
    <a href="planning.php" class="btn btn-secondary">Planning dashboard</a>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#3498db;">📅</div>
        <div><div class="stat-value"><?= count($schedules) ?></div><div class="stat-label">Jobs in range</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f39c12;">⏳</div>
        <div><div class="stat-value"><?= $planned ?></div><div class="stat-label">Planned</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2f7d4f;">🏭</div>
        <div><div class="stat-value"><?= $inProgress ?></div><div class="stat-label">In progress</div></div>
    </div>
</div>

<div class="filter-section">
    <form method="get" class="filter-form">
        <label>From</label><input type="date" name="from" value="<?= e($from) ?>">
        <label>To</label><input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-primary">Show</button>
    </form>
</div>

<?php if (has_permission('production', 'create')): ?>
<div class="panel">
    <h3>Schedule production run</h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="add_schedule" value="1">
        <input type="date" name="schedule_date" value="<?= date('Y-m-d') ?>" required>
        <select name="block_type_id" required>
            <?php foreach ($blocks as $b): ?>
            <option value="<?= (int) $b['id'] ?>"><?= e($b['name'] . ' ' . $b['size']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="target_quantity" placeholder="Target qty" required min="1">
        <select name="priority">
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
            <option value="low">Low</option>
        </select>
        <input type="text" name="notes" placeholder="Notes">
        <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Block</th>
                <th>Target</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Notes</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$schedules): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b;padding:20px;">No production runs scheduled between <?= e(format_date($from)) ?> and <?= e(format_date($to)) ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($schedules as $s): ?>
            <tr>
                <td><?= format_date($s['schedule_date']) ?></td>
                <td><?= e($s['block_name'] . ' ' . $s['size']) ?></td>
                <td><strong><?= number_format($s['target_quantity']) ?></strong></td>
                <td><span class="badge badge-<?= $s['priority'] === 'urgent' ? 'danger' : ($s['priority'] === 'high' ? 'warning' : 'muted') ?>"><?= e($s['priority']) ?></span></td>
                <td><span class="badge badge-info"><?= e($s['status']) ?></span></td>
                <td><?= e($s['notes'] ?? '') ?></td>
                <td>
                    <a class="btn-sm" href="create.php?block_type=<?= (int) $s['block_type_id'] ?>&planned=<?= (int) $s['target_quantity'] ?>">Run</a>
                    <?php if (has_permission('production', 'edit')): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="font-size:0.75rem;">
                            <?php foreach (['planned', 'in_progress', 'completed', 'cancelled'] as $st): ?>
                            <option value="<?= $st ?>" <?= $s['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
