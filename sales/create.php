<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'create');
ensure_logistics_cost_columns();
ensure_gate_schema();
ensure_production_curing_schema();

$pageTitle = t('new_sale');
$blocks = $pdo->query("SELECT b.*, COALESCE(f.quantity, 0) AS stock FROM block_types b LEFT JOIN finished_stock f ON f.block_type_id = b.id WHERE b.status = 1 ORDER BY b.name, b.size")->fetchAll();
foreach ($blocks as &$block) {
    $curing = finished_curing_quantity((int) $block['id']);
    $block['total_stock'] = (int) $block['stock'];
    $block['curing_stock'] = $curing;
    $block['stock'] = max(0, (int) $block['stock'] - $curing);
}
unset($block);
$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE status = 1 ORDER BY name")->fetchAll();
$company = company_settings();
$flash = get_flash();

// Pre-fill from a quotation when converting ("Convert to Sale").
$quotationId = (int) ($_GET['quotation'] ?? 0);
$quotation = null;
$quotationItems = [];
$quotationFirst = null;
if ($quotationId > 0) {
    $qStmt = $pdo->prepare("SELECT q.*, c.name AS customer_name FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id WHERE q.id = ?");
    $qStmt->execute([$quotationId]);
    $quotation = $qStmt->fetch() ?: null;
    if ($quotation) {
        $qiStmt = $pdo->prepare("SELECT qi.*, bt.name AS block_name, bt.size FROM quotation_items qi JOIN block_types bt ON bt.id = qi.block_type_id WHERE qi.quotation_id = ? ORDER BY qi.id");
        $qiStmt->execute([$quotationId]);
        $quotationItems = $qiStmt->fetchAll();
        $quotationFirst = $quotationItems[0] ?? null;
    }
}
$prefillCustomerId = $quotation ? (int) ($quotation['customer_id'] ?? 0) : 0;
$customerParam = (int) ($_GET['customer'] ?? 0);
if ($customerParam > 0) {
    $prefillCustomerId = $customerParam;
}
$prefillDiscount = $quotation ? (float) $quotation['discount'] : 0;
$prefillItems = [];
foreach ($quotationItems as $qi) {
    $prefillItems[] = [
        'block_type_id' => (int) $qi['block_type_id'],
        'quantity' => (int) $qi['quantity'],
        'unit_price' => (float) $qi['unit_price'],
    ];
}
// Pre-fill from an online order ("Create invoice" on the Online Orders screen).
$onlineOrderId = (int) ($_GET['order'] ?? 0);
$onlineOrder = null;
if ($onlineOrderId > 0) {
    require_once __DIR__ . '/../shop/bootstrap.php';
    ensure_shop_schema();
    $oStmt = $pdo->prepare("SELECT * FROM online_orders WHERE id = ?");
    $oStmt->execute([$onlineOrderId]);
    $onlineOrder = $oStmt->fetch() ?: null;
    if ($onlineOrder && !in_array($onlineOrder['status'], ['completed', 'cancelled'], true)) {
        $prefillCustomerId = (int) ($onlineOrder['customer_id'] ?? 0);
        $oiStmt = $pdo->prepare("SELECT oi.*, bt.name AS block_name, bt.size
            FROM online_order_items oi
            JOIN block_types bt ON bt.id = oi.block_type_id
            WHERE oi.order_id = ?
            ORDER BY oi.id");
        $oiStmt->execute([$onlineOrderId]);
        $onlineItems = $oiStmt->fetchAll();
        if ($onlineItems) {
            $prefillItems = [];
            foreach ($onlineItems as $oi) {
                $prefillItems[] = [
                    'block_type_id' => (int) $oi['block_type_id'],
                    'quantity' => (int) $oi['quantity'],
                    'unit_price' => (float) $oi['unit_price'],
                ];
            }
        }
    } else {
        $onlineOrder = null;
        $onlineOrderId = 0;
    }
}
if (!$prefillItems) {
    $prefillItems[] = ['block_type_id' => 0, 'quantity' => 0, 'unit_price' => 0];
}
$renderBlockOptions = function (int $selectedId) use ($blocks): string {
    $html = '<option value="">' . e(t('select_block_type')) . '</option>';
    foreach ($blocks as $block) {
        $selected = $selectedId === (int) $block['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $block['id'] . '" data-stock="' . (int) $block['stock'] . '" data-curing="' . (int) $block['curing_stock'] . '"' . $selected . '>'
            . e($block['name'] . ' ' . $block['size']) . '</option>';
    }
    return $html;
};

require_once "../partials/header.php";
?>

<style>
    .sale-hero {
        background: linear-gradient(120deg, var(--bp-primary) 0%, var(--bp-primary-light) 58%, var(--bp-accent) 100%);
        border-radius: 14px;
        color: #fff;
        padding: 26px 30px;
        margin-bottom: 22px;
        box-shadow: 0 10px 24px rgba(30, 41, 59, .16);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }
    .sale-hero h1 { margin: 0 0 6px; font-size: 1.85rem; }
    .sale-hero p { margin: 0; opacity: .84; }
    .sale-layout { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(300px, .75fr); gap: 20px; align-items: start; }
    .sale-panel { background: var(--bp-surface); border: 1px solid var(--bp-border); border-radius: var(--bp-radius); box-shadow: var(--bp-shadow); padding: 24px; }
    .sale-panel h2 { margin: 0 0 20px; font-size: 1.1rem; }
    .sale-section { padding-bottom: 22px; margin-bottom: 22px; border-bottom: 1px solid var(--bp-border); }
    .sale-section:last-of-type { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
    .sale-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .sale-field label { display: block; margin-bottom: 7px; font-weight: 600; color: #334155; font-size: .88rem; }
    .sale-field input, .sale-field select { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1e293b; font: inherit; }
    .sale-field input:focus, .sale-field select:focus { outline: 3px solid rgba(0, 137, 123, .14); border-color: var(--bp-accent); }
    .sale-help { display: block; color: var(--bp-muted); font-size: .78rem; margin-top: 5px; }
    .stock-indicator { margin-top: 12px; padding: 11px 13px; border-radius: 8px; background: #f1f5f9; color: #475569; font-size: .88rem; }
    .line-items-table { width: 100%; border-collapse: collapse; }
    .line-items-table th { text-align: left; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: 6px 8px; border-bottom: 1px solid var(--bp-border); }
    .line-items-table td { padding: 6px 8px; vertical-align: top; border-bottom: 1px solid #f1f5f9; }
    .line-items-table select, .line-items-table input { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1e293b; font: inherit; }
    .line-items-table .line-subtotal { font-weight: 600; color: #0f172a; padding-top: 16px; }
    .line-stock { display: block; font-size: .74rem; margin-top: 4px; color: #64748b; }
    .line-stock.warn { color: #b91c1c; font-weight: 600; }
    .btn-remove-line { border: 1px solid #e2e8f0; background: #fff; color: #b91c1c; border-radius: 8px; width: 30px; height: 30px; font-size: 1.1rem; line-height: 1; cursor: pointer; }
    .btn-remove-line:hover { background: #fee2e2; }
    .summary-panel { position: sticky; top: 18px; }
    .summary-title { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .summary-line { display: flex; justify-content: space-between; gap: 18px; padding: 12px 0; color: #475569; border-bottom: 1px solid var(--bp-border); }
    .summary-line strong { color: #0f172a; text-align: right; }
    .summary-total { display: flex; justify-content: space-between; align-items: baseline; padding: 20px 0 8px; font-size: 1.35rem; font-weight: 700; color: var(--bp-primary); }
    .summary-balance { display: flex; justify-content: space-between; padding: 10px 0 18px; font-weight: 600; }
    .sale-actions { display: flex; gap: 10px; margin-top: 22px; }
    .sale-actions .btn { flex: 1; text-align: center; }
    @media (max-width: 850px) { .sale-layout { grid-template-columns: 1fr; } .summary-panel { position: static; } }
    @media (max-width: 560px) { .sale-hero { padding: 22px; align-items: flex-start; flex-direction: column; } .sale-grid { grid-template-columns: 1fr; } .sale-panel { padding: 18px; } }
</style>

<div class="sale-hero">
    <div>
        <h1><?= t('new_sale') ?></h1>
        <p><?= t('create_invoice_reserve_stock_payment') ?></p>
    </div>
    <div style="text-align:right;font-size:.85rem;opacity:.9;">
        <strong><?= e($company['company_name'] ?? 'Block Plant') ?></strong><br>
        <?= e(date('d M Y')) ?>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-danger' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($quotation): ?>
<div class="flash flash-info" style="display:block;">
    Converting quotation <strong><?= e($quotation['quotation_no']) ?></strong> for <?= e($quotation['customer_name'] ?? 'Walk-in customer') ?>.
    <?php if (!$quotationFirst): ?>
    This quotation has no line items; please add the products for this invoice.
    <?php else: ?>
    <?= count($quotationItems) ?> line item<?= count($quotationItems) === 1 ? '' : 's' ?> pre-filled on the invoice — adjust quantities or prices as needed.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($onlineOrder): ?>
<div class="flash flash-info" style="display:block;">
    <strong><?= e(shop_t('Online order')) ?> <?= e($onlineOrder['order_no']) ?></strong>
    <?= e(shop_t('for')) ?> <?= e($onlineOrder['customer_name']) ?> · <?= e($onlineOrder['phone']) ?>.
    <?= e(shop_t('The items and prices are pre-filled. Add the vehicle plate and payment details to issue the invoice.')) ?>
</div>
<?php endif; ?>

<form method="post" action="save.php" id="saleForm">
    <input type="hidden" name="quotation_id" value="<?= $quotation ? (int) $quotation['id'] : 0 ?>">
    <input type="hidden" name="online_order_id" value="<?= $onlineOrder ? (int) $onlineOrder['id'] : 0 ?>">
    <div class="sale-layout">
        <div class="sale-panel">
            <div class="sale-section">
                <h2><?= t('invoice_details') ?></h2>
                <div class="sale-grid">
                    <div class="sale-field">
                        <label for="customer_id"><?= t('customer') ?></label>
                        <select name="customer_id" id="customer_id">
                            <option value=""><?= t('walk_in_customer') ?></option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $prefillCustomerId === (int) $customer['id'] ? 'selected' : '' ?>><?= e($customer['name']) ?><?= $customer['phone'] ? ' - ' . e($customer['phone']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="sale-help"><?= t('leave_blank_walkin') ?></span>
                    </div>
                    <div class="sale-field">
                        <label for="sale_date"><?= t('sale_date') ?></label>
                        <input type="date" name="sale_date" id="sale_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                </div>
                <div class="sale-grid" style="margin-top:16px;">
                    <div class="sale-field"><label for="vehicle_plate"><?= t('vehicle_plate_number') ?></label><input type="text" name="vehicle_plate" id="vehicle_plate" maxlength="50" placeholder="e.g. 3-A12345" required></div>
                    <div class="sale-field"><label for="driver_name"><?= t('driver_name') ?></label><input type="text" name="driver_name" id="driver_name" maxlength="150"></div>
                    <div class="sale-field"><label for="driver_phone"><?= t('driver_phone') ?></label><input type="text" name="driver_phone" id="driver_phone" maxlength="50"></div>
                    <div class="sale-field"><label for="payment_reference"><?= t('payment_reference') ?></label><input type="text" name="payment_reference" id="payment_reference" maxlength="100" placeholder="<?= e(t('transaction_reference_placeholder')) ?>"></div>
                </div>
            </div>

            <div class="sale-section">
                <h2><?= t('product_quantity') ?></h2>
                <div class="table-container" style="box-shadow:none;">
                    <table class="line-items-table">
                        <thead>
                            <tr>
                                <th style="min-width:200px;"><?= t('finished_block') ?></th>
                                <th style="width:110px;"><?= t('quantity') ?></th>
                                <th style="width:140px;"><?= t('unit_price') ?> (<?= e(currency_symbol()) ?>)</th>
                                <th style="width:120px;text-align:right;"><?= t('subtotal') ?></th>
                                <th style="width:44px;"></th>
                            </tr>
                        </thead>
                        <tbody id="lineItemsBody">
                            <?php foreach ($prefillItems as $item): ?>
                            <tr class="line-row">
                                <td>
                                    <select name="item_block_type_id[]" class="line-block" required><?= $renderBlockOptions((int) $item['block_type_id']) ?></select>
                                    <span class="line-stock"></span>
                                </td>
                                <td><input type="number" name="item_quantity[]" class="line-qty" min="1" step="1" placeholder="0" value="<?= (int) $item['quantity'] > 0 ? (int) $item['quantity'] : '' ?>" required></td>
                                <td><input type="number" name="item_unit_price[]" class="line-price" min="0" step="0.01" placeholder="0.00" value="<?= (float) $item['unit_price'] > 0 ? e($item['unit_price']) : '' ?>" required></td>
                                <td class="line-subtotal" style="text-align:right;"><?= e(currency_symbol()) ?>0.00</td>
                                <td><button type="button" class="btn-remove-line" title="<?= e(t('remove')) ?>">&times;</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-secondary" id="addLineBtn" style="margin-top:12px;">+ <?= t('add_line_item') ?></button>
                <div class="stock-indicator" id="stockIndicator"><?= t('select_product_stock') ?></div>
            </div>

            <template id="lineRowTemplate">
                <tr class="line-row">
                    <td>
                        <select name="item_block_type_id[]" class="line-block" required><?= $renderBlockOptions(0) ?></select>
                        <span class="line-stock"></span>
                    </td>
                    <td><input type="number" name="item_quantity[]" class="line-qty" min="1" step="1" placeholder="0" required></td>
                    <td><input type="number" name="item_unit_price[]" class="line-price" min="0" step="0.01" placeholder="0.00" required></td>
                    <td class="line-subtotal" style="text-align:right;"><?= e(currency_symbol()) ?>0.00</td>
                    <td><button type="button" class="btn-remove-line" title="<?= e(t('remove')) ?>">&times;</button></td>
                </tr>
            </template>

            <div class="sale-section">
                <h2><?= t('payments') ?></h2>
                <div class="sale-grid">
                    <div class="sale-field">
                        <label for="paid_amount"><?= t('amount_paid') ?> (<?= e(currency_symbol()) ?>)</label>
                        <input type="number" name="paid_amount" id="paid_amount" min="0" step="0.01" value="0">
                        <span class="sale-help"><?= t('partial_payment_credit') ?></span>
                    </div>
                    <div class="sale-field">
                        <label for="payment_method"><?= t('payment_method') ?></label>
                        <select name="payment_method" id="payment_method">
                            <option value="cash"><?= t('cash') ?></option>
                            <option value="bank"><?= t('bank_transfer') ?></option>
                            <option value="telebirr">Telebirr</option>
                            <option value="chapa">Chapa</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="sale-section">
                <h2><?= t('loading_unloading_costs') ?></h2>
                <div class="sale-grid">
                    <div class="sale-field">
                        <label for="loading_cost"><?= t('loading_cost') ?> (<?= e(currency_symbol()) ?>)</label>
                        <input type="number" name="loading_cost" id="loading_cost" min="0" step="0.01" value="0">
                    </div>
                    <div class="sale-field">
                        <label for="unloading_cost"><?= t('unloading_cost') ?> (<?= e(currency_symbol()) ?>)</label>
                        <input type="number" name="unloading_cost" id="unloading_cost" min="0" step="0.01" value="0">
                    </div>
                    <div class="sale-field"><label for="discount"><?= t('discount') ?> (<?= e(currency_symbol()) ?>)</label><input type="number" name="discount" id="discount" min="0" step="0.01" value="<?= e($prefillDiscount) ?>"></div>
                </div>
                <span class="sale-help"><?= t('sale_cost_expense_note') ?></span>
            </div>

            <div class="sale-actions">
                <a href="index.php" class="btn btn-secondary"><?= t('cancel') ?></a>
                <button type="submit" class="btn btn-accent"><?= t('save_issue_invoice') ?></button>
            </div>
        </div>

        <aside class="sale-panel summary-panel">
            <div class="summary-title">
                <h2><?= t('sale_summary') ?></h2>
                <span class="badge badge-info"><?= t('draft') ?></span>
            </div>
            <div class="summary-line"><span><?= t('product_lines') ?></span><strong id="summaryLines">0</strong></div>
            <div class="summary-line"><span><?= t('total_quantity') ?></span><strong id="summaryQuantity">0</strong></div>
            <div class="summary-line"><span><?= t('subtotal') ?></span><strong id="summaryPrice"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div class="summary-line"><span><?= t('loading_unloading') ?></span><strong id="summaryLogistics"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div class="summary-line"><span><?= t('discount') ?></span><strong id="summaryDiscount"><?= e(currency_symbol()) ?>0.00</strong></div>
            <div class="summary-total"><span><?= t('total') ?></span><span id="summaryTotal"><?= e(currency_symbol()) ?>0.00</span></div>
            <div class="summary-balance"><span><?= t('balance_due') ?></span><span id="summaryBalance"><?= e(currency_symbol()) ?>0.00</span></div>
            <p class="sale-help"><?= t('stock_deducted_after_save') ?></p>
        </aside>
    </div>
</form>

<script>
(function () {
    const currency = <?= json_encode(currency_symbol()) ?>;
    const tSelectStock = <?= json_encode(t('select_product_stock')) ?>;
    const tExceeds = <?= json_encode(t('quantity_exceeds_stock')) ?>;
    const tAvailOne = <?= json_encode(t('available_blocks_one')) ?>;
    const tAvailMany = <?= json_encode(t('available_blocks_many')) ?>;

    const body = document.getElementById('lineItemsBody');
    const template = document.getElementById('lineRowTemplate');
    const addBtn = document.getElementById('addLineBtn');
    const paid = document.getElementById('paid_amount');
    const loading = document.getElementById('loading_cost');
    const unloading = document.getElementById('unloading_cost');
    const discount = document.getElementById('discount');
    const stock = document.getElementById('stockIndicator');
    const linesSummary = document.getElementById('summaryLines');
    const quantitySummary = document.getElementById('summaryQuantity');
    const priceSummary = document.getElementById('summaryPrice');
    const totalSummary = document.getElementById('summaryTotal');
    const balanceSummary = document.getElementById('summaryBalance');
    const logisticsSummary = document.getElementById('summaryLogistics');
    const discountSummary = document.getElementById('summaryDiscount');

    function money(value) { return currency + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function num(el) { return Math.max(0, Number(el.value || 0)); }
    function rows() { return Array.prototype.slice.call(body.querySelectorAll('.line-row')); }

    function wireRow(row) {
        row.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener('input', refresh);
            field.addEventListener('change', refresh);
        });
        var remove = row.querySelector('.btn-remove-line');
        if (remove) {
            remove.addEventListener('click', function () {
                if (rows().length <= 1) {
                    row.querySelector('.line-block').value = '';
                    row.querySelector('.line-qty').value = '';
                    row.querySelector('.line-price').value = '';
                } else {
                    row.remove();
                }
                refresh();
            });
        }
    }

    function addRow() {
        var node = template.content.firstElementChild.cloneNode(true);
        body.appendChild(node);
        wireRow(node);
        return node;
    }

    function refresh() {
        var subtotal = 0, totalQty = 0, lineCount = 0, issues = 0, selectedLines = 0;

        rows().forEach(function (row) {
            var block = row.querySelector('.line-block');
            var qtyEl = row.querySelector('.line-qty');
            var priceEl = row.querySelector('.line-price');
            var subEl = row.querySelector('.line-subtotal');
            var stockEl = row.querySelector('.line-stock');
            var qty = num(qtyEl), price = num(priceEl);

            subEl.textContent = money(qty * price);

            var opt = block.options[block.selectedIndex];
            stockEl.className = 'line-stock';
            stockEl.textContent = '';
            if (!block.value) { return; }

            selectedLines++;
            if (opt && opt.dataset.stock !== undefined) {
                var available = Number(opt.dataset.stock);
                var curing = Number(opt.dataset.curing || 0);
                var label = available === 1 ? tAvailOne : tAvailMany;
                stockEl.textContent = available.toLocaleString() + ' ' + label +
                    (curing > 0 ? ' · ' + curing.toLocaleString() + ' curing' : '') +
                    (qty > available ? ' - ' + tExceeds : '');
                if (qty > available) { stockEl.className = 'line-stock warn'; issues++; }
            }
            if (qty > 0) { lineCount++; subtotal += qty * price; totalQty += qty; }
        });

        var amountPaid = num(paid);
        var logistics = num(loading) + num(unloading);
        var discountAmount = num(discount);
        var total = Math.max(0, subtotal + logistics - discountAmount);

        linesSummary.textContent = lineCount;
        quantitySummary.textContent = totalQty.toLocaleString();
        priceSummary.textContent = money(subtotal);
        logisticsSummary.textContent = money(logistics);
        discountSummary.textContent = money(discountAmount);
        totalSummary.textContent = money(total);
        balanceSummary.textContent = money(Math.max(0, total - amountPaid));

        if (selectedLines === 0) {
            stock.textContent = tSelectStock;
            stock.style.background = '#f1f5f9';
            stock.style.color = '#475569';
        } else if (issues > 0) {
            stock.textContent = issues + ' line' + (issues === 1 ? '' : 's') + ' exceed available stock.';
            stock.style.background = '#fee2e2';
            stock.style.color = '#991b1b';
        } else {
            stock.textContent = 'All selected lines are within available stock.';
            stock.style.background = '#dcfce7';
            stock.style.color = '#166534';
        }
    }

    addBtn.addEventListener('click', function () { addRow(); refresh(); });
    [paid, loading, unloading, discount].forEach(function (field) {
        field.addEventListener('input', refresh);
        field.addEventListener('change', refresh);
    });
    rows().forEach(wireRow);
    refresh();
}());
</script>

<?php require_once "../partials/footer.php"; ?>
