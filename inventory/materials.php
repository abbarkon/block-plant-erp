<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');
$pageTitle = "Raw Materials";
$canEdit = has_permission('inventory', 'edit');

$editingMaterial = null;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editStmt = $pdo->prepare("SELECT * FROM raw_materials WHERE id = ?");
    $editStmt->execute([(int) $_GET['edit']]);
    $editingMaterial = $editStmt->fetch();
    if (!$editingMaterial) {
        $error = "Material not found.";
    } elseif (!$canEdit) {
        $error = "You have view-only access to inventory, so materials cannot be edited here.";
        $editingMaterial = null;
    }
}

// UPDATE MATERIAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_material'])) {
    $id = (int) ($_POST['material_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $unitCostInput = trim($_POST['unit_cost'] ?? '');

    if (!$canEdit) {
        $error = "You do not have permission to edit materials.";
    } else {
        $materialStmt = $pdo->prepare("SELECT * FROM raw_materials WHERE id = ?");
        $materialStmt->execute([$id]);
        $existingMaterial = $materialStmt->fetch();

        if (!$existingMaterial) {
            $error = "Material not found.";
        } else {
            $editingMaterial = array_merge($existingMaterial, [
                'name' => $name,
                'unit' => $unit,
                'unit_cost' => $unitCostInput,
            ]);

            if ($name === '' || $unit === '' || !is_numeric($unitCostInput) || (float) $unitCostInput < 0) {
                $error = "Enter a material name, unit, and a valid non-negative unit cost.";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE raw_materials SET name = ?, unit = ?, unit_cost = ? WHERE id = ?");
                    $stmt->execute([$name, $unit, (float) $unitCostInput, $id]);
                    log_activity('update', 'inventory', $id, "Material updated: {$name} @ " . number_format((float) $unitCostInput, 2));
                    set_flash('success', 'Material updated successfully.');
                    header('Location: materials.php');
                    exit;
                } catch (PDOException $e) {
                    $error = "Could not update material. Its name may already be in use.";
                }
            }
        }
    }
}

// ADD MATERIAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $current_stock = (float) ($_POST['current_stock'] ?? 0);
    $unit_cost = (float) ($_POST['unit_cost'] ?? 0);

    if (!$canEdit) {
        $error = "You do not have permission to add materials.";
    } elseif ($name === '' || $unit === '') {
        $error = "Material name and unit are required.";
    } elseif (!is_finite($current_stock) || $current_stock < 0) {
        $error = "Opening stock must be zero or a positive number.";
    } elseif (!is_finite($unit_cost) || $unit_cost < 0) {
        $error = "Unit cost must be zero or a positive number.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO raw_materials (name, unit, current_stock, unit_cost) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $unit, $current_stock, $unit_cost]);
            $newId = (int) $pdo->lastInsertId();
            if ($current_stock > 0) {
                $pdo->prepare("INSERT INTO stock_transactions(material_id, transaction_type, quantity, reference_no, notes, transaction_date, created_by) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$newId, 'opening', $current_stock, 'OPEN-' . date('Ymd'), 'Opening stock on material creation', date('Y-m-d'), $_SESSION['user_id']]);
            }
            log_activity('create', 'inventory', $newId, "Material added: {$name} (opening {$current_stock})");
            set_flash('success', 'Material added successfully.');
            header('Location: materials.php');
            exit;
        } catch (PDOException $e) {
            $error = "Could not add material. It may already exist.";
        }
    }
}

// DEACTIVATE MATERIAL
if (isset($_GET['deactivate']) && is_numeric($_GET['deactivate'])) {
    $id = (int) $_GET['deactivate'];
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to deactivate materials.');
    } else {
        $stmt = $pdo->prepare("SELECT name FROM raw_materials WHERE id = ?");
        $stmt->execute([$id]);
        $matName = $stmt->fetchColumn();
        if ($matName !== false) {
            $pdo->prepare("UPDATE raw_materials SET status = 0 WHERE id = ?")->execute([$id]);
            log_activity('update', 'inventory', $id, "Material deactivated: {$matName}");
            set_flash('success', 'Material deactivated successfully.');
        }
    }
    header('Location: materials.php');
    exit;
}

// GET MATERIAL LIST WITH REORDER LEVELS
$stmt = $pdo->query("
    SELECT 
        rm.*,
        rl.min_level,
        rl.reorder_point
    FROM raw_materials rm
    LEFT JOIN reorder_levels rl ON rm.id = rl.material_id
    ORDER BY rm.status DESC, rm.name
");
$materials = $stmt->fetchAll();
$flash = get_flash();

require_once "../partials/header.php";
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>🧱 Raw Materials</h1>
        <p style="color: #666; margin: 5px 0 0 0;">Manage materials used in production</p>
    </div>
    <?php if ($canEdit): ?>
    <a href="reorder.php" class="btn" style="background: #ffc107; color: #000;">⚙️ Set Reorder Levels</a>
    <?php endif; ?>
</div>

<?php if (!empty($flash)): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>" style="<?= $flash['type'] === 'error' ? 'background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545;' : 'background: #d4edda; color: #155724; border-left: 4px solid #28a745;' ?> padding: 15px; border-radius: 5px; margin-bottom: 20px;">
    <?= $flash['type'] === 'error' ? '❌' : '✅' ?> <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
<div class="flash flash-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #28a745;">
    ✅ <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
    ❌ <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- ADD OR EDIT MATERIAL FORM -->
<?php if ($canEdit): ?>
<div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 25px;">
    <h2 style="margin: 0 0 20px 0;"><?= $editingMaterial ? 'Edit Raw Material' : 'Add Raw Material' ?></h2>

    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
            <?php if ($editingMaterial): ?>
            <input type="hidden" name="material_id" value="<?= (int) $editingMaterial['id'] ?>">
            <?php endif; ?>
            <div>
                <label>Material Name *</label>
                <input type="text" name="name" placeholder="Example: Cement" value="<?= htmlspecialchars($editingMaterial['name'] ?? '') ?>" required>
            </div>

            <div>
                <label>Unit *</label>
                <input type="text" name="unit" placeholder="bag / m3 / kg" value="<?= htmlspecialchars($editingMaterial['unit'] ?? '') ?>" required>
            </div>

            <?php if ($editingMaterial): ?>
            <div>
                <label>Current Stock</label>
                <input type="text" value="<?= number_format((float) $editingMaterial['current_stock'], 2) ?>" readonly>
            </div>
            <?php else: ?>
            <div>
                <label>Opening Stock</label>
                <input type="number" step="0.01" name="current_stock" value="0">
            </div>
            <?php endif; ?>

            <div>
                <label>Unit Cost</label>
                <input type="number" step="0.01" min="0" name="unit_cost" value="<?= htmlspecialchars((string) ($editingMaterial['unit_cost'] ?? '0')) ?>" required>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <?php if ($editingMaterial): ?>
                <button type="submit" name="update_material" class="btn">Save Changes</button>
                <a href="materials.php" class="btn btn-secondary">Cancel</a>
                <span style="margin-left: 8px; color: #666;">Use Stock Adjustments to change current stock.</span>
            <?php else: ?>
                <button type="submit" name="add_material" class="btn">+ Add Material</button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php else: ?>
<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; color: #856404;">
    You have view-only access to inventory. Materials can be viewed here, but adding, editing, and deactivating them requires the <strong>inventory edit</strong> permission.
</div>
<?php endif; ?>

<!-- MATERIAL TABLE -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="margin: 0;">Material Stock</h2>
        <?php if ($canEdit): ?>
        <a href="adjustments.php" class="btn btn-sm" style="background: #17a2b8;">Stock Adjustments</a>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Material</th>
                <th>Unit</th>
                <th>Current Stock</th>
                <th>Reorder Point</th>
                <th>Unit Cost</th>
                <th>Stock Value</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($materials as $item): ?>
            <?php
                $stock = (float)$item['current_stock'];
                $reorder_point = (float)($item['reorder_point'] ?? 0);
                $stock_value = $stock * $item['unit_cost'];
                
                // Determine status color
                if (!$item['status']) {
                    $status_color = '#6c757d';
                    $status_text = 'INACTIVE';
                } elseif ($reorder_point > 0 && $stock <= $reorder_point) {
                    $status_color = '#dc3545';
                    $status_text = 'LOW STOCK';
                } elseif ($item['min_level'] > 0 && $stock <= $item['min_level']) {
                    $status_color = '#ffc107';
                    $status_text = 'BELOW MIN';
                } else {
                    $status_color = '#28a745';
                    $status_text = 'OK';
                }
            ?>
            <tr style="<?= !$item['status'] ? 'opacity: 0.6;' : '' ?>">
                <td>
                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                </td>
                <td><?= htmlspecialchars($item['unit']) ?></td>
                <td>
                    <strong style="font-size: 16px; color: <?= $status_color ?>;">
                        <?= number_format($stock, 2) ?>
                    </strong>
                </td>
                <td>
                    <?= $reorder_point > 0 ? number_format($reorder_point, 2) : '-' ?>
                </td>
                <td><?= number_format($item['unit_cost'], 2) ?></td>
                <td><strong><?= number_format($stock_value, 2) ?></strong></td>
                <td>
                    <span style="padding: 5px 12px; border-radius: 5px; background: <?= $status_color ?>; color: white; font-weight: bold; font-size: 11px;">
                        <?= $status_text ?>
                    </span>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                    <a href="?edit=<?= (int) $item['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                    <?php if ($item['status']): ?>
                        <a href="?deactivate=<?= $item['id'] ?>" 
                           class="btn btn-sm" 
                           style="background: #dc3545;"
                           onclick="return confirm('Deactivate this material?');">
                            Deactivate
                        </a>
                    <?php else: ?>
                        <span style="color: #999;">Inactive</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color: #94a3b8; font-size: 0.8rem;">view only</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if (empty($materials)): ?>
            <tr>
                <td colspan="8" style="text-align: center; color: #888; padding: 30px;">
                    No materials added yet.<?= $canEdit ? ' Add your first material above.' : '' ?>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
        
        <?php if (!empty($materials)): ?>
        <tfoot style="background: #f8f9fa; font-weight: bold;">
            <tr>
                <td colspan="5">TOTAL STOCK VALUE</td>
                <td>
                    <?php
                    $total_value = array_sum(array_map(function($m) {
                        return $m['current_stock'] * $m['unit_cost'];
                    }, $materials));
                    echo number_format($total_value, 2);
                    ?>
                </td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<div style="background: #e7f3ff; padding: 20px; border-radius: 8px; margin-top: 20px;">
    <h3 style="margin: 0 0 10px 0;">💡 Quick Tips</h3>
    <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
        <li>Set <strong>Reorder Levels</strong> to get automatic low stock alerts</li>
        <li>Use <strong>Stock Adjustments</strong> to correct stock quantities</li>
        <li>Check <strong>Stock Movement</strong> to see transaction history</li>
        <li><strong>Unit Cost</strong> is used for inventory valuation</li>
    </ul>
</div>

<?php require_once "../partials/footer.php"; ?>
