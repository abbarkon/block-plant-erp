<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('sales', 'view');

$pageTitle = t('commissions');
$co = company_settings();
$defaultRate = (float) ($co['default_commission_rate'] ?? 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('sales', 'create')) {
    if (isset($_POST['add_commission'])) {
        $saleId = (int) $_POST['sale_id'];
        $userId = (int) $_POST['user_id'];
        $pct = max(0, (float) $_POST['commission_percentage']);
        $sale = $pdo->prepare("SELECT total_amount FROM sales WHERE id=?");
        $sale->execute([$saleId]);
        $total = (float) $sale->fetchColumn();
        if ($saleId > 0 && $userId > 0 && $total > 0) {
            $amount = round($total * $pct / 100, 2);
            $pdo->prepare("INSERT INTO sales_commissions(sale_id, user_id, commission_percentage, commission_amount) VALUES(?,?,?,?)")
                ->execute([$saleId, $userId, $pct, $amount]);
            log_activity('create', 'commissions', (int) $pdo->lastInsertId(), "Commission on sale #{$saleId}");
            set_flash('success', 'Commission added.');
        } else {
            set_flash('error', 'Select a valid sale and sales rep.');
        }
        header('Location: commissions.php');
        exit;
    }
    if (isset($_POST['mark_paid']) && has_permission('sales', 'edit')) {
        $pdo->prepare("UPDATE sales_commissions SET payment_status='paid', paid_date=? WHERE id=?")
            ->execute([date('Y-m-d'), (int) $_POST['id']]);
        set_flash('success', 'Commission marked as paid.');
        header('Location: commissions.php');
        exit;
    }
}

$repFilter = (int) ($_GET['rep'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$validStatuses = ['paid', 'pending'];

$where = [];
$params = [];
if ($repFilter > 0) {
    $where[] = "sc.user_id = ?";
    $params[] = $repFilter;
}
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = "sc.payment_status = ?";
    $params[] = $statusFilter;
}
if ($q !== '') {
    $where[] = "s.invoice_no LIKE ?";
    $params[] = '%' . $q . '%';
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "s.sale_date >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "s.sale_date <= ?";
    $params[] = $to;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$baseSql = "FROM sales_commissions sc JOIN sales s ON sc.sale_id = s.id JOIN users u ON sc.user_id = u.id";
$rowSql = "SELECT sc.*, s.invoice_no, s.sale_date, u.name AS user_name $baseSql $whereSql ORDER BY sc.created_at DESC LIMIT 1000";
$stmt = $pdo->prepare($rowSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$kpiStmt = $pdo->prepare("SELECT COALESCE(SUM(sc.commission_amount),0) AS total, COALESCE(SUM(CASE WHEN sc.payment_status='paid' THEN sc.commission_amount ELSE 0 END),0) AS paid, COALESCE(SUM(CASE WHEN sc.payment_status='pending' THEN sc.commission_amount ELSE 0 END),0) AS pending $baseSql $whereSql");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['total' => 0, 'paid' => 0, 'pending' => 0];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissions_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice', 'Sale Date', 'Rep', 'Rate %', 'Amount', 'Status', 'Paid Date']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['invoice_no'], $r['sale_date'], $r['user_name'], (float) $r['commission_percentage'], (float) $r['commission_amount'], $r['payment_status'], $r['paid_date'] ?? '']);
    }
    fclose($out);
    exit;
}

$users = $pdo->query("SELECT id, name FROM users WHERE status=1 ORDER BY name")->fetchAll();
$sales = $pdo->query("SELECT id, invoice_no, total_amount FROM sales ORDER BY id DESC LIMIT 80")->fetchAll();
$flash = get_flash();

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'commissions.php?' . http_build_query($exportQuery);

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('commissions') ?></h1>
    <div class="header-actions">
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
        <a href="../sales/index.php" class="btn btn-secondary"><?= t('sales_invoices') ?></a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#3498db;">💼</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['total']) ?></div><div class="stat-label">Total commission</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#2ecc71;">✅</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['paid']) ?></div><div class="stat-label">Paid</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e67e22;">⏳</div><div><div class="stat-value"><?= currency_symbol() . money($kpi['pending']) ?></div><div class="stat-label"><?= t('commission_due') ?></div></div></div>
</div>

<?php if (has_permission('sales', 'create')): ?>
<div class="panel">
    <h3>Add commission (default rate: <?= number_format($defaultRate, 2) ?>%)</h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="add_commission" value="1">
        <select name="sale_id" required>
            <option value="">Sale invoice</option>
            <?php foreach ($sales as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['invoice_no']) ?> — <?= currency_symbol() . money($s['total_amount']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user_id" required>
            <option value="">Sales rep</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="commission_percentage" value="<?= $defaultRate ?>" required>
        <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <form method="get" class="filter-form">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Invoice no">
        <select name="rep">
            <option value="">All reps</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $repFilter === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-accent">Filter</button>
        <a href="commissions.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Rep</th>
                <th>Rate</th>
                <th>Amount</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b;padding:24px;">No commissions match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="../sales/view.php?id=<?= (int) $r['sale_id'] ?>"><?= e($r['invoice_no']) ?></a></td>
                <td><?= format_date($r['sale_date']) ?></td>
                <td><?= e($r['user_name']) ?></td>
                <td><?= number_format($r['commission_percentage'], 2) ?>%</td>
                <td><strong><?= currency_symbol() . money($r['commission_amount']) ?></strong></td>
                <td><span class="badge badge-<?= $r['payment_status'] === 'paid' ? 'success' : 'warning' ?>"><?= e($r['payment_status']) ?></span></td>
                <td>
                    <?php if ($r['payment_status'] === 'pending' && has_permission('sales', 'edit')): ?>
                    <form method="post">
                        <input type="hidden" name="mark_paid" value="1">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-sm" style="border:0;cursor:pointer;">Mark paid</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
