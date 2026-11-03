<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Asset Issuance";
require_once "../partials/header.php";

// Create employee_assets table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    asset_type VARCHAR(100) NOT NULL,
    serial_number VARCHAR(100),
    description TEXT,
    issue_date DATE NOT NULL,
    condition_issued VARCHAR(50) DEFAULT 'Good',
    status ENUM('issued','returned','damaged','lost') DEFAULT 'issued',
    return_date DATE,
    condition_returned VARCHAR(50),
    notes TEXT,
    issued_by INT,
    fixed_asset_id INT,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    FOREIGN KEY (fixed_asset_id) REFERENCES assets(id)
)");

// Add fixed_asset_id column if it doesn't exist
$columns = $pdo->query("SHOW COLUMNS FROM employee_assets")->fetchAll();
$columnNames = array_column($columns, 'Field');
if (!in_array('fixed_asset_id', $columnNames)) {
    $pdo->exec("ALTER TABLE employee_assets ADD COLUMN fixed_asset_id INT");
}

// Add unique index on serial_number for issued assets (only applies to issued assets)
try {
    $indexes = $pdo->query("SHOW INDEX FROM employee_assets WHERE Key_name = 'unique_serial_issued'")->fetchAll();
    if(empty($indexes)) {
        $pdo->exec("ALTER TABLE employee_assets ADD UNIQUE INDEX unique_serial_issued (serial_number) WHERE status = 'issued'");
    }
} catch (PDOException $e) {
    // Index might already exist or can't be created due to duplicates, continue anyway
}

// Add columns to asset_maintenance table if they don't exist
try {
    $maintenanceColumns = $pdo->query("SHOW COLUMNS FROM asset_maintenance")->fetchAll();
    $maintenanceColumnNames = array_column($maintenanceColumns, 'Field');
    
    if (!in_array('source', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN source VARCHAR(50) DEFAULT 'manual'");
    }
    if (!in_array('employee_asset_id', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN employee_asset_id INT");
    }
    if (!in_array('status', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed'");
    }
    if (!in_array('priority', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
    if (!in_array('assigned_to', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN assigned_to INT NULL");
    }
    if (!in_array('due_date', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN due_date DATE NULL");
    }
    if (!in_array('completed_at', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN completed_at DATETIME NULL");
    }
    if (!in_array('completion_notes', $maintenanceColumnNames)) {
        $pdo->exec("ALTER TABLE asset_maintenance ADD COLUMN completion_notes TEXT NULL");
    }
    try {
        $pdo->exec("UPDATE asset_maintenance SET status = 'pending' WHERE (source = 'asset_return') AND (status = 'completed') AND completed_at IS NULL AND (completion_notes IS NULL OR completion_notes = '')");
    } catch (Throwable $ignored) {}
} catch (PDOException $e) {
    // asset_maintenance table might not exist, continue
}

// Issue asset to employee
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_asset'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to issue assets.');
    }
    $assetSource = $_POST['asset_source'];
    $assetName = '';
    $assetType = '';
    $fixedAssetId = null;
    
    if($assetSource === 'fixed' && !empty($_POST['fixed_asset_id'])) {
        $fixedAssetId = $_POST['fixed_asset_id'];
        // Get fixed asset details
        $stmt = $pdo->prepare("SELECT name, asset_code FROM assets WHERE id=?");
        $stmt->execute([$fixedAssetId]);
        $fixedAsset = $stmt->fetch();
        $assetName = $fixedAsset['name'];
        $assetType = 'Fixed Asset';
        $serialNumber = $fixedAsset['asset_code'];

        $openMaintenanceId = open_maintenance_request_for_fixed_asset((int) $fixedAssetId)
            ?: open_maintenance_request_for_serial($serialNumber);
        if ($openMaintenanceId) {
            $errorMessage = 'This fixed asset is still under maintenance (request #' . $openMaintenanceId . '). Complete or cancel the maintenance request before issuing it again.';
            echo '<script>alert(' . json_encode($errorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "); location.href='asset_issuance.php';</script>";
            exit;
        }

        $issuedFixedStmt = $pdo->prepare("SELECT id FROM employee_assets WHERE fixed_asset_id = ? AND status = 'issued' LIMIT 1");
        $issuedFixedStmt->execute([(int) $fixedAssetId]);
        if ($issuedFixedStmt->fetchColumn()) {
            $errorMessage = 'This fixed asset is already issued. Return it before issuing it again.';
            echo '<script>alert(' . json_encode($errorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "); location.href='asset_issuance.php';</script>";
            exit;
        }
    } else {
        $assetName = $_POST['asset_name'];
        $assetType = $_POST['asset_type'];
        $serialNumber = $_POST['serial_number'] ?: null;
        
        // Check serial number uniqueness for manual entries
        if(!empty($serialNumber)) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_assets WHERE serial_number = ? AND status = 'issued'");
            $checkStmt->execute([$serialNumber]);
            $exists = $checkStmt->fetchColumn();
            
            if($exists > 0) {
                echo "<script>alert('Error: Serial number already exists. Please use a unique serial number.'); location.href='asset_issuance.php';</script>";
                exit;
            }

            $openMaintenanceId = open_maintenance_request_for_serial($serialNumber);
            if ($openMaintenanceId) {
                $errorMessage = 'Serial number ' . $serialNumber . ' is still under maintenance (request #' . $openMaintenanceId . '). Complete or cancel the maintenance request before reissuing it.';
                echo '<script>alert(' . json_encode($errorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "); location.href='asset_issuance.php';</script>";
                exit;
            }
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO employee_assets(employee_id, asset_name, asset_type, serial_number, description, issue_date, condition_issued, status, issued_by, fixed_asset_id) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['employee_id'],
        $assetName,
        $assetType,
        $serialNumber,
        $_POST['description'] ?: null,
        $_POST['issue_date'],
        $_POST['condition_issued'],
        'issued',
        $_SESSION['user_id'],
        $fixedAssetId
    ]);
    
    // Update fixed asset status if linked. assets.status is a tinyint:
    // 1 = active/available, 0 = not available (issued or inactive).
    if($fixedAssetId) {
        $pdo->prepare("UPDATE assets SET status=0 WHERE id=?")->execute([$fixedAssetId]);
    }
    
    echo "<script>alert('Asset issued successfully!'); location.href='asset_issuance.php';</script>";
}

// Return asset
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_asset'])) {
    if (!has_permission('hr', 'edit')) {
        http_response_code(403);
        exit('You do not have permission to return assets.');
    }

    $conditionReturned = trim((string) ($_POST['condition_returned'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $assetId = (int) ($_POST['asset_id'] ?? 0);

    try {
        $result = return_employee_asset($assetId, $conditionReturned, $notes, date('Y-m-d'));
        $message = 'Asset returned successfully in ' . $result['condition'] . ' condition.';
        if ($result['maintenance_created']) {
            $message .= ' A maintenance request was created.';
        }
        echo '<script>alert(' . json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "); location.href='asset_issuance.php';</script>";
    } catch (Throwable $e) {
        echo '<script>alert(' . json_encode($e->getMessage(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . "); location.href='asset_issuance.php';</script>";
    }
    exit;
}

// Get employees
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();

// Get fixed assets for selection
try {
    // Check if assets table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'assets'")->fetchColumn();
    if($tableExists) {
        // Get all assets without status filter first to see if they exist
        $fixedAssets = $pdo->query("SELECT id, name, asset_code, category_id FROM assets ORDER BY name")->fetchAll();
        
        // If we have assets, try to filter by status if available
        if($fixedAssets) {
            $columns = $pdo->query("SHOW COLUMNS FROM assets")->fetchAll();
            $columnNames = array_column($columns, 'Field');
            
            if(in_array('status', $columnNames)) {
                // Find the status column type
                $statusColumn = null;
                foreach($columns as $col) {
                    if($col['Field'] === 'status') {
                        $statusColumn = $col;
                        break;
                    }
                }
                
                // The assets table uses TINYINT for status (1=active, 0=inactive)
                if($statusColumn && (strpos($statusColumn['Type'], 'tinyint') !== false || strpos($statusColumn['Type'], 'int') !== false)) {
                    $fixedAssets = $pdo->query("SELECT id, name, asset_code, category_id FROM assets WHERE status = 1 ORDER BY name")->fetchAll();
                } else {
                    // If status is string type
                    $fixedAssets = $pdo->query("SELECT id, name, asset_code, category_id FROM assets WHERE status = 'active' ORDER BY name")->fetchAll();
                }
            }
        }
    } else {
        $fixedAssets = [];
    }
} catch (PDOException $e) {
    $fixedAssets = [];
    // For debugging - remove in production
    // echo "Error: " . $e->getMessage();
}

// Do not offer fixed assets that are still waiting for repair.
if (!empty($fixedAssets)) {
    try {
        $openMaintenanceAssetIds = $pdo->query(
            "SELECT DISTINCT asset_id FROM asset_maintenance
             WHERE status IN ('pending', 'in_progress') AND asset_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        $openMaintenanceAssetIds = array_map('intval', $openMaintenanceAssetIds);
        if ($openMaintenanceAssetIds) {
            $fixedAssets = array_values(array_filter($fixedAssets, function ($fa) use ($openMaintenanceAssetIds) {
                return !in_array((int) $fa['id'], $openMaintenanceAssetIds, true);
            }));
        }
    } catch (Throwable $ignored) {
        // If the maintenance table is unavailable, keep the existing list.
    }
}

// Open maintenance requests keyed by employee-asset id, for the records list.
$openMaintenanceByEmployeeAsset = [];
try {
    $openMaintenanceRows = $pdo->query(
        "SELECT employee_asset_id, id FROM asset_maintenance
         WHERE status IN ('pending', 'in_progress') AND employee_asset_id IS NOT NULL"
    )->fetchAll();
    foreach ($openMaintenanceRows as $openMaintenanceRow) {
        $openMaintenanceByEmployeeAsset[(int) $openMaintenanceRow['employee_asset_id']] = (int) $openMaintenanceRow['id'];
    }
} catch (Throwable $ignored) {
}

// Get asset records
$assets = $pdo->query("SELECT ea.*, e.name as employee_name, e.employee_code, u.name as issued_by_name FROM employee_assets ea JOIN employees e ON ea.employee_id = e.id LEFT JOIN users u ON ea.issued_by = u.id ORDER BY ea.issue_date DESC LIMIT 50")->fetchAll();

// Get asset types
$assetTypes = ['Laptop', 'Desktop Computer', 'Mobile Phone', 'Tablet', 'Printer', 'Monitor', 'Keyboard', 'Mouse', 'Headset', 'ID Card', 'Uniform', 'Safety Equipment', 'Vehicle', 'Keys', 'Other'];
?>

<h1>📦 Asset Issuance Management</h1>

<!-- Issue Asset Form -->
<div class="panel" style="margin-bottom:20px;">
    <h3>Issue Asset to Employee</h3>
    <form method="post" style="margin-top:15px;">
        <input type="hidden" name="issue_asset" value="1">
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px;">
            <div>
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="">Select Employee</option>
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Source:</label>
                <select name="asset_source" id="assetSource" onchange="toggleAssetSource()" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="manual">Manual Entry</option>
                    <option value="fixed">From Fixed Assets</option>
                </select>
            </div>
            <div id="manualAssetFields">
                <div>
                    <label>Asset Name:</label>
                    <input type="text" name="asset_name" id="manualAssetName" placeholder="e.g., Dell Laptop" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label>Asset Type:</label>
                    <select name="asset_type" id="manualAssetType" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                        <?php foreach($assetTypes as $type): ?>
                        <option value="<?= $type ?>"><?= $type ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="fixedAssetFields" style="display:none;">
                <div>
                    <label>Select Fixed Asset:</label>
                    <select name="fixed_asset_id" id="fixedAssetSelect" onchange="populateFromFixedAsset()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                        <option value="">Select from Fixed Assets</option>
                        <?php if($fixedAssets && count($fixedAssets) > 0): ?>
                        <?php foreach($fixedAssets as $fa): ?>
                        <option value="<?= $fa['id'] ?>" data-name="<?= htmlspecialchars($fa['name']) ?>" data-code="<?= htmlspecialchars($fa['asset_code'] ?? '') ?>"><?= htmlspecialchars($fa['name']) ?> <?= $fa['asset_code'] ? '(' . htmlspecialchars($fa['asset_code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <option value="" disabled>No fixed assets available (<?= count($fixedAssets) ?> found)</option>
                        <?php endif; ?>
                    </select>
                    <small style="display:block;margin-top:6px;color:#64748b;">Assets with an open maintenance request are hidden until the repair is completed.</small>
                </div>
            </div>
            <div>
                <label>Serial Number:</label>
                <input type="text" name="serial_number" placeholder="Optional" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="display:block;margin-top:6px;color:#64748b;">If this asset was returned for repair, enter the same serial number. Reissue is blocked while its maintenance request is open.</small>
            </div>
            <div>
                <label>Issue Date:</label>
                <input type="date" name="issue_date" required value="<?= date('Y-m-d') ?>" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div>
                <label>Condition Issued:</label>
                <select name="condition_issued" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="New">New</option>
                    <option value="Good" selected>Good</option>
                    <option value="Fair">Fair</option>
                    <option value="Used">Used</option>
                </select>
            </div>
        </div>
        <div style="margin-top:15px;">
            <label>Description:</label>
            <textarea name="description" rows="2" placeholder="Asset description, specifications, etc." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:15px;">Issue Asset</button>
    </form>
</div>

<!-- Asset Records -->
<div class="panel">
    <h3>Asset Issuance Records</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Asset Name</th>
                    <th>Type</th>
                    <th>Serial Number</th>
                    <th>Issue Date</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th>Return Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$assets): ?>
                <tr><td colspan="9">No assets issued yet.</td></tr>
                <?php endif; ?>
                <?php foreach($assets as $asset): ?>
                <tr>
                    <td><?= htmlspecialchars($asset['employee_name']) ?> (<?= $asset['employee_code'] ?>)</td>
                    <td><strong><?= htmlspecialchars($asset['asset_name']) ?></strong></td>
                    <td><?= htmlspecialchars($asset['asset_type']) ?></td>
                    <td><?= htmlspecialchars($asset['serial_number'] ?: '-') ?></td>
                    <td><?= date('d M Y', strtotime($asset['issue_date'])) ?></td>
                    <td><?= htmlspecialchars($asset['condition_issued']) ?></td>
                    <td>
                        <?php if($asset['status'] === 'returned' && isset($openMaintenanceByEmployeeAsset[(int) $asset['id']])): ?>
                        <span style="padding:5px 10px; border-radius:5px; background:#fef3c7; color:#92400e;" title="Maintenance request #<?= (int) $openMaintenanceByEmployeeAsset[(int) $asset['id']] ?> is still open">Under maintenance</span>
                        <?php else: ?>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $asset['status'] === 'issued' ? '#d1fae5' : ($asset['status'] === 'returned' ? '#e0e7ff' : ($asset['status'] === 'damaged' ? '#fee2e2' : '#fef3c7')) ?>;">
                            <?= ucfirst($asset['status']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><?= $asset['return_date'] ? date('d M Y', strtotime($asset['return_date'])) : '-' ?></td>
                    <td>
                        <a href="asset_handover_form.php?id=<?= $asset['id'] ?>" target="_blank" class="btn-sm" style="background:#6366f1;">📄 Form</a>
                        <?php if($asset['status'] === 'issued'): ?>
                        <button onclick="returnAsset(<?= $asset['id'] ?>)" class="btn-sm" style="background:#10b981;">Return</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Asset Summary -->
<?php 
$totalIssued = 0;
$totalReturned = 0;
foreach($assets as $asset) {
    if($asset['status'] === 'issued') $totalIssued++;
    if($asset['status'] === 'returned') $totalReturned++;
}
?>
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:20px;">
    <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
        <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($totalIssued) ?></div>
        <div style="color:#667a80; font-size:0.85rem;">Currently Issued</div>
    </div>
    <div style="text-align:center; padding:15px; background:#e0e7ff; border-radius:8px;">
        <div style="font-size:1.3rem; font-weight:bold; color:#6366f1;"><?= number_format($totalReturned) ?></div>
        <div style="color:#667a80; font-size:0.85rem;">Returned</div>
    </div>
</div>

<!-- Return Asset Modal -->
<div id="returnModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Return Asset</h2>
            <button onclick="document.getElementById('returnModal').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="return_asset" value="1">
            <input type="hidden" name="asset_id" id="returnAssetId">
            <div style="margin-bottom:15px;">
                <label>Condition Returned:</label>
                <select name="condition_returned" id="conditionSelect" required onchange="checkMaintenanceWarning()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach (asset_return_conditions() as $condition): ?>
                    <option value="<?= e($condition) ?>"><?= e($condition) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="maintenanceWarning" style="display:none; background:#fef3c7; padding:10px; border-radius:6px; margin-bottom:15px; border-left:4px solid #f59e0b;">
                <strong>⚠️ Maintenance Request:</strong> A maintenance request will be automatically created for this asset.
            </div>
            <div style="margin-bottom:15px;">
                <label>Notes:</label>
                <textarea name="notes" rows="2" placeholder="Any notes about the returned asset" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Confirm Return</button>
        </form>
    </div>
</div>

<script>
function returnAsset(assetId) {
    document.getElementById('returnAssetId').value = assetId;
    document.getElementById('returnModal').style.display = 'flex';
}

function toggleAssetSource() {
    const source = document.getElementById('assetSource').value;
    const manualFields = document.getElementById('manualAssetFields');
    const fixedFields = document.getElementById('fixedAssetFields');
    const manualName = document.getElementById('manualAssetName');
    const manualType = document.getElementById('manualAssetType');
    
    if(source === 'fixed') {
        manualFields.style.display = 'none';
        fixedFields.style.display = 'block';
        manualName.required = false;
        manualType.required = false;
    } else {
        manualFields.style.display = 'block';
        fixedFields.style.display = 'none';
        manualName.required = true;
        manualType.required = true;
    }
}

function populateFromFixedAsset() {
    const select = document.getElementById('fixedAssetSelect');
    const selectedOption = select.options[select.selectedIndex];
    const name = selectedOption.dataset.name;
    const code = selectedOption.dataset.code;
    
    document.getElementById('manualAssetName').value = name;
    document.getElementById('manualAssetName').required = false;
}

function checkMaintenanceWarning() {
    const condition = document.getElementById('conditionSelect').value.toLowerCase();
    const warning = document.getElementById('maintenanceWarning');
    const needsMaintenance = ['poor', 'damaged', 'needs repair', 'not working', 'broken'];
    
    if(needsMaintenance.includes(condition)) {
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

document.addEventListener('click', function(event) {
    const modal = document.getElementById('returnModal');
    if(modal && modal.style.display === 'flex' && event.target === modal) {
        modal.style.display = 'none';
    }
});
</script>

<?php require_once "../partials/footer.php"; ?>
