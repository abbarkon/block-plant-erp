<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_system_admin();
ensure_expense_approval_schema();

$pageTitle = 'Admin Overview';

$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn(),
    'activity' => $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn(),
    'journal_entries' => $pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(),
    'customers' => $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
    'suppliers' => $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn(),
    'total_sales' => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn(),
    'total_expenses' => $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE approval_status = 'approved' OR approval_status IS NULL")->fetchColumn(),
];

$recentActivity = $pdo->query("SELECT al.*, u.name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();

require_once "../partials/header.php";
?>

<style>
/* Modern Admin Overview Styles */
.admin-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    padding: 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 25px rgba(124, 58, 237, 0.2);
    position: relative;
    overflow: hidden;
}

.admin-header::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
    border-radius: 50%;
}

.admin-header h1 {
    font-size: 2.2rem;
    margin: 0;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.admin-header p {
    margin: 8px 0 0 0;
    color: rgba(255, 255, 255, 0.85);
    font-size: 1.1rem;
}

.btn-white {
    background: white;
    color: #4f46e5;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.btn-white:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    background: #f8fafc;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    border: 1px solid rgba(0,0,0,0.05);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(0,0,0,0.08);
}

.stat-card::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 4px;
}

.stat-card.blue::after { background: #3b82f6; }
.stat-card.green::after { background: #10b981; }
.stat-card.purple::after { background: #8b5cf6; }
.stat-card.orange::after { background: #f59e0b; }
.stat-card.rose::after { background: #f43f5e; }
.stat-card.indigo::after { background: #6366f1; }
.stat-card.cyan::after { background: #06b6d4; }
.stat-card.emerald::after { background: #10b981; }

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-icon.blue { background: #eff6ff; color: #3b82f6; }
.stat-icon.green { background: #ecfdf5; color: #10b981; }
.stat-icon.purple { background: #f5f3ff; color: #8b5cf6; }
.stat-icon.orange { background: #fffbeb; color: #f59e0b; }
.stat-icon.rose { background: #fff1f2; color: #f43f5e; }
.stat-icon.indigo { background: #e0e7ff; color: #6366f1; }
.stat-icon.cyan { background: #ecfeff; color: #06b6d4; }
.stat-icon.emerald { background: #d1fae5; color: #10b981; }

.stat-title {
    font-size: 0.95rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.85rem;
    color: #94a3b8;
}

.dashboard-panels {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
}

.beautiful-panel {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    border: 1px solid rgba(0,0,0,0.05);
}

.beautiful-panel h3 {
    margin-top: 0;
    font-size: 1.25rem;
    color: #1e293b;
    font-weight: 700;
    padding-bottom: 16px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.activity-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.activity-table th {
    background: #f8fafc;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #64748b;
    font-size: 0.85rem;
    text-transform: uppercase;
    border-bottom: 2px solid #e2e8f0;
}

.activity-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    font-size: 0.95rem;
}

.activity-table tr:last-child td {
    border-bottom: none;
}

.activity-table tr:hover td {
    background: #f8fafc;
}

.badge {
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.badge-module {
    background: #e2e8f0;
    color: #475569;
}

.nav-links-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-links-list li {
    margin-bottom: 12px;
}

.nav-links-list a {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-radius: 10px;
    color: #334155;
    text-decoration: none;
    font-weight: 500;
    background: #f8fafc;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.nav-links-list a:hover {
    background: #fff;
    border-color: #cbd5e1;
    color: #4f46e5;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.nav-links-list a::before {
    content: '→';
    margin-right: 12px;
    color: #94a3b8;
    transition: color 0.2s;
}

.nav-links-list a:hover::before {
    color: #4f46e5;
}

/* Dark mode adjustments */
body.dark-theme .stat-card,
body.dark-theme .beautiful-panel {
    background: #1e293b;
    border-color: #334155;
}
body.dark-theme .stat-title,
body.dark-theme .stat-label {
    color: #94a3b8;
}
body.dark-theme .stat-value,
body.dark-theme .beautiful-panel h3,
body.dark-theme .activity-table td {
    color: #f8fafc;
}
body.dark-theme .activity-table th {
    background: #0f172a;
    border-bottom-color: #334155;
    color: #94a3b8;
}
body.dark-theme .activity-table td {
    border-bottom-color: #334155;
}
body.dark-theme .activity-table tr:hover td {
    background: #0f172a;
}
body.dark-theme .nav-links-list a {
    background: #0f172a;
    color: #cbd5e1;
}
body.dark-theme .nav-links-list a:hover {
    background: #1e293b;
    border-color: #475569;
    color: #818cf8;
}
body.dark-theme .badge-module {
    background: #334155;
    color: #cbd5e1;
}

@media (max-width: 1024px) {
    .dashboard-panels {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="admin-header">
    <div>
        <h1>Admin Overview</h1>
        <p>System health, user activity, and critical operational metrics.</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-white">Back to Dashboard</a>
    </div>
</div>

<div class="stats-grid">
    <!-- Row 1 -->
    <div class="stat-card blue">
        <div class="stat-header">
            <div class="stat-title">Total Users</div>
            <div class="stat-icon blue">👥</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['users']) ?></div>
        <div class="stat-label">All registered accounts</div>
    </div>
    
    <div class="stat-card green">
        <div class="stat-header">
            <div class="stat-title">Active Users</div>
            <div class="stat-icon green">✅</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['active_users']) ?></div>
        <div class="stat-label">Enabled accounts</div>
    </div>
    
    <div class="stat-card purple">
        <div class="stat-header">
            <div class="stat-title">Journal Entries</div>
            <div class="stat-icon purple">🧾</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['journal_entries']) ?></div>
        <div class="stat-label">Posted financial entries</div>
    </div>
    
    <div class="stat-card orange">
        <div class="stat-header">
            <div class="stat-title">Activity Logs</div>
            <div class="stat-icon orange">📊</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['activity']) ?></div>
        <div class="stat-label">Tracked system events</div>
    </div>

    <!-- Row 2 -->
    <div class="stat-card rose">
        <div class="stat-header">
            <div class="stat-title">Customers</div>
            <div class="stat-icon rose">🧑‍💼</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['customers']) ?></div>
        <div class="stat-label">Registered CRM profiles</div>
    </div>
    
    <div class="stat-card indigo">
        <div class="stat-header">
            <div class="stat-title">Suppliers</div>
            <div class="stat-icon indigo">🏭</div>
        </div>
        <div class="stat-value"><?= number_format((int) $stats['suppliers']) ?></div>
        <div class="stat-label">Partner vendors</div>
    </div>
    
    <div class="stat-card emerald">
        <div class="stat-header">
            <div class="stat-title">Total Sales</div>
            <div class="stat-icon emerald">💰</div>
        </div>
        <div class="stat-value"><?= number_format((float) $stats['total_sales'], 2) ?></div>
        <div class="stat-label">Cumulative sales volume</div>
    </div>
    
    <div class="stat-card cyan">
        <div class="stat-header">
            <div class="stat-title">Total Expenses</div>
            <div class="stat-icon cyan">💸</div>
        </div>
        <div class="stat-value"><?= number_format((float) $stats['total_expenses'], 2) ?></div>
        <div class="stat-label">Cumulative operational costs</div>
    </div>
</div>

<div class="dashboard-panels">
    <div class="beautiful-panel">
        <h3>⚡ Recent System Activity</h3>
        <div style="overflow-x: auto;">
            <table class="activity-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recentActivity): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 30px; color: #94a3b8;">No activity yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentActivity as $row): ?>
                    <tr>
                        <td style="white-space: nowrap; color: #64748b; font-size: 0.85rem;"><?= format_datetime($row['created_at']) ?></td>
                        <td style="font-weight: 500;"><?= e($row['name'] ?? 'System') ?></td>
                        <td><?= e($row['action']) ?></td>
                        <td><span class="badge badge-module"><?= e($row['module']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="beautiful-panel">
        <h3>🛠️ Management Links</h3>
        <ul class="nav-links-list">
            <li><a href="../settings/users.php">User Management</a></li>
            <li><a href="../settings/permissions.php">Role Permissions</a></li>
            <li><a href="../settings/company.php">Company Settings</a></li>
            <li><a href="../settings/activity_log.php">Activity Log</a></li>
            <li><a href="../settings/backup.php">Backup & Restore</a></li>
            <li><a href="../finance/journal.php">Journal Entries</a></li>
        </ul>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
