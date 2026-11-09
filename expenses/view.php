<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('expenses', 'view');
ensure_expense_approval_schema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT e.*, c.name AS created_by_name, a.name AS approved_by_name
    FROM expenses e
    LEFT JOIN users c ON c.id = e.created_by
    LEFT JOIN users a ON a.id = e.approved_by
    WHERE e.id = ?");
$stmt->execute([$id]);
$expense = $stmt->fetch();

if (!$expense) {
    render_not_found('Expense not found', 'That expense voucher does not exist or has been deleted.', '/block_plant/expenses/index.php', 'Back to expenses');
}

$cash = $pdo->prepare("SELECT * FROM cashbook WHERE transaction_type = 'expense' AND reference_no = ? ORDER BY id DESC LIMIT 1");
$cash->execute([$expense['voucher_no']]);
$cashEntry = $cash->fetch();

$activity = $pdo->prepare("SELECT al.*, u.name AS user_name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.module = 'expenses' AND al.record_id = ? ORDER BY al.created_at DESC LIMIT 20");
$activity->execute([$id]);
$history = $activity->fetchAll();

$company = company_settings();
$status = $expense['approval_status'] ?: 'approved';
$flash = get_flash();
$pageTitle = 'Expense ' . $expense['voucher_no'];
require_once "../partials/header.php";
?>

<style>
    .voucher { max-width:920px; margin:0 auto; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 30px rgba(15,23,42,.07); overflow:hidden; }
    .voucher-head { display:flex; justify-content:space-between; gap:20px; padding:24px 26px; border-bottom:3px solid #b8481b; background:#f8fafc; flex-wrap:wrap; }
    .voucher-head h1 { margin:0; color:#1f2933; font-size:1.35rem; }
    .voucher-head p { margin:4px 0 0; color:#64748b; font-size:.86rem; }
    .voucher-body { padding:24px 26px; }
    .voucher-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px 22px; margin-bottom:22px; }
    @media (max-width: 760px) { .voucher-grid { grid-template-columns:1fr 1fr; } }
    .voucher-grid span { display:block; color:#64748b; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
    .voucher-grid strong { color:#1f2933; font-weight:600; }
    .amount-box { border:1px solid #e2e8f0; border-radius:10px; padding:16px; background:#f4efe8; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
    .amount-box .amount { font-size:1.6rem; font-weight:800; color:#1f2933; }
    .note-box { border-left:4px solid #b45309; background:#fffbeb; padding:12px 14px; border-radius:8px; margin-bottom:20px; color:#78350f; font-size:.88rem; }
    .history-list { list-style:none; margin:0; padding:0; }
    .history-list li { padding:10px 0; border-bottom:1px solid #eef2f7; font-size:.86rem; display:flex; gap:12px; }
    .history-list li:last-child { border-bottom:none; }
    .history-list .when { color:#64748b; min-width:135px; }
    @media print {
        .no-print, nav, footer { display:none !important; }
        body { background:#fff; }
        .voucher { box-shadow:none; border:none; max-width:100%; }
        .voucher-head { background:#fff; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1>Expense Voucher</h1>
        <p style="color:#64748b;margin:6px 0 0;"><?= e($expense['voucher_no']) ?> · <?= e(format_date($expense['expense_date'])) ?></p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to expenses</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print voucher</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?> no-print"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="voucher">
    <div class="voucher-head">
        <div>
            <h1><?= e(company_display_name()) ?></h1>
            <p><?= e($company['address'] ?? '') ?><?= !empty($company['phone']) ? ' · ' . e($company['phone']) : '' ?><?= !empty($company['email']) ? ' · ' . e($company['email']) : '' ?></p>
        </div>
        <div style="text-align:right;">
            <div style="font-weight:800;color:#1f2933;letter-spacing:.06em;">EXPENSE VOUCHER</div>
            <p><?= e($expense['voucher_no']) ?></p>
            <span class="badge badge-<?= $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning') ?>"><?= e(ucfirst($status)) ?></span>
        </div>
    </div>

    <div class="voucher-body">
        <div class="amount-box">
            <div>
                <span style="display:block;color:#64748b;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;">Amount</span>
                <div class="amount"><?= e(currency_symbol()) . money($expense['amount']) ?></div>
            </div>
            <div style="text-align:right;color:#475569;font-size:.86rem;">
                <div><strong>Payment:</strong> <?= e(expense_payment_methods()[$expense['payment_method']] ?? ucfirst($expense['payment_method'])) ?></div>
                <div><strong>Date:</strong> <?= e(format_date($expense['expense_date'])) ?></div>
            </div>
        </div>

        <div class="voucher-grid">
            <div><span>Category</span><strong><?= e($expense['category']) ?></strong></div>
            <div><span>Reference</span><strong><?= e($expense['reference_no'] ?? '—') ?></strong></div>
            <div><span>Submitted by</span><strong><?= e($expense['created_by_name'] ?? 'System') ?></strong></div>
            <div><span>Submitted at</span><strong><?= e(!empty($expense['created_at']) ? format_datetime($expense['created_at']) : '—') ?></strong></div>
            <div><span>Decided by</span><strong><?= e($expense['approved_by_name'] ?? '—') ?></strong></div>
            <div><span>Decided at</span><strong><?= e(!empty($expense['approved_at']) ? format_datetime($expense['approved_at']) : '—') ?></strong></div>
        </div>

        <div style="margin-bottom:18px;">
            <span style="display:block;color:#64748b;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Description</span>
            <div style="color:#1f2933;line-height:1.55;"><?= nl2br(e($expense['description'])) ?></div>
        </div>
        <?php if (!empty($expense['notes'])): ?>
        <div style="margin-bottom:18px;">
            <span style="display:block;color:#64748b;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Internal notes</span>
            <div style="color:#334155;line-height:1.55;"><?= nl2br(e($expense['notes'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($expense['approval_notes'])): ?>
        <div class="note-box"><strong>Approval comment:</strong> <?= e($expense['approval_notes']) ?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
            <div>
                <h4 style="margin:0 0 8px;color:#1f2933;">Cashbook posting</h4>
                <?php if ($cashEntry): ?>
                <p style="margin:0;color:#475569;font-size:.88rem;">
                    Posted as <strong><?= e($cashEntry['transaction_type']) ?></strong> on <?= e(format_date($cashEntry['transaction_date'])) ?>
                    (<?= e(currency_symbol()) . money($cashEntry['amount']) ?>, <?= e(ucfirst($cashEntry['payment_method'])) ?>).
                </p>
                <?php elseif ($status === 'approved'): ?>
                <p style="margin:0;color:#b45309;font-size:.88rem;">Approved but no matching cashbook entry was found. Review the cashbook.</p>
                <?php else: ?>
                <p style="margin:0;color:#64748b;font-size:.88rem;">Posts to the cashbook only after approval.</p>
                <?php endif; ?>
            </div>
            <div>
                <h4 style="margin:0 0 8px;color:#1f2933;">Audit trail</h4>
                <?php if (!$history): ?>
                <p style="margin:0;color:#64748b;font-size:.88rem;">No audit entries yet.</p>
                <?php else: ?>
                <ul class="history-list">
                    <?php foreach ($history as $h): ?>
                    <li><span class="when"><?= e(format_datetime($h['created_at'])) ?></span><span><strong><?= e($h['user_name'] ?? 'System') ?></strong> · <?= e($h['action']) ?><br><span style="color:#64748b;"><?= e($h['description'] ?? '') ?></span></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($status === 'pending' && has_permission('expenses', 'approve')): ?>
<div class="panel no-print" style="max-width:920px;margin:20px auto 0;">
    <h3>Decide this expense</h3>
    <form method="post" action="approvals.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
        <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
        <div style="flex:1;min-width:240px;">
            <label>Comment</label>
            <input type="text" name="notes" maxlength="255" placeholder="Required when rejecting">
        </div>
        <div style="display:flex;gap:8px;align-items:end;">
            <button type="submit" name="action" value="approve" class="btn btn-accent">Approve</button>
            <button type="submit" name="action" value="reject" class="btn btn-danger" onclick="return confirm('Reject this expense?');">Reject</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
