<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'edit');
ensure_gate_schema();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT gc.*, s.invoice_no, c.name AS customer_name FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id LEFT JOIN customers c ON c.id=gc.customer_id WHERE gc.id=?");
$stmt->execute([$id]);
$clearance = $stmt->fetch();
if (!$clearance) {
    render_not_found(t('clearance_not_found'), t('clearance_not_found_hint'), '/block_plant/gate/index.php', t('gate'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reject'])) {
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        set_flash('error', 'A rejection reason is required.');
        header('Location: reject.php?id=' . $id);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM gate_clearances WHERE id=? FOR UPDATE");
        $lock->execute([$id]);
        $current = $lock->fetch();
        if (!$current || !in_array($current['status'], ['READY_FOR_GATE', 'WAITING_LOADING'], true)) {
            throw new Exception('Only a waiting or ready clearance can be rejected.');
        }
        $update = $pdo->prepare("UPDATE gate_clearances SET status='REJECTED', cancelled_by=?, cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND status IN ('READY_FOR_GATE','WAITING_LOADING')");
        $update->execute([$_SESSION['user_id'], $reason, $id]);
        if ($update->rowCount() !== 1) {
            throw new Exception('Clearance could not be rejected.');
        }
        log_activity('clearance_rejected', 'gate', $id, "Clearance {$current['clearance_number']} rejected: {$reason}");

        // Notify the salesperson who owns the sale (if any) about the rejection
        $spStmt = $pdo->prepare("SELECT salesperson_id FROM sales WHERE id=?");
        $spStmt->execute([$current['sale_id']]);
        $salespersonId = (int) $spStmt->fetchColumn();
        if ($salespersonId && $salespersonId !== (int) $_SESSION['user_id']) {
            $msg = "Gate clearance {$current['clearance_number']} for invoice " . ($current['invoice_no'] ?? '') . " was REJECTED. Reason: {$reason}";
            notify_user($salespersonId, $msg, '/block_plant/gate/index.php?clearance=' . urlencode($current['clearance_number']));
        }

        $pdo->commit();
        set_flash('success', 'Clearance rejected. The vehicle is on hold.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage());
    }
    header('Location: index.php?clearance=' . urlencode($clearance['clearance_number']));
    exit;
}

$pageTitle = 'Reject / Hold Vehicle';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="panel" style="max-width:620px;margin:40px auto;border-top:5px solid #e8a33d;">
    <h1>Reject / hold this clearance?</h1>
    <p>Use this when the vehicle, products, or payment do not match, or the exit should be blocked. A reason is required and the salesperson will be notified.</p>
    <div class="grid-2">
        <p><strong>Clearance</strong><br><?= e($clearance['clearance_number']) ?></p>
        <p><strong>Invoice</strong><br><?= e($clearance['invoice_no']) ?></p>
        <p><strong>Customer</strong><br><?= e($clearance['customer_name'] ?? 'Walk-in') ?></p>
        <p><strong>Vehicle</strong><br><?= e($clearance['vehicle_plate']) ?></p>
        <p><strong>Status</strong><br><?= e(str_replace('_', ' ', $clearance['status'])) ?></p>
        <p><strong>Payment</strong><br><?= e($clearance['payment_status']) ?></p>
    </div>
    <form method="post" style="margin-top:20px;">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label for="reason" style="display:block;font-weight:600;margin-bottom:6px;">Rejection reason</label>
        <textarea id="reason" name="reason" rows="3" required placeholder="e.g. Loaded quantity does not match clearance" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
        <div style="display:flex;gap:10px;margin-top:16px;">
            <button class="btn btn-accent" type="submit" name="confirm_reject" value="1">Reject &amp; hold vehicle</button>
            <a class="btn btn-secondary" href="index.php?clearance=<?= urlencode($clearance['clearance_number']) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
