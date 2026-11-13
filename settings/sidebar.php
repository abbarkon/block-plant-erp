<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'edit');

ensure_sidebar_menu_schema();

$pageTitle = 'Sidebar Menu';
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$lang = app_lang();
$error = '';
$success = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $links = $_POST['links'] ?? [];
    if (!is_array($links)) {
        $links = [];
    }
    $postedKeys = $_POST['all_link_keys'] ?? [];
    if (is_string($postedKeys)) {
        $decoded = json_decode($postedKeys, true);
        $postedKeys = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($postedKeys)) {
        $postedKeys = [];
    }
    if (save_sidebar_visibility($userId, $links, $postedKeys)) {
        log_activity('update', 'settings', 0, 'Sidebar menu visibility updated');
        $success = 'Sidebar menu updated.';
    } else {
        $error = 'Could not save sidebar menu settings.';
    }
}

// Fetch all menu items (master catalog) grouped by module, including hidden.
$allItems = [];
try {
    $rows = $pdo->query("SELECT link_key, module, label_en, label_am, url, icon, parent_key, permission_required, is_visible_by_default, sort_order FROM sidebar_menu_items ORDER BY module, sort_order, link_key")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $allItems[] = $r;
    }
} catch (Throwable $e) {
    $error = 'Could not load menu items: ' . $e->getMessage();
}

// User visibility overrides.
$visibility = [];
try {
    $stmt = $pdo->prepare("SELECT link_key, is_visible, sort_order FROM user_sidebar_visibility WHERE user_id = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $visibility[(string) $r['link_key']] = ['is_visible' => (int) $r['is_visible'], 'sort_order' => (int) $r['sort_order']];
    }
} catch (Throwable $e) {
}

// Usage stats (most used).
$usage = [];
try {
    $rows = $pdo->query("SELECT link_key, click_count FROM sidebar_link_usage ORDER BY click_count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $usage[(string) $r['link_key']] = (int) $r['click_count'];
    }
} catch (Throwable $e) {
}

// Group items by module for display.
$byModule = [];
foreach ($allItems as $item) {
    $mod = $item['module'];
    if (!isset($byModule[$mod])) {
        $byModule[$mod] = [];
    }
    $byModule[$mod][] = $item;
}

// Collect every link key rendered on the form so unchecked boxes
// (which HTML does not submit) are treated as hidden.
$allLinkKeys = [];
foreach ($byModule as $moduleItems) {
    foreach ($moduleItems as $item) {
        $allLinkKeys[] = $item['link_key'];
    }
}

$moduleLabels = [
    'dashboard' => ['Dashboard', 'ዲሽቦርድ'],
    'gate' => ['Gate', 'ዋና ግድያ'],
    'sales' => ['Sales', 'ስልጠና'],
    'crm' => ['CRM', 'CRM'],
    'customers' => ['Customers', 'ደንበኛዎች'],
    'purchases' => ['Purchasing', 'ምርመራ'],
    'suppliers' => ['Suppliers', 'የስልጠናዎች ደንበኛዎች'],
    'production' => ['Production', 'ምርመራ'],
    'inventory' => ['Inventory', 'ክምችት'],
    'hr' => ['HR', 'ሰው መሪዎች'],
    'finance' => ['Finance', 'ፋዋንስ'],
    'assets' => ['Assets', 'ሥራ መለያዎች'],
    'reports' => ['Reports', 'ሪፖርቶች'],
    'settings' => ['Settings', 'መÿ-ቦች'],
    'advanced' => ['Advanced', 'ጥንቃቄ'],
    'shop' => ['Shop', 'መሸጫ'],
];

$flash = get_flash();
require_once "../partials/header.php";
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>⚙️ <?= e(t('sidebar_menu') ?? 'Sidebar Menu') ?></h1>
        <p style="color: #666; margin: 5px 0 0 0;">
            Choose which module links appear in the sidebar. Links marked "most used"
            are based on how often you (or any user) open them. Hidden links are still
            accessible by URL but will not show in the sidebar.
        </p>
    </div>
    <div class="no-print" style="display: flex; gap: 10px;">
        <a href="company.php" class="btn btn-secondary">Back to Settings</a>
    </div>
</div>

<?php if ($flash): ?>
<div style="padding: 12px 16px; margin-bottom: 18px; border-radius: 6px; background: #e7f3ff; color: #0b4a8c; border: 1px solid #bfe3ff;">
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div style="padding: 12px 16px; margin-bottom: 18px; border-radius: 6px; background: #e7f3ff; color: #0b4a8c; border: 1px solid #bfe3ff;">
    <?= e($success) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div style="padding: 12px 16px; margin-bottom: 18px; border-radius: 6px; background: #fbecec; color: #8b1a1a; border: 1px solid #f5c2c2;">
    <?= e($error) ?>
</div>
<?php endif; ?>

<!-- Most used links -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <h2 style="margin: 0 0 15px 0;">🏆 Most Used Sidebar Links</h2>
    <?php if (!$usage): ?>
    <p style="color: #888; padding: 20px 0; text-align: center;">No usage data yet. Links will appear here as they are opened.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:40%;">Link</th>
                <th style="text-align:right;">Times opened</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usage as $linkKey => $count): ?>
            <?php
            $label = '';
            foreach ($allItems as $it) {
                if ($it['link_key'] === $linkKey) {
                    $label = ($lang === 'am' && !empty($it['label_am'])) ? $it['label_am'] : $it['label_en'];
                    break;
                }
            }
            ?>
            <tr>
                <td><?= e($label ?? $linkKey) ?></td>
                <td style="text-align:right;"><strong><?= number_format($count) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Visibility configuration -->
<form method="post" action="sidebar.php">
    <input type="hidden" name="all_link_keys" value="<?= htmlspecialchars(json_encode($allLinkKeys)) ?>">
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h2 style="margin: 0 0 15px 0;">📋 Module Links</h2>
    <p style="color: #666; font-size: 0.9rem; margin-bottom: 15px;">
        Check to show in sidebar. Uncheck to hide. Sort order controls position (lower = higher).
    </p>

    <?php foreach ($byModule as $module => $items): ?>
    <?php
    $modLabel = $moduleLabels[$module][0] ?? ucfirst($module);
    $modLabelAm = $moduleLabels[$module][1] ?? '';
    $displayLabel = ($lang === 'am' && $modLabelAm) ? $modLabelAm : $modLabel;
    ?>
    <h3 style="margin: 20px 0 10px 0; color: #333; border-bottom: 2px solid #eee; padding-bottom: 5px;">
        <?= e($displayLabel) ?>
    </h3>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:34px;"><input type="checkbox" id="select-all-<?= e($module) ?>"></th>
                <th>Link</th>
                <th style="width:150px;">URL</th>
                <th style="text-align:right;width:100px;">Visible</th>
                <th style="text-align:right;width:100px;">Sort order</th>
                <th style="text-align:right;width:120px;">Times used</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <?php
            $key = $item['link_key'];
            $label = ($lang === 'am' && !empty($item['label_am'])) ? $item['label_am'] : $item['label_en'];
            $perm = $item['permission_required'] ?? null;
            $permText = $perm ? ' (requires: ' . e($perm) . ')' : '';
            $vis = $visibility[$key]['is_visible'] ?? (int) $item['is_visible_by_default'];
            $sort = $visibility[$key]['sort_order'] ?? (int) $item['sort_order'];
            $cnt = $usage[$key] ?? 0;
            $checked = $vis ? 'checked' : '';
            $disabled = $perm && !has_permission($perm) ? ' disabled' : '';
            ?>
            <tr<?= $disabled ? ' style="opacity:0.6;"' : '' ?>>
                <td style="text-align:center;">
                    <input type="checkbox" name="links[<?= e($key) ?>][is_visible]" value="1" <?= $checked ?> <?= $disabled ?>>
                </td>
                <td>
                    <strong><?= e($label) ?></strong><?= $permText ? '<br><small style="color:#888;">' . $permText . '</small>' : '' ?>
                    <input type="hidden" name="links[<?= e($key) ?>][link_key]" value="<?= e($key) ?>">
                </td>
                <td style="font-size:0.8rem;color:#666;word-break:break-all;"><?= e($item['url']) ?></td>
                <td style="text-align:right;"><?= $vis ? '<span style="color:#28a745;">●</span> Yes' : '<span style="color:#dc3545;">●</span> No' ?></td>
                <td style="text-align:right;">
                    <input type="number" name="links[<?= e($key) ?>][sort_order]" value="<?= (int) $sort ?>" min="0" style="width:70px;">
                </td>
                <td style="text-align:right;"><?= number_format($cnt) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endforeach; ?>

    <div style="margin-top: 25px; text-align: right;">
        <button type="submit" class="btn" style="background:#e67e22;">Save Sidebar Settings</button>
    </div>
</div>
</form>

<?php require_once "../partials/footer.php"; ?>
