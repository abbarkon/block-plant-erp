<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
if (!preg_match($datePattern, $asOf) || !checkdate((int) substr($asOf, 5, 2), (int) substr($asOf, 8, 2), (int) substr($asOf, 0, 4))) {
    $asOf = date('Y-m-d');
}

$lastPostedDate = $pdo->query("SELECT MAX(entry_date) FROM journal_entries WHERE LOWER(status) = 'posted'")->fetchColumn();
$lastPostedDate = $lastPostedDate ?: null;

// The trial balance is built from posted journal lines, not the denormalised
// chart_of_accounts.balance cache, so it always reflects the ledger itself.
$stmt = $pdo->prepare("SELECT a.id, a.account_code, a.account_name, a.account_type,
        COALESCE(m.total_debit, 0) AS total_debit,
        COALESCE(m.total_credit, 0) AS total_credit,
        COALESCE(m.line_count, 0) AS line_count
    FROM chart_of_accounts a
    LEFT JOIN (
        SELECT jl.account_id,
               SUM(jl.debit) AS total_debit,
               SUM(jl.credit) AS total_credit,
               COUNT(*) AS line_count
        FROM journal_entry_lines jl
        JOIN journal_entries je ON je.id = jl.entry_id
        WHERE LOWER(je.status) = 'posted' AND je.entry_date <= ?
        GROUP BY jl.account_id
    ) m ON m.account_id = a.id
    WHERE a.status = 1
    ORDER BY a.account_code");
$stmt->execute([$asOf]);
$accounts = $stmt->fetchAll();

$rows = [];
$debitTotal = 0.0;
$creditTotal = 0.0;
$accountsWithMovement = 0;

foreach ($accounts as $account) {
    $totalDebit = (float) $account['total_debit'];
    $totalCredit = (float) $account['total_credit'];
    $net = $totalDebit - $totalCredit;

    // A debit balance sits in the debit column, a credit balance in the credit
    // column. This keeps the statement balanced even for unusual balances.
    if ($net > 0.005) {
        $debit = $net;
        $credit = 0.0;
    } elseif ($net < -0.005) {
        $debit = 0.0;
        $credit = -$net;
    } else {
        $debit = 0.0;
        $credit = 0.0;
    }

    if ((int) $account['line_count'] > 0) {
        $accountsWithMovement++;
    }

    $debitTotal += $debit;
    $creditTotal += $credit;

    $rows[] = [
        'id' => (int) $account['id'],
        'code' => $account['account_code'],
        'name' => $account['account_name'],
        'type' => (string) $account['account_type'],
        'debit' => $debit,
        'credit' => $credit,
        'movement' => $totalDebit + $totalCredit,
        'line_count' => (int) $account['line_count'],
    ];
}

$difference = $debitTotal - $creditTotal;
$isBalanced = abs($difference) < 0.005;
$hasActivity = $accountsWithMovement > 0;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="trial_balance_' . $asOf . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['As of', $asOf]);
    fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['code'],
            $row['name'],
            ucfirst($row['type']),
            number_format($row['debit'], 2, '.', ''),
            number_format($row['credit'], 2, '.', ''),
        ]);
    }
    fputcsv($out, ['TOTAL', '', '', number_format($debitTotal, 2, '.', ''), number_format($creditTotal, 2, '.', '')]);
    fputcsv($out, ['DIFFERENCE', '', '', number_format(abs($difference), 2, '.', ''), '']);
    fclose($out);
    exit;
}

$flash = get_flash();
$pageTitle = 'Trial Balance';
require_once "../partials/header.php";
?>

<div class="tb-hero">
    <div>
        <span class="tb-eyebrow">FINANCE · LEDGER CONTROL</span>
        <h1>Trial Balance</h1>
        <p>Every active account's posted balance, with debit and credit totals that must agree.</p>
    </div>
    <div class="tb-hero-actions no-print">
        <a class="btn btn-secondary" href="general_ledger.php">General Ledger</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="tb-filter panel no-print">
    <div>
        <label for="as_of">As of date</label>
        <input type="date" id="as_of" name="as_of" value="<?= e($asOf) ?>" required>
    </div>
    <button type="submit" class="btn btn-accent">Apply</button>
    <?php if ($lastPostedDate): ?>
    <a class="tb-quick" href="?as_of=<?= e($lastPostedDate) ?>">All posted (<?= e(format_date($lastPostedDate)) ?>)</a>
    <?php endif; ?>
    <a class="tb-quick" href="?as_of=<?= e(date('Y-m-d')) ?>">Today</a>
    <a class="btn btn-secondary tb-export" href="?export=csv&amp;as_of=<?= e($asOf) ?>">Export CSV</a>
</form>

<div class="tb-summary">
    <div><span>Total debits</span><strong><?= e(currency_symbol()) . money($debitTotal) ?></strong></div>
    <div><span>Total credits</span><strong><?= e(currency_symbol()) . money($creditTotal) ?></strong></div>
    <div><span>Difference</span><strong class="<?= $isBalanced ? 'tb-ok' : 'tb-bad' ?>"><?= e(currency_symbol()) . money(abs($difference)) ?></strong></div>
    <div><span>Status</span><strong class="<?= $isBalanced ? 'tb-ok' : 'tb-bad' ?>"><?= $isBalanced ? 'Balanced' : 'Out of balance' ?></strong></div>
    <div><span>Accounts with activity</span><strong><?= number_format($accountsWithMovement) ?> / <?= number_format(count($rows)) ?></strong></div>
</div>

<?php if (!$isBalanced): ?>
<div class="flash flash-error">
    Debits and credits differ by <?= e(currency_symbol()) . money(abs($difference)) ?>.
    Check posted journal entries for lines that do not balance.
</div>
<?php endif; ?>

<div class="panel tb-panel">
    <div class="tb-panel-head">
        <h3>Balances as of <?= e(format_date($asOf)) ?></h3>
        <span class="tb-count"><?= number_format(count($rows)) ?> active account<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-container">
        <table class="data-table tb-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="tb-num">Debit</th>
                    <th class="tb-num">Credit</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="tb-empty">No active accounts in the chart of accounts.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $isZero = $row['debit'] === 0.0 && $row['credit'] === 0.0; ?>
                <tr class="<?= $isZero ? 'tb-zero' : '' ?>">
                    <td>
                        <?php if ($row['line_count'] > 0): ?>
                        <a href="general_ledger.php?account_id=<?= $row['id'] ?>&amp;from=2000-01-01&amp;to=<?= e($asOf) ?>"><?= e($row['code']) ?></a>
                        <?php else: ?>
                        <?= e($row['code']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['name']) ?></td>
                    <td><span class="tb-type tb-type-<?= e($row['type']) ?>"><?= e(ucfirst($row['type'])) ?></span></td>
                    <td class="tb-num"><?= $row['debit'] > 0 ? e(currency_symbol()) . money($row['debit']) : '—' ?></td>
                    <td class="tb-num"><?= $row['credit'] > 0 ? e(currency_symbol()) . money($row['credit']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Total</th>
                    <th class="tb-num"><?= e(currency_symbol()) . money($debitTotal) ?></th>
                    <th class="tb-num"><?= e(currency_symbol()) . money($creditTotal) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="tb-note">
        Built from posted journal entries dated on or before <?= e(format_date($asOf)) ?>. Draft and void entries are excluded.
        <?php if (!$hasActivity): ?>
        No posted activity falls within this date range.
        <?php endif; ?>
    </p>
</div>

<div class="tb-help no-print">
    <h3>How to read this</h3>
    <p>Each account shows its net posted balance on whichever side it falls. Because every posted journal entry has equal debits
        and credits, the two totals must match. A difference here means an entry was posted without balanced lines — review
        <a href="journal.php">journal entries</a> before running the balance sheet.</p>
</div>

<style>
.tb-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 65%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.tb-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.tb-hero p{margin:0;color:rgba(255,255,255,.76)}
.tb-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.tb-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.tb-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.tb-filter{padding:18px 20px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;margin-bottom:14px}
.tb-filter label{display:block;margin:0 0 5px}
.tb-filter input{margin:0}
.tb-filter .tb-export{margin-left:auto}
.tb-quick{align-self:center;font-size:.82rem;font-weight:700;color:#b8481b;text-decoration:none;border-bottom:1px dashed #9fc3bb}
.tb-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:0 0 18px}
.tb-summary div{padding:15px 17px;background:#fff;border:1px solid #e3ddd4;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.tb-summary span{display:block;font-size:.76rem;color:#6a6259}
.tb-summary strong{display:block;margin-top:5px;font-size:1.18rem;color:#1f2933;font-variant-numeric:tabular-nums}
.tb-summary strong.tb-ok{color:#1d7a4f}
.tb-summary strong.tb-bad{color:#b3452f}
.tb-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.tb-panel-head h3{margin:0}
.tb-count{font-size:.8rem;color:#6a6259;font-weight:700}
.tb-table th.tb-num,.tb-table td.tb-num{text-align:right;font-variant-numeric:tabular-nums}
.tb-table td a{font-weight:700;color:#b8481b}
.tb-table tfoot th{padding:13px 12px;background:#f4efe8;color:#1f2933;text-align:right}
.tb-table tfoot th:first-child{text-align:left}
.tb-zero td{color:#9aa8af}
.tb-empty{text-align:center;padding:34px!important;color:#6a6259}
.tb-type{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:800;background:#eef2f5;color:#4a5f6b}
.tb-type-asset{background:#e6f4ec;color:#1d7a4f}
.tb-type-liability{background:#fdeee9;color:#b3452f}
.tb-type-equity{background:#eef3ff;color:#35558f}
.tb-type-revenue{background:#e0f2f1;color:#b8481b}
.tb-type-expense{background:#fff4e0;color:#a86a12}
.tb-note{margin:14px 0 0;color:#6a6259;font-size:.82rem}
.tb-help{margin-top:16px;padding:20px;background:#f4f7f8;border:1px solid #e3ddd4;border-radius:12px}
.tb-help h3{margin:0 0 8px}
.tb-help p{margin:0;color:#5b6b73;line-height:1.6}
@media(max-width:1000px){.tb-summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.tb-summary{grid-template-columns:repeat(2,1fr)}.tb-filter .tb-export{margin-left:0}}
@media(max-width:600px){.tb-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.tb-hero h1{font-size:1.75rem}.tb-summary{grid-template-columns:1fr 1fr;gap:8px}.tb-filter{align-items:stretch;flex-direction:column}.tb-filter .btn{width:100%}}
@media print{
    .no-print,.tb-hero-actions,.tb-filter,.tb-help,.flash{display:none!important}
    .tb-hero{background:#1f2933!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:none}
    .tb-table{min-width:0}
    .table-container{overflow:visible}
}
</style>

<?php require_once "../partials/footer.php"; ?>
