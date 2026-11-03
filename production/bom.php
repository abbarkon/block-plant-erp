<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'view');
ensure_manufacturing_costing_schema();

$pageTitle = 'Bill of Materials';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('production', 'edit')) {
    if (isset($_POST['add_line'])) {
        $stmt = $pdo->prepare("INSERT INTO block_bom(block_type_id, material_id, quantity_per_block, notes) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE quantity_per_block=VALUES(quantity_per_block), notes=VALUES(notes)");
        $stmt->execute([
            (int) $_POST['block_type_id'],
            (int) $_POST['material_id'],
            (float) $_POST['quantity_per_block'],
            trim($_POST['notes'] ?? ''),
        ]);
        log_activity('update', 'production', (int) $_POST['block_type_id'], 'BOM updated');
        header('Location: bom.php?block_type_id=' . (int) $_POST['block_type_id']);
        exit;
    }
    if (isset($_POST['save_labor'])) {
        $blockTypeId = (int) ($_POST['labor_block_type_id'] ?? 0);
        $laborPerBlock = round((float) ($_POST['labor_cost_per_block'] ?? 0), 4);
        if ($blockTypeId <= 0 || $laborPerBlock < 0 || $laborPerBlock > 99999999) {
            set_flash('danger', 'Enter a valid direct labor cost per block (0 or greater).');
            header('Location: bom.php' . ($blockTypeId > 0 ? '?block_type_id=' . $blockTypeId : ''));
            exit;
        }
        $pdo->prepare("UPDATE block_types SET labor_cost_per_block = ? WHERE id = ?")
            ->execute([$laborPerBlock, $blockTypeId]);
        log_activity('update', 'production', $blockTypeId, 'BOM direct labor cost per block set to ' . number_format($laborPerBlock, 4));
        set_flash('success', 'Direct labor cost per block saved.');
        header('Location: bom.php?block_type_id=' . $blockTypeId);
        exit;
    }
    if (isset($_POST['delete_id'])) {
        $pdo->prepare("DELETE FROM block_bom WHERE id=?")->execute([(int) $_POST['delete_id']]);
        header('Location: bom.php');
        exit;
    }
}

$blockFilter = (int) ($_GET['block_type_id'] ?? 0);
$blocks = $pdo->query("SELECT id, name, size, selling_price, labor_cost_per_block FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$materials = $pdo->query("SELECT id, name, unit, unit_cost FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();
$laborByBlock = [];
foreach ($blocks as $b) {
    $laborByBlock[(int) $b['id']] = (float) $b['labor_cost_per_block'];
}

$sql = "SELECT bb.*, bt.name AS block_name, bt.size, rm.name AS material_name, rm.unit, rm.unit_cost
        FROM block_bom bb
        JOIN block_types bt ON bb.block_type_id = bt.id
        JOIN raw_materials rm ON bb.material_id = rm.id";
if ($blockFilter) {
    $sql .= " WHERE bb.block_type_id = " . $blockFilter;
}
$sql .= " ORDER BY bt.name, rm.name";
$lines = $pdo->query($sql)->fetchAll();

$summarySql = "SELECT bt.id, bt.name, bt.size, bt.selling_price, bt.labor_cost_per_block,
                      COALESCE(SUM(bb.quantity_per_block * rm.unit_cost), 0) AS material_cost,
                      COUNT(bb.id) AS material_lines
               FROM block_types bt
               LEFT JOIN block_bom bb ON bb.block_type_id = bt.id
               LEFT JOIN raw_materials rm ON rm.id = bb.material_id
               WHERE bt.status = 1";
if ($blockFilter) {
    $summarySql .= " AND bt.id = " . $blockFilter;
}
$summarySql .= " GROUP BY bt.id, bt.name, bt.size, bt.selling_price, bt.labor_cost_per_block
                 ORDER BY bt.name, bt.size";
$costSummary = $pdo->query($summarySql)->fetchAll();

$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Bill of Materials (BOM)</h1>
    <div class="header-actions">
        <a href="analytics.php" class="btn btn-secondary">Production analytics</a>
        <a href="schedule.php" class="btn btn-secondary">Schedule</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="filter-section">
    <form method="get" class="filter-form">
        <select name="block_type_id" onchange="this.form.submit()">
            <option value="">All block types</option>
            <?php foreach ($blocks as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= $blockFilter === (int) $b['id'] ? 'selected' : '' ?>>
                <?= e($b['name'] . ' ' . $b['size']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (has_permission('production', 'edit')): ?>
<div class="grid-2">
    <div class="panel">
        <h3>Add / update BOM line</h3>
        <form method="post" class="filter-form">
            <input type="hidden" name="add_line" value="1">
            <select name="block_type_id" required>
                <?php foreach ($blocks as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= $blockFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' ' . $b['size']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="material_id" required>
                <?php foreach ($materials as $m): ?>
                <option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="number" step="0.0001" name="quantity_per_block" placeholder="Qty per block" required>
            <input type="text" name="notes" placeholder="Notes">
            <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
        </form>
    </div>

    <div class="panel">
        <h3>Direct labor cost per block</h3>
        <p style="color:#64748b;font-size:.85rem;margin:0 0 10px;">Labor is entered once per block type. Production runs use it to suggest their direct labor cost.</p>
        <form method="post" class="filter-form">
            <input type="hidden" name="save_labor" value="1">
            <select name="labor_block_type_id" id="laborBlockType" required>
                <?php foreach ($blocks as $b): ?>
                <option value="<?= (int) $b['id'] ?>" <?= $blockFilter === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name'] . ' ' . $b['size']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" step="0.0001" min="0" max="99999999" name="labor_cost_per_block" id="laborCostInput" placeholder="Labor cost / block" required value="<?= e($laborByBlock[$blockFilter] ?? 0) ?>">
            <button type="submit" class="btn btn-accent"><?= t('save') ?></button>
        </form>
        <p style="color:#64748b;font-size:.8rem;margin:8px 0 0;">Amount in <?= e(currency_symbol()) ?> per block. Set it to 0 if labor is not tracked per block.</p>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Cost per block</h3>
    <div class="table-container" style="box-shadow:none;border:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Block type</th>
                    <th style="text-align:right;">Materials / block</th>
                    <th style="text-align:right;">Direct labor / block</th>
                    <th style="text-align:right;">Total cost / block</th>
                    <th style="text-align:right;">Selling price</th>
                    <th style="text-align:right;">Margin / block</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$costSummary): ?>
                <tr><td colspan="6">No active block types yet. Add a block type in Settings &rarr; Block Types.</td></tr>
                <?php endif; ?>
                <?php foreach ($costSummary as $row): ?>
                <?php
                    $materialCost = (float) $row['material_cost'];
                    $laborCost = (float) $row['labor_cost_per_block'];
                    $totalCost = $materialCost + $laborCost;
                    $margin = (float) $row['selling_price'] - $totalCost;
                ?>
                <tr>
                    <td><strong><?= e($row['name'] . ' ' . $row['size']) ?></strong></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) ?><?= money($materialCost) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) ?><?= money($laborCost) ?></td>
                    <td style="text-align:right;"><strong><?= e(currency_symbol()) ?><?= money($totalCost) ?></strong></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) ?><?= money($row['selling_price']) ?></td>
                    <td style="text-align:right;color:<?= $margin < 0 ? '#c0392b' : '#0f766e' ?>;"><?= e(currency_symbol()) ?><?= money($margin) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="color:#64748b;font-size:.82rem;margin-top:12px;">Material cost uses the current weighted-average cost of each raw material. Total cost per block = materials + direct labor.</p>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Block type</th>
                <th>Material</th>
                <th>Qty / block</th>
                <th>Est. material cost / block</th>
                <th>Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
            <tr>
                <td><?= e($line['block_name'] . ' ' . $line['size']) ?></td>
                <td><?= e($line['material_name']) ?> (<?= e($line['unit']) ?>)</td>
                <td><?= number_format($line['quantity_per_block'], 4) ?></td>
                <td><?= currency_symbol() . money($line['quantity_per_block'] * $line['unit_cost']) ?></td>
                <td><?= e($line['notes'] ?? '') ?></td>
                <td>
                    <?php if (has_permission('production', 'edit')): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove this BOM line?');">
                        <input type="hidden" name="delete_id" value="<?= (int) $line['id'] ?>">
                        <button type="submit" class="btn-sm" style="background:#c0392b;border:0;cursor:pointer;">Remove</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (has_permission('production', 'edit')): ?>
<script>
const laborByBlock = <?= json_encode($laborByBlock) ?>;
const laborSelect = document.getElementById('laborBlockType');
const laborInput = document.getElementById('laborCostInput');
if (laborSelect && laborInput) {
    laborSelect.addEventListener('change', function () {
        laborInput.value = laborByBlock[this.value] ?? '0';
    });
}
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
