<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'create');
ensure_manufacturing_costing_schema();

$pageTitle = 'New Production Run';
$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$blockYieldByBlock = [];
foreach ($blocks as $b) {
    $blockYieldByBlock[(int) $b['id']] = 1.0;
    $laborByBlock[(int) $b['id']] = (float) ($b['labor_cost_per_block'] ?? 0);
}
try {
    $yieldRows = $pdo->query("SELECT id, yield_factor FROM block_types WHERE yield_factor IS NOT NULL")->fetchAll();
    foreach ($yieldRows as $row) {
        $blockYieldByBlock[(int) $row['id']] = (float) $row['yield_factor'];
    }
} catch (PDOException $e) {
    // Older databases do not have the optional yield_factor column; keep the default fallback.
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

<div id="cementPopup" style="position:fixed; inset:0; background:rgba(15,23,42,.5); display:flex; align-items:center; justify-content:center; z-index:2000; display:none;">
    <div style="background:#fff; width:min(420px, calc(100% - 24px)); border-radius:18px; padding:22px 20px; box-shadow:0 18px 46px rgba(15,23,42,.35);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h3 style="margin:0;">Enter cement qty</h3>
            <button type="button" id="cementPopupClose" class="btn btn-secondary btn-sm" aria-label="Close">Close</button>
        </div>
        <p style="margin:0 0 14px; color:#64748b;">Select the cement quantity for this block type. The produced quantity and BOM will update automatically.</p>
        <label for="cementPopupInput">Cement qty</label>
        <input type="number" id="cementPopupInput" min="0" step="0.01" placeholder="e.g. 100" style="width:100%; margin-bottom:16px;">
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" id="cementPopupCancel" class="btn btn-secondary">Cancel</button>
            <button type="button" id="cementPopupApply" class="btn btn-accent">Apply</button>
        </div>
    </div>
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
            <div><label>Cement qty</label><input type="number" name="planned_quantity" id="plannedQty" value="<?= $plannedQty ?>" min="0" step="0.01" placeholder="Cement bags"></div>
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
const blockYieldByBlock = <?= json_encode($blockYieldByBlock) ?>;
let laborTouched = false;
let producedManualOverride = false;
const laborInput = document.getElementById('laborCost');
const producedInput = document.getElementById('producedQty');
const cementInput = document.getElementById('plannedQty');
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

function getCementQuantity() {
    let total = 0;
    const rows = document.querySelectorAll('#materialRows .filter-form');
    rows.forEach(row => {
        const materialSelect = row.querySelector('select[name="material_id[]"]');
        const quantityInput = row.querySelector('input[name="material_quantity[]"]');
        if (!materialSelect || !quantityInput) {
            return;
        }
        const materialId = Number(materialSelect.value);
        const material = materials.find(m => m.id === materialId);
        const name = (material && material.name ? material.name.toLowerCase() : '');
        if (name.includes('cement')) {
            total += parseFloat(quantityInput.value) || 0;
        }
    });
    return total;
}

function suggestProducedFromCement() {
    if (producedManualOverride) {
        return;
    }
    const blockId = document.getElementById('blockType').value;
    const yieldFactor = parseFloat(blockYieldByBlock[blockId] || 1);
    const cementQty = parseFloat(cementInput.value) || 0;
    if (!cementQty || !yieldFactor || !Number.isFinite(yieldFactor)) {
        return;
    }
    const suggested = Number((cementQty * yieldFactor).toFixed(4));
    producedInput.value = suggested;
    applyBom();
    updateGood();
}

function applyBom() {
    const blockId = document.getElementById('blockType').value;
    const cementQty = parseFloat(cementInput.value) || 0;
    const produced = parseFloat(document.getElementById('producedQty').value) || 0;
    document.getElementById('materialRows').innerHTML = '';
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
            } else if (produced > 0) {
                qty = line.qty_per_block * produced;
            }
            addRow(line.material_id, qty ? qty.toFixed(4) : '', mat ? mat.unit_cost : '');
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

const cementPopup = document.getElementById('cementPopup');
const cementPopupInput = document.getElementById('cementPopupInput');
const openCementPopup = () => {
    cementPopupInput.value = cementInput.value || '';
    cementPopup.style.display = 'flex';
    setTimeout(() => cementPopupInput.focus(), 50);
};
const closeCementPopup = () => {
    cementPopup.style.display = 'none';
};

document.getElementById('blockType').addEventListener('change', () => {
    producedManualOverride = false;
    cementInput.value = '';
    producedInput.value = '';
    applyBom();
    openCementPopup();
});
cementInput.addEventListener('input', () => {
    producedManualOverride = false;
    suggestProducedFromCement();
});

document.getElementById('cementPopupApply').addEventListener('click', () => {
    cementInput.value = cementPopupInput.value;
    closeCementPopup();
    if (parseFloat(cementInput.value) > 0) {
        suggestProducedFromCement();
    }
});
document.getElementById('cementPopupCancel').addEventListener('click', closeCementPopup);
document.getElementById('cementPopupClose').addEventListener('click', closeCementPopup);
cementPopup.addEventListener('click', (event) => {
    if (event.target === cementPopup) {
        closeCementPopup();
    }
});
document.getElementById('producedQty').addEventListener('focus', () => { producedManualOverride = true; });
document.getElementById('producedQty').addEventListener('input', () => {
    producedManualOverride = true;
    updateGood();
});
document.getElementById('rejectedQty').addEventListener('input', updateGood);
document.getElementById('addRow').addEventListener('click', () => addRow());
document.getElementById('materialRows').addEventListener('input', (event) => {
    if (event.target.matches('input[name="material_quantity[]"]')) {
        producedManualOverride = false;
        suggestProducedFromCement();
    }
});

applyBom();
updateGood();
</script>

<?php require_once "../partials/footer.php"; ?>
