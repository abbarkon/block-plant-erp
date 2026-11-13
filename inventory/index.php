<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');
ensure_production_curing_schema();

$pageTitle = "Finished Stock";
$canProduce = has_permission('production', 'create');
$canAdjust = has_permission('inventory', 'edit');

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

// Curing blocks are part of physical stock but cannot be sold until their
// ready date. Ready = total physical stock - curing.
$total_quantity = 0;
$total_curing = 0;
$total_ready = 0;
$total_value = 0.0;
$total_cost = 0.0;
foreach ($finished_stock as &$stock) {
    $curing = finished_curing_quantity((int) $stock['block_type_id']);
    $stock['curing_quantity'] = $curing;
    $stock['ready_quantity'] = max(0, (int) $stock['quantity'] - $curing);
    $total_quantity += (int) $stock['quantity'];
    $total_curing += $curing;
    $total_ready += (int) $stock['ready_quantity'];
    $total_value += (float) $stock['stock_value'];
    $total_cost += (float) $stock['cost_value'];
}
unset($stock);

// Inline CSV export, same convention as sales/production/purchases
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finished_stock_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Block Type', 'Size', 'Total Quantity', 'Curing (not ready)', 'Ready to Sell', 'Unit Price (retail)', 'Value (retail)', 'Unit Cost', 'Value (cost)']);
    foreach ($finished_stock as $stock) {
        fputcsv($fh, [
            $stock['block_name'], $stock['size'], $stock['quantity'], $stock['curing_quantity'], $stock['ready_quantity'],
            $stock['selling_price'], $stock['stock_value'],
            $stock['average_unit_cost'], $stock['cost_value'],
        ]);
    }
    fputcsv($fh, ['TOTAL', '', $total_quantity, $total_curing, $total_ready, '', $total_value, '', $total_cost]);
    fclose($fh);
    exit;
}

$curingBatches = finished_curing_batches();

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
        <?php if ($canAdjust): ?>
        <a href="opening_stock.php" class="btn" style="background: #6f42c1;">Opening Stock</a>
        <a href="curing_start.php" class="btn" style="background: #e67e22;">Start Curing</a>
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
        <div style="font-size: 12px; color: #999;">blocks physically in stock</div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Ready to Sell</h3>
        <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?= number_format($total_ready) ?></div>
        <div style="font-size: 12px; color: #999;">cured and available for sale</div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Curing (Not Ready)</h3>
        <div style="font-size: 32px; font-weight: bold; color: #f39c12;"><?= number_format($total_curing) ?></div>
        <div style="font-size: 12px; color: #999;">still drying</div>
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
                <th>Total Quantity</th>
                <th>Curing (not ready)</th>
                <th>Ready to Sell</th>
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
                $status_color = $stock['ready_quantity'] > 100 ? '#28a745' : ($stock['ready_quantity'] > 50 ? '#ffc107' : '#dc3545');
                $status_text = $stock['ready_quantity'] > 100 ? 'Good' : ($stock['ready_quantity'] > 50 ? 'Low' : 'Critical');
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($stock['block_name']) ?></strong>
                </td>
                <td><?= htmlspecialchars($stock['size']) ?></td>
                <td><?= number_format($stock['quantity']) ?></td>
                <td style="color:#b45309;font-weight:600;"><?= number_format($stock['curing_quantity']) ?></td>
                <td>
                    <strong style="font-size: 18px; color: <?= $status_color ?>;">
                        <?= number_format($stock['ready_quantity']) ?>
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
                <td colspan="10" style="text-align: center; color: #888; padding: 30px;">
                    No finished goods in stock. Start production or enter opening stock.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
        
        <?php if (!empty($finished_stock)): ?>
        <tfoot style="background: #f8f9fa; font-weight: bold; font-size: 16px;">
            <tr>
                <td colspan="2">TOTAL</td>
                <td><?= number_format($total_quantity) ?></td>
                <td style="color:#b45309;"><?= number_format($total_curing) ?></td>
                <td style="color:#28a745;"><?= number_format($total_ready) ?></td>
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

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px;">
    <h2 style="margin: 0 0 6px 0;">⏳ Blocks Curing (Not Ready to Sell)</h2>
    <p style="color:#666;margin:0 0 15px 0;font-size:.9rem;">These production batches are still drying. They move to ready stock automatically on their ready date.</p>
    <table>
        <thead>
            <tr>
                <th>Production #</th>
                <th>Block Type</th>
                <th>Production Date</th>
                <th>Good Qty</th>
                <th>Curing Days</th>
                <th>Ready Date</th>
                <th>Days Left</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$curingBatches): ?>
            <tr><td colspan="7" style="text-align:center;color:#888;padding:20px;">No blocks are curing right now.</td></tr>
            <?php endif; ?>
            <?php foreach ($curingBatches as $batch): ?>
            <tr>
                <td>
                    <?php if (($batch['source'] ?? 'production') === 'hold'): ?>
                        <strong><?= e($batch['production_no']) ?></strong>
                    <?php else: ?>
                        <a href="../production/view.php?id=<?= (int) $batch['id'] ?>"><strong><?= e($batch['production_no']) ?></strong></a>
                    <?php endif; ?>
                </td>
                <td><?= e($batch['block_name'] . ' ' . $batch['size']) ?></td>
                <td><?= format_date($batch['production_date']) ?></td>
                <td><?= number_format((int) $batch['good_quantity']) ?></td>
                <td><span><?= (int) $batch['curing_days'] ?></span> <span>days</span></td>
                <td><?= format_date($batch['ready_date'] ?: $batch['production_date']) ?></td>
                <td><strong style="color:#b45309;"><span><?= (int) $batch['days_remaining'] ?></span> <span>days</span></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="background: #e7f3ff; padding: 20px; border-radius: 8px; margin-top: 20px;">
    <h3 style="margin: 0 0 10px 0;">💡 Stock Management Tips</h3>
    <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
        <li>Monitor stock levels daily to avoid stockouts</li>
        <li>Only <strong>ready</strong> blocks are available in sales and the online shop; curing blocks become ready automatically on their ready date</li>
        <li>Use <strong>Opening Stock</strong> once to enter blocks that existed before you started using the system</li>
        <li>Use <strong>Production Planning</strong> to maintain optimal stock levels</li>
        <li>Check <strong>Stock Movement</strong> for complete transaction history</li>
        <li>Stock is automatically updated when you record production or sales</li>
    </ul>
</div>

<?php require_once "../partials/footer.php"; ?>
