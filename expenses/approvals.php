<?php
session_start();
require_once "../config/database.php";
require_once __DIR__ . "/../config/helpers.php";
require_login();
require_permission('expenses', 'view');
ensure_notifications_schema();
ensure_expense_approval_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['expense_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if (!has_permission('expenses', 'approve')) {
        set_flash('danger', 'You do not have permission to approve expenses.');
        header('Location: approvals.php');
        exit;
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        set_flash('danger', 'Unknown approval action.');
        header('Location: approvals.php');
        exit;
    }
    if (mb_strlen($notes) > 255) {
        set_flash('danger', 'The approval comment must be 255 characters or fewer.');
        header('Location: approvals.php');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND approval_status = 'pending' FOR UPDATE");
        $lock->execute([$id]);
        $expense = $lock->fetch();
        if (!$expense) {
            throw new Exception('Expense not found or already processed.');
        }
        if ((float) $expense['amount'] <= 0) {
            throw new Exception('Expense amount must be greater than zero before approval.');
        }

        if ($action === 'approve') {
            post_expense_cashbook(
                $expense['voucher_no'],
                $expense['expense_date'],
                $expense['description'],
                $expense['amount'],
                $expense['payment_method'],
                (int) $_SESSION['user_id']
            );
            $pdo->prepare("UPDATE expenses SET approval_status = 'approved', approved_by = ?, approved_at = NOW(), approval_notes = ? WHERE id = ?")
                ->execute([$_SESSION['user_id'], $notes ?: null, $id]);
            log_activity('expense_approved', 'expenses', $id, "Expense {$expense['voucher_no']} approved and posted to cashbook");
            $decisionMsg = "Your expense {$expense['voucher_no']} (" . currency_symbol() . money($expense['amount']) . ") was APPROVED" . ($notes ? ". Comment: {$notes}" : ".");
        } else {
            if ($notes === '') {
                throw new Exception('A reason is required to reject an expense.');
            }
            $pdo->prepare("UPDATE expenses SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(), approval_notes = ? WHERE id = ?")
                ->execute([$_SESSION['user_id'], $notes, $id]);
            log_activity('expense_rejected', 'expenses', $id, "Expense {$expense['voucher_no']} rejected: {$notes}");
            $decisionMsg = "Your expense {$expense['voucher_no']} (" . currency_symbol() . money($expense['amount']) . ") was REJECTED. Reason: {$notes}";
        }

        if (!empty($expense['created_by']) && (int) $expense['created_by'] !== (int) $_SESSION['user_id']) {
            notify_user((int) $expense['created_by'], $decisionMsg, '/block_plant/expenses/view.php?id=' . $id);
        }

        $pdo->commit();
        set_flash('success', $action === 'approve' ? 'Expense approved and posted to the cashbook.' : 'Expense rejected.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('danger', $e->getMessage());
    }
    header('Location: approvals.php');
    exit;
}

$pending = $pdo->query("SELECT e.*, u.name AS created_by_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.approval_status = 'pending' ORDER BY e.expense_date ASC, e.id ASC")->fetchAll();
$recent = $pdo->query("SELECT e.*, u.name AS created_by_name, a.name AS approved_by_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by LEFT JOIN users a ON a.id = e.approved_by WHERE e.approval_status IN ('approved','rejected') AND e.approved_at IS NOT NULL ORDER BY e.approved_at DESC LIMIT 30")->fetchAll();
$pendingTotal = array_sum(array_map(static fn($r) => (float) $r['amount'], $pending));

$monthApproved = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE approval_status = 'approved' AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$monthRejected = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE approval_status = 'rejected' AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

$flash = get_flash();
$pageTitle = 'Expense Approvals';
require_once __DIR__ . '/../partials/header.php';
?>
<style>
    .appr-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    @media (max-width: 950px) { .appr-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .appr-kpi { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #b8481b; border-radius:10px; padding:14px 16px; box-shadow:0 4px 14px rgba(15,23,42,.05); }
    .appr-kpi span { display:block; color:#64748b; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
    .appr-kpi strong { display:block; margin-top:4px; color:#1f2933; font-size:1.3rem; }
    .appr-kpi.alert { border-left-color:#b45309; }
    .appr-kpi.ok { border-left-color:#d9742f; }
    .decision-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:0; }
    .decision-form input { min-width:160px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } .appr-kpi, .table-container { box-shadow:none; } }
</style>

<div class="page-header">
    <div>
        <h1>✅ Expense Approvals</h1>
        <p style="color:#64748b;margin:6px 0 0;">Review staff-submitted expenses. Approved vouchers post to the cashbook automatically.</p>
    </div>
    <div class="header-actions no-print">
        <a class="btn btn-secondary" href="index.php">Back to expenses</a>
        <a class="btn btn-secondary" href="export_csv.php?status=pending&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">Export pending</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?><div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div class="appr-kpis">
    <div class="appr-kpi alert"><span>Awaiting approval</span><strong><?= number_format(count($pending)) ?></strong></div>
    <div class="appr-kpi alert"><span>Pending value</span><strong><?= e(currency_symbol()) . money($pendingTotal) ?></strong></div>
    <div class="appr-kpi ok"><span>Approved this month</span><strong><?= e(currency_symbol()) . money($monthApproved) ?></strong></div>
    <div class="appr-kpi"><span>Rejected this month</span><strong><?= number_format($monthRejected) ?></strong></div>
</div>

<div class="table-container">
    <h3 style="margin:18px 0 10px;">Awaiting approval</h3>
    <table class="data-table">
        <thead>
            <tr><th>Voucher</th><th>Date</th><th>Category</th><th>Description</th><th style="text-align:right;">Amount</th><th>Submitted by</th><th class="no-print">Decision</th></tr>
        </thead>
        <tbody>
            <?php if (!$pending): ?><tr><td colspan="7">No expenses are awaiting approval.</td></tr><?php endif; ?>
            <?php foreach ($pending as $r): ?>
            <tr>
                <td><a href="view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['voucher_no']) ?></strong></a></td>
                <td style="white-space:nowrap;"><?= e(format_date($r['expense_date'])) ?></td>
                <td><?= e($r['category']) ?></td>
                <td style="max-width:320px;"><?= e($r['description']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($r['amount']) ?></td>
                <td><?= e($r['created_by_name'] ?? '—') ?></td>
                <td class="no-print">
                    <?php if (has_permission('expenses', 'approve')): ?>
                    <form method="post" class="decision-form">
                        <input type="hidden" name="expense_id" value="<?= (int) $r['id'] ?>">
                        <input type="text" name="notes" maxlength="255" placeholder="Comment (required to reject)">
                        <button class="btn btn-sm btn-accent" type="submit" name="action" value="approve">Approve</button>
                        <button class="btn btn-sm btn-danger" type="submit" name="action" value="reject" onclick="return confirm('Reject this expense?');">Reject</button>
                    </form>
                    <?php else: ?>
                    <span style="color:#64748b;font-size:.84rem;">Awaiting manager review</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="table-container" style="margin-top:24px;">
    <h3 style="margin:18px 0 10px;">Recent decisions</h3>
    <table class="data-table">
        <thead>
            <tr><th>Voucher</th><th>Category</th><th style="text-align:right;">Amount</th><th>Submitted by</th><th>Status</th><th>Decided by</th><th>Decided at</th><th>Notes</th></tr>
        </thead>
        <tbody>
            <?php if (!$recent): ?><tr><td colspan="8">No approval decisions yet.</td></tr><?php endif; ?>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td><a href="view.php?id=<?= (int) $r['id'] ?>"><?= e($r['voucher_no']) ?></a></td>
                <td><?= e($r['category']) ?></td>
                <td style="text-align:right;"><?= e(currency_symbol()) . money($r['amount']) ?></td>
                <td><?= e($r['created_by_name'] ?? '—') ?></td>
                <td><span class="badge badge-<?= $r['approval_status'] === 'approved' ? 'success' : 'danger' ?>"><?= e(ucfirst($r['approval_status'])) ?></span></td>
                <td><?= e($r['approved_by_name'] ?? '—') ?></td>
                <td><?= e(!empty($r['approved_at']) ? format_datetime($r['approved_at']) : '—') ?></td>
                <td><?= e($r['approval_notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
