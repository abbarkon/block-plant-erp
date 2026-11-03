<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('purchases', 'view');
ensure_purchase_payment_schema();

$pageTitle = t('supplier_payments');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission('purchases', 'edit')) {
        set_flash('error', 'You do not have permission to record supplier payments.');
        header('Location: supplier.php');
        exit;
    }
    $supplier = (int) ($_POST['supplier_id'] ?? 0);
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $method = trim((string) ($_POST['payment_method'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $purchase_id = !empty($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : null;

    $pdo->beginTransaction();
    try {
        if ($supplier <= 0) {
            throw new RuntimeException(t('select_supplier_before_payment'));
        }
        $supplierCheck = $pdo->prepare("SELECT id FROM suppliers WHERE id = ? AND status = 1");
        $supplierCheck->execute([$supplier]);
        if (!$supplierCheck->fetchColumn()) {
            throw new RuntimeException(t('select_supplier_before_payment'));
        }
        if (!is_valid_date_string($date)) {
            throw new RuntimeException('Enter a valid payment date.');
        }
        if ($date > date('Y-m-d')) {
            throw new RuntimeException('The payment date cannot be in the future.');
        }
        if (!in_array($method, ['cash', 'bank', 'telebirr', 'chapa', 'cheque', 'mobile money'], true)) {
            throw new RuntimeException('Select a valid payment method.');
        }
        if (mb_strlen($notes) > 255) {
            throw new RuntimeException('Notes must be 255 characters or fewer.');
        }

        if ($purchase_id) {
            $purchaseStmt = $pdo->prepare("SELECT supplier_id, balance FROM purchases WHERE id = ? AND approval_status = 'approved' AND balance > 0 FOR UPDATE");
            $purchaseStmt->execute([$purchase_id]);
            $purchase = $purchaseStmt->fetch();
            if (!$purchase) {
                throw new RuntimeException(t('payment_requires_approved_po'));
            }
            if ($purchase['supplier_id'] !== null && (int) $purchase['supplier_id'] !== $supplier) {
                throw new RuntimeException(t('supplier_does_not_match_po'));
            }
            if ($purchase['supplier_id'] === null) {
                $pdo->prepare("UPDATE purchases SET supplier_id = ? WHERE id = ? AND supplier_id IS NULL")
                    ->execute([$supplier, $purchase_id]);
            }
            if ($amount <= 0 || $amount > (float) $purchase['balance']) {
                throw new RuntimeException(t('payment_exceeds_po_balance'));
            }
            $pdo->prepare("UPDATE purchases SET paid_amount=paid_amount+?, balance=balance-?, payment_status=CASE WHEN balance-?<=0 THEN 'paid' ELSE 'partial' END WHERE id=?")
                ->execute([$amount, $amount, $amount, $purchase_id]);
        } else {
            if ($amount <= 0) {
                throw new RuntimeException(t('payment_must_be_positive'));
            }
            $remaining = $amount;
            $open = $pdo->prepare("SELECT id, balance FROM purchases WHERE supplier_id=? AND approval_status='approved' AND balance>0 ORDER BY purchase_date ASC, id ASC FOR UPDATE");
            $open->execute([$supplier]);
            foreach ($open->fetchAll() as $po) {
                if ($remaining <= 0) break;
                $apply = min($remaining, (float) $po['balance']);
                $pdo->prepare("UPDATE purchases SET paid_amount=paid_amount+?, balance=balance-?, payment_status=CASE WHEN balance-?<=0 THEN 'paid' ELSE 'partial' END WHERE id=?")
                    ->execute([$apply, $apply, $apply, $po['id']]);
                $remaining -= $apply;
            }
            if ($remaining > 0.009) {
                throw new RuntimeException(t('payment_exceeds_supplier_balance'));
            }
        }

        $paymentId = null;
        try {
            $pdo->prepare("INSERT INTO supplier_payments(purchase_id,supplier_id,payment_date,amount,payment_method,notes,created_by) VALUES(?,?,?,?,?,?,?)")
                ->execute([$purchase_id, $supplier, $date, $amount, $method, $notes, $_SESSION['user_id']]);
            $paymentId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Older databases may not have the supplier_payments table; the cashbook
            // and purchase balances are still the authoritative records.
        }

        $reference = 'SUP-PAY-' . ($paymentId ?: ('S' . $supplier . '-' . date('YmdHis')));
        $pdo->prepare("INSERT INTO cashbook(transaction_date,transaction_type,reference_no,description,amount,payment_method,created_by) VALUES(?,'expense',?,?,?,?,?)")
            ->execute([$date, $reference, mb_substr('Supplier payment' . ($notes !== '' ? ': ' . $notes : ''), 0, 255), $amount, $method, $_SESSION['user_id']]);

        post_accounting_journal($reference, $date, 'Supplier payment', mb_substr('Supplier #' . $supplier, 0, 60), [
            ['2110', $amount, 0, 'Supplier payable settled'],
            ['1110', 0, $amount, 'Cash paid to supplier'],
        ], (int) $_SESSION['user_id']);

        $pdo->commit();
        log_activity('create', 'purchases', $paymentId ?: $supplier, 'Supplier payment of ' . money($amount) . ' recorded');
        set_flash('success', t('payment_recorded'));
        header('Location: supplier.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$selectedPurchaseId = (int) ($_GET['purchase_id'] ?? 0);
$selectedSupplierId = 0;
$selectedPurchase = null;
if ($selectedPurchaseId > 0) {
    $selectedPurchaseStmt = $pdo->prepare("SELECT id, supplier_id, purchase_no, balance FROM purchases WHERE id = ? AND approval_status = 'approved' AND balance > 0");
    $selectedPurchaseStmt->execute([$selectedPurchaseId]);
    $selectedPurchase = $selectedPurchaseStmt->fetch();
    if ($selectedPurchase) {
        $selectedSupplierId = (int) ($selectedPurchase['supplier_id'] ?? 0);
    } else {
        $selectedPurchaseId = 0;
    }
}

$showAllSuppliers = $selectedPurchase && empty($selectedPurchase['supplier_id']);
$supplierBalanceFilter = $showAllSuppliers ? '' : 'HAVING outstanding > 0';
$suppliers = $pdo->query("
    SELECT s.id, s.name, COALESCE(SUM(p.balance),0) AS outstanding
    FROM suppliers s
    LEFT JOIN purchases p ON s.id = p.supplier_id AND p.balance > 0 AND p.approval_status = 'approved'
    WHERE s.status = 1
    GROUP BY s.id
    {$supplierBalanceFilter}
    ORDER BY s.name
")->fetchAll();

$recent = [];
try {
    $recent = $pdo->query("SELECT sp.*, s.name AS supplier_name, pu.purchase_no FROM supplier_payments sp LEFT JOIN suppliers s ON sp.supplier_id=s.id LEFT JOIN purchases pu ON sp.purchase_id=pu.id ORDER BY sp.created_at DESC LIMIT 20")->fetchAll();
} catch (PDOException $e) {
    $recent = [];
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= t('supplier_payments') ?></h1>
    <a href="../purchases/index.php" class="btn btn-secondary"><?= t('purchase_orders') ?></a>
</div>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid-2">
    <?php if (has_permission('purchases', 'edit')): ?>
    <div class="panel">
        <h3><?= t('record_payment') ?></h3>
        <form method="post">
            <label><?= t('supplier') ?> *</label>
            <select name="supplier_id" id="supplierSelect" required>
                <option value=""><?= t('select_supplier') ?></option>
                <?php foreach ($suppliers as $sup): ?>
                <option value="<?= (int) $sup['id'] ?>" <?= (int) $sup['id'] === $selectedSupplierId ? 'selected' : '' ?>><?= e($sup['name']) ?> (<?= currency_symbol() . money($sup['outstanding']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <label><?= t('purchase_order_optional') ?></label>
            <select name="purchase_id" id="poSelect" data-initial-purchase-id="<?= (int) $selectedPurchaseId ?>">
                <option value=""><?= t('apply_oldest_open_pos') ?></option>
                <?php if ($selectedPurchase): ?>
                <option value="<?= (int) $selectedPurchase['id'] ?>" selected><?= e($selectedPurchase['purchase_no']) ?> - balance <?= currency_symbol() . money($selectedPurchase['balance']) ?></option>
                <?php endif; ?>
            </select>
            <label><?= t('amount') ?> *</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($_POST['amount'] ?? ($selectedPurchase ? (string) $selectedPurchase['balance'] : '')) ?>" required>
            <label><?= t('date') ?> *</label>
            <input type="date" name="payment_date" value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required>
            <label><?= t('method') ?></label>
            <?php $methodValue = $_POST['payment_method'] ?? 'cash'; ?>
            <select name="payment_method"><option value="cash" <?= $methodValue === 'cash' ? 'selected' : '' ?>><?= t('cash') ?></option><option value="bank" <?= $methodValue === 'bank' ? 'selected' : '' ?>><?= t('bank') ?></option><option value="telebirr" <?= $methodValue === 'telebirr' ? 'selected' : '' ?>>Telebirr</option><option value="chapa" <?= $methodValue === 'chapa' ? 'selected' : '' ?>>Chapa</option></select>
            <label><?= t('notes') ?></label>
            <textarea name="notes" rows="2" maxlength="255"><?= e($_POST['notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-accent" style="margin-top:12px;"><?= t('save_payment') ?></button>
        </form>
    </div>
    <?php else: ?>
    <div class="panel">
        <h3><?= t('record_payment') ?></h3>
        <p style="color:#64748b;margin:0;">You can view supplier balances, but recording a payment requires purchase edit permission.</p>
    </div>
    <?php endif; ?>
    <div class="panel">
        <h3><?= t('suppliers_with_balance') ?></h3>
        <table class="data-table">
            <thead><tr><th><?= t('suppliers') ?></th><th><?= t('outstanding') ?></th></tr></thead>
            <tbody>
                <?php foreach ($suppliers as $sup): ?>
                <tr><td><?= e($sup['name']) ?></td><td><?= currency_symbol() . money($sup['outstanding']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($recent): ?>
<div class="panel">
    <h3><?= t('recent_supplier_payments') ?></h3>
    <table class="data-table">
        <thead><tr><th><?= t('date') ?></th><th><?= t('suppliers') ?></th><th><?= t('purchase_number') ?></th><th><?= t('amount') ?></th><th><?= t('method') ?></th></tr></thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= format_date($r['payment_date']) ?></td>
                <td><?= e($r['supplier_name'] ?? '—') ?></td>
                <td><?= e($r['purchase_no'] ?? t('general')) ?></td>
                <td><?= currency_symbol() . money($r['amount']) ?></td>
                <td><?= e($r['payment_method']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function loadPOs(sid, selectedPurchaseId = 0) {
    const sel = document.getElementById('poSelect');
    sel.innerHTML = '<option value="">Apply to oldest open POs</option>';
    if (!sid) return;
    fetch('../purchases/open_pos.php?supplier_id=' + sid)
        .then(r => r.json())
        .then(rows => {
            const selectedExists = rows.some(po => String(po.id) === String(selectedPurchaseId));
            rows.forEach(po => {
                const o = document.createElement('option');
                o.value = po.id;
                o.textContent = po.purchase_no + ' — balance ' + po.balance;
                o.selected = String(po.id) === String(selectedPurchaseId);
                sel.appendChild(o);
            });
            if (!selectedExists && initialUnassignedPurchase && String(initialUnassignedPurchase.id) === String(selectedPurchaseId)) {
                const o = document.createElement('option');
                o.value = initialUnassignedPurchase.id;
                o.textContent = initialUnassignedPurchase.purchase_no + ' — balance ' + initialUnassignedPurchase.balance;
                o.selected = true;
                sel.appendChild(o);
            }
        })
        .catch(() => {});
}

const initialSupplierId = <?= json_encode($selectedSupplierId) ?>;
const initialPurchaseId = <?= json_encode($selectedPurchaseId) ?>;
const initialUnassignedPurchase = <?= json_encode($selectedPurchase && empty($selectedPurchase['supplier_id']) ? [
    'id' => (int) $selectedPurchase['id'],
    'purchase_no' => $selectedPurchase['purchase_no'],
    'balance' => number_format((float) $selectedPurchase['balance'], 2, '.', ''),
] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.getElementById('supplierSelect').addEventListener('change', function () {
    loadPOs(this.value, document.getElementById('poSelect').dataset.initialPurchaseId);
});
if (initialSupplierId) loadPOs(initialSupplierId, initialPurchaseId);
</script>

<?php require_once "../partials/footer.php"; ?>
