<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('reports', 'view');
ensure_gate_schema();
$from = trim((string) ($_GET['from'] ?? date('Y-m-d')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!is_valid_date_string($from)) {
    $from = date('Y-m-d');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$stmt = $pdo->prepare("SELECT gc.*, s.invoice_no, c.name AS customer_name, u.name AS guard_name FROM gate_clearances gc JOIN sales s ON s.id=gc.sale_id LEFT JOIN customers c ON c.id=gc.customer_id LEFT JOIN users u ON u.id=gc.guard_id WHERE DATE(COALESCE(gc.exit_time, gc.generated_at)) BETWEEN ? AND ? ORDER BY COALESCE(gc.exit_time, gc.generated_at) DESC");
$stmt->execute([$from, $to]);
$rows = $stmt->fetchAll();

// Summary KPIs for the selected period
$exitedCount = 0; $waitingCount = 0; $readyCount = 0; $rejectedCount = 0;
foreach ($rows as $r) {
    switch ($r['status']) {
        case 'EXITED': $exitedCount++; break;
        case 'WAITING_LOADING': $waitingCount++; break;
        case 'READY_FOR_GATE': $readyCount++; break;
        case 'REJECTED': $rejectedCount++; break;
    }
}
$pageTitle = 'Gate Exit Report';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="page-header"><div><h1>Gate Exit Report</h1><p style="color:#667a80;">Traceable clearance and vehicle movement history.</p></div><div class="header-actions"><a href="/block_plant/gate/register.php" class="btn btn-secondary no-print"><?= e(t('gate_register')) ?></a><a href="/block_plant/reports/export_csv.php?type=gate&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-secondary no-print">⬇️ <?= e(t('export_csv')) ?></a><button class="btn btn-accent no-print" onclick="window.print()">Print report</button></div></div>
<div class="filter-section no-print"><form method="get" class="filter-form"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn btn-primary">Filter</button></form></div>
<div class="gate-report-kpis no-print">
    <div class="grk exited"><div class="grk-value"><?= $exitedCount ?></div><div class="grk-label">Exited</div></div>
    <div class="grk ready"><div class="grk-value"><?= $readyCount ?></div><div class="grk-label">Ready at gate</div></div>
    <div class="grk waiting"><div class="grk-value"><?= $waitingCount ?></div><div class="grk-label">Waiting loading</div></div>
    <div class="grk rejected"><div class="grk-value"><?= $rejectedCount ?></div><div class="grk-label">Rejected / hold</div></div>
</div>
<div class="table-container"><table class="data-table"><thead><tr><th>Clearance</th><th>Invoice</th><th>Customer</th><th>Vehicle</th><th>Driver</th><th>Status</th><th>Exit time</th><th>Guard</th><th>Reason</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="9">No gate records for this period.</td></tr><?php endif; ?><?php foreach($rows as $row): ?><?php $badge = $row['status'] === 'EXITED' ? 'badge-success' : (in_array($row['status'], ['CANCELLED','REJECTED'], true) ? 'badge-danger' : 'badge-warning'); ?><tr><td><?= e($row['clearance_number']) ?></td><td><?= e($row['invoice_no']) ?></td><td><?= e($row['customer_name'] ?? 'Walk-in') ?></td><td><?= e($row['vehicle_plate']) ?></td><td><?= e($row['driver_name'] ?? '—') ?></td><td><span class="badge <?= $badge ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><?= $row['exit_time'] ? e(format_datetime($row['exit_time'])) : '—' ?></td><td><?= e($row['guard_name'] ?? '—') ?></td><td><?= e($row['cancellation_reason'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
<style>
.gate-report-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}
.grk{background:#fff;border:1px solid #dce7e5;border-radius:12px;padding:16px;text-align:center;box-shadow:0 6px 18px rgba(24,59,86,.06)}
.grk-value{font-size:1.7rem;font-weight:700;color:#183b56}.grk-label{color:#667a80;font-size:.82rem}
.grk.exited{border-top:4px solid #2a9d8f}.grk.ready{border-top:4px solid #4f46e5}.grk.waiting{border-top:4px solid #e76f51}.grk.rejected{border-top:4px solid #b91c1c}
@media(max-width:700px){.gate-report-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>