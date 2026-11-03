<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'create');
ensure_manufacturing_costing_schema();

$pageTitle = 'New Production Run';
$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$laborByBlock = [];
foreach ($blocks as $b) {
    $laborByBlock[(int) $b['id']] = (float) ($b['labor_cost_per_block'] ?? 0);
}
$materials = $pdo->query("SELECT * FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();

$bomByBlock = [];
try {
    $bomRows = $pdo->query("SELECT block_type_id, material_id, quantity_per_block FROM block_bom")->fetchAll();
    foreach ($bomRows as $row) {
        $bomByBlock[$row['block_type_id']][] = [
            'material_id' => (int) $row['material_id'],
            'qty_per_block' => (float) $row['quantity_per_block'],
        ];
    }
} catch (PDOException $e) {
    $bomByBlock = [];
}

$materialMeta = [];
foreach ($materials as $m) {
    $materialMeta[$m['id']] = ['name' => $m['name'], 'unit_cost' => (float) $m['unit_cost'], 'stock' => (float) $m['current_stock']];
}

$selBlock = (int) ($_GET['block_type'] ?? ($blocks[0]['id'] ?? 0));
$plannedQty = (int) ($_GET['planned'] ?? 0);
$matJson = [];
foreach ($materials as $m) {
    $matJson[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'unit_cost' => (float) $m['unit_cost']];
}

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>New Production Run</h1>
    <a href="index.php" class="btn btn-secondary"><?= t('cancel') ?></a>
</div>

<div class="panel" style="max-width:900px;">
    <form method="post" action="save.php" id="prodForm">
        <label>Date</label>
        <input type="date" name="production_date" value="<?= date('Y-m-d') ?>" required>

        <label>Block type</label>
        <select name="block_type_id" id="blockType" required>
            <?php foreach ($blocks as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $selBlock === (int) $x['id'] ? 'selected' : '' ?>>
                <?= e($x['name'] . ' - ' . $x['size']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <div class="grid-2">
            <div><label>Planned quantity</label><input type="number" name="planned_quantity" id="plannedQty" value="<?= $plannedQty ?>" min="0"></div>
            <div><label>Produced quantity *</label><input type="number" name="produced_quantity" id="producedQty" required min="1"></div>
        </div>
        <label>Rejected quantity</label>
        <input type="number" name="rejected_quantity" id="rejectedQty" value="0" min="0">

        <div class="grid-2">
            <div><label>Direct labor cost (<?= e(currency_symbol()) ?>)</label><input type="number" name="labor_cost" id="laborCost" min="0" step="0.01" value="0" required></div>
            <div><label>Factory overhead (<?= e(currency_symbol()) ?>)</label><input type="number" name="overhead_cost" min="0" step="0.01" value="0" required></div>
        </div>
        <p style="color:#667a80;font-size:.82rem;">Material cost is calculated from current raw-material weighted-average cost. Labor and overhead are allocated across good blocks. Direct labor starts at the BOM labor cost per block × produced quantity; you can override it.</p>

        <p id="goodPreview" style="color:#00897b;font-weight:600;"></p>

        <h3>Materials <small style="font-weight:normal;color:#64748b;">(auto-filled from BOM × produced qty)</small></h3>
        <div id="materialRows"></div>
        <button type="button" class="btn btn-secondary" id="addRow" style="margin-top:8px;">+ Add material line</button>

        <label>Notes</label>
        <textarea name="notes" rows="2"></textarea>

        <button type="submit" class="btn btn-accent" style="margin-top:16px;">Save production</button>
    </form>
</div>

<script>
const bom = <?= json_encode($bomByBlock) ?>;
const materials = <?= json_encode($matJson) ?>;
const laborPerBlock = <?= json_encode($laborByBlock) ?>;
let laborTouched = false;
const laborInput = document.getElementById('laborCost');
laborInput.addEventListener('input', () => { laborTouched = true; });

function suggestLabor() {
    if (laborTouched) {
        return;
    }
    const blockId = document.getElementById('blockType').value;
    const produced = parseFloat(document.getElementById('producedQty').value) || 0;
    const perBlock = parseFloat(laborPerBlock[blockId] || 0);
    laborInput.value = (perBlock * produced).toFixed(2);
}

function materialOptions(selected) {
    return materials.map(m => `<option value="${m.id}" ${m.id == selected ? 'selected' : ''}>${m.name}</option>`).join('');
}

function addRow(mid = '', qty = '', cost = '') {
    const div = document.createElement('div');
    div.className = 'filter-form';
    div.style.marginBottom = '8px';
    div.innerHTML = `
        <select name="material_id[]" required>${materialOptions(mid)}</select>
        <input name="material_quantity[]" type="number" step="0.01" placeholder="Qty" value="${qty}" required>
        <input type="number" step="0.01" placeholder="Unit cost" value="${cost}" readonly title="Unit cost from the raw-material weighted average (set by purchasing)" style="background:#f1f5f9;">
        <button type="button" class="btn-sm" style="background:#c0392b;border:0;cursor:pointer;" onclick="this.parentElement.remove()">×</button>`;
    document.getElementById('materialRows').appendChild(div);
}

function applyBom() {
    const blockId = document.getElementById('blockType').value;
    const produced = parseFloat(document.getElementById('producedQty').value) || 0;
    document.getElementById('materialRows').innerHTML = '';
    const lines = bom[blockId] || [];
    if (lines.length && produced > 0) {
        lines.forEach(line => {
            const mat = materials.find(m => m.id == line.material_id);
            const qty = (line.qty_per_block * produced).toFixed(4);
            addRow(line.material_id, qty, mat ? mat.unit_cost : '');
        });
    } else if (materials.length) {
        addRow(materials[0].id, '', materials[0].unit_cost);
    }
    suggestLabor();
}

function updateGood() {
    const p = parseInt(document.getElementById('producedQty').value, 10) || 0;
    const r = parseInt(document.getElementById('rejectedQty').value, 10) || 0;
    document.getElementById('goodPreview').textContent = p ? `Good quantity: ${Math.max(0, p - r)}` : '';
}

document.getElementById('blockType').addEventListener('change', applyBom);
document.getElementById('producedQty').addEventListener('input', () => { updateGood(); applyBom(); });
document.getElementById('rejectedQty').addEventListener('input', updateGood);
document.getElementById('addRow').addEventListener('click', () => addRow());

applyBom();
updateGood();
</script>

<?php require_once "../partials/footer.php"; ?>
