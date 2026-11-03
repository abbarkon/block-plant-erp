<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'edit');
ensure_gate_schema();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT gc.*, s.invoice_no, s.balance, c.name AS customer_name FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id LEFT JOIN customers c ON c.id=gc.customer_id WHERE gc.id=?");
$stmt->execute([$id]);
$clearance = $stmt->fetch();
if (!$clearance) {
    render_not_found(t('clearance_not_found'), t('clearance_not_found_hint'), '/block_plant/gate/index.php', t('gate'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reinstate'])) {
    $note = trim($_POST['note'] ?? '');
    if ($note === '') {
        set_flash('error', 'A reinstate note is required.');
        header('Location: reinstate.php?id=' . $id);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT gc.*, s.balance FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id WHERE gc.id=? FOR UPDATE");
        $lock->execute([$id]);
        $current = $lock->fetch();
        if (!$current || $current['status'] !== 'REJECTED') {
            throw new Exception('Only a rejected clearance can be reinstated.');
        }
        if ($current['payment_status'] !== 'PAID' || (float) $current['balance'] > 0) {
            throw new Exception('This sale is no longer fully paid, so the clearance cannot be reinstated.');
        }
        // Return to the loading step if loading was never verified, otherwise straight to the gate.
        $newStatus = !empty($current['loaded_at']) ? 'READY_FOR_GATE' : 'WAITING_LOADING';
        $update = $pdo->prepare("UPDATE gate_clearances SET status=?, cancelled_by=NULL, cancelled_at=NULL, cancellation_reason=? WHERE id=? AND status='REJECTED'");
        $update->execute([$newStatus, 'Reinstated: ' . $note, $id]);
        if ($update->rowCount() !== 1) {
            throw new Exception('Clearance could not be reinstated.');
        }
        log_activity('clearance_reinstated', 'gate', $id, "Clearance {$current['clearance_number']} reinstated to {$newStatus}: {$note}");

        // Notify the salesperson that the hold was cleared
        $spStmt = $pdo->prepare("SELECT salesperson_id FROM sales WHERE id=?");
        $spStmt->execute([$current['sale_id']]);
        $spId = (int) $spStmt->fetchColumn();
        if ($spId && $spId != $_SESSION['user_id']) {
            $msg = "Gate clearance {$current['clearance_number']} was reinstated ({$newStatus}). Note: {$note}";
            notify_user($spId, $msg, '/block_plant/gate/index.php?clearance=' . urlencode($current['clearance_number']));
        }

        $pdo->commit();
        set_flash('success', 'Clearance reinstated. Status is now ' . str_replace('_', ' ', $newStatus) . '.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage());
    }
    header('Location: index.php?clearance=' . urlencode($clearance['clearance_number']));
    exit;
}

$pageTitle = 'Reinstate Clearance';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="panel" style="max-width:620px;margin:40px auto;border-top:5px solid #2a9d8f;">
    <h1>Reinstate this clearance?</h1>
    <p>Use this when a rejected vehicle's issue is resolved. The clearance returns to the gate (or loading, if it was never loaded). A note is required and the salesperson will be notified.</p>
    <div class="grid-2">
        <p><strong>Clearance</strong><br><?= e($clearance['clearance_number']) ?></p>
        <p><strong>Invoice</strong><br><?= e($clearance['invoice_no']) ?></p>
        <p><strong>Customer</strong><br><?= e($clearance['customer_name'] ?? 'Walk-in') ?></p>
        <p><strong>Vehicle</strong><br><?= e($clearance['vehicle_plate']) ?></p>
        <p><strong>Status</strong><br><?= e(str_replace('_', ' ', $clearance['status'])) ?></p>
        <p><strong>Payment</strong><br><?= e($clearance['payment_status']) ?></p>
    </div>
    <?php if (!empty($clearance['cancellation_reason'])): ?>
        <p style="margin-top:12px;"><strong>Original rejection reason:</strong><br><?= e($clearance['cancellation_reason']) ?></p>
    <?php endif; ?>
    <?php if ($clearance['status'] !== 'REJECTED'): ?>
        <p style="color:#b45309;font-weight:600;margin-top:14px;">Only a REJECTED clearance can be reinstated. Current status: <?= e(str_replace('_', ' ', $clearance['status'])) ?>.</p>
    <?php else: ?>
    <form method="post" style="margin-top:20px;">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="note" style="display:block;font-weight:600;margin-bottom:6px;">Reinstate note</label>
        <textarea id="note" name="note" rows="3" required placeholder="e.g. Correct vehicle arrived; quantity re-verified" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
        <div style="display:flex;gap:10px;margin-top:16px;">
            <button class="btn btn-accent" type="submit" name="confirm_reinstate" value="1">Reinstate clearance</button>
            <a class="btn btn-secondary" href="index.php?clearance=<?= urlencode($clearance['clearance_number']) ?>">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
