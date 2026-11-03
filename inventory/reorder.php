<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');
$pageTitle = "Reorder Levels";
$canEdit = has_permission('inventory', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reorder levels are configuration — require edit, not just view.
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to change reorder levels.');
        header('Location: reorder.php');
        exit;
    }
    $material = (int) ($_POST['material_id'] ?? 0);
    $min = (float) ($_POST['min_level'] ?? 0);
    $max = (float) ($_POST['max_level'] ?? 0);
    $reorder = (float) ($_POST['reorder_point'] ?? 0);
    $qty = (float) ($_POST['reorder_quantity'] ?? 0);

    $values = [$min, $max, $reorder, $qty];
    $invalid = array_filter($values, fn($v) => !is_finite($v) || $v < 0);

    if ($material <= 0) {
        $error = 'Select a material.';
    } elseif ($invalid) {
        $error = 'Reorder levels must be zero or positive numbers.';
    } elseif ($reorder <= 0 || $qty <= 0) {
        $error = 'Reorder point and reorder quantity must be greater than zero.';
    } elseif ($max > 0 && $min > $max) {
        $error = 'Minimum level cannot be greater than maximum level.';
    } else {
        $exists = $pdo->prepare("SELECT id FROM raw_materials WHERE id = ? AND status = 1");
        $exists->execute([$material]);
        if (!$exists->fetchColumn()) {
            $error = 'That material does not exist or is inactive.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO reorder_levels(material_id, min_level, max_level, reorder_point, reorder_quantity) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE min_level=VALUES(min_level), max_level=VALUES(max_level), reorder_point=VALUES(reorder_point), reorder_quantity=VALUES(reorder_quantity)");
            $stmt->execute([$material, $min, $max, $reorder, $qty]);
            log_activity('update', 'inventory', $material, "Reorder levels set: min {$min}, max {$max}, point {$reorder}, qty {$qty}");
            set_flash('success', 'Reorder level saved.');
            header('Location: reorder.php');
            exit;
        }
    }
}

$stmt = $pdo->query("SELECT rm.*, rl.min_level, rl.max_level, rl.reorder_point, rl.reorder_quantity FROM raw_materials rm LEFT JOIN reorder_levels rl ON rm.id=rl.material_id WHERE rm.status=1");
$materials = $stmt->fetchAll();
$flash = get_flash();

// Deep link from low_stock_alert.php: reorder.php?material=ID opens that material's modal.
$deepLink = null;
if ($canEdit && isset($_GET['material']) && ctype_digit((string) $_GET['material'])) {
    foreach ($materials as $m) {
        if ((int) $m['id'] === (int) $_GET['material']) {
            $deepLink = $m;
            break;
        }
    }
}

require_once "../partials/header.php";

// Materials below reorder point
$low_stock = array_filter($materials, function($m) {
    return $m['reorder_point'] && $m['current_stock'] <= $m['reorder_point'];
});
?>

<h1>📦 Reorder Level Management</h1>

<?php if (!empty($flash)): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if(!empty($low_stock)): ?>
<div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
    <h3 style="margin: 0 0 10px 0;">⚠️ Low Stock Alert</h3>
    <p>The following materials are below their reorder point:</p>
    <ul>
        <?php foreach($low_stock as $item): ?>
        <li><strong><?= htmlspecialchars($item['name']) ?></strong>: Current Stock = <?= number_format($item['current_stock'], 2) ?> <?= $item['unit'] ?>, Reorder Point = <?= number_format($item['reorder_point'], 2) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <table>
        <thead>
            <tr>
                <th>Material</th>
                <th>Current Stock</th>
                <th>Min Level</th>
                <th>Max Level</th>
                <th>Reorder Point</th>
                <th>Reorder Quantity</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($materials as $mat): ?>
            <tr style="<?= $mat['reorder_point'] && $mat['current_stock'] <= $mat['reorder_point'] ? 'background: #fff3cd;' : '' ?>">
                <td><?= htmlspecialchars($mat['name']) ?></td>
                <td><strong><?= number_format($mat['current_stock'], 2) ?> <?= $mat['unit'] ?></strong></td>
                <td><?= $mat['min_level'] ? number_format($mat['min_level'], 2) : '-' ?></td>
                <td><?= $mat['max_level'] ? number_format($mat['max_level'], 2) : '-' ?></td>
                <td><?= $mat['reorder_point'] ? number_format($mat['reorder_point'], 2) : '-' ?></td>
                <td><?= $mat['reorder_quantity'] ? number_format($mat['reorder_quantity'], 2) : '-' ?></td>
                <td>
                    <?php if($mat['reorder_point'] && $mat['current_stock'] <= $mat['reorder_point']): ?>
                    <span style="padding: 5px 10px; border-radius: 5px; background: #f8d7da; color: #721c24;">Low Stock</span>
                    <?php elseif($mat['reorder_point'] && $mat['current_stock'] <= $mat['min_level']): ?>
                    <span style="padding: 5px 10px; border-radius: 5px; background: #fff3cd; color: #856404;">Below Min</span>
                    <?php else: ?>
                    <span style="padding: 5px 10px; border-radius: 5px; background: #d4edda; color: #155724;">OK</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                    <button class="btn btn-sm" onclick="editReorder(<?= (int) $mat['id'] ?>, '<?= htmlspecialchars($mat['name'], ENT_QUOTES) ?>', <?= (float) ($mat['min_level'] ?? 0) ?>, <?= (float) ($mat['max_level'] ?? 0) ?>, <?= (float) ($mat['reorder_point'] ?? 0) ?>, <?= (float) ($mat['reorder_quantity'] ?? 0) ?>)">Set Levels</button>
                    <?php else: ?>
                    <span style="color:#94a3b8;font-size:.8rem;">view only</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal for setting reorder levels -->
<div id="reorderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="background: white; max-width: 500px; margin: 100px auto; padding: 30px; border-radius: 8px;">
        <h3 id="modalTitle">Set Reorder Levels</h3>
        <form method="post">
            <input type="hidden" name="material_id" id="modal_material_id">
            
            <div style="margin-bottom: 15px;">
                <label>Minimum Level</label>
                <input type="number" step="0.01" name="min_level" id="modal_min_level">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Maximum Level</label>
                <input type="number" step="0.01" name="max_level" id="modal_max_level">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Reorder Point *</label>
                <input type="number" step="0.01" name="reorder_point" id="modal_reorder_point" required>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label>Reorder Quantity *</label>
                <input type="number" step="0.01" name="reorder_quantity" id="modal_reorder_qty" required>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn">Save</button>
                <button type="button" class="btn" style="background: #6c757d;" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function editReorder(id, name, min, max, reorder, qty) {
    document.getElementById('modalTitle').textContent = 'Set Reorder Levels: ' + name;
    document.getElementById('modal_material_id').value = id;
    document.getElementById('modal_min_level').value = min;
    document.getElementById('modal_max_level').value = max;
    document.getElementById('modal_reorder_point').value = reorder;
    document.getElementById('modal_reorder_qty').value = qty;
    document.getElementById('reorderModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('reorderModal').style.display = 'none';
}
</script>

<?php if ($deepLink): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
    editReorder(
        <?= (int) $deepLink['id'] ?>,
        <?= json_encode((string) $deepLink['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        <?= (float) ($deepLink['min_level'] ?? 0) ?>,
        <?= (float) ($deepLink['max_level'] ?? 0) ?>,
        <?= (float) ($deepLink['reorder_point'] ?? 0) ?>,
        <?= (float) ($deepLink['reorder_quantity'] ?? 0) ?>
    );
});
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
