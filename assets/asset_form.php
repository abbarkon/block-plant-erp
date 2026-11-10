<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$asset = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) {
        render_not_found('Asset not found', 'That asset does not exist or has been deleted.', '/block_plant/assets/index.php', 'Back to assets');
    }
}
require_permission('assets', $asset ? 'edit' : 'create');

$errors = [];
$old = [];
$maintenanceStats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['asset_code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $purchaseCostRaw = trim($_POST['purchase_cost'] ?? '');
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $usefulLife = (int) ($_POST['useful_life'] ?? 0);
    $salvageRaw = trim($_POST['salvage_value'] ?? '0');
    $currentRaw = trim($_POST['current_value'] ?? '0');
    $location = trim($_POST['location'] ?? '');
    $condition = $_POST['condition_status'] ?? 'good';
    $notes = trim($_POST['notes'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;

    $old = [
        'asset_code' => $code,
        'name' => $name,
        'category_id' => $categoryId,
        'purchase_date' => $purchaseDate,
        'purchase_cost' => $purchaseCostRaw,
        'supplier_id' => $supplierId,
        'useful_life' => $usefulLife,
        'salvage_value' => $salvageRaw,
        'current_value' => $currentRaw,
        'location' => $location,
        'condition_status' => $condition,
        'notes' => $notes,
        'status' => $status,
    ];

    $categoryCheck = $pdo->prepare("SELECT COUNT(*) FROM asset_categories WHERE id = ? AND (status = 1 OR id = ?)");
    $categoryCheck->execute([$categoryId, $asset['category_id'] ?? 0]);
    $supplierCheck = null;
    if ($supplierId > 0) {
        $supplierCheck = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE id = ?");
        $supplierCheck->execute([$supplierId]);
    }

    if ($code === '' || mb_strlen($code) > 40 || !preg_match('/^[A-Z0-9._-]+$/', $code)) {
        $errors[] = 'Asset code is required, up to 40 characters, using letters, numbers, dots, dashes, or underscores.';
    }
    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Asset name is required (maximum 150 characters).';
    }
    if ((int) $categoryCheck->fetchColumn() === 0) {
        $errors[] = 'Choose an active asset category.';
    }
    if (!is_valid_date_string($purchaseDate)) {
        $errors[] = 'Enter a valid purchase date.';
    } elseif ($purchaseDate > date('Y-m-d')) {
        $errors[] = 'Purchase date cannot be in the future.';
    }
    if ($purchaseCostRaw === '' || !is_numeric($purchaseCostRaw) || (float) $purchaseCostRaw <= 0 || (float) $purchaseCostRaw > 999999999.99) {
        $errors[] = 'Purchase cost must be greater than zero and below 1,000,000,000.';
    }
    if ($supplierId > 0 && (int) $supplierCheck->fetchColumn() === 0) {
        $errors[] = 'The selected supplier does not exist.';
    }
    if ($usefulLife < 1 || $usefulLife > 100) {
        $errors[] = 'Useful life must be between 1 and 100 years.';
    }
    if ($salvageRaw === '' || !is_numeric($salvageRaw) || (float) $salvageRaw < 0 || (float) $salvageRaw > 999999999.99) {
        $errors[] = 'Salvage value must be zero or a positive amount.';
    } elseif ((float) $salvageRaw > (float) $purchaseCostRaw) {
        $errors[] = 'Salvage value cannot exceed the purchase cost.';
    }
    if ($currentRaw === '' || !is_numeric($currentRaw) || (float) $currentRaw < 0 || (float) $currentRaw > 999999999.99) {
        $errors[] = 'Current value must be zero or a positive amount.';
    }
    if (mb_strlen($location) > 150) {
        $errors[] = 'Location must be 150 characters or fewer.';
    }
    if (!in_array($condition, ['good', 'fair', 'poor', 'damaged', 'critical'], true)) {
        $errors[] = 'Choose a valid condition.';
    }
    if (mb_strlen($notes) > 2000) {
        $errors[] = 'Notes must be 2,000 characters or fewer.';
    }

    if (!$errors) {
        $duplicate = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE asset_code = ? AND id <> ?");
        $duplicate->execute([$code, $id]);
        if ((int) $duplicate->fetchColumn() > 0) {
            $errors[] = 'That asset code is already in use.';
        }
    }

    if (!$errors) {
        $purchaseCost = round((float) $purchaseCostRaw, 2);
        $salvage = round((float) $salvageRaw, 2);
        $current = round((float) $currentRaw, 2);
        try {
            $pdo->beginTransaction();
            if ($asset) {
                $pdo->prepare("UPDATE assets SET asset_code = ?, name = ?, category_id = ?, purchase_date = ?, purchase_cost = ?, supplier_id = ?, useful_life = ?, salvage_value = ?, current_value = ?, location = ?, condition_status = ?, notes = ?, status = ? WHERE id = ?")
                    ->execute([$code, $name, $categoryId, $purchaseDate, $purchaseCost, $supplierId ?: null, $usefulLife, $salvage, $current, $location ?: null, $condition, $notes ?: null, $status, $id]);
                log_activity('update', 'assets', $id, "Asset {$code} updated: {$name}");
                $message = "Asset {$code} was updated.";
            } else {
                $pdo->prepare("INSERT INTO assets(asset_code, name, category_id, purchase_date, purchase_cost, supplier_id, useful_life, salvage_value, current_value, location, condition_status, notes, status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$code, $name, $categoryId, $purchaseDate, $purchaseCost, $supplierId ?: null, $usefulLife, $salvage, $current, $location ?: null, $condition, $notes ?: null, $status]);
                $id = (int) $pdo->lastInsertId();
                log_activity('create', 'assets', $id, "Asset {$code} created: {$name}");
                $message = "Asset {$code} was added.";
            }
            $pdo->commit();
            set_flash('success', $message);
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'The asset could not be saved. Please try again.';
        }
    }
}

$categories = $pdo->query("SELECT id, name FROM asset_categories WHERE status = 1" . ($asset ? ' OR id = ' . (int) $asset['category_id'] : '') . " ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status = 1 ORDER BY name")->fetchAll();

$nextNumber = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM assets")->fetchColumn();
$suggestedCode = 'ASSET' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);

$values = [
    'asset_code' => $old['asset_code'] ?? ($asset['asset_code'] ?? $suggestedCode),
    'name' => $old['name'] ?? ($asset['name'] ?? ''),
    'category_id' => $old['category_id'] ?? ($asset['category_id'] ?? ''),
    'purchase_date' => $old['purchase_date'] ?? ($asset['purchase_date'] ?? date('Y-m-d')),
    'purchase_cost' => $old['purchase_cost'] ?? ($asset['purchase_cost'] ?? ''),
    'supplier_id' => $old['supplier_id'] ?? ($asset['supplier_id'] ?? ''),
    'useful_life' => $old['useful_life'] ?? ($asset['useful_life'] ?? 5),
    'salvage_value' => $old['salvage_value'] ?? ($asset['salvage_value'] ?? '0'),
    'current_value' => $old['current_value'] ?? ($asset['current_value'] ?? ($asset['purchase_cost'] ?? '')),
    'location' => $old['location'] ?? ($asset['location'] ?? ''),
    'condition_status' => $old['condition_status'] ?? ($asset['condition_status'] ?? 'good'),
    'notes' => $old['notes'] ?? ($asset['notes'] ?? ''),
    'status' => $old['status'] ?? ($asset['status'] ?? 1),
];

if ($asset) {
    $mStmt = $pdo->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(cost), 0) AS total_cost, MAX(maintenance_date) AS last_date, MIN(next_maintenance_date) AS next_date FROM asset_maintenance WHERE asset_id = ?");
    $mStmt->execute([$id]);
    $maintenanceStats = $mStmt->fetch();
}

$conditions = ['good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'damaged' => 'Damaged', 'critical' => 'Critical'];
$flash = get_flash();
$pageTitle = $asset ? 'Edit Asset' : 'Add Asset';
require_once "../partials/header.php";
?>

<style>
    .asset-form-wrap { max-width:980px; margin:0 auto; }
    .asset-form-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:26px; }
    .asset-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width: 780px) { .asset-form-grid { grid-template-columns:1fr; } }
    .asset-form-grid .full { grid-column:1 / -1; }
    .asset-form-card label { display:block; font-weight:600; font-size:.84rem; color:#334155; margin-bottom:5px; }
    .asset-form-card input[type=text], .asset-form-card input[type=number], .asset-form-card input[type=date], .asset-form-card select, .asset-form-card textarea { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; background:#fff; color:#0f172a; }
    .asset-form-card input:focus, .asset-form-card select:focus, .asset-form-card textarea:focus { outline:none; border-color:#7a5cd6; box-shadow:0 0 0 3px rgba(42,157,143,.15); }
    .asset-form-hint { color:#64748b; font-size:.78rem; margin-top:5px; }
    .asset-errors { border-left:4px solid #b91c1c; background:#fef2f2; color:#7f1d1d; padding:12px 16px; border-radius:8px; margin-bottom:18px; }
    .asset-errors ul { margin:6px 0 0 18px; padding:0; }
    .asset-meta { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
    @media (max-width: 780px) { .asset-meta { grid-template-columns:1fr 1fr; } }
    .asset-meta div { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; }
    .asset-meta span { display:block; color:#64748b; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
    .asset-meta strong { color:#1c2250; font-size:.95rem; }
</style>

<div class="asset-form-wrap">
    <div class="page-header no-print">
        <div>
            <h1><?= $asset ? 'Edit Asset' : 'Add Asset' ?></h1>
            <p style="color:#64748b;margin:6px 0 0;"><?= $asset ? e($asset['asset_code'] . ' · ' . $asset['name']) : 'Register a new fixed asset and its depreciation profile.' ?></p>
        </div>
        <div class="header-actions">
            <a class="btn btn-secondary" href="index.php">Back to assets</a>
        </div>
    </div>

    <?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($errors): ?>
    <div class="asset-errors">
        <strong>Please correct the following:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <?php if ($asset && $maintenanceStats): ?>
    <div class="asset-meta">
        <div><span>Maintenance records</span><strong><?= number_format((int) $maintenanceStats['records']) ?></strong></div>
        <div><span>Maintenance cost</span><strong><?= e(currency_symbol()) . money($maintenanceStats['total_cost']) ?></strong></div>
        <div><span>Last maintenance</span><strong><?= $maintenanceStats['last_date'] ? e(format_date($maintenanceStats['last_date'])) : '—' ?></strong></div>
        <div><span>Next scheduled</span><strong><?= $maintenanceStats['next_date'] ? e(format_date($maintenanceStats['next_date'])) : '—' ?></strong></div>
    </div>
    <?php endif; ?>

    <form method="post" class="asset-form-card">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="asset-form-grid">
            <div>
                <label for="asset_code">Asset code *</label>
                <input type="text" id="asset_code" name="asset_code" maxlength="40" required value="<?= e($values['asset_code']) ?>" placeholder="ASSET0001">
                <p class="asset-form-hint">Letters, numbers, dots, dashes, or underscores. Must be unique.</p>
            </div>
            <div>
                <label for="name">Asset name *</label>
                <input type="text" id="name" name="name" maxlength="150" required value="<?= e($values['name']) ?>" placeholder="e.g., Block making machine">
            </div>

            <div>
                <label for="category_id">Category *</label>
                <select id="category_id" name="category_id" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= (int) $values['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id">
                    <option value="">No supplier</option>
                    <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= (int) $sup['id'] ?>" <?= (int) $values['supplier_id'] === (int) $sup['id'] ? 'selected' : '' ?>><?= e($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="purchase_date">Purchase date *</label>
                <input type="date" id="purchase_date" name="purchase_date" max="<?= date('Y-m-d') ?>" required value="<?= e($values['purchase_date']) ?>">
            </div>
            <div>
                <label for="purchase_cost">Purchase cost (<?= e(currency_symbol()) ?>) *</label>
                <input type="number" id="purchase_cost" name="purchase_cost" min="0.01" max="999999999.99" step="0.01" required value="<?= e($values['purchase_cost']) ?>">
            </div>

            <div>
                <label for="useful_life">Useful life (years) *</label>
                <input type="number" id="useful_life" name="useful_life" min="1" max="100" step="1" required value="<?= e($values['useful_life']) ?>">
                <p class="asset-form-hint">Used for straight-line depreciation when greater than zero.</p>
            </div>
            <div>
                <label for="salvage_value">Salvage value (<?= e(currency_symbol()) ?>)</label>
                <input type="number" id="salvage_value" name="salvage_value" min="0" max="999999999.99" step="0.01" value="<?= e($values['salvage_value']) ?>">
                <p class="asset-form-hint">Cannot exceed the purchase cost.</p>
            </div>

            <div>
                <label for="current_value">Current recorded value (<?= e(currency_symbol()) ?>) *</label>
                <input type="number" id="current_value" name="current_value" min="0" max="999999999.99" step="0.01" required value="<?= e($values['current_value']) ?>">
                <p class="asset-form-hint">Adjust after a revaluation. The depreciation report computes book value separately.</p>
            </div>
            <div>
                <label for="condition_status">Condition</label>
                <select id="condition_status" name="condition_status">
                    <?php foreach ($conditions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $values['condition_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="location">Location</label>
                <input type="text" id="location" name="location" maxlength="150" value="<?= e($values['location']) ?>" placeholder="e.g., Main plant, Hawasa">
            </div>
            <div style="display:flex;align-items:end;padding-bottom:6px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin:0;">
                    <input type="checkbox" name="status" value="1" style="width:auto;margin:0;" <?= !empty($values['status']) ? 'checked' : '' ?>>
                    Active asset
                </label>
            </div>

            <div class="full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000" placeholder="Serial number, warranty, or other details"><?= e($values['notes']) ?></textarea>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;">
            <button type="submit" class="btn btn-accent"><?= $asset ? 'Save changes' : 'Add asset' ?></button>
            <a class="btn btn-secondary" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>
