<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ensure_manufacturing_costing_schema();
ensure_finished_stock_transaction_schema();

$action = $_POST['action'] ?? 'create';
if ($action === 'delete') {
    require_permission('production', 'delete');

    $productionId = (int) ($_POST['production_id'] ?? 0);
    if ($productionId <= 0) {
        set_flash('error', 'Select a production record to delete.');
        header('Location: index.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $prod = $pdo->prepare("SELECT production_no, block_type_id, good_quantity FROM production WHERE id = ? FOR UPDATE");
        $prod->execute([$productionId]);
        $record = $prod->fetch();
        if (!$record) {
            throw new RuntimeException('Production record not found.');
        }

        $productionNo = (string) $record['production_no'];
        $blockTypeId = (int) $record['block_type_id'];
        $goodQty = (int) $record['good_quantity'];

        $materials = $pdo->prepare("SELECT material_id, quantity FROM production_materials WHERE production_id = ?");
        $materials->execute([$productionId]);
        foreach ($materials->fetchAll() as $line) {
            $pdo->prepare("UPDATE raw_materials SET current_stock = current_stock + ? WHERE id = ?")
                ->execute([(float) $line['quantity'], (int) $line['material_id']]);
        }

        $pdo->prepare("DELETE FROM production_materials WHERE production_id = ?")->execute([$productionId]);
        $pdo->prepare("DELETE FROM stock_transactions WHERE reference_no = ? AND transaction_type = 'production_issue'")->execute([$productionNo]);

        $finished = $pdo->prepare("SELECT quantity, average_unit_cost FROM finished_stock WHERE block_type_id = ? FOR UPDATE");
        $finished->execute([$blockTypeId]);
        $stock = $finished->fetch();
        if ($stock) {
            $newQty = max(0, (int) $stock['quantity'] - $goodQty);
            $pdo->prepare("UPDATE finished_stock SET quantity = ? WHERE block_type_id = ?")->execute([$newQty, $blockTypeId]);
        }

        $pdo->prepare("DELETE FROM production WHERE id = ?")->execute([$productionId]);
        $pdo->commit();
        log_activity('delete', 'production', $productionId, "Deleted production record {$productionNo}");
        set_flash('success', 'Production record deleted.');
        header('Location: index.php');
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $error->getMessage());
        header('Location: index.php');
        exit;
    }
}

if ($action === 'update') {
    require_permission('production', 'edit');

    $productionId = (int) ($_POST['production_id'] ?? 0);
    $date = $_POST['production_date'] ?? date('Y-m-d');
    $blockTypeId = (int) ($_POST['block_type_id'] ?? 0);
    $produced = (int) ($_POST['produced_quantity'] ?? 0);
    $rejected = (int) ($_POST['rejected_quantity'] ?? 0);
    $good = $produced - $rejected;
    $laborCost = max(0, (float) ($_POST['labor_cost'] ?? 0));
    $overheadCost = max(0, (float) ($_POST['overhead_cost'] ?? 0));
    $materialIds = $_POST['material_id'] ?? [];
    $materialQuantities = $_POST['material_quantity'] ?? [];

    if ($productionId <= 0 || $blockTypeId <= 0 || $produced <= 0 || $rejected < 0 || $rejected >= $produced || $laborCost < 0 || $overheadCost < 0) {
        set_flash('error', 'Enter a valid block, production quantity, rejection quantity, labor, and overhead. At least one good block is required.');
        header('Location: edit.php?id=' . $productionId);
        exit;
    }
    if (!is_array($materialIds) || !is_array($materialQuantities) || count($materialIds) !== count($materialQuantities)) {
        set_flash('error', 'Production material lines are invalid.');
        header('Location: edit.php?id=' . $productionId);
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
        header('Location: edit.php?id=' . $productionId);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare("SELECT production_no, good_quantity, production_cost, block_type_id FROM production WHERE id = ? FOR UPDATE");
        $old->execute([$productionId]);
        $existing = $old->fetch();
        if (!$existing) {
            throw new RuntimeException('Production record not found.');
        }

        $oldGood = (int) $existing['good_quantity'];
        $materialCost = 0.0;
        $materialLines = [];
        foreach ($requestedMaterials as $materialId => $quantity) {
            $materialStmt = $pdo->prepare("SELECT name, unit, unit_cost FROM raw_materials WHERE id=? AND status=1 FOR UPDATE");
            $materialStmt->execute([$materialId]);
            $material = $materialStmt->fetch();
            if (!$material) {
                throw new RuntimeException('Invalid material selected for update.');
            }
            $unitCost = (float) $material['unit_cost'];
            $lineCost = round($quantity * $unitCost, 2);
            $materialCost += $lineCost;
            $materialLines[] = ['id' => $materialId, 'name' => $material['name'], 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $lineCost];
        }

        $productionCost = round($materialCost + $laborCost + $overheadCost, 2);
        $unitCost = $good > 0 ? round($productionCost / $good, 4) : 0;
        $pdo->prepare("UPDATE production SET production_date=?, block_type_id=?, planned_quantity=?, produced_quantity=?, rejected_quantity=?, good_quantity=?, production_cost=?, labor_cost=?, overhead_cost=?, notes=? WHERE id=?")
            ->execute([$date, $blockTypeId, max(0, (int) ($_POST['planned_quantity'] ?? 0)), $produced, $rejected, $good, $productionCost, $laborCost, $overheadCost, trim($_POST['notes'] ?? ''), $productionId]);

        $pdo->prepare("DELETE FROM production_materials WHERE production_id = ?")->execute([$productionId]);
        $materialInsert = $pdo->prepare("INSERT INTO production_materials(production_id,material_id,quantity,unit_cost,total_cost) VALUES(?,?,?,?,?)");
        foreach ($materialLines as $line) {
            $materialInsert->execute([$productionId, $line['id'], $line['quantity'], $line['unit_cost'], $line['total_cost']]);
        }

        $finishedStmt = $pdo->prepare("SELECT quantity, average_unit_cost FROM finished_stock WHERE block_type_id=? FOR UPDATE");
        $finishedStmt->execute([$blockTypeId]);
        $finished = $finishedStmt->fetch();
        if ($finished) {
            $currentQty = (int) $finished['quantity'];
            $deltaQty = $good - $oldGood;
            $newQty = max(0, $currentQty + $deltaQty);
            $newAverage = $currentQty > 0 ? (float) $finished['average_unit_cost'] : 0;
            $pdo->prepare("UPDATE finished_stock SET quantity=?, average_unit_cost=? WHERE block_type_id=?")->execute([$newQty, $newAverage, $blockTypeId]);
        }

        $pdo->commit();
        log_activity('update', 'production', $productionId, "Updated production record #{$productionId}; material cost {$materialCost}; labor {$laborCost}; overhead {$overheadCost}; total cost {$productionCost}; unit cost {$unitCost}");
        set_flash('success', 'Production record updated successfully.');
        header('Location: view.php?id=' . $productionId);
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $error->getMessage());
        header('Location: edit.php?id=' . $productionId);
        exit;
    }
}

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
