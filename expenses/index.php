<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'view');
ensure_expense_approval_schema();

$pageTitle = 'Expenses';

$category = trim($_GET['category'] ?? '');
$method = trim($_GET['payment_method'] ?? '');
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if (!isset(expense_payment_methods()[$method])) {
    $method = '';
}
if (!isset(expense_statuses()[$status])) {
    $status = '';
}

$where = ['e.expense_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($category !== '') {
    $where[] = 'e.category = ?';
    $params[] = $category;
}
if ($method !== '') {
    $where[] = 'e.payment_method = ?';
    $params[] = $method;
}
if ($status !== '') {
    $where[] = 'e.approval_status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(e.voucher_no LIKE ? OR e.description LIKE ? OR e.reference_no LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT e.*, u.name AS created_by_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by {$whereSql} ORDER BY e.expense_date DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total_count,
    COALESCE(SUM(e.amount), 0) AS total_amount,
    COALESCE(SUM(CASE WHEN e.approval_status = 'approved' THEN e.amount ELSE 0 END), 0) AS approved_amount,
    COALESCE(SUM(CASE WHEN e.approval_status = 'pending' THEN e.amount ELSE 0 END), 0) AS pending_amount,
    COALESCE(SUM(CASE WHEN e.approval_status = 'approved' AND e.payment_method = 'cash' THEN e.amount ELSE 0 END), 0) AS cash_amount
    FROM expenses e {$whereSql}");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$categories = $pdo->query("SELECT DISTINCT category FROM expenses ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$pendingTotalCount = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE approval_status = 'pending'")->fetchColumn();

$filterParams = array_filter([
    'from' => $from,
    'to' => $to,
    'category' => $category,
    'payment_method' => $method,
    'status' => $status,
    'q' => $q,
], static fn($v) => $v !== '');
$exportUrl = 'export_csv.php?' . http_build_query($filterParams);
$pageUrl = static fn($p) => 'index.php?' . http_build_query(array_merge($filterParams, ['page' => $p]));
$hasFilters = $category !== '' || $method !== '' || $status !== '' || $q !== ''
    || $from !== date('Y-m-01') || $to !== date('Y-m-d');

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .exp-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .exp-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .exp-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .exp-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .exp-kpi strong { display:block; margin-top:4px; color:#183b56; font-size:1.3rem; }
    .exp-kpi.pending { border-left-color:#b45309; }
    .exp-kpi.approved { border-left-color:#2a9d8f; }
    .exp-desc { max-width:340px; }
    .pager { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:14px; }
    .pager a, .pager span { padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0; background:#fff; color:#183b56; text-decoration:none; font-size:.82rem; }
    .pager span.current { background:#183b56; color:#fff; border-color:#183b56; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .exp-kpi, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>📉 Expenses</h1>
        <p style="color:#64748b;margin:6px 0 0;">Track plant operating costs, approvals, and cash/bank payments.</p>
    </div>
    <div class="header-actions no-print">
        <?php if (has_permission('expenses', 'create')): ?>
        <a class="btn btn-accent" href="create.php">+ New expense</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="approvals.php">
            Approvals<?= $pendingTotalCount > 0 ? ' <span class="badge badge-warning">' . $pendingTotalCount . '</span>' : '' ?>
        </a>
        <a class="btn btn-secondary" href="recurring.php">Recurring costs</a>
        <a class="btn btn-secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="exp-kpis">
    <div class="exp-kpi"><span>Filtered expenses</span><strong><?= e(currency_symbol()) . money($summary['total_amount']) ?></strong><small style="color:#64748b;"><?= number_format((int) $summary['total_count']) ?> vouchers</small></div>
    <div class="exp-kpi approved"><span>Approved / posted</span><strong><?= e(currency_symbol()) . money($summary['approved_amount']) ?></strong></div>
    <div class="exp-kpi pending"><span>Pending approval</span><strong><?= e(currency_symbol()) . money($summary['pending_amount']) ?></strong></div>
    <div class="exp-kpi"><span>Cash paid (approved)</span><strong><?= e(currency_symbol()) . money($summary['cash_amount']) ?></strong></div>
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <input type="text" name="q" placeholder="Search voucher, description, reference" value="<?= e($q) ?>" style="min-width:200px;">
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $item): ?>
            <option value="<?= e($item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e($item) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="payment_method">
            <option value="">All payment methods</option>
            <?php foreach (expense_payment_methods() as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $method === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (expense_statuses() as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="index.php" class="btn btn-secondary">Reset</a><?php endif; ?>
    </form>
</div>

<p style="color:#64748b;font-size:.84rem;margin:0 0 10px;">
    Showing <?= number_format(count($rows)) ?> of <?= number_format($totalRows) ?> matching vouchers.
</p>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Voucher</th>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th style="text-align:right;">Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Submitted by</th>
                <th>Reference</th>
                <th class="no-print">View</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?><tr><td colspan="10">No expenses match the selected filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <?php $appr = $r['approval_status'] ?? 'approved'; ?>
            <tr>
                <td><a href="view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['voucher_no']) ?></strong></a></td>
                <td style="white-space:nowrap;"><?= e(format_date($r['expense_date'])) ?></td>
                <td><?= e($r['category']) ?></td>
                <td class="exp-desc"><?= e($r['description']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($r['amount']) ?></td>
                <td><span class="badge badge-info"><?= e(expense_payment_methods()[$r['payment_method']] ?? ucfirst($r['payment_method'])) ?></span></td>
                <td><span class="badge badge-<?= $appr === 'approved' ? 'success' : ($appr === 'rejected' ? 'danger' : 'warning') ?>"><?= e(ucfirst($appr)) ?></span></td>
                <td><?= e($r['created_by_name'] ?? '—') ?></td>
                <td><?= e($r['reference_no'] ?? '') ?></td>
                <td class="no-print">
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a class="btn btn-sm btn-secondary" href="view.php?id=<?= (int) $r['id'] ?>">Open</a>
                        <?php if (has_permission('expenses', 'edit')): ?>
                        <a class="btn btn-sm btn-secondary" href="edit.php?id=<?= (int) $r['id'] ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (has_permission('expenses', 'delete')): ?>
                        <form method="post" action="save.php" style="display:inline;" onsubmit="return confirm('Delete this expense voucher?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:#c0392b;border:0;color:#fff;cursor:pointer;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager no-print">
    <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">← Previous</a><?php endif; ?>
    <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="<?= e($pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= e($pageUrl($page + 1)) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
