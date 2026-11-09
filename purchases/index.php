<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();require_permission('purchases', 'view');
ensure_purchase_payment_schema();
$pageTitle = t('purchase_orders');

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$approval = trim((string) ($_GET['approval'] ?? ''));
$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = "(p.purchase_no LIKE ? OR s.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status !== '') {
    $conditions[] = "p.payment_status = ?";
    $params[] = $status;
}
if ($approval !== '') {
    $conditions[] = "COALESCE(p.approval_status, 'pending') = ?";
    $params[] = $approval;
}
if ($supplierId > 0) {
    $conditions[] = "p.supplier_id = ?";
    $params[] = $supplierId;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $conditions[] = "p.purchase_date >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $conditions[] = "p.purchase_date <= ?";
    $params[] = $to;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "
    SELECT p.*, s.name AS supplier_name
    FROM purchases p
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    $where
    ORDER BY p.purchase_date DESC, p.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status=1 ORDER BY name")->fetchAll();
// Rejected purchases are not real purchases: keep them out of every total.
$liveRows = array_filter($rows, function ($r) { return ($r['approval_status'] ?? 'pending') !== 'rejected'; });

$monthSpend = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND (approval_status IS NULL OR approval_status <> 'rejected')")->fetchColumn();
$payables = (float) $pdo->query("SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance>0")->fetchColumn();
$pendingSched = 0;
try {
    $pendingSched = (int) $pdo->query("SELECT COUNT(*) FROM purchase_schedules WHERE status IN ('planned','ordered') AND schedule_date >= CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    $pendingSched = 0;
}

require_once "../partials/header.php";
?>

<div class="module-hero purchasing-hero">
    <div><span class="module-eyebrow"><?= t('supply_chain') ?></span><h1><?= t('purchase_orders') ?></h1><p><?= t('purchasing_intro') ?></p></div>
    <div class="header-actions no-print">
        <a href="create.php" class="btn btn-accent"><?= t('new_po') ?></a>
        <a href="planning.php" class="btn btn-primary"><?= t('planning') ?></a>
        <a href="analytics.php" class="btn btn-secondary"><?= t('analytics') ?></a>
    </div>
</div>

<div class="module-kpis no-print">
    <div class="module-kpi">
        <div class="stat-icon" style="background:#b8481b;">📦</div>
        <div><div class="stat-value"><?= number_format((int) count($rows)) ?></div><div class="stat-label">POs shown</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#2f7d4f;">💰</div>
        <div><div class="stat-value"><?= currency_symbol() . money(array_sum(array_column($liveRows, 'total_amount'))) ?></div><div class="stat-label">Total value</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#e74c3c;">📮</div>
        <div><div class="stat-value"><?= currency_symbol() . money(array_sum(array_map(function ($r) { return (float) $r['balance']; }, $liveRows))) ?></div><div class="stat-label">Payables</div></div>
    </div>
    <div class="module-kpi">
        <div class="stat-icon" style="background:#f39c12;">💳</div>
        <div><div class="stat-value"><?= currency_symbol() . money(array_sum(array_column($liveRows, 'paid_amount'))) ?></div><div class="stat-label">Paid</div></div>
    </div>
</div>

<div class="header-actions no-print" style="margin-bottom:16px;">
    <a href="schedule.php" class="btn btn-secondary"><?= t('schedule') ?></a>
    <a href="supplier_prices.php" class="btn btn-secondary"><?= t('supplier_prices') ?></a>
    <a href="../payments/supplier.php" class="btn btn-secondary"><?= t('supplier_payments') ?></a>
    <?php
        $exportQuery = $_GET;
        $exportQuery['export'] = 'csv';
        $exportUrl = 'export_csv.php?' . http_build_query($exportQuery);
    ?>
    <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary">Print</button>
</div>

<div class="panel no-print">
    <form method="get" class="filter-form">
        <input type="text" name="search" placeholder="Search PO # or supplier" value="<?= e($search) ?>">
        <select name="supplier_id">
            <option value="">All suppliers</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $supplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All payment statuses</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
            <option value="credit" <?= $status === 'credit' ? 'selected' : '' ?>>Credit</option>
        </select>
        <select name="approval">
            <option value="">All approvals</option>
            <option value="pending" <?= $approval === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $approval === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= $approval === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-accent">Apply</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="module-section-heading"><div><h2><?= t('recent_purchase_orders') ?></h2><p><?= t('latest_purchase_commitments') ?></p></div><span class="badge badge-info"><?= count($rows) ?> <?= t('records') ?></span></div>
<div class="table-container module-table">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= t('purchase_number') ?> #</th>
                <th><?= t('date') ?></th>
                <th><?= t('supplier') ?></th>
                <th><?= t('total') ?></th>
                <th><?= t('paid') ?></th>
                <th><?= t('balance') ?></th>
                <th><?= t('payment') ?></th>
                <th><?= t('approval') ?></th>
                <th class="no-print"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['purchase_no']) ?></strong></td>
                <td><?= format_date($r['purchase_date']) ?></td>
                <td><?= e($r['supplier_name'] ?? '—') ?></td>
                <td><?= currency_symbol() . money($r['total_amount']) ?></td>
                <td><?= currency_symbol() . money($r['paid_amount']) ?></td>
                <td><?= currency_symbol() . money($r['balance']) ?></td>
                <td><span class="badge badge-info"><?= e(t('status_' . strtolower($r['payment_status']))) ?></span></td>
                <td><span class="badge badge-<?= ($r['approval_status'] ?? 'pending') === 'approved' ? 'success' : (($r['approval_status'] ?? 'pending') === 'rejected' ? 'danger' : 'warning') ?>"><?= e(t('status_' . strtolower($r['approval_status'] ?? 'pending'))) ?></span></td>
                <td class="no-print"><a class="btn-sm" href="view.php?id=<?= (int) $r['id'] ?>"><?= t('view') ?></a>
                    <?php if (($r['approval_status'] ?? 'pending') === 'rejected' && has_permission('purchases', 'delete')): ?>
                    <form method="post" action="delete.php" style="display:inline" onsubmit="return confirm('Delete rejected purchase <?= e($r['purchase_no']) ?>? This cannot be undone.');">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-sm" style="background:#c0392b;color:#fff;border:0;cursor:pointer;">Delete</button>
                    </form>
                    <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
    @media print {
        .no-print, .navbar, .sidebar, .topbar, .header-actions, .filter-form { display: none !important; }
        .module-hero, .module-kpi, .table-container, .module-table { box-shadow: none !important; }
        body { background: #fff; }
    }
.module-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:22px;margin-bottom:24px;padding:28px 32px;border-radius:18px;color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.purchasing-hero{background:linear-gradient(120deg,#1f2933,#b8481b 62%,#d9742f)}.module-hero h1{margin:3px 0 5px;color:#fff;font-size:2.1rem}.module-hero p{margin:0;color:rgba(255,255,255,.76)}.module-eyebrow{font-size:.7rem;font-weight:800;letter-spacing:.14em;color:#bce6df}.module-hero .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);box-shadow:none}.module-hero .btn-accent{background:#e8a33d!important;border-color:#e8a33d}.module-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}.module-kpi{display:flex;align-items:center;gap:14px;padding:19px;background:#fff;border:1px solid #e3ddd4;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);border-top:4px solid #d9742f}.module-kpi .stat-icon{width:46px;height:46px;border-radius:13px;font-size:1.2rem}.module-kpi .stat-value{font-size:1.4rem;color:#1f2933}.module-kpi .stat-label{font-size:.78rem}.module-section-heading{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:28px 0 12px}.module-section-heading h2{margin:0;font-size:1.25rem}.module-section-heading p{margin:4px 0 0;color:#6a6259;font-size:.84rem}.module-table{box-shadow:0 10px 26px rgba(24,59,86,.08)}
@media(max-width:800px){.module-hero{align-items:flex-start;flex-direction:column}.module-hero .header-actions{justify-content:flex-start}}@media(max-width:500px){.module-hero{padding:24px 20px}.module-hero h1{font-size:1.75rem}.module-section-heading{align-items:flex-start;flex-direction:column}}
</style>

<?php require_once "../partials/footer.php"; ?>
