<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$pageTitle = "Production Reports";
require_once "../partials/header.php";

$stmt = $pdo->prepare("SELECT p.*, bt.name as block_name, bt.size FROM production p JOIN block_types bt ON p.block_type_id=bt.id WHERE p.production_date BETWEEN ? AND ? ORDER BY p.production_date DESC");
$stmt->execute([$from, $to]);
$productions = $stmt->fetchAll();

$total_planned = array_sum(array_column($productions, 'planned_quantity'));
$total_produced = array_sum(array_column($productions, 'produced_quantity'));
$total_good = array_sum(array_column($productions, 'good_quantity'));
$total_rejected = array_sum(array_column($productions, 'rejected_quantity'));
$total_cost = array_sum(array_column($productions, 'production_cost'));

$efficiency = $total_planned > 0 ? ($total_produced / $total_planned) * 100 : 0;
$quality = $total_produced > 0 ? ($total_good / $total_produced) * 100 : 0;
?>

<h1>🏭 Production Reports</h1>

<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px;">
        <div>
            <label>From Date</label>
            <input type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to" value="<?= e($to) ?>">
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="btn">Filter</button>
        </div>
    </div>
</form>

<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Total Produced</h3>
        <div style="font-size: 28px; font-weight: bold;"><?= number_format($total_produced) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Good Quality</h3>
        <div style="font-size: 28px; font-weight: bold; color: #28a745;"><?= number_format($total_good) ?></div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Efficiency</h3>
        <div style="font-size: 28px; font-weight: bold; color: #007bff;"><?= number_format($efficiency, 1) ?>%</div>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <h3 style="color: #666; margin: 0 0 10px 0;">Quality Rate</h3>
        <div style="font-size: 28px; font-weight: bold; color: #17a2b8;"><?= number_format($quality, 1) ?>%</div>
    </div>
</div>

<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
        <h3>Production Details</h3>
        <div style="display:flex;gap:8px;">
            <a href="../reports/export_csv.php?type=production&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-secondary">Export CSV</a>
            <button onclick="window.print()" class="btn">Print Report</button>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Production No</th>
                <th>Block Type</th>
                <th>Planned</th>
                <th>Produced</th>
                <th>Good</th>
                <th>Rejected</th>
                <th>Cost</th>
                <th>Unit Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($productions as $prod): ?>
            <tr>
                <td><?= date('d M Y', strtotime($prod['production_date'])) ?></td>
                <td><?= htmlspecialchars($prod['production_no']) ?></td>
                <td><?= htmlspecialchars($prod['block_name']) ?> (<?= $prod['size'] ?>)</td>
                <td><?= number_format($prod['planned_quantity']) ?></td>
                <td><?= number_format($prod['produced_quantity']) ?></td>
                <td><?= number_format($prod['good_quantity']) ?></td>
                <td style="color: #dc3545;"><?= number_format($prod['rejected_quantity']) ?></td>
                <td><?= number_format($prod['production_cost'], 2) ?></td>
                <td><?= $prod['good_quantity'] > 0 ? number_format($prod['production_cost'] / $prod['good_quantity'], 2) : '0.00' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
