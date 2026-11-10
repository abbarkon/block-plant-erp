<?php
/**
 * Payroll journal posting.
 *
 * Books one payroll period into the general ledger as a single balanced
 * double-entry: gross salaries are expensed, and the credits settle net pay
 * out of cash, reduce the employee loan receivable for recoveries made through
 * payroll, and hold deductions that were withheld but not yet paid out.
 *
 * Posting is idempotent per period (entry number PAYROLL-YYYY-MM), so payroll
 * can never be double-booked. Finance viewers can see the preview; posting
 * additionally requires finance create permission.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
if (!has_permission('finance', 'view') && !has_permission('hr', 'view')) {
    require_permission('finance', 'view'); // renders the standard access-denied page
    exit;
}
$canPost = has_permission('finance', 'create');

$pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_no VARCHAR(100) UNIQUE NOT NULL,
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

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
$year = ($year >= 2000 && $year <= 2100) ? $year : (int) date('Y');

$periodKey = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
$entryNo = 'PAYROLL-' . $year . '-' . $periodKey;
$periodEnd = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
$totals = payroll_period_totals($month, $year);
$payrollYears = array_map('intval', $pdo->query("SELECT DISTINCT year FROM payroll ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN));
if (!in_array($year, $payrollYears, true)) {
    $payrollYears[] = $year;
}
rsort($payrollYears);

$existingEntryStmt = $pdo->prepare("SELECT id, entry_no, entry_date FROM journal_entries WHERE entry_no = ?");
$existingEntryStmt->execute([$entryNo]);
$existingEntry = $existingEntryStmt->fetch();

/* ------------------------------------------------------------------- posting */
$flash = null;
$flashType = 'error';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_payroll_journal'])) {
    if (!$canPost) {
        $flash = t('access_denied');
    } elseif ($totals === []) {
        $flash = t('no_payroll_for_period');
    } elseif ($existingEntry) {
        $flash = t('journal_already_posted');
    } else {
        $expenseCode = trim((string) ($_POST['expense_account'] ?? ''));
        $cashCode = trim((string) ($_POST['cash_account'] ?? ''));
        $loanCode = trim((string) ($_POST['loan_account'] ?? ''));
        $deductionsCode = trim((string) ($_POST['deductions_account'] ?? ''));
        $payrollAccounts = ensure_payroll_journal_accounts();
        $loanCode = $loanCode !== '' ? $loanCode : $payrollAccounts['loan'];
        $deductionsCode = $deductionsCode !== '' ? $deductionsCode : $payrollAccounts['deductions'];

        $lines = [
            [$expenseCode, $totals['gross'], 0, 'Gross salaries and wages for ' . format_app_month_year($month, $year)],
            [$cashCode, 0, $totals['net'], 'Net pay released to employees'],
        ];
        if ($totals['loan'] > 0) {
            $lines[] = [$loanCode, 0, $totals['loan'], 'Employee loan recovery withheld from payroll'];
        }
        if ($totals['other'] > 0) {
            $lines[] = [$deductionsCode, 0, $totals['other'], 'Other payroll deductions withheld'];
        }

        try {
            $pdo->beginTransaction();
            $postedId = post_accounting_journal(
                $entryNo,
                $periodEnd,
                'Payroll for ' . format_app_month_year($month, $year),
                $entryNo,
                $lines,
                (int) $_SESSION['user_id']
            );
            $pdo->commit();
            if ($postedId) {
                $flashType = 'success';
                $flash = t('journal_posted');
                $existingEntry = ['id' => (int) $postedId, 'entry_no' => $entryNo, 'entry_date' => $periodEnd];
            } else {
                $flash = t('journal_already_posted');
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = t('error_occurred') . ' ' . $ex->getMessage();
        }
        $existingEntryStmt->execute([$entryNo]);
        $existingEntry = $existingEntryStmt->fetch();
    }
}

/* ------------------------------------------------------------ account lists */
$expenseAccounts = $pdo->query("SELECT account_code, account_name FROM chart_of_accounts WHERE account_type = 'expense' AND status = 1 ORDER BY account_code")->fetchAll();
$assetAccounts = $pdo->query("SELECT account_code, account_name FROM chart_of_accounts WHERE account_type = 'asset' AND status = 1 ORDER BY account_code")->fetchAll();
$liabilityAccounts = $pdo->query("SELECT account_code, account_name FROM chart_of_accounts WHERE account_type = 'liability' AND status = 1 ORDER BY account_code")->fetchAll();

$payrollAccounts = ensure_payroll_journal_accounts();
$defaults = ['expense' => '6100', 'cash' => '1110', 'loan' => $payrollAccounts['loan'], 'deductions' => $payrollAccounts['deductions']];
if (!$expenseAccounts) {
    $defaults['expense'] = '';
}
if (!$assetAccounts) {
    $defaults['cash'] = '';
}

$sym = currency_symbol();
$pageTitle = t('payroll_journal');
require_once "../partials/header.php";

function account_options(array $accounts, $selected) {
    $html = '';
    foreach ($accounts as $account) {
        $code = (string) $account['account_code'];
        $isSelected = ($code === (string) $selected) ? ' selected' : '';
        $html .= '<option value="' . e($code) . '"' . $isSelected . '>' . e($code . ' - ' . $account['account_name']) . '</option>';
    }
    return $html;
}
?>
<style>
    @media print {
        .no-print { display: none !important; }
        .panel { box-shadow: none; border: 1px solid #cbd5e1; page-break-inside: avoid; }
        .data-table th { background: #f1f5f9 !important; }
    }
</style>

<div class="page-header">
    <div>
        <h1>📒 <?= e(t('payroll_journal')) ?></h1>
        <p style="color:#64748b; margin:6px 0 0;">
            Books <?= e(format_app_month_year($month, $year)) ?> payroll into the general ledger as one balanced entry.
            Gross pay is expensed and the credits settle net pay, recover employee loans and hold back withheld deductions. All amounts in <?= e($sym) ?>.
        </p>
    </div>
    <div class="header-actions">
        <button type="button" onclick="window.print()" class="btn btn-print">🖨️ <?= e(t('print')) ?></button>
    </div>
</div>

<?php if ($flash !== null): ?>
<div style="margin-bottom:16px; padding:14px 18px; border-radius:12px; border-left:5px solid <?= $flashType === 'success' ? '#10b981' : '#ef4444' ?>; background:#fff;">
    <strong style="font-size:1.05rem;"><?= $flashType === 'success' ? '✅ ' : '⚠️ ' ?><?= e($flash) ?></strong>
</div>
<?php endif; ?>

<form method="get" class="panel no-print" style="margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:15px; align-items:end;">
        <div>
            <label><?= e(t('month')) ?></label>
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= e(format_app_month_name($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label><?= e(t('year')) ?></label>
            <select name="year">
                <?php foreach ($payrollYears as $y): ?>
                <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-accent">Apply</button>
            <a href="payroll.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-secondary"><?= e(t('payroll_generation')) ?></a>
        </div>
    </div>
</form>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:20px;">
    <?php if ($totals === []): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:#94a3b8;">∅</div>
        <div>
            <div class="stat-value">0</div>
            <div class="stat-label"><?= e(t('no_payroll_for_period')) ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:#6366f1;">👥</div>
        <div>
            <div class="stat-value"><?= number_format($totals['employees']) ?></div>
            <div class="stat-label"><?= e(t('employees')) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0ea5e9;">Σ</div>
        <div>
            <div class="stat-value"><?= e($sym) . money($totals['gross']) ?></div>
            <div class="stat-label"><?= e(t('gross_pay')) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#ef4444;">➖</div>
        <div>
            <div class="stat-value"><?= e($sym) . money($totals['deductions']) ?></div>
            <div class="stat-label"><?= e(t('total_deductions')) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#0d9488;">💰</div>
        <div>
            <div class="stat-value"><?= e($sym) . money($totals['net']) ?></div>
            <div class="stat-label"><?= e(t('total_net_pay')) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <h3 style="margin:0;"><?= e(t('journal_entry')) ?> — <?= e($entryNo) ?></h3>
        <?php if ($existingEntry): ?>
        <span class="badge badge-success">Posted</span>
        <?php elseif ($totals === []): ?>
        <span class="badge badge-warning"><?= e(t('no_payroll_for_period')) ?></span>
        <?php else: ?>
        <span class="badge badge-muted">Preview</span>
        <?php endif; ?>
    </div>

    <?php if ($totals === []): ?>
        <p style="margin:0; color:#64748b;"><?= e(t('no_payroll_for_period')) ?> <?= e(format_app_month_year($month, $year)) ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= e(t('account')) ?></th>
                    <th><?= e(t('description')) ?></th>
                    <th style="text-align:right;"><?= e(t('debit')) ?></th>
                    <th style="text-align:right;"><?= e(t('credit')) ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= e($defaults['expense'] ?: '—') ?> <?= e(t('salary_expense_account')) ?></td>
                    <td><?= e(t('gross_pay')) ?> — <?= number_format($totals['employees']) ?> employee(s)</td>
                    <td style="text-align:right;"><strong><?= money($totals['gross']) ?></strong></td>
                    <td style="text-align:right;">—</td>
                </tr>
                <tr>
                    <td><?= e($defaults['cash'] ?: '—') ?> <?= e(t('payment_account')) ?></td>
                    <td><?= e(t('net_pay')) ?></td>
                    <td style="text-align:right;">—</td>
                    <td style="text-align:right;"><strong><?= money($totals['net']) ?></strong></td>
                </tr>
                <?php if ($totals['loan'] > 0): ?>
                <tr>
                    <td><?= e($defaults['loan']) ?> <?= e(t('loan_account')) ?></td>
                    <td><?= e(t('loan_deduction')) ?></td>
                    <td style="text-align:right;">—</td>
                    <td style="text-align:right;"><strong><?= money($totals['loan']) ?></strong></td>
                </tr>
                <?php endif; ?>
                <?php if ($totals['other'] > 0): ?>
                <tr>
                    <td><?= e($defaults['deductions']) ?> <?= e(t('deductions_account')) ?></td>
                    <td><?= e(t('other_deductions')) ?></td>
                    <td style="text-align:right;">—</td>
                    <td style="text-align:right;"><strong><?= money($totals['other']) ?></strong></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:#e2e8f0; font-weight:700;">
                    <td colspan="2"><?= e(t('total')) ?></td>
                    <td style="text-align:right;"><?= money($totals['gross']) ?></td>
                    <td style="text-align:right;"><?= money($totals['gross']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p style="margin:12px 0 0; color:#64748b; font-size:0.85rem;">
        Entry date: <?= e(format_app_date($periodEnd)) ?> (period end). Loan recovery is what payroll actually withheld this period;
        withheld deductions stay in the ledger as a payable until they are paid out.
    </p>

    <?php if ($existingEntry): ?>
    <div style="margin-top:18px; padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px;">
        <strong><?= e(t('journal_already_posted')) ?></strong>
        <p style="margin:6px 0 0; color:#475569;">
            This period is already booked as <?= e($existingEntry['entry_no']) ?> on <?= e(format_app_date($existingEntry['entry_date'])) ?>.
        </p>
        <a href="journal_view.php?id=<?= (int) $existingEntry['id'] ?>" class="btn btn-secondary" style="margin-top:10px; display:inline-block;">View <?= e(t('journal_entry')) ?></a>
    </div>
    <?php elseif ($canPost): ?>
    <form method="post" class="no-print" style="margin-top:18px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
        <input type="hidden" name="post_payroll_journal" value="1">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:15px;">
            <div>
                <label><?= e(t('salary_expense_account')) ?></label>
                <select name="expense_account"><?= account_options($expenseAccounts, $defaults['expense']) ?></select>
            </div>
            <div>
                <label><?= e(t('payment_account')) ?></label>
                <select name="cash_account"><?= account_options($assetAccounts, $defaults['cash']) ?></select>
            </div>
            <?php if ($totals['loan'] > 0): ?>
            <div>
                <label><?= e(t('loan_account')) ?></label>
                <select name="loan_account"><?= account_options(array_merge($liabilityAccounts, $assetAccounts), $defaults['loan']) ?></select>
            </div>
            <?php endif; ?>
            <?php if ($totals['other'] > 0): ?>
            <div>
                <label><?= e(t('deductions_account')) ?></label>
                <select name="deductions_account"><?= account_options($liabilityAccounts, $defaults['deductions']) ?></select>
            </div>
            <?php endif; ?>
        </div>
        <p style="margin:12px 0; color:#64748b; font-size:0.85rem;">
            Defaults book gross pay to expense, net pay to cash, loan recovery to the employee loan receivable and withheld deductions to the deductions payable.
            Review the accounts above, then post. Posting once per period is enforced.
        </p>
        <button type="submit" class="btn">✅ <?= e(t('post_journal_entry')) ?></button>
    </form>
    <?php else: ?>
    <div style="margin-top:18px; padding:14px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px;">
        <strong>Read only</strong>
        <p style="margin:6px 0 0; color:#475569;">You can preview the payroll entry, but posting requires finance create permission.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
