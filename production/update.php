<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_permission('production', 'edit');

ensure_manufacturing_costing_schema();
ensure_finished_stock_transaction_schema();
ensure_production_curing_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$back = 'edit.php?id=' . $id;
$date = trim($_POST['production_date'] ?? '');
$produced = (int) ($_POST['produced_quantity'] ?? 0);
$rejected = (int) ($_POST['rejected_quantity'] ?? 0);
$good = $produced - $rejected;
$planned = max(0, (int) ($_POST['planned_quantity'] ?? 0));
$laborCost = max(0, (float) ($_POST['labor_cost'] ?? 0));
$overheadCost = max(0, (float) ($_POST['overhead_cost'] ?? 0));
$curingDays = min(365, max(0, (int) ($_POST['curing_days'] ?? production_default_curing_days())));
$notes = trim($_POST['notes'] ?? '');
$materialIds = $_POST['material_id'] ?? [];
$materialQuantities = $_POST['material_quantity'] ?? [];

if ($id <= 0 || !is_valid_date_string($date) || $date > date('Y-m-d')) {
    set_flash('error', 'Enter a valid production date (not in the future).');
    header('Location: ' . $back);
    exit;
}
if ($produced <= 0 || $rejected < 0 || $rejected >= $produced) {
    set_flash('error', 'Enter a valid production quantity and rejection quantity. At least one good block is required.');
    header('Location: ' . $back);
    exit;
}
if (!is_array($materialIds) || !is_array($materialQuantities) || count($materialIds) !== count($materialQuantities)) {
    set_flash('error', 'Production material lines are invalid.');
    header('Location: ' . $back);
    exit;
}
$requested = [];
for ($i = 0; $i < count($materialIds); $i++) {
    $mid = (int) $materialIds[$i];
    $qty = (float) $materialQuantities[$i];
    if ($mid <= 0 || !is_finite($qty) || $qty <= 0) {
        continue;
    }
    $requested[$mid] = ($requested[$mid] ?? 0) + $qty;
}
if (!$requested) {
    set_flash('error', 'Add at least one positive raw-material quantity.');
    header('Location: ' . $back);
    exit;
}

try {
    $pdo->beginTransaction();

    $runStmt = $pdo->prepare("SELECT * FROM production WHERE id=? FOR UPDATE");
    $runStmt->execute([$id]);
    $run = $runStmt->fetch();
    if (!$run) {
        throw new RuntimeException('Production run not found.');
    }
    $productionNo = $run['production_no'];
    $blockTypeId = (int) $run['block_type_id'];
    $oldGood = (int) $run['good_quantity'];
    $oldCost = (float) $run['production_cost'];
    $oldLabor = (float) $run['labor_cost'];
    $oldOverhead = (float) $run['overhead_cost'];
    $oldReadyDate = $run['ready_date'] ?: $run['production_date'];
    // A batch that is already ready must not be pushed back into curing by an edit.
    $readyDate = $oldReadyDate > date('Y-m-d') ? production_ready_date($date, $curingDays) : $oldReadyDate;

    $oldMatStmt = $pdo->prepare("SELECT material_id, quantity, unit_cost, total_cost FROM production_materials WHERE production_id=?");
    $oldMatStmt->execute([$id]);
    $oldMaterials = $oldMatStmt->fetchAll();
    $oldMaterialCost = 0.0;
    $oldUnitCosts = [];
    foreach ($oldMaterials as $m) {
        $oldMaterialCost += (float) $m['total_cost'];
        $oldUnitCosts[(int) $m['material_id']] = (float) $m['unit_cost'];
    }

    $rawMovement = $pdo->prepare("INSERT INTO stock_transactions(material_id,transaction_type,quantity,reference_no,notes,transaction_date,created_by) VALUES(?,'production_issue',?,?,?,?,?)");

    // 1) Put the old material consumption back into raw-material stock.
    foreach ($oldMaterials as $m) {
        $pdo->prepare("UPDATE raw_materials SET current_stock=current_stock+? WHERE id=?")
            ->execute([(float) $m['quantity'], (int) $m['material_id']]);
        $rawMovement->execute([(int) $m['material_id'], (float) $m['quantity'], $productionNo, 'Reversal - production run edited', $date, $_SESSION['user_id']]);
    }
    $pdo->prepare("DELETE FROM production_materials WHERE production_id=?")->execute([$id]);

    // 2) Issue the new material quantities (original unit cost kept for materials already in the run).
    $materialCost = 0.0;
    $lines = [];
    foreach ($requested as $mid => $qty) {
        $mStmt = $pdo->prepare("SELECT name, current_stock, unit_cost FROM raw_materials WHERE id=? AND status=1 FOR UPDATE");
        $mStmt->execute([$mid]);
        $mat = $mStmt->fetch();
        if (!$mat) {
            throw new RuntimeException('Raw material #' . $mid . ' was not found.');
        }
        if ((float) $mat['current_stock'] < $qty) {
            throw new RuntimeException('Insufficient stock of ' . $mat['name'] . ' for this change (available ' . number_format((float) $mat['current_stock'], 2) . ').');
        }
        $unitCost = $oldUnitCosts[$mid] ?? (float) $mat['unit_cost'];
        $lineCost = round($qty * $unitCost, 2);
        $materialCost += $lineCost;
        $lines[] = ['id' => $mid, 'qty' => $qty, 'unit_cost' => $unitCost, 'total' => $lineCost];
    }
    $matInsert = $pdo->prepare("INSERT INTO production_materials(production_id,material_id,quantity,unit_cost,total_cost) VALUES(?,?,?,?,?)");
    foreach ($lines as $l) {
        $pdo->prepare("UPDATE raw_materials SET current_stock=current_stock-? WHERE id=?")->execute([$l['qty'], $l['id']]);
        $matInsert->execute([$id, $l['id'], $l['qty'], $l['unit_cost'], $l['total']]);
        $rawMovement->execute([$l['id'], -$l['qty'], $productionNo, 'Production material consumption (edited)', $date, $_SESSION['user_id']]);
    }

    $newCost = round($materialCost + $laborCost + $overheadCost, 2);
    $unitCostBlock = round($newCost / $good, 4);

    // 3) Finished stock: swap the old receipt for the new one (never below zero).
    $pdo->prepare("INSERT IGNORE INTO finished_stock(block_type_id,quantity,average_unit_cost) VALUES(?,0,0)")->execute([$blockTypeId]);
    $fsStmt = $pdo->prepare("SELECT quantity, average_unit_cost FROM finished_stock WHERE block_type_id=? FOR UPDATE");
    $fsStmt->execute([$blockTypeId]);
    $fs = $fsStmt->fetch();
    $curQty = (int) $fs['quantity'];
    $newQty = $curQty - $oldGood + $good;
    if ($newQty < 0) {
        throw new RuntimeException('Cannot reduce this run: only ' . $curQty . ' finished blocks are in stock, but ' . $oldGood . ' came from this run and some have already been sold or issued.');
    }
    $curValue = $curQty * (float) $fs['average_unit_cost'];
    $newValue = max(0, $curValue - $oldCost + $newCost);
    $newAvg = $newQty > 0 ? round($newValue / $newQty, 4) : 0;
    $pdo->prepare("UPDATE finished_stock SET quantity=?, average_unit_cost=? WHERE block_type_id=?")->execute([$newQty, $newAvg, $blockTypeId]);
    if ($good !== $oldGood) {
        log_finished_stock_transaction($blockTypeId, $good - $oldGood, 'production_adjustment', $productionNo, 'Production run edited (' . $oldGood . ' to ' . $good . ' good blocks)', $date);
    }

    // 4) Update the production record.
    $pdo->prepare("UPDATE production SET production_date=?, curing_days=?, ready_date=?, planned_quantity=?, produced_quantity=?, rejected_quantity=?, good_quantity=?, production_cost=?, labor_cost=?, overhead_cost=?, notes=? WHERE id=?")
        ->execute([$date, $curingDays, $readyDate, $planned, $produced, $rejected, $good, $newCost, $laborCost, $overheadCost, $notes, $id]);

    // 5) Post adjustment journals for the cost differences.
    $dMat = round($materialCost - $oldMaterialCost, 2);
    $dLab = round($laborCost - $oldLabor, 2);
    $dOv = round($overheadCost - $oldOverhead, 2);
    $dCost = round($dMat + $dLab + $dOv, 2);
    // Keep adjustment entry numbers within journal_entries.entry_no limits:
    // production_no (21) + suffix (18) + '-COMPLETE' (9) = 48 characters.
    $suffix = '-ADJ' . date('ymdHis') . random_int(10, 99);
    if ($dMat != 0.0 || $dLab != 0.0 || $dOv != 0.0) {
        post_accounting_journal($productionNo . $suffix . '-ISSUE', date('Y-m-d'), 'Adjustment: production ' . $productionNo . ' edited (costs issued to WIP)', $productionNo, [
            ['1140', max($dCost, 0), max(-$dCost, 0), 'WIP adjustment'],
            ['1130', max(-$dMat, 0), max($dMat, 0), 'Raw material adjustment'],
            ['2160', max(-$dLab, 0), max($dLab, 0), 'Direct labor adjustment'],
            ['2170', max(-$dOv, 0), max($dOv, 0), 'Factory overhead adjustment'],
        ], $_SESSION['user_id']);
    }
    if ($dCost != 0.0) {
        post_accounting_journal($productionNo . $suffix . '-COMPLETE', date('Y-m-d'), 'Adjustment: production ' . $productionNo . ' edited (WIP to finished goods)', $productionNo, [
            ['1150', max($dCost, 0), max(-$dCost, 0), 'Finished goods adjustment'],
            ['1140', max(-$dCost, 0), max($dCost, 0), 'WIP adjustment'],
        ], $_SESSION['user_id']);
    }

    $pdo->commit();
    log_activity('update', 'production', $id, "{$productionNo} edited; good {$oldGood}->{$good}; cost {$oldCost}->{$newCost}; unit cost {$unitCostBlock}; curing {$curingDays} days; ready {$readyDate}");
    set_flash('success', "Production {$productionNo} updated. New total cost: " . currency_symbol() . money($newCost) . '; cost per good block: ' . currency_symbol() . number_format($unitCostBlock, 4) . '. Ready for sale: ' . format_date($readyDate) . '.');
    header('Location: view.php?id=' . $id);
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', $error->getMessage());
    header('Location: ' . $back);
    exit;
}
