<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'edit');
ensure_gate_schema();

// Detail / confirmation view for a single clearance
$verifyId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_loading'])) {
    $id = (int) ($_POST['clearance_id'] ?? 0);
    $loaded = $_POST['loaded_qty'] ?? [];
    $allowMismatch = isset($_POST['allow_mismatch']);

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT gc.*, s.invoice_no FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id WHERE gc.id=? AND gc.status='WAITING_LOADING' AND gc.payment_status='PAID' FOR UPDATE");
        $lock->execute([$id]);
        $clearance = $lock->fetch();
        if (!$clearance) {
            throw new Exception('Clearance is not waiting for loading or is unpaid.');
        }

        // Ordered items for this sale
        $orderedStmt = $pdo->prepare("SELECT si.block_type_id, si.quantity AS ordered_qty, bt.name, bt.size FROM sale_items si JOIN block_types bt ON bt.id=si.block_type_id WHERE si.sale_id=?");
        $orderedStmt->execute([$clearance['sale_id']]);
        $ordered = $orderedStmt->fetchAll();

        $mismatches = [];
        foreach ($ordered as $o) {
            $btId = (int) $o['block_type_id'];
            $orderedQty = (int) $o['ordered_qty'];
            $loadedQty = isset($loaded[$btId]) ? max(0, (int) $loaded[$btId]) : 0;
            if ($loadedQty !== $orderedQty) {
                $mismatches[] = $o['name'] . ' ' . $o['size'] . " (ordered {$orderedQty}, loaded {$loadedQty})";
            }
        }

        if ($mismatches && !$allowMismatch) {
            $pdo->rollBack();
            set_flash('error', 'Loaded quantities do not match the order: ' . implode('; ', $mismatches) . '. Correct them, or tick "allow mismatch" to override.');
            header('Location: loading.php?id=' . $id);
            exit;
        }

        // Record verified loading quantities
        $pdo->prepare("DELETE FROM gate_loading_items WHERE clearance_id=?")->execute([$id]);
        $insItem = $pdo->prepare("INSERT INTO gate_loading_items(clearance_id,block_type_id,ordered_qty,loaded_qty) VALUES(?,?,?,?)");
        foreach ($ordered as $o) {
            $btId = (int) $o['block_type_id'];
            $loadedQty = isset($loaded[$btId]) ? max(0, (int) $loaded[$btId]) : 0;
            $insItem->execute([$id, $btId, (int) $o['ordered_qty'], $loadedQty]);
        }

        $update = $pdo->prepare("UPDATE gate_clearances SET status='READY_FOR_GATE', loaded_at=NOW(), loaded_by=? WHERE id=? AND status='WAITING_LOADING'");
        $update->execute([$_SESSION['user_id'], $id]);
        if ($update->rowCount() !== 1) {
            throw new Exception('Clearance could not be advanced.');
        }

        $note = $mismatches ? ' with quantity mismatch override: ' . implode('; ', $mismatches) : '';
        log_activity('confirm_loading', 'gate', $id, "Loading confirmed for {$clearance['clearance_number']}{$note}");

        $pdo->commit();
        set_flash('success', $mismatches ? 'Loading confirmed with a recorded mismatch override.' : 'Loading confirmed. Clearance is ready for the gate.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage());
    }
    header('Location: loading.php');
    exit;
}

$flash = get_flash();
$pageTitle = 'Loading Confirmation';

// --- Detail view: quantity verification form ---
if ($verifyId > 0) {
    $stmt = $pdo->prepare("SELECT gc.*, s.invoice_no, c.name AS customer_name FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id LEFT JOIN customers c ON c.id=gc.customer_id WHERE gc.id=?");
    $stmt->execute([$verifyId]);
    $clearance = $stmt->fetch();
    if (!$clearance) {
        set_flash('error', 'Clearance not found.');
        header('Location: loading.php');
        exit;
    }
    $itemStmt = $pdo->prepare("SELECT si.block_type_id, si.quantity AS ordered_qty, bt.name, bt.size FROM sale_items si JOIN block_types bt ON bt.id=si.block_type_id WHERE si.sale_id=?");
    $itemStmt->execute([$clearance['sale_id']]);
    $items = $itemStmt->fetchAll();

    require_once __DIR__ . '/../partials/header.php';
    ?>
    <div class="page-header">
        <div><h1>Verify loading — <?= e($clearance['clearance_number']) ?></h1>
        <p style="color:#646b8c;">Enter the quantity actually loaded for each product. The clearance only advances when loaded matches ordered, unless you override a mismatch.</p></div>
        <a href="loading.php" class="btn btn-secondary">← Back to list</a>
    </div>
    <?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <div class="panel" style="max-width:860px;">
        <div class="grid-2" style="margin-bottom:14px;">
            <p><strong>Invoice:</strong> <?= e($clearance['invoice_no']) ?></p>
            <p><strong>Customer:</strong> <?= e($clearance['customer_name'] ?? 'Walk-in') ?></p>
            <p><strong>Vehicle:</strong> <?= e($clearance['vehicle_plate']) ?></p>
            <p><strong>Driver:</strong> <?= e($clearance['driver_name'] ?? '—') ?> <?= e($clearance['driver_phone'] ?? '') ?></p>
        </div>
        <?php if ($clearance['status'] !== 'WAITING_LOADING'): ?>
            <p style="color:#b45309;font-weight:600;">This clearance is no longer waiting for loading (status: <?= e(str_replace('_', ' ', $clearance['status'])) ?>).</p>
        <?php else: ?>
        <form method="post" id="loadingForm">
            <input type="hidden" name="confirm_loading" value="1">
            <input type="hidden" name="clearance_id" value="<?= (int) $clearance['id'] ?>">
            <table class="data-table">
                <thead><tr><th>Product</th><th>Ordered</th><th>Loaded</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['name'] . ' ' . $it['size']) ?></td>
                        <td><?= number_format((int) $it['ordered_qty']) ?></td>
                        <td><input type="number" min="0" step="1" name="loaded_qty[<?= (int) $it['block_type_id'] ?>]" value="<?= (int) $it['ordered_qty'] ?>" data-ordered="<?= (int) $it['ordered_qty'] ?>" class="loaded-input" style="width:110px;padding:6px;border:1px solid #cbd5e1;border-radius:6px;"></td>
                        <td class="match-cell" style="font-weight:600;color:#7a5cd6;">✓ Match</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="mismatchWarning" style="display:none;margin-top:14px;padding:12px;background:#fff3cd;border:1px solid #f0c36d;border-radius:8px;color:#92400e;">
                <strong>⚠ Quantity mismatch detected.</strong> Loaded quantities differ from the order. You must tick the override box below to proceed.
                <label style="display:block;margin-top:8px;font-weight:600;"><input type="checkbox" name="allow_mismatch" value="1"> Allow mismatch and record an override</label>
            </div>
            <div style="display:flex;gap:10px;margin-top:18px;">
                <button class="btn btn-accent" type="submit">Confirm loading</button>
                <a class="btn btn-secondary" href="loading.php">Cancel</a>
            </div>
        </form>
        <script>
        (function () {
            var inputs = document.querySelectorAll('.loaded-input');
            var warning = document.getElementById('mismatchWarning');
            function check() {
                var mismatch = false;
                inputs.forEach(function (inp) {
                    var cell = inp.closest('tr').querySelector('.match-cell');
                    if (parseInt(inp.value || '0', 10) === parseInt(inp.dataset.ordered, 10)) {
                        cell.textContent = '✓ Match';
                        cell.style.color = '#7a5cd6';
                    } else {
                        cell.textContent = '✕ Mismatch';
                        cell.style.color = '#b91c1c';
                        mismatch = true;
                    }
                });
                warning.style.display = mismatch ? 'block' : 'none';
                if (!mismatch) {
                    var cb = warning.querySelector('input[type=checkbox]');
                    if (cb) cb.checked = false;
                }
            }
            inputs.forEach(function (inp) { inp.addEventListener('input', check); });
            check();
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../partials/footer.php';
    exit;
}

// --- List view ---
$rows = $pdo->query("SELECT gc.*, s.invoice_no, c.name AS customer_name, (SELECT GROUP_CONCAT(CONCAT(bt.name, ' ', bt.size, ' x ', si.quantity) SEPARATOR ', ') FROM sale_items si JOIN block_types bt ON bt.id=si.block_type_id WHERE si.sale_id=s.id) AS products FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id LEFT JOIN customers c ON c.id=gc.customer_id WHERE gc.status='WAITING_LOADING' ORDER BY gc.generated_at ASC")->fetchAll();
require_once __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h1>Loading Confirmation</h1><p style="color:#646b8c;">Verify the correct vehicle and loaded quantities before releasing a paid sale to the gate.</p></div><a href="index.php" class="btn btn-secondary">Gate dashboard</a></div>
<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<div class="table-container"><table class="data-table"><thead><tr><th>Clearance</th><th>Invoice</th><th>Customer</th><th>Vehicle</th><th>Products</th><th>Payment</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">No paid sales are waiting for loading confirmation.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><strong><?= e($row['clearance_number']) ?></strong></td><td><?= e($row['invoice_no']) ?></td><td><?= e($row['customer_name'] ?? 'Walk-in') ?></td><td><?= e($row['vehicle_plate']) ?></td><td><?= e($row['products'] ?? '—') ?></td><td><span class="badge badge-success">PAID</span></td><td><a class="btn btn-accent" href="loading.php?id=<?= (int) $row['id'] ?>">Verify &amp; confirm</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
