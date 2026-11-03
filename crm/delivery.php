<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('sales', 'view');

$pageTitle = t('delivery_notes');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('sales', 'create')) {
    if (isset($_POST['create_delivery'])) {
        $no = 'DN-' . date('YmdHis');
        $stmt = $pdo->prepare("INSERT INTO delivery_notes(delivery_no, sale_id, delivery_date, vehicle_no, driver_name, delivery_address, delivery_status, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $no,
            (int) $_POST['sale_id'],
            $_POST['delivery_date'],
            trim($_POST['vehicle_no'] ?? ''),
            trim($_POST['driver_name'] ?? ''),
            trim($_POST['delivery_address'] ?? ''),
            'pending',
            trim($_POST['notes'] ?? ''),
            $_SESSION['user_id'],
        ]);
        log_activity('create', 'delivery', (int) $pdo->lastInsertId(), "Delivery {$no}");
        header('Location: delivery.php');
        exit;
    }
    if (isset($_POST['update_status']) && has_permission('sales', 'edit')) {
        $stmt = $pdo->prepare("UPDATE delivery_notes SET delivery_status=?, received_by=?, received_date=? WHERE id=?");
        $stmt->execute([
            $_POST['delivery_status'],
            trim($_POST['received_by'] ?? ''),
            $_POST['received_date'] ?: null,
            (int) $_POST['id'],
        ]);
        header('Location: delivery.php');
        exit;
    }
}

$preselectSale = (int) ($_GET['sale_id'] ?? 0);
$sales = $pdo->query("SELECT s.id, s.invoice_no, s.sale_date, c.name AS customer_name, c.address FROM sales s LEFT JOIN customers c ON s.customer_id=c.id ORDER BY s.id DESC LIMIT 100")->fetchAll();

$deliveries = $pdo->query("
    SELECT d.*, s.invoice_no, c.name AS customer_name
    FROM delivery_notes d
    JOIN sales s ON d.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    ORDER BY d.delivery_date DESC, d.id DESC
")->fetchAll();

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM delivery_notes WHERE delivery_status='pending'")->fetchColumn();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('delivery_notes') ?></h1>
    <span class="badge badge-warning"><?= t('pending_deliveries') ?>: <?= $pendingCount ?></span>
</div>

<?php if (has_permission('sales', 'create')): ?>
<div class="panel">
    <h3>Create delivery note</h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="create_delivery" value="1">
        <select name="sale_id" required>
            <option value="">Invoice *</option>
            <?php foreach ($sales as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $preselectSale === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['invoice_no']) ?> — <?= e($s['customer_name'] ?? 'Walk-in') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>" required>
        <input type="text" name="vehicle_no" placeholder="Vehicle no">
        <input type="text" name="driver_name" placeholder="Driver">
        <input type="text" name="delivery_address" placeholder="Delivery address">
        <input type="text" name="notes" placeholder="Notes">
        <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Delivery #</th>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Vehicle / Driver</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($deliveries as $d): ?>
            <tr>
                <td><strong><?= e($d['delivery_no']) ?></strong></td>
                <td><?= e($d['invoice_no']) ?></td>
                <td><?= e($d['customer_name'] ?? '—') ?></td>
                <td><?= format_date($d['delivery_date']) ?></td>
                <td><?= e($d['vehicle_no']) ?> <?= $d['driver_name'] ? '/ ' . e($d['driver_name']) : '' ?></td>
                <td><span class="badge badge-<?= $d['delivery_status'] === 'delivered' ? 'success' : ($d['delivery_status'] === 'pending' ? 'warning' : 'info') ?>"><?= e($d['delivery_status']) ?></span></td>
                <td>
                    <a class="btn-sm" href="delivery_print.php?id=<?= (int) $d['id'] ?>" target="_blank">Print</a>
                    <?php if (has_permission('sales', 'edit')): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                        <select name="delivery_status" onchange="this.form.submit()" style="font-size:0.8rem;">
                            <option value="pending" <?= $d['delivery_status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                            <option value="in_transit" <?= $d['delivery_status'] === 'in_transit' ? 'selected' : '' ?>>in_transit</option>
                            <option value="delivered" <?= $d['delivery_status'] === 'delivered' ? 'selected' : '' ?>>delivered</option>
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
