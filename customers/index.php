<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('customers', 'view');

$pageTitle = "Customer Management";

// Get filter
$category_filter = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT c.*, cc.name as category_name,
        (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as total_sales,
        (SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE customer_id = c.id) as sales_amount,
        (SELECT COUNT(*) FROM customer_interactions WHERE customer_id = c.id AND status='pending') as pending_actions
        FROM customers c
        LEFT JOIN customer_categories cc ON c.category_id = cc.id
        WHERE 1=1";
$params = [];

if ($category_filter) {
    $sql .= " AND c.category_id = ?";
    $params[] = (int) $category_filter;
}
if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.customer_code LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
$categories = $pdo->query("SELECT * FROM customer_categories WHERE status=1")->fetchAll();

// Stats
$stats = $pdo->query("SELECT 
    COUNT(*) as total_customers,
    COALESCE(SUM(credit_limit), 0) as total_credit,
    (SELECT COUNT(*) FROM customer_interactions WHERE status='pending') as pending_interactions,
    (SELECT COUNT(*) FROM sales_pipeline WHERE status='active') as active_opportunities
    FROM customers WHERE status=1")->fetch();
?>
<?php include "../partials/header.php"; ?>

<div class="page-header">
    <h1>👥 Customer Management</h1>
    <div>
        <a href="create.php" class="btn btn-primary">+ New Customer</a>
        <a href="analytics.php" class="btn btn-secondary">📊 Analytics</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #3498db;">👥</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['total_customers']) ?></div>
            <div class="stat-label">Total Customers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #2ecc71;">💰</div>
        <div class="stat-info">
            <div class="stat-value"><?= money($stats['total_credit']) ?></div>
            <div class="stat-label">Total Credit Limit</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #f39c12;">⏰</div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending_interactions'] ?></div>
            <div class="stat-label">Pending Follow-ups</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #9b59b6;">🎯</div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['active_opportunities'] ?></div>
            <div class="stat-label">Active Opportunities</div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" class="filter-form">
        <input type="text" name="search" placeholder="Search customers..." value="<?= e($search) ?>">
        <select name="category">
            <option value="">All Categories</option>
            <?php foreach($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="index.php" class="btn btn-secondary">Clear</a>
    </form>
</div>

<!-- Customers Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th>Phone</th>
                <th>Credit Limit</th>
                <th>Total Sales</th>
                <th>Sales Amount</th>
                <th>Actions</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($customers as $c): ?>
            <tr>
                <td><strong><?= e($c['customer_code']) ?></strong></td>
                <td>
                    <a href="view.php?id=<?= $c['id'] ?>" style="color: #3498db; text-decoration: none;">
                        <?= e($c['name']) ?>
                    </a>
                    <?php if($c['pending_actions'] > 0): ?>
                        <span class="badge badge-warning"><?= $c['pending_actions'] ?> pending</span>
                    <?php endif; ?>
                </td>
                <td><?= e($c['category_name'] ?? 'N/A') ?></td>
                <td><?= e($c['phone']) ?></td>
                <td><?= money($c['credit_limit']) ?></td>
                <td><?= $c['total_sales'] ?></td>
                <td><?= money($c['sales_amount']) ?></td>
                <td>
                    <a href="view.php?id=<?= $c['id'] ?>" class="btn-sm">View</a>
                    <a href="../crm/interactions.php?customer_id=<?= $c['id'] ?>" class="btn-sm">📞</a>
                </td>
                <td>
                    <?php if($c['status']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Inactive</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid #ecf0f1;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 15px;
}
.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}
.stat-value {
    font-size: 28px;
    font-weight: bold;
    color: #2c3e50;
}
.stat-label {
    color: #7f8c8d;
    font-size: 14px;
}
.filter-section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.filter-form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.filter-form input, .filter-form select {
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    flex: 1;
    min-width: 200px;
}
.table-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow-x: auto;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: #34495e;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 600;
}
.data-table td {
    padding: 12px;
    border-bottom: 1px solid #ecf0f1;
}
.data-table tr:hover {
    background: #f8f9fa;
}
.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 5px;
}
.badge-success {
    background: #d4edda;
    color: #155724;
}
.badge-danger {
    background: #f8d7da;
    color: #721c24;
}
.badge-warning {
    background: #fff3cd;
    color: #856404;
}
.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 4px;
    text-decoration: none;
    background: #3498db;
    color: white;
    display: inline-block;
    margin: 2px;
}
.btn-sm:hover {
    background: #2980b9;
}
</style>

<?php include "../partials/footer.php"; ?>