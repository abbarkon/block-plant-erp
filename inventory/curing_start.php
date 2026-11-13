<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('inventory', 'edit');

ensure_finished_stock_transaction_schema();
ensure_production_curing_schema();

$pageTitle = 'Start Curing (Previous Production)';
$error = '';
$success = '';
$startDate = date('Y-m-d');
$curingDays = production_default_curing_days();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Release an existing curing hold early.
    if (($_POST['action'] ?? '') === 'release') {
        $holdId = (int) ($_POST['hold_id'] ?? 0);
        if ($holdId > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE curing_holds SET ready_date = CURDATE() WHERE id = ? AND ready_date > CURDATE()")->execute([$holdId]);
                $pdo->commit();
                log_activity('update', 'inventory', $holdId, 'Released curing hold #' . $holdId);
                set_flash('success', 'Curing hold released. The blocks are ready to sell.');
                header('Location: curing_start.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                set_flash('error', 'Could not release the curing hold.');
            }
        }
        header('Location: curing_start.php');
        exit;
    }

    $startDate = trim((string) ($_POST['start_date'] ?? date('Y-m-d')));
    $curingDays = min(365, max(0, (int) ($_POST['curing_days'] ?? production_default_curing_days())));
    $quantities = $_POST['hold_qty'] ?? [];

    if (!is_valid_date_string($startDate)) {
        $error = 'Choose a valid start date.';
    } else {
        $entries = [];
        if (is_array($quantities)) {
            foreach ($quantities as $blockId => $qty) {
                $blockId = (int) $blockId;
                $qty = (int) $qty;
                if ($blockId > 0 && $qty > 0) {
                    $entries[$blockId] = $qty;
                }
            }
        }

        if (!$entries) {
            $error = 'Enter a curing quantity for at least one block type.';
        } else {
            try {
                $pdo->beginTransaction();
                $readyDate = production_ready_date($startDate, $curingDays);
                $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $insert = $pdo->prepare("INSERT INTO curing_holds(block_type_id, quantity, curing_days, start_date, ready_date, notes, created_by)
                    VALUES(?,?,?,?,?,?,?)");
                foreach ($entries as $blockId => $qty) {
                    $insert->execute([
                        $blockId,
                        $qty,
                        $curingDays,
                        $startDate,
                        $readyDate,
                        'Previous production curing',
                        $createdBy,
                    ]);
                }
                $pdo->commit();
                log_activity('create', 'inventory', 0, 'Started curing hold for ' . count($entries) . ' block type(s) starting ' . $startDate);
                set_flash('success', 'Curing hold saved for ' . count($entries) . ' block type(s). Ready date: ' . $readyDate . '.');
                header('Location: curing_start.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not save the curing hold: ' . $e->getMessage();
            }
        }
    }
}

$blocks = $pdo->query("
    SELECT bt.id, bt.name, bt.size,
           COALESCE(fs.quantity, 0) AS total_qty
    FROM block_types bt
    LEFT JOIN finished_stock fs ON fs.block_type_id = bt.id
    WHERE bt.status = 1
    ORDER BY bt.name, bt.size
")->fetchAll();

$openingStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM finished_stock_transactions WHERE block_type_id = ? AND transaction_type = 'opening'");
$productionCuringStmt = $pdo->prepare("SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE block_type_id = ? AND COALESCE(ready_date, production_date) > CURDATE()");
$holdStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM curing_holds WHERE block_type_id = ? AND ready_date > CURDATE()");

foreach ($blocks as &$block) {
    $blockId = (int) $block['id'];
    $openingStmt->execute([$blockId]);
    $block['opening_qty'] = (int) $openingStmt->fetchColumn();
    $productionCuringStmt->execute([$blockId]);
    $block['production_curing'] = (int) $productionCuringStmt->fetchColumn();
    $holdStmt->execute([$blockId]);
    $block['active_hold'] = (int) $holdStmt->fetchColumn();
    // Only production-derived current stock can become curing; opening stock stays ready.
    $block['suggested'] = max(0, (int) $block['total_qty'] - $block['opening_qty'] - $block['production_curing'] - $block['active_hold']);
}
unset($block);

$activeHolds = $pdo->query("
    SELECT h.id, h.quantity, h.curing_days, h.start_date, h.ready_date,
           bt.name AS block_name, bt.size,
           GREATEST(0, DATEDIFF(h.ready_date, CURDATE())) AS days_remaining
    FROM curing_holds h
    JOIN block_types bt ON bt.id = h.block_type_id
    WHERE h.ready_date > CURDATE()
    ORDER BY h.ready_date, h.id
")->fetchAll();

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
table.curing-table td input {
    width: 90px;
    padding: 4px 6px;
}
.panel h2 { margin-top: 0; }
.btn-danger { background: #dc3545; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
.btn-danger:hover { background: #c82333; }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>🧊 Start Curing (Previous Production)</h1>
        <p style="color: #666; margin: 5px 0 0 0;">
            Put current finished stock into drying/curing so it is not sold until the ready date.
            Opening stock stays ready to sell.
        </p>
    </div>
    <div class="no-print" style="display: flex; gap: 10px;">
        <a href="index.php" class="btn btn-secondary">Back to Stock</a>
    </div>
</div>

<?php if ($flash): ?>
<div style="padding: 12px 16px; margin-bottom: 18px; border-radius: 6px; background: #e7f3ff; color: #0b4a8c; border: 1px solid #bfe3ff;">
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div style="padding: 12px 16px; margin-bottom: 18px; border-radius: 6px; background: #fbecec; color: #8b1a1a; border: 1px solid #f5c2c2;">
    <?= e($error) ?>
</div>
<?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <form method="post" action="curing_start.php">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="display:block;margin-bottom:5px;font-size:0.9rem;color:#555;">Start date</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>" required style="width:100%;padding:6px;">
            </div>
            <div>
                <label style="display:block;margin-bottom:5px;font-size:0.9rem;color:#555;">Curing days</label>
                <input type="number" name="curing_days" value="<?= (int) $curingDays ?>" min="0" max="365" required style="width:100%;padding:6px;">
            </div>
            <div style="align-self:end;">
                <button type="submit" class="btn" style="background:#e67e22;">Start Curing</button>
            </div>
        </div>

        <table class="data-table curing-table">
            <thead>
                <tr>
                    <th style="width:30%;">Block type</th>
                    <th style="width:12%;" style="text-align:right;">Total stock</th>
                    <th style="width:14%;" style="text-align:right;">Opening stock (stays ready)</th>
                    <th style="width:13%;" style="text-align:right;">Already curing</th>
                    <th style="width:13%;" style="text-align:right;">Suggested curing qty</th>
                    <th style="width:18%;" style="text-align:center;">Quantity to cure</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$blocks): ?>
                <tr><td colspan="6" style="text-align:center;color:#888;padding:20px;">No block types found.</td></tr>
                <?php endif; ?>
                <?php foreach ($blocks as $block): ?>
                <tr>
                    <td><?= e($block['name'] . ' × ' . $block['size']) ?></td>
                    <td style="text-align:right;"><?= number_format((int) $block['total_qty']) ?></td>
                    <td style="text-align:right;color:#666;"><?= number_format((int) $block['opening_qty']) ?></td>
                    <td style="text-align:right;color:#b45309;"><?= number_format((int) $block['production_curing']) ?></td>
                    <td style="text-align:right;color:#17a2b8;"><?= number_format((int) $block['suggested']) ?></td>
                    <td style="text-align:center;">
                        <input type="number" name="hold_qty[<?= (int) $block['id'] ?>]" value="<?= (int) $block['suggested'] ?>" min="0" style="width:100%;padding:6px;">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="font-size:0.85rem;color:#666;margin-top:12px;">
            Only the quantity you enter will become curing. The rest remains ready to sell.
            Opening stock and blocks already curing from a production batch are not counted twice.
        </p>
    </form>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h2 style="margin: 0 0 15px 0;">🕒 Active Curing Holds</h2>
    <?php if (!$activeHolds): ?>
        <p style="color: #888; padding: 20px 0; text-align: center;">No active curing holds.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:30%;">Block type</th>
                <th style="text-align:right;">Quantity</th>
                <th>Start date</th>
                <th>Ready date</th>
                <th style="text-align:right;">Days left</th>
                <th style="text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activeHolds as $hold): ?>
            <tr>
                <td><?= e($hold['block_name'] . ' × ' . $hold['size']) ?></td>
                <td style="text-align:right;"><?= number_format((int) $hold['quantity']) ?></td>
                <td><?= format_date($hold['start_date']) ?></td>
                <td><?= format_date($hold['ready_date']) ?></td>
                <td style="text-align:right;color:#b45309;"><strong><?= (int) $hold['days_remaining'] ?></strong> days</td>
                <td style="text-align:center;">
                    <form method="post" action="curing_start.php" style="margin:0;display:inline;" onsubmit="return confirm('Release this curing hold? The blocks will be ready to sell immediately.');">
                        <input type="hidden" name="action" value="release">
                        <input type="hidden" name="hold_id" value="<?= (int) $hold['id'] ?>">
                        <button type="submit" class="btn btn-danger">Release</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
