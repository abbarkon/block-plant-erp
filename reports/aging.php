<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

$pageTitle = "Aging Reports";

$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
if (!is_valid_date_string($asOf)) {
    $asOf = date('Y-m-d');
}
$view = ($_GET['view'] ?? 'summary') === 'detail' ? 'detail' : 'summary';

function aging_category($days) {
    if ($days <= 30) return 'current';
    if ($days <= 60) return '31_60';
    if ($days <= 90) return '61_90';
    return '90_plus';
}
function aging_bucket_label($key) {
    return ['current' => '0-30', '31_60' => '31-60', '61_90' => '61-90', '90_plus' => '90+'][$key] ?? $key;
}

// --- Customer receivables (open balances) -----------------------------------
$arStmt = $pdo->prepare("SELECT s.id AS sale_id, c.id AS customer_id, c.name AS entity, s.invoice_no, s.sale_date, s.total_amount, s.balance,
        DATEDIFF(?, s.sale_date) AS days_overdue
    FROM sales s JOIN customers c ON s.customer_id = c.id
    WHERE s.balance > 0 AND s.sale_date <= ?
    ORDER BY days_overdue DESC");
$arStmt->execute([$asOf, $asOf]);
$arDetail = $arStmt->fetchAll();

// --- Supplier payables (open balances) --------------------------------------
$apStmt = $pdo->prepare("SELECT sup.id AS supplier_id, sup.name AS entity, p.purchase_no AS doc_no, p.purchase_date AS doc_date, p.total_amount, p.balance,
        DATEDIFF(?, p.purchase_date) AS days_overdue
    FROM purchases p JOIN suppliers sup ON p.supplier_id = sup.id
    WHERE p.balance > 0 AND p.purchase_date <= ?
    ORDER BY days_overdue DESC");
$apStmt->execute([$asOf, $asOf]);
$apDetail = $apStmt->fetchAll();

function aging_summary(array $rows) {
    $buckets = ['current' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0];
    $entities = [];
    foreach ($rows as $r) {
        $cat = aging_category((int) $r['days_overdue']);
        $key = $r['entity'];
        if (!isset($entities[$key])) {
            $entities[$key] = ['entity' => $key, 'current' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '90_plus' => 0.0, 'total' => 0.0];
        }
        $amount = (float) $r['balance'];
        $entities[$key][$cat] += $amount;
        $entities[$key]['total'] += $amount;
        $buckets[$cat] += $amount;
    }
    usort($entities, static function ($a, $b) { return $b['total'] <=> $a['total']; });
    $grand = array_sum($buckets);
    return ['entities' => $entities, 'buckets' => $buckets, 'grand' => $grand];
}

$ar = aging_summary($arDetail);
$ap = aging_summary($apDetail);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aging_' . $asOf . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Aging report as of ' . $asOf]);
    fputcsv($out, []);
    fputcsv($out, ['Accounts Receivable by customer']);
    fputcsv($out, ['Customer', '0-30', '31-60', '61-90', '90+', 'Total']);
    foreach ($ar['entities'] as $e) {
        fputcsv($out, [$e['entity'], $e['current'], $e['31_60'], $e['61_90'], $e['90_plus'], $e['total']]);
    }
    fputcsv($out, ['TOTAL', $ar['buckets']['current'], $ar['buckets']['31_60'], $ar['buckets']['61_90'], $ar['buckets']['90_plus'], $ar['grand']]);
    fputcsv($out, []);
    fputcsv($out, ['Accounts Payable by supplier']);
    fputcsv($out, ['Supplier', '0-30', '31-60', '61-90', '90+', 'Total']);
    foreach ($ap['entities'] as $e) {
        fputcsv($out, [$e['entity'], $e['current'], $e['31_60'], $e['61_90'], $e['90_plus'], $e['total']]);
    }
    fputcsv($out, ['TOTAL', $ap['buckets']['current'], $ap['buckets']['31_60'], $ap['buckets']['61_90'], $ap['buckets']['90_plus'], $ap['grand']]);
    fclose($out);
    exit;
}

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'aging.php?' . http_build_query($exportQuery);

require_once "../partials/header.php";
?>

<style>
    @media print {
        .no-print, .navbar, .sidebar, .topbar { display: none !important; }
        .table-container { box-shadow: none; }
        body { background: #fff; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1>⏳ Aging Reports</h1>
        <p style="color:#64748b;margin:4px 0 0;">Open receivables and payables by age as of <?= e(date('d M Y', strtotime($asOf))) ?>.</p>
    </div>
    <div class="header-actions">
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
    </div>
</div>

<div class="panel no-print">
    <form method="get" class="filter-form">
        <input type="date" name="as_of" value="<?= e($asOf) ?>">
        <select name="view">
            <option value="summary" <?= $view === 'summary' ? 'selected' : '' ?>>Summary by account</option>
            <option value="detail" <?= $view === 'detail' ? 'selected' : '' ?>>Invoice / purchase detail</option>
        </select>
        <button type="submit" class="btn btn-accent">Apply</button>
        <a href="aging.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="stats-grid no-print">
    <div class="stat-card"><div class="stat-icon" style="background:#e67e22;">📥</div><div><div class="stat-value"><?= currency_symbol() . money($ar['grand']) ?></div><div class="stat-label">Total receivables</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#dc3545;">⏰</div><div><div class="stat-value"><?= currency_symbol() . money($ar['buckets']['90_plus']) ?></div><div class="stat-label">Receivables 90+ days</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#9b59b6;">📤</div><div><div class="stat-value"><?= currency_symbol() . money($ap['grand']) ?></div><div class="stat-label">Total payables</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#64748b;">⏰</div><div><div class="stat-value"><?= currency_symbol() . money($ap['buckets']['90_plus']) ?></div><div class="stat-label">Payables 90+ days</div></div></div>
</div>

<?php
function render_aging_table($title, $label, $data, $currency) {
    ?>
    <div class="panel">
        <h3 style="margin-top:0;"><?= e($title) ?></h3>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e($label) ?></th>
                        <th style="text-align:right;">0-30</th>
                        <th style="text-align:right;">31-60</th>
                        <th style="text-align:right;">61-90</th>
                        <th style="text-align:right;">90+</th>
                        <th style="text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$data['entities']): ?>
                    <tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px;">Nothing outstanding.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($data['entities'] as $e): ?>
                    <tr>
                        <td><strong><?= e($e['entity']) ?></strong></td>
                        <td style="text-align:right;"><?= $e['current'] > 0 ? $currency . money($e['current']) : '—' ?></td>
                        <td style="text-align:right;"><?= $e['31_60'] > 0 ? $currency . money($e['31_60']) : '—' ?></td>
                        <td style="text-align:right;<?= $e['61_90'] > 0 ? 'color:#b45309;' : '' ?>"><?= $e['61_90'] > 0 ? $currency . money($e['61_90']) : '—' ?></td>
                        <td style="text-align:right;<?= $e['90_plus'] > 0 ? 'color:#b91c1c;font-weight:600;' : '' ?>"><?= $e['90_plus'] > 0 ? $currency . money($e['90_plus']) : '—' ?></td>
                        <td style="text-align:right;"><strong><?= $currency . money($e['total']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f8fafc;font-weight:700;">
                        <td>TOTAL</td>
                        <td style="text-align:right;"><?= $currency . money($data['buckets']['current']) ?></td>
                        <td style="text-align:right;"><?= $currency . money($data['buckets']['31_60']) ?></td>
                        <td style="text-align:right;"><?= $currency . money($data['buckets']['61_90']) ?></td>
                        <td style="text-align:right;"><?= $currency . money($data['buckets']['90_plus']) ?></td>
                        <td style="text-align:right;"><?= $currency . money($data['grand']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

$cur = currency_symbol();
render_aging_table('Customer Receivables Aging', 'Customer', $ar, $cur);
render_aging_table('Supplier Payables Aging', 'Supplier', $ap, $cur);

if ($view === 'detail'):
?>
<div class="panel">
    <h3 style="margin-top:0;">Receivables detail <span class="badge badge-muted"><?= count($arDetail) ?></span></h3>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Customer</th><th>Invoice</th><th>Date</th><th>Total</th><th>Balance</th><th>Days</th><th>Aging</th></tr></thead>
            <tbody>
                <?php if (!$arDetail): ?><tr><td colspan="7" style="text-align:center;color:#64748b;padding:20px;">No open invoices.</td></tr><?php endif; ?>
                <?php foreach ($arDetail as $r): $cat = aging_category((int) $r['days_overdue']); ?>
                <tr>
                    <td><a href="customer_statement.php?customer_id=<?= (int) $r['customer_id'] ?>"><?= e($r['entity']) ?></a></td>
                    <td><a href="../sales/view.php?id=<?= (int) $r['sale_id'] ?>"><?= e($r['invoice_no']) ?></a></td>
                    <td><?= e(date('d M Y', strtotime($r['sale_date']))) ?></td>
                    <td><?= $cur . money($r['total_amount']) ?></td>
                    <td><?= $cur . money($r['balance']) ?></td>
                    <td><?= (int) $r['days_overdue'] ?></td>
                    <td><span class="badge badge-<?= $cat === 'current' ? 'success' : ($cat === '90_plus' ? 'danger' : 'warning') ?>"><?= e(aging_bucket_label($cat)) ?> days</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <h3 style="margin-top:0;">Payables detail <span class="badge badge-muted"><?= count($apDetail) ?></span></h3>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Supplier</th><th>Purchase</th><th>Date</th><th>Total</th><th>Balance</th><th>Days</th><th>Aging</th></tr></thead>
            <tbody>
                <?php if (!$apDetail): ?><tr><td colspan="7" style="text-align:center;color:#64748b;padding:20px;">No open purchase orders.</td></tr><?php endif; ?>
                <?php foreach ($apDetail as $r): $cat = aging_category((int) $r['days_overdue']); ?>
                <tr>
                    <td><?= e($r['entity']) ?></td>
                    <td><?= e($r['doc_no']) ?></td>
                    <td><?= e(date('d M Y', strtotime($r['doc_date']))) ?></td>
                    <td><?= $cur . money($r['total_amount']) ?></td>
                    <td><?= $cur . money($r['balance']) ?></td>
                    <td><?= (int) $r['days_overdue'] ?></td>
                    <td><span class="badge badge-<?= $cat === 'current' ? 'success' : ($cat === '90_plus' ? 'danger' : 'warning') ?>"><?= e(aging_bucket_label($cat)) ?> days</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
