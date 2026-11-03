<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'edit');

$pdo->exec("CREATE TABLE IF NOT EXISTS dispatches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NULL,
    customer_id INT NULL,
    dispatch_date DATE NOT NULL,
    vehicle_no VARCHAR(50) NULL,
    destination VARCHAR(150) NULL,
    quantity INT DEFAULT 0,
    status VARCHAR(25) DEFAULT 'scheduled',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$dispatchStatuses = ['scheduled', 'in_transit', 'delivered', 'delayed'];
$saleExists = function (int $id) use ($pdo): bool {
    $stmt = $pdo->prepare("SELECT id FROM sales WHERE id = ?");
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dispatch'])) {
    $saleId = isset($_POST['sale_id']) && $_POST['sale_id'] !== '' ? (int) $_POST['sale_id'] : null;
    $customerId = isset($_POST['customer_id']) && $_POST['customer_id'] !== '' ? (int) $_POST['customer_id'] : null;
    $dispatchDate = (string) ($_POST['dispatch_date'] ?? date('Y-m-d'));
    $vehicleNo = trim($_POST['vehicle_no'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'scheduled');
    $notes = trim($_POST['notes'] ?? '');

    if ($quantity <= 0) {
        set_flash('error', 'Dispatch quantity must be at least 1.');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dispatchDate)) {
        set_flash('error', 'Choose a valid dispatch date.');
    } elseif (!in_array($status, $dispatchStatuses, true)) {
        set_flash('error', 'Invalid dispatch status.');
    } elseif ($saleId !== null && !$saleExists($saleId)) {
        set_flash('error', 'Selected invoice does not exist.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO dispatches(sale_id, customer_id, dispatch_date, vehicle_no, destination, quantity, status, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$saleId, $customerId, $dispatchDate, $vehicleNo, $destination, $quantity, $status, $notes, $_SESSION['user_id']]);
        log_activity('create', 'inventory', (int) $pdo->lastInsertId(), "Dispatch scheduled: qty {$quantity} to " . ($destination ?: 'unspecified'));
        set_flash('success', 'Dispatch record saved.');
    }
    header('Location: dispatch.php');
    exit;
}

// Advance / correct a dispatch status (tracking a load is the whole point of this page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispatch_status'])) {
    $dispatchId = (int) ($_POST['dispatch_id'] ?? 0);
    $newStatus = (string) ($_POST['dispatch_status'] ?? '');
    if (!in_array($newStatus, $dispatchStatuses, true)) {
        set_flash('error', 'Invalid dispatch status.');
    } else {
        $chk = $pdo->prepare("SELECT id FROM dispatches WHERE id = ?");
        $chk->execute([$dispatchId]);
        if (!$chk->fetchColumn()) {
            set_flash('error', 'Dispatch not found.');
        } else {
            $pdo->prepare("UPDATE dispatches SET status = ? WHERE id = ?")->execute([$newStatus, $dispatchId]);
            log_activity('update', 'inventory', $dispatchId, "Dispatch status set to {$newStatus}");
            set_flash('success', 'Dispatch status updated.');
        }
    }
    header('Location: dispatch.php');
    exit;
}

// Remove a mis-keyed dispatch row
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dispatch'])) {
    $dispatchId = (int) ($_POST['dispatch_id'] ?? 0);
    if (!has_permission('inventory', 'delete')) {
        set_flash('error', 'You do not have permission to delete dispatch records.');
    } else {
        $chk = $pdo->prepare("SELECT id FROM dispatches WHERE id = ?");
        $chk->execute([$dispatchId]);
        if (!$chk->fetchColumn()) {
            set_flash('error', 'Dispatch not found.');
        } else {
            $pdo->prepare("DELETE FROM dispatches WHERE id = ?")->execute([$dispatchId]);
            log_activity('delete', 'inventory', $dispatchId, "Dispatch record deleted");
            set_flash('success', 'Dispatch record deleted.');
        }
    }
    header('Location: dispatch.php');
    exit;
}

$customers = $pdo->query("SELECT * FROM customers WHERE status=1 ORDER BY name")->fetchAll();
$sales = $pdo->query("SELECT * FROM sales ORDER BY sale_date DESC LIMIT 50")->fetchAll();
$dispatches = $pdo->query("SELECT d.*, c.name AS customer_name, s.invoice_no FROM dispatches d LEFT JOIN customers c ON c.id = d.customer_id LEFT JOIN sales s ON s.id = d.sale_id ORDER BY d.dispatch_date DESC, d.id DESC LIMIT 50")->fetchAll();
$flash = get_flash();
$pageTitle = 'Dispatch Tracking';
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Dispatch Tracking</h1>
        <p style="color:#64748b; margin:6px 0 0;">Manage vehicle dispatches, delivery schedules, and customer outbound loads.</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to inventory</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <h3>Schedule dispatch</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_dispatch" value="1">
        <div>
            <label>Invoice / sale</label>
            <select name="sale_id">
                <option value="">Optional</option>
                <?php foreach ($sales as $sale): ?>
                <option value="<?= (int) $sale['id'] ?>"><?= e($sale['invoice_no']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Customer</label>
            <select name="customer_id">
                <option value="">Select customer</option>
                <?php foreach ($customers as $customer): ?>
                <option value="<?= (int) $customer['id'] ?>"><?= e($customer['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Dispatch date</label>
            <input type="date" name="dispatch_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
            <label>Vehicle no.</label>
            <input type="text" name="vehicle_no" placeholder="e.g. T-100">
        </div>
        <div>
            <label>Destination</label>
            <input type="text" name="destination" placeholder="Delivery location">
        </div>
        <div>
            <label>Quantity</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="scheduled">Scheduled</option>
                <option value="in_transit">In Transit</option>
                <option value="delivered">Delivered</option>
                <option value="delayed">Delayed</option>
            </select>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save dispatch</button>
        </div>
    </form>
</div>

<div class="panel">
    <h3>Dispatch schedule</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Destination</th>
                <th>Vehicle</th>
                <th>Qty</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$dispatches): ?>
                <tr><td colspan="8">No dispatch schedules created yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($dispatches as $dispatch): ?>
            <tr>
                <td><?= format_date($dispatch['dispatch_date']) ?></td>
                <td><?= e($dispatch['invoice_no'] ?: '-') ?></td>
                <td><?= e($dispatch['customer_name'] ?: '-') ?></td>
                <td><?= e($dispatch['destination'] ?: '-') ?></td>
                <td><?= e($dispatch['vehicle_no'] ?: '-') ?></td>
                <td><?= number_format((int) $dispatch['quantity']) ?></td>
                <td>
                    <span class="badge badge-<?= $dispatch['status'] === 'delivered' ? 'success' : ($dispatch['status'] === 'delayed' ? 'danger' : ($dispatch['status'] === 'in_transit' ? 'warning' : 'info')) ?>"><?= e(ucfirst($dispatch['status'])) ?></span>
                </td>
                <td style="white-space: nowrap;">
                    <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                        <input type="hidden" name="dispatch_id" value="<?= (int) $dispatch['id'] ?>">
                        <select name="dispatch_status" style="padding:4px 6px; font-size:0.8rem;">
                            <?php foreach ($dispatchStatuses as $st): ?>
                            <option value="<?= $st ?>" <?= $dispatch['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $st))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_dispatch_status" value="1" class="btn btn-sm btn-secondary">Update</button>
                    </form>
                    <?php if (has_permission('inventory', 'delete')): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this dispatch record?');">
                        <input type="hidden" name="dispatch_id" value="<?= (int) $dispatch['id'] ?>">
                        <button type="submit" name="delete_dispatch" value="1" class="btn btn-sm" style="background:#dc3545; color:#fff;">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
