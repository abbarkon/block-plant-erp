<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');

$pageTitle = "Stock Adjustments";
$canEdit = has_permission('inventory', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recording an adjustment moves real stock — require the edit permission,
    // not just the view permission the page itself runs under.
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to record stock adjustments.');
        header('Location: adjustments.php');
        exit;
    }

    $materialId = (int) ($_POST['material_id'] ?? 0);
    $type = (string) ($_POST['adjustment_type'] ?? '');
    $qty = (float) ($_POST['quantity'] ?? 0);
    $date = (string) ($_POST['adjustment_date'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($materialId <= 0) {
        $error = 'Select a material.';
    } elseif ($type !== 'increase' && $type !== 'decrease') {
        $error = 'Adjustment type must be increase or decrease.';
    } elseif (!is_finite($qty) || $qty <= 0) {
        $error = 'Quantity must be a positive number.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Choose a valid adjustment date.';
    } elseif ($reason === '') {
        $error = 'A reason is required for every stock adjustment.';
    } else {
        $adjNo = 'ADJ-' . date('YmdHis') . '-' . random_int(100, 999);
        $multiplier = $type === 'increase' ? 1 : -1;
        try {
            $pdo->beginTransaction();

            $matStmt = $pdo->prepare("SELECT id, name, current_stock FROM raw_materials WHERE id = ? AND status = 1 FOR UPDATE");
            $matStmt->execute([$materialId]);
            $material = $matStmt->fetch();
            if (!$material) {
                throw new RuntimeException('That material does not exist or is inactive.');
            }

            if ($multiplier < 0 && (float) $material['current_stock'] < $qty) {
                throw new RuntimeException(
                    "Cannot decrease {$qty} — {$material['name']} only has " . number_format((float) $material['current_stock'], 2) . ' in stock.'
                );
            }

            $pdo->prepare("INSERT INTO stock_adjustments(adjustment_no, adjustment_date, material_id, adjustment_type, quantity, reason, created_by) VALUES(?,?,?,?,?,?,?)")
                ->execute([$adjNo, $date, $materialId, $type, $qty, $reason, $_SESSION['user_id']]);

            $upd = $pdo->prepare("UPDATE raw_materials SET current_stock = current_stock + ? WHERE id = ? AND current_stock + ? >= 0");
            $upd->execute([$qty * $multiplier, $materialId, $qty * $multiplier]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('Stock changed while saving this adjustment. Please retry.');
            }

            $pdo->prepare("INSERT INTO stock_transactions(material_id, transaction_type, quantity, reference_no, notes, transaction_date, created_by) VALUES(?,?,?,?,?,?,?)")
                ->execute([$materialId, 'adjustment', $qty * $multiplier, $adjNo, $reason, $date, $_SESSION['user_id']]);

            $pdo->commit();
            log_activity('update', 'inventory', $materialId, "Stock adjustment {$adjNo}: {$type} {$qty} — {$reason}");
            set_flash('success', "Stock adjustment {$adjNo} recorded.");
            header('Location: adjustments.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$materials = $pdo->query("SELECT * FROM raw_materials WHERE status=1")->fetchAll();
$adjustments = $pdo->query("SELECT sa.*, rm.name as material_name FROM stock_adjustments sa JOIN raw_materials rm ON sa.material_id=rm.id ORDER BY sa.created_at DESC LIMIT 50")->fetchAll();
$flash = get_flash();

require_once "../partials/header.php";
?>

<h1>📊 Stock Adjustments</h1>

<?php if (!empty($flash)): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <h3>New Stock Adjustment</h3>
    
    <?php if(isset($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <form method="post">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
            <div>
                <label>Material *</label>
                <select name="material_id" required>
                    <option value="">Select Material</option>
                    <?php foreach($materials as $mat): ?>
                    <option value="<?= (int) $mat['id'] ?>"><?= htmlspecialchars($mat['name']) ?> (Current: <?= number_format((float) $mat['current_stock'], 2) ?> <?= e($mat['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label>Adjustment Type *</label>
                <select name="adjustment_type" required>
                    <option value="increase">Increase Stock</option>
                    <option value="decrease">Decrease Stock</option>
                </select>
            </div>
            
            <div>
                <label>Date *</label>
                <input type="date" name="adjustment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <div>
                <label>Quantity *</label>
                <input type="number" step="0.01" min="0.01" name="quantity" required>
            </div>
            
            <div style="grid-column: span 2;">
                <label>Reason *</label>
                <input type="text" name="reason" placeholder="e.g., Damaged goods, Stock count correction" required>
            </div>
        </div>
        
        <div style="margin-top: 15px;">
            <button type="submit" class="btn">Record Adjustment</button>
        </div>
    </form>
</div>
<?php else: ?>
<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; color:#856404;">
    You have view-only access to inventory, so stock adjustments cannot be recorded here. Ask an administrator for the <strong>inventory edit</strong> permission.
</div>
<?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h3>Recent Adjustments</h3>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Adjustment No</th>
                <th>Material</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($adjustments)): ?>
            <tr><td colspan="6" style="text-align:center;color:#888;padding:24px;">No stock adjustments recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach($adjustments as $adj): ?>
            <tr>
                <td><?= date('d M Y', strtotime($adj['adjustment_date'])) ?></td>
                <td><?= htmlspecialchars($adj['adjustment_no']) ?></td>
                <td><?= htmlspecialchars($adj['material_name']) ?></td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $adj['adjustment_type'] === 'increase' ? '#d4edda' : '#f8d7da' ?>;">
                        <?= ucfirst($adj['adjustment_type']) ?>
                    </span>
                </td>
                <td><?= number_format($adj['quantity'], 2) ?></td>
                <td><?= htmlspecialchars($adj['reason']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
