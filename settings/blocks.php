<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'edit');

$pageTitle = "Block Types";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_block') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $priceRaw = trim($_POST['selling_price'] ?? '');
        $yieldRaw = trim($_POST['yield_factor'] ?? '1');

        if ($name === '' || mb_strlen($name) > 100) {
            set_flash('danger', 'Block name is required (maximum 100 characters).');
            header('Location: blocks.php');
            exit;
        }
        if ($size === '' || mb_strlen($size) > 50) {
            set_flash('danger', 'Size is required (maximum 50 characters).');
            header('Location: blocks.php');
            exit;
        }
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 99999999) {
            set_flash('danger', 'Selling price must be a number between 0 and 99,999,999.');
            header('Location: blocks.php');
            exit;
        }
        if ($yieldRaw === '' || !is_numeric($yieldRaw) || (float) $yieldRaw <= 0 || (float) $yieldRaw > 9999.9999) {
            set_flash('danger', 'Yield factor must be a number greater than 0 and up to 9,999.9999.');
            header('Location: blocks.php');
            exit;
        }
        $price = round((float) $priceRaw, 2);
        $yieldFactor = round((float) $yieldRaw, 4);

        try {
            if ($id > 0) {
                $exists = $pdo->prepare("SELECT id FROM block_types WHERE id = ?");
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    set_flash('danger', 'That block type no longer exists.');
                    header('Location: blocks.php');
                    exit;
                }
                $pdo->prepare("UPDATE block_types SET name = ?, size = ?, selling_price = ?, yield_factor = ? WHERE id = ?")
                    ->execute([$name, $size, $price, $yieldFactor, $id]);
                log_activity('update', 'settings', $id, "Block type updated: {$name} ({$size}) at {$price} with yield {$yieldFactor}");
                set_flash('success', 'Block type updated.');
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO block_types(name, size, selling_price, yield_factor, status) VALUES(?,?,?,?,1)")
                    ->execute([$name, $size, $price, $yieldFactor]);
                $newId = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO finished_stock(block_type_id, quantity) VALUES(?, 0)")
                    ->execute([$newId]);
                $pdo->commit();
                log_activity('create', 'settings', $newId, "Block type created: {$name} ({$size}) at {$price} with yield {$yieldFactor}");
                set_flash('success', 'Block type added and added to finished stock.');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $duplicate = $e->getCode() === '23000';
            set_flash('danger', $duplicate
                ? 'A block type with the same name and size already exists.'
                : 'Could not save the block type. Please try again.');
        }
        header('Location: blocks.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name, size, status FROM block_types WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();
        if ($block) {
            $newStatus = empty($block['status']) ? 1 : 0;
            $pdo->prepare("UPDATE block_types SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('update', 'settings', $id, 'Block type ' . ($newStatus ? 'activated' : 'deactivated') . ": {$block['name']} ({$block['size']})");
            set_flash('success', 'Block type ' . ($newStatus ? 'activated' : 'deactivated') . '.');
        }
        header('Location: blocks.php');
        exit;
    }

    if ($action === 'delete_block') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name, size FROM block_types WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();
        if (!$block) {
            set_flash('danger', 'That block type no longer exists.');
            header('Location: blocks.php');
            exit;
        }

        $references = 0;
        foreach (['sales' => 'SELECT COUNT(*) FROM sale_items WHERE block_type_id = ?',
                  'quotations' => 'SELECT COUNT(*) FROM quotation_items WHERE block_type_id = ?',
                  'production' => 'SELECT COUNT(*) FROM production WHERE block_type_id = ?',
                  'production schedules' => 'SELECT COUNT(*) FROM production_schedules WHERE block_type_id = ?',
                  'production targets' => 'SELECT COUNT(*) FROM production_targets WHERE block_type_id = ?',
                  'stock transactions' => 'SELECT COUNT(*) FROM finished_stock_transactions WHERE block_type_id = ?',
                  'recipes' => 'SELECT COUNT(*) FROM block_bom WHERE block_type_id = ?'] as $label => $sql) {
            $check = $pdo->prepare($sql);
            $check->execute([$id]);
            $references += (int) $check->fetchColumn();
        }
        $stock = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM finished_stock WHERE block_type_id = ?");
        $stock->execute([$id]);
        $onHand = (float) $stock->fetchColumn();

        if ($references > 0 || $onHand > 0) {
            set_flash('danger', 'This block type has stock on hand or is used by sales, quotations, or production records. Deactivate it instead.');
            header('Location: blocks.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM finished_stock WHERE block_type_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM block_types WHERE id = ?")->execute([$id]);
            $pdo->commit();
            log_activity('delete', 'settings', $id, "Block type deleted: {$block['name']} ({$block['size']})");
            set_flash('success', 'Block type deleted.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', 'This block type is still referenced by other records. Deactivate it instead.');
        }
        header('Location: blocks.php');
        exit;
    }

    header('Location: blocks.php');
    exit;
}

$editId = (int) ($_GET['edit'] ?? 0);
$editBlock = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM block_types WHERE id = ?");
    $stmt->execute([$editId]);
    $editBlock = $stmt->fetch() ?: null;
}

$blocks = $pdo->query("SELECT bt.*, COALESCE(fs.quantity, 0) AS stock_qty, COALESCE(fs.average_unit_cost, 0) AS unit_cost
    FROM block_types bt
    LEFT JOIN finished_stock fs ON fs.block_type_id = bt.id
    ORDER BY bt.status DESC, bt.name, bt.size")->fetchAll();

$totalTypes = count($blocks);
$activeTypes = count(array_filter($blocks, static fn($b) => (int) $b['status'] === 1));
$totalStock = array_sum(array_map(static fn($b) => (float) $b['stock_qty'], $blocks));
$stockValue = array_sum(array_map(static fn($b) => (float) $b['stock_qty'] * (float) $b['unit_cost'], $blocks));

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .block-grid { display:grid; grid-template-columns: minmax(300px, 1fr) minmax(0, 2fr); gap:20px; align-items:start; }
    @media (max-width: 900px) { .block-grid { grid-template-columns: 1fr; } }
    .kpi-row { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    .kpi-card { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #246a73; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .kpi-card span { display:block; color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-card strong { display:block; margin-top:4px; color:#183b56; font-size:1.35rem; }
    .block-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .actions-inline { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .kpi-card, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🧱 Block Types</h1>
        <p style="color:#64748b;margin:6px 0 0;">Define product sizes, selling prices, and availability. Every block type is linked to finished stock automatically.</p>
    </div>
    <div class="header-actions no-print">
        <a href="company.php" class="btn btn-secondary">Company settings</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="kpi-row">
    <div class="kpi-card"><span>Block types</span><strong><?= number_format($totalTypes) ?></strong></div>
    <div class="kpi-card"><span>Active</span><strong><?= number_format($activeTypes) ?></strong></div>
    <div class="kpi-card"><span>Units in stock</span><strong><?= number_format($totalStock, 2) ?></strong></div>
    <div class="kpi-card"><span>Stock value (cost)</span><strong><?= e(currency_symbol()) ?><?= money($stockValue) ?></strong></div>
</div>

<div class="block-grid">
    <div class="panel no-print">
        <h3><?= $editBlock ? 'Edit block type' : 'Add block type' ?></h3>
        <form method="post" class="block-form">
            <input type="hidden" name="action" value="save_block">
            <input type="hidden" name="id" value="<?= $editBlock ? (int) $editBlock['id'] : 0 ?>">

            <label>Block name *</label>
            <input type="text" name="name" maxlength="100" required value="<?= e($editBlock['name'] ?? '') ?>" placeholder="e.g., Hollow Block">

            <label style="margin-top:12px;">Size *</label>
            <input type="text" name="size" maxlength="50" required value="<?= e($editBlock['size'] ?? '') ?>" placeholder="e.g., 10 cm, 15 cm">

            <label style="margin-top:12px;">Selling price (<?= e(currency_symbol()) ?>) *</label>
            <input type="number" step="0.01" min="0" max="99999999" name="selling_price" required value="<?= e($editBlock['selling_price'] ?? '') ?>">
            <p class="hint">Price must be zero or greater. Prices are per block.</p>

            <label style="margin-top:12px;">Yield factor *</label>
            <input type="number" step="0.0001" min="0.0001" max="9999.9999" name="yield_factor" required value="<?= e($editBlock['yield_factor'] ?? 1) ?>">
            <p class="hint">Used to estimate produced quantity: cement bags × yield factor.</p>

            <div class="actions-inline" style="margin-top:18px;">
                <button type="submit" class="btn btn-accent"><?= $editBlock ? 'Save changes' : 'Add block type' ?></button>
                <?php if ($editBlock): ?>
                <a href="blocks.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3>Block types (<?= number_format($totalTypes) ?>)</h3>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Block name</th>
                        <th>Size</th>
                        <th style="text-align:right;">Selling price</th>
                        <th style="text-align:right;">Yield</th>
                        <th style="text-align:right;">On hand</th>
                        <th style="text-align:right;">Unit cost</th>
                        <th>Status</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$blocks): ?>
                    <tr><td colspan="8">No block types configured yet. Add the first product on the left.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($blocks as $block): ?>
                    <tr>
                        <td><strong><?= e($block['name']) ?></strong></td>
                        <td><?= e($block['size']) ?></td>
                        <td style="text-align:right;"><?= e(currency_symbol()) ?><?= money($block['selling_price']) ?></td>
                        <td style="text-align:right;"><?= number_format((float) ($block['yield_factor'] ?? 1), 4) ?></td>
                        <td style="text-align:right;"><?= number_format((float) $block['stock_qty'], 2) ?></td>
                        <td style="text-align:right;"><?= e(currency_symbol()) ?><?= money($block['unit_cost']) ?></td>
                        <td>
                            <span class="badge <?= $block['status'] ? 'badge-success' : 'badge-muted' ?>">
                                <?= $block['status'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="no-print">
                            <div class="actions-inline">
                                <a class="btn btn-sm btn-secondary" href="blocks.php?edit=<?= (int) $block['id'] ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                    <button type="submit" class="btn btn-sm"><?= $block['status'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this block type? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_block">
                                    <input type="hidden" name="id" value="<?= (int) $block['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="color:#64748b;font-size:.82rem;margin-top:12px;">Block types with stock or transaction history cannot be deleted; deactivate them instead.</p>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
