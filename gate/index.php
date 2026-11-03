<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'view');
ensure_gate_schema();

$number = trim($_GET['clearance'] ?? '');
$token = trim($_GET['token'] ?? '');
$clearance = null;
$message = null;

if ($number !== '' || $token !== '') {
    if ($token !== '') {
        $sql = "SELECT gc.*, s.invoice_no, s.total_amount, s.paid_amount, s.payment_reference, c.name AS customer_name, c.phone AS customer_phone 
                FROM gate_clearances gc 
                JOIN sales s ON s.id = gc.sale_id 
                LEFT JOIN customers c ON c.id = gc.customer_id 
                WHERE gc.qr_token = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$token]);
    } else {
        $sql = "SELECT gc.*, s.invoice_no, s.total_amount, s.paid_amount, s.payment_reference, c.name AS customer_name, c.phone AS customer_phone 
                FROM gate_clearances gc 
                JOIN sales s ON s.id = gc.sale_id 
                LEFT JOIN customers c ON c.id = gc.customer_id 
                WHERE gc.clearance_number = ? OR gc.vehicle_plate = ? 
                ORDER BY gc.id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$number, $number]);
    }
    
    $clearance = $stmt->fetch();
    if ($clearance) {
        $itemStmt = $pdo->prepare("SELECT si.quantity, bt.name, bt.size FROM sale_items si JOIN block_types bt ON bt.id = si.block_type_id WHERE si.sale_id = ?");
        $itemStmt->execute([$clearance['sale_id']]);
        $clearance['items'] = $itemStmt->fetchAll();
    } else {
        $message = 'INVALID CLEARANCE OR PLATE NUMBER';
    }
}
$waitingLoading = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status='WAITING_LOADING' AND payment_status='PAID'")->fetchColumn();
$readyAtGate = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status='READY_FOR_GATE' AND payment_status='PAID'")->fetchColumn();
$activeHolds = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status='REJECTED'")->fetchColumn();
$todayExited = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status='EXITED' AND DATE(exit_time)=CURDATE()")->fetchColumn();
$queueStmt = $pdo->query("SELECT gc.id, gc.clearance_number, gc.vehicle_plate, gc.driver_name, gc.payment_status, gc.status, gc.generated_at, gc.cancellation_reason, s.invoice_no, c.name AS customer_name, (SELECT GROUP_CONCAT(CONCAT(bt.name, ' ', bt.size, ' x ', si.quantity) SEPARATOR ', ') FROM sale_items si JOIN block_types bt ON bt.id = si.block_type_id WHERE si.sale_id = s.id) AS products FROM gate_clearances gc JOIN sales s ON s.id = gc.sale_id LEFT JOIN customers c ON c.id = gc.customer_id WHERE gc.status IN ('WAITING_LOADING', 'READY_FOR_GATE', 'REJECTED') ORDER BY CASE gc.status WHEN 'READY_FOR_GATE' THEN 1 WHEN 'WAITING_LOADING' THEN 2 ELSE 3 END, gc.generated_at ASC LIMIT 50");
$gateQueue = $queueStmt->fetchAll();
$activeQueueCount = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status IN ('WAITING_LOADING', 'READY_FOR_GATE', 'REJECTED')")->fetchColumn();
$staleOpenClearances = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status IN ('WAITING_LOADING', 'READY_FOR_GATE') AND generated_at < (NOW() - INTERVAL 1 DAY)")->fetchColumn();
$avgDwellMinutes30d = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, COALESCE(loaded_at, generated_at), exit_time)) FROM gate_clearances WHERE status = 'EXITED' AND exit_time IS NOT NULL AND exit_time >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
$avgDwellLabel30d = ($avgDwellMinutes30d === null || $avgDwellMinutes30d === false)
    ? '—'
    : (((float) $avgDwellMinutes30d < 60) ? round((float) $avgDwellMinutes30d) . ' min' : round((float) $avgDwellMinutes30d / 60, 1) . ' h');
$flash = get_flash();
$pageTitle = 'Gate Guard Dashboard';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="gate-hero"><div><span class="module-eyebrow">SECURITY GATE</span><h1>Gate Guard Dashboard</h1><p>Verify paid clearances before releasing vehicles.</p></div><div class="header-actions"><span class="gate-shield">✓ Secure verification</span><a href="register.php" class="btn btn-secondary"><?= e(t('gate_register')) ?></a><a href="loading.php" class="btn btn-secondary">Loading confirmation</a></div></div>
<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<div class="gate-kpis">
    <div class="gate-kpi waiting"><div class="stat-icon" style="background:#e76f51;">📦</div><div><div class="stat-value"><?= number_format($waitingLoading) ?></div><div class="stat-label">Waiting for loading</div></div></div>
    <div class="gate-kpi ready"><div class="stat-icon" style="background:#2a9d8f;">🚚</div><div><div class="stat-value"><?= number_format($readyAtGate) ?></div><div class="stat-label">Ready for gate release</div></div></div>
    <div class="gate-kpi rejected"><div class="stat-icon" style="background:#b91c1c;">⛔</div><div><div class="stat-value"><?= number_format($activeHolds) ?></div><div class="stat-label">Active holds</div></div></div>
    <div class="gate-kpi exited"><div class="stat-icon" style="background:#2a9d8f;">✓</div><div><div class="stat-value"><?= number_format($todayExited) ?></div><div class="stat-label">Exited today</div></div></div>
    <div class="gate-kpi <?= $staleOpenClearances > 0 ? 'rejected' : 'exited' ?>"><div class="stat-icon" style="background:<?= $staleOpenClearances > 0 ? '#b91c1c' : '#94a3b8' ?>;">⌛</div><div><div class="stat-value"><?= number_format($staleOpenClearances) ?></div><div class="stat-label"><?= e(t('gate_open_over_24h')) ?></div></div></div>
    <div class="gate-kpi exited"><div class="stat-icon" style="background:#0ea5e9;">⏱</div><div><div class="stat-value"><?= e($avgDwellLabel30d) ?></div><div class="stat-label"><?= e(t('gate_avg_dwell_30d')) ?></div></div></div>
</div>
<div class="panel gate-queue">
    <div class="gate-queue-head"><div><h2>Live gate queue</h2><p>Clearances requiring loading confirmation, release, or hold review.</p></div><span class="gate-queue-count"><?= number_format($activeQueueCount) ?> open</span></div>
    <div class="table-container">
        <table class="data-table">
            <thead><tr><th>Status</th><th>Clearance / Invoice</th><th>Vehicle / Driver</th><th>Customer / Products</th><th>Payment</th><th>Waiting since</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$gateQueue): ?><tr><td colspan="7">No clearances need gate attention.</td></tr><?php endif; ?>
            <?php foreach ($gateQueue as $queueItem): ?>
                <tr>
                    <td><span class="gate-status <?= strtolower($queueItem['status']) ?>"><?= e(str_replace('_', ' ', $queueItem['status'])) ?></span><?php if ($queueItem['status'] === 'REJECTED' && $queueItem['cancellation_reason']): ?><small class="gate-reason"><?= e($queueItem['cancellation_reason']) ?></small><?php endif; ?></td>
                    <td><strong><?= e($queueItem['clearance_number']) ?></strong><small class="gate-subline">Invoice <?= e($queueItem['invoice_no']) ?></small></td>
                    <td><strong><?= e($queueItem['vehicle_plate']) ?></strong><small class="gate-subline"><?= e($queueItem['driver_name'] ?: 'Driver not recorded') ?></small></td>
                    <td><?= e($queueItem['customer_name'] ?: 'Walk-in') ?><small class="gate-subline"><?= e($queueItem['products'] ?: 'No product details') ?></small></td>
                    <td><span class="gate-payment <?= $queueItem['payment_status'] === 'PAID' ? 'paid' : 'unpaid' ?>"><?= e($queueItem['payment_status']) ?></span></td>
                    <td><?= e(format_datetime($queueItem['generated_at'])) ?></td>
                    <td class="gate-actions">
                        <?php if ($queueItem['status'] === 'WAITING_LOADING'): ?>
                            <?php if ($queueItem['payment_status'] !== 'PAID'): ?><span class="gate-action-blocked">Payment required</span><?php elseif (has_permission('gate', 'edit')): ?><a class="btn-sm" href="loading.php?id=<?= (int) $queueItem['id'] ?>">Verify loading</a><?php else: ?><a class="btn-sm" href="?clearance=<?= urlencode($queueItem['clearance_number']) ?>">View</a><?php endif; ?>
                        <?php elseif ($queueItem['status'] === 'READY_FOR_GATE'): ?>
                            <?php if ($queueItem['payment_status'] !== 'PAID'): ?><span class="gate-action-blocked">Payment required</span><?php elseif (has_permission('gate', 'edit')): ?><a class="btn-sm gate-release-link" href="release.php?id=<?= (int) $queueItem['id'] ?>">Release vehicle</a><a class="btn-sm" href="reject.php?id=<?= (int) $queueItem['id'] ?>">Hold</a><?php else: ?><a class="btn-sm" href="?clearance=<?= urlencode($queueItem['clearance_number']) ?>">View</a><?php endif; ?>
                        <?php else: ?>
                            <?php if (has_permission('gate', 'edit')): ?><a class="btn-sm" href="reinstate.php?id=<?= (int) $queueItem['id'] ?>">Review hold</a><?php else: ?><a class="btn-sm" href="?clearance=<?= urlencode($queueItem['clearance_number']) ?>">View</a><?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="panel" style="max-width:720px;margin:20px auto;">
    <h2>Verify clearance</h2>
    <form method="get" class="filter-form" style="display:flex;gap:10px;">
        <input type="text" name="clearance" style="flex:1;" placeholder="Enter clearance number or Vehicle Plate (e.g. GC-2026-000125, AA-1234)" value="<?= e($number) ?>" required>
        <button class="btn btn-accent" type="submit">Verify</button>
        <button type="button" class="btn btn-secondary" onclick="toggleScanner()" style="display:flex;align-items:center;gap:6px;">📷 Scan QR</button>
    </form>
    <p style="color:#667a80;font-size:.85rem;margin-top:8px;">QR scans should open this page with the secure token, or use the camera to scan from a printed receipt.</p>
    
    <div id="qr-scanner-container" style="display:none; margin-top:20px; text-align:center;">
        <div id="qr-reader" style="width:100%; max-width:500px; margin:0 auto; border-radius:8px; overflow:hidden; border:2px solid #2a9d8f;"></div>
        <button type="button" class="btn" style="margin-top:10px;" onclick="stopScanner()">Cancel Scan</button>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
    let html5QrcodeScanner = null;
    function toggleScanner() {
        const container = document.getElementById('qr-scanner-container');
        if (container.style.display === 'none') {
            container.style.display = 'block';
            html5QrcodeScanner = new Html5QrcodeScanner("qr-reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
            html5QrcodeScanner.render((decodedText, decodedResult) => {
                // If it's a URL, extract the token/clearance or redirect
                if (decodedText.includes('token=')) {
                    window.location.href = decodedText;
                } else if (decodedText.includes('clearance=')) {
                    window.location.href = decodedText;
                } else {
                    // Try treating the raw text as the token
                    window.location.href = "?token=" + encodeURIComponent(decodedText);
                }
                stopScanner();
            }, (error) => { /* ignore */ });
        } else {
            stopScanner();
        }
    }
    function stopScanner() {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.clear();
            html5QrcodeScanner = null;
        }
        document.getElementById('qr-scanner-container').style.display = 'none';
    }
</script>

<?php if ($message): ?><div class="panel" style="border-left:5px solid #e76f51;color:#991b1b;"><h2>✕ <?= e($message) ?></h2><p>This clearance does not exist or the QR token is invalid.</p></div><?php endif; ?>
<?php if ($clearance): ?>
<?php $clearanceItems = $clearance['items'] ?? []; ?>
<?php $isReady = $clearance['status'] === 'READY_FOR_GATE' && $clearance['payment_status'] === 'PAID'; ?>
<?php $isRejected = $clearance['status'] === 'REJECTED'; ?>
<?php $borderColor = $isReady ? '#2a9d8f' : ($isRejected ? '#b91c1c' : '#e76f51'); ?>
<div class="panel" style="max-width:820px;margin:20px auto;border-top:5px solid <?= $borderColor ?>;"><h2><?= $isReady ? '✓ VALID CLEARANCE' : ($isRejected ? '⛔ REJECTED / ON HOLD' : '⚠ ' . e(str_replace('_', ' ', $clearance['status']))) ?></h2><?php if ($isRejected && !empty($clearance['cancellation_reason'])): ?><p style="color:#b91c1c;font-weight:600;margin:4px 0 12px;">Reason: <?= e($clearance['cancellation_reason']) ?></p><?php endif; ?><div class="grid-2"><p><strong>Clearance:</strong><br><?= e($clearance['clearance_number']) ?></p><p><strong>Invoice:</strong><br><?= e($clearance['invoice_no']) ?></p><p><strong>Customer:</strong><br><?= e($clearance['customer_name'] ?? 'Walk-in') ?></p><p><strong>Vehicle:</strong><br><?= e($clearance['vehicle_plate']) ?></p><p><strong>Driver:</strong><br><?= e($clearance['driver_name'] ?? '—') ?> <?= e($clearance['driver_phone'] ?? '') ?></p><p><strong>Payment:</strong><br><?= e($clearance['payment_status']) ?> · <?= e(currency_symbol()) . money($clearance['paid_amount']) ?></p></div><div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;"><a class="btn btn-secondary" target="_blank" href="clearance_print.php?id=<?= (int) $clearance['id'] ?>">Print clearance</a><?php if ($isReady): ?><a class="btn btn-accent" href="release.php?id=<?= (int) $clearance['id'] ?>">Allow vehicle exit</a><a class="btn" style="background:#b91c1c;color:#fff;" href="reject.php?id=<?= (int) $clearance['id'] ?>">Reject / hold</a><?php elseif ($isRejected): ?><a class="btn" style="background:#2a9d8f;color:#fff;" href="reinstate.php?id=<?= (int) $clearance['id'] ?>">Reinstate clearance</a><?php endif; ?></div></div>
<div class="panel" style="max-width:820px;margin:20px auto;"><h3>Products</h3><ul><?php foreach ($clearanceItems as $item): ?><li><?= e($item['name'] . ' ' . $item['size']) ?> — <?= number_format((int) $item['quantity']) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php
// Fetch recent exits
$recentExitsStmt = $pdo->query("SELECT gc.clearance_number, gc.vehicle_plate, gc.exit_time, gc.driver_name FROM gate_clearances gc WHERE gc.status = 'EXITED' ORDER BY gc.exit_time DESC LIMIT 10");
$recentExits = $recentExitsStmt->fetchAll();
?>
<?php if (!$clearance): ?>
<div class="panel" style="max-width:720px;margin:30px auto;">
    <h3 style="margin-top:0;display:flex;align-items:center;gap:8px;">📋 Recent Gate Exits</h3>
    <table class="data-table">
        <thead><tr><th>Time</th><th>Vehicle Plate</th><th>Driver</th><th>Clearance</th></tr></thead>
        <tbody>
            <?php if (!$recentExits): ?><tr><td colspan="4">No vehicles have exited recently.</td></tr><?php endif; ?>
            <?php foreach ($recentExits as $re): ?>
            <tr>
                <td style="color:#64748b;font-size:0.9rem;"><?= format_datetime($re['exit_time']) ?></td>
                <td><strong style="background:#f1f5f9;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;"><?= e($re['vehicle_plate']) ?></strong></td>
                <td><?= e($re['driver_name'] ?: '—') ?></td>
                <td><a href="?clearance=<?= urlencode($re['clearance_number']) ?>" style="color:#4f46e5;text-decoration:none;"><?= e($re['clearance_number']) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<style>
.gate-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:30px 32px;border-radius:18px;background:linear-gradient(120deg,#183b56,#19323c 58%,#246a73);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.18)}
.gate-hero h1{margin:4px 0;color:#fff;font-size:2.1rem}.gate-hero p{margin:0;color:rgba(255,255,255,.72)}.gate-hero .header-actions{justify-content:flex-end}.gate-hero .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);box-shadow:none}.gate-shield{padding:8px 12px;border:1px solid rgba(255,255,255,.25);border-radius:999px;color:#c8f0df;font-size:.78rem;font-weight:700}
.gate-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:20px}.gate-kpi{display:flex;align-items:center;gap:14px;padding:20px;background:#fff;border:1px solid #dce7e5;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07)}.gate-kpi.waiting{border-top:4px solid #e76f51}.gate-kpi.ready{border-top:4px solid #2a9d8f}.gate-kpi.exited{border-top:4px solid #2a9d8f}.gate-kpi.rejected{border-top:4px solid #b91c1c}.gate-kpi .stat-icon{width:48px;height:48px;border-radius:14px;display:grid;place-items:center}.gate-kpi .stat-value{font-size:1.65rem;color:#183b56}.gate-kpi .stat-label{color:#667a80}
.gate-queue{margin-bottom:20px;border-top:4px solid #246a73}.gate-queue-head{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.gate-queue-head h2{margin:0}.gate-queue-head p{margin:4px 0 0;color:#667a80}.gate-queue-count{padding:6px 10px;background:#eaf5f1;color:#246a73;border-radius:4px;font-weight:700;white-space:nowrap}.gate-status,.gate-payment{display:inline-block;padding:5px 8px;border-radius:4px;font-size:.72rem;font-weight:700;white-space:nowrap}.gate-status.ready_for_gate,.gate-payment.paid{background:#e5f5eb;color:#17643a}.gate-status.waiting_loading{background:#fff3d6;color:#845500}.gate-status.rejected,.gate-payment.unpaid{background:#fce8e8;color:#9f2525}.gate-subline,.gate-reason{display:block;margin-top:4px;color:#64748b;font-size:.78rem;max-width:320px;overflow-wrap:anywhere}.gate-reason{color:#9f2525}.gate-actions{min-width:150px;white-space:nowrap}.gate-release-link{background:#2a9d8f;color:#fff}.gate-action-blocked{color:#9f2525;font-size:.8rem;font-weight:700}
.gate-hero + .flash,.gate-kpis + .panel{border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07)}
.gate-hero + .flash{border-left:5px solid #2a9d8f}
.gate-kpis + .panel{max-width:900px!important;margin:20px auto!important;padding:24px;border-top:4px solid #e76f51}
.gate-kpis + .panel h2{margin-top:0}.gate-kpis + .panel .filter-form{display:flex;gap:10px}.gate-kpis + .panel .filter-form input{margin:0}
@media(max-width:900px){.gate-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.gate-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.gate-hero h1{font-size:1.75rem}.gate-hero .header-actions{justify-content:flex-start}.gate-kpis + .panel .filter-form{flex-direction:column}.gate-queue-head{align-items:flex-start;flex-direction:column}.gate-actions{min-width:0}.gate-actions .btn-sm{display:inline-block;margin:2px 0}}
</style>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>