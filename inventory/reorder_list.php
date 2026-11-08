<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');

$canPurchase = has_permission('purchases', 'create');
$pageTitle = "Reorder Suggestions";
$cs = currency_symbol();

// Materials at or below their reorder point
$low = $pdo->query("SELECT rm.id, rm.name, rm.unit, rm.unit_cost, rm.current_stock,
        rl.min_level, rl.max_level, rl.reorder_point, rl.reorder_quantity
    FROM raw_materials rm JOIN reorder_levels rl ON rl.material_id = rm.id
    WHERE rm.status = 1 AND rm.current_stock <= rl.reorder_point
    ORDER BY CASE WHEN rl.reorder_point > 0 THEN rm.current_stock / rl.reorder_point ELSE 0 END ASC")->fetchAll();

// Cheapest listed price per material, and the supplier that gave it
$best = [];
try {
    foreach ($pdo->query("SELECT p.material_id, p.supplier_id, p.unit_price, s.name AS supplier_name
        FROM supplier_material_prices p JOIN suppliers s ON s.id = p.supplier_id
        WHERE s.status = 1 ORDER BY p.unit_price ASC")->fetchAll() as $r) {
        if (!isset($best[(int) $r['material_id']])) {
            $best[(int) $r['material_id']] = $r;
        }
    }
} catch (PDOException $e) {
}
// Otherwise the supplier we last bought it from
$last = [];
try {
    foreach ($pdo->query("SELECT pi.material_id, p.supplier_id, s.name AS supplier_name, pi.unit_cost
        FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id JOIN suppliers s ON s.id = p.supplier_id
        WHERE (p.approval_status IS NULL OR p.approval_status <> 'rejected')
        ORDER BY p.purchase_date DESC, p.id DESC")->fetchAll() as $r) {
        if (!isset($last[(int) $r['material_id']])) {
            $last[(int) $r['material_id']] = $r;
        }
    }
} catch (PDOException $e) {
}

$groups = [];
$grand = 0.0;
foreach ($low as $m) {
    $stock = (float) $m['current_stock'];
    $qty = (float) $m['reorder_quantity'];
    if ((float) $m['max_level'] > 0) {
        $qty = max($qty, (float) $m['max_level'] - $stock);   // top up to the maximum level
    }
    if ($qty <= 0) {
        $qty = max(0, (float) $m['reorder_point'] * 2 - $stock);
    }
    $mid = (int) $m['id'];
    if (isset($best[$mid])) {
        $sid = (int) $best[$mid]['supplier_id']; $sname = $best[$mid]['supplier_name']; $price = (float) $best[$mid]['unit_price']; $src = 'lowest listed price';
    } elseif (isset($last[$mid])) {
        $sid = (int) $last[$mid]['supplier_id']; $sname = $last[$mid]['supplier_name']; $price = (float) $last[$mid]['unit_cost']; $src = 'last supplier';
    } else {
        $sid = 0; $sname = 'No supplier yet'; $price = (float) $m['unit_cost']; $src = 'material cost';
    }
    $groups[$sid]['name'] = $sname;
    $groups[$sid]['rows'][] = ['m' => $m, 'qty' => round($qty, 2), 'price' => $price, 'src' => $src];
    $grand += $qty * $price;
}
ksort($groups);
$flash = get_flash();
require_once "../partials/header.php";
?>
<div class="page-header">
    <div>
        <h1>Reorder Suggestions</h1>
        <p style="color:#64748b;margin:6px 0 0;">Materials at or below their reorder point, grouped by supplier. Tick what you want and create the purchase order in one click.</p>
    </div>
    <div class="header-actions no-print">
        <a href="stock_count.php" class="btn btn-secondary">Stock count</a>
        <a href="reorder.php" class="btn btn-secondary">Reorder levels</a>
        <button type="button" class="btn btn-accent" onclick="window.print()">Print list</button>
    </div>
</div>
<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<?php if (!$groups): ?>
<div class="panel">✅ Nothing to reorder: every material with a reorder level is above its reorder point.</div>
<?php else: ?>
<p style="margin:0 0 14px;"><strong><?= count($low) ?></strong> material(s) to reorder &middot; estimated total <strong><?= $cs . money($grand) ?></strong></p>
<?php foreach ($groups as $sid => $g): ?>
<div class="panel reorder-group" data-supplier="<?= (int) $sid ?>" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
        <h3 style="margin:0;"><?= $sid ? '🏭 ' : '❓ ' ?><?= e($g['name']) ?></h3>
        <?php if ($canPurchase): ?>
        <a href="#" class="btn btn-accent no-print make-po">Create purchase order</a>
        <?php endif; ?>
    </div>
    <div class="table-container"><table class="data-table">
        <thead><tr><th class="no-print"></th><th>Material</th><th style="text-align:right">In stock</th><th style="text-align:right">Reorder point</th><th style="text-align:right">Order qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Line total</th></tr></thead>
        <tbody>
        <?php foreach ($g['rows'] as $r): $m = $r['m']; ?>
            <tr data-mid="<?= (int) $m['id'] ?>" data-price="<?= e($r['price']) ?>">
                <td class="no-print"><input type="checkbox" class="pick" checked></td>
                <td><strong><?= e($m['name']) ?></strong> <small style="color:#62767e;">(<?= e($m['unit']) ?>)</small></td>
                <td style="text-align:right;color:#c0392b;font-weight:700"><?= number_format((float) $m['current_stock'], 2) ?></td>
                <td style="text-align:right"><?= number_format((float) $m['reorder_point'], 2) ?></td>
                <td style="text-align:right"><input type="number" class="qty" min="0" step="0.01" value="<?= e($r['qty']) ?>" style="width:110px;text-align:right;"></td>
                <td style="text-align:right"><?= $cs . money($r['price']) ?><br><small style="color:#62767e;"><?= e($r['src']) ?></small></td>
                <td style="text-align:right" class="line">—</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td colspan="6" style="text-align:right">Selected total</td><td style="text-align:right" class="gtotal">—</td></tr></tfoot>
    </table></div>
</div>
<?php endforeach; ?>
<script>
(function () {
    var cs = <?= json_encode($cs) ?>;
    function fmt(n) { return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function recalc(group) {
        var total = 0;
        group.querySelectorAll('tbody tr').forEach(function (tr) {
            var q = parseFloat(tr.querySelector('.qty').value) || 0;
            var v = q * parseFloat(tr.dataset.price);
            tr.querySelector('.line').textContent = cs + fmt(v);
            if (tr.querySelector('.pick').checked) total += v;
        });
        group.querySelector('.gtotal').textContent = cs + fmt(total);
    }
    document.querySelectorAll('.reorder-group').forEach(function (group) {
        recalc(group);
        group.addEventListener('input', function () { recalc(group); });
        group.addEventListener('change', function () { recalc(group); });
        var btn = group.querySelector('.make-po');
        if (btn) btn.addEventListener('click', function (e) {
            e.preventDefault();
            var items = [];
            group.querySelectorAll('tbody tr').forEach(function (tr) {
                var q = parseFloat(tr.querySelector('.qty').value) || 0;
                if (tr.querySelector('.pick').checked && q > 0) items.push(tr.dataset.mid + ':' + q);
            });
            if (!items.length) { alert('Tick at least one material with a quantity.'); return; }
            var url = '../purchases/create.php?items=' + encodeURIComponent(items.join(','));
            if (group.dataset.supplier !== '0') url += '&supplier_id=' + group.dataset.supplier;
            window.location.href = url;
        });
    });
})();
</script>
<?php endif; ?>
<style>@media print{.no-print,.navbar,.sidebar,.topbar{display:none!important}body{background:#fff}}</style>
<?php require_once "../partials/footer.php"; ?>
