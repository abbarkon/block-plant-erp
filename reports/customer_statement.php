<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');

$pageTitle = "Customer Statement";

$customers = $pdo->query("SELECT id, customer_code, name FROM customers ORDER BY name")->fetchAll();

$customerId = (int) ($_GET['customer_id'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-01-01');
}
if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$customer = null;
$rows = [];
$opening = 0.0;
$totalDebit = 0.0;
$totalCredit = 0.0;
$closing = 0.0;

if ($customerId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        render_not_found('Customer not found', 'The customer you requested does not exist.');
    }

    // Opening balance = customer opening balance + invoices before range - payments before range
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE customer_id = ? AND sale_date < ?");
    $stmt->execute([$customerId, $from]);
    $openInv = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ? AND payment_date < ?");
    $stmt->execute([$customerId, $from]);
    $openPay = (float) $stmt->fetchColumn();

    $opening = (float) $customer['opening_balance'] + $openInv - $openPay;

    // Invoices within range
    $stmt = $pdo->prepare("SELECT id, invoice_no AS ref, sale_date AS dt, total_amount AS amount, notes
        FROM sales WHERE customer_id = ? AND sale_date BETWEEN ? AND ?");
    $stmt->execute([$customerId, $from, $to]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'dt' => $r['dt'],
            'type' => 'Invoice',
            'ref' => $r['ref'],
            'memo' => $r['notes'],
            'debit' => (float) $r['amount'],
            'credit' => 0.0,
            'link' => '../sales/view.php?id=' . (int) $r['id'],
        ];
    }

    // Payments within range
    $stmt = $pdo->prepare("SELECT p.id, p.payment_date AS dt, p.amount, p.payment_method, p.notes, s.invoice_no
        FROM payments p LEFT JOIN sales s ON s.id = p.sale_id
        WHERE p.customer_id = ? AND p.payment_date BETWEEN ? AND ?");
    $stmt->execute([$customerId, $from, $to]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'dt' => $r['dt'],
            'type' => 'Payment',
            'ref' => $r['invoice_no'] ?: ('PAY-' . (int) $r['id']),
            'memo' => trim(($r['payment_method'] ? $r['payment_method'] . ' ' : '') . (string) $r['notes']),
            'debit' => 0.0,
            'credit' => (float) $r['amount'],
            'link' => '',
        ];
    }

    usort($rows, static function ($a, $b) {
        return [$a['dt'], $a['type'] === 'Payment' ? 1 : 0] <=> [$b['dt'], $b['type'] === 'Payment' ? 1 : 0];
    });

    $totalDebit = array_sum(array_column($rows, 'debit'));
    $totalCredit = array_sum(array_column($rows, 'credit'));
    $closing = $opening + $totalDebit - $totalCredit;
}

if (($_GET['export'] ?? '') === 'csv' && $customer) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statement_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $customer['name']) . '_' . $to . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Customer statement']);
    fputcsv($out, ['Customer', $customer['name'] . ' (' . $customer['customer_code'] . ')']);
    fputcsv($out, ['Period', $from . ' to ' . $to]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Type', 'Reference', 'Memo', 'Debit', 'Credit', 'Balance']);
    fputcsv($out, [$from, 'Opening balance', '', '', '', '', round($opening, 2)]);
    $run = $opening;
    foreach ($rows as $r) {
        $run += $r['debit'] - $r['credit'];
        fputcsv($out, [$r['dt'], $r['type'], $r['ref'], $r['memo'], $r['debit'], $r['credit'], round($run, 2)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Totals', '', '', '', $totalDebit, $totalCredit, round($closing, 2)]);
    fclose($out);
    exit;
}

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'customer_statement.php?' . http_build_query($exportQuery);

require_once "../partials/header.php";
?>

<style>
    @media print {
        .no-print, .navbar, .sidebar, .topbar { display: none !important; }
        .table-container { box-shadow: none; }
        body { background: #fff; }
    }
    .statement-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .statement-meta div { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; }
    .statement-meta span { display: block; font-size: .75rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
    .statement-meta strong { font-size: 1rem; }
</style>

<div class="page-header no-print">
    <h1>🧾 Customer Statement</h1>
    <div class="header-actions">
        <?php if ($customer): ?>
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
        <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
        <?php endif; ?>
        <a href="../customers/index.php" class="btn btn-secondary">Customers</a>
    </div>
</div>

<div class="panel no-print">
    <form method="get" class="filter-form">
        <select name="customer_id" required>
            <option value="">Select customer…</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $customerId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['customer_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
        <button type="submit" class="btn btn-accent">View statement</button>
        <a href="customer_statement.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<?php if (!$customerId): ?>
<div class="panel">
    <p style="color:#64748b;margin:0;">Select a customer to view their statement of invoices and payments.</p>
</div>
<?php else: ?>

<div class="statement-meta">
    <div><span>Customer</span><strong><?= e($customer['name']) ?></strong></div>
    <div><span>Account code</span><strong><?= e($customer['customer_code']) ?></strong></div>
    <div><span>Period</span><strong><?= e(date('d M Y', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?></strong></div>
    <div><span>Closing balance</span><strong><?= currency_symbol() . money($closing) ?></strong></div>
</div>

<div class="panel">
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Memo</th>
                    <th style="text-align:right;">Debit</th>
                    <th style="text-align:right;">Credit</th>
                    <th style="text-align:right;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background:#f8fafc;font-weight:600;">
                    <td><?= e(date('d M Y', strtotime($from))) ?></td>
                    <td colspan="4">Opening balance</td>
                    <td style="text-align:right;">—</td>
                    <td style="text-align:right;">—</td>
                    <td style="text-align:right;"><?= currency_symbol() . money($opening) ?></td>
                </tr>
                <?php $run = $opening; foreach ($rows as $r): $run += $r['debit'] - $r['credit']; ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime($r['dt']))) ?></td>
                    <td><span class="badge <?= $r['type'] === 'Payment' ? 'badge-success' : 'badge-muted' ?>"><?= e($r['type']) ?></span></td>
                    <td>
                        <?php if ($r['link']): ?><a href="<?= e($r['link']) ?>"><?= e($r['ref']) ?></a>
                        <?php else: ?><?= e($r['ref']) ?><?php endif; ?>
                    </td>
                    <td style="color:#64748b;"><?= e($r['memo']) ?></td>
                    <td style="text-align:right;"><?= $r['debit'] > 0 ? currency_symbol() . money($r['debit']) : '—' ?></td>
                    <td style="text-align:right;color:#0f766e;"><?= $r['credit'] > 0 ? currency_symbol() . money($r['credit']) : '—' ?></td>
                    <td style="text-align:right;"><strong><?= currency_symbol() . money($run) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="7" style="text-align:center;color:#64748b;padding:20px;">No transactions in this period.</td></tr>
                <?php endif; ?>
                <tr style="background:#f8fafc;font-weight:700;">
                    <td colspan="4">Totals</td>
                    <td style="text-align:right;"><?= currency_symbol() . money($totalDebit) ?></td>
                    <td style="text-align:right;"><?= currency_symbol() . money($totalCredit) ?></td>
                    <td style="text-align:right;"><?= currency_symbol() . money($closing) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
