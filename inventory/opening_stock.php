<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'edit');
ensure_finished_stock_transaction_schema();
ensure_production_curing_schema();

$pageTitle = 'Opening Stock';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openingDate = trim((string) ($_POST['opening_date'] ?? date('Y-m-d')));
    if (!is_valid_date_string($openingDate)) {
        $error = 'Choose a valid opening-stock date.';
    } else {
        $quantities = $_POST['opening_qty'] ?? [];
        $costs = $_POST['opening_cost'] ?? [];
        $entries = [];
        if (is_array($quantities)) {
            foreach ($quantities as $blockId => $quantity) {
                $blockId = (int) $blockId;
                $quantity = (int) $quantity;
                if ($blockId <= 0 || $quantity <= 0) {
                    continue;
                }
                $entries[] = [
                    'block_type_id' => $blockId,
                    'quantity' => $quantity,
                    'unit_cost' => max(0, (float) ($costs[$blockId] ?? 0)),
                ];
            }
        }

        if (!$entries) {
            $error = 'Enter an opening quantity for at least one block type.';
        } else {
            try {
                $pdo->beginTransaction();
                $reference = 'OPEN-' . date('YmdHis');
                foreach ($entries as $entry) {
                    $blockId = $entry['block_type_id'];
                    $quantity = $entry['quantity'];
                    $unitCost = $entry['unit_cost'];

                    $pdo->prepare("INSERT IGNORE INTO finished_stock(block_type_id, quantity, average_unit_cost) VALUES(?, 0, 0)")
                        ->execute([$blockId]);
                    $stmt = $pdo->prepare("SELECT quantity, average_unit_cost FROM finished_stock WHERE block_type_id = ? FOR UPDATE");
                    $stmt->execute([$blockId]);
                    $stock = $stmt->fetch();
                    if (!$stock) {
                        throw new RuntimeException('Finished-stock record could not be created for block #' . $blockId . '.');
                    }

                    $oldQuantity = (int) $stock['quantity'];
                    $oldAverage = (float) $stock['average_unit_cost'];
                    // If no cost is entered, keep the existing average cost instead of
                    // dragging the weighted average down to zero.
                    if ($unitCost <= 0) {
                        $unitCost = $oldAverage;
                    }
                    $newQuantity = $oldQuantity + $quantity;
                    $newAverage = $newQuantity > 0
                        ? round((($oldQuantity * $oldAverage) + ($quantity * $unitCost)) / $newQuantity, 4)
                        : 0;

                    $pdo->prepare("UPDATE finished_stock SET quantity = ?, average_unit_cost = ? WHERE block_type_id = ?")
                        ->execute([$newQuantity, $newAverage, $blockId]);
                    log_finished_stock_transaction(
                        $blockId,
                        $quantity,
                        'opening',
                        $reference,
                        'Opening stock entered before system go-live',
                        $openingDate
                    );
                }
                $pdo->commit();
                log_activity('create', 'inventory', 0, 'Opening stock entered for ' . count($entries) . ' block type(s)');
                set_flash('success', 'Opening stock saved for ' . count($entries) . ' block type(s).');
                header('Location: index.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $exception->getMessage();
            }
        }
    }
}

$blocks = $pdo->query("
    SELECT bt.id, bt.name, bt.size, bt.selling_price,
           COALESCE(fs.quantity, 0) AS total_qty,
           COALESCE(fs.average_unit_cost, 0) AS avg_cost
    FROM block_types bt
    LEFT JOIN finished_stock fs ON fs.block_type_id = bt.id
    WHERE bt.status = 1
    ORDER BY bt.name, bt.size
")->fetchAll();
foreach ($blocks as &$block) {
    $block['curing_qty'] = finished_curing_quantity((int) $block['id']);
    $block['ready_qty'] = max(0, (int) $block['total_qty'] - (int) $block['curing_qty']);
}
unset($block);

$openingHistory = $pdo->query("
    SELECT fst.*, bt.name AS block_name, bt.size
    FROM finished_stock_transactions fst
    JOIN block_types bt ON bt.id = fst.block_type_id
    WHERE fst.transaction_type = 'opening'
    ORDER BY fst.id DESC
    LIMIT 50
")->fetchAll();

$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Opening Stock</h1>
        <p style="color:#64748b;margin:6px 0 0;">Enter the finished blocks you already had before you started using this system.</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">← Finished Stock</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="panel" style="background:#fff8e1;border:1px solid #ffe082;margin-bottom:18px;">
    <strong>Important:</strong> enter only blocks that existed <em>before</em> you started using the system.
    Do not include production already recorded under Production. This page adds the quantities to current stock.
    Opening stock is treated as ready to sell immediately.
</div>

<div class="panel">
    <form method="post" title="Add these opening quantities to finished stock?" onsubmit="return confirm(this.getAttribute('title') || 'Add these opening quantities to finished stock?');">
        <label>Opening stock date</label>
        <input type="date" name="opening_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>

        <div class="table-container" style="margin-top:16px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Block Type</th>
                        <th>Size</th>
                        <th style="text-align:right;">Current Total</th>
                        <th style="text-align:right;">Curing</th>
                        <th style="text-align:right;">Ready</th>
                        <th style="text-align:right;">Opening Qty</th>
                        <th style="text-align:right;">Unit Cost (optional)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$blocks): ?>
                    <tr><td colspan="7" style="text-align:center;color:#64748b;">No active block types. Add block types under Settings → Blocks first.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td><strong><?= e($block['name']) ?></strong></td>
                        <td><?= e($block['size']) ?></td>
                        <td style="text-align:right;"><?= number_format((int) $block['total_qty']) ?></td>
                        <td style="text-align:right;color:#b45309;"><?= number_format((int) $block['curing_qty']) ?></td>
                        <td style="text-align:right;color:#166534;font-weight:600;"><?= number_format((int) $block['ready_qty']) ?></td>
                        <td style="text-align:right;">
                            <input type="number" name="opening_qty[<?= (int) $block['id'] ?>]" min="0" step="1" value="0" style="max-width:120px;text-align:right;">
                        </td>
                        <td style="text-align:right;">
                            <input type="number" name="opening_cost[<?= (int) $block['id'] ?>]" min="0" step="0.01" value="<?= e(number_format((float) $block['avg_cost'], 4, '.', '')) ?>" style="max-width:130px;text-align:right;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-accent" style="margin-top:16px;">Save opening stock</button>
    </form>
</div>

<div class="panel" style="margin-top:18px;">
    <h3 style="margin-top:0;">Opening stock history</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Block</th>
                    <th>Size</th>
                    <th style="text-align:right;">Quantity</th>
                    <th>Reference</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$openingHistory): ?>
                <tr><td colspan="6" style="text-align:center;color:#64748b;">No opening stock has been entered yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($openingHistory as $row): ?>
                <tr>
                    <td><?= format_date($row['transaction_date']) ?></td>
                    <td><?= e($row['block_name']) ?></td>
                    <td><?= e($row['size']) ?></td>
                    <td style="text-align:right;font-weight:600;"><?= number_format((float) $row['quantity']) ?></td>
                    <td><?= e($row['reference_no'] ?? '') ?></td>
                    <td><?= e($row['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
