<?php
session_start();
require_once "../config/database.php";

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT s.*, c.name as customer_name, c.phone, c.address FROM sales s LEFT JOIN customers c ON s.customer_id=c.id WHERE s.id=?");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if(!$sale) {
    die("Invoice not found!");
}

$stmt = $pdo->prepare("SELECT si.*, bt.name, bt.size FROM sale_items si JOIN block_types bt ON si.block_type_id=bt.id WHERE si.sale_id=?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM company_settings LIMIT 1");
$company = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice - <?= htmlspecialchars($sale['invoice_no']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .header { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 3px solid #2c3e50; padding-bottom: 20px; }
        .company-info h1 { margin: 0; color: #2c3e50; font-size: 28px; }
        .company-info p { margin: 5px 0; color: #666; }
        .invoice-info { text-align: right; }
        .invoice-info h2 { margin: 0; color: #2c3e50; }
        .invoice-info p { margin: 5px 0; }
        .customer-info { background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .customer-info h3 { margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #2c3e50; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals { margin-top: 20px; float: right; width: 300px; }
        .totals table { margin: 0; }
        .totals td { padding: 8px; }
        .totals .final { background: #2c3e50; color: white; font-size: 18px; font-weight: bold; }
        .footer { clear: both; margin-top: 50px; text-align: center; color: #666; border-top: 2px solid #ddd; padding-top: 20px; }
        .status { padding: 5px 15px; border-radius: 5px; display: inline-block; font-weight: bold; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-partial { background: #fff3cd; color: #856404; }
        .status-unpaid { background: #f8d7da; color: #721c24; }
        @media print {
            @page { margin: 12mm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { margin: 0; }
            button { display: none !important; }
            tr, .customer-info, .totals, .footer { page-break-inside: avoid; break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" style="position: fixed; top: 10px; right: 10px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">🖨️ Print Invoice</button>

    <div class="header">
        <div class="company-info">
            <h1>🧱 <?= htmlspecialchars($company['company_name'] ?? 'Block Plant Company') ?></h1>
            <p><?= nl2br(htmlspecialchars($company['address'] ?? '')) ?></p>
            <p>Phone: <?= htmlspecialchars($company['phone'] ?? '') ?></p>
            <p>Email: <?= htmlspecialchars($company['email'] ?? '') ?></p>
            <?php if($company['tax_id']): ?>
            <p>Tax ID: <?= htmlspecialchars($company['tax_id']) ?></p>
            <?php endif; ?>
        </div>
        
        <div class="invoice-info">
            <h2>SALES INVOICE</h2>
            <p><strong>Invoice No:</strong> <?= htmlspecialchars($sale['invoice_no']) ?></p>
            <p><strong>Date:</strong> <?= date('d M Y', strtotime($sale['sale_date'])) ?></p>
            <p><strong>Time:</strong> <?= date('H:i', strtotime($sale['created_at'])) ?></p>
            <p>
                <span class="status status-<?= $sale['payment_status'] ?>">
                    <?= strtoupper($sale['payment_status']) ?>
                </span>
            </p>
        </div>
    </div>

    <div class="customer-info">
        <h3>Bill To:</h3>
        <p style="margin: 0; line-height: 1.6;">
            <strong><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer') ?></strong><br>
            <?php if($sale['phone']): ?>
            Phone: <?= htmlspecialchars($sale['phone']) ?><br>
            <?php endif; ?>
            <?php if($sale['address']): ?>
            <?= nl2br(htmlspecialchars($sale['address'])) ?>
            <?php endif; ?>
        </p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item Description</th>
                <th class="text-center">Quantity</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['size']) ?>)</td>
                <td class="text-center"><?= number_format($item['quantity']) ?></td>
                <td class="text-right"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="text-right"><strong><?= number_format($item['total_price'], 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td><strong>Subtotal:</strong></td>
                <td class="text-right"><?= number_format($sale['subtotal'], 2) ?></td>
            </tr>
            <?php if($sale['discount'] > 0): ?>
            <tr>
                <td><strong>Discount:</strong></td>
                <td class="text-right" style="color: #dc3545;">-<?= number_format($sale['discount'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="final">
                <td>TOTAL:</td>
                <td class="text-right"><?= number_format($sale['total_amount'], 2) ?></td>
            </tr>
            <tr>
                <td><strong>Paid:</strong></td>
                <td class="text-right" style="color: #28a745;"><?= number_format($sale['paid_amount'], 2) ?></td>
            </tr>
            <?php if($sale['balance'] > 0): ?>
            <tr>
                <td><strong>Balance Due:</strong></td>
                <td class="text-right" style="color: #dc3545; font-weight: bold;"><?= number_format($sale['balance'], 2) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="footer">
        <?php if($sale['notes']): ?>
        <div style="text-align: left; margin-bottom: 20px;">
            <strong>Notes:</strong><br>
            <?= nl2br(htmlspecialchars($sale['notes'])) ?>
        </div>
        <?php endif; ?>
        
        <p><strong>Thank you for your business!</strong></p>
        <p style="font-size: 12px;">This is a computer-generated invoice and does not require a signature.</p>
        <p style="font-size: 12px;">Printed on: <?= date('d M Y H:i:s') ?></p>
    </div>
</body>
</html>
