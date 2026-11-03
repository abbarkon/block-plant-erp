<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$pageTitle = 'Asset Categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        require_permission('assets', $id > 0 ? 'edit' : 'create');
        $name = trim($_POST['name'] ?? '');
        $rateRaw = trim($_POST['depreciation_rate'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Category name is required (maximum 100 characters).';
        }
        if ($rateRaw === '' || !is_numeric($rateRaw) || (float) $rateRaw < 0 || (float) $rateRaw > 100) {
            $errors[] = 'Depreciation rate must be between 0 and 100 percent.';
        }
        if (!$errors) {
            $duplicate = $pdo->prepare("SELECT COUNT(*) FROM asset_categories WHERE name = ? AND id <> ?");
            $duplicate->execute([$name, $id]);
            if ((int) $duplicate->fetchColumn() > 0) {
                $errors[] = 'A category with that name already exists.';
            }
        }
        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: categories.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        $rate = round((float) $rateRaw, 2);
        try {
            if ($id > 0) {
                $exists = $pdo->prepare('SELECT name FROM asset_categories WHERE id = ?');
                $exists->execute([$id]);
                if ($exists->fetchColumn() === false) {
                    throw new RuntimeException('Category not found.');
                }
                $pdo->prepare('UPDATE asset_categories SET name = ?, depreciation_rate = ? WHERE id = ?')->execute([$name, $rate, $id]);
                log_activity('update', 'assets', $id, "Asset category updated: {$name} ({$rate}%)");
                set_flash('success', 'Asset category updated.');
            } else {
                $pdo->prepare('INSERT INTO asset_categories(name, depreciation_rate, status) VALUES(?,?,1)')->execute([$name, $rate]);
                $newId = (int) $pdo->lastInsertId();
                log_activity('create', 'assets', $newId, "Asset category created: {$name} ({$rate}%)");
                set_flash('success', 'Asset category added.');
            }
            header('Location: categories.php');
            exit;
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
            header('Location: categories.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }
    }

    if ($action === 'toggle') {
        require_permission('assets', 'edit');
        $stmt = $pdo->prepare('SELECT name, status FROM asset_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = (int) $row['status'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE asset_categories SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            log_activity('update', 'assets', $id, "Asset category " . ($newStatus ? 'activated' : 'deactivated') . ": {$row['name']}");
            set_flash('success', 'Category ' . ($newStatus ? 'activated' : 'deactivated') . '.');
        }
        header('Location: categories.php');
        exit;
    }

    if ($action === 'delete') {
        require_permission('assets', 'delete');
        $stmt = $pdo->prepare('SELECT name FROM asset_categories WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name !== false) {
            $used = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE category_id = ?');
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                set_flash('danger', 'This category is used by existing assets. Deactivate it instead of deleting it.');
            } else {
                $pdo->prepare('DELETE FROM asset_categories WHERE id = ?')->execute([$id]);
                log_activity('delete', 'assets', $id, "Asset category deleted: {$name}");
                set_flash('success', 'Asset category deleted.');
            }
        }
        header('Location: categories.php');
        exit;
    }

    header('Location: categories.php');
    exit;
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_categories WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

$categories = $pdo->query("SELECT ac.*, COUNT(a.id) AS asset_count, COALESCE(SUM(a.purchase_cost), 0) AS total_cost
    FROM asset_categories ac
    LEFT JOIN assets a ON a.category_id = ac.id
    GROUP BY ac.id
    ORDER BY ac.name")->fetchAll();
$activeCount = 0;
$assetCount = 0;
$totalCost = 0.0;
foreach ($categories as $cat) {
    if ((int) $cat['status'] === 1) {
        $activeCount++;
    }
    $assetCount += (int) $cat['asset_count'];
    $totalCost += (float) $cat['total_cost'];
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .cat-grid { display:grid; grid-template-columns:360px 1fr; gap:20px; align-items:start; }
    @media (max-width: 900px) { .cat-grid { grid-template-columns:1fr; } }
    .cat-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:22px; }
    .cat-card h3 { margin:0 0 14px; color:#183b56; }
    .cat-card label { display:block; font-weight:600; font-size:.84rem; color:#334155; margin:12px 0 5px; }
    .cat-card input { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
    .cat-card input:focus { outline:none; border-color:#2a9d8f; box-shadow:0 0 0 3px rgba(42,157,143,.15); }
    .cat-actions { display:flex; gap:5px; flex-wrap:wrap; }
    .cat-actions form { margin:0; }
    .cat-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
    .cat-kpis div { background:#eaf3f0; border-radius:8px; padding:10px 12px; }
    .cat-kpis span { display:block; color:#64748b; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
    .cat-kpis strong { color:#183b56; font-size:1.05rem; }
</style>

<div class="page-header">
    <div>
        <h1>📁 Asset Categories</h1>
        <p style="color:#64748b;margin:6px 0 0;">Group assets and set default depreciation rates.</p>
    </div>
    <div class="header-actions">
        <a class="btn btn-secondary" href="index.php">Back to assets</a>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="cat-grid">
    <div class="cat-card">
        <h3><?= $edit ? 'Edit category' : 'Add category' ?></h3>
        <?php if (has_permission('assets', $edit ? 'edit' : 'create')): ?>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
            <label for="cat_name">Category name *</label>
            <input type="text" id="cat_name" name="name" maxlength="100" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g., Machinery">
            <label for="cat_rate">Depreciation rate (% per year) *</label>
            <input type="number" id="cat_rate" name="depreciation_rate" min="0" max="100" step="0.01" required value="<?= e($edit['depreciation_rate'] ?? '') ?>" placeholder="10.00">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;">
                <button type="submit" class="btn btn-accent"><?= $edit ? 'Save changes' : 'Add category' ?></button>
                <?php if ($edit): ?><a class="btn btn-secondary" href="categories.php">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php else: ?>
        <p style="color:#64748b;">You have view-only access to asset categories.</p>
        <?php endif; ?>
    </div>

    <div class="cat-card">
        <div class="cat-kpis">
            <div><span>Categories</span><strong><?= number_format(count($categories)) ?></strong></div>
            <div><span>Active</span><strong><?= number_format($activeCount) ?></strong></div>
            <div><span>Assets / cost</span><strong><?= number_format($assetCount) ?> · <?= e(currency_symbol()) . money($totalCost) ?></strong></div>
        </div>
        <table class="data-table">
            <thead>
                <tr><th>Category</th><th style="text-align:right;">Rate</th><th style="text-align:right;">Assets</th><th style="text-align:right;">Purchase cost</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$categories): ?><tr><td colspan="6">No asset categories yet.</td></tr><?php endif; ?>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><strong><?= e($cat['name']) ?></strong></td>
                    <td style="text-align:right;"><?= e(number_format((float) $cat['depreciation_rate'], 2)) ?>%</td>
                    <td style="text-align:right;"><a href="index.php?category=<?= (int) $cat['id'] ?>"><?= number_format((int) $cat['asset_count']) ?></a></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($cat['total_cost']) ?></td>
                    <td><span class="badge <?= (int) $cat['status'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $cat['status'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <div class="cat-actions">
                            <?php if (has_permission('assets', 'edit')): ?>
                            <a class="btn btn-sm btn-secondary" href="categories.php?edit=<?= (int) $cat['id'] ?>">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm"><?= (int) $cat['status'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if (has_permission('assets', 'delete')): ?>
                            <form method="post" onsubmit="return confirm('Delete this category? Categories used by assets cannot be deleted.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="color:#64748b;font-size:.8rem;margin-top:10px;">A category used by any asset cannot be deleted; deactivate it instead. Inactive categories stay attached to existing assets.</p>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
