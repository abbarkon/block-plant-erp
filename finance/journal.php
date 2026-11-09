<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_no VARCHAR(50) UNIQUE NOT NULL,
        entry_date DATE NOT NULL,
        description VARCHAR(255),
        reference_no VARCHAR(60),
        total_debit DECIMAL(14,2) DEFAULT 0,
        total_credit DECIMAL(14,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'posted',
        posted_by INT,
        posted_at TIMESTAMP NULL,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id),
        FOREIGN KEY(posted_by) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entry_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NOT NULL,
        account_id INT NOT NULL,
        debit DECIMAL(14,2) DEFAULT 0,
        credit DECIMAL(14,2) DEFAULT 0,
        description VARCHAR(255),
        FOREIGN KEY(entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
        FOREIGN KEY(account_id) REFERENCES chart_of_accounts(id)
    )");
} catch (Throwable $ex) {
    // Table setup problems should not blank out the register; the query below will surface them.
}

$canCreate = has_permission('finance', 'create');

$search = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $from)) { $from = ''; }
if (!preg_match($datePattern, $to)) { $to = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(entry_no LIKE ? OR description LIKE ? OR reference_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'entry_date >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'entry_date <= ?'; $params[] = $to; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totals = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_debit),0) d, COALESCE(SUM(total_credit),0) cr FROM journal_entries")->fetch();
$recentTotals = null;
if ($whereSql) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_debit),0) d, COALESCE(SUM(total_credit),0) cr FROM journal_entries{$whereSql}");
    $stmt->execute($params);
    $recentTotals = $stmt->fetch();
}

$limit = 200;
$stmt = $pdo->prepare("SELECT je.*, u.name AS posted_by_name FROM journal_entries je LEFT JOIN users u ON u.id = je.posted_by{$whereSql} ORDER BY je.entry_date DESC, je.id DESC LIMIT {$limit}");
$stmt->execute($params);
$entries = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries{$whereSql}");
$countStmt->execute($params);
$totalMatching = (int) $countStmt->fetchColumn();
$truncated = $totalMatching > $limit;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal_entries_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Entry date', 'Entry no', 'Description', 'Reference', 'Total debit', 'Total credit', 'Status', 'Posted by']);
    foreach ($entries as $entry) {
        fputcsv($out, [
            $entry['entry_date'],
            $entry['entry_no'],
            $entry['description'] ?? '',
            $entry['reference_no'] ?? '',
            number_format((float) $entry['total_debit'], 2, '.', ''),
            number_format((float) $entry['total_credit'], 2, '.', ''),
            $entry['status'],
            $entry['posted_by_name'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$flash = get_flash();
$pageTitle = "Journal Entries";
require_once "../partials/header.php";
$cur = currency_symbol();

$badgeClass = static function (string $status): string {
    $status = strtolower($status);
    if ($status === 'posted') { return 'badge-success'; }
    if ($status === 'draft' || $status === 'pending') { return 'badge-warning'; }
    if (in_array($status, ['void', 'voided', 'cancelled', 'reversed'], true)) { return 'badge-danger'; }
    return 'badge-muted';
};
?>

<div class="jl-hero">
    <div>
        <span class="jl-hero-eyebrow">FINANCE · POSTED ACTIVITY</span>
        <h1>📒 Journal Entries</h1>
        <p>Every posted double-entry transaction, with debit and credit totals per entry.</p>
    </div>
    <div class="jl-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="?export=csv&amp;q=<?= e(urlencode($search)) ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">Export CSV</a>
        <?php if ($canCreate): ?>
        <a href="journal_form.php" class="btn btn-accent">+ New Journal Entry</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="jl-filter panel no-print">
    <div><label for="q">Search</label><input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Entry no, description or reference"></div>
    <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-accent" type="submit">Filter</button>
    <?php if ($search !== '' || $from !== '' || $to !== ''): ?><a class="btn btn-secondary" href="journal.php">Clear</a><?php endif; ?>
</form>

<div class="jl-summary">
    <div><span><?= $whereSql ? 'Matching entries' : 'Entries posted' ?></span><strong><?= number_format($whereSql ? (int) $recentTotals['c'] : (int) $totals['c']) ?></strong></div>
    <div><span>Total debits</span><strong><?= e($cur) . money($whereSql ? $recentTotals['d'] : $totals['d']) ?></strong></div>
    <div><span>Total credits</span><strong><?= e($cur) . money($whereSql ? $recentTotals['cr'] : $totals['cr']) ?></strong></div>
    <div><span>Ledger position</span><strong class="<?= abs((float) $totals['d'] - (float) $totals['cr']) < 0.01 ? 'jl-ok' : 'jl-bad' ?>"><?= abs((float) $totals['d'] - (float) $totals['cr']) < 0.01 ? 'Balanced' : 'Off by ' . money(abs((float) $totals['d'] - (float) $totals['cr'])) ?></strong></div>
</div>

<div class="table-container jl-table-wrap">
    <table class="data-table jl-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entry No</th>
                <th>Description</th>
                <th>Reference</th>
                <th class="jl-num">Total debit</th>
                <th class="jl-num">Total credit</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$entries): ?>
            <tr><td colspan="8" class="jl-empty">No journal entries match the current filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= e(format_date($entry['entry_date'])) ?></td>
                <td><a class="jl-link" href="journal_view.php?id=<?= (int) $entry['id'] ?>"><?= e($entry['entry_no']) ?></a></td>
                <td><?= e($entry['description'] ?: '—') ?></td>
                <td><?= e($entry['reference_no'] ?: '—') ?></td>
                <td class="jl-num"><?= e($cur) . money($entry['total_debit']) ?></td>
                <td class="jl-num"><?= e($cur) . money($entry['total_credit']) ?></td>
                <td><span class="badge <?= $badgeClass((string) $entry['status']) ?>"><?= e(ucfirst($entry['status'])) ?></span></td>
                <td class="no-print"><a href="journal_view.php?id=<?= (int) $entry['id'] ?>" class="btn btn-sm">View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($entries): ?>
        <tfoot>
            <tr>
                <th colspan="4">Totals<?= $truncated ? ' (first ' . $limit . ' of ' . number_format($totalMatching) . ')' : '' ?></th>
                <th class="jl-num"><?= e($cur) . money(array_sum(array_column($entries, 'total_debit'))) ?></th>
                <th class="jl-num"><?= e($cur) . money(array_sum(array_column($entries, 'total_credit'))) ?></th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php if ($truncated): ?>
<p class="jl-note no-print">Showing the first <?= $limit ?> of <?= number_format($totalMatching) ?> matching entries — narrow the date range or search to see older ones.</p>
<?php endif; ?>

<div class="jl-guide no-print">
    <h3>📌 Journal Entry Guidelines</h3>
    <ul>
        <li><strong>Double-Entry:</strong> Every entry must have equal debits and credits</li>
        <li><strong>Documentation:</strong> Always include reference numbers and descriptions</li>
        <li><strong>Review:</strong> Verify entries before posting</li>
        <li><strong>Audit Trail:</strong> All entries are permanently recorded</li>
    </ul>
</div>

<style>
.jl-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 65%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.jl-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.jl-hero p{margin:0;color:rgba(255,255,255,.76)}
.jl-hero-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.jl-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.jl-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.jl-filter{padding:18px 20px;display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:14px;align-items:end;margin-bottom:14px}
.jl-filter label{display:block;margin:0 0 5px}
.jl-filter input{margin:0}
.jl-filter .btn{min-height:38px;white-space:nowrap}
.jl-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.jl-summary div{padding:15px 17px;background:#fff;border:1px solid #e3ddd4;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.jl-summary span{display:block;font-size:.76rem;color:#6a6259}
.jl-summary strong{display:block;margin-top:5px;font-size:1.15rem;color:#1f2933;font-variant-numeric:tabular-nums}
.jl-summary strong.jl-ok{color:#1d7a4f}
.jl-summary strong.jl-bad{color:#b3452f}
.jl-table-wrap{overflow-x:auto}
.jl-table{min-width:940px}
.jl-table th.jl-num,.jl-table td.jl-num{text-align:right;font-variant-numeric:tabular-nums}
.jl-table td a.jl-link{font-weight:700;color:#b8481b;text-decoration:none}
.jl-table td a.jl-link:hover{text-decoration:underline}
.jl-table tfoot th{padding:13px 12px;background:#f4efe8;color:#1f2933;text-align:right;font-variant-numeric:tabular-nums}
.jl-table tfoot th:first-child{text-align:left}
.jl-empty{text-align:center;padding:34px!important;color:#6a6259}
.jl-note{margin:10px 0 0;font-size:.82rem;color:#6a6259}
.jl-guide{margin-top:20px;padding:20px;background:#fff8e6;border:1px solid #f0e2b8;border-radius:12px}
.jl-guide h3{margin:0 0 8px}
.jl-guide ul{margin:0;padding-left:20px;line-height:1.8}
@media(max-width:850px){.jl-filter{grid-template-columns:1fr 1fr}.jl-filter .btn{width:100%}.jl-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.jl-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.jl-hero h1{font-size:1.75rem}.jl-filter{grid-template-columns:1fr}.jl-summary{grid-template-columns:1fr 1fr;gap:8px}}
@media print {
    .no-print, .jl-hero-actions, .jl-filter, .jl-guide, .jl-note { display: none !important; }
    .jl-hero { background: #1f2933 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; }
    .jl-table { min-width: 0; }
    .table-container { overflow: visible; }
}
</style>

<?php require_once "../partials/footer.php"; ?>
