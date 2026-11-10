<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../config/labor_settings.php";
require_permission('production', 'create');
ensure_manufacturing_costing_schema();

$pageTitle = 'New Production Run';
$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$laborByBlock = [];
$overheadByBlock = [];
$laborRatePerBag = labor_rate_per_bag();
$kgPerBag = labor_kg_per_bag();
foreach ($blocks as $b) {
    $laborByBlock[(int) $b['id']] = (float) ($b['labor_cost_per_block'] ?? 0);
    $overheadByBlock[(int) $b['id']] = (float) ($b['overhead_cost_per_block'] ?? 0);
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

// Factory overhead: approved monthly expenses in these categories, spread over the
// month's good blocks. (Wages are excluded: direct labor is costed separately.
// Transport, loading and office costs are selling/admin costs, not factory overhead.)
$overheadCategories = ['Electricity', 'Fuel', 'Machine Repair', 'Maintenance', 'Rent', 'Security', 'Utilities'];
$overheadMonths = [];
try {
    $in = implode(',', array_fill(0, count($overheadCategories), '?'));
    $st = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'%Y-%m') AS ym, SUM(amount) AS total FROM expenses
        WHERE category IN ($in) AND COALESCE(approval_status,'approved')='approved'
        AND expense_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 12 MONTH)
        GROUP BY ym");
    $st->execute($overheadCategories);
    foreach ($st->fetchAll() as $r) {
        $overheadMonths[$r['ym']] = ['expenses' => (float) $r['total'], 'produced' => 0.0];
    }
    $pr = $pdo->query("SELECT DATE_FORMAT(production_date,'%Y-%m') AS ym, SUM(good_quantity) AS good FROM production
        WHERE production_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 12 MONTH)
        GROUP BY ym")->fetchAll();
    foreach ($pr as $r) {
        if (!isset($overheadMonths[$r['ym']])) {
            $overheadMonths[$r['ym']] = ['expenses' => 0.0, 'produced' => 0.0];
        }
        $overheadMonths[$r['ym']]['produced'] = (float) $r['good'];
    }
} catch (PDOException $e) {
    $overheadMonths = [];
}

$selBlock = (int) ($_GET['block_type'] ?? ($blocks[0]['id'] ?? 0));
$plannedQty = (int) ($_GET['planned'] ?? 0);
$matJson = [];
foreach ($materials as $m) {
    $matJson[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'unit_cost' => (float) $m['unit_cost'], 'unit' => $m['unit'] ?? ''];
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>New Production Run</h1>
    <a href="index.php" class="btn btn-secondary"><?= t('cancel') ?></a>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="max-width:900px;">
    <form method="post" action="save.php" id="prodForm">
        <label>Date</label>
        <input type="date" name="production_date" id="prodDate" value="<?= date('Y-m-d') ?>" required>

        <label>Block type</label>
        <select name="block_type_id" id="blockType" required>
            <?php foreach ($blocks as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $selBlock === (int) $x['id'] ? 'selected' : '' ?>>
                <?= e($x['name'] . ' - ' . $x['size']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <div class="panel" style="background:#f1f8f7;border:1px dashed #2f7d4f;margin:12px 0;padding:12px;">
            <label>Cement to use <span id="cementUnit" style="color:#646b8c;"></span> <small style="color:#646b8c;">(optional - calculates the produced quantity)</small></label>
            <input type="number" id="cementQty" min="0" step="0.01" placeholder="Enter cement amount to auto-calculate blocks">
            <p id="cementHint" style="margin:6px 0 0;font-size:.85rem;color:#646b8c;"></p>
        </div>

        <div class="grid-2">
            <div><label>Planned quantity</label><input type="number" name="planned_quantity" id="plannedQty" value="<?= $plannedQty ?>" min="0"></div>
            <div><label>Produced quantity *</label><input type="number" name="produced_quantity" id="producedQty" required min="1"></div>
        </div>
        <label>Rejected quantity</label>
        <input type="number" name="rejected_quantity" id="rejectedQty" value="0" min="0">

        <div class="grid-2">
            <div><label>Direct labor cost (<?= e(currency_symbol()) ?>)</label><input type="number" name="labor_cost" id="laborCost" min="0" step="0.01" value="0" required></div>
            <div><label>Factory overhead (<?= e(currency_symbol()) ?>)</label><input type="number" name="overhead_cost" id="overheadCost" min="0" step="0.01" value="0" required></div>
        </div>
        <p style="color:#646b8c;font-size:.82rem;">Material cost is calculated from current raw-material weighted-average cost. Labor and overhead are allocated across good blocks. Direct labor starts at the BOM labor cost per block × produced quantity; you can override it.</p>

        <p id="laborHint" style="color:#646b8c;font-size:.82rem;margin:4px 0;"></p>
        <p id="overheadHint" style="color:#646b8c;font-size:.82rem;margin:4px 0;"></p>
        <p id="goodPreview" style="color:#2f7d4f;font-weight:600;"></p>

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

const laborRatePerBag = <?= json_encode($laborRatePerBag) ?>;
const kgPerBag = <?= json_encode($kgPerBag) ?>;
function cementBagsInForm() {
    const cm = materials.find(m => /cement/i.test(m.name));
    if (!cm) return null;
    let total = 0;
    document.querySelectorAll('#materialRows > div').forEach(d => {
        const s = d.querySelector('select');
        const q = d.querySelector('input[name="material_quantity[]"]');
        if (s && q && parseInt(s.value, 10) === cm.id) total += parseFloat(q.value) || 0;
    });
    return /kg|kilo/i.test(cm.unit || '') ? total / kgPerBag : total;
}
function suggestLabor() {
    if (laborTouched) {
        return;
    }
    // Paid by cement used: bags x rate per bag (set under Production > Weekly Labor).
    if (laborRatePerBag > 0) {
        const bags = cementBagsInForm();
        if (bags !== null && bags > 0) {
            laborInput.value = (bags * laborRatePerBag).toFixed(2);
            const lh = document.getElementById('laborHint');
            if (lh) lh.textContent = 'Labor from cement: ' + bags.toFixed(2) + ' bags x ' + laborRatePerBag.toFixed(2) + ' per bag.';
            return;
        }
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

// --- Factory overhead from monthly expenses (per block) ---
const overheadPerBlockFixed = <?= json_encode($overheadByBlock) ?>;
const overheadMonths = <?= json_encode($overheadMonths) ?>;
const overheadCats = <?= json_encode($overheadCategories) ?>;
let overheadTouched = false;
const overheadInput = document.getElementById('overheadCost');
overheadInput.addEventListener('input', () => { overheadTouched = true; });

function suggestOverhead() {
    const hint = document.getElementById('overheadHint');
    const month = (document.getElementById('prodDate').value || '').slice(0, 7);
    const m = overheadMonths[month];
    const p = parseInt(document.getElementById('producedQty').value, 10) || 0;
    const r = parseInt(document.getElementById('rejectedQty').value, 10) || 0;
    const good = Math.max(0, p - r);
    const fixedRate = parseFloat(overheadPerBlockFixed[document.getElementById('blockType').value] || 0);
    if (fixedRate > 0) {
        if (good <= 0) { hint.textContent = ''; return; }
        hint.textContent = 'Overhead from BOM: ' + fixedRate.toFixed(4) + ' per block x ' + good + ' good blocks.';
        if (!overheadTouched) overheadInput.value = (fixedRate * good).toFixed(2);
        return;
    }
    if (!m || m.expenses <= 0) {
        hint.textContent = 'No approved factory expenses (' + overheadCats.join(', ') + ') found for this month, so overhead is left for you to enter.';
        return;
    }
    if (good <= 0) { hint.textContent = ''; return; }
    const rate = m.expenses / (m.produced + good);
    hint.textContent = 'Auto overhead: ' + m.expenses.toFixed(2) + ' of approved factory expenses this month / ' +
        (m.produced + good) + ' good blocks (including this run) = ' + rate.toFixed(4) + ' per block.';
    if (!overheadTouched) overheadInput.value = (rate * good).toFixed(2);
}

function updateGood() {
    const p = parseInt(document.getElementById('producedQty').value, 10) || 0;
    const r = parseInt(document.getElementById('rejectedQty').value, 10) || 0;
    document.getElementById('goodPreview').textContent = p ? `Good quantity: ${Math.max(0, p - r)}` : '';
}

document.getElementById('blockType').addEventListener('change', applyBom);
document.getElementById('producedQty').addEventListener('input', () => { updateGood(); applyBom(); });
document.getElementById('rejectedQty').addEventListener('input', () => { updateGood(); suggestOverhead(); });
document.getElementById('producedQty').addEventListener('input', suggestOverhead);
document.getElementById('prodDate').addEventListener('change', suggestOverhead);
document.getElementById('addRow').addEventListener('click', () => addRow());
document.getElementById('materialRows').addEventListener('input', suggestLabor);

// --- Cement calculator: cement amount -> number of blocks (uses the block's BOM) ---
const cementMat = materials.find(m => /cement/i.test(m.name));
function cementPerBlock() {
    if (!cementMat) return 0;
    const line = (bom[document.getElementById('blockType').value] || []).find(l => l.material_id == cementMat.id);
    return line ? parseFloat(line.qty_per_block) : 0;
}
function updateCementHint() {
    const hint = document.getElementById('cementHint');
    document.getElementById('cementUnit').textContent = cementMat && cementMat.unit ? '(' + cementMat.unit + ')' : '';
    if (!cementMat) { hint.textContent = 'No raw material with "cement" in its name was found.'; return; }
    const per = cementPerBlock();
    if (!per) { hint.textContent = 'This block type has no cement in its BOM yet (Production > BOM). Add it to use the calculator.'; return; }
    const perCement = 1 / per;
    hint.textContent = '1 ' + (cementMat.unit || 'unit') + ' of cement makes about ' + perCement.toFixed(1) + ' blocks (from the BOM).';
}
function calcFromCement() {
    const per = cementPerBlock();
    const cement = parseFloat(document.getElementById('cementQty').value) || 0;
    if (!per || cement <= 0) return;
    document.getElementById('producedQty').value = Math.floor(cement / per);
    document.getElementById('producedQty').dispatchEvent(new Event('input'));
}
document.getElementById('cementQty').addEventListener('input', calcFromCement);
document.getElementById('blockType').addEventListener('change', () => { updateCementHint(); calcFromCement(); suggestOverhead(); });
updateCementHint();

applyBom();
updateGood();
</script>

<?php require_once "../partials/footer.php"; ?>
