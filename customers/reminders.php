<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('customers', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    reminder_date DATE NOT NULL,
    due_amount DECIMAL(12,2) DEFAULT 0,
    followup_type VARCHAR(30) DEFAULT 'payment',
    status VARCHAR(20) DEFAULT 'pending',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    resolved_at DATETIME NULL,
    last_action_by INT NULL,
    last_action_at DATETIME NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

$reminderColumns = array_column($pdo->query("SHOW COLUMNS FROM customer_reminders")->fetchAll(), 'Field');
foreach ([
    'sent_at' => "ALTER TABLE customer_reminders ADD COLUMN sent_at DATETIME NULL",
    'resolved_at' => "ALTER TABLE customer_reminders ADD COLUMN resolved_at DATETIME NULL",
    'last_action_by' => "ALTER TABLE customer_reminders ADD COLUMN last_action_by INT NULL",
    'last_action_at' => "ALTER TABLE customer_reminders ADD COLUMN last_action_at DATETIME NULL",
] as $column => $alterSql) {
    if (!in_array($column, $reminderColumns, true)) {
        $pdo->exec($alterSql);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('customers', 'edit');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_reminder') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $date = trim($_POST['reminder_date'] ?? '');
            $dueInput = trim($_POST['due_amount'] ?? '0');
            $type = $_POST['followup_type'] ?? 'payment';
            $notes = trim($_POST['notes'] ?? '');
            $parsedDate = DateTime::createFromFormat('Y-m-d', $date);

            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || !is_numeric($dueInput) || (float) $dueInput < 0) {
                throw new InvalidArgumentException('Enter a valid reminder date and a non-negative due amount.');
            }
            if (!in_array($type, ['payment', 'delivery', 'invoice', 'other'], true)) {
                throw new InvalidArgumentException('Select a valid follow-up type.');
            }

            $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 1");
            $customerStmt->execute([$customerId]);
            if (!$customerStmt->fetchColumn()) {
                throw new InvalidArgumentException('Select an active customer.');
            }

            $insert = $pdo->prepare("INSERT INTO customer_reminders (customer_id, reminder_date, due_amount, followup_type, status, notes, created_by) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
            $insert->execute([$customerId, $date, (float) $dueInput, $type, $notes, (int) $_SESSION['user_id']]);
            set_flash('success', 'Reminder scheduled as pending.');
        } elseif ($action === 'transition') {
            $reminderId = (int) ($_POST['reminder_id'] ?? 0);
            $nextStatus = $_POST['next_status'] ?? '';
            $transitions = [
                'sent' => ['pending'],
                'followed_up' => ['sent'],
                'resolved' => ['pending', 'sent', 'followed_up'],
                'pending' => ['resolved'],
            ];
            if (!isset($transitions[$nextStatus])) {
                throw new InvalidArgumentException('Invalid reminder action.');
            }

            $pdo->beginTransaction();
            $currentStmt = $pdo->prepare("SELECT status FROM customer_reminders WHERE id = ? FOR UPDATE");
            $currentStmt->execute([$reminderId]);
            $currentStatus = $currentStmt->fetchColumn();
            if (!$currentStatus || !in_array($currentStatus, $transitions[$nextStatus], true)) {
                throw new InvalidArgumentException('This reminder has already changed. Refresh the page and try again.');
            }

            $update = $pdo->prepare("UPDATE customer_reminders SET status = ?, sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END, resolved_at = CASE WHEN ? = 'resolved' THEN NOW() WHEN ? = 'pending' THEN NULL ELSE resolved_at END, last_action_by = ?, last_action_at = NOW() WHERE id = ?");
            $update->execute([$nextStatus, $nextStatus, $nextStatus, $nextStatus, (int) $_SESSION['user_id'], $reminderId]);
            $pdo->commit();
            set_flash('success', $nextStatus === 'pending' ? 'Reminder reopened.' : 'Reminder updated to ' . str_replace('_', ' ', $nextStatus) . '.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Unable to update the reminder. Please try again.');
    }

    header('Location: reminders.php');
    exit;
}

$customers = $pdo->query("SELECT c.id, c.name, c.email, c.phone, COALESCE(b.balance, 0) AS outstanding_balance FROM customers c LEFT JOIN (SELECT customer_id, SUM(balance) AS balance FROM sales WHERE balance > 0 GROUP BY customer_id) b ON b.customer_id = c.id WHERE c.status = 1 ORDER BY c.name")->fetchAll();
$stats = $pdo->query("SELECT SUM(status IN ('pending','sent','followed_up') AND reminder_date < CURDATE()) AS overdue, SUM(status IN ('pending','sent','followed_up') AND reminder_date = CURDATE()) AS due_today, SUM(status = 'pending') AS pending, SUM(status = 'resolved') AS resolved FROM customer_reminders")->fetch() ?: [];

$statusFilter = $_GET['status'] ?? 'open';
$allowedFilters = ['open', 'all', 'pending', 'sent', 'followed_up', 'resolved'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'open';
}
$search = trim($_GET['search'] ?? '');
$reminderSql = "SELECT cr.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, actor.name AS last_action_name FROM customer_reminders cr JOIN customers c ON c.id = cr.customer_id LEFT JOIN users actor ON actor.id = cr.last_action_by WHERE 1=1";
$reminderParams = [];
if ($statusFilter === 'open') {
    $reminderSql .= " AND cr.status IN ('pending', 'sent', 'followed_up')";
} elseif ($statusFilter !== 'all') {
    $reminderSql .= " AND cr.status = ?";
    $reminderParams[] = $statusFilter;
}
if ($search !== '') {
    $reminderSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR cr.notes LIKE ?)";
    $like = '%' . $search . '%';
    array_push($reminderParams, $like, $like, $like);
}
$reminderSql .= " ORDER BY cr.created_at DESC, CASE WHEN cr.status IN ('pending','sent','followed_up') AND cr.reminder_date < CURDATE() THEN 0 WHEN cr.status IN ('pending','sent','followed_up') AND cr.reminder_date = CURDATE() THEN 1 ELSE 2 END, cr.reminder_date ASC LIMIT 100";
$reminderStmt = $pdo->prepare($reminderSql);
$reminderStmt->execute($reminderParams);
$reminders = $reminderStmt->fetchAll();

$overdue = $pdo->query("SELECT c.id, c.name, c.phone, COUNT(s.id) AS invoice_count, SUM(s.balance) AS balance, MIN(s.sale_date) AS oldest_invoice FROM customers c JOIN sales s ON s.customer_id = c.id WHERE c.status = 1 AND s.balance > 0 AND s.sale_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY c.id, c.name, c.phone ORDER BY balance DESC LIMIT 10")->fetchAll();
$flash = get_flash();
$pageTitle = 'Customer Reminders';
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Customer Reminders</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track payment follow-ups and collection actions.</p>
    </div>
    <div class="header-actions">
        <?php if (has_permission('customers', 'edit')): ?><a href="#schedule-reminder" class="btn btn-accent">Schedule reminder</a><?php endif; ?>
        <a href="index.php" class="btn btn-secondary">Back to customers</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="reminder-kpis">
    <div class="reminder-kpi overdue"><span>Overdue</span><strong><?= number_format((int) ($stats['overdue'] ?? 0)) ?></strong></div>
    <div class="reminder-kpi today"><span>Due today</span><strong><?= number_format((int) ($stats['due_today'] ?? 0)) ?></strong></div>
    <div class="reminder-kpi pending"><span>Pending</span><strong><?= number_format((int) ($stats['pending'] ?? 0)) ?></strong></div>
    <div class="reminder-kpi resolved"><span>Resolved</span><strong><?= number_format((int) ($stats['resolved'] ?? 0)) ?></strong></div>
</div>

<?php if (has_permission('customers', 'edit')): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3 id="schedule-reminder">Schedule reminder</h3>
    <form method="post" class="grid-2">
        <input type="hidden" name="action" value="add_reminder">
        <div>
            <label for="customer_id">Customer</label>
            <select name="customer_id" required>
                <option value="">Select customer</option>
                <?php foreach ($customers as $customer): ?>
                <option value="<?= (int) $customer['id'] ?>" data-balance="<?= e(number_format((float) $customer['outstanding_balance'], 2, '.', '')) ?>"><?= e($customer['name']) ?><?= $customer['phone'] ? ' · ' . e($customer['phone']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="followup_type">Follow-up type</label>
            <select name="followup_type">
                <option value="payment">Payment Follow-up</option>
                <option value="delivery">Delivery Follow-up</option>
                <option value="invoice">Invoice Check</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div>
            <label for="reminder_date">Reminder date</label>
            <input type="date" name="reminder_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div>
            <label for="due_amount">Outstanding amount</label>
            <input type="number" step="0.01" name="due_amount" min="0" value="0">
            <small class="reminder-help">Selecting a customer fills their current unpaid invoice balance; adjust if this follow-up is for a different amount.</small>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Notes</label>
            <textarea name="notes" rows="3" placeholder="Invoice reference, agreed payment date, or follow-up details"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save reminder</button>
        </div>
    </form>
</div>
<script>
document.querySelector('select[name="customer_id"]')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    document.querySelector('input[name="due_amount"]').value = selected.dataset.balance || '0';
});
</script>
<?php else: ?>
<div class="panel"><p>You have view-only access. Customer edit permission is required to schedule or update reminders.</p></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:20px;">
    <div class="reminder-section-heading"><div><h3>Overdue customer invoices</h3><p>Outstanding invoices older than 30 days.</p></div><a href="../reports/aging.php" class="btn-sm">Open aging report</a></div>
    <table class="data-table">
        <thead>
            <tr><th>Customer</th><th>Oldest invoice</th><th>Invoices</th><th>Outstanding</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if (!$overdue): ?>
                <tr><td colspan="5">No invoices are more than 30 days overdue.</td></tr>
            <?php endif; ?>
            <?php foreach ($overdue as $row): ?>
            <tr>
                <td><a href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['name']) ?></a><?= $row['phone'] ? '<small class="reminder-help">' . e($row['phone']) . '</small>' : '' ?></td>
                <td><?= e(format_date($row['oldest_invoice'])) ?></td>
                <td><?= number_format((int) $row['invoice_count']) ?></td>
                <td><?= e(currency_symbol()) . money($row['balance']) ?></td>
                <td><?php if (has_permission('customers', 'edit')): ?><a href="#schedule-reminder" class="btn-sm" onclick="document.querySelector('select[name=customer_id]').value='<?= (int) $row['id'] ?>';document.querySelector('select[name=customer_id]').dispatchEvent(new Event('change'))">Schedule</a><?php else: ?><a href="view.php?id=<?= (int) $row['id'] ?>" class="btn-sm">View</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="panel">
    <div class="reminder-section-heading"><div><h3>Reminder queue</h3><p>Contact the customer, then record each follow-up step here.</p></div><form method="get" class="reminder-filters"><input type="search" name="search" value="<?= e($search) ?>" placeholder="Customer, phone, notes"><select name="status"><option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open reminders</option><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All reminders</option><option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option><option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option><option value="followed_up" <?= $statusFilter === 'followed_up' ? 'selected' : '' ?>>Followed up</option><option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option></select><button class="btn btn-secondary" type="submit">Filter</button></form></div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Type</th>
                <th>Date</th>
                <th>Due</th>
                <th>Status</th>
                <th>Notes / last action</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$reminders): ?>
                <tr><td colspan="7">No reminders match this view.</td></tr>
            <?php endif; ?>
            <?php foreach ($reminders as $reminder): ?>
            <?php 
            $isReminderOverdue = in_array($reminder['status'], ['pending', 'sent', 'followed_up'], true) && $reminder['reminder_date'] < date('Y-m-d');
            $isRecent = (time() - strtotime($reminder['created_at'])) <= 86400 && $reminder['status'] !== 'resolved'; // 24 hours and not resolved
            $rowStyle = '';
            if ($isRecent) {
                $rowStyle = 'background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-left: 4px solid #0ea5e9; box-shadow: 0 2px 4px rgba(14, 165, 233, 0.1);';
            }
            ?>
            <tr style="<?= $rowStyle ?>">
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div>
                            <a href="view.php?id=<?= (int) $reminder['customer_id'] ?>"><?= e($reminder['customer_name']) ?></a>
                            <?php if ($reminder['customer_phone']): ?><small class="reminder-help"><?= e($reminder['customer_phone']) ?></small><?php endif; ?>
                        </div>
                        <?php if ((time() - strtotime($reminder['created_at'])) <= 86400 && $reminder['status'] !== 'resolved'): // 24 hours and not resolved ?>
                        <span class="badge" style="background: #0ea5e9; color: white; font-size: 0.7rem; padding: 2px 6px;">NEW</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td><?= e(ucfirst($reminder['followup_type'])) ?></td>
                <td><?= format_date($reminder['reminder_date']) ?></td>
                <td><?= e(currency_symbol()) . money($reminder['due_amount']) ?></td>
                <td>
                    <?php $isReminderOverdue = in_array($reminder['status'], ['pending', 'sent', 'followed_up'], true) && $reminder['reminder_date'] < date('Y-m-d'); ?>
                    <span class="badge badge-<?= $reminder['status'] === 'resolved' ? 'success' : ($isReminderOverdue ? 'danger' : ($reminder['status'] === 'sent' ? 'info' : ($reminder['status'] === 'followed_up' ? 'warning' : 'muted'))) ?>"><?= $isReminderOverdue ? 'Overdue · ' : '' ?><?= e(ucwords(str_replace('_', ' ', $reminder['status']))) ?></span>
                </td>
                <td><?= e($reminder['notes'] ?: '—') ?><?php if ($reminder['last_action_at']): ?><small class="reminder-help">Updated <?= e(format_datetime($reminder['last_action_at'])) ?><?= $reminder['last_action_name'] ? ' by ' . e($reminder['last_action_name']) : '' ?></small><?php endif; ?></td>
                <td class="reminder-actions">
                    <?php if (has_permission('customers', 'edit')): ?>
                        <?php if ($reminder['status'] === 'pending'): ?><form method="post"><input type="hidden" name="action" value="transition"><input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>"><input type="hidden" name="next_status" value="sent"><button class="btn-sm" type="submit">Mark sent</button></form><?php endif; ?>
                        <?php if ($reminder['status'] === 'sent'): ?><form method="post"><input type="hidden" name="action" value="transition"><input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>"><input type="hidden" name="next_status" value="followed_up"><button class="btn-sm" type="submit">Log follow-up</button></form><?php endif; ?>
                        <?php if (in_array($reminder['status'], ['pending', 'sent', 'followed_up'], true)): ?><form method="post"><input type="hidden" name="action" value="transition"><input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>"><input type="hidden" name="next_status" value="resolved"><button class="btn-sm reminder-resolve" type="submit">Resolve</button></form><?php else: ?><form method="post"><input type="hidden" name="action" value="transition"><input type="hidden" name="reminder_id" value="<?= (int) $reminder['id'] ?>"><input type="hidden" name="next_status" value="pending"><button class="btn-sm" type="submit">Reopen</button></form><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.reminder-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}.reminder-kpi{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border:1px solid var(--bp-border);border-top:3px solid #246a73;border-radius:6px;background:#fff}.reminder-kpi span{color:#52616b}.reminder-kpi strong{font-size:1.35rem;color:#183b56}.reminder-kpi.overdue{border-top-color:#b91c1c}.reminder-kpi.today{border-top-color:#d97706}.reminder-kpi.resolved{border-top-color:#2a9d8f}.reminder-section-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}.reminder-section-heading h3{margin:0}.reminder-section-heading p{margin:4px 0 0;color:#667a80;font-size:.84rem}.reminder-help{display:block;margin-top:4px;color:#667a80;font-size:.78rem}.reminder-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.reminder-filters input,.reminder-filters select{width:auto;min-width:150px}.reminder-actions{white-space:nowrap}.reminder-actions form{display:inline-block;margin:0 4px 3px 0}.reminder-resolve{background:#2a9d8f;color:#fff}

/* New Entry Highlighting */
.data-table tr[style*="linear-gradient"] {
    position: relative;
    animation: highlightFadeIn 0.5s ease-in-out;
}

@keyframes highlightFadeIn {
    0% { 
        transform: translateX(-5px); 
        opacity: 0.7; 
    }
    100% { 
        transform: translateX(0); 
        opacity: 1; 
    }
}

.badge[style*="0ea5e9"] {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

@media(max-width:650px){.reminder-filters{width:100%;align-items:stretch;flex-direction:column}.reminder-filters input,.reminder-filters select{width:100%}.reminder-actions{white-space:normal}}
</style>

<?php require_once "../partials/footer.php"; ?>
