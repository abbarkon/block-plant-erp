<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('production', 'view');

$days = max(7, min(365, (int) ($_GET['days'] ?? 60)));
$since = date('Y-m-d', strtotime("-{$days} days"));

$cementId = 0;
$cementUnit = '';
try {
    $c = $pdo->query("SELECT id, unit FROM raw_materials WHERE status=1 AND name LIKE '%cement%' ORDER BY id LIMIT 1")->fetch();
    if ($c) { $cementId = (int) $c['id']; $cementUnit = $c['unit'] ?? ''; }
} catch (PDOException $e) {}

$bomPerBlock = [];
try {
    if ($cementId) {
        $st = $pdo->prepare("SELECT block_type_id, quantity_per_block FROM block_bom WHERE material_id=?");
        $st->execute([$cementId]);
        foreach ($st->fetchAll() as $b) { $bomPerBlock[(int) $b['block_type_id']] = (float) $b['quantity_per_block']; }
    }
} catch (PDOException $e) {}

$runs = [];
try {
    $st = $pdo->prepare("SELECT p.id, p.production_no, p.production_date, p.block_type_id, p.produced_quantity, p.rejected_quantity, p.good_quantity,
            bt.name, bt.size,
            (SELECT COALESCE(SUM(pm.quantity),0) FROM production_materials pm WHERE pm.production_id=p.id AND pm.material_id=?) AS cement_used
        FROM production p JOIN block_types bt ON bt.id=p.block_type_id
        WHERE p.production_date >= ? ORDER BY p.production_date DESC, p.id DESC");
    $st->execute([$cementId, $since]);
    $runs = $st->fetchAll();
} catch (PDOException $e) {}

$totProduced = $totRejected = 0; $totCement = 0.0; $totGoodWithCement = 0; $flagged = 0;
foreach ($runs as $r) {
    $totProduced += (int) $r['produced_quantity'];
    $totRejected += (int) $r['rejected_quantity'];
    if ((float) $r['cement_used'] > 0) { $totCement += (float) $r['cement_used']; $totGoodWithCement += (int) $r['good_quantity']; }
}
$rejectRate = $totProduced > 0 ? $totRejected / $totProduced * 100 : 0;
$avgYield = $totCement > 0 ? $totGoodWithCement / $totCement : 0;

$pageTitle = 'Production Efficiency';
require_once "../partials/header.php";
?>
<div class="page-header">
    <h1>Production Efficiency</h1>
    <form method="get" class="no-print" style="display:flex;gap:8px;align-items:center;">
        <select name="days" onchange="this.form.submit()">
            <?php foreach ([30 => '30 days', 60 => '60 days', 90 => '90 days', 365 => '1 year'] as $d => $label): ?>
            <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<p style="color:#62767e;margin:-6px 0 16px;">Compares the blocks each run actually made per unit of cement with what the BOM recipe expects. A big shortfall means waste, a wrong recipe or an unrecorded loss.</p>
<?php if (!$cementId): ?>
<div class="panel">No raw material with "cement" in its name was found, so cement yield can't be calculated.</div>
<?php endif; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;">
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Runs</div><div style="font-size:1.5rem;font-weight:800;"><?= count($runs) ?></div></div>
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Rejection rate</div><div style="font-size:1.5rem;font-weight:800;color:<?= $rejectRate > 5 ? '#c0392b' : '#15803d' ?>"><?= number_format($rejectRate, 1) ?>%</div></div>
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Avg blocks per <?= e($cementUnit ?: 'cement unit') ?></div><div style="font-size:1.5rem;font-weight:800;"><?= $avgYield > 0 ? number_format($avgYield, 1) : '—' ?></div></div>
</div>
<div class="panel"><div class="table-container">
<table class="data-table">
    <thead><tr>
        <th>Run</th><th>Date</th><th>Block</th><th style="text-align:right">Produced</th><th style="text-align:right">Rejected</th><th style="text-align:right">Reject %</th>
        <th style="text-align:right">Cement used</th><th style="text-align:right">Actual / <?= e($cementUnit ?: 'unit') ?></th><th style="text-align:right">Expected</th><th style="text-align:right">Difference</th>
    </tr></thead>
    <tbody>
    <?php foreach ($runs as $r):
        $prod = (int) $r['produced_quantity']; $rej = (int) $r['rejected_quantity']; $good = (int) $r['good_quantity'];
        $cement = (float) $r['cement_used'];
        $rr = $prod > 0 ? $rej / $prod * 100 : 0;
        $actual = $cement > 0 ? $good / $cement : 0;
        $perBlock = $bomPerBlock[(int) $r['block_type_id']] ?? 0;
        $expected = $perBlock > 0 ? 1 / $perBlock : 0;
        $diff = ($actual > 0 && $expected > 0) ? ($actual - $expected) / $expected * 100 : null;
        $bad = $diff !== null && $diff < -5;
    ?>
        <tr<?= $bad ? ' style="background:#fff5f5"' : '' ?>>
            <td><a href="view.php?id=<?= (int) $r['id'] ?>"><?= e($r['production_no']) ?></a></td>
            <td><?= e(format_date($r['production_date'])) ?></td>
            <td><?= e($r['name'] . ' ' . $r['size']) ?></td>
            <td style="text-align:right"><?= number_format($prod) ?></td>
            <td style="text-align:right"><?= number_format($rej) ?></td>
            <td style="text-align:right;color:<?= $rr > 5 ? '#c0392b' : 'inherit' ?>"><?= number_format($rr, 1) ?>%</td>
            <td style="text-align:right"><?= $cement > 0 ? number_format($cement, 2) : '—' ?></td>
            <td style="text-align:right"><?= $actual > 0 ? number_format($actual, 1) : '—' ?></td>
            <td style="text-align:right"><?= $expected > 0 ? number_format($expected, 1) : '—' ?></td>
            <td style="text-align:right;font-weight:700;color:<?= $diff === null ? 'inherit' : ($bad ? '#c0392b' : '#15803d') ?>"><?= $diff === null ? '—' : ($diff >= 0 ? '+' : '') . number_format($diff, 1) . '%' ?></td>
        </tr>
    <?php endforeach; if (!$runs): ?><tr><td colspan="10">No production runs in this period.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<p style="font-size:.82rem;color:#62767e;">Runs more than 5% below the expected blocks per cement unit are shaded red.</p>
<?php require_once "../partials/footer.php"; ?>
