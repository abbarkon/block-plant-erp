<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$pageTitle = 'Global Search';
$query = trim($_GET['q'] ?? '');

$customers = [];
$suppliers = [];
$materials = [];
$sales = [];
$purchases = [];
$production = [];

if ($query !== '') {
    $term = '%' . $query . '%';

    $customers = $pdo->prepare("SELECT id, customer_code, name, phone, email, status FROM customers WHERE status = 1 AND (name LIKE ? OR customer_code LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY name LIMIT 10");
    $customers->execute([$term, $term, $term, $term]);
    $customers = $customers->fetchAll();

    $suppliers = $pdo->prepare("SELECT id, supplier_code, name, phone, address FROM suppliers WHERE status = 1 AND (name LIKE ? OR supplier_code LIKE ? OR phone LIKE ? OR address LIKE ?) ORDER BY name LIMIT 10");
    $suppliers->execute([$term, $term, $term, $term]);
    $suppliers = $suppliers->fetchAll();

    $materials = $pdo->prepare("SELECT id, name, unit, current_stock FROM raw_materials WHERE status = 1 AND (name LIKE ? OR unit LIKE ?) ORDER BY name LIMIT 10");
    $materials->execute([$term, $term]);
    $materials = $materials->fetchAll();

    $sales = $pdo->prepare("SELECT s.id, s.invoice_no, s.sale_date, s.total_amount, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.invoice_no LIKE ? OR c.name LIKE ? ORDER BY s.sale_date DESC LIMIT 10");
    $sales->execute([$term, $term]);
    $sales = $sales->fetchAll();

    $purchases = $pdo->prepare("SELECT p.id, p.purchase_no, p.purchase_date, p.total_amount, s.name AS supplier_name FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.purchase_no LIKE ? OR s.name LIKE ? ORDER BY p.purchase_date DESC LIMIT 10");
    $purchases->execute([$term, $term]);
    $purchases = $purchases->fetchAll();

    $production = $pdo->prepare("SELECT p.id, p.production_no, p.production_date, p.good_quantity, bt.name AS block_name FROM production p LEFT JOIN block_types bt ON bt.id = p.block_type_id WHERE p.production_no LIKE ? OR bt.name LIKE ? ORDER BY p.production_date DESC LIMIT 10");
    $production->execute([$term, $term]);
    $production = $production->fetchAll();
}

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Global Search</h1>
</div>

<form method="get" class="panel" style="margin-bottom: 20px;">
    <div class="grid-2" style="align-items: end; gap: 12px;">
        <div>
            <label for="q">Search across the plant</label>
            <input type="text" id="q" name="q" value="<?= e($query) ?>" placeholder="Customer, supplier, invoice, material, order..." required>
        </div>
        <div>
            <button type="submit" class="btn btn-accent">Search</button>
        </div>
    </div>
</form>

<?php if ($query !== ''): ?>
    <?php $hasResults = !empty($customers) || !empty($suppliers) || !empty($materials) || !empty($sales) || !empty($purchases) || !empty($production); ?>

    <?php if (!$hasResults): ?>
        <div class="panel">
            <p>No matches found for <strong><?= e($query) ?></strong>. Try another supplier, invoice number, customer name, or material.</p>
        </div>
    <?php endif; ?>

    <div class="grid-2" style="gap:20px;">
        <?php if (!empty($customers)): ?>
        <div class="panel">
            <h3>Customers</h3>
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Email</th></tr></thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?= e($customer['customer_code']) ?></td>
                        <td><a href="../customers/view.php?id=<?= (int) $customer['id'] ?>"><?= e($customer['name']) ?></a></td>
                        <td><?= e($customer['phone'] ?? '—') ?></td>
                        <td><?= e($customer['email'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($suppliers)): ?>
        <div class="panel">
            <h3>Suppliers</h3>
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Address</th></tr></thead>
                <tbody>
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?= e($supplier['supplier_code']) ?></td>
                        <td><a href="../suppliers/index.php"><?= e($supplier['name']) ?></a></td>
                        <td><?= e($supplier['phone'] ?? '—') ?></td>
                        <td><?= e($supplier['address'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($materials)): ?>
        <div class="panel">
            <h3>Raw Materials</h3>
            <table class="data-table">
                <thead><tr><th>Name</th><th>Unit</th><th>Stock</th></tr></thead>
                <tbody>
                    <?php foreach ($materials as $material): ?>
                    <tr>
                        <td><?= e($material['name']) ?></td>
                        <td><?= e($material['unit']) ?></td>
                        <td><?= number_format((float) $material['current_stock'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($sales)): ?>
        <div class="panel">
            <h3>Sales Invoices</h3>
            <table class="data-table">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><a href="../sales/view.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['invoice_no']) ?></a></td>
                        <td><?= e($sale['customer_name'] ?? 'Walk-in') ?></td>
                        <td><?= e(date('d M Y', strtotime($sale['sale_date']))) ?></td>
                        <td><?= number_format((float) $sale['total_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($purchases)): ?>
        <div class="panel">
            <h3>Purchase Orders</h3>
            <table class="data-table">
                <thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($purchases as $purchase): ?>
                    <tr>
                        <td><a href="../purchases/view.php?id=<?= (int) $purchase['id'] ?>"><?= e($purchase['purchase_no']) ?></a></td>
                        <td><?= e($purchase['supplier_name'] ?? 'N/A') ?></td>
                        <td><?= e(date('d M Y', strtotime($purchase['purchase_date']))) ?></td>
                        <td><?= number_format((float) $purchase['total_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($production)): ?>
        <div class="panel">
            <h3>Production Orders</h3>
            <table class="data-table">
                <thead><tr><th>Number</th><th>Block</th><th>Date</th><th>Good Qty</th></tr></thead>
                <tbody>
                    <?php foreach ($production as $item): ?>
                    <tr>
                        <td><a href="../production/view.php?id=<?= (int) $item['id'] ?>"><?= e($item['production_no']) ?></a></td>
                        <td><?= e($item['block_name'] ?? 'N/A') ?></td>
                        <td><?= e(date('d M Y', strtotime($item['production_date']))) ?></td>
                        <td><?= number_format((float) $item['good_quantity']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
