<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$pageTitle = 'Asset Alerts';

$assets = $pdo->query("SELECT a.id, a.asset_code, a.name, a.condition_status, a.current_value, a.status,
        am.next_maintenance_date, am.maintenance_date AS last_maintenance,
        am.completed_at AS last_completed
    FROM assets a
    LEFT JOIN asset_maintenance am ON am.id = (
        SELECT am2.id FROM asset_maintenance am2
        WHERE am2.asset_id = a.id
        ORDER BY am2.maintenance_date DESC, am2.id DESC LIMIT 1
    )
    WHERE a.status = 1
    ORDER BY a.name")->fetchAll();

$today = new DateTime(date('Y-m-d'));
$overdue = [];
$dueSoon = [];
$unscheduled = [];
$conditionAlarms = [];
$pendingRequests = [];

foreach ($assets as $asset) {
    if (in_array($asset['condition_status'], ['poor', 'damaged', 'critical'], true)) {
        $conditionAlarms[] = $asset;
    }
    if (empty($asset['next_maintenance_date'])) {
        $unscheduled[] = $asset;
        continue;
    }
    $next = new DateTime($asset['next_maintenance_date']);
    $days = (int) $today->diff($next)->format('%r%a');
    $asset['days_left'] = $days;
    if ($days < 0) {
        $overdue[] = $asset;
    } elseif ($days <= 30) {
        $dueSoon[] = $asset;
    }
}

try {
    $pendingRequests = $pdo->query("SELECT am.id, am.asset_id, am.maintenance_date, am.maintenance_type, am.description, a.name AS asset_name, a.asset_code
        FROM asset_maintenance am
        LEFT JOIN assets a ON a.id = am.asset_id
        WHERE am.status = 'pending'
        ORDER BY am.maintenance_date ASC")->fetchAll();
} catch (Throwable $e) {
    $pendingRequests = [];
}

usort($overdue, static fn($a, $b) => $a['days_left'] <=> $b['days_left']);
usort($dueSoon, static fn($a, $b) => $a['days_left'] <=> $b['days_left']);

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .alert-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .alert-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .alert-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .alert-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .alert-kpi strong { display:block; margin-top:4px; color:#1f2933; font-size:1.3rem; }
    .alert-kpi.danger { border-left-color:#b91c1c; }
    .alert-kpi.warn { border-left-color:#b45309; }
    .alert-section { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:20px; margin-bottom:20px; }
    .alert-section h3 { margin:0 0 12px; color:#1f2933; display:flex; align-items:center; gap:8px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .alert-kpi, .alert-section { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🔔 Asset Alerts</h1>
        <p style="color:#64748b;margin:6px 0 0;">Overdue servicing, upcoming maintenance, and condition alarms.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="index.php">Back to assets</a>
        <a class="btn btn-secondary" href="maintenance.php">Maintenance log</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="alert-kpis">
    <div class="alert-kpi danger"><span>Overdue maintenance</span><strong><?= number_format(count($overdue)) ?></strong></div>
    <div class="alert-kpi warn"><span>Due within 30 days</span><strong><?= number_format(count($dueSoon)) ?></strong></div>
    <div class="alert-kpi warn"><span>Pending requests</span><strong><?= number_format(count($pendingRequests)) ?></strong></div>
    <div class="alert-kpi danger"><span>Condition alarms</span><strong><?= number_format(count($conditionAlarms)) ?></strong></div>
</div>

<div class="alert-section">
    <h3>⛔ Overdue maintenance</h3>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Condition</th><th>Last maintenance</th><th>Next due</th><th>Days overdue</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php if (!$overdue): ?><tr><td colspan="6">No overdue maintenance.</td></tr><?php endif; ?>
            <?php foreach ($overdue as $item): ?>
            <tr>
                <td><strong><?= e($item['name']) ?></strong><div style="font-size:.75rem;color:#64748b;"><?= e($item['asset_code']) ?></div></td>
                <td><?= e(ucfirst($item['condition_status'])) ?></td>
                <td><?= $item['last_maintenance'] ? e(format_date($item['last_maintenance'])) : '—' ?></td>
                <td><?= e(format_date($item['next_maintenance_date'])) ?></td>
                <td><span class="badge badge-danger"><?= abs($item['days_left']) ?> days</span></td>
                <td class="no-print"><a class="btn btn-sm btn-accent" href="maintenance.php?asset_id=<?= (int) $item['id'] ?>">Record maintenance</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="alert-section">
    <h3>📅 Due within 30 days</h3>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Condition</th><th>Last maintenance</th><th>Next due</th><th>Days left</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php if (!$dueSoon): ?><tr><td colspan="6">No maintenance due in the next 30 days.</td></tr><?php endif; ?>
            <?php foreach ($dueSoon as $item): ?>
            <tr>
                <td><strong><?= e($item['name']) ?></strong><div style="font-size:.75rem;color:#64748b;"><?= e($item['asset_code']) ?></div></td>
                <td><?= e(ucfirst($item['condition_status'])) ?></td>
                <td><?= $item['last_maintenance'] ? e(format_date($item['last_maintenance'])) : '—' ?></td>
                <td><?= e(format_date($item['next_maintenance_date'])) ?></td>
                <td><span class="badge <?= $item['days_left'] <= 7 ? 'badge-warning' : 'badge-info' ?>"><?= (int) $item['days_left'] === 0 ? 'Today' : $item['days_left'] . ' days' ?></span></td>
                <td class="no-print"><a class="btn btn-sm btn-secondary" href="maintenance.php?asset_id=<?= (int) $item['id'] ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="alert-section">
    <h3>🛠️ Pending maintenance requests</h3>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Requested</th><th>Type</th><th>Description</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php if (!$pendingRequests): ?><tr><td colspan="5">No pending maintenance requests.</td></tr><?php endif; ?>
            <?php foreach ($pendingRequests as $item): ?>
            <tr>
                <td><strong><?= e($item['asset_name'] ?: 'N/A') ?></strong><?php if ($item['asset_code']): ?><div style="font-size:.75rem;color:#64748b;"><?= e($item['asset_code']) ?></div><?php endif; ?></td>
                <td><?= e(format_date($item['maintenance_date'])) ?></td>
                <td><?= e($item['maintenance_type']) ?></td>
                <td><?= e($item['description']) ?></td>
                <td class="no-print"><a class="btn btn-sm btn-secondary" href="maintenance.php?asset_id=<?= (int) $item['asset_id'] ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="alert-section">
    <h3>⚠️ Condition alarms</h3>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Code</th><th>Condition</th><th style="text-align:right;">Recorded value</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php if (!$conditionAlarms): ?><tr><td colspan="5">No poor, damaged, or critical asset conditions reported.</td></tr><?php endif; ?>
            <?php foreach ($conditionAlarms as $item): ?>
            <tr>
                <td><strong><?= e($item['name']) ?></strong></td>
                <td><?= e($item['asset_code']) ?></td>
                <td><span class="badge badge-danger"><?= e(ucfirst($item['condition_status'])) ?></span></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($item['current_value']) ?></td>
                <td class="no-print"><a class="btn btn-sm btn-secondary" href="maintenance.php?asset_id=<?= (int) $item['id'] ?>">Review</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="alert-section">
    <h3>🗓️ Active assets without a scheduled next maintenance date</h3>
    <table class="data-table">
        <thead><tr><th>Asset</th><th>Code</th><th>Condition</th><th>Last maintenance</th><th class="no-print">Action</th></tr></thead>
        <tbody>
            <?php if (!$unscheduled): ?><tr><td colspan="5">Every active asset has a next maintenance date.</td></tr><?php endif; ?>
            <?php foreach ($unscheduled as $item): ?>
            <tr>
                <td><strong><?= e($item['name']) ?></strong></td>
                <td><?= e($item['asset_code']) ?></td>
                <td><?= e(ucfirst($item['condition_status'])) ?></td>
                <td><?= $item['last_maintenance'] ? e(format_date($item['last_maintenance'])) : 'Never' ?></td>
                <td class="no-print"><a class="btn btn-sm btn-secondary" href="maintenance.php?asset_id=<?= (int) $item['id'] ?>">Schedule</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
