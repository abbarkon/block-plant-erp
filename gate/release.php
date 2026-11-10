<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'edit');
ensure_gate_schema();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM gate_clearances WHERE id=?");
$stmt->execute([$id]);
$clearance = $stmt->fetch();
if (!$clearance) render_not_found(t('clearance_not_found'), t('clearance_not_found_hint'), '/block_plant/gate/index.php', t('gate'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_release'])) {
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM gate_clearances WHERE id=? FOR UPDATE");
        $lock->execute([$id]);
        $current = $lock->fetch();
        if (!$current || $current['status'] !== 'READY_FOR_GATE' || $current['payment_status'] !== 'PAID') throw new Exception('Clearance is invalid, unpaid, cancelled, or already used.');
        $update = $pdo->prepare("UPDATE gate_clearances SET status='EXITED', exit_time=NOW(), guard_id=? WHERE id=? AND status='READY_FOR_GATE'");
        $update->execute([$_SESSION['user_id'], $id]);
        if ($update->rowCount() !== 1) throw new Exception('Clearance was already used.');
        $pdo->prepare("INSERT INTO gate_exit_logs(clearance_id, clearance_number, guard_id, vehicle_plate, ip_address, user_agent) VALUES(?,?,?,?,?,?)")->execute([$id, $current['clearance_number'], $_SESSION['user_id'], $current['vehicle_plate'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
        log_activity('vehicle_released', 'gate', $id, "Vehicle {$current['vehicle_plate']} exited under {$current['clearance_number']}");

        // Notify the salesperson and admins that the vehicle has exited
        $exitMsg = "Vehicle {$current['vehicle_plate']} exited under clearance {$current['clearance_number']} at " . date('d M Y H:i');
        $exitLink = '/block_plant/gate/index.php?clearance=' . urlencode($current['clearance_number']);
        $spStmt = $pdo->prepare("SELECT salesperson_id FROM sales WHERE id=?");
        $spStmt->execute([$current['sale_id']]);
        $spId = (int) $spStmt->fetchColumn();
        if ($spId && $spId != $_SESSION['user_id']) {
            notify_user($spId, $exitMsg, $exitLink);
        }
        notify_admins($exitMsg, $exitLink, $_SESSION['user_id']);

        $pdo->commit();
        set_flash('success', 'Vehicle released successfully. Clearance is now EXITED.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }
    header('Location: index.php?clearance=' . urlencode($clearance['clearance_number']));
    exit;
}
$pageTitle = 'Confirm Vehicle Exit';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="panel" style="max-width:620px;margin:40px auto;border-top:5px solid #22b8cf;"><h1>Allow vehicle exit?</h1><p>Confirm that this vehicle is leaving the factory.</p><div class="grid-2"><p><strong>Clearance</strong><br><?= e($clearance['clearance_number']) ?></p><p><strong>Vehicle</strong><br><?= e($clearance['vehicle_plate']) ?></p><p><strong>Status</strong><br><?= e($clearance['status']) ?></p><p><strong>Payment</strong><br><?= e($clearance['payment_status']) ?></p></div><form method="post" style="display:flex;gap:10px;margin-top:20px;"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-accent" type="submit" name="confirm_release" value="1">Yes — Release vehicle</button><a class="btn btn-secondary" href="index.php?clearance=<?= urlencode($clearance['clearance_number']) ?>">Cancel</a></form></div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>