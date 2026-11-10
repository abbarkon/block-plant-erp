<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');
$canPurchase = has_permission('purchases', 'create');
$pageTitle = "Low Stock Alert";
require_once "../partials/header.php";

// Get materials below reorder point
$stmt = $pdo->query("
    SELECT rm.*, rl.min_level, rl.max_level, rl.reorder_point, rl.reorder_quantity,
           (rl.reorder_quantity * rm.unit_cost) as reorder_value
    FROM raw_materials rm 
    JOIN reorder_levels rl ON rm.id=rl.material_id 
    WHERE rm.current_stock <= rl.reorder_point AND rm.status=1
    ORDER BY CASE WHEN rl.reorder_point > 0 THEN rm.current_stock / rl.reorder_point ELSE 0 END ASC
");
$low_stock = $stmt->fetchAll();

// Get materials with no reorder levels set
$no_reorder = $pdo->query("
    SELECT rm.* 
    FROM raw_materials rm 
    LEFT JOIN reorder_levels rl ON rm.id=rl.material_id 
    WHERE rl.id IS NULL AND rm.status=1
")->fetchAll();

$total_reorder_value = array_sum(array_column($low_stock, 'reorder_value'));

// Order quantity and best supplier for each low material, so the buttons open a ready purchase order.
$bestSupplier = [];
try {
    foreach ($pdo->query("SELECT p.material_id, p.supplier_id FROM supplier_material_prices p JOIN suppliers s ON s.id = p.supplier_id WHERE s.status = 1 ORDER BY p.unit_price ASC")->fetchAll() as $r) {
        if (!isset($bestSupplier[(int) $r['material_id']])) {
            $bestSupplier[(int) $r['material_id']] = (int) $r['supplier_id'];
        }
    }
} catch (PDOException $e) {
}
try {
    foreach ($pdo->query("SELECT pi.material_id, p.supplier_id FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id WHERE (p.approval_status IS NULL OR p.approval_status <> 'rejected') ORDER BY p.purchase_date DESC, p.id DESC")->fetchAll() as $r) {
        if (!isset($bestSupplier[(int) $r['material_id']])) {
            $bestSupplier[(int) $r['material_id']] = (int) $r['supplier_id'];
        }
    }
} catch (PDOException $e) {
}
$orderQty = [];
$allItems = [];
$suppliersInList = [];
foreach ($low_stock as $m) {
    $q = (float) $m['reorder_quantity'];
    if ((float) $m['max_level'] > 0) {
        $q = max($q, (float) $m['max_level'] - (float) $m['current_stock']);
    }
    if ($q <= 0) {
        $q = max(0, (float) $m['reorder_point'] * 2 - (float) $m['current_stock']);
    }
    $q = round($q, 2);
    $orderQty[(int) $m['id']] = $q;
    if ($q > 0) {
        $allItems[] = (int) $m['id'] . ':' . $q;
        $suppliersInList[$bestSupplier[(int) $m['id']] ?? 0] = true;
    }
}
$allUrl = '../purchases/create.php?items=' . urlencode(implode(',', $allItems));
if (count($suppliersInList) === 1 && key($suppliersInList) > 0) {
    $allUrl .= '&supplier_id=' . (int) key($suppliersInList);
}
?>

<h1>⚠️ Low Stock Alert System</h1>

<?php if(!empty($low_stock)): ?>
<div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
    <h2 style="margin: 0 0 10px 0; color: #856404;">🚨 URGENT: Materials Need Reordering</h2>
    <p style="margin: 0; color: #856404;">
        <strong><?= count($low_stock) ?></strong> materials are below their reorder point. 
        Estimated reorder value: <strong><?= number_format($total_reorder_value, 2) ?></strong>
    </p>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
        <h3>Critical Stock Levels</h3>
        <div class="header-actions">
            <a href="../purchases/planning.php" class="btn btn-accent">Purchase planning</a>
            <?php if ($canPurchase): ?>
            <?php if ($allItems): ?>
            <a href="<?= e($allUrl) ?>" class="btn" style="background: #dc3545;color:#fff;">Create PO for all <?= count($allItems) ?> materials</a>
            <a href="reorder_list.php" class="btn btn-secondary">Order by supplier</a>
            <?php else: ?>
            <a href="../purchases/create.php" class="btn" style="background: #dc3545;color:#fff;">Create PO</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Priority</th>
                <th>Material</th>
                <th>Current Stock</th>
                <th>Reorder Point</th>
                <th>Recommended Order</th>
                <th>Estimated Cost</th>
                <th>Criticality</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $priority = 1;
            $canPurchase = has_permission('purchases', 'create');
            foreach($low_stock as $mat): 
                // guard: a zero reorder point would be a fatal DivisionByZeroError in PHP 8
                $stock_ratio = (float) $mat['reorder_point'] > 0 ? $mat['current_stock'] / $mat['reorder_point'] : 0.0;
                $criticality = $stock_ratio < 0.3 ? 'CRITICAL' : ($stock_ratio < 0.7 ? 'HIGH' : 'MEDIUM');
                $color = $stock_ratio < 0.3 ? '#dc3545' : ($stock_ratio < 0.7 ? '#ffc107' : '#fd7e14');
            ?>
            <tr style="background: <?= $stock_ratio < 0.3 ? '#f8d7da' : ($stock_ratio < 0.7 ? '#fff3cd' : '#ffe5d0') ?>;">
                <td><strong><?= $priority++ ?></strong></td>
                <td>
                    <strong><?= htmlspecialchars($mat['name']) ?></strong><br>
                    <small style="color: #666;">Unit: <?= htmlspecialchars($mat['unit']) ?></small>
                </td>
                <td>
                    <strong style="color: <?= $color ?>; font-size: 18px;">
                        <?= number_format($mat['current_stock'], 2) ?>
                    </strong>
                </td>
                <td><?= number_format($mat['reorder_point'], 2) ?></td>
                <td>
                    <strong><?= number_format((float) $mat['reorder_quantity'], 2) ?> <?= $mat['unit'] ?></strong><br>
                    <small>To reach: <?= number_format((float) $mat['max_level'], 2) ?></small>
                </td>
                <td><strong><?= number_format($mat['reorder_value'], 2) ?></strong></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $color ?>; color: white; font-weight: bold;">
                        <?= $criticality ?>
                    </span>
                </td>
                <td>
                    <?php if ($canPurchase): ?>
                    <a href="../purchases/create.php?material_id=<?= (int) $mat['id'] ?>&qty=<?= e($orderQty[(int) $mat['id']] ?? 0) ?><?= !empty($bestSupplier[(int) $mat['id']]) ? '&supplier_id=' . (int) $bestSupplier[(int) $mat['id']] : '' ?>" class="btn btn-sm">Order <?= number_format($orderQty[(int) $mat['id']] ?? 0, 2) ?> <?= e($mat['unit']) ?></a>
                    <?php else: ?>
                    <span style="color:#94a3b8;font-size:.8rem;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background: #f8f9fa; font-weight: bold; font-size: 16px;">
                <td colspan="5">TOTAL ESTIMATED COST</td>
                <td colspan="3"><?= number_format($total_reorder_value, 2) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php else: ?>
<div style="background: #d4edda; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    <h2 style="margin: 0 0 10px 0; color: #155724;">✅ All Stock Levels Are Good</h2>
    <p style="margin: 0; color: #155724;">No materials are currently below their reorder points.</p>
</div>
<?php endif; ?>

<?php if(!empty($no_reorder)): ?>
<div style="background: #e7f3ff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px;">
    <h3>⚙️ Materials Without Reorder Levels</h3>
    <p>The following materials don't have reorder levels configured. Set reorder levels to enable automatic alerts.</p>
    
    <table>
        <thead>
            <tr>
                <th>Material</th>
                <th>Current Stock</th>
                <th>Unit</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($no_reorder as $mat): ?>
            <tr>
                <td><?= htmlspecialchars($mat['name']) ?></td>
                <td><?= number_format($mat['current_stock'], 2) ?></td>
                <td><?= htmlspecialchars($mat['unit']) ?></td>
                <td>
                    <a href="../inventory/reorder.php?material=<?= $mat['id'] ?>" class="btn btn-sm">Set Levels</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px;">
    <h3>📊 Stock Management Tips</h3>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 15px;">
        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
            <h4 style="margin: 0 0 10px 0; color: #2c3e50;">🎯 Set Reorder Points</h4>
            <p style="margin: 0; color: #666; font-size: 14px;">Configure minimum and reorder levels for all materials to avoid stockouts.</p>
        </div>
        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
            <h4 style="margin: 0 0 10px 0; color: #2c3e50;">📅 Regular Reviews</h4>
            <p style="margin: 0; color: #666; font-size: 14px;">Check this report daily to stay ahead of material requirements.</p>
        </div>
        <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
            <h4 style="margin: 0 0 10px 0; color: #2c3e50;">🔄 Update Levels</h4>
            <p style="margin: 0; color: #666; font-size: 14px;">Adjust reorder points based on seasonal demand and lead times.</p>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
