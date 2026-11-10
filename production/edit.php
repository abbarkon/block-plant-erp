<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'edit');
ensure_manufacturing_costing_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, bt.name, bt.size FROM production p JOIN block_types bt ON p.block_type_id=bt.id WHERE p.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) {
    require_once '../partials/header.php';
    echo render_not_found('Production run not found', 'The production run you requested does not exist.', 'index.php', 'Return to production orders');
    require_once '../partials/footer.php';
    exit;
}

$lineStmt = $pdo->prepare("SELECT pm.material_id, pm.quantity, pm.unit_cost FROM production_materials pm WHERE pm.production_id=?");
$lineStmt->execute([$id]);
$lines = $lineStmt->fetchAll();

$materials = $pdo->query("SELECT id, name, unit, unit_cost, current_stock FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();
$matJson = [];
foreach ($materials as $m) {
    $matJson[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'unit' => $m['unit'] ?? '', 'unit_cost' => (float) $m['unit_cost'], 'stock' => (float) $m['current_stock']];
}
$bomByBlock = [];
try {
    foreach ($pdo->query("SELECT material_id, quantity_per_block FROM block_bom WHERE block_type_id=" . (int) $p['block_type_id'])->fetchAll() as $row) {
        $bomByBlock[] = ['material_id' => (int) $row['material_id'], 'qty_per_block' => (float) $row['quantity_per_block']];
    }
} catch (PDOException $e) {
    $bomByBlock = [];
}
$existing = [];
foreach ($lines as $l) {
    $existing[] = ['material_id' => (int) $l['material_id'], 'quantity' => (float) $l['quantity'], 'unit_cost' => (float) $l['unit_cost']];
}

$pageTitle = 'Edit ' . $p['production_no'];
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Edit <?= e($p['production_no']) ?></h1>
    <a href="view.php?id=<?= (int) $p['id'] ?>" class="btn btn-secondary"><?= t('cancel') ?></a>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="max-width:900px;">
    <p style="background:#fff8e1;border:1px solid #ffe082;padding:10px;border-radius:8px;font-size:.88rem;">
        Saving reverses this run's raw-material and finished-stock effects and applies the new figures.
        Cost changes are posted as adjustment journal entries. The block type cannot be changed. Change the produced quantity and the material lines update from the BOM; each line shows your stock before and after saving.
    </p>
    <form method="post" action="update.php">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

        <label>Date</label>
        <input type="date" name="production_date" value="<?= e($p['production_date']) ?>" max="<?= date('Y-m-d') ?>" required>

        <label>Block type</label>
        <input type="text" value="<?= e($p['name'] . ' - ' . $p['size']) ?>" readonly style="background:#f1f5f9;">

        <div class="grid-2">
            <div><label>Planned quantity</label><input type="number" name="planned_quantity" value="<?= (int) $p['planned_quantity'] ?>" min="0"></div>
            <div><label>Produced quantity *</label><input type="number" name="produced_quantity" id="producedQty" value="<?= (int) $p['produced_quantity'] ?>" required min="1"></div>
        </div>
        <label>Rejected quantity</label>
        <input type="number" name="rejected_quantity" id="rejectedQty" value="<?= (int) $p['rejected_quantity'] ?>" min="0">

        <div class="grid-2">
            <div><label>Direct labor cost (<?= e(currency_symbol()) ?>)</label><input type="number" name="labor_cost" min="0" step="0.01" value="<?= e($p['labor_cost']) ?>" required></div>
            <div><label>Factory overhead (<?= e(currency_symbol()) ?>)</label><input type="number" name="overhead_cost" min="0" step="0.01" value="<?= e($p['overhead_cost']) ?>" required></div>
        </div>

        <p id="goodPreview" style="color:#2f7d4f;font-weight:600;"></p>

        <h3>Materials</h3>
        <div id="materialRows"></div>
        <button type="button" class="btn btn-secondary" id="addRow" style="margin-top:8px;">+ Add material line</button>
        <?php if ($bomByBlock): ?>
        <button type="button" class="btn btn-secondary" id="fromBom" style="margin-top:8px;">Recalculate from BOM</button>
        <?php endif; ?>

        <label>Notes</label>
        <textarea name="notes" rows="2"><?= e($p['notes']) ?></textarea>

        <button type="submit" class="btn btn-accent" style="margin-top:16px;" onclick="return confirm('Save changes to this production run? Stock and accounting will be adjusted.');">Save changes</button>
    </form>
</div>

<script>
const materials = <?= json_encode($matJson) ?>;
const bom = <?= json_encode($bomByBlock) ?>;
const existing = <?= json_encode($existing) ?>;
// Quantity this run already took from stock (it is put back when you save).
const oldQty = {};
existing.forEach(l => { oldQty[l.material_id] = (oldQty[l.material_id] || 0) + l.quantity; });

function materialOptions(selected) {
    return materials.map(m => `<option value="${m.id}" ${m.id == selected ? 'selected' : ''}>${m.name}</option>`).join('');
}
function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 }); }

function refreshRow(div) {
    const mid = parseInt(div.querySelector('select').value, 10);
    const qty = parseFloat(div.querySelector('.mq').value) || 0;
    const mat = materials.find(m => m.id === mid);
    const info = div.querySelector('.stockInfo');
    if (!mat) { info.textContent = ''; return; }
    div.querySelector('.mc').value = mat.unit_cost;
    const available = mat.stock + (oldQty[mid] || 0);   // stock if this run were not counted
    const after = available - qty;
    info.style.color = after < 0 ? '#c0392b' : '#2f7d4f';
    info.textContent = 'Stock now ' + fmt(mat.stock) + (mat.unit ? ' ' + mat.unit : '') +
        ' → after saving ' + fmt(after) + (after < 0 ? ' (not enough stock!)' : '');
}
function addRow(mid = '', qty = '', cost = '') {
    const div = document.createElement('div');
    div.style.marginBottom = '10px';
    div.innerHTML = `
        <div class="filter-form">
            <select name="material_id[]" required>${materialOptions(mid)}</select>
            <input class="mq" name="material_quantity[]" type="number" step="0.01" placeholder="Qty" value="${qty}" required>
            <input class="mc" type="number" step="0.01" placeholder="Unit cost" value="${cost}" readonly style="background:#f1f5f9;">
            <button type="button" class="btn-sm" style="background:#c0392b;border:0;cursor:pointer;" onclick="this.closest('div[style]').remove()">×</button>
        </div>
        <small class="stockInfo" style="display:block;margin:2px 0 0 4px;"></small>`;
    div.querySelector('select').addEventListener('change', () => refreshRow(div));
    div.querySelector('.mq').addEventListener('input', e => { e.target.dataset.manual = '1'; refreshRow(div); });
    document.getElementById('materialRows').appendChild(div);
    refreshRow(div);
    return div;
}
function rowFor(mid) {
    return Array.from(document.querySelectorAll('#materialRows > div')).find(d => parseInt(d.querySelector('select').value, 10) === mid);
}
// Recalculate material lines from the BOM for the current produced quantity.
// Lines you typed by hand are left alone unless force = true.
function applyBom(force) {
    const produced = parseFloat(document.getElementById('producedQty').value) || 0;
    if (produced <= 0) return;
    bom.forEach(l => {
        let row = rowFor(l.material_id);
        const value = (l.qty_per_block * produced).toFixed(4);
        if (!row) {
            const mat = materials.find(m => m.id === l.material_id);
            row = addRow(l.material_id, value, mat ? mat.unit_cost : '');
            return;
        }
        const q = row.querySelector('.mq');
        if (force || q.dataset.manual !== '1') { q.value = value; q.dataset.manual = ''; refreshRow(row); }
    });
}
function updateGood() {
    const p = parseInt(document.getElementById('producedQty').value, 10) || 0;
    const r = parseInt(document.getElementById('rejectedQty').value, 10) || 0;
    document.getElementById('goodPreview').textContent = p ? `Good quantity: ${Math.max(0, p - r)}` : '';
}
existing.forEach(l => addRow(l.material_id, l.quantity, l.unit_cost));
if (!existing.length && materials.length) addRow(materials[0].id, '', materials[0].unit_cost);

document.getElementById('addRow').addEventListener('click', () => addRow());
const fromBom = document.getElementById('fromBom');
if (fromBom) fromBom.addEventListener('click', () => applyBom(true));
document.getElementById('producedQty').addEventListener('input', () => { updateGood(); applyBom(false); });
document.getElementById('rejectedQty').addEventListener('input', updateGood);
updateGood();
</script>

<?php require_once "../partials/footer.php"; ?>
