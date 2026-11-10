<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

$inflowTypes = ['receipt', 'income'];
$outflowTypes = ['payment', 'expense'];

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$typeFilter = strtolower(trim($_GET['type'] ?? ''));
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $from) || !preg_match($datePattern, $to) || $from > $to) {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}
$knownTypes = array_merge($inflowTypes, $outflowTypes);
if (!in_array($typeFilter, $knownTypes, true)) {
    $typeFilter = '';
}

$direction = static function (string $type) use ($inflowTypes): string {
    return in_array($type, $inflowTypes, true) ? 'in' : 'out';
};

// Opening balance is the full history before the range, regardless of the type filter.
$openingStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('receipt','income') THEN amount ELSE -amount END), 0)
     FROM cashbook WHERE transaction_date < ?"
);
$openingStmt->execute([$from]);
$openingBalance = (float) $openingStmt->fetchColumn();

$sql = "SELECT * FROM cashbook WHERE transaction_date BETWEEN ? AND ?";
$params = [$from, $to];
if ($typeFilter !== '') {
    $sql .= " AND transaction_type = ?";
    $params[] = $typeFilter;
}
$sql .= " ORDER BY transaction_date, id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$runningBalance = $openingBalance;
$totalIn = 0.0;
$totalOut = 0.0;
$lines = [];
foreach ($transactions as $txn) {
    $amount = (float) $txn['amount'];
    $dir = $direction((string) $txn['transaction_type']);
    if ($dir === 'in') {
        $totalIn += $amount;
        $runningBalance += $amount;
    } else {
        $totalOut += $amount;
        $runningBalance -= $amount;
    }
    $txn['direction'] = $dir;
    $txn['balance'] = $runningBalance;
    $lines[] = $txn;
}
$closingBalance = $runningBalance;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cashbook_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Direction', 'Reference', 'Description', 'Payment method', 'Money in', 'Money out', 'Running balance', 'Recorded by']);
    foreach ($lines as $txn) {
        fputcsv($out, [
            $txn['transaction_date'],
            $txn['transaction_type'],
            $txn['direction'] === 'in' ? 'Inflow' : 'Outflow',
            $txn['reference_no'] ?? '',
            $txn['description'] ?? '',
            $txn['payment_method'],
            $txn['direction'] === 'in' ? $txn['amount'] : '',
            $txn['direction'] === 'out' ? $txn['amount'] : '',
            number_format((float) $txn['balance'], 2, '.', ''),
            $txn['created_by'] ?? '',
        ]);
    }
    fputcsv($out, ['TOTAL', '', '', '', '', '', number_format($totalIn, 2, '.', ''), number_format($totalOut, 2, '.', ''), number_format($closingBalance, 2, '.', ''), '']);
    fclose($out);
    exit;
}

$pageTitle = "Cash Book";
require_once "../partials/header.php";
$cur = currency_symbol();
?>

<div class="cash-hero">
    <div>
        <span class="cash-eyebrow">FINANCE · DAILY CASH MOVEMENT</span>
        <h1>💵 Cash Book</h1>
        <p>Every cash-in and cash-out entry with a running balance for the selected period.</p>
    </div>
    <div class="cash-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-accent" href="?export=csv&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;type=<?= e($typeFilter) ?>">Export CSV</a>
    </div>
</div>

<form method="get" class="cash-filter panel no-print">
    <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>" required></div>
    <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>" required></div>
    <div>
        <label for="type">Entry type</label>
        <select id="type" name="type">
            <option value="">All entries</option>
            <?php foreach ($knownTypes as $t): ?>
            <option value="<?= e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-accent" type="submit">Apply</button>
</form>

<div class="cash-ranges no-print">
    <?php
    $ranges = [
        'This month' => [date('Y-m-01'), date('Y-m-d')],
        'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
        'Last 30 days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
        'This year' => [date('Y-01-01'), date('Y-m-d')],
    ];
    foreach ($ranges as $label => [$rFrom, $rTo]): ?>
    <a class="cash-range <?= $from === $rFrom && $to === $rTo ? 'is-active' : '' ?>" href="?from=<?= e($rFrom) ?>&amp;to=<?= e($rTo) ?>&amp;type=<?= e($typeFilter) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="cash-summary">
    <div><span>Opening balance</span><strong><?= e($cur) . money($openingBalance) ?></strong></div>
    <div class="is-in"><span>Money in</span><strong><?= e($cur) . money($totalIn) ?></strong></div>
    <div class="is-out"><span>Money out</span><strong><?= e($cur) . money($totalOut) ?></strong></div>
    <div><span>Closing balance</span><strong><?= e($cur) . money($closingBalance) ?></strong></div>
</div>

<div class="table-container cash-table-wrap">
    <table class="data-table cash-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Method</th>
                <th class="cash-num">Money in</th>
                <th class="cash-num">Money out</th>
                <th class="cash-num">Balance</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$lines): ?>
            <tr><td colspan="7" class="cash-empty">No cash entries recorded for this period<?= $typeFilter !== '' ? ' and entry type' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($lines as $txn): ?>
            <tr>
                <td><?= e(format_date($txn['transaction_date'])) ?></td>
                <td>
                    <span class="cash-badge cash-badge-<?= e($txn['transaction_type']) ?>"><?= e(ucfirst($txn['transaction_type'])) ?></span>
                    <?php if ($txn['reference_no']): ?><small class="cash-ref"><?= e($txn['reference_no']) ?></small><?php endif; ?>
                </td>
                <td><?= e($txn['description'] ?: '—') ?></td>
                <td><?= e(ucfirst($txn['payment_method'])) ?></td>
                <td class="cash-num cash-in"><?= $txn['direction'] === 'in' ? e($cur) . money($txn['amount']) : '—' ?></td>
                <td class="cash-num cash-out"><?= $txn['direction'] === 'out' ? e($cur) . money($txn['amount']) : '—' ?></td>
                <td class="cash-num"><strong><?= e($cur) . money($txn['balance']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($lines): ?>
        <tfoot>
            <tr>
                <th colspan="4">Period totals (<?= count($lines) ?> entr<?= count($lines) === 1 ? 'y' : 'ies' ?>)</th>
                <th class="cash-num"><?= e($cur) . money($totalIn) ?></th>
                <th class="cash-num"><?= e($cur) . money($totalOut) ?></th>
                <th class="cash-num"><?= e($cur) . money($closingBalance) ?></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<style>
.cash-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1c2250,#4f4fc9 65%,#7a5cd6);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.cash-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.cash-hero p{margin:0;color:rgba(255,255,255,.76)}
.cash-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.cash-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.cash-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.cash-filter{padding:18px 20px;display:grid;grid-template-columns:repeat(3,minmax(150px,1fr)) auto;gap:14px;align-items:end;margin-bottom:12px}
.cash-filter label{display:block;margin:0 0 5px}
.cash-filter input,.cash-filter select{margin:0}
.cash-filter button{min-height:38px}
.cash-ranges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.cash-range{padding:7px 13px;border-radius:20px;background:#fff;border:1px solid #dde1f0;color:#4f4fc9;font-size:.82rem;font-weight:700;text-decoration:none}
.cash-range:hover{background:#eaf5f1}
.cash-range.is-active{background:#4f4fc9;border-color:#4f4fc9;color:#fff}
.cash-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.cash-summary div{padding:15px 17px;background:#fff;border:1px solid #dde1f0;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.cash-summary span{display:block;font-size:.76rem;color:#646b8c}
.cash-summary strong{display:block;margin-top:5px;font-size:1.15rem;color:#1c2250;font-variant-numeric:tabular-nums}
.cash-summary .is-in strong{color:#1d7a4f}
.cash-summary .is-out strong{color:#c2364a}
.cash-table-wrap{overflow-x:auto}
.cash-table{min-width:920px}
.cash-table th.cash-num,.cash-table td.cash-num{text-align:right;font-variant-numeric:tabular-nums}
.cash-table td.cash-in{color:#1d7a4f;font-weight:700}
.cash-table td.cash-out{color:#c2364a;font-weight:700}
.cash-table tfoot th{padding:13px 12px;background:#eef0fa;color:#1c2250;text-align:right;font-variant-numeric:tabular-nums}
.cash-table tfoot th:first-child{text-align:left}
.cash-empty{text-align:center;padding:34px!important;color:#646b8c}
.cash-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;background:#eef2f5;color:#4a5f6b}
.cash-badge-receipt,.cash-badge-income{background:#e6f4ec;color:#1d7a4f}
.cash-badge-payment,.cash-badge-expense{background:#fdeee9;color:#c2364a}
.cash-ref{display:block;margin-top:4px;color:#646b8c}
@media(max-width:850px){.cash-filter{grid-template-columns:1fr 1fr}.cash-filter button{grid-column:1/-1}.cash-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.cash-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.cash-hero h1{font-size:1.75rem}.cash-summary{grid-template-columns:1fr 1fr;gap:8px}.cash-filter{grid-template-columns:1fr}}
@media print {
    .no-print, .cash-filter, .cash-ranges, .cash-hero-actions { display: none !important; }
    .cash-hero { background: #1c2250 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; }
    .cash-table { min-width: 0; }
    .table-container { overflow: visible; }
}
</style>

<?php require_once "../partials/footer.php"; ?>
