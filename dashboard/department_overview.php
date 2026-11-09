<?php
$departments = [
    'gate' => ['Gate', 'Vehicle flow, clearances, and loading status'],
    'production' => ['Production', 'Daily output, production targets, and stock readiness'],
    'sales' => ['Sales', 'Sales performance, collections, and customer balances'],
    'purchasing' => ['Purchasing', 'Purchase spend, supplier balances, and material levels'],
    'hr' => ['HR', 'Staffing, attendance, leave, and payroll'],
    'finance' => ['Finance', 'Revenue, expenses, and outstanding balances'],
];
[$departmentName, $departmentDescription] = $departments[$dashboardDepartment];
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$metrics = [];
$metric = static function (string $label, $value, string $note, bool $isCurrency = false) use (&$metrics): void {
    $metrics[] = ['label' => $label, 'value' => $value, 'note' => $note, 'currency' => $isCurrency];
};
$value = static function (string $sql, array $parameters = []) use ($pdo) {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
};

switch ($dashboardDepartment) {
    case 'gate':
        ensure_gate_schema();
        $metric('Waiting for loading', (int) $value("SELECT COUNT(*) FROM gate_clearances WHERE status = 'WAITING_LOADING' AND payment_status = 'PAID'"), 'Paid clearances');
        $metric('Ready for release', (int) $value("SELECT COUNT(*) FROM gate_clearances WHERE status = 'READY_FOR_GATE' AND payment_status = 'PAID'"), 'Vehicles at the gate');
        $metric('Active holds', (int) $value("SELECT COUNT(*) FROM gate_clearances WHERE status = 'REJECTED'"), 'Clearances needing review');
        $metric('Exited today', (int) $value("SELECT COUNT(*) FROM gate_clearances WHERE status = 'EXITED' AND DATE(exit_time) = ?", [$today]), 'Vehicles released');
        break;
    case 'production':
        $metric('Produced today', (int) $value('SELECT COALESCE(SUM(good_quantity), 0) FROM production WHERE production_date = ?', [$today]), 'Good blocks');
        $metric('Scheduled today', (int) $value("SELECT COALESCE(SUM(target_quantity), 0) FROM production_schedules WHERE schedule_date = ? AND status IN ('planned', 'in_progress')", [$today]), 'Target quantity');
        $metric('Finished stock', (int) $value('SELECT COALESCE(SUM(quantity), 0) FROM finished_stock'), 'Blocks ready for dispatch');
        $metric('Low stock materials', (int) $value('SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point'), 'At or below reorder level');
        break;
    case 'sales':
        $metric('Sales today', (float) $value('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date = ?', [$today]), 'Invoices issued', true);
        $metric('Collected today', (float) $value('SELECT COALESCE(SUM(paid_amount), 0) FROM sales WHERE sale_date = ?', [$today]), 'Payments received', true);
        $metric('Sales this month', (float) $value('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date >= ?', [$monthStart]), 'Month to date', true);
        $metric('Receivables', (float) $value('SELECT COALESCE(SUM(balance), 0) FROM sales WHERE balance > 0'), 'Outstanding customer balances', true);
        break;
    case 'purchasing':
        $metric('Purchases today', (float) $value('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date = ? AND (approval_status IS NULL OR approval_status <> \'rejected\')', [$today]), 'Purchase orders', true);
        $metric('Spend this month', (float) $value('SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date >= ? AND (approval_status IS NULL OR approval_status <> \'rejected\')', [$monthStart]), 'Month to date', true);
        $metric('Supplier balances', (float) $value('SELECT COALESCE(SUM(balance), 0) FROM purchases WHERE balance > 0'), 'Outstanding payables', true);
        $lowStockStatement = $pdo->query('SELECT rm.id, rm.name, rm.current_stock, rm.unit, rl.reorder_point FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point AND rm.status = 1 ORDER BY (rm.current_stock / NULLIF(rl.reorder_point, 0)) ASC');
        $lowStockMaterials = $lowStockStatement->fetchAll();
        $metric('Low stock materials', count($lowStockMaterials), 'At or below reorder level');
        break;
    case 'hr':
        $metric('Active employees', (int) $value('SELECT COUNT(*) FROM employees WHERE status = 1'), 'Current workforce');
        $metric('Present today', (int) $value("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.attendance_date = ? AND (a.status = 'present' OR " . attendance_paid_leave_predicate('a', 'e') . ") AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining)", [$today]), 'Attendance recorded, incl. paid leave');
        $metric('Pending leave', (int) $value("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"), 'Requests awaiting review');
        $metric('Payroll this month', (float) $value('SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE month = MONTH(CURDATE()) AND year = YEAR(CURDATE())'), 'Net salary total', true);
        break;
    case 'finance':
        $salesTotal = (float) $value('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date >= ?', [$monthStart]);
        $expenseTotal = (float) $value("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND (approval_status = 'approved' OR approval_status IS NULL)", [$monthStart]);
        $metric('Sales this month', $salesTotal, 'Month to date', true);
        $metric('Approved expenses', $expenseTotal, 'Month to date', true);
        $metric('Net before production costs', $salesTotal - $expenseTotal, 'Sales less approved expenses', true);
        $metric('Receivables', (float) $value('SELECT COALESCE(SUM(balance), 0) FROM sales WHERE balance > 0'), 'Outstanding customer balances', true);
        break;
}

$linksByDepartment = [
    'gate' => [
        ['label' => 'Gate dashboard', 'url' => '../gate/index.php', 'module' => 'gate', 'action' => 'view'],
        ['label' => 'Loading confirmation', 'url' => '../gate/loading.php', 'module' => 'gate', 'action' => 'edit'],
    ],
    'production' => [
        ['label' => 'Production overview', 'url' => '../production/index.php', 'module' => 'production', 'action' => 'view'],
        ['label' => 'New production', 'url' => '../production/create.php', 'module' => 'production', 'action' => 'create'],
        ['label' => 'Inventory', 'url' => '../inventory/index.php', 'module' => 'inventory', 'action' => 'view'],
        ['label' => 'Production schedule', 'url' => '../production/schedule.php', 'module' => 'production', 'action' => 'view'],
    ],
    'sales' => [
        ['label' => 'Sales invoices', 'url' => '../sales/index.php', 'module' => 'sales', 'action' => 'view'],
        ['label' => 'New sale', 'url' => '../sales/create.php', 'module' => 'sales', 'action' => 'create'],
        ['label' => 'Customers', 'url' => '../customers/index.php', 'module' => 'customers', 'action' => 'view'],
        ['label' => 'Sales pipeline', 'url' => '../crm/pipeline.php', 'module' => 'crm', 'action' => 'view'],
    ],
    'purchasing' => [
        ['label' => 'Purchase orders', 'url' => '../purchases/index.php', 'module' => 'purchases', 'action' => 'view'],
        ['label' => 'New purchase', 'url' => '../purchases/create.php', 'module' => 'purchases', 'action' => 'create'],
        ['label' => 'Suppliers', 'url' => '../suppliers/index.php', 'module' => 'suppliers', 'action' => 'view'],
        ['label' => 'Inventory', 'url' => '../inventory/index.php', 'module' => 'inventory', 'action' => 'view'],
    ],
    'hr' => [
        ['label' => 'HR overview', 'url' => '../hr/index.php', 'module' => 'hr', 'action' => 'view'],
        ['label' => 'Employees', 'url' => '../hr/employees.php', 'module' => 'hr', 'action' => 'view'],
        ['label' => 'Attendance', 'url' => '../hr/attendance.php', 'module' => 'hr', 'action' => 'view'],
        ['label' => 'Leave requests', 'url' => '../hr/leaves.php', 'module' => 'hr', 'action' => 'view'],
    ],
    'finance' => [
        ['label' => 'Accounts', 'url' => '../finance/accounts.php', 'module' => 'finance', 'action' => 'view'],
        ['label' => 'Expenses', 'url' => '../expenses/index.php', 'module' => 'expenses', 'action' => 'view'],
        ['label' => 'New expense', 'url' => '../expenses/create.php', 'module' => 'expenses', 'action' => 'create'],
    ],
];
$pageTitle = $departmentName . ' Overview';
require_once '../partials/header.php';
?>

<div class="department-dashboard">
    <header class="department-dashboard__header">
        <div>
            <p class="department-dashboard__eyebrow"><?= e($departmentName) ?> DEPARTMENT</p>
            <h1><?= e($departmentName) ?> Overview</h1>
            <p class="department-dashboard__description"><?= e($departmentDescription) ?></p>
        </div>
        <div class="department-dashboard__identity">
            <strong><?= e($dashboardUser['name'] ?? '') ?></strong>
            <span><?= $dashboardDepartmentRole === 'manager' ? 'Department Manager' : 'Department User' ?></span>
        </div>
    </header>

    <section class="department-dashboard__metrics" aria-label="Department metrics">
        <?php foreach ($metrics as $item): ?>
        <article class="department-dashboard__metric">
            <h2><?= e($item['label']) ?></h2>
            <p class="department-dashboard__value"><?= $item['currency'] ? e(currency_symbol()) : '' ?><?= $item['currency'] ? number_format((float) $item['value'], 2) : number_format((int) $item['value']) ?></p>
            <p class="department-dashboard__note"><?= e($item['note']) ?></p>
        </article>
        <?php endforeach; ?>
    </section>

    <?php if ($dashboardDepartment === 'purchasing'): ?>
    <section class="department-dashboard__stock-alert<?= empty($lowStockMaterials) ? ' is-clear' : '' ?>" role="<?= empty($lowStockMaterials) ? 'status' : 'alert' ?>">
        <div class="department-dashboard__stock-alert-heading">
            <div>
                <h2><?= empty($lowStockMaterials) ? 'Stock levels are healthy' : 'Low stock needs attention' ?></h2>
                <p><?= empty($lowStockMaterials) ? 'No active materials are at or below their reorder point.' : count($lowStockMaterials) . ' active material' . (count($lowStockMaterials) === 1 ? ' is' : 's are') . ' at or below the reorder point.' ?></p>
            </div>
            <a class="btn <?= empty($lowStockMaterials) ? 'btn-secondary' : 'btn-accent' ?>" href="../inventory/low_stock_alert.php">View low stock alerts</a>
        </div>
        <?php if (!empty($lowStockMaterials)): ?>
        <ul class="department-dashboard__stock-list">
            <?php foreach (array_slice($lowStockMaterials, 0, 5) as $material): ?>
            <li>
                <span><strong><?= e($material['name']) ?></strong><small><?= number_format((float) $material['current_stock'], 2) ?> <?= e($material['unit']) ?> remaining; reorder point <?= number_format((float) $material['reorder_point'], 2) ?></small></span>
                <?php if (has_permission('purchases', 'create')): ?><a href="../purchases/create.php?material=<?= (int) $material['id'] ?>">Create purchase</a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="department-dashboard__section">
        <div>
            <h2>Department workspace</h2>
            <p><?= e(date('l, d F Y')) ?></p>
        </div>
        <nav class="department-dashboard__links" aria-label="Department actions">
            <?php foreach ($linksByDepartment[$dashboardDepartment] as $link): ?>
                <?php if (has_permission($link['module'], $link['action'])): ?>
                <a class="btn btn-secondary" href="<?= e($link['url']) ?>"><?= e($link['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </section>
</div>

<style>
.department-dashboard{max-width:1400px;margin:0 auto}
.department-dashboard__header{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;padding:28px 30px;margin-bottom:22px;background:linear-gradient(115deg,#173b4f,#b8481b 72%,#d9742f);color:#fff;border-radius:12px}
.department-dashboard__header h1{margin:4px 0 8px;color:#fff;font-size:2rem}
.department-dashboard__description{margin:0;color:rgba(255,255,255,.82)}
.department-dashboard__eyebrow{margin:0;color:#b8ded8;font-size:.76rem;font-weight:700}
.department-dashboard__identity{display:grid;gap:4px;text-align:right;white-space:nowrap}
.department-dashboard__identity span{font-size:.85rem;color:rgba(255,255,255,.75)}
.department-dashboard__metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.department-dashboard__metric{padding:18px 20px;border:1px solid #e3ddd4;border-top:3px solid #d9742f;background:#fff;border-radius:8px}
.department-dashboard__metric h2{margin:0;color:#6a6259;font-size:.8rem;text-transform:uppercase}
.department-dashboard__value{margin:14px 0 5px;color:#1f2933;font-size:1.8rem;font-weight:800}
.department-dashboard__note{margin:0;color:#839499;font-size:.85rem}
.department-dashboard__stock-alert{margin-top:22px;padding:20px;border:1px solid #f2c879;border-left:5px solid #d97706;border-radius:8px;background:#fff9e9;color:#78350f}
.department-dashboard__stock-alert.is-clear{border-color:#9ed5b3;border-left-color:#198754;background:#f1fbf4;color:#14532d}
.department-dashboard__stock-alert-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.department-dashboard__stock-alert h2{margin:0;font-size:1.05rem}
.department-dashboard__stock-alert p{margin:5px 0 0}
.department-dashboard__stock-list{list-style:none;margin:14px 0 0;padding:0;border-top:1px solid rgba(120,53,15,.18)}
.department-dashboard__stock-list li{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:11px 0;border-bottom:1px solid rgba(120,53,15,.14)}
.department-dashboard__stock-list li span{display:grid;gap:3px}
.department-dashboard__stock-list small{color:#765e3d}
.department-dashboard__stock-list a{font-weight:700;white-space:nowrap;color:#176b58}
.department-dashboard__section{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-top:28px;padding:20px 0;border-top:1px solid #e3ddd4}
.department-dashboard__section h2{margin:0;color:#1f2933;font-size:1.2rem}
.department-dashboard__section p{margin:5px 0 0;color:#64748b}
.department-dashboard__links{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:9px}
@media(max-width:700px){.department-dashboard__header,.department-dashboard__section{align-items:flex-start;flex-direction:column}.department-dashboard__identity{text-align:left}.department-dashboard__links{justify-content:flex-start}.department-dashboard__stock-list li{align-items:flex-start;flex-direction:column}}
</style>

<?php require_once '../partials/footer.php'; ?>