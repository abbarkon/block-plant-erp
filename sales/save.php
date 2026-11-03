<?php
session_start();
require_once "../config/database.php";
require_once __DIR__ . "/../config/helpers.php";
require_login();
require_permission('sales', 'create');
ensure_logistics_cost_columns();
ensure_gate_schema();
ensure_manufacturing_costing_schema();
ensure_finished_stock_transaction_schema();

$customerId = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
$saleDate = $_POST['sale_date'] ?? date('Y-m-d');
$paidAmount = max(0, (float) ($_POST['paid_amount'] ?? 0));
$paymentMethod = $_POST['payment_method'] ?? 'cash';
$vehiclePlate = trim($_POST['vehicle_plate'] ?? '');
$driverName = trim($_POST['driver_name'] ?? '');
$driverPhone = trim($_POST['driver_phone'] ?? '');
$paymentReference = trim($_POST['payment_reference'] ?? '');
$discount = max(0, (float) ($_POST['discount'] ?? 0));
$loadingCost = max(0, (float) ($_POST['loading_cost'] ?? 0));
$unloadingCost = max(0, (float) ($_POST['unloading_cost'] ?? 0));
$logisticsCost = $loadingCost + $unloadingCost;
$quotationId = (int) ($_POST['quotation_id'] ?? 0);

// Line items: prefer the array posted by the multi-line form, fall back to the
// legacy single-product fields for backward compatibility.
$items = [];
if (isset($_POST['item_block_type_id']) && is_array($_POST['item_block_type_id'])) {
    $qtyArr = is_array($_POST['item_quantity'] ?? null) ? $_POST['item_quantity'] : [];
    $priceArr = is_array($_POST['item_unit_price'] ?? null) ? $_POST['item_unit_price'] : [];
    foreach ($_POST['item_block_type_id'] as $i => $blockId) {
        $blockId = (int) $blockId;
        $qty = (int) ($qtyArr[$i] ?? 0);
        $price = (float) ($priceArr[$i] ?? 0);
        if ($blockId <= 0 && $qty <= 0 && $price <= 0) {
            continue; // skip fully-empty rows
        }
        $items[] = ['block_type_id' => $blockId, 'quantity' => $qty, 'unit_price' => $price];
    }
} elseif (isset($_POST['block_type_id'])) {
    $items[] = [
        'block_type_id' => (int) $_POST['block_type_id'],
        'quantity' => (int) ($_POST['quantity'] ?? 0),
        'unit_price' => (float) ($_POST['unit_price'] ?? 0),
    ];
}

$subtotal = 0.0;
foreach ($items as $item) {
    $subtotal += $item['quantity'] * $item['unit_price'];
}
$subtotal = round($subtotal, 2);
$total = max(0, $subtotal + $loadingCost + $unloadingCost - $discount);
$paidAmount = min($paidAmount, $total);
$balance = $total - $paidAmount;

try {
    // Validate before touching the database.
    if ($vehiclePlate === '') {
        throw new Exception('A vehicle plate number is required.');
    }
    if (!$items) {
        throw new Exception('Add at least one product line to the invoice.');
    }
    foreach ($items as $item) {
        if ($item['block_type_id'] <= 0 || $item['quantity'] <= 0 || $item['unit_price'] < 0) {
            throw new Exception('Every line needs a product, a quantity of at least 1, and a valid price.');
        }
    }

    $pdo->beginTransaction();

    $stockStmt = $pdo->prepare("SELECT COALESCE(quantity, 0) AS quantity, COALESCE(average_unit_cost, 0) AS average_unit_cost FROM finished_stock WHERE block_type_id = ? FOR UPDATE");

    // Aggregate demand per block so repeated lines cannot overdraw the same stock.
    $needByBlock = [];
    foreach ($items as $item) {
        $needByBlock[$item['block_type_id']] = ($needByBlock[$item['block_type_id']] ?? 0) + $item['quantity'];
    }
    $avgCostByBlock = [];
    foreach ($needByBlock as $blockId => $need) {
        $stockStmt->execute([$blockId]);
        $finishedStock = $stockStmt->fetch();
        if (!$finishedStock || (int) $finishedStock['quantity'] < $need) {
            throw new Exception('Insufficient finished stock for one of the selected products.');
        }
        $avgCostByBlock[$blockId] = (float) $finishedStock['average_unit_cost'];
    }

    $cogsAmount = 0.0;
    foreach ($items as $item) {
        $cogsAmount += $item['quantity'] * $avgCostByBlock[$item['block_type_id']];
    }
    $cogsAmount = round($cogsAmount, 2);

    $invoiceNo = 'INV-' . date('YmdHis');
    $saleStmt = $pdo->prepare("INSERT INTO sales(invoice_no, customer_id, sale_date, subtotal, discount, total_amount, paid_amount, balance, payment_status, loading_cost, unloading_cost, vehicle_plate, driver_name, driver_phone, payment_method, payment_reference, salesperson_id, cogs_amount, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $saleStmt->execute([
        $invoiceNo,
        $customerId,
        $saleDate,
        $subtotal,
        $discount,
        $total,
        $paidAmount,
        $balance,
        $balance == 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'credit'),
        $loadingCost,
        $unloadingCost,
        $vehiclePlate,
        $driverName ?: null,
        $driverPhone ?: null,
        $paymentMethod,
        $paymentReference ?: null,
        $_SESSION['user_id'],
        $cogsAmount,
        $_SESSION['user_id'],
    ]);
    $saleId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("INSERT INTO sale_items(sale_id, block_type_id, quantity, unit_price, total_price) VALUES(?,?,?,?,?)");
    $updateStock = $pdo->prepare("UPDATE finished_stock SET quantity = quantity - ? WHERE block_type_id = ?");
    foreach ($items as $item) {
        $lineSubtotal = round($item['quantity'] * $item['unit_price'], 2);
        $itemStmt->execute([$saleId, $item['block_type_id'], $item['quantity'], $item['unit_price'], $lineSubtotal]);
        $updateStock->execute([$item['quantity'], $item['block_type_id']]);
        log_finished_stock_transaction($item['block_type_id'], -$item['quantity'], 'sale_issue', $invoiceNo, 'Sales invoice', $saleDate);
    }

    if ($total > 0) {
        $saleJournalLines = [
            ['1110', $paidAmount, 0, 'Cash received for sale'],
            ['1120', $balance, 0, 'Customer receivable for sale'],
            ['4100', 0, $total, 'Sales revenue'],
        ];
        post_accounting_journal('SALE-' . $invoiceNo, $saleDate, 'Sales invoice revenue', $invoiceNo, $saleJournalLines, $_SESSION['user_id']);
    }
    if ($cogsAmount > 0) {
        post_accounting_journal('COGS-' . $invoiceNo, $saleDate, 'Cost of goods sold', $invoiceNo, [
            ['5000', $cogsAmount, 0, 'Finished blocks sold at weighted-average manufacturing cost'],
            ['1150', 0, $cogsAmount, 'Finished goods inventory relieved on sale'],
        ], $_SESSION['user_id']);
    }

    if ($paidAmount > 0) {
        $paymentStmt = $pdo->prepare("INSERT INTO payments(sale_id, customer_id, payment_date, amount, payment_method, created_by) VALUES(?,?,?,?,?,?)");
        $paymentStmt->execute([$saleId, $customerId, $saleDate, $paidAmount, $paymentMethod, $_SESSION['user_id']]);

        $cashbookStmt = $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES(?,'income',?,?,?,?,?)");
        $cashbookStmt->execute([$saleDate, $invoiceNo, 'Sales payment', $paidAmount, $paymentMethod, $_SESSION['user_id']]);
    }

    if ($logisticsCost > 0) {
        $expenseNo = 'EXP-' . date('YmdHis') . '-' . random_int(100, 999);
        $pdo->prepare("INSERT INTO expenses(voucher_no, expense_date, category, description, amount, payment_method, reference_no, created_by) VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$expenseNo, $saleDate, 'Loading and Unloading', "Logistics cost for sale {$invoiceNo}", $logisticsCost, $paymentMethod, $invoiceNo, $_SESSION['user_id']]);
        $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES(?,'expense',?,?,?,?,?)")
            ->execute([$saleDate, $expenseNo, "Logistics cost for sale {$invoiceNo}", $logisticsCost, $paymentMethod, $_SESSION['user_id']]);
    }

    $gateClearance = null;
    if ($balance <= 0) {
        $gateClearance = create_gate_clearance_for_sale($saleId, $_SESSION['user_id']);
    }

    if ($quotationId > 0) {
        $pdo->prepare("UPDATE quotations SET status = 'accepted' WHERE id = ? AND status <> 'accepted'")->execute([$quotationId]);
    }

    $pdo->commit();

    $company = company_settings();
    $commissionRate = (float) ($company['default_commission_rate'] ?? 0);
    if ($commissionRate > 0) {
        $commission = round($total * $commissionRate / 100, 2);
        $pdo->prepare("INSERT INTO sales_commissions(sale_id, user_id, commission_percentage, commission_amount) VALUES(?,?,?,?)")
            ->execute([$saleId, $_SESSION['user_id'], $commissionRate, $commission]);
    }

    set_flash('success', $gateClearance
        ? "Sale {$invoiceNo} was saved successfully. Gate clearance {$gateClearance['clearance_number']} is ready to print after loading confirmation."
        : "Sale {$invoiceNo} was saved successfully. Gate clearance requires full payment and a vehicle plate.");
    $redirect = "index.php?created={$saleId}";
    if ($gateClearance) {
        $redirect .= '&clearance=' . (int) $gateClearance['id'];
    }
    header("Location: {$redirect}");
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $e->getMessage());
    header('Location: create.php');
    exit;
}
