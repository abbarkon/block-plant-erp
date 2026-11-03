<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$canEdit = has_permission('hr', 'edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        exit('You do not have permission to clear employee assets.');
    }

    if (isset($_POST['clear_asset'])) {
        $assetId = (int) ($_POST['asset_id'] ?? 0);
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $conditionReturned = trim((string) ($_POST['condition_returned'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        try {
            $result = return_employee_asset(
                $assetId,
                $conditionReturned,
                $notes,
                date('Y-m-d'),
                $employeeId > 0 ? $employeeId : null
            );
            set_flash(
                'success',
                'Asset cleared in ' . $result['condition'] . ' condition.'
                    . ($result['maintenance_created'] ? ' A maintenance request was created.' : '')
            );
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }
        header('Location: asset_clearance.php');
        exit;
    }

    if (isset($_POST['clear_all_assets'])) {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $clearanceDate = trim((string) ($_POST['clearance_date'] ?? date('Y-m-d')));
        $assetIds = $_POST['asset_ids'] ?? [];
        $conditions = is_array($_POST['conditions'] ?? null) ? $_POST['conditions'] : [];
        $notesByAsset = is_array($_POST['notes'] ?? null) ? $_POST['notes'] : [];

        if ($employeeId <= 0 || !is_valid_date_string($clearanceDate) || $clearanceDate > date('Y-m-d')
            || !is_array($assetIds) || !$assetIds) {
            set_flash('error', 'Select a valid employee, clearance date and at least one asset.');
            header('Location: asset_clearance.php');
            exit;
        }

        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $cleared = 0;
        $maintenanceCreated = 0;
        $errors = [];

        foreach ($assetIds as $assetId) {
            if ($assetId <= 0) {
                continue;
            }
            $condition = trim((string) ($conditions[$assetId] ?? ''));
            $notes = trim((string) ($notesByAsset[$assetId] ?? ''));
            try {
                $result = return_employee_asset(
                    $assetId,
                    $condition,
                    $notes,
                    $clearanceDate,
                    $employeeId
                );
                $cleared++;
                if ($result['maintenance_created']) {
                    $maintenanceCreated++;
                }
            } catch (Throwable $e) {
                $errors[] = 'Asset #' . $assetId . ': ' . $e->getMessage();
            }
        }

        $message = $cleared . ' asset' . ($cleared === 1 ? '' : 's') . ' cleared'
            . ($maintenanceCreated > 0
                ? '; ' . $maintenanceCreated . ' maintenance request' . ($maintenanceCreated === 1 ? '' : 's') . ' created'
                : '')
            . '.';
        if ($errors) {
            $message .= ' Not cleared: ' . implode(' ', $errors);
        }
        set_flash($cleared > 0 ? 'success' : 'error', $message);
        header('Location: asset_clearance.php');
        exit;
    }

    header('Location: asset_clearance.php');
    exit;
}

$pageTitle = 'Asset Clearance';
require_once "../partials/header.php";
$flash = get_flash();

// Employees with at least one asset still issued to them.
$employeesWithAssets = $pdo->query(
    "SELECT DISTINCT e.id, e.name, e.employee_code, e.status
     FROM employees e
     JOIN employee_assets ea ON e.id = ea.employee_id
     WHERE ea.status = 'issued'
     ORDER BY e.name"
)->fetchAll();

$clearanceSummary = [];
foreach ($employeesWithAssets as $employee) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) AS pending
         FROM employee_assets
         WHERE employee_id = ?"
    );
    $stmt->execute([$employee['id']]);
    $clearanceSummary[$employee['id']] = $stmt->fetch();
}
?>

<h1>✅ Asset Clearance</h1>

<?php if ($flash): ?>
<div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:6px;margin-bottom:18px;color:#1e3a8a;">
    Asset clearance now records the <strong>returned condition</strong> of each asset, just like Return Asset.
    Poor, damaged, needs-repair, not-working or broken assets automatically raise a high-priority maintenance request.
</div>

<!-- Employee Selection for Clearance -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Select Employee for Clearance</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Total Assets</th>
                    <th>Pending Return</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$employeesWithAssets): ?>
                <tr><td colspan="5">No employees with pending assets.</td></tr>
                <?php endif; ?>
                <?php foreach ($employeesWithAssets as $employee): ?>
                <?php $stats = $clearanceSummary[$employee['id']]; ?>
                <tr>
                    <td><?= e($employee['name']) ?> (<?= e($employee['employee_code']) ?>)</td>
                    <td><?= number_format((int) $stats['total']) ?></td>
                    <td style="color:<?= (int) $stats['pending'] > 0 ? '#f59e0b' : '#10b981' ?>; font-weight:bold;"><?= number_format((int) $stats['pending']) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= (int) $stats['pending'] > 0 ? '#fef3c7' : '#d1fae5' ?>;">
                            <?= (int) $stats['pending'] > 0 ? 'Pending' : 'Cleared' ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" onclick="viewEmployeeAssets(<?= (int) $employee['id'] ?>)" class="btn-sm">View Assets</button>
                        <?php if ($canEdit && (int) $stats['pending'] > 0): ?>
                        <button type="button" onclick="clearAllAssets(<?= (int) $employee['id'] ?>, <?= e(json_encode($employee['name'])) ?>)" class="btn-sm" style="background:#10b981;">Clear Assets</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Employee Asset Details Modal -->
<div id="assetDetailsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:1100px; width:95%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Employee Assets</h2>
            <button type="button" onclick="document.getElementById('assetDetailsModal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div id="assetDetailsContent"></div>
    </div>
</div>

<!-- Clear All Assets Modal -->
<div id="clearAllModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:1050px; width:95%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <div>
                <h2 style="margin:0;">Clear Assets</h2>
                <div id="clearAllEmployeeName" style="color:#64748b;margin-top:4px;"></div>
            </div>
            <button type="button" onclick="document.getElementById('clearAllModal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div id="clearAllContent"></div>
    </div>
</div>

<script>
function viewEmployeeAssets(employeeId) {
    fetch('asset_clearance_ajax.php?employee_id=' + encodeURIComponent(employeeId))
        .then(response => response.text())
        .then(html => {
            document.getElementById('assetDetailsContent').innerHTML = html;
            document.getElementById('assetDetailsModal').style.display = 'flex';
        })
        .catch(() => {
            document.getElementById('assetDetailsContent').innerHTML = '<p>Could not load employee assets.</p>';
            document.getElementById('assetDetailsModal').style.display = 'flex';
        });
}

function clearAllAssets(employeeId, employeeName) {
    document.getElementById('clearAllEmployeeName').textContent = employeeName;
    document.getElementById('clearAllContent').innerHTML = '<p>Loading assets…</p>';
    document.getElementById('clearAllModal').style.display = 'flex';

    fetch('asset_clearance_ajax.php?employee_id=' + encodeURIComponent(employeeId) + '&mode=clear')
        .then(response => response.text())
        .then(html => {
            document.getElementById('clearAllContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('clearAllContent').innerHTML = '<p>Could not load employee assets.</p>';
        });
}

document.addEventListener('click', function(event) {
    const detailsModal = document.getElementById('assetDetailsModal');
    const clearModal = document.getElementById('clearAllModal');

    if (detailsModal && detailsModal.style.display === 'flex' && event.target === detailsModal) {
        detailsModal.style.display = 'none';
    }
    if (clearModal && clearModal.style.display === 'flex' && event.target === clearModal) {
        clearModal.style.display = 'none';
    }
});
</script>

<?php require_once "../partials/footer.php"; ?>
