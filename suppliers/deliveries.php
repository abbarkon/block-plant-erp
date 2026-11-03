<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('suppliers', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS supplier_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    purchase_id INT NULL,
    delivery_date DATE NOT NULL,
    expected_date DATE NULL,
    quantity INT DEFAULT 0,
    item_name VARCHAR(150) NULL,
    status VARCHAR(25) DEFAULT 'pending',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_delivery'])) {
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $purchaseId = isset($_POST['purchase_id']) && $_POST['purchase_id'] !== '' ? (int) $_POST['purchase_id'] : null;
    $date = $_POST['delivery_date'] ?? date('Y-m-d');
    $expected = $_POST['expected_date'] ?? null;
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $itemName = trim($_POST['item_name'] ?? '');
    $status = $_POST['status'] ?? 'pending';
    $notes = trim($_POST['notes'] ?? '');

    if ($supplierId > 0 && $quantity > 0) {
        $stmt = $pdo->prepare("INSERT INTO supplier_deliveries(supplier_id, purchase_id, delivery_date, expected_date, quantity, item_name, status, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$supplierId, $purchaseId, $date, $expected, $quantity, $itemName, $status, $notes, $_SESSION['user_id']]);
        set_flash('success', 'Delivery recorded.');
        header('Location: deliveries.php');
        exit;
    }
}

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE status=1 ORDER BY name")->fetchAll();
$purchases = $pdo->query("SELECT * FROM purchases ORDER BY purchase_date DESC LIMIT 50")->fetchAll();
$deliveries = $pdo->query("SELECT sd.*, s.name AS supplier_name, p.purchase_no FROM supplier_deliveries sd JOIN suppliers s ON s.id = sd.supplier_id LEFT JOIN purchases p ON p.id = sd.purchase_id ORDER BY sd.delivery_date DESC, sd.id DESC LIMIT 50")->fetchAll();
$flash = get_flash();
$pageTitle = 'Supplier Deliveries';
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Supplier Deliveries</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track inbound deliveries, delays, and receiving status.</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to suppliers</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-success"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <h3>Record delivery</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_delivery" value="1">
        <div>
            <label>Supplier</label>
            <select name="supplier_id" required>
                <option value="">Select supplier</option>
                <?php foreach ($suppliers as $supplier): ?>
                <option value="<?= (int) $supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Purchase order</label>
            <select name="purchase_id">
                <option value="">Optional</option>
                <?php foreach ($purchases as $purchase): ?>
                <option value="<?= (int) $purchase['id'] ?>"><?= e($purchase['purchase_no']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Delivery date</label>
            <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
            <label>Expected date</label>
            <input type="date" name="expected_date">
        </div>
        <div>
            <label>Item name</label>
            <input type="text" name="item_name" placeholder="Material or item">
        </div>
        <div>
            <label>Quantity</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="pending">Pending</option>
                <option value="received">Received</option>
                <option value="partial">Partial</option>
                <option value="delayed">Delayed</option>
            </select>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save delivery</button>
        </div>
    </form>
</div>

<div class="panel">
    <h3>Inbound delivery log</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Supplier</th>
                <th>PO</th>
                <th>Item</th>
                <th>Delivery</th>
                <th>Qty</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$deliveries): ?>
                <tr><td colspan="6">No deliveries recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($deliveries as $delivery): ?>
            <tr>
                <td><?= e($delivery['supplier_name']) ?></td>
                <td><?= e($delivery['purchase_no'] ?: '-') ?></td>
                <td><?= e($delivery['item_name'] ?: '-') ?></td>
                <td><?= format_date($delivery['delivery_date']) ?></td>
                <td><?= number_format((int) $delivery['quantity']) ?></td>
                <td>
                    <span class="badge badge-<?= $delivery['status'] === 'received' ? 'success' : ($delivery['status'] === 'partial' ? 'warning' : ($delivery['status'] === 'delayed' ? 'danger' : 'info')) ?>"><?= e(ucfirst($delivery['status'])) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
