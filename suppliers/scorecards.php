<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('suppliers', 'view');

$pageTitle = 'Supplier Scorecards';

$suppliers = $pdo->query("SELECT s.id, s.name, s.phone, COALESCE(SUM(p.total_amount),0) AS total_purchases, COALESCE(COUNT(p.id),0) AS purchase_count, COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END),0) AS paid_count, COALESCE(AVG(CASE WHEN d.delivery_status = 'delivered' THEN 1 ELSE 0 END),0) AS delivery_rate FROM suppliers s LEFT JOIN purchases p ON p.supplier_id = s.id LEFT JOIN delivery_notes d ON d.sale_id IN (SELECT id FROM sales WHERE customer_id IS NOT NULL) WHERE 1=1 GROUP BY s.id, s.name, s.phone ORDER BY total_purchases DESC LIMIT 20")->fetchAll();

$scoreRows = [];
foreach ($suppliers as $supplier) {
    $purchaseCount = (int) $supplier['purchase_count'];
    $totalPurchases = (float) $supplier['total_purchases'];
    $paidRatio = $purchaseCount > 0 ? ((float) $supplier['paid_count'] / $purchaseCount) * 100 : 0;
    $deliveryRate = (float) $supplier['delivery_rate'] * 100;
    $score = min(100, round(($paidRatio * 0.5) + ($deliveryRate * 0.5)));
    $scoreRows[] = [
        'id' => (int) $supplier['id'],
        'name' => $supplier['name'],
        'phone' => $supplier['phone'],
        'total_purchases' => $totalPurchases,
        'score' => $score,
        'paid_ratio' => $paidRatio,
        'delivery_rate' => $deliveryRate,
    ];
}

usort($scoreRows, static function ($a, $b) {
    return $b['score'] <=> $a['score'];
});

require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Supplier Scorecards</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track supplier reliability, payment performance, and value delivered</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to suppliers</a>
    </div>
</div>

<div class="panel">
    <table class="data-table">
        <thead>
            <tr>
                <th>Supplier</th>
                <th>Total spend</th>
                <th>Payment score</th>
                <th>Delivery score</th>
                <th>Overall score</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($scoreRows as $row): ?>
            <tr>
                <td>
                    <strong><?= e($row['name']) ?></strong>
                    <?php if ($row['phone']): ?>
                    <div style="font-size:0.8rem;color:#64748b;"><?= e($row['phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= currency_symbol() . money($row['total_purchases']) ?></td>
                <td><?= number_format($row['paid_ratio'], 1) ?>%</td>
                <td><?= number_format($row['delivery_rate'], 1) ?>%</td>
                <td>
                    <span class="badge badge-<?= $row['score'] >= 80 ? 'success' : ($row['score'] >= 60 ? 'warning' : 'danger') ?>"><?= $row['score'] ?> / 100</span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
