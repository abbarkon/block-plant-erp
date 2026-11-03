<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('sales', 'edit');
ensure_gate_schema();
ensure_manufacturing_costing_schema();

$pageTitle = "Customer Payments";
$paymentMethods = ['Cash', 'Bank Transfer', 'Cheque', 'Mobile Money'];
$selectedCustomer = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$selectedSale = (int) ($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);
$error = null;
$postedAmount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $method = trim((string) ($_POST['payment_method'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $sale_id = !empty($_POST['sale_id']) ? (int) $_POST['sale_id'] : null;
    $postedAmount = (string) ($_POST['amount'] ?? '');

    try {
        if ($amount <= 0) {
            throw new RuntimeException('Enter a payment amount greater than zero.');
        }
        if ($amount > 999999999.99) {
            throw new RuntimeException('The payment amount is too large.');
        }
        if (!is_valid_date_string($date)) {
            throw new RuntimeException('Enter a valid payment date.');
        }
        if ($date > date('Y-m-d')) {
            throw new RuntimeException('The payment date cannot be in the future.');
        }
        if (!in_array($method, $paymentMethods, true)) {
            throw new RuntimeException('Select a valid payment method.');
        }
        if (mb_strlen($notes) > 255) {
            throw new RuntimeException('Notes must be 255 characters or fewer.');
        }
        if ($customer !== null) {
            $check = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 1");
            $check->execute([$customer]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Select an active customer.');
            }
        }

        $pdo->beginTransaction();

        $allocations = [];
        if ($sale_id !== null) {
            $saleStmt = $pdo->prepare("SELECT id, invoice_no, customer_id, balance FROM sales WHERE id = ? AND balance > 0 FOR UPDATE");
            $saleStmt->execute([$sale_id]);
            $sale = $saleStmt->fetch();
            if (!$sale) {
                throw new RuntimeException('The selected invoice has no outstanding balance.');
            }
            if ($customer === null) {
                $customer = $sale['customer_id'] !== null ? (int) $sale['customer_id'] : null;
            }
            if ($sale['customer_id'] !== null && $customer !== null && (int) $sale['customer_id'] !== $customer) {
                throw new RuntimeException('The invoice does not belong to the selected customer.');
            }
            if ($amount > (float) $sale['balance'] + 0.009) {
                throw new RuntimeException('The payment is greater than the outstanding invoice balance.');
            }
            $allocations[] = ['id' => (int) $sale['id'], 'amount' => $amount, 'invoice_no' => $sale['invoice_no']];
        } else {
            if ($customer === null) {
                throw new RuntimeException('Select a customer or an outstanding invoice. A general payment must be applied to a customer account.');
            }
            $open = $pdo->prepare("SELECT id, invoice_no, balance FROM sales WHERE customer_id = ? AND balance > 0 ORDER BY sale_date ASC, id ASC FOR UPDATE");
            $open->execute([$customer]);
            $invoices = $open->fetchAll();
            $available = 0.0;
            foreach ($invoices as $invoice) {
                $available += (float) $invoice['balance'];
            }
            if ($available <= 0) {
                throw new RuntimeException('This customer has no outstanding invoices.');
            }
            if ($amount > $available + 0.009) {
                throw new RuntimeException('The payment is greater than the customer\'s total outstanding balance.');
            }
            $remaining = $amount;
            foreach ($invoices as $invoice) {
                if ($remaining <= 0.009) {
                    break;
                }
                $apply = min($remaining, (float) $invoice['balance']);
                $allocations[] = ['id' => (int) $invoice['id'], 'amount' => round($apply, 2), 'invoice_no' => $invoice['invoice_no']];
                $remaining = round($remaining - $apply, 2);
            }
        }

        $paymentStmt = $pdo->prepare("INSERT INTO payments(sale_id, customer_id, payment_date, amount, payment_method, notes, created_by) VALUES(?,?,?,?,?,?,?)");
        // The unallocated general-payment header keeps sale_id null; allocations below carry the invoice detail.
        $paymentStmt->execute([$sale_id, $customer, $date, $amount, $method, $notes, $_SESSION['user_id']]);
        $paymentId = (int) $pdo->lastInsertId();

        $updateSale = $pdo->prepare("UPDATE sales SET paid_amount = paid_amount + ?, balance = GREATEST(0, balance - ?), payment_status = CASE WHEN balance - ? <= 0 THEN 'paid' ELSE 'partial' END WHERE id = ?");
        $clearancePosted = [];
        foreach ($allocations as $allocation) {
            $updateSale->execute([$allocation['amount'], $allocation['amount'], $allocation['amount'], $allocation['id']]);
            $balanceStmt = $pdo->prepare("SELECT balance FROM sales WHERE id = ?");
            $balanceStmt->execute([$allocation['id']]);
            if ((float) $balanceStmt->fetchColumn() <= 0) {
                create_gate_clearance_for_sale($allocation['id'], (int) $_SESSION['user_id']);
                $clearancePosted[] = $allocation['invoice_no'];
            }
        }

        $invoiceLabel = implode(', ', array_column($allocations, 'invoice_no'));
        if ($customer !== null) {
            // One receivable settlement journal for the whole payment.
            post_accounting_journal('PAY-' . $paymentId, $date, 'Customer payment received', mb_substr($invoiceLabel !== '' ? $invoiceLabel : 'Customer account', 0, 60), [
                ['1110', $amount, 0, 'Customer payment received'],
                ['1120', 0, $amount, 'Customer receivable settled'],
            ], (int) $_SESSION['user_id']);
        }

        $cashbookStmt = $pdo->prepare("INSERT INTO cashbook(transaction_date, transaction_type, reference_no, description, amount, payment_method, created_by) VALUES(?,?,?,?,?,?,?)");
        $cashbookStmt->execute([$date, 'receipt', 'PAY-' . $paymentId, mb_substr('Payment from ' . ($customer !== null ? 'customer #' . $customer : 'walk-in') . ($invoiceLabel !== '' ? ' for ' . $invoiceLabel : ''), 0, 255), $amount, $method, (int) $_SESSION['user_id']]);

        $pdo->commit();
        log_activity('create', 'payments', $paymentId, 'Customer payment of ' . money($amount) . ($invoiceLabel !== '' ? ' applied to ' . $invoiceLabel : ''));
        set_flash('success', 'Payment recorded and applied to the customer account.');
        header('Location: customer.php' . ($customer !== null ? '?customer_id=' . $customer : ''));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$flash = get_flash();
$customers = $pdo->query("SELECT c.id, c.name, COALESCE(SUM(s.balance), 0) AS outstanding FROM customers c LEFT JOIN sales s ON c.id = s.customer_id AND s.balance > 0 WHERE c.status = 1 GROUP BY c.id, c.name HAVING outstanding > 0 ORDER BY c.name")->fetchAll();
$customerInvoices = $pdo->query("SELECT id, invoice_no, customer_id, balance FROM sales WHERE balance > 0 ORDER BY sale_date ASC, id ASC LIMIT 500")->fetchAll();
$selectedInvoiceBalance = '';
foreach ($customerInvoices as $invoice) {
    if ((int) $invoice['id'] === $selectedSale) {
        $selectedInvoiceBalance = (string) $invoice['balance'];
        break;
    }
}

$recent_payments = $pdo->query("SELECT p.*, c.name AS customer_name, s.invoice_no FROM payments p LEFT JOIN customers c ON p.customer_id = c.id LEFT JOIN sales s ON p.sale_id = s.id ORDER BY p.created_at DESC, p.id DESC LIMIT 20")->fetchAll();
require_once "../partials/header.php";
?>

<style>
    .pay-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.7fr); gap:20px; align-items:start; }
    @media (max-width: 950px) { .pay-grid { grid-template-columns:1fr; } }
    .pay-card { background:#fff; padding:22px; border-radius:14px; border:1px solid #e2e8f0; box-shadow:0 8px 24px rgba(15,23,42,.05); }
    .pay-card h2 { margin:0 0 16px; color:#183b56; font-size:1.2rem; }
    .pay-field { margin-bottom:14px; }
    .pay-field label { display:block; margin-bottom:6px; font-weight:600; color:#475569; font-size:.86rem; }
    .pay-field input, .pay-field select, .pay-field textarea { width:100%; box-sizing:border-box; }
    .pay-hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    @media print { .no-print { display:none !important; } }
</style>

<div class="page-header no-print">
    <div>
        <h1>💰 Customer Payments</h1>
        <p style="color:#64748b;margin:6px 0 0;">Record a receipt against one invoice or allocate it to the customer's oldest open invoices.</p>
    </div>
    <div class="header-actions">
        <a href="../sales/index.php" class="btn btn-secondary">Sales invoices</a>
        <a href="../reports/aging.php" class="btn btn-secondary">AR aging</a>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<div class="pay-grid">
    <div class="pay-card">
        <h2>Record payment</h2>
        <form method="post">
            <div class="pay-field">
                <label>Customer *</label>
                <select name="customer_id" onchange="filterInvoices()">
                    <option value="">Select customer / walk-in invoice</option>
                    <?php foreach ($customers as $cust): ?>
                    <option value="<?= (int) $cust['id'] ?>" <?= $selectedCustomer === (int) $cust['id'] ? 'selected' : '' ?>><?= e($cust['name']) ?> (<?= currency_symbol() . money($cust['outstanding']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div class="pay-hint">A general payment is applied to the oldest open invoices first.</div>
            </div>

            <div class="pay-field">
                <label>Invoice (optional)</label>
                <select name="sale_id" id="invoiceSelect">
                    <option value="">General payment (oldest first)</option>
                    <?php foreach ($customerInvoices as $invoice): ?>
                    <option value="<?= (int) $invoice['id'] ?>" data-customer="<?= (int) ($invoice['customer_id'] ?? 0) ?>" <?= $selectedSale === (int) $invoice['id'] ? 'selected' : '' ?>><?= e($invoice['invoice_no']) ?> — <?= currency_symbol() . money($invoice['balance']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pay-field">
                <label>Amount *</label>
                <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($error !== null && $postedAmount !== '' ? $postedAmount : $selectedInvoiceBalance) ?>" required>
            </div>

            <div class="pay-field">
                <label>Payment date *</label>
                <input type="date" name="payment_date" value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="pay-field">
                <label>Payment method *</label>
                <select name="payment_method" required>
                    <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?= e($method) ?>" <?= ($_POST['payment_method'] ?? 'Cash') === $method ? 'selected' : '' ?>><?= e($method) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pay-field">
                <label>Notes</label>
                <textarea name="notes" rows="2" maxlength="255"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-accent">Record payment</button>
        </form>
    </div>

    <div class="pay-card">
        <h2>Recent payments</h2>
        <?php if (!$recent_payments): ?>
            <p style="color:#64748b;margin:0;">No customer payments recorded yet.</p>
        <?php else: ?>
        <div style="overflow:auto;">
            <table class="data-table" style="width:100%;">
                <thead><tr><th>Date</th><th>Customer</th><th>Invoice</th><th style="text-align:right;">Amount</th><th>Method</th><th>Notes</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_payments as $pay): ?>
                    <tr>
                        <td><?= e(format_date($pay['payment_date'])) ?></td>
                        <td><?= e($pay['customer_name'] ?? 'Walk-in') ?></td>
                        <td><?= e($pay['invoice_no'] ?? 'General / allocated') ?></td>
                        <td style="text-align:right;"><strong><?= e(currency_symbol()) . money($pay['amount']) ?></strong></td>
                        <td><?= e($pay['payment_method']) ?></td>
                        <td><?= e($pay['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function filterInvoices() {
    var customer = document.querySelector('select[name="customer_id"]').value;
    var select = document.getElementById('invoiceSelect');
    var selectedHidden = false;
    Array.prototype.forEach.call(select.options, function (option) {
        if (!option.value) return;
        var matches = !customer || option.getAttribute('data-customer') === customer;
        option.hidden = !matches;
        option.disabled = !matches;
        if (!matches && option.selected) selectedHidden = true;
    });
    if (selectedHidden) select.value = '';
}
document.addEventListener('DOMContentLoaded', filterInvoices);
</script>
<?php require_once "../partials/footer.php"; ?>
