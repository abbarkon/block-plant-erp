<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('dashboard', 'view');
ensure_department_access_schema();
ensure_expense_approval_schema();
ensure_purchase_payment_schema();
$dashboardUser = current_user();
$isSystemAdmin = is_system_admin();
$dashboardDepartment = strtolower(trim((string) ($dashboardUser['department'] ?? '')));
$dashboardDepartmentRole = strtolower(trim((string) ($dashboardUser['department_role'] ?? '')));
if (!$isSystemAdmin && in_array($dashboardDepartmentRole, ['manager', 'user'], true) && isset(department_permission_modules()[$dashboardDepartment])) {
    require __DIR__ . '/department_overview.php';
    exit;
}

$pageTitle = t('dashboard');
require_once "../partials/header.php";

function v($p,$q,$a=[]){$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}

$d=date('Y-m-d');
$m=date('Y-m-01');

// Today's metrics
$prod=v($pdo,"SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date=?",[$d]);
$sales=v($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=?",[$d]);
$paid=v($pdo,"SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE sale_date=?",[$d]);
$exp=v($pdo,"SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=? AND (approval_status='approved' OR approval_status IS NULL)",[$d]);
$days_in_month = (int) date('t');
$monthly_labor_cost = (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN p.id IS NULL THEN e.basic_salary ELSE p.net_salary END), 0) FROM employees e LEFT JOIN payroll p ON p.employee_id = e.id AND p.month = MONTH(CURDATE()) AND p.year = YEAR(CURDATE()) WHERE e.status = 1")->fetchColumn();
$daily_labor_cost = $days_in_month > 0 ? $monthly_labor_cost / $days_in_month : 0;

// Month metrics
$month_sales=v($pdo,"SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date>=?",[$m]);
$month_exp=v($pdo,"SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=? AND (approval_status='approved' OR approval_status IS NULL)",[$m]);
$month_cogs=v($pdo,"SELECT COALESCE(SUM(cogs_amount),0) FROM sales WHERE sale_date>=?",[$m]);
$month_prod_cost=v($pdo,"SELECT COALESCE(SUM(production_cost),0) FROM production WHERE production_date>=?",[$m]);
// Match the P&L: revenue - cost of blocks sold - approved expenses. Production
// spend is a manufacturing metric, not a second COGS charge.
$month_profit = $month_sales - $month_cogs - $month_exp;

// Stock and balances
$stock=v($pdo,"SELECT COALESCE(SUM(quantity),0) FROM finished_stock");
$recv=v($pdo,"SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance>0");
// Rejected purchase orders are not liabilities.
$pay=v($pdo,"SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance>0 AND (approval_status IS NULL OR approval_status <> 'rejected')");

// Additional metrics
$total_customers=v($pdo,"SELECT COUNT(*) FROM customers WHERE status=1");
$total_employees=v($pdo,"SELECT COUNT(*) FROM employees WHERE status=1");

// Expired quotations notification
$expiredQuotations = [];
try {
    $expiredStmt = $pdo->prepare("SELECT quotation_no, expiry_date FROM quotations WHERE status = 'pending' AND expiry_date < CURDATE() ORDER BY expiry_date ASC LIMIT 10");
    $expiredStmt->execute();
    $expiredQuotations = $expiredStmt->fetchAll();
} catch (PDOException $e) {
    $expiredQuotations = [];
}

// Show expired quotation notification on dashboard
if (!empty($expiredQuotations) && has_permission('sales', 'view')) {
    $expiredCount = count($expiredQuotations);
    $expiredList = [];
    foreach ($expiredQuotations as $exp) {
        $expiredList[] = $exp['quotation_no'];
    }
    $expiredMessage = "You have <strong>{$expiredCount}</strong> expired quotations: " . implode(', ', $expiredList) . ".<br>These quotations are no longer valid for acceptance.";
    set_modal('warning', '⚠️ Expired Quotations', $expiredMessage);
    $showModal = true;
}

// Check for quotations expiring soon (within 7 days)
$expiringSoon = [];
try {
    $soonStmt = $pdo->prepare("SELECT quotation_no, expiry_date FROM quotations WHERE status = 'pending' AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY expiry_date ASC LIMIT 10");
    $soonStmt->execute();
    $expiringSoon = $soonStmt->fetchAll();
} catch (PDOException $e) {
    $expiringSoon = [];
}

// Show expiring soon notification if we have expired ones already, show both together
if (!empty($expiringSoon) && has_permission('sales', 'view') && empty($expiredQuotations)) {
    $soonCount = count($expiringSoon);
    $soonList = [];
    foreach ($expiringSoon as $exp) {
        $daysLeft = floor((strtotime($exp['expiry_date']) - strtotime(date('Y-m-d'))) / (60*60*24));
        $soonList[] = "{$exp['quotation_no']} ({$daysLeft} day" . ($daysLeft !== 1 ? 's' : '') . ")";
    }
    $soonMessage = "You have <strong>{$soonCount}</strong> quotations expiring soon: " . implode(', ', $soonList) . ".<br>Please review them before they expire.";
    set_modal('info', 'ℹ️ Quotations Expiring Soon', $soonMessage);
    $showModal = true;
}
$low_stock=v($pdo,"SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id=rl.material_id WHERE rm.current_stock<=rl.reorder_point");
$low_production = $pdo->query("SELECT bt.name, bt.size, SUM(ps.target_quantity) AS target_quantity, COALESCE(SUM(p.good_quantity), 0) AS produced_quantity, SUM(ps.target_quantity) - COALESCE(SUM(p.good_quantity), 0) AS shortfall FROM production_schedules ps JOIN block_types bt ON bt.id = ps.block_type_id LEFT JOIN production p ON p.block_type_id = ps.block_type_id AND p.production_date = ps.schedule_date WHERE ps.schedule_date = CURDATE() AND ps.status IN ('planned', 'in_progress') GROUP BY ps.block_type_id, bt.name, bt.size HAVING produced_quantity < target_quantity ORDER BY shortfall DESC")->fetchAll();
$plannedProduction = $pdo->query("SELECT bt.name, bt.size, SUM(p.planned_quantity) AS target_quantity, SUM(p.good_quantity) AS produced_quantity, SUM(p.planned_quantity) - SUM(p.good_quantity) AS shortfall FROM production p JOIN block_types bt ON bt.id = p.block_type_id WHERE p.production_date = CURDATE() AND p.planned_quantity > 0 GROUP BY p.block_type_id, bt.name, bt.size HAVING produced_quantity < target_quantity ORDER BY shortfall DESC")->fetchAll();
foreach ($plannedProduction as $plannedAlert) {
    $alreadyListed = false;
    foreach ($low_production as $scheduledAlert) {
        if ($scheduledAlert['name'] === $plannedAlert['name'] && $scheduledAlert['size'] === $plannedAlert['size']) {
            $alreadyListed = true;
            break;
        }
    }
    if (!$alreadyListed) {
        $low_production[] = $plannedAlert;
    }
}
usort($low_production, static fn($left, $right) => (int) $right['shortfall'] <=> (int) $left['shortfall']);
$task_reminders = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_reminders (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, description TEXT, priority VARCHAR(20) DEFAULT 'medium', status VARCHAR(20) DEFAULT 'pending', due_date DATE NULL, assigned_to INT NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $isAdmin = in_array(strtolower(trim((string) ($_SESSION['role'] ?? ''))), ['admin', 'administrator'], true);
    $taskScope = $isAdmin ? '1=1' : '(assigned_to IS NULL OR assigned_to = ?)';
    $taskQuery = "SELECT title, priority, status, due_date FROM task_reminders WHERE status != 'done' AND due_date IS NOT NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND {$taskScope} ORDER BY due_date ASC, FIELD(priority, 'high','medium','low') LIMIT 10";
    $taskStmt = $pdo->prepare($taskQuery);
    $taskStmt->execute($isAdmin ? [] : [$_SESSION['user_id']]);
    $task_reminders = $taskStmt->fetchAll();
} catch (PDOException $e) {
    $task_reminders = [];
}

// Recent activities
$recent_sales = $pdo->query("SELECT s.invoice_no, s.total_amount, s.sale_date, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id=c.id ORDER BY s.created_at DESC LIMIT 5")->fetchAll();
$recent_production = $pdo->query("SELECT p.production_no, p.good_quantity, p.production_date, bt.name as block_name FROM production p JOIN block_types bt ON p.block_type_id=bt.id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();

// Cross-module pending approvals widget
$canApprove = has_permission('purchases', 'approve') || has_permission('expenses', 'approve') || has_permission('gate', 'edit') || has_permission('hr', 'approve') || has_permission('finance', 'approve');
$pendingApprovals = ['purchases' => 0, 'expenses' => 0, 'gate' => 0, 'hr_leaves' => 0, 'hr_overtime' => 0, 'hr_exits' => 0, 'finance_loans' => 0, 'finance_payroll' => 0];
$pendingPurchasesValue = 0.0;
$pendingExpensesValue = 0.0;
if ($canApprove) {
    ensure_expense_approval_schema();
    if (has_permission('purchases', 'approve')) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(p.total_amount),0) AS v FROM purchase_approvals pa JOIN purchases p ON p.id = pa.purchase_id WHERE pa.status = 'pending' AND COALESCE(p.approval_status, 'pending') <> 'approved'")->fetch();
            $pendingApprovals['purchases'] = (int) $row['c'];
            $pendingPurchasesValue = (float) $row['v'];
        } catch (PDOException $e) { $pendingApprovals['purchases'] = 0; }
    }
    if (has_permission('expenses', 'approve')) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS v FROM expenses WHERE approval_status = 'pending'")->fetch();
            $pendingApprovals['expenses'] = (int) $row['c'];
            $pendingExpensesValue = (float) $row['v'];
        } catch (PDOException $e) { $pendingApprovals['expenses'] = 0; }
    }
    if (has_permission('gate', 'edit')) {
        try {
            $pendingApprovals['gate'] = (int) $pdo->query("SELECT COUNT(*) FROM gate_clearances WHERE status = 'REJECTED'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['gate'] = 0; }
    }
    if (has_permission('hr', 'approve')) {
        try {
            $pendingApprovals['hr_leaves'] = (int) $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['hr_leaves'] = 0; }
        try {
            $pendingApprovals['hr_overtime'] = (int) $pdo->query("SELECT COUNT(*) FROM overtime_requests WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['hr_overtime'] = 0; }
        try {
            ensure_employee_exit_clearance_schema();
            $pendingApprovals['hr_exits'] = (int) $pdo->query("SELECT COUNT(*) FROM employee_exit_clearances WHERE status = 'pending'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['hr_exits'] = 0; }
    }
    if (has_permission('finance', 'approve')) {
        try {
            $pendingApprovals['finance_loans'] = (int) $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE approval_status = 'pending'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['finance_loans'] = 0; }
        try {
            $pendingApprovals['finance_payroll'] = (int) $pdo->query("SELECT COUNT(*) FROM payroll WHERE workflow_status = 'transferred'")->fetchColumn();
        } catch (PDOException $e) { $pendingApprovals['finance_payroll'] = 0; }
    }
}
$pendingTotal = array_sum($pendingApprovals);
$canReports = has_permission('reports', 'view');
?>

<div class="dashboard-hero">
    <div>
        <h1 style="margin:0;"><?= t('dashboard') ?></h1>
        <p style="color: #64748b; margin: 6px 0 0;"><?= e(format_app_date('now', true)) ?></p>
    </div>
    <div class="header-actions">
        <?php if ($isSystemAdmin): ?>
        <a class="btn btn-accent" href="widgets.php"><?= t('advanced_analytics') ?></a>
        <a class="btn btn-secondary" href="insights.php"><?= t('operational_insights') ?></a>
        <a class="btn btn-secondary" href="operations.php"><?= t('operations_overview') ?></a>
        <a class="btn btn-secondary" href="forecast.php"><?= t('budget_forecast') ?></a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="tasks.php"><?= t('tasks_reminders') ?></a>
        <?php if (has_permission('expenses', 'view')): ?>
        <a class="btn btn-secondary" href="../expenses/recurring.php"><?= t('recurring_costs') ?></a>
        <?php endif; ?>
        <?php if (has_permission('purchases', 'view')): ?>
        <a class="btn btn-secondary" href="../purchases/approvals.php"><?= t('purchase_approvals') ?></a>
        <?php endif; ?>
        <?php if (has_permission('suppliers', 'view')): ?>
        <a class="btn btn-secondary" href="../suppliers/scorecards.php"><?= t('supplier_scorecards') ?></a>
        <?php endif; ?>
        <?php if (has_permission('crm', 'view')): ?>
        <a class="btn btn-secondary" href="../crm/pipeline.php"><?= t('sales_pipeline') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($low_stock > 0 || $low_production || $task_reminders): ?>
<div role="alert" style="margin:18px 0;padding:16px 20px;border:1px solid #f1b0b7;border-left:5px solid #dc3545;border-radius:8px;background:#fff5f5;color:#842029;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
        <strong style="font-size:16px;">🔔 <?= t('notifications') ?></strong>
        <div style="margin-top:6px;">
            <?php if ($low_stock > 0): ?>
                <span><?= number_format($low_stock) ?> <?= t($low_stock === 1 ? 'material_below_reorder_one' : 'materials_below_reorder_many') ?></span>
            <?php endif; ?>
            <?php if ($low_stock > 0 && $low_production): ?><span> </span><?php endif; ?>
            <?php if ($low_production): ?>
                <span><?= number_format(count($low_production)) ?> <?= t(count($low_production) === 1 ? 'block_type_below_target_one' : 'block_types_below_target_many') ?></span>
                <?php foreach ($low_production as $alertIndex => $productionAlert): ?>
                    <strong><?= e($productionAlert['name'] . ' ' . $productionAlert['size']) ?> (<?= t('shortfall') ?> <?= number_format((int) $productionAlert['shortfall']) ?>)</strong><?= $alertIndex === count($low_production) - 1 ? '.' : ',' ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($task_reminders): ?>
                <span><?= count($task_reminders) ?> <?= t('tasks_overdue_due_soon') ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($low_stock > 0): ?><a class="btn btn-secondary" href="../inventory/low_stock_alert.php"><?= t('view_stock_alerts') ?></a><?php endif; ?>
        <?php if ($low_production): ?><a class="btn btn-secondary" href="../production/schedule.php"><?= t('view_production') ?></a><?php endif; ?>
        <?php if ($task_reminders): ?><a class="btn btn-secondary" href="tasks.php"><?= t('view_tasks') ?></a><?php endif; ?>
    </div>
</div>
<div id="dashboardAlertToast" role="alert" aria-live="assertive" style="position:fixed;right:24px;top:24px;z-index:1000;width:min(420px,calc(100vw - 32px));padding:18px 20px;background:#fff;border:1px solid #f1b0b7;border-left:5px solid #dc3545;border-radius:10px;box-shadow:0 12px 35px rgba(127,29,29,.22);color:#842029;">
    <button type="button" aria-label="<?= e(t('close_notification')) ?>" onclick="document.getElementById('dashboardAlertToast').remove()" style="float:right;border:0;background:transparent;color:#842029;font-size:20px;line-height:1;cursor:pointer;">&times;</button>
    <strong style="display:block;margin-bottom:7px;">🔔 <?= t('attention_required') ?></strong>
    <?php if ($low_stock > 0): ?><div><?= number_format($low_stock) ?> <?= t($low_stock === 1 ? 'material_below_reorder_one' : 'materials_below_reorder_many') ?></div><?php endif; ?>
    <?php if ($low_production): ?>
        <div><?= number_format(count($low_production)) ?> <?= t(count($low_production) === 1 ? 'block_type_below_target_one' : 'block_types_below_target_many') ?></div>
        <ul style="margin:8px 0 0;padding-left:20px;">
            <?php foreach ($low_production as $productionAlert): ?>
                <li><?= e($productionAlert['name'] . ' ' . $productionAlert['size']) ?>: <?= t('shortfall') ?> <?= number_format((int) $productionAlert['shortfall']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($task_reminders): ?>
        <div style="margin-top:10px;"><?= count($task_reminders) ?> <?= t('tasks_need_attention') ?></div>
        <ul style="margin:8px 0 0;padding-left:20px;">
            <?php foreach ($task_reminders as $task): ?>
                <li><?= e($task['title']) ?><?= $task['due_date'] < date('Y-m-d') ? ' (overdue)' : ' (due ' . format_date($task['due_date']) . ')' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script>
    window.setTimeout(function () {
        const toast = document.getElementById('dashboardAlertToast');
        if (toast) toast.remove();
    }, 12000);
</script>
<?php endif; ?>

<style>
.dashboard-hero{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:flex-end;gap:22px;margin:0 0 24px;padding:30px 32px;border-radius:18px;background:linear-gradient(120deg,#183b56 0%,#246a73 62%,#2a9d8f 100%);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.18)}
.dashboard-hero:after{content:"";position:absolute;width:220px;height:220px;right:-70px;top:-100px;border:30px solid rgba(255,255,255,.09);border-radius:50%}
.dashboard-hero h1{position:relative;z-index:1;color:#fff;font-size:2.2rem;letter-spacing:-.02em}
.dashboard-hero p{position:relative;z-index:1;color:rgba(255,255,255,.75)!important}
.dashboard-hero .header-actions{position:relative;z-index:1;justify-content:flex-end}
.dashboard-hero .header-actions .btn{background:rgba(255,255,255,.14)!important;border:1px solid rgba(255,255,255,.22);box-shadow:none}
.dashboard-hero .header-actions .btn-accent{background:#e76f51!important;border-color:#e76f51}
.dashboard-hero .header-actions .btn:hover{background:rgba(255,255,255,.24)!important}
.dashboard-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}
.dashboard-cards .card{position:relative;overflow:hidden;padding:20px 20px 18px;background:rgba(255,255,255,.94);border:1px solid #dce7e5;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);transition:transform .2s ease,box-shadow .2s ease}
.dashboard-cards .card:before{content:"";position:absolute;left:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#246a73,#2a9d8f)}
.dashboard-cards .card:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(24,59,86,.13)}
.dashboard-cards .card h3{margin:0 0 12px;font-size:.82rem;color:#667a80;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.dashboard-cards .card .value{font-size:1.8rem;font-weight:800;line-height:1.1;margin-bottom:7px;color:#183b56}
.dashboard-cards .card .label{font-size:.78rem;color:#839499}
.dashboard-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:30px 0 14px}
.dashboard-section-title h2{margin:0}
.dashboard-data-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:28px}
.dashboard-data-panel{background:rgba(255,255,255,.94);padding:20px;border:1px solid #dce7e5;border-radius:14px;box-shadow:0 8px 22px rgba(24,59,86,.07);overflow:auto}
.dashboard-data-panel h3{margin:0 0 14px;color:#183b56}
.dashboard-actions{display:flex;flex-wrap:wrap;gap:10px;padding:20px;background:rgba(255,255,255,.55);border:1px solid #dce7e5;border-radius:14px}
@media(max-width:900px){.dashboard-hero{align-items:flex-start;flex-direction:column}.dashboard-hero .header-actions{justify-content:flex-start}.dashboard-data-grid{grid-template-columns:1fr}}
@media(max-width:600px){.dashboard-hero{padding:24px 20px}.dashboard-hero h1{font-size:1.8rem}.dashboard-hero .header-actions .btn{font-size:.78rem}.dashboard-cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.dashboard-cards .card{padding:16px}.dashboard-cards .card .value{font-size:1.4rem}}
@media(max-width:400px){.dashboard-cards{grid-template-columns:1fr}}
.pending-approvals{margin:0 0 26px;padding:20px 22px;border:1px solid #dce7e5;border-left:5px solid #f59e0b;border-radius:14px;background:#fffdf5;box-shadow:0 8px 22px rgba(24,59,86,.06)}
.pending-approvals .pa-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.pending-approvals h2{margin:0;font-size:1.15rem;color:#183b56}
.pending-approvals .pa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.pending-approvals .pa-item{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;background:#fff;border:1px solid #e6ecf0;border-radius:10px}
.pending-approvals .pa-item .pa-count{font-size:1.6rem;font-weight:800;color:#b45309;line-height:1}
.pending-approvals .pa-item .pa-meta{font-size:.82rem;color:#64748b;margin-top:4px}
.pending-approvals .pa-item.zero .pa-count{color:#16a34a}
.pending-approvals .pa-clear{margin-top:12px;color:#16a34a;font-weight:600}
</style>

<?php if ($canApprove && $pendingTotal > 0): ?>
<div class="pending-approvals">
    <div class="pa-head">
        <h2>⏳ <?= t('pending_approvals') ?></h2>
        <span style="color:#64748b;font-size:.9rem;"><?= number_format($pendingTotal) ?> <?= t('items_need_action') ?></span>
    </div>
    <div class="pa-grid">
        <?php if (has_permission('purchases', 'approve')): ?>
        <div class="pa-item<?= $pendingApprovals['purchases'] === 0 ? ' zero' : '' ?>">
            <div>
                <div style="font-weight:700;color:#183b56;"><?= t('purchase_orders') ?></div>
                <div class="pa-meta"><?= currency_symbol() . number_format($pendingPurchasesValue, 2) ?> <?= t('awaiting_approval') ?></div>
            </div>
            <a href="../purchases/approvals.php" style="text-align:right;text-decoration:none;">
                <div class="pa-count"><?= number_format($pendingApprovals['purchases']) ?></div>
                <div class="pa-meta"><?= t('review') ?> →</div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (has_permission('expenses', 'approve')): ?>
        <div class="pa-item<?= $pendingApprovals['expenses'] === 0 ? ' zero' : '' ?>">
            <div>
                <div style="font-weight:700;color:#183b56;"><?= t('expense_claims') ?></div>
                <div class="pa-meta"><?= currency_symbol() . number_format($pendingExpensesValue, 2) ?> <?= t('awaiting_approval') ?></div>
            </div>
            <a href="../expenses/approvals.php" style="text-align:right;text-decoration:none;">
                <div class="pa-count"><?= number_format($pendingApprovals['expenses']) ?></div>
                <div class="pa-meta"><?= t('review') ?> →</div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (has_permission('hr', 'approve')): ?>
        <?php foreach (['hr_leaves' => [t('leave_requests'), '../hr/leaves.php'], 'hr_overtime' => [t('overtime_requests'), '../hr/overtime.php'], 'hr_exits' => [t('exit_clearances'), '../hr/exit_clearance.php']] as $key => [$label, $url]): ?>
        <div class="pa-item<?= $pendingApprovals[$key] === 0 ? ' zero' : '' ?>">
            <div style="font-weight:700;color:#183b56;"><?= e($label) ?></div>
            <a href="<?= e($url) ?>" style="text-align:right;text-decoration:none;"><div class="pa-count"><?= number_format($pendingApprovals[$key]) ?></div><div class="pa-meta"><?= t('review') ?> →</div></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (has_permission('finance', 'approve')): ?>
        <?php foreach (['finance_loans' => [t('loan_requests'), '../finance/loan_approval.php'], 'finance_payroll' => [t('payroll_transfers'), '../finance/payroll_approval.php']] as $key => [$label, $url]): ?>
        <div class="pa-item<?= $pendingApprovals[$key] === 0 ? ' zero' : '' ?>">
            <div style="font-weight:700;color:#183b56;"><?= e($label) ?></div>
            <a href="<?= e($url) ?>" style="text-align:right;text-decoration:none;"><div class="pa-count"><?= number_format($pendingApprovals[$key]) ?></div><div class="pa-meta"><?= t('review') ?> →</div></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (has_permission('gate', 'edit')): ?>
        <div class="pa-item<?= $pendingApprovals['gate'] === 0 ? ' zero' : '' ?>">
            <div>
                <div style="font-weight:700;color:#183b56;"><?= t('gate_holds') ?></div>
                <div class="pa-meta"><?= t('rejected_clearances_reinstate') ?></div>
            </div>
            <a href="../gate/index.php" style="text-align:right;text-decoration:none;">
                <div class="pa-count"><?= number_format($pendingApprovals['gate']) ?></div>
                <div class="pa-meta"><?= t('review') ?> →</div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($canApprove): ?>
<div class="pending-approvals" style="border-left-color:#16a34a;background:#f4fbf6;">
    <div class="pa-clear">✅ <?= t('all_caught_up_approvals') ?></div>
</div>
<?php endif; ?>

<h2><?= t('today_overview') ?></h2>
<div class="dashboard-cards">
    <div class="card" data-link="../production/index.php" title="<?= e(t('view_production')) ?>">
        <h3>📦 <?= t('production_today') ?></h3>
        <div class="value"><?=number_format($prod)?></div>
        <div class="label"><?= t('blocks_produced') ?></div>
    </div>
    <div class="card" data-link="../sales/index.php" title="<?= e(t('view_sales_invoices')) ?>">
        <h3>💰 <?= t('sales_today') ?></h3>
        <div class="value" style="color:#28a745"><?=number_format($sales,2)?></div>
        <div class="label"><?= t('total_sales') ?></div>
    </div>
    <div class="card">
        <h3>💵 <?= t('cash_received') ?></h3>
        <div class="value" style="color:#007bff"><?=number_format($paid,2)?></div>
        <div class="label"><?= t('payments_received') ?></div>
    </div>
    <div class="card" data-link="../expenses/index.php" title="<?= e(t('expenses')) ?>">
        <h3>📉 <?= t('expenses_today') ?></h3>
        <div class="value" style="color:#dc3545"><?=number_format($exp,2)?></div>
        <div class="label"><?= t('total_expenses') ?></div>
    </div>
    <div class="card">
        <h3>👷 <?= t('daily_labor_cost') ?></h3>
        <div class="value" style="color:#e76f51"><?=currency_symbol() . number_format($daily_labor_cost,2)?></div>
        <div class="label"><?= t('estimated_monthly_payroll') ?></div>
    </div>
</div>

<?php if ($low_production): ?>
<div class="panel" style="margin:24px 0; border-left:4px solid #dc3545;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0;color:#dc3545;">⚠️ <?= t('low_production_alerts') ?></h3>
            <p style="margin:6px 0 0;color:#64748b;"><?= t('production_below_target') ?></p>
        </div>
        <a class="btn btn-secondary" href="../production/schedule.php"><?= t('view_schedule') ?></a>
    </div>
    <table class="data-table" style="margin-top:16px;">
        <thead><tr><th><?= t('block_type') ?></th><th><?= t('target') ?></th><th><?= t('produced') ?></th><th><?= t('shortfall') ?></th></tr></thead>
        <tbody>
        <?php foreach ($low_production as $alert): ?>
            <tr>
                <td><?= e($alert['name'] . ' ' . $alert['size']) ?></td>
                <td><?= number_format((int) $alert['target_quantity']) ?></td>
                <td><?= number_format((int) $alert['produced_quantity']) ?></td>
                <td><span class="badge badge-danger"><?= number_format((int) $alert['shortfall']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($canReports): ?>
<div class="grid-2 dashboard-chart-grid" style="margin:24px 0;">
    <div class="panel"><h3 style="margin-top:0;"><?= t('sales_trend') ?></h3><canvas id="chartSalesTrend" height="200"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;"><?= t('production_chart') ?></h3><canvas id="chartProduction" height="200"></canvas></div>
</div>
<div class="grid-2 dashboard-chart-grid" style="margin-bottom:24px;">
    <div class="panel"><h3 style="margin-top:0;"><?= t('top_products') ?></h3><canvas id="chartTopProducts" height="220"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;"><?= t('sales_vs_expenses_mtd') ?></h3><canvas id="chartExpenseSales" height="220"></canvas></div>
</div>
<?php endif; ?>

<div class="dashboard-section-title"><h2><?= t('month_to_date') ?></h2></div>
<div class="dashboard-cards">
    <div class="card" data-link="../reports/financial.php" title="View financial report">
        <h3>📊 <?= t('sales_mtd') ?></h3>
        <div class="value" style="color:#28a745"><?=number_format($month_sales,2)?></div>
        <div class="label"><?= t('since_month_start') ?> <?= e(format_app_date(date('Y-m-01'))) ?></div>
    </div>
    <div class="card" data-link="../reports/profit_loss.php" title="<?= e(t('view_profit_loss')) ?>">
        <h3>💼 <?= t('profit_mtd') ?></h3>
        <div class="value" style="color:<?=$month_profit>=0?'#28a745':'#dc3545'?>"><?=number_format($month_profit,2)?></div>
        <div class="label"><?= t('net_profit_loss') ?></div>
    </div>
    <div class="card" data-link="../inventory/index.php" title="<?= e(t('view_finished_stock')) ?>">
        <h3>📦 <?= t('finished_stock') ?></h3>
        <div class="value"><?=number_format($stock)?></div>
        <div class="label"><?= t('blocks_in_stock') ?></div>
    </div>
    <div class="card" data-link="../hr/index.php" title="<?= e(t('view_employees')) ?>">
        <h3>👥 <?= t('active_employees') ?></h3>
        <div class="value"><?=number_format($total_employees)?></div>
        <div class="label"><?= t('employees_label') ?></div>
    </div>
</div>

<div class="dashboard-section-title"><h2><?= t('accounts_overview') ?></h2></div>
<div class="dashboard-cards">
    <div class="card" data-link="../payments/customer.php" title="<?= e(t('view_customer_payments')) ?>">
        <h3>💳 <?= t('receivables') ?></h3>
        <div class="value" style="color:#ffc107"><?=number_format($recv,2)?></div>
        <div class="label"><?= t('from_customers') ?> <?= $total_customers ?> <?= t('customers') ?></div>
    </div>
    <div class="card" data-link="../payments/supplier.php" title="<?= e(t('view_supplier_payments')) ?>">
        <h3>📮 <?= t('payables') ?></h3>
        <div class="value" style="color:#dc3545"><?=number_format($pay,2)?></div>
        <div class="label"><?= t('to_suppliers') ?></div>
    </div>
    <div class="card" data-link="../inventory/low_stock_alert.php" title="<?= e(t('view_low_stock_alerts')) ?>">
        <h3>⚠️ <?= t('low_stock') ?></h3>
        <div class="value" style="color:<?=$low_stock>0?'#dc3545':'#28a745'?>"><?=$low_stock?></div>
        <div class="label"><?= t('materials_below_reorder') ?></div>
    </div>
    <div class="card">
        <h3>💼 <?= t('net_position') ?></h3>
        <div class="value" style="color:<?=($recv-$pay)>=0?'#28a745':'#dc3545'?>"><?=number_format($recv-$pay,2)?></div>
        <div class="label"><?= t('receivables_minus_payables') ?></div>
    </div>
</div>

<div class="dashboard-data-grid">
    <div class="dashboard-data-panel">
        <h3><?= t('recent_sales') ?></h3>
        <table style="width:100%">
            <thead><tr><th><?= t('invoice') ?></th><th><?= t('customer') ?></th><th><?= t('amount') ?></th><th><?= t('date') ?></th></tr></thead>
            <tbody>
                <?php foreach($recent_sales as $s): ?>
                <tr>
                    <td><?=htmlspecialchars($s['invoice_no'])?></td>
                    <td><?=htmlspecialchars($s['customer_name']??'Walk-in')?></td>
                    <td><?=number_format($s['total_amount'],2)?></td>
                    <td><?=date('d M',strtotime($s['sale_date']))?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="dashboard-data-panel">
        <h3><?= t('recent_production') ?></h3>
        <table style="width:100%">
            <thead><tr><th><?= t('prod_no') ?></th><th><?= t('block_type') ?></th><th><?= t('quantity') ?></th><th><?= t('date') ?></th></tr></thead>
            <tbody>
                <?php foreach($recent_production as $p): ?>
                <tr>
                    <td><?=htmlspecialchars($p['production_no'])?></td>
                    <td><?=htmlspecialchars($p['block_name'])?></td>
                    <td><?=number_format($p['good_quantity'])?></td>
                    <td><?=date('d M',strtotime($p['production_date']))?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:30px">
    <div class="dashboard-section-title"><h2><?= t('quick_actions') ?></h2></div>
    <div class="dashboard-actions">
        <a class="btn" href="../production/create.php">📦 <?= t('new_production') ?></a>
        <a class="btn" href="../production/quality.php">✅ <?= t('quality_checks') ?></a>
        <a class="btn" href="../inventory/dispatch.php">🚚 <?= t('dispatch_tracking') ?></a>
        <a class="btn" href="../sales/create.php">💰 <?= t('new_sale') ?></a>
        <a class="btn" href="../purchases/create.php">🛒 <?= t('new_purchase') ?></a>
        <a class="btn" href="../expenses/create.php">💳 <?= t('new_expense') ?></a>
        <a class="btn" href="../customers/create.php">👤 <?= t('add_customer') ?></a>
        <a class="btn" href="../hr/attendance.php">📅 <?= t('mark_attendance') ?></a>
        <a class="btn" href="../reports/financial.php">📊 <?= t('view_reports') ?></a>
        <a class="btn btn-accent" href="widgets.php">📈 <?= t('advanced_widgets') ?></a>
        <a class="btn" href="../crm/interactions.php">📞 <?= t('followups') ?></a>
        <a class="btn" href="../crm/delivery.php"><?= t('delivery_notes') ?></a>
        <a class="btn" href="../crm/commissions.php"><?= t('commissions') ?></a>
        <a class="btn" href="../crm/feedback.php"><?= t('feedback') ?></a>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    var card = e.target.closest('.dashboard-cards .card[data-link]');
    if (card) window.location.href = card.getAttribute('data-link');
});
</script>

<?php if ($canReports): ?>
<?php require_once "../partials/charts.php"; ?>
<?php endif; ?>
<?php require_once "../partials/footer.php"; ?>