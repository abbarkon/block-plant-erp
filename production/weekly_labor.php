<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../config/labor_settings.php";
require_login();
require_permission('production', 'view');
labor_ensure_schema();

$canEdit = has_permission('production', 'edit');
$cs = currency_symbol();

// ---- actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        set_flash('error', 'You do not have permission to change weekly labor payments.');
        header('Location: weekly_labor.php');
        exit;
    }
    if (isset($_POST['save_settings'])) {
        $rate = round(max(0, (float) ($_POST['rate_per_bag'] ?? 0)), 4);
        $kg = (float) ($_POST['kg_per_bag'] ?? 50);
        labor_save_setting('rate_per_bag', $rate);
        labor_save_setting('kg_per_bag', $kg > 0 ? $kg : 50);
        log_activity('update', 'production', 0, 'Labor rate per cement bag set to ' . $rate);
        set_flash('success', 'Labor rate saved.');
        header('Location: weekly_labor.php?week=' . urlencode($_POST['week'] ?? ''));
        exit;
    }
    if (isset($_POST['record_payment'])) {
        [$ws, $we] = labor_week_bounds($_POST['week'] ?? '');
        if (labor_payment_for_week($we)) {
            set_flash('error', 'This week has already been recorded as paid.');
        } else {
            $sum = labor_week_summary($ws, $we);
            if ($sum['due'] <= 0) {
                set_flash('error', 'Nothing to pay for this week (no cement used or no rate set).');
            } else {
                $amount = round($sum['due'], 2);
                $ref = 'LAB-' . $we;
                $postedJournal = false;
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO weekly_labor_payments(week_start, week_end, cement_bags, rate_per_bag, blocks_produced, amount, paid_at, note, created_by) VALUES(?,?,?,?,?,?,NOW(),?,?)")
                        ->execute([$ws, $we, round($sum['bags'], 2), $sum['rate'], $sum['blocks'], $amount, trim($_POST['note'] ?? ''), $_SESSION['user_id']]);
                    // Money out in the cashbook.
                    post_expense_cashbook($ref, $we, 'Weekly direct labor ' . $ws . ' to ' . $we . ' (' . round($sum['bags'], 1) . ' cement bags)', $amount, 'cash', (int) $_SESSION['user_id']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    set_flash('error', 'The payment could not be recorded: ' . $e->getMessage());
                    header('Location: weekly_labor.php?week=' . urlencode($we));
                    exit;
                }
                // Settle the accrued direct labor liability (labor was accrued when each run was saved).
                try {
                    $postedJournal = (bool) post_accounting_journal($ref, $we, 'Weekly direct labor paid ' . $ws . ' to ' . $we, $ref, [
                        ['2160', $amount, 0, 'Accrued direct labor paid'],
                        ['1110', 0, $amount, 'Cash paid for direct labor'],
                    ], (int) $_SESSION['user_id']);
                } catch (Throwable $e) {
                    $postedJournal = false;
                }
                log_activity('create', 'production', 0, "Weekly labor paid {$ws} to {$we}: {$amount}");
                set_flash('success', 'Weekly labor payment recorded in the cashbook' . ($postedJournal ? ' and the accounts.' : '. The accounting entry was skipped - check that accounts 2160 and 1110 exist.'));
            }
        }
        header('Location: weekly_labor.php?week=' . urlencode($we));
        exit;
    }
    if (isset($_POST['delete_payment'])) {
        $pid = (int) $_POST['delete_payment'];
        $st = $pdo->prepare("SELECT * FROM weekly_labor_payments WHERE id = ?");
        $st->execute([$pid]);
        $pay = $st->fetch();
        if ($pay) {
            $ref = 'LAB-' . $pay['week_end'];
            $pdo->prepare("DELETE FROM cashbook WHERE transaction_type = 'expense' AND reference_no = ?")->execute([$ref]);
            try {
                post_accounting_journal($ref . '-REV-' . date('YmdHis'), date('Y-m-d'), 'Reversal of weekly direct labor payment ' . $pay['week_start'] . ' to ' . $pay['week_end'], $ref, [
                    ['1110', (float) $pay['amount'], 0, 'Cash returned (payment removed)'],
                    ['2160', 0, (float) $pay['amount'], 'Accrued direct labor restored'],
                ], (int) $_SESSION['user_id']);
            } catch (Throwable $e) {
            }
            $pdo->prepare("DELETE FROM weekly_labor_payments WHERE id = ?")->execute([$pid]);
            log_activity('delete', 'production', $pid, 'Weekly labor payment removed: ' . $ref);
        }
        set_flash('success', 'Payment record removed and its cashbook entry reversed.');
        header('Location: weekly_labor.php');
        exit;
    }
}

$weekInput = $_GET['week'] ?? date('Y-m-d');
if (!is_valid_date_string($weekInput)) {
    $weekInput = date('Y-m-d');
}
[$ws, $we] = labor_week_bounds($weekInput);
$sum = labor_week_summary($ws, $we);
$paid = labor_payment_for_week($we);
$prevWeek = date('Y-m-d', strtotime('-7 days', strtotime($we)));
$nextWeek = date('Y-m-d', strtotime('+7 days', strtotime($we)));
$history = $pdo->query("SELECT * FROM weekly_labor_payments ORDER BY week_end DESC LIMIT 12")->fetchAll();
$flash = get_flash();
$pageTitle = 'Weekly Labor Payment';
require_once "../partials/header.php";
$costPerBlock = $sum['blocks'] > 0 ? $sum['due'] / $sum['blocks'] : 0;
?>
<div class="page-header">
    <div>
        <h1>Weekly Labor Payment</h1>
        <p style="color:#64748b;margin:6px 0 0;">Paid every Saturday at 5:00 PM, by the cement bags used in production.</p>
    </div>
    <div class="header-actions no-print">
        <a href="?week=<?= e($prevWeek) ?>" class="btn btn-secondary">&larr; Previous week</a>
        <a href="?week=<?= e($nextWeek) ?>" class="btn btn-secondary">Next week &rarr;</a>
        <button type="button" class="btn btn-accent" onclick="window.print()">Print</button>
    </div>
</div>
<?php if ($flash): ?><div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<p style="font-size:1.05rem;margin:0 0 14px;"><strong>Week:</strong> <?= e(format_date($ws)) ?> to <?= e(format_date($we)) ?> (pay day Saturday)
<?php if ($paid): ?> <span class="badge badge-success">Paid <?= e(format_datetime($paid['paid_at'])) ?></span><?php else: ?> <span class="badge badge-warning">Not paid yet</span><?php endif; ?></p>

<?php if (!$sum['cement']): ?>
<div class="panel">No raw material with "cement" in its name was found, so labor cannot be calculated by cement.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px;">
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Cement used</div><div style="font-size:1.6rem;font-weight:800;"><?= number_format($sum['bags'], 1) ?> bags</div></div>
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Rate per bag</div><div style="font-size:1.6rem;font-weight:800;"><?= $cs . money($sum['rate']) ?></div></div>
    <div class="panel" style="padding:16px;border:2px solid #0e6a78;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Total labor to pay</div><div style="font-size:1.8rem;font-weight:800;color:#0e6a78;"><?= $cs . money($sum['due']) ?></div></div>
    <div class="panel" style="padding:16px;"><div style="font-size:.76rem;color:#62767e;text-transform:uppercase;font-weight:700;">Blocks produced</div><div style="font-size:1.6rem;font-weight:800;"><?= number_format($sum['blocks']) ?></div><div style="font-size:.8rem;color:#62767e;">labor / block: <?= $cs . number_format($costPerBlock, 4) ?></div></div>
</div>

<?php if ($canEdit && !$paid && $sum['due'] > 0): ?>
<div class="panel no-print" style="margin-bottom:18px;">
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Record this week as paid: <?= e($cs . money($sum['due'])) ?> ?');">
        <input type="hidden" name="record_payment" value="1">
        <input type="hidden" name="week" value="<?= e($we) ?>">
        <input type="text" name="note" placeholder="Note (optional)" style="flex:1;min-width:200px;">
        <button type="submit" class="btn btn-accent">Record payment of <?= $cs . money($sum['due']) ?></button>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h3>By day</h3>
    <div class="table-container"><table class="data-table">
        <thead><tr><th>Date</th><th style="text-align:right">Runs</th><th style="text-align:right">Blocks</th><th style="text-align:right">Cement bags</th><th style="text-align:right">Labor</th></tr></thead>
        <tbody>
        <?php foreach ($sum['days'] as $d => $row): ?>
            <tr><td><?= e(format_date($d)) ?> <small style="color:#62767e;"><?= e(date('D', strtotime($d))) ?></small></td>
                <td style="text-align:right"><?= $row['runs'] ?></td><td style="text-align:right"><?= number_format($row['blocks']) ?></td>
                <td style="text-align:right"><?= number_format($row['bags'], 1) ?></td><td style="text-align:right"><?= $cs . money($row['due']) ?></td></tr>
        <?php endforeach; if (!$sum['days']): ?><tr><td colspan="5">No production this week.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($sum['days']): ?><tfoot><tr style="font-weight:800;"><td>Total</td><td></td><td style="text-align:right"><?= number_format($sum['blocks']) ?></td><td style="text-align:right"><?= number_format($sum['bags'], 1) ?></td><td style="text-align:right"><?= $cs . money($sum['due']) ?></td></tr></tfoot><?php endif; ?>
    </table></div>
</div>

<div class="panel" style="margin-top:18px;">
    <h3>By production run</h3>
    <div class="table-container"><table class="data-table">
        <thead><tr><th>Run</th><th>Date</th><th>Block</th><th style="text-align:right">Blocks</th><th style="text-align:right">Cement bags</th><th style="text-align:right">Labor (by cement)</th><th style="text-align:right">Labor on run</th></tr></thead>
        <tbody>
        <?php foreach ($sum['runs'] as $r): ?>
            <tr><td><a href="view.php?id=<?= (int) $r['id'] ?>"><?= e($r['production_no']) ?></a></td><td><?= e(format_date($r['production_date'])) ?></td>
                <td><?= e($r['name'] . ' ' . $r['size']) ?></td><td style="text-align:right"><?= number_format((int) $r['good_quantity']) ?></td>
                <td style="text-align:right"><?= number_format($r['bags'], 1) ?></td><td style="text-align:right"><?= $cs . money($r['due']) ?></td>
                <td style="text-align:right;color:#62767e;"><?= $cs . money($r['labor_cost']) ?></td></tr>
        <?php endforeach; if (!$sum['runs']): ?><tr><td colspan="7">No runs this week.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <p style="font-size:.82rem;color:#62767e;margin:10px 0 0;">"Labor on run" is the labor cost saved on each run. If it differs from the cement-based amount, edit the run or set the rate below so new runs fill it in automatically.</p>
</div>

<?php if ($canEdit): ?>
<div class="panel no-print" style="margin-top:18px;">
    <h3>Labor rate</h3>
    <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="save_settings" value="1">
        <input type="hidden" name="week" value="<?= e($we) ?>">
        <div><label>Pay per cement bag (<?= e($cs) ?>)</label><input type="number" name="rate_per_bag" min="0" step="0.01" value="<?= e(labor_rate_per_bag()) ?>" required></div>
        <div><label>Kg per bag</label><input type="number" name="kg_per_bag" min="1" step="0.5" value="<?= e(labor_kg_per_bag()) ?>" required></div>
        <button type="submit" class="btn btn-accent">Save rate</button>
    </form>
    <p style="font-size:.82rem;color:#62767e;margin:8px 0 0;">Used for this page and to fill in Direct labor cost on New Production Run (cement bags x rate).</p>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:18px;">
    <h3>Payment history</h3>
    <div class="table-container"><table class="data-table">
        <thead><tr><th>Week</th><th style="text-align:right">Bags</th><th style="text-align:right">Rate</th><th style="text-align:right">Blocks</th><th style="text-align:right">Paid</th><th>Paid on</th><?php if ($canEdit): ?><th class="no-print"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td><a href="?week=<?= e($h['week_end']) ?>"><?= e(format_date($h['week_start'])) ?> - <?= e(format_date($h['week_end'])) ?></a></td>
                <td style="text-align:right"><?= number_format((float) $h['cement_bags'], 1) ?></td><td style="text-align:right"><?= $cs . money($h['rate_per_bag']) ?></td>
                <td style="text-align:right"><?= number_format((int) $h['blocks_produced']) ?></td><td style="text-align:right;font-weight:700"><?= $cs . money($h['amount']) ?></td>
                <td><?= e(format_datetime($h['paid_at'])) ?></td>
                <?php if ($canEdit): ?><td class="no-print"><form method="post" style="display:inline" onsubmit="return confirm('Remove this payment? Its cashbook entry will be deleted and the accounting entry reversed.');"><input type="hidden" name="delete_payment" value="<?= (int) $h['id'] ?>"><button type="submit" class="btn-sm" style="background:#c0392b;color:#fff;border:0;cursor:pointer;">Remove</button></form></td><?php endif; ?></tr>
        <?php endforeach; if (!$history): ?><tr><td colspan="7">No payments recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<style>@media print{.no-print,.navbar,.sidebar,.topbar{display:none!important}body{background:#fff}}</style>
<?php require_once "../partials/footer.php"; ?>
