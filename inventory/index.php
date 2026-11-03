<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');

$pageTitle = "Finished Stock";
$canProduce = has_permission('production', 'create');

// Get finished stock with block type details
$stmt = $pdo->query("
    SELECT 
        fs.*,
        bt.name as block_name,
        bt.size,
        bt.selling_price,
        (fs.quantity * bt.selling_price) as stock_value,
        (fs.quantity * fs.average_unit_cost) as cost_value
    FROM finished_stock fs
    JOIN block_types bt ON fs.block_type_id = bt.id
    WHERE bt.status = 1
    ORDER BY bt.name, bt.size
");
$finished_stock = $stmt->fetchAll();

// Calculate totals: retail = what it would sell for, cost = what it cost us to make
$total_quantity = array_sum(array_column($finished_stock, 'quantity'));
$total_value = array_sum(array_column($finished_stock, 'stock_value'));
$total_cost = array_sum(array_column($finished_stock, 'cost_value'));

// Inline CSV export, same convention as sales/production/purchases
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finished_stock_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Block Type', 'Size', 'Quantity', 'Unit Price (retail)', 'Value (retail)', 'Unit Cost', 'Value (cost)']);
    foreach ($finished_stock as $stock) {
        fputcsv($fh, [
            $stock['block_name'], $stock['size'], $stock['quantity'],
            $stock['selling_price'], $stock['stock_value'],
            $stock['average_unit_cost'], $stock['cost_value'],
        ]);
    }
    fputcsv($fh, ['TOTAL', '', $total_quantity, '', $total_value, '', $total_cost]);
    fclose($fh);
    exit;
}

require_once "../partials/header.php";
?>

<style>
@media print {
    .no-print, .navbar, .sidebar, .topbar { display: none !important; }
    body { background: #fff; }
}
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>📦 Finished Goods Stock</h1>
        <p style="color: #666; margin: 5px 0 0 0;">Current inventory of finished blocks</p>
    </div>
    <div class="no-print" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <?php if ($canProduce): ?>
        <a href="../production/create.php" class="btn">+ New Production</a>
        <?php endif; ?>
        <a href="movement.php" class="btn" style="background: #17a2b8;">Stock Movement</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a href="?export=csv" class="btn btn-secondary">Export CSV</a>
    </div>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Total Block Types</h3>
        <div style="font-size: 32px; font-weight: bold; color: #007bff;"><?= count($finished_stock) ?></div>
    </div>
    
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Total Quantity</h3>
        <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?= number_format($total_quantity) ?></div>
        <div style="font-size: 12px; color: #999;">blocks in stock</div>
    </div>
    
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Value at Cost</h3>
        <div style="font-size: 32px; font-weight: bold; color: #17a2b8;"><?= number_format($total_cost, 2) ?></div>
        <div style="font-size: 12px; color: #999;">weighted-average unit cost</div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Value at Retail</h3>
        <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?= number_format($total_value, 2) ?></div>
        <div style="font-size: 12px; color: #999;">at selling price</div>
    </div>
</div>

<!-- Stock Table -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h2 style="margin: 0 0 15px 0;">Stock Details</h2>
    
    <table>
        <thead>
            <tr>
                <th>Block Type</th>
                <th>Size</th>
                <th>Quantity in Stock</th>
                <th>Unit Price</th>
                <th>Value (retail)</th>
                <th>Unit Cost</th>
                <th>Value (cost)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($finished_stock as $stock): ?>
            <?php
                $status_color = $stock['quantity'] > 100 ? '#28a745' : ($stock['quantity'] > 50 ? '#ffc107' : '#dc3545');
                $status_text = $stock['quantity'] > 100 ? 'Good' : ($stock['quantity'] > 50 ? 'Low' : 'Critical');
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($stock['block_name']) ?></strong>
                </td>
                <td><?= htmlspecialchars($stock['size']) ?></td>
                <td>
                    <strong style="font-size: 18px; color: <?= $status_color ?>;">
                        <?= number_format($stock['quantity']) ?>
                    </strong>
                </td>
                <td><?= number_format($stock['selling_price'], 2) ?></td>
                <td><strong><?= number_format($stock['stock_value'], 2) ?></strong></td>
                <td><?= number_format((float) $stock['average_unit_cost'], 2) ?></td>
                <td><?= number_format((float) $stock['cost_value'], 2) ?></td>
                <td>
                    <span style="padding: 5px 12px; border-radius: 5px; background: <?= $status_color ?>; color: white; font-weight: bold; font-size: 11px;">
                        <?= $status_text ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if (empty($finished_stock)): ?>
            <tr>
                <td colspan="8" style="text-align: center; color: #888; padding: 30px;">
                    No finished goods in stock. Start production to add stock.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
        
        <?php if (!empty($finished_stock)): ?>
        <tfoot style="background: #f8f9fa; font-weight: bold; font-size: 16px;">
            <tr>
                <td colspan="2">TOTAL</td>
                <td><?= number_format($total_quantity) ?></td>
                <td></td>
                <td><?= number_format($total_value, 2) ?></td>
                <td></td>
                <td><?= number_format($total_cost, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<div style="background: #e7f3ff; padding: 20px; border-radius: 8px; margin-top: 20px;">
    <h3 style="margin: 0 0 10px 0;">💡 Stock Management Tips</h3>
    <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
        <li>Monitor stock levels daily to avoid stockouts</li>
        <li>Use <strong>Production Planning</strong> to maintain optimal stock levels</li>
        <li>Check <strong>Stock Movement</strong> for complete transaction history</li>
        <li>Stock is automatically updated when you record production or sales</li>
    </ul>
</div>

<?php require_once "../partials/footer.php"; ?>
