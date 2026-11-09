<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

$pageTitle = 'Balance Sheet';
$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
if (!is_valid_date_string($asOf)) {
    $asOf = date('Y-m-d');
}

$stmt = $pdo->prepare(
    "SELECT coa.id, coa.account_code, coa.account_name, coa.account_type,
            COALESCE(SUM(jel.debit), 0) AS total_debit,
            COALESCE(SUM(jel.credit), 0) AS total_credit
     FROM chart_of_accounts coa
     JOIN journal_entry_lines jel ON jel.account_id = coa.id
     JOIN journal_entries je ON je.id = jel.entry_id
     WHERE je.status = 'posted' AND je.entry_date <= ? AND coa.status = 1
     GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
     ORDER BY coa.account_code"
);
$stmt->execute([$asOf]);
$ledgerRows = $stmt->fetchAll();

$assetRows = [];
$liabilityRows = [];
$equityRows = [];
$revenueTotal = 0.0;
$expenseTotal = 0.0;

foreach ($ledgerRows as $row) {
    $debit = (float) $row['total_debit'];
    $credit = (float) $row['total_credit'];
    $type = $row['account_type'];
    if ($type === 'asset') {
        $net = $debit - $credit;
        if (abs($net) >= 0.005) {
            $assetRows[] = ['account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'balance' => $net];
        }
    } elseif ($type === 'liability') {
        $net = $credit - $debit;
        if (abs($net) >= 0.005) {
            $liabilityRows[] = ['account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'balance' => $net];
        }
    } elseif ($type === 'equity') {
        $net = $credit - $debit;
        if (abs($net) >= 0.005) {
            $equityRows[] = ['account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'balance' => $net];
        }
    } elseif ($type === 'revenue') {
        $revenueTotal += $credit - $debit;
    } elseif ($type === 'expense') {
        $expenseTotal += $debit - $credit;
    }
}

$currentEarnings = round($revenueTotal - $expenseTotal, 2);
if (abs($currentEarnings) >= 0.005) {
    $equityRows[] = [
        'account_code' => '3900',
        'account_name' => 'Current period earnings (ledger, unclosed)',
        'balance' => $currentEarnings,
    ];
}

$totalAssets = array_sum(array_column($assetRows, 'balance'));
$totalLiabilities = array_sum(array_column($liabilityRows, 'balance'));
$totalEquity = array_sum(array_column($equityRows, 'balance'));
$balanceDifference = round($totalAssets - ($totalLiabilities + $totalEquity), 2);

// Show the denormalised cache alongside the ledger so drift is visible.
$cacheRows = $pdo->query("SELECT account_type, COALESCE(SUM(balance), 0) AS total FROM chart_of_accounts WHERE status = 1 GROUP BY account_type")->fetchAll();
$cache = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
foreach ($cacheRows as $row) {
    if (isset($cache[$row['account_type']])) {
        $cache[$row['account_type']] = (float) $row['total'];
    }
}

require_once "../partials/header.php";
?>

<style>
    .bs-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width:950px) { .bs-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .bs-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .bs-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .bs-kpi strong { display:block; margin-top:4px; color:#1f2933; font-size:1.3rem; }
    .bs-kpi.equity { border-left-color:#d9742f; }
    .bs-kpi.check { border-left-color:#b45309; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .bs-kpi, .panel { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>Balance Sheet</h1>
        <p style="color:#64748b; margin:6px 0 0;">Assets, liabilities and equity derived from posted journal entries as of <?= e(format_date($asOf)) ?>.</p>
    </div>
    <div class="header-actions no-print">
        <a href="export_csv.php?type=balance_sheet&as_of=<?= e($asOf) ?>" class="btn btn-secondary">Export CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ Print</button>
        <a href="financial.php" class="btn btn-secondary">Financial Reports</a>
    </div>
</div>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div class="grid-2">
        <div>
            <label>As of</label>
            <input type="date" name="as_of" value="<?= e($asOf) ?>">
        </div>
    </div>
    <div style="margin-top:12px; display:flex; gap:8px;">
        <button type="submit" class="btn btn-accent">Refresh</button>
        <a href="balance_sheet.php" class="btn btn-secondary">Today</a>
    </div>
</form>

<div class="bs-kpis">
    <div class="bs-kpi"><span>Total assets</span><strong><?= e(currency_symbol()) . money($totalAssets) ?></strong></div>
    <div class="bs-kpi"><span>Total liabilities</span><strong><?= e(currency_symbol()) . money($totalLiabilities) ?></strong></div>
    <div class="bs-kpi equity"><span>Total equity</span><strong><?= e(currency_symbol()) . money($totalEquity) ?></strong></div>
    <div class="bs-kpi check"><span>Balance check</span><strong><?= e(currency_symbol()) . money(abs($balanceDifference)) ?></strong><small style="color:#64748b;"><?= abs($balanceDifference) < 0.01 ? 'Balanced' : 'Difference' ?></small></div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Assets</h3>
        <table class="data-table">
            <thead><tr><th>Account</th><th style="text-align:right;">Balance</th></tr></thead>
            <tbody>
                <?php if (!$assetRows): ?><tr><td colspan="2">No posted asset balances as of this date.</td></tr><?php endif; ?>
                <?php foreach ($assetRows as $row): ?>
                <tr>
                    <td><?= e($row['account_code'] . ' - ' . $row['account_name']) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($row['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold; background:#f8f9fa;">
                    <td>Total Assets</td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($totalAssets) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h3>Liabilities &amp; Equity</h3>
        <table class="data-table">
            <thead><tr><th>Account</th><th style="text-align:right;">Balance</th></tr></thead>
            <tbody>
                <?php if (!$liabilityRows && !$equityRows): ?><tr><td colspan="2">No posted liability or equity balances as of this date.</td></tr><?php endif; ?>
                <?php foreach ($liabilityRows as $row): ?>
                <tr>
                    <td><?= e($row['account_code'] . ' - ' . $row['account_name']) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($row['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($equityRows as $row): ?>
                <tr>
                    <td><?= e($row['account_code'] . ' - ' . $row['account_name']) ?></td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($row['balance']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:bold; background:#f8f9fa;">
                    <td>Total Liabilities</td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($totalLiabilities) ?></td>
                </tr>
                <tr style="font-weight:bold; background:#f8f9fa;">
                    <td>Total Equity</td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($totalEquity) ?></td>
                </tr>
                <tr style="font-weight:bold; background:#f1f5f9;">
                    <td>Total Liabilities + Equity</td>
                    <td style="text-align:right;"><?= e(currency_symbol()) . money($totalLiabilities + $totalEquity) ?></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:16px; padding:12px 14px; border-radius:8px; background: <?= abs($balanceDifference) < 0.01 ? '#d1fae5' : '#fee2e2' ?>; color: <?= abs($balanceDifference) < 0.01 ? '#065f46' : '#991b1b' ?>; font-weight:600;">
            Balance check: <?= e(currency_symbol()) . money(abs($balanceDifference)) ?>
            <?= abs($balanceDifference) < 0.01 ? 'matching' : 'difference' ?>
        </div>
        <p style="color:#64748b;font-size:.78rem;margin-top:12px;">
            Current period earnings are revenue less expenses from posted entries up to this date. The live account cache
            (assets <?= e(currency_symbol()) . money($cache['asset']) ?>,
            liabilities <?= e(currency_symbol()) . money($cache['liability']) ?>,
            equity <?= e(currency_symbol()) . money($cache['equity']) ?>) may differ from the ledger.
        </p>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
