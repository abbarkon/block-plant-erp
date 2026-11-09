<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_login();
require_permission('finance', 'view');

$pageTitle = 'General Ledger';
$accountId = max(0, (int) ($_GET['account_id'] ?? 0));
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $from) || !preg_match($datePattern, $to) || $from > $to) {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

$accounts = $pdo->query("SELECT id, account_code, account_name, account_type, balance FROM chart_of_accounts WHERE status = 1 ORDER BY account_code")->fetchAll();
$account = null;
$entries = [];
$openingBalance = 0.0;
$periodDebit = 0.0;
$periodCredit = 0.0;
$closingBalance = 0.0;

if ($accountId > 0) {
    $accountStmt = $pdo->prepare("SELECT id, account_code, account_name, account_type, balance FROM chart_of_accounts WHERE id = ? AND status = 1");
    $accountStmt->execute([$accountId]);
    $account = $accountStmt->fetch();

    if ($account) {
        $normalDebit = in_array(strtolower($account['account_type']), ['asset', 'expense'], true);
        $movementExpression = $normalDebit ? '(jl.debit - jl.credit)' : '(jl.credit - jl.debit)';
        $accountBalance = (float) $account['balance'];

        $lifetimeStmt = $pdo->prepare("SELECT COALESCE(SUM({$movementExpression}), 0) FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = ? AND LOWER(je.status) = 'posted'");
        $lifetimeStmt->execute([$accountId]);
        $openingBase = $accountBalance - (float) $lifetimeStmt->fetchColumn();

        $priorStmt = $pdo->prepare("SELECT COALESCE(SUM({$movementExpression}), 0) FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = ? AND LOWER(je.status) = 'posted' AND je.entry_date < ?");
        $priorStmt->execute([$accountId, $from]);
        $openingBalance = $openingBase + (float) $priorStmt->fetchColumn();
        $closingBalance = $openingBalance;

        $entryStmt = $pdo->prepare("SELECT jl.entry_id, jl.debit, jl.credit, jl.description AS line_note, je.entry_no, je.entry_date, je.description, je.reference_no FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE jl.account_id = ? AND LOWER(je.status) = 'posted' AND je.entry_date BETWEEN ? AND ? ORDER BY je.entry_date, je.id, jl.id");
        $entryStmt->execute([$accountId, $from, $to]);
        $entries = $entryStmt->fetchAll();
        foreach ($entries as &$entry) {
            $debit = (float) $entry['debit'];
            $credit = (float) $entry['credit'];
            $periodDebit += $debit;
            $periodCredit += $credit;
            $closingBalance += $normalDebit ? $debit - $credit : $credit - $debit;
            $entry['running_balance'] = $closingBalance;
        }
        unset($entry);
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<div class="ledger-hero">
    <div><span class="ledger-eyebrow">FINANCE · ACCOUNT ACTIVITY</span><h1>General Ledger</h1><p>Review posted transactions and running balances by account.</p></div>
    <a href="accounts.php" class="btn btn-secondary">Chart of Accounts</a>
</div>

<div class="ledger-filter panel">
    <form method="get" class="ledger-filter-form">
        <div><label for="account_id">Account</label><select id="account_id" name="account_id" required><option value="">Choose an account</option><?php foreach ($accounts as $acct): ?><option value="<?= (int) $acct['id'] ?>" <?= $accountId === (int) $acct['id'] ? 'selected' : '' ?>><?= e($acct['account_code'] . ' · ' . $acct['account_name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>" required></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>" required></div>
        <button class="btn btn-accent" type="submit">View ledger</button>
    </form>
</div>

<?php if ($accountId > 0 && !$account): ?>
<div class="flash flash-error">The selected account does not exist or is inactive.</div>
<?php endif; ?>

<?php if ($account): ?>
<div class="ledger-account-bar"><div><span>Selected account</span><h2><?= e($account['account_code'] . ' · ' . $account['account_name']) ?></h2><small><?= e(ucfirst($account['account_type'])) ?> account · <?= e(format_date($from)) ?> to <?= e(format_date($to)) ?></small></div><span class="ledger-account-balance"><?= e(currency_symbol()) . money($closingBalance) ?><small>Closing balance</small></span></div>

<div class="ledger-summary">
    <div><span>Opening balance</span><strong><?= e(currency_symbol()) . money($openingBalance) ?></strong></div>
    <div><span>Period debits</span><strong><?= e(currency_symbol()) . money($periodDebit) ?></strong></div>
    <div><span>Period credits</span><strong><?= e(currency_symbol()) . money($periodCredit) ?></strong></div>
    <div><span>Closing balance</span><strong><?= e(currency_symbol()) . money($closingBalance) ?></strong></div>
</div>

<div class="table-container ledger-table-wrap">
    <table class="data-table ledger-table">
        <thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th>Description</th><th>Debit</th><th>Credit</th><th>Running balance</th></tr></thead>
        <tbody>
        <?php if (!$entries): ?><tr><td colspan="7" class="ledger-empty">No posted ledger activity for this account and date range.</td></tr><?php endif; ?>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?= e(format_date($entry['entry_date'])) ?></td>
            <td><a href="journal_view.php?id=<?= (int) $entry['entry_id'] ?>"><?= e($entry['entry_no']) ?></a></td>
            <td><?= e($entry['reference_no'] ?: '—') ?></td>
            <td><?= e($entry['description'] ?: $entry['line_note'] ?: '—') ?></td>
            <td><?= (float) $entry['debit'] > 0 ? e(currency_symbol()) . money($entry['debit']) : '—' ?></td>
            <td><?= (float) $entry['credit'] > 0 ? e(currency_symbol()) . money($entry['credit']) : '—' ?></td>
            <td><strong><?= e(currency_symbol()) . money($entry['running_balance']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($entries): ?><tfoot><tr><th colspan="4">Period totals</th><th><?= e(currency_symbol()) . money($periodDebit) ?></th><th><?= e(currency_symbol()) . money($periodCredit) ?></th><th><?= e(currency_symbol()) . money($closingBalance) ?></th></tr></tfoot><?php endif; ?>
    </table>
</div>
<?php else: ?>
<div class="ledger-empty-state"><span>↗</span><h2>Select an account to begin</h2><p>Choose an account and date range to review its posted journal activity.</p></div>
<?php endif; ?>

<style>
.ledger-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 65%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}.ledger-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}.ledger-hero p{margin:0;color:rgba(255,255,255,.76)}.ledger-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}.ledger-hero .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);box-shadow:none}
.ledger-filter{padding:18px 20px}.ledger-filter-form{display:grid;grid-template-columns:minmax(240px,2fr) repeat(2,minmax(150px,1fr)) auto;gap:14px;align-items:end}.ledger-filter-form label{display:block;margin:0 0 5px}.ledger-filter-form input,.ledger-filter-form select{margin:0}.ledger-filter-form button{min-height:38px}
.ledger-account-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:20px 0 14px;padding:20px 22px;background:#fff;border:1px solid #e3ddd4;border-left:5px solid #d9742f;border-radius:12px;box-shadow:0 6px 18px rgba(24,59,86,.06)}.ledger-account-bar span:first-child{font-size:.72rem;color:#6a6259;text-transform:uppercase;font-weight:800;letter-spacing:.06em}.ledger-account-bar h2{margin:3px 0;font-size:1.25rem}.ledger-account-bar small{color:#6a6259}.ledger-account-balance{text-align:right;font-size:1.55rem;font-weight:800;color:#1f2933}.ledger-account-balance small{display:block;font-size:.72rem;font-weight:600}
.ledger-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0 18px}.ledger-summary div{padding:15px 17px;background:#fff;border:1px solid #e3ddd4;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}.ledger-summary span{display:block;font-size:.76rem;color:#6a6259}.ledger-summary strong{display:block;margin-top:5px;font-size:1.15rem;color:#1f2933}.ledger-table-wrap{overflow-x:auto}.ledger-table{min-width:900px}.ledger-table td:nth-child(5),.ledger-table td:nth-child(6),.ledger-table td:nth-child(7),.ledger-table th:nth-child(5),.ledger-table th:nth-child(6),.ledger-table th:nth-child(7){text-align:right;font-variant-numeric:tabular-nums}.ledger-table td a{font-weight:700;color:#b8481b}.ledger-table tfoot th{padding:13px 12px;background:#f4efe8;color:#1f2933;text-align:right}.ledger-table tfoot th:first-child{text-align:left}.ledger-empty{text-align:center;padding:34px!important;color:#6a6259}.ledger-empty-state{text-align:center;padding:52px 20px;color:#6a6259;background:rgba(255,255,255,.65);border:1px dashed #c8d9d5;border-radius:14px}.ledger-empty-state span{display:grid;place-items:center;width:54px;height:54px;margin:0 auto 12px;border-radius:16px;background:#eaf5f1;color:#b8481b;font-size:1.6rem}.ledger-empty-state h2{margin:0 0 5px}.ledger-empty-state p{margin:0}
@media(max-width:850px){.ledger-filter-form{grid-template-columns:1fr 1fr}.ledger-filter-form>div:first-child{grid-column:1/-1}.ledger-filter-form button{grid-column:1/-1}.ledger-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.ledger-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.ledger-hero h1{font-size:1.75rem}.ledger-account-bar{align-items:flex-start;flex-direction:column}.ledger-account-balance{text-align:left}.ledger-summary{grid-template-columns:1fr 1fr;gap:8px}.ledger-summary div{padding:12px}.ledger-summary strong{font-size:.98rem}.ledger-filter-form{grid-template-columns:1fr}}
</style>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>