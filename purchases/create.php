<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'create');
ensure_logistics_cost_columns();

$pageTitle = t('new_purchase_order');
$materials = $pdo->query("SELECT * FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE status=1 ORDER BY name")->fetchAll();

$priceMap = [];
try {
    foreach ($pdo->query("SELECT supplier_id, material_id, unit_price FROM supplier_material_prices")->fetchAll() as $row) {
        $priceMap[$row['supplier_id']][$row['material_id']] = (float) $row['unit_price'];
    }
} catch (PDOException $e) {
    $priceMap = [];
}

$selSupplier = (int) ($_GET['supplier_id'] ?? 0);
$selMaterial = (int) ($_GET['material_id'] ?? 0);
$selQty = (float) ($_GET['qty'] ?? 0);
// Several lines at once, e.g. ?items=3:25,7:10 (material id : quantity) from the reorder suggestions.
$preItems = [];
foreach (explode(',', (string) ($_GET['items'] ?? '')) as $pair) {
    $bits = explode(':', $pair, 2);
    $mid = (int) ($bits[0] ?? 0);
    $qty = (float) ($bits[1] ?? 0);
    if ($mid > 0 && $qty > 0) {
        $preItems[$mid] = $qty;
    }
}

$matJson = [];
foreach ($materials as $m) {
    $matJson[] = ['id' => (int) $m['id'], 'name' => $m['name'] . ' (' . $m['unit'] . ')', 'default_cost' => (float) $m['unit_cost']];
}

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('new_purchase_order') ?></h1>
    <a href="index.php" class="btn btn-secondary"><?= t('cancel') ?></a>
</div>

<div class="panel" style="max-width:920px;">
    <form method="post" action="save.php">
        <label><?= t('supplier') ?></label>
        <select name="supplier_id" id="supplierId">
            <option value="">— <?= t('optional') ?> —</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $selSupplier === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label><?= t('date') ?></label>
        <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" required>

        <h3><?= t('line_items') ?></h3>
        <div id="itemRows"></div>
        <button type="button" class="btn btn-secondary" id="addRow">+ <?= t('add_line_item') ?></button>

        <div class="grid-2" style="margin-top:16px;">
            <div><label for="paidAmount"><?= t('supplier_payment_requested_field') ?></label><input type="number" step="0.01" name="paid_amount" id="paidAmount" value="0" min="0"><small style="color:#64748b;"><?= t('no_payment_before_approval') ?></small></div>
            <div>
                <label><?= t('payment_method') ?></label>
                <select name="payment_method">
                    <option value="cash"><?= t('cash') ?></option>
                    <option value="bank"><?= t('bank') ?></option>
                    <option value="telebirr">Telebirr</option>
                    <option value="chapa">Chapa</option>
                </select>
            </div>
        </div>
        <div class="panel" role="status" aria-live="polite" style="margin:16px 0;padding:16px;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;gap:12px;"><span><?= t('materials_subtotal') ?></span><strong id="materialSubtotal"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px;"><span><?= t('loading_unloading') ?></span><strong id="logisticsTotal"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px;"><span><?= t('order_total') ?></span><strong id="orderTotal"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px;"><span><?= t('paid_before_approval') ?></span><strong><?= e(currency_symbol()) ?>0.00</strong></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px;"><span><?= t('payment_after_approval') ?></span><strong id="calculatedPaid"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px;"><span><?= t('balance_after_approval') ?></span><strong id="balanceDue"><?= e(currency_symbol()) ?>0.00</strong></div>
        </div>
        <div class="grid-2">
            <div><label for="loadingCost"><?= t('loading_cost') ?></label><input type="number" step="0.01" name="loading_cost" id="loadingCost" value="0" min="0"></div>
            <div><label for="unloadingCost"><?= t('unloading_cost') ?></label><input type="number" step="0.01" name="unloading_cost" id="unloadingCost" value="0" min="0"></div>
        </div>
        <small style="color:#64748b;"><?= t('logistics_expense_note') ?></small>
        <label><?= t('notes') ?></label>
        <textarea name="notes" rows="2"></textarea>
        <button type="submit" class="btn btn-accent" style="margin-top:16px;"><?= t('save_purchase') ?></button>
    </form>
</div>

<script>
const priceMap = <?= json_encode($priceMap) ?>;
const materials = <?= json_encode($matJson) ?>;
const preMaterial = <?= (int) $selMaterial ?>;
const preQty = <?= json_encode($selQty) ?>;
const preItems = <?= json_encode($preItems) ?>;
const currencySymbol = <?= json_encode(currency_symbol()) ?>;
const itemRows = document.getElementById('itemRows');
const paidAmountInput = document.getElementById('paidAmount');
const loadingCostInput = document.getElementById('loadingCost');
const unloadingCostInput = document.getElementById('unloadingCost');

function formatAmount(amount) {
    return currencySymbol + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function updateTotals() {
    let subtotal = 0;
    itemRows.querySelectorAll('.filter-form').forEach(row => {
        const quantity = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
        const unitCost = parseFloat(row.querySelector('[name="unit_cost[]"]').value) || 0;
        if (quantity > 0) subtotal += quantity * unitCost;
    });

    const logistics = Math.max(0, parseFloat(loadingCostInput.value) || 0) + Math.max(0, parseFloat(unloadingCostInput.value) || 0);
    const total = subtotal + logistics;
    const supplierPaid = Math.min(Math.max(0, parseFloat(paidAmountInput.value) || 0), subtotal);
    const paid = supplierPaid + logistics;
    document.getElementById('materialSubtotal').textContent = formatAmount(subtotal);
    document.getElementById('logisticsTotal').textContent = formatAmount(logistics);
    document.getElementById('orderTotal').textContent = formatAmount(total);
    document.getElementById('calculatedPaid').textContent = formatAmount(paid);
    document.getElementById('balanceDue').textContent = formatAmount(Math.max(0, total - paid));
}

function materialOptions(selected) {
    return materials.map(m => `<option value="${m.id}" ${m.id == selected ? 'selected' : ''}>${m.name}</option>`).join('');
}

function unitCostFor(mid) {
    const sid = document.getElementById('supplierId').value;
    if (sid && priceMap[sid] && priceMap[sid][mid] !== undefined) return priceMap[sid][mid];
    const m = materials.find(x => x.id == mid);
    return m ? m.default_cost : '';
}

function addRow(mid = '', qty = '', cost = '') {
    const div = document.createElement('div');
    div.className = 'filter-form';
    div.style.marginBottom = '8px';
    div.innerHTML = `
        <select name="material_id[]" class="mat-select" required>${materialOptions(mid)}</select>
        <input name="quantity[]" type="number" step="0.01" placeholder="Qty" value="${qty}" required>
        <input name="unit_cost[]" type="number" step="0.01" class="unit-cost" placeholder="Unit cost" value="${cost || unitCostFor(mid)}" required>
        <button type="button" class="btn-sm remove-row" style="background:#c0392b;border:0;cursor:pointer;">×</button>`;
    itemRows.appendChild(div);
    div.querySelector('.mat-select').addEventListener('change', function () {
        div.querySelector('.unit-cost').value = unitCostFor(this.value);
        updateTotals();
    });
    updateTotals();
}

document.getElementById('supplierId').addEventListener('change', () => {
    itemRows.querySelectorAll('.filter-form').forEach(row => {
        const mid = row.querySelector('.mat-select').value;
        row.querySelector('.unit-cost').value = unitCostFor(mid);
    });
    updateTotals();
});
itemRows.addEventListener('input', updateTotals);
itemRows.addEventListener('click', event => {
    const removeButton = event.target.closest('.remove-row');
    if (removeButton) {
        removeButton.closest('.filter-form').remove();
        updateTotals();
    }
});
paidAmountInput.addEventListener('input', updateTotals);
loadingCostInput.addEventListener('input', updateTotals);
unloadingCostInput.addEventListener('input', updateTotals);
document.getElementById('addRow').addEventListener('click', () => addRow());

if (Object.keys(preItems).length) {
    Object.keys(preItems).forEach(id => addRow(parseInt(id, 10), preItems[id], unitCostFor(id)));
} else if (preMaterial) {
    addRow(preMaterial, preQty || '', unitCostFor(preMaterial));
} else {
    addRow(materials[0]?.id || '', '', unitCostFor(materials[0]?.id));
}
updateTotals();
</script>

<?php require_once "../partials/footer.php"; ?>
