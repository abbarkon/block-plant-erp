<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('production', 'view');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, bt.name, bt.size, u.name AS created_by_name FROM production p JOIN block_types bt ON p.block_type_id=bt.id LEFT JOIN users u ON p.created_by=u.id WHERE p.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) {
    require_once '../partials/header.php';
    echo render_not_found('Production run not found', 'The production run you requested does not exist.', 'index.php', 'Return to production orders');
    require_once '../partials/footer.php';
    exit;
}

$materials = $pdo->prepare("SELECT pm.*, rm.name, rm.unit FROM production_materials pm JOIN raw_materials rm ON pm.material_id=rm.id WHERE pm.production_id=?");
$materials->execute([$id]);
$materials = $materials->fetchAll();

$qualityChecks = [];
try {
    $qcStmt = $pdo->prepare("SELECT * FROM production_quality_checks WHERE production_id = ? ORDER BY check_date DESC, id DESC");
    $qcStmt->execute([$id]);
    $qualityChecks = $qcStmt->fetchAll();
} catch (PDOException $e) {
    $qualityChecks = [];
}

$materialCostTotal = array_sum(array_map(fn($m) => (float) $m['total_cost'], $materials));
$laborCost = (float) ($p['labor_cost'] ?? 0);
$overheadCost = (float) ($p['overhead_cost'] ?? 0);

$eff = $p['planned_quantity'] > 0 ? ($p['produced_quantity'] / $p['planned_quantity']) * 100 : 0;
$qual = $p['produced_quantity'] > 0 ? ($p['good_quantity'] / $p['produced_quantity']) * 100 : 0;
$unitCost = (int) $p['good_quantity'] > 0 ? (float) $p['production_cost'] / (int) $p['good_quantity'] : 0;

$pageTitle = 'Production ' . $p['production_no'];
require_once "../partials/header.php";
?>

<div class="page-header no-print">
    <h1><?= e($p['production_no']) ?></h1>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <?php if (has_permission('production', 'edit')): ?>
        <a href="edit.php?id=<?= (int) $p['id'] ?>" class="btn btn-accent">Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary">← All runs</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#3498db;">📅</div><div><div class="stat-value"><?= format_date($p['production_date']) ?></div><div class="stat-label">Date</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#2f7d4f;">✓</div><div><div class="stat-value"><?= number_format($p['good_quantity']) ?></div><div class="stat-label">Good qty</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e74c3c;">%</div><div><div class="stat-value"><?= number_format($qual, 1) ?>%</div><div class="stat-label">Quality</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#9b59b6;">💰</div><div><div class="stat-value"><?= currency_symbol() . money($p['production_cost']) ?></div><div class="stat-label">Total cost</div></div></div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Run details</h3>
        <p><strong>Block:</strong> <?= e($p['name'] . ' ' . $p['size']) ?></p>
        <p><strong>Planned:</strong> <?= number_format($p['planned_quantity']) ?> · <strong>Produced:</strong> <?= number_format($p['produced_quantity']) ?></p>
        <p><strong>Rejected:</strong> <?= number_format($p['rejected_quantity']) ?> · <strong>Efficiency:</strong> <?= number_format($eff, 1) ?>%</p>
        <p><strong>Recorded by:</strong> <?= e($p['created_by_name'] ?? '—') ?></p>
        <?php if ($p['notes']): ?><p><strong>Notes:</strong> <?= e($p['notes']) ?></p><?php endif; ?>
    </div>
    <div class="panel">
        <h3>Materials consumed</h3>
        <table class="data-table">
            <thead><tr><th>Material</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
            <tbody>
                <?php if (!$materials): ?><tr><td colspan="4" style="color:#64748b;">No materials recorded for this run.</td></tr><?php endif; ?>
                <?php foreach ($materials as $m): ?>
                <tr>
                    <td><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</td>
                    <td><?= number_format($m['quantity'], 2) ?></td>
                    <td><?= currency_symbol() . money($m['unit_cost']) ?></td>
                    <td><?= currency_symbol() . money($m['total_cost']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($materials): ?>
                <tr style="font-weight:700;border-top:2px solid #dbe3ef;">
                    <td colspan="3" style="text-align:right;">Material cost</td>
                    <td><?= currency_symbol() . money($materialCostTotal) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <h3>Cost breakdown</h3>
    <div class="stats-grid">
        <div class="stat-card"><div><div class="stat-value"><?= currency_symbol() . money($materialCostTotal) ?></div><div class="stat-label">Materials</div></div></div>
        <div class="stat-card"><div><div class="stat-value"><?= currency_symbol() . money($laborCost) ?></div><div class="stat-label">Direct labor</div></div></div>
        <div class="stat-card"><div><div class="stat-value"><?= currency_symbol() . money($overheadCost) ?></div><div class="stat-label">Factory overhead</div></div></div>
        <div class="stat-card"><div><div class="stat-value"><?= currency_symbol() . money($p['production_cost']) ?></div><div class="stat-label">Total production cost</div></div></div>
        <div class="stat-card"><div><div class="stat-value"><?= currency_symbol() . number_format($unitCost, 4) ?></div><div class="stat-label">Cost / good block</div></div></div>
    </div>
</div>

<div class="panel" style="margin-top:16px;">
    <h3>Quality checks</h3>
    <table class="data-table">
        <thead><tr><th>Date</th><th>Batch</th><th>Inspector</th><th>Checks</th><th>Result</th></tr></thead>
        <tbody>
            <?php if (!$qualityChecks): ?>
            <tr><td colspan="5" style="color:#64748b;">No quality checks linked to this run yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($qualityChecks as $qc): ?>
            <tr>
                <td><?= format_date($qc['check_date']) ?></td>
                <td><?= e($qc['batch_no'] ?: '-') ?></td>
                <td><?= e($qc['inspector'] ?: '-') ?></td>
                <td><?= (int) $qc['dimensions_ok'] + (int) $qc['strength_ok'] + (int) $qc['visual_ok'] + (int) $qc['finished_ok'] ?>/4</td>
                <td><span class="badge badge-<?= $qc['result'] === 'pass' ? 'success' : ($qc['result'] === 'fail' ? 'danger' : 'warning') ?>"><?= ucfirst(e($qc['result'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
