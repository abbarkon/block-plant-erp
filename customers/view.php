<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('customers', 'view');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, cc.name AS category_name FROM customers c LEFT JOIN customer_categories cc ON c.category_id = cc.id WHERE c.id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Customer: ' . $customer['name'];

$sales = $pdo->prepare("SELECT * FROM sales WHERE customer_id = ? ORDER BY sale_date DESC LIMIT 15");
$sales->execute([$id]);
$sales = $sales->fetchAll();

$interactions = $pdo->prepare("SELECT * FROM customer_interactions WHERE customer_id = ? ORDER BY interaction_date DESC LIMIT 10");
$interactions->execute([$id]);
$interactions = $interactions->fetchAll();

$totals = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(balance),0) AS balance FROM sales WHERE customer_id = ?");
$totals->execute([$id]);
$totals = $totals->fetch();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1><?= e($customer['name']) ?></h1>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">← All customers</a>
        <?php if (has_permission('sales', 'create')): ?>
        <a href="../sales/create.php?customer=<?= $id ?>" class="btn btn-primary">+ New sale</a>
        <?php endif; ?>
        <a href="../crm/interactions.php?customer_id=<?= $id ?>" class="btn btn-accent">Log interaction</a>
        <a href="../reports/customer_statement.php?customer_id=<?= $id ?>" class="btn btn-secondary">Statement</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#3498db;">#</div>
        <div>
            <div class="stat-value"><?= e($customer['customer_code']) ?></div>
            <div class="stat-label">Customer code</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2ecc71;">📦</div>
        <div>
            <div class="stat-value"><?= (int) $totals['cnt'] ?></div>
            <div class="stat-label">Invoices</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#9b59b6;">💰</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($totals['total']) ?></div>
            <div class="stat-label">Lifetime sales</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e67e22;">📋</div>
        <div>
            <div class="stat-value"><?= currency_symbol() . money($totals['balance']) ?></div>
            <div class="stat-label">Outstanding balance</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <h3>Contact &amp; credit</h3>
        <p><strong>Category:</strong> <?= e($customer['category_name'] ?? '—') ?></p>
        <p><strong>Phone:</strong> <?= e($customer['phone'] ?: '—') ?></p>
        <p><strong>Email:</strong> <?= e($customer['email'] ?: '—') ?></p>
        <p><strong>Address:</strong> <?= e($customer['address'] ?: '—') ?></p>
        <p><strong>Credit limit:</strong> <?= currency_symbol() . money($customer['credit_limit']) ?></p>
        <p><strong>Status:</strong>
            <span class="badge badge-<?= $customer['status'] ? 'success' : 'danger' ?>">
                <?= $customer['status'] ? 'Active' : 'Inactive' ?>
            </span>
        </p>
    </div>
    <div class="panel">
        <h3>Recent interactions</h3>
        <?php if (!$interactions): ?>
        <p style="color:#64748b;">No interactions yet.</p>
        <?php else: ?>
        <ul style="margin:0;padding-left:18px;">
            <?php foreach ($interactions as $i): ?>
            <li style="margin-bottom:8px;">
                <strong><?= format_date($i['interaction_date']) ?></strong> — <?= e(ucfirst($i['interaction_type'])) ?>
                <?= $i['subject'] ? ': ' . e($i['subject']) : '' ?>
                <span class="badge badge-<?= $i['status'] === 'pending' ? 'warning' : 'muted' ?>"><?= e($i['status']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <h3>Recent invoices</h3>
    <div class="table-container" style="box-shadow:none;border:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$sales): ?>
                <tr><td colspan="6">No sales recorded.</td></tr>
                <?php endif; ?>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td><a href="../sales/view.php?id=<?= (int) $s['id'] ?>"><?= e($s['invoice_no']) ?></a></td>
                    <td><?= format_date($s['sale_date']) ?></td>
                    <td><?= currency_symbol() . money($s['total_amount']) ?></td>
                    <td><?= currency_symbol() . money($s['paid_amount']) ?></td>
                    <td><?= currency_symbol() . money($s['balance']) ?></td>
                    <td><span class="badge badge-info"><?= e($s['payment_status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
