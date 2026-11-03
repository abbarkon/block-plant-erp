<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_system_admin();

$pdo->exec("CREATE TABLE IF NOT EXISTS fleet_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_name VARCHAR(150) NOT NULL,
    registration_no VARCHAR(50) NULL,
    type VARCHAR(50) DEFAULT 'vehicle',
    assigned_to VARCHAR(100) NULL,
    status VARCHAR(25) DEFAULT 'active',
    last_service DATE NULL,
    next_service DATE NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fleet_asset'])) {
    $name = trim($_POST['asset_name'] ?? '');
    if ($name !== '') {
        $stmt = $pdo->prepare("INSERT INTO fleet_assets(asset_name, registration_no, type, assigned_to, status, last_service, next_service, notes) VALUES(?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $name,
            trim($_POST['registration_no'] ?? ''),
            $_POST['type'] ?? 'vehicle',
            trim($_POST['assigned_to'] ?? ''),
            $_POST['status'] ?? 'active',
            $_POST['last_service'] ?? null,
            $_POST['next_service'] ?? null,
            trim($_POST['notes'] ?? '')
        ]);
        set_flash('success', 'Fleet asset saved.');
        header('Location: fleet.php');
        exit;
    }
}

$fleet = $pdo->query("SELECT * FROM fleet_assets ORDER BY next_service ASC, asset_name")->fetchAll();
$flash = get_flash();
$pageTitle = 'Fleet Management';
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Fleet Management</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track vehicles, assignments, and maintenance readiness.</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-success"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <h3>Add fleet asset</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_fleet_asset" value="1">
        <div>
            <label>Asset name</label>
            <input type="text" name="asset_name" placeholder="Truck / trailer / machine" required>
        </div>
        <div>
            <label>Registration / plate</label>
            <input type="text" name="registration_no" placeholder="ABC-123">
        </div>
        <div>
            <label>Type</label>
            <select name="type">
                <option value="vehicle">Vehicle</option>
                <option value="machine">Machine</option>
                <option value="equipment">Equipment</option>
            </select>
        </div>
        <div>
            <label>Assigned to</label>
            <input type="text" name="assigned_to" placeholder="Driver / operator">
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="active">Active</option>
                <option value="maintenance">Maintenance</option>
                <option value="idle">Idle</option>
                <option value="retired">Retired</option>
            </select>
        </div>
        <div>
            <label>Last service</label>
            <input type="date" name="last_service">
        </div>
        <div>
            <label>Next service</label>
            <input type="date" name="next_service">
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save asset</button>
        </div>
    </form>
</div>

<div class="panel">
    <h3>Fleet overview</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Asset</th>
                <th>Type</th>
                <th>Assigned</th>
                <th>Next Service</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$fleet): ?>
                <tr><td colspan="5">No fleet assets recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($fleet as $item): ?>
            <tr>
                <td><?= e($item['asset_name']) ?> <?= e($item['registration_no'] ? '(' . $item['registration_no'] . ')' : '') ?></td>
                <td><?= e(ucfirst($item['type'])) ?></td>
                <td><?= e($item['assigned_to'] ?: '-') ?></td>
                <td><?= $item['next_service'] ? format_date($item['next_service']) : '-' ?></td>
                <td>
                    <span class="badge badge-<?= $item['status'] === 'active' ? 'success' : ($item['status'] === 'maintenance' ? 'warning' : ($item['status'] === 'idle' ? 'info' : 'danger')) ?>"><?= e(ucfirst($item['status'])) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
