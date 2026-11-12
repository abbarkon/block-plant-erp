<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'edit');
ensure_finished_stock_transaction_schema();
ensure_production_curing_schema();

$pageTitle = 'Finished Stock Adjustment';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bid = (int) $_POST['block_type_id'];
    $type = $_POST['adjustment_type'];
    $qty = (int) $_POST['quantity'];
    $date = $_POST['adjustment_date'];
    $reason = trim($_POST['reason'] ?? '');
    if ($bid <= 0 || $qty <= 0) {
        $error = 'Quantity must be positive.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
        $error = 'Choose a valid adjustment date.';
    } elseif ($type !== 'increase' && $type !== 'decrease') {
        $error = 'Adjustment type must be increase or decrease.';
    } elseif ($reason === '') {
        $error = 'A reason is required for every stock adjustment.';
    } else {
        $delta = $type === 'increase' ? $qty : -$qty;
        $ref = 'FADJ-' . date('YmdHis');
        try {
            $pdo->beginTransaction();
            if ($delta < 0) {
                $chk = $pdo->prepare("SELECT quantity FROM finished_stock WHERE block_type_id=? FOR UPDATE");
                $chk->execute([$bid]);
                $ready = max(0, (int) $chk->fetchColumn() - finished_curing_quantity($bid));
                if ($ready < $qty) {
                    throw new Exception('Only ' . number_format($ready) . ' finished blocks are ready to sell. Curing blocks cannot be reduced until they are ready.');
                }
            }
            $pdo->prepare("INSERT INTO finished_stock(block_type_id, quantity) VALUES(?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)")
                ->execute([$bid, $delta]);
            log_finished_stock_transaction($bid, $delta, 'adjustment', $ref, $reason, $date);
            $pdo->commit();
            log_activity('update', 'inventory', $bid, 'Finished stock adjustment');
            header('Location: finished_movement.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$blocks = $pdo->query("SELECT bt.id, bt.name, bt.size, COALESCE(fs.quantity,0) AS qty FROM block_types bt LEFT JOIN finished_stock fs ON fs.block_type_id=bt.id WHERE bt.status=1 ORDER BY bt.name")->fetchAll();
foreach ($blocks as &$block) {
    $block['curing'] = finished_curing_quantity((int) $block['id']);
    $block['ready'] = max(0, (int) $block['qty'] - (int) $block['curing']);
}
unset($block);

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Finished stock adjustment</h1>
    <a href="finished_movement.php" class="btn btn-secondary">Movement history</a>
</div>

<?php if (!empty($error)): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="panel" style="max-width:560px;">
    <form method="post">
        <label>Block type</label>
        <select name="block_type_id" required>
            <?php foreach ($blocks as $b): ?>
            <option value="<?= (int) $b['id'] ?>"><?= e($b['name'] . ' ' . $b['size']) ?> (ready: <?= (int) $b['ready'] ?>, curing: <?= (int) $b['curing'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <label>Date</label>
        <input type="date" name="adjustment_date" value="<?= date('Y-m-d') ?>" required>
        <label>Adjustment</label>
        <select name="adjustment_type">
            <option value="increase">Increase</option>
            <option value="decrease">Decrease</option>
        </select>
        <label>Quantity</label>
        <input type="number" name="quantity" min="1" required>
        <label>Reason</label>
        <textarea name="reason" rows="2" required></textarea>
        <button type="submit" class="btn btn-accent" style="margin-top:12px;">Apply adjustment</button>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>
