<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('sales', 'edit');
ensure_gate_schema();

$saleId = (int) ($_GET['id'] ?? $_POST['sale_id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();
if (!$sale) {
    render_not_found('Sale not found', 'This invoice does not exist or was removed.', 'index.php', 'Back to sales');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle = trim($_POST['vehicle_plate'] ?? '');
    $driver = trim($_POST['driver_name'] ?? '');
    $phone = trim($_POST['driver_phone'] ?? '');
    if ($vehicle === '') {
        $error = 'Vehicle plate number is required.';
    } else {
        $update = $pdo->prepare("UPDATE sales SET vehicle_plate=?, driver_name=?, driver_phone=? WHERE id=?");
        $update->execute([$vehicle, $driver ?: null, $phone ?: null, $saleId]);
        if ((float) $sale['balance'] <= 0) {
            create_gate_clearance_for_sale($saleId, $_SESSION['user_id']);
        }
        set_flash('success', 'Vehicle details saved. The gate clearance is now available for this paid sale.');
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Update Vehicle Details';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="panel" style="max-width:680px;margin:30px auto;"><h1>Update vehicle details</h1><p style="color:#6a6259;">Invoice <?= e($sale['invoice_no']) ?> · <?= e($sale['customer_name'] ?? 'Walk-in') ?></p><?php if (!empty($error)): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="sale_id" value="<?= $saleId ?>"><label>Vehicle plate number</label><input type="text" name="vehicle_plate" value="<?= e($sale['vehicle_plate'] ?? '') ?>" required maxlength="50"><label>Driver name</label><input type="text" name="driver_name" value="<?= e($sale['driver_name'] ?? '') ?>" maxlength="150"><label>Driver phone</label><input type="text" name="driver_phone" value="<?= e($sale['driver_phone'] ?? '') ?>" maxlength="50"><div style="display:flex;gap:10px;margin-top:18px;"><button class="btn btn-accent" type="submit">Save vehicle</button><a class="btn btn-secondary" href="index.php">Cancel</a></div></form></div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>