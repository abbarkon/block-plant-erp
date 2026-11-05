<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'edit');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    render_not_found('Production record not found', 'The production record you requested does not exist.', 'index.php', 'Return to production records');
    exit;
}

$stmt = $pdo->prepare("SELECT p.*, bt.name AS block_name, bt.size, bt.yield_factor, bt.labor_cost_per_block, bt.overhead_cost_per_block FROM production p JOIN block_types bt ON bt.id = p.block_type_id WHERE p.id = ?");
$stmt->execute([$id]);
$production = $stmt->fetch();
if (!$production) {
    render_not_found('Production record not found', 'The production record you requested does not exist.', 'index.php', 'Return to production records');
    exit;
}

$pageTitle = 'Edit Production Record';
$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$blockYieldByBlock = [];
$laborByBlock = [];
$overheadByBlock = [];
foreach ($blocks as $b) {
    $blockYieldByBlock[(int) $b['id']] = (float) ($b['yield_factor'] ?? 1);
    $laborByBlock[(int) $b['id']] = (float) ($b['labor_cost_per_block'] ?? 0);
    $overheadByBlock[(int) $b['id']] = (float) ($b['overhead_cost_per_block'] ?? 0);
}

$materials = $pdo->query("SELECT * FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();
$matJson = [];
foreach ($materials as $m) {
    $matJson[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'unit_cost' => (float) $m['unit_cost']];
}

$bomByBlock = [];
try {
    $bomRows = $pdo->query("SELECT block_type_id, material_id, quantity_per_block FROM block_bom")->fetchAll();
    foreach ($bomRows as $row) {
        $bomByBlock[(int) $row['block_type_id']][] = [
            'material_id' => (int) $row['material_id'],
            'qty_per_block' => (float) $row['quantity_per_block'],
        ];
    }
} catch (PDOException $e) {
    $bomByBlock = [];
}

$materialMap = [];
foreach ($materials as $m) {
    $materialMap[$m['id']] = ['name' => $m['name'], 'unit_cost' => (float) $m['unit_cost']];
}

$existingMaterials = $pdo->prepare("SELECT pm.material_id, pm.quantity, rm.unit_cost FROM production_materials pm JOIN raw_materials rm ON rm.id = pm.material_id WHERE pm.production_id = ? ORDER BY pm.id");
$existingMaterials->execute([$id]);
$existingMaterialRows = $existingMaterials->fetchAll(PDO::FETCH_ASSOC);
foreach ($existingMaterialRows as &$row) {
    $row['unit_cost'] = (float) ($row['unit_cost'] ?? 0);
    $row['quantity'] = (float) ($row['quantity'] ?? 0);
}
unset($row);

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Edit Production Record</h1>
    <a href="view.php?id=<?= (int) $id ?>" class="btn btn-secondary">Cancel</a>
</div>

<div class="panel" style="max-width:940px;">
    <form method="post" action="save.php" id="prodForm">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="production_id" value="<?= (int) $id ?>">

        <label>Date</label>
        <input type="date" name="production_date" value="<?= e($production['production_date']) ?>" required>

        <label>Block type</label>
        <select name="block_type_id" id="blockType" required>
            <?php foreach ($blocks as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= (int) $production['block_type_id'] === (int) $x['id'] ? 'selected' : '' ?>>
                <?= e($x['name'] . ' - ' . $x['size']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <div class="grid-2">
            <div><label>Cement qty</label><input type="number" name="planned_quantity" id="plannedQty" value="<?= (float) $production['planned_quantity'] ?>" min="0" step="0.01" placeholder="Cement bags"></div>
            <div><label>Produced quantity *</label><input type="number" name="produced_quantity" id="producedQty" required min="1" value="<?= (float) $production['produced_quantity'] ?>"></div>
        </div>

        <label>Rejected quantity</label>
        <input type="number" name="rejected_quantity" id="rejectedQty" value="<?= (float) $production['rejected_quantity'] ?>" min="0">

        <div class="grid-2">
            <div><label>Direct labor cost (<?= e(currency_symbol()) ?>)</label><input type="number" name="labor_cost" id="laborCost" min="0" step="0.01" value="<?= (float) ($production['labor_cost'] ?? 0) ?>" required></div>
            <div><label>Factory overhead (<?= e(currency_symbol()) ?>)</label><input type="number" name="overhead_cost" id="overheadCost" min="0" step="0.01" value="<?= (float) ($production['overhead_cost'] ?? 0) ?>" required></div>
        </div>

        <p id="goodPreview" style="color:#00897b;font-weight:600;"></p>

        <h3>Materials <small style="font-weight:normal;color:#64748b;">(auto-filled from BOM × produced qty)</small></h3>
        <div id="materialRows"></div>
        <button type="button" class="btn btn-secondary" id="addRow" style="margin-top:8px;">+ Add material line</button>

        <label>Notes</label>
        <textarea name="notes" rows="2"><?= e($production['notes'] ?? '') ?></textarea>

        <button type="submit" class="btn btn-accent" style="margin-top:16px;">Update production</button>
    </form>
</div>

<script>
const bom = <?= json_encode($bomByBlock) ?>;
const materials = <?= json_encode($matJson) ?>;
const laborPerBlock = <?= json_encode($laborByBlock) ?>;
const overheadPerBlock = <?= json_encode($overheadByBlock) ?>;
const blockYieldByBlock = <?= json_encode($blockYieldByBlock) ?>;
const existingMaterialRows = <?= json_encode($existingMaterialRows) ?>;
let laborTouched = false;
let producedManualOverride = false;
let overheadTouched = false;
const laborInput = document.getElementById('laborCost');
const producedInput = document.getElementById('producedQty');
const cementInput = document.getElementById('plannedQty');
const overheadInput = document.getElementById('overheadCost');
const blockTypeInput = document.getElementById('blockType');

if (laborInput) laborInput.addEventListener('input', () => { laborTouched = true; });
if (overheadInput) overheadInput.addEventListener('input', () => { overheadTouched = true; });

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
        <input type="number" step="0.01" placeholder="Unit cost" value="${cost}" readonly title="Unit cost from the raw-material weighted average" style="background:#f1f5f9;">
        <button type="button" class="btn-sm" style="background:#c0392b;border:0;cursor:pointer;" onclick="this.parentElement.remove()">×</button>`;
    document.getElementById('materialRows').appendChild(div);
}

function renderBomForProduced(producedQty, cementQty) {
    const blockId = blockTypeInput ? blockTypeInput.value : '';
    const materialRows = document.getElementById('materialRows');
    if (!materialRows) return;
    materialRows.innerHTML = '';
    const lines = bom[blockId] || [];
    if (lines.length) {
        const cementLine = lines.find(line => {
            const mat = materials.find(m => m.id == line.material_id);
            const name = (mat && mat.name ? mat.name.toLowerCase() : '');
            return name.includes('cement');
        });
        lines.forEach(line => {
            const mat = materials.find(m => m.id == line.material_id);
            let qty = 0;
            if (cementLine && line.material_id === cementLine.material_id && cementQty > 0) {
                qty = cementQty;
            } else if (producedQty > 0) {
                qty = line.qty_per_block * producedQty;
            }
            addRow(line.material_id, qty ? qty.toFixed(4) : '', mat ? mat.unit_cost : '');
        });
    } else if (materials.length) {
        addRow(materials[0].id, '', materials[0].unit_cost);
    }
    suggestLabor();
}

function suggestLabor() {
    if (!laborInput || laborTouched) return;
    const blockId = blockTypeInput ? blockTypeInput.value : '';
    const produced = parseFloat(producedInput ? producedInput.value : 0) || 0;
    const perBlock = parseFloat(laborPerBlock[blockId] || 0);
    laborInput.value = (perBlock * produced).toFixed(2);
}

function suggestOverhead() {
    if (!overheadInput || overheadTouched) return;
    const blockId = blockTypeInput ? blockTypeInput.value : '';
    const produced = parseFloat(producedInput ? producedInput.value : 0) || 0;
    const perBlock = parseFloat(overheadPerBlock[blockId] || 0);
    if (!Number.isFinite(perBlock)) {
        overheadInput.value = '0.00';
        return;
    }
    overheadInput.value = (perBlock * produced).toFixed(2);
}

function suggestProducedFromCement() {
    if (producedManualOverride) return;
    const blockId = blockTypeInput ? blockTypeInput.value : '';
    const yieldFactor = parseFloat(blockYieldByBlock[blockId] || 1);
    const cementQty = parseFloat(cementInput.value) || 0;
    if (!cementQty || !yieldFactor || !Number.isFinite(yieldFactor)) {
        if (producedInput) producedInput.value = '';
        renderBomForProduced(0, 0);
        updateGood();
        return;
    }
    const suggested = Number((cementQty * yieldFactor).toFixed(4));
    if (producedInput) producedInput.value = suggested;
    renderBomForProduced(suggested, cementQty);
    updateGood();
    suggestOverhead();
}

function updateGood() {
    const p = parseInt(document.getElementById('producedQty').value, 10) || 0;
    const r = parseInt(document.getElementById('rejectedQty').value, 10) || 0;
    document.getElementById('goodPreview').textContent = p ? `Good quantity: ${Math.max(0, p - r)}` : '';
}

function hydrateExistingRows() {
    const rows = Array.isArray(existingMaterialRows) ? existingMaterialRows : [];
    if (!rows.length) {
        renderBomForProduced(parseFloat(producedInput.value || 0), parseFloat(cementInput.value || 0));
        return;
    }
    const container = document.getElementById('materialRows');
    if (!container) return;
    container.innerHTML = '';
    rows.forEach((row) => {
        const quantity = Number(row.quantity || 0);
        const unitCost = Number(row.unit_cost || 0);
        addRow(row.material_id, quantity.toFixed(4), unitCost.toFixed(2));
    });
}

const cementPopup = document.getElementById('cementPopup');
if (cementPopup) {
    const cementPopupInput = document.getElementById('cementPopupInput');
    const openCementPopup = () => {
        cementPopupInput.value = cementInput.value || '';
        cementPopup.style.display = 'flex';
        setTimeout(() => cementPopupInput.focus(), 50);
    };
    const closeCementPopup = () => { cementPopup.style.display = 'none'; };
    document.getElementById('blockType').addEventListener('change', () => {
        producedManualOverride = false;
        overheadTouched = false;
        cementInput.value = '';
        producedInput.value = '';
        renderBomForProduced(0, 0);
        suggestOverhead();
        openCementPopup();
    });
    document.getElementById('cementPopupApply').addEventListener('click', () => {
        cementInput.value = cementPopupInput.value;
        closeCementPopup();
        if (parseFloat(cementInput.value) > 0) suggestProducedFromCement();
        suggestOverhead();
    });
    document.getElementById('cementPopupCancel').addEventListener('click', closeCementPopup);
    document.getElementById('cementPopupClose').addEventListener('click', closeCementPopup);
    cementPopup.addEventListener('click', (event) => {
        if (event.target === cementPopup) closeCementPopup();
    });
}

if (cementInput) {
    cementInput.addEventListener('input', () => {
        producedManualOverride = false;
        suggestProducedFromCement();
    });
}

if (producedInput) {
    producedInput.addEventListener('focus', () => { producedManualOverride = true; });
    producedInput.addEventListener('input', () => {
        producedManualOverride = true;
        updateGood();
        suggestOverhead();
    });
}

const rejectedInput = document.getElementById('rejectedQty');
if (rejectedInput) rejectedInput.addEventListener('input', updateGood);

const addRowButton = document.getElementById('addRow');
if (addRowButton) addRowButton.addEventListener('click', () => addRow());

const materialRowsContainer = document.getElementById('materialRows');
if (materialRowsContainer) {
    materialRowsContainer.addEventListener('input', (event) => {
        if (event.target.matches('input[name="material_quantity[]"]')) {
            producedManualOverride = false;
            suggestProducedFromCement();
        }
    });
}

hydrateExistingRows();
updateGood();
if (overheadInput) suggestOverhead();
</script>

<?php require_once "../partials/footer.php"; ?>
