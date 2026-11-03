<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";require_login();
require_system_admin();
$pageTitle = "Dashboard Widgets";
require_once "../partials/header.php";

// Top selling products
$top_products = $pdo->query("
    SELECT bt.name, bt.size, SUM(si.quantity) as total_sold, SUM(si.total_price) as total_revenue
    FROM sale_items si
    JOIN block_types bt ON si.block_type_id=bt.id
    JOIN sales s ON si.sale_id=s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY bt.id
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll();

// Recent customers
$recent_customers = $pdo->query("
    SELECT c.name, COUNT(s.id) as order_count, SUM(s.total_amount) as total_spent, MAX(s.sale_date) as last_order
    FROM customers c
    JOIN sales s ON c.id=s.customer_id
    WHERE c.status=1
    GROUP BY c.id
    ORDER BY last_order DESC
    LIMIT 10
")->fetchAll();

// Production efficiency trend (last 7 days)
$production_trend = $pdo->query("
    SELECT production_date, 
           SUM(planned_quantity) as planned,
           SUM(produced_quantity) as produced,
           SUM(good_quantity) as good,
           SUM(rejected_quantity) as rejected
    FROM production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY production_date
    ORDER BY production_date DESC
")->fetchAll();

// Monthly comparison
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));

$current_sales = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?");
$current_sales->execute([$current_month]);
$current_month_sales = $current_sales->fetchColumn();

$last_sales = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE_FORMAT(sale_date,'%Y-%m')=?");
$last_sales->execute([$last_month]);
$last_month_sales = $last_sales->fetchColumn();

$sales_growth = $last_month_sales > 0 ? (($current_month_sales - $last_month_sales) / $last_month_sales) * 100 : 0;
?>

<h1>📊 Advanced Dashboard Widgets</h1>

<div class="grid-2" style="margin-bottom:20px;">
    <div class="panel"><h3><?= t('sales_trend') ?></h3><canvas id="chartSalesTrend" height="180"></canvas></div>
    <div class="panel"><h3><?= t('top_products') ?></h3><canvas id="chartTopProducts" height="180"></canvas></div>
</div>

<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
    <!-- Top Selling Products -->
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3>🏆 Top Selling Products (Last 30 Days)</h3>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($top_products as $prod): ?>
                <tr>
                    <td><?= htmlspecialchars($prod['name']) ?> (<?= htmlspecialchars($prod['size']) ?>)</td>
                    <td><?= number_format($prod['total_sold']) ?></td>
                    <td><strong><?= number_format($prod['total_revenue'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Sales Growth -->
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3>📈 Sales Growth</h3>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 15px;">
            <div style="padding: 15px; background: #e7f3ff; border-radius: 5px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Last Month</div>
                <div style="font-size: 24px; font-weight: bold; color: #007bff;"><?= number_format($last_month_sales, 2) ?></div>
                <div style="font-size: 12px; color: #666;"><?= date('M Y', strtotime($last_month)) ?></div>
            </div>
            <div style="padding: 15px; background: #d4edda; border-radius: 5px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">This Month</div>
                <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?= number_format($current_month_sales, 2) ?></div>
                <div style="font-size: 12px; color: #666;"><?= date('M Y', strtotime($current_month)) ?></div>
            </div>
        </div>
        <div style="margin-top: 15px; padding: 15px; background: <?= $sales_growth >= 0 ? '#d4edda' : '#f8d7da' ?>; border-radius: 5px; text-align: center;">
            <div style="font-size: 14px; color: #666;">Growth Rate</div>
            <div style="font-size: 32px; font-weight: bold; color: <?= $sales_growth >= 0 ? '#28a745' : '#dc3545' ?>;">
                <?= $sales_growth >= 0 ? '↑' : '↓' ?> <?= number_format(abs($sales_growth), 1) ?>%
            </div>
        </div>
    </div>
</div>

<!-- Production Trend -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <h3>🏭 Production Efficiency Trend (Last 7 Days)</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Planned</th>
                <th>Produced</th>
                <th>Good Quality</th>
                <th>Rejected</th>
                <th>Efficiency %</th>
                <th>Quality %</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_planned = 0;
            $total_produced = 0;
            $total_good = 0;
            $total_rejected = 0;
            
            foreach($production_trend as $day): 
                $efficiency = $day['planned'] > 0 ? ($day['produced'] / $day['planned']) * 100 : 0;
                $quality = $day['produced'] > 0 ? ($day['good'] / $day['produced']) * 100 : 0;
                
                $total_planned += $day['planned'];
                $total_produced += $day['produced'];
                $total_good += $day['good'];
                $total_rejected += $day['rejected'];
            ?>
            <tr>
                <td><?= date('D, d M', strtotime($day['production_date'])) ?></td>
                <td><?= number_format($day['planned']) ?></td>
                <td><?= number_format($day['produced']) ?></td>
                <td style="color: #28a745;"><strong><?= number_format($day['good']) ?></strong></td>
                <td style="color: #dc3545;"><?= number_format($day['rejected']) ?></td>
                <td>
                    <span style="padding: 3px 8px; border-radius: 3px; background: <?= $efficiency >= 90 ? '#d4edda' : ($efficiency >= 70 ? '#fff3cd' : '#f8d7da') ?>;">
                        <?= number_format($efficiency, 1) ?>%
                    </span>
                </td>
                <td>
                    <span style="padding: 3px 8px; border-radius: 3px; background: <?= $quality >= 95 ? '#d4edda' : ($quality >= 85 ? '#fff3cd' : '#f8d7da') ?>;">
                        <?= number_format($quality, 1) ?>%
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background: #f8f9fa; font-weight: bold;">
                <td>TOTALS</td>
                <td><?= number_format($total_planned) ?></td>
                <td><?= number_format($total_produced) ?></td>
                <td style="color: #28a745;"><?= number_format($total_good) ?></td>
                <td style="color: #dc3545;"><?= number_format($total_rejected) ?></td>
                <td><?= $total_planned > 0 ? number_format(($total_produced/$total_planned)*100, 1) : '0' ?>%</td>
                <td><?= $total_produced > 0 ? number_format(($total_good/$total_produced)*100, 1) : '0' ?>%</td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Recent Customers -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h3>👥 Active Customers</h3>
    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th>Orders</th>
                <th>Total Spent</th>
                <th>Last Order</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($recent_customers as $cust): 
                $days_since = (strtotime('today') - strtotime($cust['last_order'])) / 86400;
                $status = $days_since <= 7 ? 'Active' : ($days_since <= 30 ? 'Regular' : 'Inactive');
                $color = $days_since <= 7 ? '#d4edda' : ($days_since <= 30 ? '#fff3cd' : '#f8d7da');
            ?>
            <tr>
                <td><?= htmlspecialchars($cust['name']) ?></td>
                <td><?= $cust['order_count'] ?></td>
                <td><strong><?= number_format($cust['total_spent'], 2) ?></strong></td>
                <td><?= date('d M Y', strtotime($cust['last_order'])) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $color ?>;">
                        <?= $status ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/charts.php"; ?>
<?php require_once "../partials/footer.php"; ?>
