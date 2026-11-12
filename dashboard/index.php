<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('dashboard', 'view');
ensure_department_access_schema();
ensure_expense_approval_schema();
ensure_purchase_payment_schema();
if (function_exists('ensure_production_curing_schema')) {
    ensure_production_curing_schema();
}
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

// Stock and balances. Only cured blocks can be sold; curing stock is shown separately.
$stockTotal=(int) v($pdo,"SELECT COALESCE(SUM(quantity),0) FROM finished_stock");
$curingStock = function_exists('finished_curing_quantity') ? (int) finished_curing_quantity() : 0;
$stock=max(0, $stockTotal-$curingStock);
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
<?php
$brandDash = brand_colors();
$dashHour = (int) date('G');
$greet = (function_exists('app_lang') && app_lang() === 'am')
    ? ($dashHour < 12 ? 'እንደምን አደሩ' : ($dashHour < 18 ? 'እንደምን ዋሉ' : 'እንደምን አመሹ'))
    : ($dashHour < 12 ? 'Good morning' : ($dashHour < 18 ? 'Good afternoon' : 'Good evening'));
$moreLabel = (function_exists('app_lang') && app_lang() === 'am') ? 'ተጨማሪ' : 'More';
$laborDue = null;
if ((int) date('w') === 6 && has_permission('production', 'view')) {
    require_once __DIR__ . '/../config/labor_settings.php';
    try {
        [$lws, $lwe] = labor_week_bounds(date('Y-m-d'));
        if (!labor_payment_for_week($lwe)) {
            $lsum = labor_week_summary($lws, $lwe);
            if ($lsum['due'] > 0) {
                $laborDue = $lsum;
            }
        }
    } catch (Throwable $e) {
        $laborDue = null;
    }
}
$alertCount = ($low_stock > 0 ? 1 : 0) + ($low_production ? 1 : 0) + ($task_reminders ? 1 : 0) + ($laborDue ? 1 : 0);
?>
<style>
:root{--d-p:<?= htmlspecialchars($brandDash['primary']) ?>;--d-s:<?= htmlspecialchars($brandDash['secondary']) ?>;--d-ink:#14262e;--d-muted:#62767e;--d-line:#e3eaec;--d-bg:#fff}
.dash-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin:0 0 22px;padding:26px 28px;border-radius:20px;color:#fff;background:linear-gradient(125deg,var(--d-p),var(--d-s));position:relative;overflow:hidden}
.dash-hero:after{content:"";position:absolute;right:-50px;top:-70px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.08)}
.dash-hero h1{margin:0;font-size:1.7rem;letter-spacing:-.01em;position:relative;z-index:1}
.dash-hero p{margin:5px 0 0;opacity:.85;position:relative;z-index:1}
.dash-hero .dh-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;position:relative;z-index:2}
.dh-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:12px;background:rgba(255,255,255,.16);color:#fff;text-decoration:none;font-weight:600;font-size:.9rem;border:1px solid rgba(255,255,255,.25);cursor:pointer;transition:background .15s,transform .15s}
.dh-btn:hover{background:rgba(255,255,255,.28);transform:translateY(-1px)}
.dh-btn.solid{background:#fff;color:var(--d-p);border-color:#fff}
.dh-more{position:relative}
.dh-more summary{list-style:none}.dh-more summary::-webkit-details-marker{display:none}
.dh-menu{position:absolute;right:0;top:calc(100% + 8px);min-width:230px;background:#fff;border-radius:14px;box-shadow:0 18px 44px rgba(15,45,55,.25);padding:8px;display:grid;gap:2px;z-index:50}
.dh-menu a{color:var(--d-ink);text-decoration:none;padding:9px 12px;border-radius:9px;font-size:.9rem}
.dh-menu a:hover{background:#eef4f5}
.dash-alert{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 22px;padding:13px 18px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;color:#6a4cc4}
.dash-alert .da-text{flex:1;min-width:220px;font-size:.92rem;line-height:1.5}
.dash-alert .da-links{display:flex;gap:8px;flex-wrap:wrap}
.dash-alert a{font-size:.82rem;font-weight:600;color:#9a3412;background:#ffedd5;padding:6px 12px;border-radius:999px;text-decoration:none}
.dash-alert a:hover{background:#fed7aa}
.dash-label{margin:26px 0 12px;font-size:.78rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--d-muted)}
.dashboard-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:0 0 6px}
.dashboard-cards.five{grid-template-columns:repeat(5,minmax(0,1fr))}
.dashboard-cards .card{position:relative;background:var(--d-bg);border:1px solid var(--d-line);border-radius:18px;padding:18px 18px 16px;box-shadow:0 2px 10px rgba(20,38,46,.05);transition:transform .18s,box-shadow .18s}
.dashboard-cards .card[data-link]{cursor:pointer}
.dashboard-cards .card:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(20,38,46,.12)}
.dashboard-cards .card:before{display:none}
.dashboard-cards .card h3{display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:.78rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--d-muted)}
.dashboard-cards .card h3 .ic{flex:none;display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:color-mix(in srgb,var(--d-p) 12%,#fff);font-size:1rem}
.dashboard-cards .card .value{font-size:1.75rem;font-weight:800;line-height:1.1;margin-bottom:6px;color:var(--d-ink);letter-spacing:-.01em;word-break:break-word}
.dashboard-cards .card .label{font-size:.78rem;color:var(--d-muted)}
.dashboard-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:0 0 18px}
.dashboard-chart-grid .panel{background:#fff;border:1px solid var(--d-line);border-radius:18px;padding:18px 20px;box-shadow:0 2px 10px rgba(20,38,46,.05)}
.dashboard-chart-grid .panel h3{margin:0 0 12px;font-size:.98rem;color:var(--d-ink)}
.dashboard-chart-grid canvas{max-height:260px}
.dash-lowprod{margin:0 0 22px;background:#fff;border:1px solid #f3c9c9;border-left:5px solid #dc3545;border-radius:16px;padding:18px 20px}
.dash-lists{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:26px}
.dash-card{background:#fff;border:1px solid var(--d-line);border-radius:18px;padding:18px 20px;box-shadow:0 2px 10px rgba(20,38,46,.05);overflow:auto}
.dash-card h3{margin:0 0 10px;font-size:1rem;color:var(--d-ink)}
.dash-card table{width:100%;border-collapse:collapse;font-size:.9rem}
.dash-card th{text-align:left;font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:var(--d-muted);padding:6px 8px;border-bottom:1px solid var(--d-line)}
.dash-card td{padding:10px 8px;border-bottom:1px solid #f0f4f5}
.dash-card tr:last-child td{border-bottom:0}
.dash-empty{color:var(--d-muted);padding:14px 0;font-size:.9rem}
.dash-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
.dash-actions a{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;background:#fff;border:1px solid var(--d-line);color:var(--d-ink);text-decoration:none;font-weight:600;font-size:.9rem;box-shadow:0 1px 4px rgba(20,38,46,.05);transition:border-color .15s,transform .15s}
.dash-actions a:hover{border-color:var(--d-p);transform:translateY(-1px)}
.pending-approvals{margin:0 0 22px;padding:18px 20px;border:1px solid #fde68a;border-left:5px solid #f59e0b;border-radius:16px;background:#fffdf5}
.pending-approvals .pa-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.pending-approvals h2{margin:0;font-size:1.05rem;color:var(--d-ink)}
.pending-approvals .pa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.pending-approvals .pa-item{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;background:#fff;border:1px solid #eef0f2;border-radius:12px}
.pending-approvals .pa-item .pa-count{font-size:1.5rem;font-weight:800;color:#b45309;line-height:1}
.pending-approvals .pa-item .pa-meta{font-size:.8rem;color:var(--d-muted);margin-top:3px}
.pending-approvals .pa-item.zero .pa-count{color:#16a34a}
.pending-approvals .pa-clear{color:#16a34a;font-weight:600}
@media(max-width:1100px){.dashboard-cards,.dashboard-cards.five{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:800px){.dashboard-chart-grid,.dash-lists{grid-template-columns:1fr}.dash-hero{padding:22px 20px}.dash-hero h1{font-size:1.4rem}}
@media(max-width:460px){.dashboard-cards,.dashboard-cards.five{grid-template-columns:1fr 1fr;gap:10px}.dashboard-cards .card{padding:14px}.dashboard-cards .card .value{font-size:1.3rem}.dashboard-cards .card h3 .ic{width:28px;height:28px}.dh-btn{padding:9px 12px;font-size:.82rem}}
</style>

<div class="dash-hero">
    <div>
        <h1><?= e($greet) ?><?= !empty($dashboardUser['name']) ? ', ' . e(explode(' ', trim($dashboardUser['name']))[0]) : '' ?></h1>
        <p><?= e(format_app_date('now', true)) ?></p>
    </div>
    <div class="dh-actions">
        <a class="dh-btn solid" href="../sales/create.php">💰 <?= t('new_sale') ?></a>
        <a class="dh-btn" href="../production/create.php">📦 <?= t('new_production') ?></a>
        <a class="dh-btn" href="../expenses/create.php">💳 <?= t('new_expense') ?></a>
        <details class="dh-more">
            <summary class="dh-btn">⋯ <?= e($moreLabel) ?></summary>
            <div class="dh-menu">
                <?php if ($isSystemAdmin): ?>
                <a href="widgets.php"><?= t('advanced_analytics') ?></a>
                <a href="insights.php"><?= t('operational_insights') ?></a>
                <a href="operations.php"><?= t('operations_overview') ?></a>
                <a href="forecast.php"><?= t('budget_forecast') ?></a>
                <?php endif; ?>
                <a href="tasks.php"><?= t('tasks_reminders') ?></a>
                <?php if (has_permission('expenses', 'view')): ?><a href="../expenses/recurring.php"><?= t('recurring_costs') ?></a><?php endif; ?>
                <?php if (has_permission('purchases', 'view')): ?><a href="../purchases/approvals.php"><?= t('purchase_approvals') ?></a><?php endif; ?>
                <?php if (has_permission('suppliers', 'view')): ?><a href="../suppliers/scorecards.php"><?= t('supplier_scorecards') ?></a><?php endif; ?>
                <?php if (has_permission('crm', 'view')): ?><a href="../crm/pipeline.php"><?= t('sales_pipeline') ?></a><?php endif; ?>
            </div>
        </details>
    </div>
</div>

<?php if ($alertCount > 0): ?>
<div class="dash-alert" role="alert">
    <strong>🔔</strong>
    <div class="da-text">
        <?php if ($low_stock > 0): ?><span><?= number_format($low_stock) ?> <?= t($low_stock === 1 ? 'material_below_reorder_one' : 'materials_below_reorder_many') ?>. </span><?php endif; ?>
        <?php if ($low_production): ?><span><?= number_format(count($low_production)) ?> <?= t(count($low_production) === 1 ? 'block_type_below_target_one' : 'block_types_below_target_many') ?>. </span><?php endif; ?>
        <?php if ($task_reminders): ?><span><?= count($task_reminders) ?> <?= t('tasks_overdue_due_soon') ?>.</span><?php endif; ?>
        <?php if ($laborDue): ?><span>Saturday: weekly labor payment due at 5:00 PM &mdash; <?= number_format($laborDue['bags'], 1) ?> cement bags, <?= e(currency_symbol()) ?><?= money($laborDue['due']) ?>.</span><?php endif; ?>
    </div>
    <div class="da-links">
        <?php if ($low_stock > 0): ?><a href="../inventory/low_stock_alert.php"><?= t('view_stock_alerts') ?></a><?php endif; ?>
        <?php if ($low_production): ?><a href="../production/schedule.php"><?= t('view_production') ?></a><?php endif; ?>
        <?php if ($task_reminders): ?><a href="tasks.php"><?= t('view_tasks') ?></a><?php endif; ?>
        <?php if ($laborDue): ?><a href="../production/weekly_labor.php">Pay labor</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
                <div style="font-weight:700;color:#1c2250;"><?= t('purchase_orders') ?></div>
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
                <div style="font-weight:700;color:#1c2250;"><?= t('expense_claims') ?></div>
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
            <div style="font-weight:700;color:#1c2250;"><?= e($label) ?></div>
            <a href="<?= e($url) ?>" style="text-align:right;text-decoration:none;"><div class="pa-count"><?= number_format($pendingApprovals[$key]) ?></div><div class="pa-meta"><?= t('review') ?> →</div></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (has_permission('finance', 'approve')): ?>
        <?php foreach (['finance_loans' => [t('loan_requests'), '../finance/loan_approval.php'], 'finance_payroll' => [t('payroll_transfers'), '../finance/payroll_approval.php']] as $key => [$label, $url]): ?>
        <div class="pa-item<?= $pendingApprovals[$key] === 0 ? ' zero' : '' ?>">
            <div style="font-weight:700;color:#1c2250;"><?= e($label) ?></div>
            <a href="<?= e($url) ?>" style="text-align:right;text-decoration:none;"><div class="pa-count"><?= number_format($pendingApprovals[$key]) ?></div><div class="pa-meta"><?= t('review') ?> →</div></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (has_permission('gate', 'edit')): ?>
        <div class="pa-item<?= $pendingApprovals['gate'] === 0 ? ' zero' : '' ?>">
            <div>
                <div style="font-weight:700;color:#1c2250;"><?= t('gate_holds') ?></div>
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

<div class="dash-label"><?= t('today_overview') ?></div>
<div class="dashboard-cards five">
    <div class="card" data-link="../sales/index.php" title="<?= e(t('view_sales_invoices')) ?>">
        <h3><span class="ic">💰</span><?= t('sales_today') ?></h3>
        <div class="value" style="color:#15803d"><?= number_format($sales, 2) ?></div>
        <div class="label"><?= t('total_sales') ?></div>
    </div>
    <div class="card">
        <h3><span class="ic">💵</span><?= t('cash_received') ?></h3>
        <div class="value" style="color:#1d4ed8"><?= number_format($paid, 2) ?></div>
        <div class="label"><?= t('payments_received') ?></div>
    </div>
    <div class="card" data-link="../production/index.php" title="<?= e(t('view_production')) ?>">
        <h3><span class="ic">📦</span><?= t('production_today') ?></h3>
        <div class="value"><?= number_format($prod) ?></div>
        <div class="label"><?= t('blocks_produced') ?></div>
    </div>
    <div class="card" data-link="../expenses/index.php" title="<?= e(t('expenses')) ?>">
        <h3><span class="ic">📉</span><?= t('expenses_today') ?></h3>
        <div class="value" style="color:#b91c1c"><?= number_format($exp, 2) ?></div>
        <div class="label"><?= t('total_expenses') ?></div>
    </div>
    <div class="card">
        <h3><span class="ic">👷</span><?= t('daily_labor_cost') ?></h3>
        <div class="value" style="color:#c2410c"><?= currency_symbol() . number_format($daily_labor_cost, 2) ?></div>
        <div class="label"><?= t('estimated_monthly_payroll') ?></div>
    </div>
</div>

<div class="dash-label"><?= t('month_to_date') ?></div>
<div class="dashboard-cards">
    <div class="card" data-link="../reports/financial.php" title="View financial report">
        <h3><span class="ic">📊</span><?= t('sales_mtd') ?></h3>
        <div class="value" style="color:#15803d"><?= number_format($month_sales, 2) ?></div>
        <div class="label"><?= t('since_month_start') ?> <?= e(format_app_date(date('Y-m-01'))) ?></div>
    </div>
    <div class="card" data-link="../reports/profit_loss.php" title="<?= e(t('view_profit_loss')) ?>">
        <h3><span class="ic">💼</span><?= t('profit_mtd') ?></h3>
        <div class="value" style="color:<?= $month_profit >= 0 ? '#15803d' : '#b91c1c' ?>"><?= number_format($month_profit, 2) ?></div>
        <div class="label"><?= t('net_profit_loss') ?></div>
    </div>
    <div class="card" data-link="../inventory/index.php" title="<?= e(t('view_finished_stock')) ?>">
        <h3><span class="ic">🧱</span><?= t('finished_stock') ?></h3>
        <div class="value"><?= number_format($stock) ?></div>
        <div class="label"><span>blocks ready to sell</span> · <span><?= number_format($curingStock) ?></span> <span>curing</span></div>
    </div>
    <div class="card" data-link="../hr/index.php" title="<?= e(t('view_employees')) ?>">
        <h3><span class="ic">👥</span><?= t('active_employees') ?></h3>
        <div class="value"><?= number_format($total_employees) ?></div>
        <div class="label"><?= t('employees_label') ?></div>
    </div>
</div>

<div class="dash-label"><?= t('accounts_overview') ?></div>
<div class="dashboard-cards">
    <div class="card" data-link="../payments/customer.php" title="<?= e(t('view_customer_payments')) ?>">
        <h3><span class="ic">💳</span><?= t('receivables') ?></h3>
        <div class="value" style="color:#a16207"><?= number_format($recv, 2) ?></div>
        <div class="label"><?= t('from_customers') ?> <?= $total_customers ?> <?= t('customers') ?></div>
    </div>
    <div class="card" data-link="../payments/supplier.php" title="<?= e(t('view_supplier_payments')) ?>">
        <h3><span class="ic">📮</span><?= t('payables') ?></h3>
        <div class="value" style="color:#b91c1c"><?= number_format($pay, 2) ?></div>
        <div class="label"><?= t('to_suppliers') ?></div>
    </div>
    <div class="card" data-link="../inventory/low_stock_alert.php" title="<?= e(t('view_low_stock_alerts')) ?>">
        <h3><span class="ic">⚠️</span><?= t('low_stock') ?></h3>
        <div class="value" style="color:<?= $low_stock > 0 ? '#b91c1c' : '#15803d' ?>"><?= $low_stock ?></div>
        <div class="label"><?= t('materials_below_reorder') ?></div>
    </div>
    <div class="card">
        <h3><span class="ic">⚖️</span><?= t('net_position') ?></h3>
        <div class="value" style="color:<?= ($recv - $pay) >= 0 ? '#15803d' : '#b91c1c' ?>"><?= number_format($recv - $pay, 2) ?></div>
        <div class="label"><?= t('receivables_minus_payables') ?></div>
    </div>
</div>

<?php if ($low_production): ?>
<div class="panel dash-lowprod">
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
<div class="grid-2 dashboard-chart-grid">
    <div class="panel"><h3 style="margin-top:0;"><?= t('sales_trend') ?></h3><canvas id="chartSalesTrend" height="200"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;"><?= t('production_chart') ?></h3><canvas id="chartProduction" height="200"></canvas></div>
</div>
<div class="grid-2 dashboard-chart-grid">
    <div class="panel"><h3 style="margin-top:0;"><?= t('top_products') ?></h3><canvas id="chartTopProducts" height="220"></canvas></div>
    <div class="panel"><h3 style="margin-top:0;"><?= t('sales_vs_expenses_mtd') ?></h3><canvas id="chartExpenseSales" height="220"></canvas></div>
</div>
<?php endif; ?>

<div class="dash-lists">
    <div class="dash-card">
        <h3><?= t('recent_sales') ?></h3>
        <?php if ($recent_sales): ?>
        <table>
            <thead><tr><th><?= t('invoice') ?></th><th><?= t('customer') ?></th><th style="text-align:right"><?= t('amount') ?></th><th><?= t('date') ?></th></tr></thead>
            <tbody>
            <?php foreach ($recent_sales as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['invoice_no']) ?></td>
                    <td><?= htmlspecialchars($s['customer_name'] ?? 'Walk-in') ?></td>
                    <td style="text-align:right;font-weight:600"><?= number_format($s['total_amount'], 2) ?></td>
                    <td><?= date('d M', strtotime($s['sale_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><div class="dash-empty">—</div><?php endif; ?>
    </div>
    <div class="dash-card">
        <h3><?= t('recent_production') ?></h3>
        <?php if ($recent_production): ?>
        <table>
            <thead><tr><th><?= t('prod_no') ?></th><th><?= t('block_type') ?></th><th style="text-align:right"><?= t('quantity') ?></th><th><?= t('date') ?></th></tr></thead>
            <tbody>
            <?php foreach ($recent_production as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['production_no']) ?></td>
                    <td><?= htmlspecialchars($p['block_name']) ?></td>
                    <td style="text-align:right;font-weight:600"><?= number_format($p['good_quantity']) ?></td>
                    <td><?= date('d M', strtotime($p['production_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><div class="dash-empty">—</div><?php endif; ?>
    </div>
</div>

<div class="dash-label"><?= t('quick_actions') ?></div>
<div class="dash-actions">
    <a href="../production/quality.php">✅ <?= t('quality_checks') ?></a>
    <a href="../inventory/dispatch.php">🚚 <?= t('dispatch_tracking') ?></a>
    <a href="../purchases/create.php">🛒 <?= t('new_purchase') ?></a>
    <a href="../customers/create.php">👤 <?= t('add_customer') ?></a>
    <a href="../hr/attendance.php">📅 <?= t('mark_attendance') ?></a>
    <a href="../reports/financial.php">📊 <?= t('view_reports') ?></a>
    <a href="../crm/interactions.php">📞 <?= t('followups') ?></a>
    <a href="../crm/delivery.php"><?= t('delivery_notes') ?></a>
    <a href="../crm/commissions.php"><?= t('commissions') ?></a>
    <a href="../crm/feedback.php"><?= t('feedback') ?></a>
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