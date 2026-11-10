<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_system_admin();
ensure_expense_approval_schema();

$pageTitle = 'Operations Overview';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$totals = [
    'sales_today' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = '$today'")->fetchColumn(),
    'production_today' => $pdo->query("SELECT COALESCE(SUM(good_quantity),0) FROM production WHERE production_date = '$today'")->fetchColumn(),
    'expenses_today' => $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = '$today' AND (approval_status = 'approved' OR approval_status IS NULL)")->fetchColumn(),
    'purchases_today' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date = '$today' AND (approval_status IS NULL OR approval_status <> 'rejected')")->fetchColumn(),
    'sales_mtd' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= '$monthStart'")->fetchColumn(),
    'purchases_mtd' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM purchases WHERE purchase_date >= '$monthStart' AND (approval_status IS NULL OR approval_status <> 'rejected')")->fetchColumn(),
    'receivables' => $pdo->query("SELECT COALESCE(SUM(balance),0) FROM sales WHERE balance > 0")->fetchColumn(),
    'payables' => $pdo->query("SELECT COALESCE(SUM(balance),0) FROM purchases WHERE balance > 0")->fetchColumn(),
    'low_stock' => $pdo->query("SELECT COUNT(*) FROM raw_materials rm JOIN reorder_levels rl ON rm.id = rl.material_id WHERE rm.current_stock <= rl.reorder_point")->fetchColumn(),
    'pending_tasks' => $pdo->query("SELECT COUNT(*) FROM task_reminders WHERE status != 'done'")->fetchColumn(),
    'active_customers' => $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 1")->fetchColumn(),
    'active_suppliers' => $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 1")->fetchColumn(),
];

$productionByType = $pdo->query("SELECT bt.name, bt.size, COALESCE(SUM(p.good_quantity),0) AS qty FROM block_types bt LEFT JOIN production p ON p.block_type_id = bt.id AND p.production_date >= '$monthStart' GROUP BY bt.id, bt.name, bt.size ORDER BY qty DESC LIMIT 5")->fetchAll();

$upcomingDispatches = $pdo->query("SELECT d.dispatch_date, d.destination, d.quantity, c.name AS customer_name, d.status FROM dispatches d LEFT JOIN customers c ON c.id = d.customer_id WHERE d.dispatch_date >= '$today' ORDER BY d.dispatch_date ASC LIMIT 5")->fetchAll();

$upcomingDeliveries = $pdo->query("SELECT sd.delivery_date, sd.item_name, sd.quantity, s.name AS supplier_name, sd.status FROM supplier_deliveries sd JOIN suppliers s ON s.id = sd.supplier_id WHERE sd.delivery_date >= '$today' ORDER BY sd.delivery_date ASC LIMIT 5")->fetchAll();

$qualityIssues = $pdo->query("SELECT q.check_date, bt.name, bt.size, q.result, q.notes FROM production_quality_checks q JOIN block_types bt ON bt.id = q.block_type_id WHERE q.result = 'fail' ORDER BY q.check_date DESC LIMIT 5")->fetchAll();

require_once "../partials/header.php";
?>
<style>
    .ops-header {
        display: flex; justify-content: space-between; align-items: center;
        background: linear-gradient(135deg, rgba(57,73,171,0.1) 0%, rgba(0,137,123,0.1) 100%);
        padding: 25px 30px; border-radius: 16px; margin-bottom: 30px; border: 1px solid rgba(0,0,0,0.05);
    }
    .ops-header h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: #1a237e; }
    .ops-header p { margin: 0; color: #546e7a; font-size: 0.95rem; }
    
    .ops-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .ops-card {
        background: #fff; border-radius: 16px; padding: 25px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.03); border: 1px solid #edf2f9;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s;
        position: relative; overflow: hidden;
    }
    .ops-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.08); }
    .ops-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%;
        background: var(--card-color, #e2e8f0);
    }
    .ops-card.c-prod { --card-color: #4f4fc9; }
    .ops-card.c-sales { --card-color: #43a047; }
    .ops-card.c-purch { --card-color: #fb8c00; }
    .ops-card.c-exp { --card-color: #e53935; }
    
    .ops-card h3 { margin: 0 0 10px 0; font-size: 0.9rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .ops-card .value { font-size: 2.2rem; font-weight: 800; color: #1e293b; margin-bottom: 5px; line-height: 1.1; }
    .ops-card .label { font-size: 0.85rem; color: #94a3b8; font-weight: 500; }
    
    .ops-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px; }
    
    .ops-panel {
        background: #fff; border-radius: 16px; padding: 25px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.02); border: 1px solid #edf2f9;
    }
    .ops-panel h3 { margin: 0 0 20px 0; font-size: 1.2rem; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    
    .alert-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .alert-box {
        padding: 15px; border-radius: 12px; background: #f8fafc;
        display: flex; align-items: center; justify-content: space-between;
        border: 1px solid #e2e8f0; transition: background 0.2s;
    }
    .alert-box:hover { background: #f1f5f9; }
    .alert-box.danger .num { color: #e53935; }
    .alert-box.warn .num { color: #fb8c00; }
    .alert-box.info .num { color: #4f4fc9; }
    .alert-box .title { font-size: 0.9rem; color: #475569; font-weight: 600; }
    .alert-box .num { font-size: 1.5rem; font-weight: 800; }
    
    .modern-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .modern-table th { background: #f8fafc; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    .modern-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.95rem; }
    .modern-table tr:last-child td { border-bottom: none; }
    .modern-table tbody tr { transition: background 0.2s; }
    .modern-table tbody tr:hover { background: #fbfbfc; }
    
    /* Dark mode overrides */
    body.dark-theme .ops-header { background: rgba(57,73,171,0.2); border-color: rgba(255,255,255,0.05); }
    body.dark-theme .ops-header h1 { color: #fff; }
    body.dark-theme .ops-card, body.dark-theme .ops-panel { background: #1e1e1e; border-color: #333; }
    body.dark-theme .ops-card .value { color: #fff; }
    body.dark-theme .ops-panel h3 { color: #fff; }
    body.dark-theme .alert-box { background: #2a2a2a; border-color: #444; }
    body.dark-theme .alert-box:hover { background: #333; }
    body.dark-theme .alert-box .title { color: #ccc; }
    body.dark-theme .modern-table th { background: #2a2a2a; border-color: #444; color: #aaa; }
    body.dark-theme .modern-table td { border-color: #333; color: #eee; }
    body.dark-theme .modern-table tbody tr:hover { background: #2c2c2c; }
    
    @media (max-width: 900px) { .ops-grid { grid-template-columns: 1fr; } }
</style>

<div class="ops-header">
    <div>
        <h1>Operations Overview</h1>
        <p>Plant performance summary for operations and management.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary" style="border-radius:20px; padding:10px 20px;">Back to Dashboard</a>
    </div>
</div>

<div class="ops-cards">
    <div class="ops-card c-prod">
        <h3>📦 Production Today</h3>
        <div class="value"><?= number_format((float) $totals['production_today']) ?></div>
        <div class="label">Total Blocks</div>
    </div>
    <div class="ops-card c-sales">
        <h3>💰 Sales Today</h3>
        <div class="value" style="color:#43a047"><?= number_format((float) $totals['sales_today'], 2) ?></div>
        <div class="label">Revenue (<?= currency_symbol() ?>)</div>
    </div>
    <div class="ops-card c-purch">
        <h3>🛒 Purchases Today</h3>
        <div class="value" style="color:#fb8c00"><?= number_format((float) $totals['purchases_today'], 2) ?></div>
        <div class="label">Materials Cost (<?= currency_symbol() ?>)</div>
    </div>
    <div class="ops-card c-exp">
        <h3>📉 Expenses Today</h3>
        <div class="value" style="color:#e53935"><?= number_format((float) $totals['expenses_today'], 2) ?></div>
        <div class="label">Operating Costs (<?= currency_symbol() ?>)</div>
    </div>
</div>

<div class="ops-cards">
    <div class="ops-card c-sales">
        <h3>📊 Sales MTD</h3>
        <div class="value" style="color:#43a047"><?= number_format((float) $totals['sales_mtd'], 2) ?></div>
        <div class="label">Month to Date</div>
    </div>
    <div class="ops-card c-purch">
        <h3>🧾 Purchases MTD</h3>
        <div class="value" style="color:#fb8c00"><?= number_format((float) $totals['purchases_mtd'], 2) ?></div>
        <div class="label">Month to Date</div>
    </div>
    <div class="ops-card c-prod">
        <h3>💳 Receivables</h3>
        <div class="value" style="color:#4f4fc9"><?= number_format((float) $totals['receivables'], 2) ?></div>
        <div class="label">From Customers</div>
    </div>
    <div class="ops-card c-exp">
        <h3>📮 Payables</h3>
        <div class="value" style="color:#e53935"><?= number_format((float) $totals['payables'], 2) ?></div>
        <div class="label">To Suppliers</div>
    </div>
</div>

<div class="ops-grid">
    <div class="ops-panel">
        <h3><span>🏭</span> Top Production by Block</h3>
        <table class="modern-table">
            <thead><tr><th>Block Profile</th><th>Quantity Produced</th></tr></thead>
            <tbody>
                <?php if (!$productionByType): ?>
                    <tr><td colspan="2">No production data available for this month.</td></tr>
                <?php endif; ?>
                <?php foreach ($productionByType as $row): ?>
                <tr>
                    <td style="font-weight: 500;"><?= e($row['name'] . ' ' . $row['size']) ?></td>
                    <td><span style="background: rgba(57,73,171,0.1); color: #4f4fc9; padding: 4px 10px; border-radius: 20px; font-weight:600; font-size:0.85rem;"><?= number_format((float) $row['qty']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ops-panel">
        <h3><span>🔔</span> Operational Alerts</h3>
        <div class="alert-grid">
            <div class="alert-box danger">
                <span class="title">Low Stock Items</span>
                <span class="num"><?= number_format((int) $totals['low_stock']) ?></span>
            </div>
            <div class="alert-box warn">
                <span class="title">Pending Tasks</span>
                <span class="num"><?= number_format((int) $totals['pending_tasks']) ?></span>
            </div>
            <div class="alert-box info">
                <span class="title">Active Customers</span>
                <span class="num"><?= number_format((int) $totals['active_customers']) ?></span>
            </div>
            <div class="alert-box info">
                <span class="title">Active Suppliers</span>
                <span class="num"><?= number_format((int) $totals['active_suppliers']) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="ops-grid">
    <div class="ops-panel">
        <h3><span>🚚</span> Upcoming Dispatches</h3>
        <table class="modern-table">
            <thead><tr><th>Date</th><th>Destination</th><th>Qty</th></tr></thead>
            <tbody>
                <?php if (!$upcomingDispatches): ?>
                    <tr><td colspan="3">No dispatch schedules.</td></tr>
                <?php endif; ?>
                <?php foreach ($upcomingDispatches as $row): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= format_date($row['dispatch_date']) ?></td>
                    <td>
                        <div style="font-weight:600;"><?= e($row['customer_name'] ?: 'Unknown') ?></div>
                        <div style="font-size:0.8rem; color:#64748b;"><?= e($row['destination'] ?: '-') ?></div>
                    </td>
                    <td style="font-weight:700;"><?= number_format((int) $row['quantity']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ops-panel">
        <h3><span>📦</span> Supplier Deliveries</h3>
        <table class="modern-table">
            <thead><tr><th>Date</th><th>Supplier / Item</th><th>Qty</th></tr></thead>
            <tbody>
                <?php if (!$upcomingDeliveries): ?>
                    <tr><td colspan="3">No inbound deliveries.</td></tr>
                <?php endif; ?>
                <?php foreach ($upcomingDeliveries as $row): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= format_date($row['delivery_date']) ?></td>
                    <td>
                        <div style="font-weight:600;"><?= e($row['supplier_name']) ?></div>
                        <div style="font-size:0.8rem; color:#64748b;"><?= e($row['item_name'] ?: '-') ?></div>
                    </td>
                    <td style="font-weight:700;"><?= number_format((int) $row['quantity']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ops-panel">
    <h3><span>⚠️</span> Recent Quality Issues</h3>
    <table class="modern-table">
        <thead><tr><th>Date</th><th>Block Type</th><th>Result</th><th>Notes</th></tr></thead>
        <tbody>
            <?php if (!$qualityIssues): ?>
                <tr><td colspan="4" style="text-align:center; padding:30px; color:#64748b;">No recent quality failures. Keep up the good work!</td></tr>
            <?php endif; ?>
            <?php foreach ($qualityIssues as $row): ?>
            <tr>
                <td><?= format_date($row['check_date']) ?></td>
                <td style="font-weight:500;"><?= e($row['name'] . ' ' . $row['size']) ?></td>
                <td><span style="background: rgba(229,57,53,0.1); color: #e53935; padding: 4px 10px; border-radius: 20px; font-weight:600; font-size:0.8rem; text-transform:uppercase;">Fail</span></td>
                <td style="color:#64748b; font-style:italic;"><?= e($row['notes'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
