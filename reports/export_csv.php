<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('reports', 'view');
ensure_production_curing_schema();

$type = trim((string) ($_GET['type'] ?? 'sales'));
$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$customer_id = (int) ($_GET['customer_id'] ?? 0);
$module = trim((string) ($_GET['module'] ?? ''));
$limit = min(10000, max(1, (int) ($_GET['limit'] ?? 200)));

$allowedTypes = [
    'sales' => 'sales',
    'purchases' => 'purchases',
    'activity' => 'settings',
    'expenses' => 'expenses',
    'production' => 'production',
    'inventory' => 'inventory',
    'payroll' => 'hr',
    'gate' => 'gate',
    'profit_loss' => 'reports',
    'balance_sheet' => 'reports',
];
if (!isset($allowedTypes[$type])) {
    http_response_code(404);
    exit('Unknown export type.');
}
// Each export is limited to the owning module, not just reports/view.
require_permission($allowedTypes[$type], 'view');

if (!is_valid_date_string($from)) {
    $from = date('Y-m-01');
}
if (!is_valid_date_string($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
if (!is_valid_date_string($asOf)) {
    $asOf = date('Y-m-d');
}

/**
 * CSV cells starting with a formula character are prefixed so spreadsheet
 * applications treat user data as text, not executable formulas.
 */
function csv_cell($value) {
    if (!is_string($value)) {
        return $value;
    }
    $value = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $value);
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function csv_row($fp, $row) {
    fputcsv($fp, array_map('csv_cell', $row));
}

$filename = 'export_' . $type . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF");

switch ($type) {
    case 'sales':
        $sql = "SELECT s.sale_date, s.invoice_no, c.name AS customer_name, s.subtotal, s.discount, s.total_amount, s.paid_amount, s.balance, s.payment_status, s.cogs_amount FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.sale_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($customer_id > 0) {
            $sql .= ' AND s.customer_id = ?';
            $params[] = $customer_id;
        }
        $sql .= ' ORDER BY s.sale_date DESC, s.id DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Date', 'Invoice No', 'Customer', 'Subtotal', 'Discount', 'Total', 'Paid', 'Balance', 'Status', 'COGS']);
        $totals = ['total' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'cogs' => 0.0];
        foreach ($rows as $row) {
            $totals['total'] += (float) $row['total_amount'];
            $totals['paid'] += (float) $row['paid_amount'];
            $totals['balance'] += (float) $row['balance'];
            $totals['cogs'] += (float) $row['cogs_amount'];
            csv_row($fp, [
                $row['sale_date'], $row['invoice_no'], $row['customer_name'] ?? 'Walk-in',
                (float) $row['subtotal'], (float) $row['discount'], (float) $row['total_amount'],
                (float) $row['paid_amount'], (float) $row['balance'], $row['payment_status'],
                (float) $row['cogs_amount'],
            ]);
        }
        csv_row($fp, []);
        csv_row($fp, ['TOTAL', '', '', '', '', $totals['total'], $totals['paid'], $totals['balance'], '', $totals['cogs']]);
        break;

    case 'purchases':
        $sql = "SELECT p.purchase_date, p.purchase_no, s.name AS supplier_name, p.subtotal, p.total_amount, p.paid_amount, p.balance, p.payment_status FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.purchase_date BETWEEN ? AND ? AND (p.approval_status IS NULL OR p.approval_status <> 'rejected') ORDER BY p.purchase_date DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to, $limit]);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Date', 'Purchase No', 'Supplier', 'Subtotal', 'Total', 'Paid', 'Balance', 'Status']);
        $totals = ['total' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        foreach ($rows as $row) {
            $totals['total'] += (float) $row['total_amount'];
            $totals['paid'] += (float) $row['paid_amount'];
            $totals['balance'] += (float) $row['balance'];
            csv_row($fp, [
                $row['purchase_date'], $row['purchase_no'], $row['supplier_name'] ?? 'N/A',
                (float) $row['subtotal'], (float) $row['total_amount'], (float) $row['paid_amount'],
                (float) $row['balance'], $row['payment_status'],
            ]);
        }
        csv_row($fp, []);
        csv_row($fp, ['TOTAL', '', '', '', $totals['total'], $totals['paid'], $totals['balance'], '']);
        break;

    case 'activity':
        $sql = "SELECT al.created_at, u.name AS user_name, al.action, al.module, al.record_id, al.description, al.ip_address FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE 1 = 1";
        $params = [];
        if ($module !== '') {
            $sql .= ' AND al.module = ?';
            $params[] = $module;
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Date', 'User', 'Action', 'Module', 'Record ID', 'Description', 'IP Address']);
        foreach ($rows as $row) {
            csv_row($fp, [$row['created_at'], $row['user_name'] ?? 'System', $row['action'], $row['module'], $row['record_id'] ?? '', $row['description'] ?? '', $row['ip_address'] ?? '']);
        }
        break;

    case 'expenses':
        ensure_expense_approval_schema();
        $sql = "SELECT e.expense_date, e.voucher_no, e.category, e.description, e.amount, e.payment_method, e.reference_no, e.approval_status, u.name AS created_by_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to, $limit]);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Date', 'Voucher No', 'Category', 'Description', 'Amount', 'Payment Method', 'Reference', 'Approval', 'Created By']);
        $approvedTotal = 0.0;
        $pendingTotal = 0.0;
        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            if ($row['approval_status'] === 'approved') {
                $approvedTotal += $amount;
            } else {
                $pendingTotal += $amount;
            }
            csv_row($fp, [
                $row['expense_date'], $row['voucher_no'], $row['category'], $row['description'],
                $amount, $row['payment_method'], $row['reference_no'] ?? '', $row['approval_status'] ?? 'approved',
                $row['created_by_name'] ?? 'System',
            ]);
        }
        csv_row($fp, []);
        csv_row($fp, ['Approved total', $approvedTotal]);
        csv_row($fp, ['Pending / rejected total', $pendingTotal]);
        break;

    case 'production':
        $sql = "SELECT p.production_date, p.production_no, bt.name AS block_name, bt.size, p.planned_quantity, p.good_quantity, p.rejected_quantity, p.curing_days, p.ready_date, p.production_cost FROM production p JOIN block_types bt ON bt.id = p.block_type_id WHERE p.production_date BETWEEN ? AND ? ORDER BY p.production_date DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to, $limit]);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Date', 'Production No', 'Block Type', 'Size', 'Planned', 'Good Qty', 'Rejected Qty', 'Curing Days', 'Ready Date', 'Status', 'Production Cost']);
        $totalCost = 0.0;
        foreach ($rows as $row) {
            $readyDate = $row['ready_date'] ?: $row['production_date'];
            $totalCost += (float) $row['production_cost'];
            csv_row($fp, [$row['production_date'], $row['production_no'], $row['block_name'], $row['size'], (int) $row['planned_quantity'], (int) $row['good_quantity'], (int) $row['rejected_quantity'], (int) ($row['curing_days'] ?? production_default_curing_days()), $readyDate, $readyDate > date('Y-m-d') ? 'Curing' : 'Ready', (float) $row['production_cost']]);
        }
        csv_row($fp, []);
        csv_row($fp, ['TOTAL', '', '', '', '', '', '', '', '', '', $totalCost]);
        break;

    case 'inventory':
        ensure_manufacturing_costing_schema();
        $raw = $pdo->query("SELECT name, unit, current_stock, unit_cost FROM raw_materials WHERE status = 1 ORDER BY name LIMIT " . (int) $limit)->fetchAll();
        csv_row($fp, ['RAW MATERIALS']);
        csv_row($fp, ['Material', 'Unit', 'Current Stock', 'Unit Cost', 'Stock Value (cost)']);
        $rawTotal = 0.0;
        foreach ($raw as $row) {
            $value = round((float) $row['current_stock'] * (float) $row['unit_cost'], 2);
            $rawTotal += $value;
            csv_row($fp, [$row['name'], $row['unit'], (float) $row['current_stock'], (float) $row['unit_cost'], $value]);
        }
        csv_row($fp, ['Total raw materials', '', '', '', $rawTotal]);
        csv_row($fp, []);

        $fin = $pdo->query("SELECT fs.block_type_id, bt.name, bt.size, fs.quantity, COALESCE(fs.average_unit_cost, 0) AS avg_cost, bt.selling_price FROM finished_stock fs JOIN block_types bt ON bt.id = fs.block_type_id ORDER BY bt.name LIMIT " . (int) $limit)->fetchAll();
        $curingRows = $pdo->query("SELECT block_type_id, SUM(curing) AS curing FROM (
            SELECT block_type_id, COALESCE(SUM(good_quantity), 0) AS curing FROM production WHERE COALESCE(ready_date, production_date) > CURDATE() GROUP BY block_type_id
            UNION ALL
            SELECT block_type_id, COALESCE(SUM(quantity), 0) AS curing FROM curing_holds WHERE ready_date > CURDATE() GROUP BY block_type_id
        ) curing_parts GROUP BY block_type_id")->fetchAll();
        $curingByBlock = [];
        foreach ($curingRows as $curingRow) {
            $curingByBlock[(int) $curingRow['block_type_id']] = (int) $curingRow['curing'];
        }
        csv_row($fp, ['FINISHED GOODS']);
        csv_row($fp, ['Block Type', 'Size', 'Total Quantity', 'Curing (not ready)', 'Ready to Sell', 'Average Cost', 'Cost Value', 'Selling Price', 'Retail Value']);
        $costTotal = 0.0;
        $retailTotal = 0.0;
        $curingTotal = 0;
        $readyTotal = 0;
        foreach ($fin as $row) {
            $curing = $curingByBlock[(int) $row['block_type_id']] ?? 0;
            $ready = max(0, (int) $row['quantity'] - $curing);
            $costValue = round((float) $row['quantity'] * (float) $row['avg_cost'], 2);
            $retailValue = round((float) $row['quantity'] * (float) $row['selling_price'], 2);
            $costTotal += $costValue;
            $retailTotal += $retailValue;
            $curingTotal += $curing;
            $readyTotal += $ready;
            csv_row($fp, [$row['name'], $row['size'], (float) $row['quantity'], $curing, $ready, (float) $row['avg_cost'], $costValue, (float) $row['selling_price'], $retailValue]);
        }
        csv_row($fp, ['Total finished goods', '', '', $curingTotal, $readyTotal, '', $costTotal, '', $retailTotal]);
        csv_row($fp, []);
        csv_row($fp, ['TOTAL INVENTORY AT COST', '', '', '', '', '', $rawTotal + $costTotal]);
        break;

    case 'payroll':
        require_permission('hr', 'view');
        ensure_payroll_report_schema();
        $month = (int) ($_GET['month'] ?? date('n'));
        $month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
        $payrollYear = (int) ($_GET['year'] ?? date('Y'));
        $payrollYear = ($payrollYear >= 2000 && $payrollYear <= 2100) ? $payrollYear : (int) date('Y');
        $department = trim((string) ($_GET['department'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $validStatuses = ['draft', 'pending_finance', 'approved', 'rejected', 'transferred', 'paid'];

        $sql = "SELECT e.employee_code, e.name AS employee_name, e.department, e.designation,
                       p.basic_salary, p.allowances, p.overtime_hours, p.overtime_payment,
                       p.deductions, p.net_salary, p.workflow_status, p.payment_status,
                       p.payment_date, p.transfer_date, p.bank_reference
                FROM payroll p
                JOIN employees e ON e.id = p.employee_id
                WHERE p.month = ? AND p.year = ?";
        $params = [$month, $payrollYear];
        if ($department !== '') {
            $sql .= ' AND e.department = ?';
            $params[] = $department;
        }
        if ($search !== '') {
            $sql .= ' AND (e.name LIKE ? OR e.employee_code LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $sql .= ' AND p.workflow_status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY e.department, e.name LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Payroll Report', date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $payrollYear]);
        csv_row($fp, []);
        csv_row($fp, ['Employee Code', 'Employee', 'Department', 'Designation', 'Basic', 'Allowances', 'OT Hours', 'OT Pay', 'Total Deductions', 'Net Salary', 'Workflow Status', 'Payment Status', 'Payment Date', 'Transfer Date', 'Bank Reference']);
        $totals = ['basic' => 0.0, 'allowances' => 0.0, 'overtime' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
        foreach ($rows as $row) {
            $totals['basic'] += (float) $row['basic_salary'];
            $totals['allowances'] += (float) $row['allowances'];
            $totals['overtime'] += (float) $row['overtime_payment'];
            $totals['deductions'] += (float) $row['deductions'];
            $totals['net'] += (float) $row['net_salary'];
            csv_row($fp, [
                $row['employee_code'] ?? '', $row['employee_name'], $row['department'] ?? '', $row['designation'] ?? '',
                (float) $row['basic_salary'], (float) $row['allowances'], (float) $row['overtime_hours'],
                (float) $row['overtime_payment'], (float) $row['deductions'], (float) $row['net_salary'],
                $row['workflow_status'], $row['payment_status'], $row['payment_date'] ?? '',
                $row['transfer_date'] ?? '', $row['bank_reference'] ?? '',
            ]);
        }
        csv_row($fp, []);
        csv_row($fp, ['', 'TOTAL', '', '', $totals['basic'], $totals['allowances'], '', $totals['overtime'], $totals['deductions'], $totals['net'], '', '', '', '', '']);
        break;

    case 'gate':
        require_permission('gate', 'view');
        ensure_gate_schema();
        $status = trim((string) ($_GET['status'] ?? ''));
        $validStatuses = ['WAITING_LOADING', 'READY_FOR_GATE', 'REJECTED', 'EXITED', 'CANCELLED'];
        $sql = "SELECT gc.clearance_number, s.invoice_no, c.name AS customer_name, gc.vehicle_plate, gc.driver_name,
                       gc.status, gc.payment_status, gc.payment_amount, gc.balance,
                       gc.generated_at, gc.loaded_at, gc.exit_time, gc.printed_at, u.name AS guard_name, gc.cancellation_reason
                FROM gate_clearances gc
                JOIN sales s ON s.id = gc.sale_id
                LEFT JOIN customers c ON c.id = gc.customer_id
                LEFT JOIN users u ON u.id = gc.guard_id
                WHERE 1 = 1";
        $params = [];
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $sql .= ' AND gc.status = ?';
            $params[] = $status;
        }
        $sql .= ' AND DATE(COALESCE(gc.exit_time, gc.generated_at)) >= ? AND DATE(COALESCE(gc.exit_time, gc.generated_at)) <= ?';
        $params[] = $from;
        $params[] = $to;
        $gateSearch = trim((string) ($_GET['q'] ?? ''));
        if ($gateSearch !== '') {
            $sql .= ' AND (gc.clearance_number LIKE ? OR gc.vehicle_plate LIKE ? OR s.invoice_no LIKE ? OR c.name LIKE ?)';
            $like = '%' . $gateSearch . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY COALESCE(gc.exit_time, gc.generated_at) DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        csv_row($fp, ['Clearance', 'Invoice', 'Customer', 'Vehicle', 'Driver', 'Status', 'Payment Status', 'Payment Amount', 'Balance', 'Opened', 'Loaded At', 'Exited At', 'Printed At', 'Guard', 'Reason']);
        foreach ($rows as $row) {
            csv_row($fp, [
                $row['clearance_number'], $row['invoice_no'], $row['customer_name'] ?? 'Walk-in',
                $row['vehicle_plate'], $row['driver_name'] ?? '', $row['status'], $row['payment_status'],
                (float) $row['payment_amount'], (float) $row['balance'], $row['generated_at'],
                $row['loaded_at'] ?? '', $row['exit_time'] ?? '', $row['printed_at'] ?? '',
                $row['guard_name'] ?? '', $row['cancellation_reason'] ?? '',
            ]);
        }
        break;

    case 'profit_loss':
        ensure_expense_approval_schema();
        ensure_manufacturing_costing_schema();
        $rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?");
        $rev->execute([$from, $to]);
        $revenue = (float) $rev->fetchColumn();
        $cogsStmt = $pdo->prepare("SELECT COALESCE(SUM(cogs_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?");
        $cogsStmt->execute([$from, $to]);
        $cogs = (float) $cogsStmt->fetchColumn();
        $exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved'");
        $exp->execute([$from, $to]);
        $expenses = (float) $exp->fetchColumn();
        $gross = $revenue - $cogs;
        $net = $gross - $expenses;

        csv_row($fp, ['Profit & Loss', $from, $to]);
        csv_row($fp, []);
        csv_row($fp, ['Item', 'Amount']);
        csv_row($fp, ['Revenue', $revenue]);
        csv_row($fp, ['Cost of goods sold', $cogs]);
        csv_row($fp, ['Gross profit', $gross]);
        csv_row($fp, ['Operating expenses', $expenses]);
        csv_row($fp, ['Net profit / loss', $net]);
        csv_row($fp, []);
        csv_row($fp, ['Expense category', 'Amount']);
        $byCat = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? AND approval_status = 'approved' GROUP BY category ORDER BY total DESC");
        $byCat->execute([$from, $to]);
        foreach ($byCat->fetchAll() as $row) {
            csv_row($fp, [$row['category'], (float) $row['total']]);
        }
        break;

    case 'balance_sheet':
        $stmt = $pdo->prepare(
            "SELECT coa.account_code, coa.account_name, coa.account_type,
                    COALESCE(SUM(jel.debit), 0) AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit
             FROM chart_of_accounts coa
             JOIN journal_entry_lines jel ON jel.account_id = coa.id
             JOIN journal_entries je ON je.id = jel.entry_id
             WHERE je.status = 'posted' AND je.entry_date <= ? AND coa.status = 1
             GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
             ORDER BY coa.account_code"
        );
        $stmt->execute([$asOf]);
        csv_row($fp, ['Balance Sheet as of', $asOf]);
        csv_row($fp, []);
        csv_row($fp, ['Section', 'Code', 'Account', 'Balance']);
        $totals = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0, 'revenue' => 0.0, 'expense' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $debit = (float) $row['total_debit'];
            $credit = (float) $row['total_credit'];
            $type = $row['account_type'];
            if ($type === 'asset' || $type === 'expense') {
                $net = $debit - $credit;
            } else {
                $net = $credit - $debit;
            }
            if (!isset($totals[$type])) {
                continue;
            }
            $totals[$type] += $net;
            if ($type !== 'revenue' && $type !== 'expense' && abs($net) >= 0.005) {
                csv_row($fp, [ucfirst($type), $row['account_code'], $row['account_name'], $net]);
            }
        }
        $earnings = $totals['revenue'] - $totals['expense'];
        if (abs($earnings) >= 0.005) {
            csv_row($fp, ['Equity', '3900', 'Current period earnings (ledger, unclosed)', $earnings]);
            $totals['equity'] += $earnings;
        }
        csv_row($fp, []);
        csv_row($fp, ['Total assets', $totals['asset']]);
        csv_row($fp, ['Total liabilities', $totals['liability']]);
        csv_row($fp, ['Total equity', $totals['equity']]);
        csv_row($fp, ['Balance check (assets - liabilities - equity)', $totals['asset'] - $totals['liability'] - $totals['equity']]);
        break;
}

fclose($fp);
log_activity('export', 'reports', null, "Report export: {$type} ({$from} to {$to})");
exit;
