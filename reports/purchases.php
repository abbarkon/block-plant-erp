<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_purchase_payment_schema();
$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$pageTitle = "Purchase Reports";
require_once "../partials/header.php";

$stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name FROM purchases p LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE p.purchase_date BETWEEN ? AND ? AND (p.approval_status IS NULL OR p.approval_status <> 'rejected') ORDER BY p.purchase_date DESC");
$stmt->execute([$from, $to]);
$purchases = $stmt->fetchAll();

$total = array_sum(array_column($purchases, 'total_amount'));
$paid = array_sum(array_column($purchases, 'paid_amount'));
$balance = array_sum(array_column($purchases, 'balance'));
?>

<div class="page-header">
    <div><h1>📊 Purchase Reports</h1></div>
    <div class="header-actions">
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
    </div>
</div>

<form method="get" class="no-print" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px;">
        <div>
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn">Filter</button>
        </div>
    </div>
</form>

<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Total Purchases</h3>
        <div style="font-size: 28px; font-weight: bold;"><?= number_format($total, 2) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Total Paid</h3>
        <div style="font-size: 28px; font-weight: bold; color: #28a745;"><?= number_format($paid, 2) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Outstanding</h3>
        <div style="font-size: 28px; font-weight: bold; color: #dc3545;"><?= number_format($balance, 2) ?></div>
    </div>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; gap:10px; flex-wrap:wrap;">
        <h3 style="margin:0;">Purchase Transactions</h3>
        <a href="../reports/export_csv.php?type=purchases&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-secondary">Export CSV</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Purchase No</th>
                <th>Supplier</th>
                <th>Total Amount</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($purchases as $purchase): ?>
            <tr>
                <td><?= date('d M Y', strtotime($purchase['purchase_date'])) ?></td>
                <td><?= htmlspecialchars($purchase['purchase_no']) ?></td>
                <td><?= htmlspecialchars($purchase['supplier_name'] ?? 'N/A') ?></td>
                <td><?= number_format($purchase['total_amount'], 2) ?></td>
                <td><?= number_format($purchase['paid_amount'], 2) ?></td>
                <td><?= number_format($purchase['balance'], 2) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $purchase['payment_status'] === 'paid' ? '#d4edda' : ($purchase['payment_status'] === 'partial' ? '#fff3cd' : '#f8d7da') ?>;">
                        <?= ucfirst($purchase['payment_status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
