<?php
/**
 * Gate clearance register.
 *
 * The complete, filterable history of gate clearances (the guard dashboard only
 * shows the live queue and the last few exits). Includes lifecycle timestamps,
 * dwell time from loading to exit, open-clearance aging, CSV export and print.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('gate', 'view');
ensure_gate_schema();

$statusMeta = [
    'WAITING_LOADING' => ['label' => t('gate_awaiting_loading'), 'badge' => 'badge-warning'],
    'READY_FOR_GATE'  => ['label' => t('gate_ready_at_gate'),    'badge' => 'badge-info'],
    'REJECTED'        => ['label' => t('gate_on_hold'),          'badge' => 'badge-danger'],
    'EXITED'          => ['label' => t('gate_exited'),           'badge' => 'badge-success'],
    'CANCELLED'       => ['label' => t('status_cancelled'),      'badge' => 'badge-muted'],
];

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !isset($statusMeta[$statusFilter])) {
    $statusFilter = '';
}
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'gc.status = ?';
    $params[] = $statusFilter;
}
if ($from !== '') {
    $where[] = 'DATE(COALESCE(gc.exit_time, gc.generated_at)) >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'DATE(COALESCE(gc.exit_time, gc.generated_at)) <= ?';
    $params[] = $to;
}
if ($search !== '') {
    $where[] = '(gc.clearance_number LIKE ? OR gc.vehicle_plate LIKE ? OR s.invoice_no LIKE ? OR c.name LIKE ? OR gc.driver_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT gc.*, s.invoice_no, s.total_amount, s.paid_amount, s.balance,
               c.name AS customer_name, gc_u.name AS guard_name, lb.name AS loaded_by_name
        FROM gate_clearances gc
        JOIN sales s ON s.id = gc.sale_id
        LEFT JOIN customers c ON c.id = gc.customer_id
        LEFT JOIN users gc_u ON gc_u.id = gc.guard_id
        LEFT JOIN users lb ON lb.id = gc.loaded_by
        $whereSql
        ORDER BY COALESCE(gc.exit_time, gc.generated_at) DESC
        LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ------------------------------------------------------------- KPI roll-up */
$kpi = ['total' => 0, 'open' => 0, 'holds' => 0, 'exited' => 0, 'stale' => 0, 'dwellSum' => 0.0, 'dwellCount' => 0];
$staleCutoff = strtotime('-1 day');
foreach ($rows as $row) {
    $kpi['total']++;
    if ($row['status'] === 'WAITING_LOADING' || $row['status'] === 'READY_FOR_GATE') {
        $kpi['open']++;
        if (strtotime($row['generated_at']) < $staleCutoff) {
            $kpi['stale']++;
        }
    } elseif ($row['status'] === 'REJECTED') {
        $kpi['holds']++;
    } elseif ($row['status'] === 'EXITED') {
        $kpi['exited']++;
        $start = $row['loaded_at'] ?: $row['generated_at'];
        if ($row['exit_time'] && $start) {
            $kpi['dwellSum'] += max(0, strtotime($row['exit_time']) - strtotime($start));
            $kpi['dwellCount']++;
        }
    }
}
$avgDwellHours = $kpi['dwellCount'] > 0 ? ($kpi['dwellSum'] / $kpi['dwellCount']) / 3600 : null;

function gate_dwell_label($row) {
    $start = $row['loaded_at'] ?: $row['generated_at'];
    $end = $row['exit_time'];
    if (!$end || !$start) {
        return '—';
    }
    $seconds = max(0, strtotime($end) - strtotime($start));
    if ($seconds < 3600) {
        return round($seconds / 60) . ' min';
    }
    return round($seconds / 3600, 1) . ' h';
}

/* -------------------------------------------------------------- CSV export */
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'gate_register_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Clearance', 'Invoice', 'Customer', 'Vehicle', 'Driver', 'Driver Phone', 'Status', 'Payment Status', 'Payment Amount', 'Balance', 'Opened', 'Loaded At', 'Exited At', 'Printed At', 'Guard', 'Dwell Time', 'Reason']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['clearance_number'],
            $row['invoice_no'],
            $row['customer_name'] ?? 'Walk-in',
            $row['vehicle_plate'],
            $row['driver_name'] ?? '',
            $row['driver_phone'] ?? '',
            $row['status'],
            $row['payment_status'],
            number_format((float) $row['payment_amount'], 2, '.', ''),
            number_format((float) $row['balance'], 2, '.', ''),
            $row['generated_at'],
            $row['loaded_at'] ?? '',
            $row['exit_time'] ?? '',
            $row['printed_at'] ?? '',
            $row['guard_name'] ?? '',
            gate_dwell_label($row),
            $row['cancellation_reason'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$csvQuery = http_build_query([
    'export' => 'csv',
    'status' => $statusFilter,
    'from' => $from,
    'to' => $to,
    'q' => $search,
]);
$pageTitle = t('gate_register');
require_once __DIR__ . '/../partials/header.php';
?>
<style>
    @media print {
        .no-print { display: none !important; }
        .panel { box-shadow: none; border: 1px solid #cbd5e1; }
        .data-table th { background: #f1f5f9 !important; }
    }
</style>

<div class="page-header">
    <div>
        <h1>📋 <?= e(t('gate_register')) ?></h1>
        <p style="color:#64748b; margin:6px 0 0;"><?= e(t('gate_register_hint')) ?></p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary"><?= e(t('gate')) ?> <?= e(t('dashboard')) ?></a>
        <button type="button" onclick="window.print()" class="btn btn-print no-print">🖨️ <?= e(t('print')) ?></button>
        <a href="register.php?<?= e($csvQuery) ?>" class="btn btn-secondary no-print">⬇️ <?= e(t('export_csv')) ?></a>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:15px; align-items:end;">
        <div>
            <label><?= e(t('status')) ?></label>
            <select name="status">
                <option value=""><?= e(t('all_statuses')) ?></option>
                <?php foreach ($statusMeta as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= e(t('date_from')) ?></label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label><?= e(t('date_to')) ?></label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div>
            <label><?= e(t('search')) ?></label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('gate_search_placeholder')) ?>">
        </div>
        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn btn-accent"><?= e(t('filter')) ?></button>
            <a href="register.php" class="btn btn-secondary"><?= e(t('reset')) ?></a>
        </div>
    </div>
</form>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#6366f1;">Σ</div>
        <div><div class="stat-value"><?= number_format($kpi['total']) ?></div><div class="stat-label"><?= e(t('gate_total_clearances')) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#d9742f;">🚚</div>
        <div><div class="stat-value"><?= number_format($kpi['exited']) ?></div><div class="stat-label"><?= e(t('gate_exited')) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f59e0b;">⏳</div>
        <div><div class="stat-value"><?= number_format($kpi['open']) ?></div><div class="stat-label"><?= e(t('gate_open_clearances')) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?= $kpi['stale'] > 0 ? '#ef4444' : '#94a3b8' ?>;">⌛</div>
        <div><div class="stat-value"><?= number_format($kpi['stale']) ?></div><div class="stat-label"><?= e(t('gate_open_over_24h')) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#b91c1c;">⛔</div>
        <div><div class="stat-value"><?= number_format($kpi['holds']) ?></div><div class="stat-label"><?= e(t('gate_on_hold')) ?></div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0ea5e9;">⏱</div>
        <div><div class="stat-value"><?= $avgDwellHours === null ? '—' : number_format($avgDwellHours, 1) . ' h' ?></div><div class="stat-label"><?= e(t('gate_avg_dwell')) ?></div></div>
    </div>
</div>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <h3 style="margin:0;"><?= e(t('gate_register')) ?></h3>
        <span style="color:#64748b; font-size:0.85rem;"><?= number_format(count($rows)) ?> <?= e(t('records')) ?></span>
    </div>
    <?php if (!$rows): ?>
    <p style="margin:0; color:#64748b;"><?= e(t('gate_no_records')) ?></p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e(t('clearance')) ?></th>
                    <th><?= e(t('invoice')) ?></th>
                    <th><?= e(t('customer')) ?></th>
                    <th><?= e(t('vehicle_plate_number')) ?></th>
                    <th><?= e(t('status')) ?></th>
                    <th><?= e(t('payment_status')) ?></th>
                    <th><?= e(t('gate_opened')) ?></th>
                    <th><?= e(t('gate_exited')) ?></th>
                    <th><?= e(t('gate_dwell_time')) ?></th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $meta = $statusMeta[$row['status']] ?? ['label' => str_replace('_', ' ', $row['status']), 'badge' => 'badge-muted']; ?>
                <tr>
                    <td><strong><?= e($row['clearance_number']) ?></strong><?php if (!empty($row['cancellation_reason'])): ?><div style="color:#b91c1c; font-size:0.78rem;"><?= e($row['cancellation_reason']) ?></div><?php endif; ?></td>
                    <td><?= e($row['invoice_no']) ?></td>
                    <td><?= e($row['customer_name'] ?? 'Walk-in') ?></td>
                    <td>
                        <strong><?= e($row['vehicle_plate']) ?></strong>
                        <div style="color:#64748b; font-size:0.78rem;"><?= e($row['driver_name'] ?: '—') ?></div>
                    </td>
                    <td><span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span></td>
                    <td>
                        <?= e($row['payment_status']) ?>
                        <div style="color:#64748b; font-size:0.78rem;"><?= e(currency_symbol()) . money($row['payment_amount']) ?></div>
                    </td>
                    <td><?= e(format_datetime($row['generated_at'])) ?><?php if (!empty($row['printed_at'])): ?><div style="color:#64748b; font-size:0.78rem;"><?= e(t('printed')) ?> <?= e(format_datetime($row['printed_at'])) ?></div><?php endif; ?></td>
                    <td><?= $row['exit_time'] ? e(format_datetime($row['exit_time'])) : '—' ?></td>
                    <td><?= e(gate_dwell_label($row)) ?></td>
                    <td class="no-print" style="white-space:nowrap;">
                        <a href="index.php?clearance=<?= urlencode($row['clearance_number']) ?>"><?= e(t('view')) ?></a>
                        &middot;
                        <a href="clearance_print.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener"><?= e(t('print')) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="margin:12px 0 0; color:#64748b; font-size:0.8rem;"><?= e(t('gate_dwell_hint')) ?></p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
