<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_manufacturing_costing_schema();

$date = $_GET['date'] ?? date('Y-m-d');
if (!is_valid_date_string($date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}
function dc_val(PDO $pdo, string $sql, array $params = []) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        return 0;
    }
}
$notRejected = "(approval_status IS NULL OR approval_status <> 'rejected')";

$salesCount = (int) dc_val($pdo, "SELECT COUNT(*) FROM sales WHERE sale_date=?", [$date]);
$salesTotal = (float) dc_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=?", [$date]);
$received = (float) dc_val($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date=?", [$date]);
$expenses = (float) dc_val($pdo, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=? AND (approval_status='approved' OR approval_status IS NULL)", [$date]);
$purchases = (float) dc_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date=? AND $notRejected", [$date]);
$supplierPaid = (float) dc_val($pdo, "SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE payment_date=?", [$date]);
$cashIn = (float) dc_val($pdo, "SELECT COALESCE(SUM(amount),0) FROM cashbook WHERE transaction_date=? AND transaction_type='income'", [$date]);
$cashOut = (float) dc_val($pdo, "SELECT COALESCE(SUM(amount),0) FROM cashbook WHERE transaction_date=? AND transaction_type='expense'", [$date]);
$prodGood = (int) dc_val($pdo, "SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date=?", [$date]);
$prodRejected = (int) dc_val($pdo, "SELECT COALESCE(SUM(rejected_quantity),0) FROM production WHERE production_date=?", [$date]);
$prodCost = (float) dc_val($pdo, "SELECT COALESCE(SUM(production_cost),0) FROM production WHERE production_date=?", [$date]);
$receivables = (float) dc_val($pdo, "SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0");
$payables = (float) dc_val($pdo, "SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance>0 AND $notRejected");

$stock = [];
try {
    $stock = $pdo->query("SELECT bt.name, bt.size, COALESCE(fs.quantity,0) AS qty, COALESCE(fs.average_unit_cost,0) AS cost
        FROM block_types bt LEFT JOIN finished_stock fs ON fs.block_type_id=bt.id WHERE bt.status=1 ORDER BY bt.name, bt.size")->fetchAll();
} catch (PDOException $e) {}
$topSales = [];
try {
    $st = $pdo->prepare("SELECT s.invoice_no, COALESCE(c.name,'Walk-in') AS customer, s.total_amount, s.paid_amount, s.balance
        FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_date=? ORDER BY s.total_amount DESC LIMIT 10");
    $st->execute([$date]);
    $topSales = $st->fetchAll();
} catch (PDOException $e) {}
$lowStock = (int) dc_val($pdo, "SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id=rl.material_id WHERE rm.current_stock <= rl.reorder_point AND rm.status=1");

$pageTitle = 'Daily Closing Report';
require_once "../partials/header.php";
$cs = currency_symbol();
?>
<div class="page-header">
    <h1>Daily Closing Report</h1>
    <form method="get" class="filter-form no-print" style="display:flex;gap:8px;align-items:center;">
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>">
        <button class="btn btn-secondary" type="submit">Show</button>
        <button class="btn btn-accent" type="button" onclick="window.print()">Print</button>
    </form>
</div>
<p style="color:#62767e;margin:-6px 0 18px;"><?= e(format_date($date)) ?> &middot; <?= e(company_display_name()) ?></p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:18px;">
    <?php
    $cards = [
        ['Sales invoiced', $cs . money($salesTotal), $salesCount . ' invoice(s)'],
        ['Payments received', $cs . money($received), 'from customers today'],
        ['Expenses (approved)', $cs . money($expenses), 'operating costs'],
        ['Purchases', $cs . money($purchases), 'supplier paid: ' . $cs . money($supplierPaid)],
        ['Cash in / out', $cs . money($cashIn) . ' / ' . $cs . money($cashOut), 'net ' . $cs . money($cashIn - $cashOut)],
        ['Blocks produced', number_format($prodGood), 'rejected ' . number_format($prodRejected) . ' &middot; cost ' . $cs . money($prodCost)],
        ['Receivables (all)', $cs . money($receivables), 'owed by customers'],
        ['Payables (all)', $cs . money($payables), 'owed to suppliers'],
    ];
    foreach ($cards as $c): ?>
    <div class="panel" style="padding:16px;">
        <div style="font-size:.76rem;color:#62767e;text-transform:uppercase;letter-spacing:.05em;font-weight:700;"><?= $c[0] ?></div>
        <div style="font-size:1.45rem;font-weight:800;margin:6px 0 2px;"><?= $c[1] ?></div>
        <div style="font-size:.8rem;color:#62767e;"><?= $c[2] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;">
    <div class="panel">
        <h3>Finished stock now</h3>
        <div class="table-container"><table class="data-table">
            <thead><tr><th>Block</th><th style="text-align:right">Qty</th><th style="text-align:right">Value at cost</th></tr></thead>
            <tbody>
            <?php foreach ($stock as $s): ?>
                <tr><td><?= e($s['name'] . ' ' . $s['size']) ?></td><td style="text-align:right"><?= number_format((int) $s['qty']) ?></td><td style="text-align:right"><?= $cs . money($s['qty'] * $s['cost']) ?></td></tr>
            <?php endforeach; if (!$stock): ?><tr><td colspan="3">No block types.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p style="font-size:.85rem;color:#62767e;margin:10px 0 0;">Raw materials below reorder level: <strong><?= $lowStock ?></strong></p>
    </div>
    <div class="panel">
        <h3>Largest sales today</h3>
        <div class="table-container"><table class="data-table">
            <thead><tr><th>Invoice</th><th>Customer</th><th style="text-align:right">Total</th><th style="text-align:right">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($topSales as $r): ?>
                <tr><td><?= e($r['invoice_no']) ?></td><td><?= e($r['customer']) ?></td><td style="text-align:right"><?= $cs . money($r['total_amount']) ?></td><td style="text-align:right"><?= $cs . money($r['balance']) ?></td></tr>
            <?php endforeach; if (!$topSales): ?><tr><td colspan="4">No sales on this date.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<style>@media print{.no-print,.navbar,.sidebar,.topbar{display:none!important}body{background:#fff}}</style>
<?php require_once "../partials/footer.php"; ?>
