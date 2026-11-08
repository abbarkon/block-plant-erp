<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');

$canEdit = has_permission('inventory', 'edit');
$pageTitle = "Stock Count";
$error = '';

$materials = $pdo->query("SELECT id, name, unit, unit_cost, current_stock FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to record a stock count.');
        header('Location: stock_count.php');
        exit;
    }
    $date = (string) ($_POST['count_date'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $counted = $_POST['counted'] ?? [];
    if (!is_valid_date_string($date) || $date > date('Y-m-d')) {
        $error = 'Choose a valid count date (not in the future).';
    } else {
        $countNo = 'CNT-' . date('YmdHis');
        $reason = 'Physical stock count ' . $date . ($note !== '' ? ' - ' . $note : '');
        $changed = 0;
        try {
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT id, name, current_stock FROM raw_materials WHERE id = ? AND status = 1 FOR UPDATE");
            $insAdj = $pdo->prepare("INSERT INTO stock_adjustments(adjustment_no, adjustment_date, material_id, adjustment_type, quantity, reason, created_by) VALUES(?,?,?,?,?,?,?)");
            $upd = $pdo->prepare("UPDATE raw_materials SET current_stock = ? WHERE id = ?");
            $insTx = $pdo->prepare("INSERT INTO stock_transactions(material_id, transaction_type, quantity, reference_no, notes, transaction_date, created_by) VALUES(?,?,?,?,?,?,?)");
            foreach ($counted as $mid => $value) {
                $value = trim((string) $value);
                if ($value === '' || !is_numeric($value) || (float) $value < 0) {
                    continue;                      // not counted
                }
                $mid = (int) $mid;
                $sel->execute([$mid]);
                $m = $sel->fetch();
                if (!$m) {
                    continue;
                }
                $new = round((float) $value, 2);
                $diff = round($new - (float) $m['current_stock'], 2);
                if (abs($diff) < 0.005) {
                    continue;                      // matches the system
                }
                $type = $diff > 0 ? 'increase' : 'decrease';
                $adjNo = $countNo . '-' . $mid;
                $insAdj->execute([$adjNo, $date, $mid, $type, abs($diff), $reason, $_SESSION['user_id']]);
                $upd->execute([$new, $mid]);
                $insTx->execute([$mid, 'adjustment', $diff, $adjNo, $reason, $date, $_SESSION['user_id']]);
                $changed++;
            }
            $pdo->commit();
            log_activity('update', 'inventory', 0, "Stock count {$countNo}: {$changed} material(s) adjusted");
            set_flash('success', $changed > 0 ? "Stock count saved: {$changed} material(s) adjusted." : 'Stock count saved: every counted quantity matched the system.');
            header('Location: stock_count.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$flash = get_flash();
require_once "../partials/header.php";
$cs = currency_symbol();
?>
<div class="page-header">
    <div>
        <h1>Stock Count</h1>
        <p style="color:#64748b;margin:6px 0 0;">Count what is physically in the store, type it in, and save. Differences are posted as stock adjustments.</p>
    </div>
    <div class="header-actions no-print">
        <a href="reorder_list.php" class="btn btn-secondary">Reorder suggestions</a>
        <a href="adjustments.php" class="btn btn-secondary">Adjustments</a>
        <button type="button" class="btn btn-accent" onclick="window.print()">Print count sheet</button>
    </div>
</div>
<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="panel">
    <?php if ($canEdit): ?>
    <div class="no-print" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div><label>Count date</label><input type="date" name="count_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div style="flex:1;min-width:220px;"><label>Note (optional)</label><input type="text" name="note" placeholder="e.g. Monthly count, counted by store keeper"></div>
    </div>
    <?php endif; ?>
    <div class="table-container"><table class="data-table">
        <thead><tr><th>Material</th><th>Unit</th><th style="text-align:right">In system</th><th style="text-align:right">Counted</th><th style="text-align:right">Difference</th><th style="text-align:right">Value of difference</th></tr></thead>
        <tbody>
        <?php foreach ($materials as $m): ?>
            <tr data-sys="<?= e($m['current_stock']) ?>" data-cost="<?= e($m['unit_cost']) ?>">
                <td><strong><?= e($m['name']) ?></strong></td>
                <td><?= e($m['unit']) ?></td>
                <td style="text-align:right"><?= number_format((float) $m['current_stock'], 2) ?></td>
                <td style="text-align:right"><?php if ($canEdit): ?><input type="number" step="0.01" min="0" name="counted[<?= (int) $m['id'] ?>]" class="cnt" style="width:120px;text-align:right;" placeholder="—"><?php else: ?>&nbsp;<?php endif; ?></td>
                <td style="text-align:right;font-weight:700" class="diff">—</td>
                <td style="text-align:right" class="val">—</td>
            </tr>
        <?php endforeach; if (!$materials): ?><tr><td colspan="6">No raw materials.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr style="font-weight:800;"><td colspan="5" style="text-align:right">Total value of differences</td><td style="text-align:right" id="totalVal">—</td></tr></tfoot>
    </table></div>
    <?php if ($canEdit): ?>
    <p style="font-size:.85rem;color:#64748b;">Leave a quantity empty to skip that material. Only materials that differ from the system are adjusted.</p>
    <button type="submit" class="btn btn-accent no-print" onclick="return confirm('Save this stock count? Differences will be posted as stock adjustments.');">Save stock count</button>
    <?php endif; ?>
</form>
<script>
(function () {
    var cs = <?= json_encode($cs) ?>;
    function fmt(n) { return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function recalc() {
        var total = 0, any = false;
        document.querySelectorAll('tbody tr[data-sys]').forEach(function (tr) {
            var inp = tr.querySelector('.cnt');
            var diffEl = tr.querySelector('.diff'), valEl = tr.querySelector('.val');
            if (!inp || inp.value === '') { diffEl.textContent = '—'; valEl.textContent = '—'; diffEl.style.color = ''; return; }
            var d = (parseFloat(inp.value) || 0) - parseFloat(tr.dataset.sys);
            var v = d * parseFloat(tr.dataset.cost);
            total += v; any = true;
            diffEl.textContent = (d > 0 ? '+' : '') + fmt(d);
            diffEl.style.color = Math.abs(d) < 0.005 ? '#15803d' : (d < 0 ? '#c0392b' : '#b45309');
            valEl.textContent = cs + fmt(v);
        });
        document.getElementById('totalVal').textContent = any ? cs + fmt(total) : '—';
    }
    document.querySelectorAll('.cnt').forEach(function (i) { i.addEventListener('input', recalc); });
})();
</script>
<style>@media print{.no-print,.navbar,.sidebar,.topbar{display:none!important}body{background:#fff}input.cnt{border:1px solid #999}}</style>
<?php require_once "../partials/footer.php"; ?>
