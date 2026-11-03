<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('production', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS production_quality_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    block_type_id INT NOT NULL,
    production_id INT NULL,
    check_date DATE NOT NULL,
    batch_no VARCHAR(50) NULL,
    inspector VARCHAR(100) NULL,
    dimensions_ok TINYINT(1) DEFAULT 0,
    strength_ok TINYINT(1) DEFAULT 0,
    visual_ok TINYINT(1) DEFAULT 0,
    finished_ok TINYINT(1) DEFAULT 0,
    result VARCHAR(20) DEFAULT 'pending',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pageTitle = 'Production Quality';
$canEdit = has_permission('production', 'create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_quality_check'])) {
    // Recording inspections is a write action — do not let view-only users post.
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to record quality inspections.');
        header('Location: quality.php');
        exit;
    }
    $blockTypeId = (int) ($_POST['block_type_id'] ?? 0);
    $productionId = isset($_POST['production_id']) && $_POST['production_id'] !== '' ? (int) $_POST['production_id'] : null;
    $checkDate = $_POST['check_date'] ?? date('Y-m-d');
    $batchNo = trim($_POST['batch_no'] ?? '');
    $inspector = trim($_POST['inspector'] ?? '');
    $dimensionsOk = !empty($_POST['dimensions_ok']) ? 1 : 0;
    $strengthOk = !empty($_POST['strength_ok']) ? 1 : 0;
    $visualOk = !empty($_POST['visual_ok']) ? 1 : 0;
    $finishedOk = !empty($_POST['finished_ok']) ? 1 : 0;
    $result = in_array($_POST['result'] ?? '', ['pass', 'fail', 'pending'], true) ? $_POST['result'] : 'pending';
    $notes = trim($_POST['notes'] ?? '');

    if ($blockTypeId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
        $stmt = $pdo->prepare("INSERT INTO production_quality_checks(block_type_id, production_id, check_date, batch_no, inspector, dimensions_ok, strength_ok, visual_ok, finished_ok, result, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$blockTypeId, $productionId, $checkDate, $batchNo, $inspector, $dimensionsOk, $strengthOk, $visualOk, $finishedOk, $result, $notes, $_SESSION['user_id']]);
        log_activity('create', 'production', $blockTypeId, "Quality check {$result} on {$checkDate}");
        set_flash('success', 'Quality check saved.');
        header('Location: quality.php');
        exit;
    }
    set_flash('error', 'Choose a block type and a valid check date.');
    header('Location: quality.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_check'])) {
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to delete quality inspections.');
        header('Location: quality.php');
        exit;
    }
    $checkId = (int) $_POST['delete_check'];
    $del = $pdo->prepare("DELETE FROM production_quality_checks WHERE id = ?");
    $del->execute([$checkId]);
    if ($del->rowCount() > 0) {
        log_activity('delete', 'production', $checkId, 'Quality check deleted');
        set_flash('success', 'Quality check deleted.');
    } else {
        set_flash('error', 'Quality check not found.');
    }
    header('Location: quality.php');
    exit;
}

$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$productionRows = $pdo->query("SELECT id, production_no, production_date, good_quantity FROM production ORDER BY production_date DESC LIMIT 50")->fetchAll();
$checks = $pdo->query("SELECT q.*, bt.name, bt.size FROM production_quality_checks q JOIN block_types bt ON bt.id = q.block_type_id ORDER BY q.check_date DESC, q.id DESC LIMIT 50")->fetchAll();
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Production Quality</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track quality checks, batch inspection, and pass/fail outcomes.</p>
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
    <h3>Record quality inspection</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_quality_check" value="1">
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
            <label>Production batch</label>
            <select name="production_id">
                <option value="">Optional</option>
                <?php foreach ($productionRows as $row): ?>
                <option value="<?= (int) $row['id'] ?>"><?= e($row['production_no'] . ' - ' . $row['good_quantity'] . ' pcs') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Check date</label>
            <input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
            <label>Batch no.</label>
            <input type="text" name="batch_no" placeholder="Batch ID">
        </div>
        <div>
            <label>Inspector</label>
            <input type="text" name="inspector" placeholder="Name">
        </div>
        <div>
            <label>Result</label>
            <select name="result">
                <option value="pass">Pass</option>
                <option value="fail">Fail</option>
                <option value="pending" selected>Pending</option>
            </select>
        </div>
        <div>
            <label><input type="checkbox" name="dimensions_ok" value="1"> Dimensions OK</label>
        </div>
        <div>
            <label><input type="checkbox" name="strength_ok" value="1"> Strength OK</label>
        </div>
        <div>
            <label><input type="checkbox" name="visual_ok" value="1"> Visual OK</label>
        </div>
        <div>
            <label><input type="checkbox" name="finished_ok" value="1"> Finished Quality OK</label>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save inspection</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Recent quality checks</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Block</th>
                <th>Date</th>
                <th>Batch</th>
                <th>Inspector</th>
                <th>Checks</th>
                <th>Result</th>
                <?php if ($canEdit): ?><th class="no-print"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$checks): ?>
                <tr><td colspan="<?= $canEdit ? 7 : 6 ?>">No quality inspections recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($checks as $check): ?>
            <tr>
                <td><?= e($check['name'] . ' ' . $check['size']) ?></td>
                <td><?= format_date($check['check_date']) ?></td>
                <td><?= e($check['batch_no'] ?: '-') ?></td>
                <td><?= e($check['inspector'] ?: '-') ?></td>
                <td>
                    <?= (int) $check['dimensions_ok'] + (int) $check['strength_ok'] + (int) $check['visual_ok'] + (int) $check['finished_ok'] ?>/4
                </td>
                <td>
                    <span class="badge badge-<?= $check['result'] === 'pass' ? 'success' : ($check['result'] === 'fail' ? 'danger' : 'warning') ?>"><?= ucfirst(e($check['result'])) ?></span>
                </td>
                <?php if ($canEdit): ?>
                <td class="no-print">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this quality check?');">
                        <input type="hidden" name="delete_check" value="<?= (int) $check['id'] ?>">
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
