<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'view');

$pageTitle = "View Quotation";
$id = (int) ($_GET['id'] ?? 0);
$validStatuses = ['pending', 'accepted', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission('sales', 'edit')) {
        http_response_code(403);
        exit('You do not have permission to update quotations.');
    }
    $status = (string) ($_POST['status'] ?? '');
    $current = $pdo->prepare("SELECT status, expiry_date FROM quotations WHERE id = ?");
    $current->execute([$id]);
    $currentRow = $current->fetch();
    if (!$currentRow) {
        render_not_found('Quotation not found', 'This quotation does not exist or was removed.', 'index.php', 'Back to quotations');
    }
    $currentStatus = $currentRow['status'];
    $expiryDate = $currentRow['expiry_date'];
    
    // Check if quotation is expired before allowing acceptance
    $today = date('Y-m-d');
    if ($currentStatus === 'pending' && $status === 'accepted' && $expiryDate && $expiryDate < $today) {
        set_modal('error', '❌ Quotation Expired', 'This quotation expired on ' . date('d M Y', strtotime($expiryDate)) . '.<br><br>You cannot accept an expired quotation.');
        $showModal = true;
    } elseif ($currentStatus !== 'pending') {
        set_flash('error', 'Only pending quotations can change status.');
    } elseif (in_array($status, $validStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE quotations SET status = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$status, $id]);
        log_activity('update', 'quotations', $id, "Quotation status set to {$status}");
        
        if ($status === 'accepted') {
            set_modal('success', '✅ Quotation Accepted', 'Quotation has been successfully accepted.<br><br>You can now convert it to a sale or continue viewing.');
            $showModal = true;
        } else {
            set_flash('success', 'Quotation status updated.');
        }
    } else {
        set_flash('error', 'Select a valid quotation status.');
    }
    if (!isset($showModal)) {
        header('Location: view.php?id=' . $id);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, c.phone, c.address FROM quotations q LEFT JOIN customers c ON q.customer_id=c.id WHERE q.id=?");
$stmt->execute([$id]);
$quotation = $stmt->fetch();

if(!$quotation) {
    render_not_found('Quotation not found', 'This quotation does not exist or was removed.', 'index.php', 'Back to quotations');
}

$stmt = $pdo->prepare("SELECT qi.*, bt.name, bt.size FROM quotation_items qi JOIN block_types bt ON qi.block_type_id=bt.id WHERE qi.quotation_id=?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM company_settings LIMIT 1");
$company = $stmt->fetch();

// Check if quotation is expired and show warning
$today = date('Y-m-d');
if ($quotation['status'] === 'pending' && $quotation['expiry_date'] && $quotation['expiry_date'] < $today) {
    $daysExpired = floor((strtotime($today) - strtotime($quotation['expiry_date'])) / (60*60*24));
    $expiryWarning = "⚠️ This quotation expired " . $daysExpired . " day" . ($daysExpired !== 1 ? 's' : '') . " ago on " . date('d M Y', strtotime($quotation['expiry_date'])) . ".<br><br>It is no longer valid for acceptance.";
    if (!isset($showModal)) {
        set_modal('warning', '⚠️ Quotation Expired', $expiryWarning);
        $showModal = true;
    }
}

require_once "../partials/header.php";
?>

<style>
@media print {
    .no-print { display: none !important; }
    body { margin: 0; }
}
.quotation-header {
    background: #f8f9fa;
    padding: 30px;
    border-radius: 8px;
    margin-bottom: 20px;
}
</style>

<?php $flash = get_flash(); ?>
<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?> no-print"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between;">
    <div>
        <a href="index.php" class="btn" style="background: #6c757d;">← Back to List</a>
        <?php if($quotation['status'] !== 'accepted' && has_permission('sales', 'create')): ?>
        <a href="../sales/create.php?quotation=<?= (int) $id ?>" class="btn" style="background: #28a745;">Convert to Sale</a>
        <?php endif; ?>
    </div>
    <button onclick="window.print()" class="btn">🖨️ Print Quotation</button>
</div>

<div style="background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div class="quotation-header">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div>
                <h1 style="margin: 0 0 10px 0; color: #2c3e50;">🧱 <?= htmlspecialchars($company['company_name'] ?? 'Block Plant Company') ?></h1>
                <p style="margin: 5px 0; color: #666;">
                    <?= nl2br(htmlspecialchars($company['address'] ?? '')) ?><br>
                    <?= htmlspecialchars($company['phone'] ?? '') ?><br>
                    <?= htmlspecialchars($company['email'] ?? '') ?>
                </p>
            </div>
            <div style="text-align: right;">
                <h2 style="margin: 0 0 10px 0; color: #2c3e50;">QUOTATION</h2>
                <p style="margin: 5px 0;">
                    <strong>Quotation No:</strong> <?= htmlspecialchars($quotation['quotation_no']) ?><br>
                    <strong>Date:</strong> <?= date('d M Y', strtotime($quotation['quotation_date'])) ?><br>
                    <?php if($quotation['expiry_date']): ?>
                    <strong>Valid Until:</strong> <?= date('d M Y', strtotime($quotation['expiry_date'])) ?><br>
                    <?php endif; ?>
                    <strong>Status:</strong> 
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $quotation['status'] === 'accepted' ? '#d4edda' : ($quotation['status'] === 'rejected' ? '#f8d7da' : '#fff3cd') ?>;">
                        <?= strtoupper($quotation['status']) ?>
                    </span>
                </p>
            </div>
        </div>
    </div>

    <div style="margin: 30px 0;">
        <h3 style="margin: 0 0 10px 0; color: #2c3e50;">Bill To:</h3>
        <p style="margin: 0; color: #666; line-height: 1.6;">
            <strong><?= htmlspecialchars($quotation['customer_name'] ?? 'Walk-in Customer') ?></strong><br>
            <?php if($quotation['phone']): ?>
            Phone: <?= htmlspecialchars($quotation['phone']) ?><br>
            <?php endif; ?>
            <?php if($quotation['address']): ?>
            <?= nl2br(htmlspecialchars($quotation['address'])) ?>
            <?php endif; ?>
        </p>
    </div>

    <table style="width: 100%; margin: 30px 0;">
        <thead>
            <tr style="background: #2c3e50; color: white;">
                <th style="padding: 12px; text-align: left;">Item</th>
                <th style="padding: 12px; text-align: center;">Quantity</th>
                <th style="padding: 12px; text-align: right;">Unit Price</th>
                <th style="padding: 12px; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): ?>
            <tr style="border-bottom: 1px solid #ddd;">
                <td style="padding: 12px;"><?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['size']) ?>)</td>
                <td style="padding: 12px; text-align: center;"><?= number_format($item['quantity']) ?></td>
                <td style="padding: 12px; text-align: right;"><?= number_format($item['unit_price'], 2) ?></td>
                <td style="padding: 12px; text-align: right;"><strong><?= number_format($item['total_price'], 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="display: grid; grid-template-columns: 1fr 300px; gap: 30px; margin-top: 30px;">
        <div>
            <?php if($quotation['notes']): ?>
            <h4>Notes:</h4>
            <p style="color: #666;"><?= nl2br(htmlspecialchars($quotation['notes'])) ?></p>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-radius: 5px;">
                <h4 style="margin: 0 0 10px 0;">Terms & Conditions:</h4>
                <ul style="margin: 0; padding-left: 20px; color: #666; line-height: 1.8;">
                    <li>This quotation is valid until the expiry date</li>
                    <li>Prices are subject to change without notice</li>
                    <li>Payment terms as agreed</li>
                    <li>Delivery charges may apply</li>
                </ul>
            </div>
        </div>

        <div>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 8px;"><strong>Subtotal:</strong></td>
                    <td style="padding: 8px; text-align: right;"><?= number_format($quotation['subtotal'], 2) ?></td>
                </tr>
                <?php if($quotation['discount'] > 0): ?>
                <tr>
                    <td style="padding: 8px;"><strong>Discount:</strong></td>
                    <td style="padding: 8px; text-align: right; color: #dc3545;">-<?= number_format($quotation['discount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background: #2c3e50; color: white; font-size: 18px;">
                    <td style="padding: 12px;"><strong>Total Amount:</strong></td>
                    <td style="padding: 12px; text-align: right;"><strong><?= number_format($quotation['total_amount'], 2) ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <div style="margin-top: 50px; text-align: center; color: #666; border-top: 2px solid #ddd; padding-top: 20px;">
        <p><strong>Thank you for your business!</strong></p>
        <p style="font-size: 12px;">Generated on <?= date('d M Y H:i') ?></p>
    </div>
</div>

<?php if($quotation['status'] === 'pending' && has_permission('sales', 'edit')): ?>
<div class="no-print" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 20px;">
    <h3>Update Status</h3>
    <form method="post" style="display: flex; gap: 10px; align-items: center;">
        <label>Change Status:</label>
        <select name="status" required>
            <option value="pending">Pending</option>
            <option value="accepted">Accepted</option>
            <option value="rejected">Rejected</option>
        </select>
        <button type="submit" class="btn">Update Status</button>
    </form>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
