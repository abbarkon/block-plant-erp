<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

// Completion tracking for maintenance requests raised from asset returns.
try {
    $mColumns = array_column($pdo->query('SHOW COLUMNS FROM asset_maintenance')->fetchAll(), 'Field');
    if (!in_array('status', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed'");
        try { $pdo->exec("UPDATE asset_maintenance SET status = 'pending' WHERE source = 'asset_return'"); } catch (Throwable $ignored) {}
    }
    if (!in_array('completed_at', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN completed_at DATETIME NULL");
    }
    if (!in_array('completion_notes', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN completion_notes TEXT NULL");
    }
    if (!in_array('source', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN source VARCHAR(50) DEFAULT 'manual'");
    }
    if (!in_array('employee_asset_id', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN employee_asset_id INT NULL");
    }
    if (!in_array('priority', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
    if (!in_array('assigned_to', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN assigned_to INT NULL");
    }
    if (!in_array('due_date', $mColumns, true)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN due_date DATE NULL");
    }
    // Allow asset_id to be NULL for manual asset returns
    try {
        $pdo->exec("ALTER TABLE asset_maintenance MODIFY asset_id INT NULL");
    } catch (Throwable $ignored) {}
    // Repair completed manual return requests that were previously reset to pending.
    try {
        $pdo->exec("UPDATE asset_maintenance
            SET status = 'completed'
            WHERE source = 'asset_return'
              AND status <> 'completed'
              AND (completed_at IS NOT NULL OR (completion_notes IS NOT NULL AND completion_notes <> ''))");
    } catch (Throwable $ignored) {}

    // Older versions auto-completed return requests. Reopen only rows with no
    // completion evidence; rows with a completion date or notes stay completed.
    try {
        $pdo->exec("UPDATE asset_maintenance
            SET status = 'pending'
            WHERE source = 'asset_return'
              AND status = 'completed'
              AND completed_at IS NULL
              AND (completion_notes IS NULL OR completion_notes = '')");
    } catch (Throwable $ignored) {}

    // Normalise only untouched old rows; never reopen a completed request.
    try {
        $pdo->exec("UPDATE asset_maintenance am
            JOIN employee_assets ea ON ea.id = am.employee_asset_id
            SET am.asset_id = NULL,
                am.status = 'pending',
                am.priority = COALESCE(am.priority, 'high')
            WHERE am.source = 'asset_return'
              AND (am.asset_id = 0 OR am.asset_id IS NULL)
              AND am.status = 'pending'
              AND am.completed_at IS NULL
              AND (am.completion_notes IS NULL OR am.completion_notes = '')");
    } catch (Throwable $ignored) {}
} catch (Throwable $e) {
    // Table might not exist yet
}

$types = ['Preventive', 'Corrective', 'Emergency', 'Inspection', 'Repair', 'Calibration'];
$statuses = ['pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_record') {
        $recordId = (int) ($_POST['id'] ?? 0);
        require_permission('assets', $recordId > 0 ? 'edit' : 'create');

        $assetId = (int) ($_POST['asset_id'] ?? 0);
        $date = trim($_POST['maintenance_date'] ?? '');
        $type = $_POST['maintenance_type'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $costRaw = trim($_POST['cost'] ?? '0');
        $performedBy = trim($_POST['performed_by'] ?? '');
        $next = trim($_POST['next_maintenance_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $errors = [];

        $assetCheck = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE id = ?');
        $assetCheck->execute([$assetId]);
        if ((int) $assetCheck->fetchColumn() === 0) {
            $errors[] = 'Choose a valid asset.';
        }
        if (!is_valid_date_string($date)) {
            $errors[] = 'Enter a valid maintenance date.';
        } elseif ($date > date('Y-m-d')) {
            $errors[] = 'Maintenance date cannot be in the future.';
        }
        if (!in_array($type, $types, true)) {
            $errors[] = 'Choose a valid maintenance type.';
        }
        if ($description === '' || mb_strlen($description) > 2000) {
            $errors[] = 'Description is required (maximum 2,000 characters).';
        }
        if ($costRaw === '' || !is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 999999999.99) {
            $errors[] = 'Cost must be zero or a positive amount below 1,000,000,000.';
        }
        if (mb_strlen($performedBy) > 100) {
            $errors[] = 'Performed by must be 100 characters or fewer.';
        }
        if ($next !== '') {
            if (!is_valid_date_string($next)) {
                $errors[] = 'Enter a valid next maintenance date.';
            } elseif (is_valid_date_string($date) && $next < $date) {
                $errors[] = 'Next maintenance date cannot be before the maintenance date.';
            }
        }
        if (mb_strlen($notes) > 2000) {
            $errors[] = 'Notes must be 2,000 characters or fewer.';
        }

        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: maintenance.php' . ($assetId ? '?asset_id=' . $assetId : ''));
            exit;
        }

        try {
            $cost = round((float) $costRaw, 2);
            if ($recordId > 0) {
                $exists = $pdo->prepare('SELECT id FROM asset_maintenance WHERE id = ?');
                $exists->execute([$recordId]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Maintenance record not found.');
                }
                $pdo->prepare("UPDATE asset_maintenance SET asset_id = ?, maintenance_date = ?, maintenance_type = ?, description = ?, cost = ?, performed_by = ?, next_maintenance_date = ?, notes = ? WHERE id = ?")
                    ->execute([$assetId, $date, $type, $description, $cost, $performedBy ?: null, $next ?: null, $notes ?: null, $recordId]);
                log_activity('update', 'assets', $recordId, "Maintenance record updated for asset #{$assetId}");
                set_flash('success', 'Maintenance record updated.');
            } else {
                $pdo->prepare("INSERT INTO asset_maintenance(asset_id, maintenance_date, maintenance_type, description, cost, performed_by, next_maintenance_date, notes, created_by, source, status, completed_at) VALUES(?,?,?,?,?,?,?,?,?,'manual','completed',NOW())")
                    ->execute([$assetId, $date, $type, $description, $cost, $performedBy ?: null, $next ?: null, $notes ?: null, $_SESSION['user_id']]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('create', 'assets', $newId, "Maintenance recorded for asset #{$assetId}: {$type}");
                set_flash('success', 'Maintenance record added.');
            }
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
        }
        header('Location: maintenance.php?asset_id=' . $assetId);
        exit;
    }

    if ($action === 'complete') {
        require_permission('assets', 'edit');
        $maintenanceId = (int) ($_POST['maintenance_id'] ?? 0);
        $completionDate = trim($_POST['completion_date'] ?? '');
        $actualCostRaw = trim($_POST['actual_cost'] ?? '');
        $completionNotes = trim($_POST['completion_notes'] ?? '');
        $next = trim($_POST['next_maintenance_date'] ?? '');

        $errors = [];
        if (!is_valid_date_string($completionDate)) {
            $errors[] = 'Choose a valid completion date.';
        } elseif ($completionDate > date('Y-m-d')) {
            $errors[] = 'Completion date cannot be in the future.';
        }
        if ($actualCostRaw !== '' && (!is_numeric($actualCostRaw) || (float) $actualCostRaw < 0 || (float) $actualCostRaw > 999999999.99)) {
            $errors[] = 'Actual cost must be zero or a positive amount.';
        }
        if ($completionNotes === '' || mb_strlen($completionNotes) > 2000) {
            $errors[] = 'Completion notes are required (maximum 2,000 characters).';
        }
        if ($next !== '') {
            if (!is_valid_date_string($next)) {
                $errors[] = 'Choose a valid next maintenance date.';
            } elseif (is_valid_date_string($completionDate) && $next < $completionDate) {
                $errors[] = 'Next maintenance date cannot be before the completion date.';
            }
        }
        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: maintenance.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT * FROM asset_maintenance WHERE id = ? AND status = 'pending' FOR UPDATE");
            $lock->execute([$maintenanceId]);
            $request = $lock->fetch();
            if (!$request) {
                throw new RuntimeException('Maintenance request not found or already completed.');
            }
            $costSql = $actualCostRaw !== '' ? round((float) $actualCostRaw, 2) : (float) $request['cost'];
            $pdo->prepare("UPDATE asset_maintenance SET status = 'completed', completed_at = NOW(), completion_notes = ?, cost = ?, next_maintenance_date = COALESCE(?, next_maintenance_date) WHERE id = ?")
                ->execute([$completionNotes, $costSql, $next ?: null, $maintenanceId]);

            $reissueManualAsset = !array_key_exists('reissue_manual_asset', $_POST)
                || (int) $_POST['reissue_manual_asset'] === 1;
            $manualAssetReissued = false;
            $manualAssetInactive = false;
            $manualAssetConflict = false;
            $manualAssetOpenMaintenance = 0;

            if ($reissueManualAsset && !empty($request['employee_asset_id'])) {
                $assetLock = $pdo->prepare("SELECT ea.*, e.status AS employee_status
                    FROM employee_assets ea
                    LEFT JOIN employees e ON e.id = ea.employee_id
                    WHERE ea.id = ? FOR UPDATE");
                $assetLock->execute([(int) $request['employee_asset_id']]);
                $employeeAsset = $assetLock->fetch();

                if ($employeeAsset
                    && empty($employeeAsset['fixed_asset_id'])
                    && $employeeAsset['status'] === 'returned') {
                    if ((int) ($employeeAsset['employee_status'] ?? 0) !== 1) {
                        $manualAssetInactive = true;
                    } else {
                        $serial = trim((string) ($employeeAsset['serial_number'] ?? ''));
                        if ($serial !== '') {
                            $conflict = $pdo->prepare("SELECT id FROM employee_assets WHERE serial_number = ? AND status = 'issued' AND id <> ? LIMIT 1");
                            $conflict->execute([$serial, (int) $employeeAsset['id']]);
                            $manualAssetConflict = (bool) $conflict->fetchColumn();

                            if (!$manualAssetConflict) {
                                $otherOpenMaintenanceId = open_maintenance_request_for_serial($serial, $maintenanceId);
                                if ($otherOpenMaintenanceId) {
                                    $manualAssetOpenMaintenance = $otherOpenMaintenanceId;
                                }
                            }
                        }

                        if (!$manualAssetConflict && !$manualAssetOpenMaintenance) {
                            $existingNotes = trim((string) ($employeeAsset['notes'] ?? ''));
                            $reissueNote = 'Maintenance completed on ' . $completionDate . '; asset returned to service.';
                            $newNotes = $existingNotes !== '' ? $existingNotes . "\n" . $reissueNote : $reissueNote;
                            $reissue = $pdo->prepare("UPDATE employee_assets
                                SET status = 'issued', return_date = NULL, condition_returned = NULL, notes = ?
                                WHERE id = ? AND status = 'returned'");
                            $reissue->execute([$newNotes, (int) $employeeAsset['id']]);
                            if ($reissue->rowCount() === 1) {
                                $manualAssetReissued = true;
                                log_activity('update', 'hr', (int) $employeeAsset['id'], 'Manual asset returned to service after maintenance completion');
                            }
                        }
                    }
                }
            }

            log_activity('complete', 'assets', $maintenanceId, "Maintenance request #{$maintenanceId} completed");
            $pdo->commit();

            $message = 'Maintenance marked as completed.';
            if ($manualAssetReissued) {
                $message .= ' The manual asset is now marked Issued to the employee.';
            } elseif ($manualAssetInactive) {
                $message .= ' The manual asset remains Returned because the employee is inactive.';
            } elseif ($manualAssetConflict) {
                $message .= ' The manual asset remains Returned because another asset with the same serial number is already issued.';
            } elseif ($manualAssetOpenMaintenance) {
                $message .= ' The manual asset remains Returned because maintenance request #' . (int) $manualAssetOpenMaintenance . ' is still open for the same serial number.';
            }
            set_flash('success', $message);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('danger', $e->getMessage());
        }
        header('Location: maintenance.php');
        exit;
    }

    if ($action === 'assign') {
        require_permission('assets', 'edit');
        $maintenanceId = (int) ($_POST['maintenance_id'] ?? 0);
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        try {
            $pdo->prepare("UPDATE asset_maintenance SET assigned_to = ? WHERE id = ?")->execute([$assignedTo ?: null, $maintenanceId]);
            set_flash('success', 'Maintenance request assigned.');
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
        }
        header('Location: maintenance.php');
        exit;
    }

    if ($action === 'delete') {
        require_permission('assets', 'delete');
        $recordId = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT asset_id, maintenance_type FROM asset_maintenance WHERE id = ?');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM asset_maintenance WHERE id = ?')->execute([$recordId]);
            log_activity('delete', 'assets', $recordId, "Maintenance record deleted ({$row['maintenance_type']})");
            set_flash('success', 'Maintenance record deleted.');
        }
        header('Location: maintenance.php' . (!empty($row['asset_id']) ? '?asset_id=' . (int) $row['asset_id'] : ''));
        exit;
    }

    header('Location: maintenance.php');
    exit;
}

$assetId = (int) ($_GET['asset_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);
$editRecord = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_maintenance WHERE id = ?');
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();
    if (!$editRecord) {
        render_not_found('Maintenance record not found', 'That maintenance record does not exist.', '/block_plant/assets/maintenance.php', 'Back to maintenance');
    }
    if (!has_permission('assets', 'edit')) {
        set_flash('danger', 'You do not have permission to edit maintenance records.');
        header('Location: maintenance.php');
        exit;
    }
    $assetId = (int) $editRecord['asset_id'];
}

$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, $types, true)) {
    $typeFilter = '';
}
$statusFilter = $_GET['status'] ?? '';
if (!isset($statuses[$statusFilter])) {
    $statusFilter = '';
}
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if (!is_valid_date_string($from)) {
    $from = '';
}
if (!is_valid_date_string($to)) {
    $to = '';
}
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$assets = $pdo->query("SELECT id, asset_code, name, status FROM assets ORDER BY name")->fetchAll();
$selectedAsset = null;
foreach ($assets as $a) {
    if ((int) $a['id'] === $assetId) {
        $selectedAsset = $a;
        break;
    }
}
if ($assetId > 0 && !$selectedAsset) {
    $assetId = 0;
}

$where = ['1=1'];
$params = [];
if ($assetId > 0) {
    $where[] = '(am.asset_id = ? OR am.employee_asset_id = ?)';
    $params[] = $assetId;
    $params[] = $assetId;
}
if ($typeFilter !== '') {
    $where[] = 'am.maintenance_type = ?';
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = 'am.status = ?';
    $params[] = $statusFilter;
}
if ($from !== '') {
    $where[] = 'am.maintenance_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'am.maintenance_date <= ?';
    $params[] = $to;
}
$select = "SELECT am.*, COALESCE(a.name, ea.asset_name, 'N/A') AS asset_name, a.asset_code,
    ea.fixed_asset_id AS employee_fixed_asset_id, ea.status AS employee_asset_status,
    e.status AS employee_status, u.name AS created_by_name";
try {
    $testCols = array_column($pdo->query('SHOW COLUMNS FROM asset_maintenance')->fetchAll(), 'Field');
    if (in_array('assigned_to', $testCols, true)) {
        $select .= ", au.name AS assigned_to_name";
    }
} catch (Throwable $ignored) {}
$sql = $select . "
    FROM asset_maintenance am
    LEFT JOIN assets a ON a.id = am.asset_id
    LEFT JOIN employee_assets ea ON ea.id = am.employee_asset_id
    LEFT JOIN employees e ON e.id = ea.employee_id
    LEFT JOIN users u ON u.id = am.created_by";
try {
    $testCols = array_column($pdo->query('SHOW COLUMNS FROM asset_maintenance')->fetchAll(), 'Field');
    if (in_array('assigned_to', $testCols, true)) {
        $sql .= " LEFT JOIN users au ON au.id = am.assigned_to";
    }
} catch (Throwable $ignored) {}
$sql .= " WHERE " . implode(' AND ', $where) . "
    ORDER BY am.maintenance_date DESC, am.id DESC
    LIMIT 250";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

try {
    $pendingMaintenance = $pdo->query("SELECT am.*, COALESCE(a.name, ea.asset_name, 'N/A') AS asset_name, a.asset_code,
        ea.fixed_asset_id AS employee_fixed_asset_id, ea.status AS employee_asset_status,
        e.status AS employee_status, e.name AS returned_by_employee, au.name AS assigned_to_name
        FROM asset_maintenance am
        LEFT JOIN assets a ON a.id = am.asset_id
        LEFT JOIN employee_assets ea ON ea.id = am.employee_asset_id
        LEFT JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN users au ON au.id = am.assigned_to
        WHERE am.status = 'pending'
        ORDER BY FIELD(COALESCE(am.priority,'normal'), 'urgent','high','normal','low'), am.maintenance_date ASC, am.id ASC")->fetchAll();
} catch (Throwable $e) {
    $pendingMaintenance = $pdo->query("SELECT am.*, COALESCE(a.name, ea.asset_name, 'N/A') AS asset_name, a.asset_code,
        ea.fixed_asset_id AS employee_fixed_asset_id, ea.status AS employee_asset_status,
        e.status AS employee_status, e.name AS returned_by_employee, au.name AS assigned_to_name
        FROM asset_maintenance am
        LEFT JOIN assets a ON a.id = am.asset_id
        LEFT JOIN employee_assets ea ON ea.id = am.employee_asset_id
        LEFT JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN users au ON au.id = am.assigned_to
        WHERE am.status = 'pending'
        ORDER BY am.maintenance_date ASC, am.id ASC")->fetchAll();
}

$dueSoon = (int) $pdo->query("SELECT COUNT(*) FROM assets a WHERE a.status = 1 AND (
    SELECT am.next_maintenance_date FROM asset_maintenance am
    WHERE am.asset_id = a.id AND am.next_maintenance_date IS NOT NULL
    ORDER BY am.maintenance_date DESC, am.id DESC LIMIT 1
) <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$yearCost = (float) $pdo->query("SELECT COALESCE(SUM(cost), 0) FROM asset_maintenance WHERE YEAR(maintenance_date) = YEAR(CURDATE())")->fetchColumn();
$filteredCost = array_sum(array_map(static fn($r) => (float) $r['cost'], $records));

$formValues = [
    'asset_id' => $editRecord['asset_id'] ?? $assetId,
    'maintenance_date' => $editRecord['maintenance_date'] ?? date('Y-m-d'),
    'maintenance_type' => $editRecord['maintenance_type'] ?? 'Preventive',
    'description' => $editRecord['description'] ?? '',
    'cost' => $editRecord['cost'] ?? '0',
    'performed_by' => $editRecord['performed_by'] ?? '',
    'next_maintenance_date' => $editRecord['next_maintenance_date'] ?? '',
    'notes' => $editRecord['notes'] ?? '',
];

$flash = get_flash();
$pageTitle = 'Asset Maintenance';
require_once "../partials/header.php";
?>

<style>
    .maint-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .maint-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .maint-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #4f4fc9; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .maint-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .maint-kpi strong { display:block; margin-top:4px; color:#1c2250; font-size:1.3rem; }
    .maint-kpi.alert { border-left-color:#b45309; }
    .maint-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:22px; margin-bottom:20px; }
    .maint-card h3 { margin:0 0 16px; color:#1c2250; }
    .maint-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media (max-width: 780px) { .maint-grid { grid-template-columns:1fr; } }
    .maint-grid .full { grid-column:1 / -1; }
    .maint-card label { display:block; font-weight:600; font-size:.82rem; color:#334155; margin-bottom:5px; }
    .maint-card input, .maint-card select, .maint-card textarea { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; background:#fff; }
    .maint-card input:focus, .maint-card select:focus, .maint-card textarea:focus { outline:none; border-color:#7a5cd6; box-shadow:0 0 0 3px rgba(42,157,143,.15); }
    .maint-actions { display:flex; gap:5px; flex-wrap:wrap; }
    .maint-actions form { margin:0; }
    .modal-backdrop { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:9999; align-items:center; justify-content:center; padding:16px; }
    .modal-card { background:#fff; border-radius:14px; max-width:560px; width:100%; padding:26px; max-height:92vh; overflow:auto; box-shadow:0 24px 60px rgba(0,0,0,.3); }
    .modal-card h2 { margin:0 0 18px; color:#1c2250; }
    .modal-card label { display:block; font-weight:600; font-size:.82rem; color:#334155; margin:12px 0 5px; }
    .modal-card input, .modal-card textarea { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
    .modal-card input:focus, .modal-card textarea:focus { outline:none; border-color:#7a5cd6; box-shadow:0 0 0 3px rgba(42,157,143,.15); }
    @media print { .no-print { display:none !important; } body { background:#fff; } .maint-kpi, .maint-card, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>🔧 Asset Maintenance</h1>
        <p style="color:#64748b;margin:6px 0 0;">Record servicing, complete return requests, and plan the next maintenance date.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="index.php">Back to assets</a>
        <a class="btn btn-secondary" href="alerts.php">Maintenance alerts</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="maint-kpis">
    <div class="maint-kpi alert"><span>Pending requests</span><strong><?= number_format(count($pendingMaintenance)) ?></strong></div>
    <div class="maint-kpi alert"><span>Due within 30 days</span><strong><?= number_format($dueSoon) ?></strong></div>
    <div class="maint-kpi"><span>Maintenance cost this year</span><strong><?= e(currency_symbol()) . money($yearCost) ?></strong></div>
    <div class="maint-kpi"><span>Filtered records cost</span><strong><?= e(currency_symbol()) . money($filteredCost) ?></strong></div>
</div>

<?php if ($pendingMaintenance): ?>
<div class="maint-card no-print" style="border-left:4px solid #b45309;">
    <h3>⚠️ Pending maintenance requests (from asset returns)</h3>
    <div class="table-container" style="box-shadow:none;">
        <table class="data-table">
            <thead><tr><th>Asset</th><th>Type/Priority</th><th>Requested</th><th>Due</th><th>Assigned</th><th>Description</th><th>Returned by</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($pendingMaintenance as $pm): ?>
                <?php
                $canReissueManual = !empty($pm['employee_asset_id'])
                    && empty($pm['employee_fixed_asset_id'])
                    && (int) ($pm['employee_status'] ?? 0) === 1
                    && ($pm['employee_asset_status'] ?? '') === 'returned';
                ?>
                <tr>
                    <td><?= e($pm['asset_name'] ?: 'N/A') ?></td>
                    <td><?= e($pm['maintenance_type']) ?> <span class="badge badge-<?= ($pm['priority']==='urgent'?'danger':($pm['priority']==='high'?'warning':($pm['priority']==='low'?'muted':'info'))) ?>" style="margin-left:4px;"><?= e(ucfirst($pm['priority']??'normal')) ?></span></td>
                    <td style="white-space:nowrap;"><?= e(format_date($pm['maintenance_date'])) ?></td>
                    <td style="white-space:nowrap;"><?= !empty($pm['due_date']) ? e(format_date($pm['due_date'])) : '—' ?></td>
                    <td><?= e($pm['assigned_to_name'] ?? ($pm['assigned_to'] ? $pm['assigned_to'] : '—')) ?></td>
                    <td><?= e($pm['description']) ?></td>
                    <td><?= e($pm['returned_by_employee'] ?: '—') ?></td>
                    <td>
                        <?php if (has_permission('assets', 'edit')): ?>
                        <button type="button" class="btn btn-sm btn-accent" onclick="openCompleteModal(<?= (int) $pm['id'] ?>, <?= e(json_encode($pm['asset_name'] ?: 'Asset')) ?>, <?= e(json_encode($pm['next_maintenance_date'] ?? '')) ?>, <?= $canReissueManual ? 'true' : 'false' ?>)">Complete</button>
                        <form method="post" action="maintenance.php" style="display:inline;">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="maintenance_id" value="<?= (int)$pm['id'] ?>">
                            <input type="hidden" name="assigned_to" value="<?= (int)($_SESSION['user_id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Assign to me</button>
                        </form>
                        <?php else: ?>
                        <span style="color:#64748b;font-size:.82rem;">Awaiting technician</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="maint-card no-print">
    <h3><?= $editRecord ? 'Edit maintenance record' : 'Add maintenance record' ?></h3>
    <?php if (has_permission('assets', $editRecord ? 'edit' : 'create')): ?>
    <form method="post">
        <input type="hidden" name="action" value="save_record">
        <input type="hidden" name="id" value="<?= $editRecord ? (int) $editRecord['id'] : 0 ?>">
        <div class="maint-grid">
            <div>
                <label for="asset_id">Asset *</label>
                <select id="asset_id" name="asset_id" required>
                    <option value="">Select asset</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $formValues['asset_id'] === (int) $a['id'] ? 'selected' : '' ?>>
                        <?= e($a['name'] . ' (' . $a['asset_code'] . ')' . ((int) $a['status'] === 0 ? ' — inactive' : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="maintenance_date">Maintenance date *</label>
                <input type="date" id="maintenance_date" name="maintenance_date" max="<?= date('Y-m-d') ?>" required value="<?= e($formValues['maintenance_date']) ?>">
            </div>
            <div>
                <label for="maintenance_type">Type *</label>
                <select id="maintenance_type" name="maintenance_type" required>
                    <?php foreach ($types as $type): ?>
                    <option value="<?= e($type) ?>" <?= $formValues['maintenance_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cost">Cost (<?= e(currency_symbol()) ?>)</label>
                <input type="number" id="cost" name="cost" min="0" max="999999999.99" step="0.01" value="<?= e($formValues['cost']) ?>">
            </div>
            <div>
                <label for="performed_by">Performed by</label>
                <input type="text" id="performed_by" name="performed_by" maxlength="100" value="<?= e($formValues['performed_by']) ?>" placeholder="Technician or workshop">
            </div>
            <div>
                <label for="next_maintenance_date">Next maintenance date</label>
                <input type="date" id="next_maintenance_date" name="next_maintenance_date" value="<?= e($formValues['next_maintenance_date']) ?>">
            </div>
            <div class="full">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="2" maxlength="2000" required placeholder="What was done or requested?"><?= e($formValues['description']) ?></textarea>
            </div>
            <div class="full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000"><?= e($formValues['notes']) ?></textarea>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;">
            <button type="submit" class="btn btn-accent"><?= $editRecord ? 'Save changes' : 'Add record' ?></button>
            <?php if ($editRecord): ?><a class="btn btn-secondary" href="maintenance.php?asset_id=<?= (int) $assetId ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php else: ?>
    <p style="color:#64748b;">You have view-only access to maintenance records.</p>
    <?php endif; ?>
</div>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <select name="asset_id">
            <option value="">All assets</option>
            <?php foreach ($assets as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $assetId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name'] . ' (' . $a['asset_code'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">All types</option>
            <?php foreach ($types as $type): ?>
            <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
        <input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($assetId || $typeFilter || $statusFilter || $from || $to): ?><a class="btn btn-secondary" href="maintenance.php">Reset</a><?php endif; ?>
    </form>
</div>

<div class="table-container">
    <h3 style="margin:18px 0 10px;"><?= $selectedAsset ? 'Maintenance history: ' . e($selectedAsset['name']) : 'Maintenance records' ?> (<?= number_format(count($records)) ?>)</h3>
    <table class="data-table">
        <thead>
            <tr><th>Date</th><th>Asset</th><th>Type</th><th>Description</th><th style="text-align:right;">Cost</th><th>Performed by</th><th>Next due</th><th>Status</th><th>Completed</th><th class="no-print">Actions</th></tr>
        </thead>
        <tbody>
            <?php if (!$records): ?><tr><td colspan="10">No maintenance records match the selected filters.</td></tr><?php endif; ?>
            <?php foreach ($records as $m): ?>
            <?php
            $st = $m['status'] ?: 'completed';
            $canReissueManual = !empty($m['employee_asset_id'])
                && empty($m['employee_fixed_asset_id'])
                && (int) ($m['employee_status'] ?? 0) === 1
                && ($m['employee_asset_status'] ?? '') === 'returned';
            ?>
            <tr>
                <td style="white-space:nowrap;"><?= e(format_date($m['maintenance_date'])) ?></td>
                <td><?= e($m['asset_name']) ?><div style="font-size:.75rem;color:#64748b;"><?= e($m['asset_code']) ?></div></td>
                <td><?= e($m['maintenance_type']) ?></td>
                <td style="max-width:280px;"><?= e($m['description']) ?><?php if ($m['completion_notes']): ?><div style="font-size:.78rem;color:#166534;margin-top:3px;">Completed: <?= e($m['completion_notes']) ?></div><?php endif; ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($m['cost']) ?></td>
                <td><?= e($m['performed_by'] ?: '—') ?></td>
                <td style="white-space:nowrap;"><?= $m['next_maintenance_date'] ? e(format_date($m['next_maintenance_date'])) : '—' ?></td>
                <td><span class="badge badge-<?= $st === 'completed' ? 'success' : ($st === 'cancelled' ? 'muted' : 'warning') ?>"><?= e(ucfirst($st)) ?></span></td>
                <td style="white-space:nowrap;"><?= $m['completed_at'] ? e(format_datetime($m['completed_at'])) : '—' ?></td>
                <td class="no-print">
                    <div class="maint-actions">
                        <?php if ($st === 'pending' && has_permission('assets', 'edit')): ?>
                        <button type="button" class="btn btn-sm btn-accent" onclick="openCompleteModal(<?= (int) $m['id'] ?>, <?= e(json_encode($m['asset_name'])) ?>, <?= e(json_encode($m['next_maintenance_date'] ?? '')) ?>, <?= $canReissueManual ? 'true' : 'false' ?>)">Complete</button>
                        <?php endif; ?>
                        <?php if (has_permission('assets', 'edit')): ?>
                        <a class="btn btn-sm btn-secondary" href="maintenance.php?edit=<?= (int) $m['id'] ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (has_permission('assets', 'delete')): ?>
                        <form method="post" onsubmit="return confirm('Delete this maintenance record?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($records): ?>
        <tfoot><tr style="font-weight:700;background:#f8fafc;"><td colspan="4">TOTAL (<?= number_format(count($records)) ?> records)</td><td style="text-align:right;"><?= e(currency_symbol()) . money($filteredCost) ?></td><td colspan="5"></td></tr></tfoot>
        <?php endif; ?>
    </table>
</div>

<div class="modal-backdrop no-print" id="completeModal">
    <div class="modal-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h2>Complete maintenance</h2>
            <button type="button" onclick="closeCompleteModal()" style="background:none;border:none;font-size:1.6rem;line-height:1;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <p style="color:#64748b;margin:0 0 8px;">Asset: <strong id="completeAssetName" style="color:#1c2250;"></strong></p>
        <form method="post">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="maintenance_id" id="completeMaintenanceId">
            <label for="completion_date">Completion date *</label>
            <input type="date" id="completion_date" name="completion_date" max="<?= date('Y-m-d') ?>" required value="<?= date('Y-m-d') ?>">
            <label for="actual_cost">Actual cost (<?= e(currency_symbol()) ?>)</label>
            <input type="number" id="actual_cost" name="actual_cost" min="0" max="999999999.99" step="0.01" placeholder="Leave blank to keep the original cost">
            <label for="next_maintenance_date_modal">Next maintenance date</label>
            <input type="date" id="next_maintenance_date_modal" name="next_maintenance_date">
            <label for="completion_notes">Completion notes *</label>
            <textarea id="completion_notes" name="completion_notes" rows="3" maxlength="2000" required placeholder="Describe what was done to fix the asset"></textarea>
            <div id="reissueManualAssetWrap" style="display:none;margin-top:12px;padding:10px 12px;background:#ecfdf5;border-left:4px solid #10b981;border-radius:6px;">
                <input type="hidden" name="reissue_manual_asset" value="0">
                <label for="reissueManualAsset" style="display:flex;align-items:flex-start;gap:8px;font-weight:400;margin:0;cursor:pointer;">
                    <input type="checkbox" id="reissueManualAsset" name="reissue_manual_asset" value="1" style="width:auto;margin-top:3px;">
                    <span>Return this repaired manual asset to the employee and mark it <strong>Issued</strong> again.</span>
                </label>
            </div>
            <div style="display:flex;gap:8px;margin-top:18px;">
                <button type="submit" class="btn btn-accent">Mark as completed</button>
                <button type="button" class="btn btn-secondary" onclick="closeCompleteModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCompleteModal(id, assetName, nextDate, canReissueManual) {
    document.getElementById('completeMaintenanceId').value = id;
    document.getElementById('completeAssetName').textContent = assetName || 'Asset';
    document.getElementById('next_maintenance_date_modal').value = nextDate || '';

    const reissueWrap = document.getElementById('reissueManualAssetWrap');
    const reissueCheckbox = document.getElementById('reissueManualAsset');
    if (canReissueManual) {
        reissueWrap.style.display = 'block';
        reissueCheckbox.checked = true;
    } else {
        reissueWrap.style.display = 'none';
        reissueCheckbox.checked = false;
    }

    document.getElementById('completeModal').style.display = 'flex';
}
function closeCompleteModal() {
    document.getElementById('completeModal').style.display = 'none';
}
document.getElementById('completeModal').addEventListener('click', function (event) {
    if (event.target === this) closeCompleteModal();
});
</script>

<?php require_once "../partials/footer.php"; ?>
