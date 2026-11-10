<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT je.*, cu.name AS created_by_name, pu.name AS posted_by_name
    FROM journal_entries je
    LEFT JOIN users cu ON cu.id = je.created_by
    LEFT JOIN users pu ON pu.id = je.posted_by
    WHERE je.id = ?");
$stmt->execute([$id]);
$entry = $stmt->fetch();

if (!$entry) {
    render_not_found(
        'Journal entry not found',
        'This journal entry may have been removed, or the link is incorrect.',
        '/block_plant/finance/journal.php',
        'Back to journal entries'
    );
}

$lineStmt = $pdo->prepare("SELECT jl.*, coa.account_code, coa.account_name
    FROM journal_entry_lines jl
    JOIN chart_of_accounts coa ON coa.id = jl.account_id
    WHERE jl.entry_id = ?
    ORDER BY jl.id");
$lineStmt->execute([$id]);
$lines = $lineStmt->fetchAll();

$lineDebit = 0.0;
$lineCredit = 0.0;
foreach ($lines as $line) {
    $lineDebit += (float) $line['debit'];
    $lineCredit += (float) $line['credit'];
}

$headerDebit = (float) $entry['total_debit'];
$headerCredit = (float) $entry['total_credit'];
$linesBalanced = abs($lineDebit - $lineCredit) < 0.005;
$headerMatches = abs($headerDebit - $lineDebit) < 0.005 && abs($headerCredit - $lineCredit) < 0.005;

$status = strtolower((string) ($entry['status'] ?: 'draft'));
$statusBadge = 'badge-muted';
if ($status === 'posted') {
    $statusBadge = 'badge-success';
} elseif ($status === 'draft') {
    $statusBadge = 'badge-warning';
} elseif (in_array($status, ['void', 'voided', 'cancelled', 'reversed'], true)) {
    $statusBadge = 'badge-danger';
} elseif (in_array($status, ['pending', 'submitted'], true)) {
    $statusBadge = 'badge-info';
}

$pageTitle = 'Journal Detail';
require_once "../partials/header.php";
?>

<div class="jv-hero">
    <div>
        <span class="jv-eyebrow">FINANCE · JOURNAL ENTRY</span>
        <h1>🧾 Entry #<?= e($entry['entry_no']) ?></h1>
        <p><?= e($entry['description'] ?: 'Journal entry detail') ?></p>
    </div>
    <div class="jv-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a href="journal.php" class="btn btn-secondary">Back to journal</a>
    </div>
</div>

<?php if (!$linesBalanced): ?>
<div class="flash flash-error">
    This entry's lines are out of balance by <?= e(currency_symbol()) . money(abs($lineDebit - $lineCredit)) ?>.
    Review the lines below before relying on this entry.
</div>
<?php elseif (!$headerMatches): ?>
<div class="flash flash-error">
    The stored entry totals do not match the lines. The line totals below are the source of truth.
</div>
<?php elseif ($status !== 'posted'): ?>
<div class="flash">
    This entry is <strong><?= e($status) ?></strong>, so it does not affect the ledger or any financial report until it is posted.
</div>
<?php endif; ?>

<div class="panel jv-meta">
    <div><span>Status</span><strong><span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst($status)) ?></span></strong></div>
    <div><span>Entry date</span><strong><?= e(format_date($entry['entry_date'])) ?></strong></div>
    <div><span>Reference</span><strong><?= e($entry['reference_no'] ?: '—') ?></strong></div>
    <div><span>Lines</span><strong><?= number_format(count($lines)) ?></strong></div>
    <div><span>Created by</span><strong><?= e($entry['created_by_name'] ?: '—') ?></strong>
        <small><?= $entry['created_at'] ? e(date('d M Y H:i', strtotime($entry['created_at']))) : '' ?></small></div>
    <div><span>Posted by</span><strong><?= e($entry['posted_by_name'] ?: '—') ?></strong>
        <small><?= $entry['posted_at'] ? e(date('d M Y H:i', strtotime($entry['posted_at']))) : '' ?></small></div>
    <div class="jv-meta-wide"><span>Description</span><strong><?= e($entry['description'] ?: '—') ?></strong></div>
</div>

<div class="panel">
    <div class="jv-panel-head">
        <h3>Entry lines</h3>
        <span class="jv-count">Debits <?= e(currency_symbol()) . money($lineDebit) ?> · Credits <?= e(currency_symbol()) . money($lineCredit) ?></span>
    </div>
    <div class="table-container">
        <table class="data-table jv-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th class="jv-num">Debit</th>
                    <th class="jv-num">Credit</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$lines): ?>
                <tr><td colspan="4" class="jv-empty">This entry has no lines yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td>
                        <a class="jv-link" href="general_ledger.php?account_id=<?= (int) $line['account_id'] ?>&amp;from=2000-01-01&amp;to=<?= e($entry['entry_date']) ?>">
                            <?= e($line['account_code']) ?>
                        </a>
                        <span class="jv-account"><?= e($line['account_name']) ?></span>
                    </td>
                    <td class="jv-num"><?= (float) $line['debit'] > 0 ? e(currency_symbol()) . money((float) $line['debit']) : '—' ?></td>
                    <td class="jv-num"><?= (float) $line['credit'] > 0 ? e(currency_symbol()) . money((float) $line['credit']) : '—' ?></td>
                    <td><?= e($line['description'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th class="jv-num"><?= e(currency_symbol()) . money($lineDebit) ?></th>
                    <th class="jv-num"><?= e(currency_symbol()) . money($lineCredit) ?></th>
                    <th>
                        <?php if ($linesBalanced): ?>
                        <span class="badge badge-success">Balanced</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Difference <?= e(currency_symbol()) . money(abs($lineDebit - $lineCredit)) ?></span>
                        <?php endif; ?>
                    </th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if (!$headerMatches): ?>
    <p class="jv-note">
        Stored header totals: debit <?= e(currency_symbol()) . money($headerDebit) ?>,
        credit <?= e(currency_symbol()) . money($headerCredit) ?>.
    </p>
    <?php endif; ?>
</div>

<style>
.jv-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1c2250,#4f4fc9 65%,#7a5cd6);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.jv-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.jv-hero p{margin:0;color:rgba(255,255,255,.76)}
.jv-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.jv-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.jv-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.jv-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:18px}
.jv-meta>div{padding:12px 14px;background:#f7faf9;border:1px solid #e3ecea;border-radius:10px}
.jv-meta span{display:block;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#7b8f94}
.jv-meta strong{display:block;margin-top:4px;color:#1c2250;font-weight:700}
.jv-meta small{display:block;margin-top:2px;color:#8b98a1;font-size:.75rem}
.jv-meta .jv-meta-wide{grid-column:1 / -1}
.jv-panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px}
.jv-panel-head h3{margin:0}
.jv-count{font-size:.82rem;font-weight:700;color:#4f4fc9;font-variant-numeric:tabular-nums}
.jv-table th.jv-num,.jv-table td.jv-num{text-align:right;font-variant-numeric:tabular-nums}
.jv-table td a.jv-link{font-weight:700;color:#4f4fc9;text-decoration:none}
.jv-table td a.jv-link:hover{text-decoration:underline}
.jv-account{display:block;font-size:.78rem;color:#7b8f94}
.jv-table tfoot th{padding:13px 12px;background:#eef0fa;color:#1c2250;text-align:right;font-variant-numeric:tabular-nums}
.jv-table tfoot th:first-child{text-align:left}
.jv-table tfoot th:last-child{text-align:left}
.jv-empty{text-align:center;padding:34px!important;color:#646b8c}
.jv-note{margin:10px 0 0;font-size:.82rem;color:#c2364a}
@media(max-width:800px){.jv-meta{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.jv-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.jv-hero h1{font-size:1.7rem}.jv-meta{grid-template-columns:1fr}}
@media print{
    .no-print,.jv-hero-actions,.flash{display:none!important}
    .jv-hero{background:#1c2250!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:none}
    .jv-table{min-width:0}
    .table-container{overflow:visible}
}
</style>

<?php require_once "../partials/footer.php"; ?>
