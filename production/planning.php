<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";require_login();
require_permission('production', 'view');
$pageTitle = "Production Planning";
require_once "../partials/header.php";

// Get current stock levels
$stock = $pdo->query("SELECT fs.*, bt.name, bt.size FROM finished_stock fs JOIN block_types bt ON fs.block_type_id=bt.id ORDER BY bt.name, bt.size")->fetchAll();

// Get recent sales to predict demand
$sales_analysis = $pdo->query("SELECT bt.id, bt.name, bt.size, SUM(si.quantity) as total_sold, AVG(si.quantity) as avg_per_sale FROM sale_items si JOIN block_types bt ON si.block_type_id=bt.id JOIN sales s ON si.sale_id=s.id WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY bt.id ORDER BY total_sold DESC")->fetchAll();

// Material availability
$materials = $pdo->query("SELECT * FROM raw_materials WHERE status=1")->fetchAll();
?>

<div class="page-header">
    <h1>Production Planning</h1>
    <div class="header-actions">
        <a href="analytics.php" class="btn btn-accent">Analytics</a>
        <a href="schedule.php" class="btn btn-primary">Schedule</a>
        <a href="bom.php" class="btn btn-secondary">BOM</a>
        <a href="create.php" class="btn btn-secondary">+ New run</a>
    </div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
    <div class="panel"><h3 style="margin-top:0;">Daily output (14d)</h3><canvas id="chartProductionDaily" height="160"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;">By block type (30d)</h3><canvas id="chartProductionByBlock" height="160"></canvas></div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#4f4fc9;">📦</div>
        <div><div class="stat-value"><?= number_format(array_sum(array_column($stock, 'quantity'))) ?></div><div class="stat-label">Finished stock</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2f7d4f;">🧱</div>
        <div><div class="stat-value"><?= count($materials) ?></div><div class="stat-label">Raw materials</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f39c12;">📈</div>
        <div><div class="stat-value"><?= number_format(array_sum(array_column($sales_analysis, 'total_sold'))) ?></div><div class="stat-label">30-day sales</div></div>
    </div>
</div>

<div class="table-container" style="margin-bottom:20px;">
    <h3 style="padding:16px 16px 0;margin:0;">Current stock status</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Block Type</th>
                <th>Size</th>
                <th>Current Stock</th>
                <th>30-Day Sales</th>
                <th>Avg/Sale</th>
                <th>Days of Stock</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($stock as $item):
                $sales_data = array_filter($sales_analysis, fn($s) => $s['id'] == $item['block_type_id']);
                $sales_data = reset($sales_data);
                $total_sold = $sales_data['total_sold'] ?? 0;
                $avg_sold = $sales_data['avg_per_sale'] ?? 0;
                $daily_rate = $total_sold / 30;
                $days_of_stock = $daily_rate > 0 ? $item['quantity'] / $daily_rate : 999;
                
                $status_color = $days_of_stock < 7 ? '#f8d7da' : ($days_of_stock < 14 ? '#fff3cd' : '#d4edda');
                $status_text = $days_of_stock < 7 ? 'Critical' : ($days_of_stock < 14 ? 'Low' : 'Good');
            ?>
            <tr style="background: <?= $status_color ?>;">
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td><?= htmlspecialchars($item['size']) ?></td>
                <td><strong><?= number_format($item['quantity']) ?></strong></td>
                <td><?= number_format($total_sold) ?></td>
                <td><?= number_format($avg_sold, 1) ?></td>
                <td><?= number_format($days_of_stock, 1) ?> days</td>
                <td><strong><?= $status_text ?></strong></td>
                <td>
                    <a href="../production/create.php?block_type=<?= $item['block_type_id'] ?>" class="btn btn-sm">Produce</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="table-container">
    <h3 style="padding:16px 16px 0;margin:0;">Material availability</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Material</th>
                <th>Current Stock</th>
                <th>Unit</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($materials as $mat): 
                $stock_status = $mat['current_stock'] > 100 ? 'Good' : ($mat['current_stock'] > 50 ? 'Low' : 'Critical');
                $color = $mat['current_stock'] > 100 ? '#d4edda' : ($mat['current_stock'] > 50 ? '#fff3cd' : '#f8d7da');
            ?>
            <tr style="background: <?= $color ?>;">
                <td><?= htmlspecialchars($mat['name']) ?></td>
                <td><strong><?= number_format($mat['current_stock'], 2) ?></strong></td>
                <td><?= htmlspecialchars($mat['unit']) ?></td>
                <td><?= $stock_status ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 20px; padding: 20px; background: #e7f3ff; border-radius: 8px;">
    <h3>📋 Planning Recommendations</h3>
    <ul style="line-height: 1.8;">
        <li><strong>Priority Production:</strong> Focus on block types with less than 7 days of stock</li>
        <li><strong>Material Orders:</strong> Check materials with low stock before production</li>
        <li><strong>Demand Forecast:</strong> Based on 30-day sales trends</li>
        <li><strong>Safety Stock:</strong> Maintain at least 14 days of stock for popular items</li>
    </ul>
</div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/production_charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
