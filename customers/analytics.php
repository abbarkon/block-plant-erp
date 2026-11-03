<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('customers', 'view');

$pageTitle = "CRM Analytics";

$byCategory = $pdo->query("
    SELECT cc.name, COUNT(c.id) AS customers,
            COALESCE(SUM(s.total_amount), 0) AS revenue
    FROM customer_categories cc
    LEFT JOIN customers c ON c.category_id = cc.id AND c.status = 1
    LEFT JOIN (
        SELECT customer_id, SUM(total_amount) AS total_amount FROM sales GROUP BY customer_id
    ) s ON s.customer_id = c.id
    GROUP BY cc.id
    ORDER BY revenue DESC
")->fetchAll();

$maxRev = max(array_column($byCategory, 'revenue')) ?: 1;

$pipelineStats = $pdo->query("
    SELECT stage, COUNT(*) AS cnt, SUM(estimated_value) AS val
    FROM sales_pipeline WHERE status = 'active'
    GROUP BY stage
")->fetchAll();

$topCustomers = $pdo->query("
    SELECT c.name, SUM(s.total_amount) AS total
    FROM customers c
    JOIN sales s ON s.customer_id = c.id
    GROUP BY c.id
    ORDER BY total DESC
    LIMIT 8
")->fetchAll();

$maxCust = max(array_column($topCustomers, 'total')) ?: 1;

$monthlySales = $pdo->query("
    SELECT DATE_FORMAT(sale_date, '%Y-%m') AS ym, SUM(total_amount) AS total
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
    ORDER BY ym
")->fetchAll();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>CRM Analytics</h1>
    <div class="header-actions">
        <a href="../crm/pipeline.php" class="btn btn-primary">Pipeline</a>
        <a href="../dashboard/widgets.php" class="btn btn-secondary">Advanced widgets</a>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Revenue by customer category</h3>
        <?php foreach ($byCategory as $row): ?>
        <div class="bar-chart-row">
            <span class="label"><?= e($row['name']) ?> (<?= (int) $row['customers'] ?>)</span>
            <div class="bar-wrap">
                <div class="bar-fill" style="width:<?= number_format(($row['revenue'] / $maxRev) * 100, 1) ?>%;"></div>
            </div>
            <span style="font-size:0.85rem;min-width:80px;text-align:right;"><?= currency_symbol() . money($row['revenue']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="panel">
        <h3>Top customers</h3>
        <?php foreach ($topCustomers as $row): ?>
        <div class="bar-chart-row">
            <span class="label"><?= e($row['name']) ?></span>
            <div class="bar-wrap">
                <div class="bar-fill" style="width:<?= number_format(($row['total'] / $maxCust) * 100, 1) ?>%;background:linear-gradient(90deg,#3949ab,#5c6bc0);"></div>
            </div>
            <span style="font-size:0.85rem;"><?= currency_symbol() . money($row['total']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (!$topCustomers): ?>
        <p style="color:#64748b;">No sales data yet.</p>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Active pipeline by stage</h3>
        <?php if (!$pipelineStats): ?>
        <p style="color:#64748b;">No pipeline data. <a href="../crm/pipeline.php">Add opportunities</a>.</p>
        <?php endif; ?>
        <?php foreach ($pipelineStats as $row): ?>
        <p><strong><?= e(ucfirst($row['stage'])) ?></strong>: <?= (int) $row['cnt'] ?> deals · <?= currency_symbol() . money($row['val']) ?></p>
        <?php endforeach; ?>
    </div>
    <div class="panel">
        <h3><?= t('sales_trend') ?></h3>
        <canvas id="chartSalesTrend" height="200"></canvas>
    </div>
</div>

<div class="panel">
    <h3><?= t('top_products') ?></h3>
    <canvas id="chartTopProducts" height="120"></canvas>
</div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
