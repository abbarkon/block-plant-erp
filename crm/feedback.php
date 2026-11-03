<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('crm', 'view');

$pageTitle = t('feedback');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('crm', 'create')) {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $saleId = (int) ($_POST['sale_id'] ?? 0);
    $feedbackDate = trim((string) ($_POST['feedback_date'] ?? ''));
    $rating = min(5, max(1, (int) ($_POST['rating'] ?? 0)));
    $productRating = min(5, max(1, (int) ($_POST['product_quality_rating'] ?? 0)));
    $serviceRating = min(5, max(1, (int) ($_POST['service_rating'] ?? 0)));
    $deliveryRating = min(5, max(1, (int) ($_POST['delivery_rating'] ?? 0)));
    $parsedDate = DateTime::createFromFormat('Y-m-d', $feedbackDate);

    if ($customerId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $feedbackDate) {
        set_flash('error', 'Select a customer and a valid feedback date.');
        header('Location: feedback.php');
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO customer_feedback(customer_id, sale_id, feedback_date, rating, product_quality_rating, service_rating, delivery_rating, comments, action_taken) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $customerId,
        $saleId > 0 ? $saleId : null,
        $feedbackDate,
        $rating,
        $productRating,
        $serviceRating,
        $deliveryRating,
        trim($_POST['comments'] ?? ''),
        trim($_POST['action_taken'] ?? ''),
    ]);
    log_activity('create', 'feedback', (int) $pdo->lastInsertId(), 'Customer feedback recorded');
    set_flash('success', 'Customer feedback recorded.');
    header('Location: feedback.php');
    exit;
}

$customerFilter = (int) ($_GET['customer_id'] ?? 0);
$minRating = (int) ($_GET['rating'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($customerFilter > 0) {
    $where[] = "cf.customer_id = ?";
    $params[] = $customerFilter;
}
if ($minRating >= 1 && $minRating <= 5) {
    $where[] = "cf.rating >= ?";
    $params[] = $minRating;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "cf.feedback_date >= ?";
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "cf.feedback_date <= ?";
    $params[] = $to;
}
if ($q !== '') {
    $where[] = "(cf.comments LIKE ? OR cf.action_taken LIKE ? OR c.name LIKE ?)";
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$baseSql = "FROM customer_feedback cf JOIN customers c ON cf.customer_id = c.id LEFT JOIN sales s ON cf.sale_id = s.id";

$stmt = $pdo->prepare("SELECT cf.*, c.name AS customer_name, s.invoice_no $baseSql $whereSql ORDER BY cf.feedback_date DESC, cf.id DESC LIMIT 500");
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

$kpiStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, ROUND(AVG(cf.rating),1) AS overall, ROUND(AVG(cf.product_quality_rating),1) AS product, ROUND(AVG(cf.service_rating),1) AS service, ROUND(AVG(cf.delivery_rating),1) AS delivery $baseSql $whereSql");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch() ?: ['cnt' => 0, 'overall' => null, 'product' => null, 'service' => null, 'delivery' => null];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_feedback_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Customer', 'Invoice', 'Overall', 'Product', 'Service', 'Delivery', 'Comments', 'Action Taken']);
    foreach ($feedbacks as $f) {
        fputcsv($out, [$f['feedback_date'], $f['customer_name'], $f['invoice_no'] ?? '', (int) $f['rating'], (int) $f['product_quality_rating'], (int) $f['service_rating'], (int) $f['delivery_rating'], $f['comments'] ?? '', $f['action_taken'] ?? '']);
    }
    fclose($out);
    exit;
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE status=1 ORDER BY name")->fetchAll();
$sales = $pdo->query("SELECT id, invoice_no FROM sales ORDER BY id DESC LIMIT 50")->fetchAll();
$flash = get_flash();

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'feedback.php?' . http_build_query($exportQuery);

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('feedback') ?></h1>
    <div class="header-actions">
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#3498db;">📝</div><div><div class="stat-value"><?= (int) $kpi['cnt'] ?></div><div class="stat-label">Responses</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#f39c12;">⭐</div><div><div class="stat-value"><?= $kpi['overall'] !== null ? e($kpi['overall']) . '/5' : '—' ?></div><div class="stat-label">Overall rating</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#9b59b6;">📦</div><div><div class="stat-value"><?= $kpi['product'] !== null ? e($kpi['product']) : '—' ?></div><div class="stat-label">Product quality</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#00897b;">🤝</div><div><div class="stat-value"><?= $kpi['service'] !== null ? e($kpi['service']) : '—' ?></div><div class="stat-label">Service</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e67e22;">🚚</div><div><div class="stat-value"><?= $kpi['delivery'] !== null ? e($kpi['delivery']) : '—' ?></div><div class="stat-label">Delivery</div></div></div>
</div>

<?php if (has_permission('crm', 'create')): ?>
<div class="panel">
    <h3>Record feedback</h3>
    <form method="post">
        <div class="grid-2">
            <div>
                <label>Customer *</label>
                <select name="customer_id" required>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Linked sale (optional)</label>
                <select name="sale_id">
                    <option value="">—</option>
                    <?php foreach ($sales as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['invoice_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date *</label>
                <input type="date" name="feedback_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Overall rating (1-5) *</label>
                <input type="number" name="rating" min="1" max="5" value="5" required>
            </div>
            <div>
                <label>Product quality</label>
                <input type="number" name="product_quality_rating" min="1" max="5" value="5">
            </div>
            <div>
                <label>Service</label>
                <input type="number" name="service_rating" min="1" max="5" value="5">
            </div>
            <div>
                <label>Delivery</label>
                <input type="number" name="delivery_rating" min="1" max="5" value="5">
            </div>
        </div>
        <label>Comments</label>
        <textarea name="comments" rows="2"></textarea>
        <label>Action taken</label>
        <textarea name="action_taken" rows="2"></textarea>
        <button type="submit" class="btn btn-accent" style="margin-top:12px;"><?= t('save') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <form method="get" class="filter-form">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Comment or customer">
        <select name="customer_id">
            <option value="">All customers</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $customerFilter === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="rating">
            <option value="">Any rating</option>
            <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= $minRating === $i ? 'selected' : '' ?>><?= $i ?>★ &amp; up</option>
            <?php endfor; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-accent">Filter</button>
        <a href="feedback.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Invoice</th>
                <th>Overall</th>
                <th>Product</th>
                <th>Service</th>
                <th>Delivery</th>
                <th>Comments</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$feedbacks): ?>
            <tr><td colspan="8" style="text-align:center;color:#64748b;padding:24px;">No feedback matches the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($feedbacks as $f): ?>
            <tr>
                <td><?= format_date($f['feedback_date']) ?></td>
                <td><?= e($f['customer_name']) ?></td>
                <td><?= e($f['invoice_no'] ?? '—') ?></td>
                <td><?= str_repeat('★', (int) $f['rating']) ?></td>
                <td><?= (int) $f['product_quality_rating'] ?></td>
                <td><?= (int) $f['service_rating'] ?></td>
                <td><?= (int) $f['delivery_rating'] ?></td>
                <td><?= e($f['comments']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
