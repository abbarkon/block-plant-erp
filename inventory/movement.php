<?php
session_start();require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('inventory', 'view');
$pageTitle = "Stock Movement";

// Get date range from query params
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$materialFilter = (int) ($_GET['material_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to)) { $to = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }
$earliest = $pdo->query("SELECT MIN(transaction_date) FROM stock_transactions")->fetchColumn() ?: date('Y-m-01');

// Get stock transactions
$sql = "
    SELECT 
        st.*,
        rm.name as material_name,
        rm.unit
    FROM stock_transactions st
    JOIN raw_materials rm ON st.material_id = rm.id
    WHERE st.transaction_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($materialFilter) {
    $sql .= " AND st.material_id = ?";
    $params[] = $materialFilter;
}
$orderBy = " ORDER BY st.transaction_date DESC, st.created_at DESC";

// Inline CSV export, same convention as sales/production/purchases
if (($_GET['export'] ?? '') === 'csv') {
    $exp = $pdo->prepare($sql . $orderBy);
    $exp->execute($params);
    $out = $exp->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_movement_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Date', 'Material', 'Unit', 'Type', 'Quantity', 'Reference', 'Notes']);
    foreach ($out as $r) {
        fputcsv($fh, [$r['transaction_date'], $r['material_name'], $r['unit'], $r['transaction_type'], $r['quantity'], $r['reference_no'], $r['notes']]);
    }
    fclose($fh);
    exit;
}

$hasMaterialFilter = $materialFilter > 0;
$limitNote = 300;
$stmt = $pdo->prepare($sql . $orderBy . " LIMIT {$limitNote}");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Totals for the selected range (alias the columns — list() on an associative
// fetch ignores string keys and would silently yield NULL)
$totSql = "SELECT SUM(CASE WHEN st.quantity >= 0 THEN st.quantity ELSE 0 END) AS in_qty,
                  SUM(CASE WHEN st.quantity < 0 THEN ABS(st.quantity) ELSE 0 END) AS out_qty
           FROM stock_transactions st
           WHERE st.transaction_date BETWEEN ? AND ?"
        . ($materialFilter ? " AND st.material_id = ?" : "");
$tot = $pdo->prepare($totSql);
$tot->execute($materialFilter ? [$from, $to, $materialFilter] : [$from, $to]);
$totRow = $tot->fetch() ?: [];
$totalIn = (float) ($totRow['in_qty'] ?? 0);
$totalOut = (float) ($totRow['out_qty'] ?? 0);

$materials = $pdo->query("SELECT id, name, unit FROM raw_materials WHERE status=1 ORDER BY name")->fetchAll();

require_once "../partials/header.php";
?>

<style>
@media print {
    .no-print, .navbar, .sidebar, .topbar { display: none !important; }
    .filter-card, .filter-form { display: none !important; }
    body { background: #fff; }
}
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>📊 Stock Movement Report</h1>
        <p style="color: #666; margin: 5px 0 0 0;">Track all inventory transactions</p>
    </div>
    <div class="no-print" style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="?export=csv&from=<?= e($from) ?>&to=<?= e($to) ?>&material_id=<?= $materialFilter ?>">Export CSV</a>
        <a href="materials.php" class="btn" style="background: #6c757d;">← Back to Materials</a>
    </div>
</div>

<!-- Filter Form -->
<div class="filter-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <form method="get" class="filter-form" style="display: grid; grid-template-columns: repeat(4, minmax(0,1fr)) auto; gap: 15px; align-items: end;">
        <div>
            <label>From Date</label>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div>
            <label>To Date</label>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div>
            <label>Material</label>
            <select name="material_id">
                <option value="">All materials</option>
                <?php foreach ($materials as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= $materialFilter === (int) $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['unit']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit" class="btn">Filter</button>
        </div>
    </form>
    <div class="no-print" style="display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; font-size:.85rem; color:#64748b;">
        <span>Quick range:</span>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>&material_id=<?= $materialFilter ?>">This month</a>
        <a href="?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-d', strtotime('last day of last month')) ?>&material_id=<?= $materialFilter ?>">Last month</a>
        <a href="?from=<?= e($earliest) ?>&to=<?= date('Y-m-d') ?>&material_id=<?= $materialFilter ?>">All time (from <?= e(format_date($earliest)) ?>)</a>
    </div>
</div>

<!-- Period totals -->
<div class="stats-grid" style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
    <div style="flex:1; min-width:180px; background:#fff; border-left:4px solid #28a745; padding:16px 18px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <div style="font-size:.8rem; color:#64748b;">In (period)</div>
        <div style="font-size:1.4rem; font-weight:700; color:#1f2933;"><?= number_format((float) $totalIn, 2) ?></div>
    </div>
    <div style="flex:1; min-width:180px; background:#fff; border-left:4px solid #dc3545; padding:16px 18px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <div style="font-size:.8rem; color:#64748b;">Out (period)</div>
        <div style="font-size:1.4rem; font-weight:700; color:#1f2933;"><?= number_format((float) $totalOut, 2) ?></div>
    </div>
</div>

<!-- Transactions Table -->
<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <h2 style="margin: 0 0 15px 0;">Transaction History</h2>

    <?php if (count($transactions) === $limitNote): ?>
    <div class="no-print" style="background:#fff3cd;border-left:4px solid #ffc107;color:#856404;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:.88rem;">
        Showing the <?= $limitNote ?> most recent movements in this range. Narrow the dates or use <strong>Export CSV</strong> for the full list.
    </div>
    <?php endif; ?>
    <?php if (!empty($transactions)): ?>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Material</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Reference</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $trans): ?>
            <?php
                $type_color = '';
                $type_icon = '';
                switch(strtolower($trans['transaction_type'])) {
                    // real values written by purchases/production, plus legacy aliases
                    case 'purchase':
                    case 'purchase_receipt':
                    case 'in':
                        $type_color = '#28a745';
                        $type_icon = '↑';
                        break;
                    case 'production':
                    case 'production_issue':
                    case 'out':
                        $type_color = '#dc3545';
                        $type_icon = '↓';
                        break;
                    case 'adjustment':
                        $type_color = '#ffc107';
                        $type_icon = '⟳';
                        break;
                    case 'opening':
                        $type_color = '#6f42c1';
                        $type_icon = '◆';
                        break;
                    default:
                        $type_color = '#6c757d';
                        $type_icon = '•';
                }
            ?>
            <tr>
                <td><?= date('d M Y', strtotime($trans['transaction_date'])) ?></td>
                <td>
                    <strong><?= htmlspecialchars($trans['material_name']) ?></strong>
                    <small style="color: #666;">(<?= htmlspecialchars($trans['unit']) ?>)</small>
                </td>
                <td>
                    <span style="padding: 5px 10px; border-radius: 5px; background: <?= $type_color ?>; color: white; font-weight: bold; font-size: 11px;">
                        <?= $type_icon ?> <?= strtoupper($trans['transaction_type']) ?>
                    </span>
                </td>
                <td>
                    <strong style="font-size: 16px; color: <?= $type_color ?>;">
                        <?= $trans['quantity'] > 0 ? '+' : '' ?><?= number_format($trans['quantity'], 2) ?>
                    </strong>
                </td>
                <td><?= htmlspecialchars($trans['reference_no'] ?? '-') ?></td>
                <td><?= htmlspecialchars($trans['notes'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align: center; padding: 40px; color: #888;">
        <p style="font-size: 18px; margin: 0 0 10px 0;">📭 No transactions found</p>
        <p style="margin: 0;">Try adjusting the date range or check if any transactions have been recorded.</p>
    </div>
    <?php endif; ?>
</div>

<div style="background: #e7f3ff; padding: 20px; border-radius: 8px; margin-top: 20px;">
    <h3 style="margin: 0 0 10px 0;">📌 Transaction Types</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
        <div>
            <strong style="color: #28a745;">↑ PURCHASE_RECEIPT</strong>
            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">Material received from suppliers</p>
        </div>
        <div>
            <strong style="color: #dc3545;">↓ PRODUCTION_ISSUE</strong>
            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">Material consumed in production</p>
        </div>
        <div>
            <strong style="color: #ffc107;">⟳ ADJUSTMENT</strong>
            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">Stock corrections</p>
        </div>
        <div>
            <strong style="color: #6f42c1;">◆ OPENING</strong>
            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">Opening balance when a material is created</p>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
