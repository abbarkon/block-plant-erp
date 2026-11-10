<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'view');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT d.*, s.invoice_no, s.total_amount, s.sale_date, c.name AS customer_name, c.phone
    FROM delivery_notes d
    JOIN sales s ON d.sale_id = s.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$d = $stmt->fetch();
if (!$d) {
    render_not_found('Delivery note not found', 'This delivery note does not exist or was removed.', '../crm/delivery.php', 'Back to delivery notes');
}

$items = $pdo->prepare("SELECT si.*, bt.name, bt.size FROM sale_items si JOIN block_types bt ON si.block_type_id=bt.id WHERE si.sale_id=?");
$items->execute([$d['sale_id']]);
$items = $items->fetchAll();
$co = company_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Delivery <?= e($d['delivery_no']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #222; }
        .head { display: flex; justify-content: space-between; border-bottom: 3px solid <?= e($co['primary_color'] ?? '#4f4fc9') ?>; padding-bottom: 16px; }
        h1 { margin: 0; color: <?= e($co['primary_color'] ?? '#4f4fc9') ?>; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f1f5f9; }
        @media print {
            @page { margin: 12mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { margin: 0; }
            .no-print { display: none !important; }
            tr, .head, table { page-break-inside: avoid; break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">Print</button></p>
    <div class="head">
        <div>
            <h1><?= e(company_display_name()) ?></h1>
            <?php if (company_tagline()): ?><p><?= e(company_tagline()) ?></p><?php endif; ?>
            <p><?= e($co['phone'] ?? '') ?> · <?= e($co['email'] ?? '') ?></p>
        </div>
        <div style="text-align:right;">
            <h2 style="margin:0;">DELIVERY NOTE</h2>
            <p><strong><?= e($d['delivery_no']) ?></strong></p>
            <p>Date: <?= format_date($d['delivery_date']) ?></p>
            <p>Invoice: <?= e($d['invoice_no']) ?></p>
        </div>
    </div>
    <p><strong>Deliver to:</strong> <?= e($d['customer_name'] ?? 'Walk-in') ?> · <?= e($d['phone'] ?? '') ?><br>
    <?= e($d['delivery_address'] ?: '—') ?></p>
    <p><strong>Vehicle:</strong> <?= e($d['vehicle_no'] ?: '—') ?> · <strong>Driver:</strong> <?= e($d['driver_name'] ?: '—') ?></p>
    <table>
        <thead><tr><th>Item</th><th>Qty</th></tr></thead>
        <tbody>
            <?php foreach ($items as $i): ?>
            <tr><td><?= e($i['name'] . ' ' . $i['size']) ?></td><td><?= (int) $i['quantity'] ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:40px;">Received by: _________________________ Date: __________</p>
    <p>Status: <strong><?= e($d['delivery_status']) ?></strong></p>
</body>
</html>
