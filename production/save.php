<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('production', 'create');

ensure_manufacturing_costing_schema();
ensure_finished_stock_transaction_schema();
$date = $_POST['production_date'] ?? date('Y-m-d');
$blockTypeId = (int) ($_POST['block_type_id'] ?? 0);
$produced = (int) ($_POST['produced_quantity'] ?? 0);
$rejected = (int) ($_POST['rejected_quantity'] ?? 0);
$good = $produced - $rejected;
$laborCost = max(0, (float) ($_POST['labor_cost'] ?? 0));
$overheadCost = max(0, (float) ($_POST['overhead_cost'] ?? 0));
$materialIds = $_POST['material_id'] ?? [];
$materialQuantities = $_POST['material_quantity'] ?? [];

if ($blockTypeId <= 0 || $produced <= 0 || $rejected < 0 || $rejected >= $produced || $laborCost < 0 || $overheadCost < 0) {
    set_flash('error', 'Enter a valid block, production quantity, rejection quantity, labor, and overhead. At least one good block is required.');
    header('Location: create.php');
    exit;
}
if (!is_array($materialIds) || !is_array($materialQuantities) || count($materialIds) !== count($materialQuantities)) {
    set_flash('error', 'Production material lines are invalid.');
    header('Location: create.php');
    exit;
}

$requestedMaterials = [];
for ($index = 0; $index < count($materialIds); $index++) {
    $materialId = (int) $materialIds[$index];
    $quantity = (float) $materialQuantities[$index];
    if ($materialId <= 0 || !is_finite($quantity) || $quantity <= 0) {
        continue;
    }
    $requestedMaterials[$materialId] = ($requestedMaterials[$materialId] ?? 0) + $quantity;
}
if (!$requestedMaterials) {
    set_flash('error', 'Add at least one positive raw-material quantity.');
    header('Location: create.php');
    exit;
}

try {
    $pdo->beginTransaction();
    $materialLines = [];
    $materialCost = 0.0;
    foreach ($requestedMaterials as $materialId => $quantity) {
        $materialStmt = $pdo->prepare("SELECT name, unit, current_stock, unit_cost FROM raw_materials WHERE id=? AND status=1 FOR UPDATE");
        $materialStmt->execute([$materialId]);
        $material = $materialStmt->fetch();
        if (!$material || (float) $material['current_stock'] < $quantity) {
            throw new RuntimeException('Insufficient raw material stock for material #' . $materialId . '.');
        }
        $unitCost = (float) $material['unit_cost'];
        $lineCost = round($quantity * $unitCost, 2);
        $materialCost += $lineCost;
        $materialLines[] = [
            'id' => $materialId,
            'name' => $material['name'],
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $lineCost,
        ];
    }

    $productionCost = round($materialCost + $laborCost + $overheadCost, 2);
    $unitCost = round($productionCost / $good, 4);
    $productionNo = 'PRO-' . date('YmdHis') . '-' . random_int(10, 99);
    $productionStmt = $pdo->prepare("INSERT INTO production(production_no,production_date,block_type_id,planned_quantity,produced_quantity,rejected_quantity,good_quantity,production_cost,labor_cost,overhead_cost,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $productionStmt->execute([
        $productionNo,
        $date,
        $blockTypeId,
        max(0, (int) ($_POST['planned_quantity'] ?? 0)),
        $produced,
        $rejected,
        $good,
        $productionCost,
        $laborCost,
        $overheadCost,
        trim($_POST['notes'] ?? ''),
        $_SESSION['user_id'],
    ]);
    $productionId = (int) $pdo->lastInsertId();

    $materialInsert = $pdo->prepare("INSERT INTO production_materials(production_id,material_id,quantity,unit_cost,total_cost) VALUES(?,?,?,?,?)");
    $rawStockUpdate = $pdo->prepare("UPDATE raw_materials SET current_stock=current_stock-? WHERE id=? AND current_stock>=?");
    $rawMovementInsert = $pdo->prepare("INSERT INTO stock_transactions(material_id,transaction_type,quantity,reference_no,notes,transaction_date,created_by) VALUES(?,'production_issue',?,?,?,?,?)");
    foreach ($materialLines as $line) {
        $rawStockUpdate->execute([$line['quantity'], $line['id'], $line['quantity']]);
        if ($rawStockUpdate->rowCount() !== 1) {
            throw new RuntimeException('Raw-material stock changed during production posting. Please retry.');
        }
        $materialInsert->execute([$productionId, $line['id'], $line['quantity'], $line['unit_cost'], $line['total_cost']]);
        $rawMovementInsert->execute([$line['id'], -$line['quantity'], $productionNo, 'Production material consumption', $date, $_SESSION['user_id']]);
    }

    $finishedInsert = $pdo->prepare("INSERT IGNORE INTO finished_stock(block_type_id,quantity,average_unit_cost) VALUES(?,0,0)");
    $finishedInsert->execute([$blockTypeId]);
    $finishedStmt = $pdo->prepare("SELECT quantity, average_unit_cost FROM finished_stock WHERE block_type_id=? FOR UPDATE");
    $finishedStmt->execute([$blockTypeId]);
    $finished = $finishedStmt->fetch();
    if (!$finished) {
        throw new RuntimeException('Finished-stock record is missing for the selected block type.');
    }
    $oldQuantity = (int) $finished['quantity'];
    $oldValue = $oldQuantity * (float) $finished['average_unit_cost'];
    $newQuantity = $oldQuantity + $good;
    $newAverageCost = $newQuantity > 0 ? round(($oldValue + $productionCost) / $newQuantity, 4) : 0;
    $pdo->prepare("UPDATE finished_stock SET quantity=?, average_unit_cost=? WHERE block_type_id=?")
        ->execute([$newQuantity, $newAverageCost, $blockTypeId]);
    log_finished_stock_transaction($blockTypeId, $good, 'production_receipt', $productionNo, 'Production receipt at weighted-average cost ' . number_format($unitCost, 4), $date);

    $issueLines = [
        ['1140', $productionCost, 0, 'Production cost accumulated in WIP'],
        ['1130', 0, $materialCost, 'Raw materials issued to production'],
        ['2160', 0, $laborCost, 'Direct labor accrued to production'],
        ['2170', 0, $overheadCost, 'Factory overhead accrued to production'],
    ];
    post_accounting_journal($productionNo . '-ISSUE', $date, 'Raw material and conversion costs issued to WIP', $productionNo, $issueLines, $_SESSION['user_id']);
    post_accounting_journal($productionNo . '-COMPLETE', $date, 'Completed production transferred from WIP to finished goods', $productionNo, [
        ['1150', $productionCost, 0, 'Finished goods received at actual production cost'],
        ['1140', 0, $productionCost, 'Completed WIP transferred to finished goods'],
    ], $_SESSION['user_id']);

    try {
        $pdo->prepare("UPDATE production_schedules SET status='completed', production_id=? WHERE block_type_id=? AND status IN ('planned','in_progress') AND schedule_date<=? ORDER BY schedule_date DESC LIMIT 1")
            ->execute([$productionId, $blockTypeId, $date]);
    } catch (PDOException $e) {
        // Production schedules may not be installed in older databases.
    }

    $pdo->commit();
    log_activity('create', 'production', $productionId, "{$productionNo}; material cost {$materialCost}; labor {$laborCost}; overhead {$overheadCost}; total cost {$productionCost}; unit cost {$unitCost}");
    set_flash('success', "Production {$productionNo} saved. Actual total cost: " . currency_symbol() . money($productionCost) . '; weighted unit cost: ' . currency_symbol() . number_format($unitCost, 4) . '.');
    header('Location: view.php?id=' . $productionId);
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $error->getMessage());
    header('Location: create.php');
    exit;
}
