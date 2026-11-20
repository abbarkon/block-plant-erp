<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('production', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS production_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    block_type_id INT NOT NULL,
    target_date DATE NOT NULL,
    target_quantity INT NOT NULL DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_type_id) REFERENCES block_types(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

$pageTitle = 'Production Targets';
$canEdit = has_permission('production', 'create');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_target'])) {
    // Creating targets is a write action — do not let view-only users post.
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to create production targets.');
        header('Location: targets.php');
        exit;
    }
    $blockTypeId = (int) ($_POST['block_type_id'] ?? 0);
    $date = $_POST['target_date'] ?? date('Y-m-d');
    $quantity = (int) ($_POST['target_quantity'] ?? 0);
    if ($blockTypeId > 0 && $quantity > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $stmt = $pdo->prepare("INSERT INTO production_targets(block_type_id, target_date, target_quantity, notes, created_by) VALUES(?,?,?,?,?)");
        $stmt->execute([$blockTypeId, $date, $quantity, trim($_POST['notes'] ?? ''), $_SESSION['user_id']]);
        log_activity('create', 'production', $blockTypeId, "Production target {$quantity} on {$date}");
        set_flash('success', 'Production target saved.');
        header('Location: targets.php');
        exit;
    }
    set_flash('error', 'Choose a block type, a valid date, and a target quantity of at least 1.');
    header('Location: targets.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_target'])) {
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to delete production targets.');
        header('Location: targets.php');
        exit;
    }
    $targetId = (int) $_POST['delete_target'];
    $del = $pdo->prepare("DELETE FROM production_targets WHERE id = ?");
    $del->execute([$targetId]);
    if ($del->rowCount() > 0) {
        log_activity('delete', 'production', $targetId, 'Production target deleted');
        set_flash('success', 'Target deleted.');
    } else {
        set_flash('error', 'Target not found.');
    }
    header('Location: targets.php');
    exit;
}

$blocks = $pdo->query("SELECT * FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();
$targets = $pdo->query("SELECT pt.*, bt.name, bt.size, COALESCE(SUM(p.good_quantity),0) AS achieved FROM production_targets pt JOIN block_types bt ON bt.id = pt.block_type_id LEFT JOIN production p ON p.block_type_id = pt.block_type_id AND p.production_date = pt.target_date GROUP BY pt.id, bt.name, bt.size ORDER BY pt.target_date DESC, bt.name")->fetchAll();
// Cement calculator data: blocks one unit of cement makes, from each block's BOM recipe.
$cementName = '';
$cementUnit = '';
$cementPerBlock = [];
try {
    $cm = $pdo->query("SELECT id, name, unit FROM raw_materials WHERE status=1 AND name LIKE '%cement%' ORDER BY id LIMIT 1")->fetch();
    if ($cm) {
        $cementName = $cm['name'];
        $cementUnit = (string) ($cm['unit'] ?? '');
        $bq = $pdo->prepare("SELECT block_type_id, quantity_per_block FROM block_bom WHERE material_id = ?");
        $bq->execute([(int) $cm['id']]);
        foreach ($bq->fetchAll() as $b) {
            $cementPerBlock[(int) $b['block_type_id']] = (float) $b['quantity_per_block'];
        }
    }
} catch (PDOException $e) {
}
$unitIsKg = (bool) preg_match('/\bkg\b|kilo/i', $cementUnit);
$calcBlocks = [];
foreach ($blocks as $b) {
    $calcBlocks[] = ['id' => (int) $b['id'], 'name' => $b['name'] . ' ' . $b['size'], 'per_unit' => $cementPerBlock[(int) $b['id']] ?? 0];
}
// Material calculator data: each block type's recipe with current stock.
$bomMaterials = [];
try {
    foreach ($pdo->query("SELECT b.block_type_id, b.material_id, b.quantity_per_block, rm.name, rm.unit, rm.current_stock
        FROM block_bom b JOIN raw_materials rm ON rm.id = b.material_id
        WHERE rm.status = 1 AND b.quantity_per_block > 0 ORDER BY rm.name")->fetchAll() as $r) {
        $bomMaterials[(int) $r['block_type_id']][] = [
            'id' => (int) $r['material_id'], 'name' => $r['name'], 'unit' => (string) ($r['unit'] ?? ''),
            'per_block' => (float) $r['quantity_per_block'], 'stock' => (float) $r['current_stock'],
        ];
    }
} catch (PDOException $e) {
}
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Production Targets</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track daily and scheduled production against goals</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to production</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>Set production target</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="add_target" value="1">
        <div>
            <label>Block type</label>
            <select name="block_type_id" id="targetBlock" required>
                <option value="">Select block</option>
                <?php foreach ($blocks as $block): ?>
                <option value="<?= (int) $block['id'] ?>"><?= e($block['name'] . ' ' . $block['size']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Cement bags <small style="color:#64748b;">(optional - calculates the target)</small></label>
            <input type="number" id="cementBags" min="0" step="0.5" placeholder="e.g. 100">
            <?php if ($unitIsKg): ?>
            <label style="margin-top:6px;">Kg per bag</label>
            <input type="number" id="kgPerBag" min="1" step="0.5" value="50">
            <?php endif; ?>
            <p id="cementHint" style="margin:6px 0 0;font-size:.82rem;color:#64748b;"></p>
        </div>
        <div>
            <label>Target quantity</label>
            <input type="number" name="target_quantity" id="targetQty" min="1" required>
        </div>
        <div>
            <label>Target date</label>
            <input type="date" name="target_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="2"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save target</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($canEdit && $cementName): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>Cement calculator</h3>
    <p style="color:#64748b;margin:0 0 10px;">Enter the cement bags you have and see how many blocks of each type they can make (from each block's BOM recipe).</p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
        <input type="number" id="calcBags" min="0" step="0.5" placeholder="Cement bags" style="max-width:200px;">
        <span style="color:#64748b;">bags</span>
    </div>
    <table class="data-table" id="calcTable">
        <thead><tr><th>Block</th><th style="text-align:right">Blocks per bag</th><th style="text-align:right">Expected blocks</th><th class="no-print"></th></tr></thead>
        <tbody></tbody>
    </table>
</div>
<?php endif; ?>

<script>
(function () {
    const blocks = <?= json_encode($calcBlocks) ?>;
    const unitIsKg = <?= json_encode($unitIsKg) ?>;
    const cementUnit = <?= json_encode($cementUnit) ?>;
    const sel = document.getElementById('targetBlock');
    const bagsInput = document.getElementById('cementBags');
    const qty = document.getElementById('targetQty');
    const hint = document.getElementById('cementHint');
    const kgInput = document.getElementById('kgPerBag');
    const calcBags = document.getElementById('calcBags');
    const calcBody = document.querySelector('#calcTable tbody');
    if (!sel || !qty) return;

    // blocks made by one BAG of cement for a block type (0 = no cement in its BOM)
    function perBag(b) {
        if (!b || !b.per_unit) return 0;
        const unitsPerBag = unitIsKg ? (parseFloat(kgInput ? kgInput.value : 50) || 50) : 1;
        return unitsPerBag / b.per_unit;
    }
    function find(id) { return blocks.find(b => String(b.id) === String(id)); }

    function fromBags() {
        const b = find(sel.value);
        const bags = parseFloat(bagsInput.value) || 0;
        const pb = perBag(b);
        if (!b) { hint.textContent = ''; return; }
        if (!pb) { hint.textContent = 'This block type has no cement in its BOM yet (Production, then BOM).'; return; }
        hint.textContent = '1 bag makes about ' + pb.toFixed(1) + ' blocks of ' + b.name + '.';
        if (bags > 0) qty.value = Math.floor(bags * pb);
    }
    bagsInput.addEventListener('input', fromBags);
    sel.addEventListener('change', fromBags);
    if (kgInput) kgInput.addEventListener('input', () => { fromBags(); renderCalc(); });

    function renderCalc() {
        if (!calcBody) return;
        const bags = parseFloat(calcBags.value) || 0;
        calcBody.innerHTML = '';
        blocks.forEach(b => {
            const pb = perBag(b);
            const tr = document.createElement('tr');
            const expected = pb ? Math.floor(bags * pb) : 0;
            tr.innerHTML = '<td>' + b.name + '</td><td style="text-align:right">' + (pb ? pb.toFixed(1) : '—') +
                '</td><td style="text-align:right;font-weight:700">' + (pb && bags > 0 ? expected.toLocaleString() : '—') +
                '</td><td class="no-print">' + (pb && bags > 0 ? '<button type="button" class="btn-sm" data-id="' + b.id + '" data-q="' + expected + '">Use as target</button>' : '') + '</td>';
            calcBody.appendChild(tr);
        });
        calcBody.querySelectorAll('button[data-id]').forEach(btn => btn.addEventListener('click', () => {
            sel.value = btn.dataset.id;
            bagsInput.value = calcBags.value;
            qty.value = btn.dataset.q;
            fromBags();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }));
    }
    if (calcBags) calcBags.addEventListener('input', renderCalc);
    renderCalc();

    // Remember how the target was planned in the notes.
    const form = sel.closest('form');
    form.addEventListener('submit', () => {
        const bags = parseFloat(bagsInput.value) || 0;
        const notes = form.querySelector('textarea[name="notes"]');
        if (bags > 0 && notes && notes.value.indexOf('cement bags') === -1) {
            notes.value = (notes.value ? notes.value + ' | ' : '') + 'Planned from ' + bags + ' cement bags';
        }
    });
})();
</script>

<?php if ($canEdit): ?>
<div class="panel" style="margin-bottom:20px;" id="mcPanel">
    <h3>Material calculator (cement, Bole, Komche and the rest of the recipe)</h3>
    <p style="color:#64748b;margin:0 0 10px;">Pick a block type. Each material in its recipe shows how many blocks it can make from the amount you have. The smallest number is what you can actually produce.</p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;">
        <div><label>Block type</label>
            <select id="mcBlock">
                <?php foreach ($blocks as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name'] . ' ' . $b['size']) ?></option><?php endforeach; ?>
            </select></div>
        <div id="mcTruckBox" style="display:none;"><label>m³ per truck</label><input type="number" id="mcTruck" min="1" step="0.5" value="16" style="width:110px;"></div>
        <button type="button" class="btn btn-secondary" id="mcUseStock">Use current stock</button>
    </div>
    <table class="data-table" id="mcTable">
        <thead><tr><th>Material</th><th style="text-align:right">Needed per block</th><th style="text-align:right">I have</th><th style="text-align:right">Trucks</th><th style="text-align:right">Blocks it can make</th></tr></thead>
        <tbody></tbody>
    </table>
    <p id="mcResult" style="margin:12px 0 0;font-size:1.05rem;font-weight:700;"></p>
    <button type="button" class="btn btn-accent" id="mcUse" style="margin-top:8px;display:none;">Use as target</button>
</div>
<script>
(function () {
    var bom = <?= json_encode($bomMaterials) ?>;
    var sel = document.getElementById('mcBlock');
    if (!sel) return;
    var body = document.querySelector('#mcTable tbody');
    var truckBox = document.getElementById('mcTruckBox');
    var truckInput = document.getElementById('mcTruck');
    var result = document.getElementById('mcResult');
    var useBtn = document.getElementById('mcUse');
    var lastBlocks = 0;
    function isM3(unit) { return /m3|m³|cubic|ሜ/i.test(unit || ''); }
    function fmt(n, d) { return Number(n).toLocaleString(undefined, { maximumFractionDigits: d == null ? 2 : d }); }

    function render(useStock) {
        var rows = bom[sel.value] || [];
        body.innerHTML = '';
        var anyM3 = rows.some(function (r) { return isM3(r.unit); });
        truckBox.style.display = anyM3 ? '' : 'none';
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="5">This block type has no recipe yet (Production, then BOM).</td></tr>';
            result.textContent = ''; useBtn.style.display = 'none'; return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.dataset.per = r.per_block;
            var m3 = isM3(r.unit);
            tr.innerHTML = '<td><strong>' + r.name + '</strong> <small style="color:#62767e">(' + r.unit + ')</small></td>' +
                '<td style="text-align:right">' + fmt(r.per_block, 5) + '</td>' +
                '<td style="text-align:right"><input type="number" class="have" min="0" step="0.01" style="width:120px;text-align:right" value="' + (useStock ? r.stock : '') + '" placeholder="' + fmt(r.stock) + ' in stock"></td>' +
                '<td style="text-align:right">' + (m3 ? '<input type="number" class="trucks" min="0" step="0.5" style="width:90px;text-align:right" placeholder="trucks">' : '') + '</td>' +
                '<td style="text-align:right;font-weight:700" class="can">—</td>';
            body.appendChild(tr);
        });
        recalc();
    }
    function recalc() {
        var min = null, limiting = '';
        body.querySelectorAll('tr[data-per]').forEach(function (tr) {
            var have = parseFloat(tr.querySelector('.have').value);
            var can = tr.querySelector('.can');
            if (isNaN(have)) { can.textContent = '—'; return; }
            var blocks = Math.floor(have / parseFloat(tr.dataset.per));
            can.textContent = fmt(blocks, 0);
            if (min === null || blocks < min) { min = blocks; limiting = tr.querySelector('strong').textContent; }
        });
        lastBlocks = min === null ? 0 : min;
        if (min === null) { result.textContent = ''; useBtn.style.display = 'none'; return; }
        result.textContent = 'You can make about ' + fmt(min, 0) + ' blocks, limited by ' + limiting + '.';
        useBtn.style.display = min > 0 ? '' : 'none';
    }
    body.addEventListener('input', function (e) {
        var tr = e.target.closest('tr[data-per]');
        if (!tr) return;
        if (e.target.classList.contains('trucks')) {
            var t = parseFloat(e.target.value) || 0;
            tr.querySelector('.have').value = e.target.value === '' ? '' : (t * (parseFloat(truckInput.value) || 16)).toFixed(2);
        }
        recalc();
    });
    truckInput.addEventListener('input', function () {
        body.querySelectorAll('tr[data-per]').forEach(function (tr) {
            var t = tr.querySelector('.trucks');
            if (t && t.value !== '') tr.querySelector('.have').value = ((parseFloat(t.value) || 0) * (parseFloat(truckInput.value) || 16)).toFixed(2);
        });
        recalc();
    });
    sel.addEventListener('change', function () { render(false); });
    document.getElementById('mcUseStock').addEventListener('click', function () { render(true); });
    useBtn.addEventListener('click', function () {
        var target = document.getElementById('targetBlock'), qty = document.getElementById('targetQty');
        if (target && qty) { target.value = sel.value; qty.value = lastBlocks; window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });
    render(false);
})();
</script>
<?php endif; ?>

<div class="panel">
    <h3>Target performance</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Block</th>
                <th>Date</th>
                <th>Target</th>
                <th>Achieved</th>
                <th>Status</th>
                <?php if ($canEdit): ?><th class="no-print"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$targets): ?>
                <tr><td colspan="<?= $canEdit ? 6 : 5 ?>">No production targets created yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($targets as $target): ?>
            <tr>
                <td><?= e($target['name'] . ' ' . $target['size']) ?></td>
                <td><?= format_date($target['target_date']) ?></td>
                <td><?= number_format((int) $target['target_quantity']) ?></td>
                <td><?= number_format((int) $target['achieved']) ?></td>
                <td>
                    <?php $pct = $target['target_quantity'] > 0 ? ((int) $target['achieved'] / (int) $target['target_quantity']) * 100 : 0; ?>
                    <span class="badge badge-<?= $pct >= 100 ? 'success' : ($pct >= 75 ? 'warning' : 'danger') ?>"><?= number_format($pct, 1) ?>%</span>
                </td>
                <?php if ($canEdit): ?>
                <td class="no-print">
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this target?');">
                        <input type="hidden" name="delete_target" value="<?= (int) $target['id'] ?>">
                        <button type="submit" class="btn-sm" style="background:#c0392b;border:0;color:#fff;cursor:pointer;">Delete</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
