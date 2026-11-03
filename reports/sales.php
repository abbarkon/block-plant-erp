<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

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
$customer_id = (int) ($_GET['customer_id'] ?? 0);

$pageTitle = "Sales Reports";
require_once "../partials/header.php";

$sql = "SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id=c.id WHERE s.sale_date BETWEEN ? AND ?";
$params = [$from, $to];

if($customer_id > 0) {
    $sql .= " AND s.customer_id=?";
    $params[] = $customer_id;
}

$sql .= " ORDER BY s.sale_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$customers = $pdo->query("SELECT * FROM customers WHERE status=1 ORDER BY name")->fetchAll();

$total_sales = array_sum(array_column($sales, 'total_amount'));
$total_paid = array_sum(array_column($sales, 'paid_amount'));
$total_balance = array_sum(array_column($sales, 'balance'));
?>

<h1>📊 Sales Reports</h1>

<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
        <div>
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div>
            <label>Customer</label>
            <select name="customer_id">
                <option value="">All Customers</option>
                <?php foreach($customers as $cust): ?>
                <option value="<?= $cust['id'] ?>" <?= $customer_id == $cust['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cust['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn">Filter</button>
        </div>
    </div>
</form>

<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Total Sales</h3>
        <div style="font-size: 28px; font-weight: bold; color: #28a745;"><?= number_format($total_sales, 2) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Total Received</h3>
        <div style="font-size: 28px; font-weight: bold; color: #007bff;"><?= number_format($total_paid, 2) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Outstanding</h3>
        <div style="font-size: 28px; font-weight: bold; color: #dc3545;"><?= number_format($total_balance, 2) ?></div>
    </div>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 10px; flex-wrap: wrap;">
        <h3>Sales Transactions</h3>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="../reports/export_csv.php?type=sales&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&customer_id=<?= urlencode((string) $customer_id) ?>" class="btn btn-secondary">Export CSV</a>
            <button onclick="exportExcel()" class="btn btn-secondary"><?= t('export_excel') ?></button>
            <button onclick="exportPDF()" class="btn btn-secondary"><?= t('export_pdf') ?></button>
            <button onclick="window.print()" class="btn">Print Report</button>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice No</th>
                <th>Customer</th>
                <th>Total Amount</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($sales as $sale): ?>
            <tr>
                <td><?= date('d M Y', strtotime($sale['sale_date'])) ?></td>
                <td><?= htmlspecialchars($sale['invoice_no']) ?></td>
                <td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in') ?></td>
                <td><?= number_format($sale['total_amount'], 2) ?></td>
                <td><?= number_format($sale['paid_amount'], 2) ?></td>
                <td><?= number_format($sale['balance'], 2) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $sale['payment_status'] === 'paid' ? '#d4edda' : ($sale['payment_status'] === 'partial' ? '#fff3cd' : '#f8d7da') ?>;">
                        <?= ucfirst($sale['payment_status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script>
function exportExcel() {
    var wb = XLSX.utils.table_to_book(document.querySelector('table'));
    XLSX.writeFile(wb, 'Sales_Report.xlsx');
}
function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.text("Sales Report", 14, 15);
    doc.autoTable({ html: 'table', startY: 20 });
    doc.save('Sales_Report.pdf');
}
</script>

<?php require_once "../partials/footer.php"; ?>
