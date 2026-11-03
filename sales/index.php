<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'view');
ensure_gate_schema();

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$gateFilter = trim((string) ($_GET['gate'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$validStatuses = ['paid', 'partial', 'credit'];
$validGate = ['none', 'WAITING_LOADING', 'READY_FOR_GATE', 'REJECTED', 'EXITED', 'CANCELLED'];

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(s.invoice_no LIKE ? OR c.name LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[] = "s.payment_status = ?";
    $params[] = $status;
}
if ($gateFilter === 'none') {
    $where[] = "gc.id IS NULL";
} elseif (in_array($gateFilter, $validGate, true)) {
    $where[] = "gc.status = ?";
    $params[] = $gateFilter;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "s.sale_date >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "s.sale_date <= ?";
    $params[] = $to;
}

$baseSql = "FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN gate_clearances gc ON gc.id = (SELECT g2.id FROM gate_clearances g2 WHERE g2.sale_id = s.id ORDER BY g2.id DESC LIMIT 1)";
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rowSql = "SELECT s.*, c.name AS customer_name, gc.id AS clearance_id, gc.clearance_number, gc.status AS clearance_status $baseSql $whereSql ORDER BY s.id DESC LIMIT 1000";
$rowStmt = $pdo->prepare($rowSql);
$rowStmt->execute($params);
$rows = $rowStmt->fetchAll();

$kpiStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total_amount), 0) AS total, COALESCE(SUM(s.paid_amount), 0) AS paid, COALESCE(SUM(s.balance), 0) AS balance $baseSql $whereSql");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['cnt' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0];

// CSV export (guarded by sales/view).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_register_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice', 'Date', 'Customer', 'Subtotal', 'Discount', 'Total', 'Paid', 'Balance', 'Payment Status', 'Gate Clearance', 'Gate Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['invoice_no'],
            $r['sale_date'],
            $r['customer_name'] ?? 'Walk-in',
            (float) $r['subtotal'],
            (float) $r['discount'],
            (float) $r['total_amount'],
            (float) $r['paid_amount'],
            (float) $r['balance'],
            $r['payment_status'],
            $r['clearance_number'] ?? '',
            $r['clearance_status'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = "Sales Invoices";
$flash = get_flash();
$createdId = (int) ($_GET['created'] ?? 0);
$createdClearanceId = (int) ($_GET['clearance'] ?? 0);

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'index.php?' . http_build_query($exportQuery);

require_once "../partials/header.php";
?>

<style>
    .sales-filter { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .sales-filter .field { display: flex; flex-direction: column; gap: 4px; }
    .sales-filter label { font-size: .78rem; color: #64748b; font-weight: 600; }
    .sales-filter input, .sales-filter select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
    @media print {
        .no-print, .navbar, .sidebar, .topbar { display: none !important; }
        .table-container { box-shadow: none; }
        body { background: #fff; }
    }
</style>

<div class="page-header no-print">
    <h1>Sales Invoices</h1>
    <div class="header-actions">
        <a href="create.php" class="btn btn-accent">+ New sale</a>
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a href="../crm/delivery.php" class="btn btn-secondary"><?= t('delivery_notes') ?></a>
        <a href="../crm/commissions.php" class="btn btn-secondary"><?= t('commissions') ?></a>
    </div>
</div>

<div class="stats-grid no-print">
    <div class="stat-card"><div class="stat-icon" style="background:#3498db;">🧾</div><div><div class="stat-value"><?= number_format((int) $kpi['cnt']) ?></div><div class="stat-label">Invoices shown</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#9b59b6;">💰</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['total']) ?></div><div class="stat-label">Invoiced value</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#00897b;">✅</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['paid']) ?></div><div class="stat-label">Collected</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e67e22;">⏳</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['balance']) ?></div><div class="stat-label">Outstanding</div></div></div>
</div>

<div class="panel no-print">
    <form method="get" class="sales-filter">
        <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Invoice or customer"></div>
        <div class="field"><label for="status">Payment</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($validStatuses as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label for="gate">Gate</label>
            <select id="gate" name="gate">
                <option value="">All</option>
                <option value="none" <?= $gateFilter === 'none' ? 'selected' : '' ?>>No clearance</option>
                <?php foreach (array_slice($validGate, 1) as $g): ?>
                <option value="<?= e($g) ?>" <?= $gateFilter === $g ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $g)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <button type="submit" class="btn btn-accent">Filter</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<?php if ($flash): ?>
<div class="flash flash-success no-print" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
    <span><?= e($flash['message']) ?></span>
    <?php if ($createdId > 0): ?>
    <a href="invoice_print.php?id=<?= $createdId ?>" target="_blank" class="btn btn-accent">Generate / Print Invoice</a>
    <?php endif; ?>
    <?php if ($createdClearanceId > 0): ?>
    <a href="../gate/clearance_print.php?id=<?= $createdClearanceId ?>" target="_blank" class="btn btn-primary">Print Gate Clearance</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
                <th>Gate</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="9" style="text-align:center;color:#64748b;padding:24px;">No sales match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['invoice_no']) ?></strong></td>
                <td><?= format_date($r['sale_date']) ?></td>
                <td><?= e($r['customer_name'] ?? 'Walk-in') ?></td>
                <td><?= currency_symbol() . money($r['total_amount']) ?></td>
                <td><?= currency_symbol() . money($r['paid_amount']) ?></td>
                <td><?= currency_symbol() . money($r['balance']) ?></td>
                <td><span class="badge badge-<?= $r['payment_status'] === 'paid' ? 'success' : ($r['payment_status'] === 'partial' ? 'warning' : 'danger') ?>"><?= e($r['payment_status']) ?></span></td>
                <td><?php if ($r['clearance_number']): ?><span class="badge <?= $r['clearance_status'] === 'EXITED' ? 'badge-success' : ($r['clearance_status'] === 'READY_FOR_GATE' ? 'badge-info' : ($r['clearance_status'] === 'CANCELLED' || $r['clearance_status'] === 'REJECTED' ? 'badge-danger' : 'badge-warning')) ?>"><?= e(str_replace('_', ' ', $r['clearance_status'])) ?></span><?php elseif ((float) $r['balance'] > 0): ?><span class="badge badge-warning">Payment required</span><?php else: ?><span class="badge badge-muted">Vehicle required</span><?php endif; ?></td>
                <td class="no-print">
                    <a class="btn-sm" href="view.php?id=<?= (int) $r['id'] ?>">View</a>
                    <a class="btn-sm" href="invoice_print.php?id=<?= (int) $r['id'] ?>" target="_blank">Print</a>
                    <a class="btn-sm" href="../crm/delivery.php?sale_id=<?= (int) $r['id'] ?>">Deliver</a>
                    <?php if ((float) $r['balance'] > 0): ?><a class="btn-sm" href="../payments/customer.php?customer_id=<?= (int) ($r['customer_id'] ?? 0) ?>&sale_id=<?= (int) $r['id'] ?>">Record payment</a><?php endif; ?>
                    <?php if (!$r['clearance_number'] && (float) $r['balance'] <= 0): ?><a class="btn-sm" href="vehicle.php?id=<?= (int) $r['id'] ?>">Add vehicle</a><?php endif; ?>
                    <?php if ($r['clearance_number']): ?><a class="btn-sm" href="../gate/clearance_print.php?id=<?= (int) $r['clearance_id'] ?>" target="_blank">Gate pass</a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
