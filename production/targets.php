<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('production', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS production_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    block_type_id INT NOT NULL,
    target_date DATE NOT NULL,
    target_quantity INT NOT NULL DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_type_id) REFERENCES block_types(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

$pageTitle = 'Production Targets';
$canEdit = has_permission('production', 'create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_target'])) {
    // Creating targets is a write action — do not let view-only users post.
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to create production targets.');
        header('Location: targets.php');
        exit;
    }
    $blockTypeId = (int) ($_POST['block_type_id'] ?? 0);
    $date = $_POST['target_date'] ?? date('Y-m-d');
    $quantity = (int) ($_POST['target_quantity'] ?? 0);
    if ($blockTypeId > 0 && $quantity > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $stmt = $pdo->prepare("INSERT INTO production_targets(block_type_id, target_date, target_quantity, notes, created_by) VALUES(?,?,?,?,?)");
        $stmt->execute([$blockTypeId, $date, $quantity, trim($_POST['notes'] ?? ''), $_SESSION['user_id']]);
        log_activity('create', 'production', $blockTypeId, "Production target {$quantity} on {$date}");
        set_flash('success', 'Production target saved.');
        header('Location: targets.php');
        exit;
    }
    set_flash('error', 'Choose a block type, a valid date, and a target quantity of at least 1.');
    header('Location: targets.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_target'])) {
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to delete production targets.');
        header('Location: targets.php');
        exit;
    }
    $targetId = (int) $_POST['delete_target'];
    $del = $pdo->prepare("DELETE FROM production_targets WHERE id = ?");
    $del->execute([$targetId]);
    if ($del->rowCount() > 0) {
        log_activity('delete', 'production', $targetId, 'Production target deleted');
        set_flash('success', 'Target deleted.');
    } else {
        set_flash('error', 'Target not found.');
    }
    header('Location: targets.php');
    exit;
}

$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$targets = $pdo->query("SELECT pt.*, bt.name, bt.size, COALESCE(SUM(p.good_quantity),0) AS achieved FROM production_targets pt JOIN block_types bt ON bt.id = pt.block_type_id LEFT JOIN production p ON p.block_type_id = pt.block_type_id AND p.production_date = pt.target_date GROUP BY pt.id, bt.name, bt.size ORDER BY pt.target_date DESC, bt.name")->fetchAll();
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Production Targets</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track daily and scheduled production against goals</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to production</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>Set production target</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_target" value="1">
        <div>
            <label>Block type</label>
            <select name="block_type_id" required>
                <option value="">Select block</option>
                <?php foreach ($blocks as $block): ?>
                <option value="<?= (int) $block['id'] ?>"><?= e($block['name'] . ' ' . $block['size']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Target quantity</label>
            <input type="number" name="target_quantity" min="1" required>
        </div>
        <div>
            <label>Target date</label>
            <input type="date" name="target_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="2"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save target</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Target performance</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Block</th>
                <th>Date</th>
                <th>Target</th>
                <th>Achieved</th>
                <th>Status</th>
                <?php if ($canEdit): ?><th class="no-print"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$targets): ?>
                <tr><td colspan="<?= $canEdit ? 6 : 5 ?>">No production targets created yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($targets as $target): ?>
            <tr>
                <td><?= e($target['name'] . ' ' . $target['size']) ?></td>
                <td><?= format_date($target['target_date']) ?></td>
                <td><?= number_format((int) $target['target_quantity']) ?></td>
                <td><?= number_format((int) $target['achieved']) ?></td>
                <td>
                    <?php $pct = $target['target_quantity'] > 0 ? ((int) $target['achieved'] / (int) $target['target_quantity']) * 100 : 0; ?>
                    <span class="badge badge-<?= $pct >= 100 ? 'success' : ($pct >= 75 ? 'warning' : 'danger') ?>"><?= number_format($pct, 1) ?>%</span>
                </td>
                <?php if ($canEdit): ?>
                <td class="no-print">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this target?');">
                        <input type="hidden" name="delete_target" value="<?= (int) $target['id'] ?>">
                        <button type="submit" class="btn-sm" style="background:#c0392b;border:0;color:#fff;cursor:pointer;">Delete</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
