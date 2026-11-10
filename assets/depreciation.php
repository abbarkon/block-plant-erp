<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'view');

$pageTitle = 'Asset Depreciation';

$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
if (!is_valid_date_string($asOf)) {
    $asOf = date('Y-m-d');
}
$includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
$categoryId = (int) ($_GET['category'] ?? 0);

$sql = "SELECT a.*, ac.name AS category_name, ac.depreciation_rate
    FROM assets a
    JOIN asset_categories ac ON ac.id = a.category_id
    WHERE 1=1";
$params = [];
if (!$includeInactive) {
    $sql .= ' AND a.status = 1';
}
if ($categoryId > 0) {
    $sql .= ' AND a.category_id = ?';
    $params[] = $categoryId;
}
$sql .= ' ORDER BY a.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

$rows = [];
$totals = [
    'cost' => 0.0,
    'salvage' => 0.0,
    'annual' => 0.0,
    'accumulated' => 0.0,
    'book' => 0.0,
    'stored' => 0.0,
    'variance' => 0.0,
];

foreach ($assets as $asset) {
    $calc = calculate_asset_depreciation($asset, $asOf);
    $stored = round((float) $asset['current_value'], 2);
    $variance = round($stored - $calc['book'], 2);

    $totals['cost'] += $calc['cost'];
    $totals['salvage'] += $calc['salvage'];
    $totals['annual'] += $calc['annual'];
    $totals['accumulated'] += $calc['accumulated'];
    $totals['book'] += $calc['book'];
    $totals['stored'] += $stored;
    $totals['variance'] += $variance;

    $rows[] = [
        'asset' => $asset,
        'cost' => $calc['cost'],
        'salvage' => $calc['salvage'],
        'annual' => $calc['annual'],
        'years' => $calc['years'],
        'accumulated' => $calc['accumulated'],
        'book' => $calc['book'],
        'stored' => $stored,
        'variance' => $variance,
        'method' => $calc['method'],
        'fully_depreciated' => $calc['fully_depreciated'],
    ];
}

$categories = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();
$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .dep-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .dep-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .dep-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #4f4fc9; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .dep-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .dep-kpi strong { display:block; margin-top:4px; color:#1c2250; font-size:1.3rem; }
    .dep-kpi.book { border-left-color:#7a5cd6; }
    .dep-kpi.warn { border-left-color:#b45309; }
    .dep-note { color:#64748b; font-size:.8rem; margin-top:10px; }
    .variance-pos { color:#166534; }
    .variance-neg { color:#991b1b; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .dep-kpi, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>📉 Asset Depreciation</h1>
        <p style="color:#64748b;margin:6px 0 0;">Straight-line depreciation calculated to any date, with a comparison to recorded values.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="index.php">Back to assets</a>
        <a class="btn btn-secondary" href="depreciation_export.php?as_of=<?= e($asOf) ?><?= $includeInactive ? '&include_inactive=1' : '' ?><?= $categoryId ? '&category=' . $categoryId : '' ?>">Export CSV</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="filter-section no-print">
    <form method="get" class="filter-form">
        <label style="align-self:center;font-weight:600;font-size:.84rem;">As of</label>
        <input type="date" name="as_of" value="<?= e($asOf) ?>">
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label style="display:flex;align-items:center;gap:6px;align-self:center;font-size:.84rem;">
            <input type="checkbox" name="include_inactive" value="1" <?= $includeInactive ? 'checked' : '' ?> style="width:auto;margin:0;"> Include inactive assets
        </label>
        <button type="submit" class="btn btn-primary">Recalculate</button>
        <a class="btn btn-secondary" href="depreciation.php">Today</a>
    </form>
</div>

<div class="dep-kpis">
    <div class="dep-kpi"><span>Purchase cost</span><strong><?= e(currency_symbol()) . money($totals['cost']) ?></strong><small style="color:#64748b;"><?= number_format(count($rows)) ?> assets</small></div>
    <div class="dep-kpi warn"><span>Accumulated depreciation</span><strong><?= e(currency_symbol()) . money($totals['accumulated']) ?></strong></div>
    <div class="dep-kpi book"><span>Computed book value</span><strong><?= e(currency_symbol()) . money($totals['book']) ?></strong></div>
    <div class="dep-kpi"><span>Recorded value variance</span><strong class="<?= $totals['variance'] >= 0 ? 'variance-pos' : 'variance-neg' ?>"><?= e(currency_symbol()) . money($totals['variance']) ?></strong></div>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Asset</th>
                <th>Category</th>
                <th>Purchased</th>
                <th style="text-align:right;">Cost</th>
                <th style="text-align:right;">Salvage</th>
                <th style="text-align:right;">Years held</th>
                <th>Method</th>
                <th style="text-align:right;">Annual depreciation</th>
                <th style="text-align:right;">Accumulated</th>
                <th style="text-align:right;">Book value</th>
                <th style="text-align:right;">Recorded value</th>
                <th style="text-align:right;">Variance</th>
                <th class="no-print">Journal</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?><tr><td colspan="13">No assets to depreciate for the selected filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <?php $asset = $row['asset']; ?>
            <tr>
                <td><strong><?= e($asset['name']) ?></strong><div style="font-size:.75rem;color:#64748b;"><?= e($asset['asset_code']) ?><?= $row['fully_depreciated'] ? ' · fully depreciated' : '' ?></div></td>
                <td><?= e($asset['category_name']) ?></td>
                <td style="white-space:nowrap;"><?= e(format_date($asset['purchase_date'])) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['cost']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['salvage']) ?></td>
                <td style="text-align:right;"><?= number_format($row['years'], 1) ?></td>
                <td style="font-size:.8rem;"><?= e($row['method']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['annual']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['accumulated']) ?></td>
                <td style="text-align:right;"><strong><?= e(currency_symbol()) . money($row['book']) ?></strong></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($row['stored']) ?></td>
                <td style="text-align:right;" class="<?= $row['variance'] >= 0 ? 'variance-pos' : 'variance-neg' ?>"><?= e(currency_symbol()) . money($row['variance']) ?></td>
                <td class="no-print">
                    <?php if (has_permission('assets', 'create')): ?>
                    <a class="btn btn-sm btn-secondary" href="journal.php?asset_id=<?= (int) $asset['id'] ?>&type=depreciation">Post</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr style="font-weight:700;background:#f8fafc;">
                <td colspan="3">TOTAL</td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['cost']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['salvage']) ?></td>
                <td></td>
                <td></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['annual']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['accumulated']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['book']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['stored']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($totals['variance']) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<p class="dep-note">
    Calculated as of <?= e(format_date($asOf)) ?> using straight-line depreciation over each asset's useful life.
    When useful life is zero, the category rate is used. Accumulated depreciation is capped at the depreciable base (cost less salvage).
    “Recorded value” is the amount stored on the asset and may reflect revaluations or posted depreciation entries; variance compares it to the computed book value.
</p>

<?php require_once "../partials/footer.php"; ?>
