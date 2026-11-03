<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'view');

$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$validStatuses = ['pending', 'accepted', 'rejected'];

$where = [];
$params = [];
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[] = "q.status = ?";
    $params[] = $status;
}
if ($q !== '') {
    $where[] = "(q.quotation_no LIKE ? OR c.name LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT q.*, c.name AS customer_name FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id $whereSql ORDER BY q.created_at DESC LIMIT 500");
$stmt->execute($params);
$quotations = $stmt->fetchAll();

// Check for expired quotations that need notification
$today = date('Y-m-d');
$expiredQuotations = [];
foreach ($quotations as $quot) {
    if ($quot['status'] === 'pending' && $quot['expiry_date'] && $quot['expiry_date'] < $today) {
        $expiredQuotations[] = $quot['quotation_no'];
    }
}

// Show expiry notification modal if we have expired quotations
if (!empty($expiredQuotations)) {
    $expiredCount = count($expiredQuotations);
    $expiredList = implode(', ', $expiredQuotations);
    $modalMessage = "You have <strong>{$expiredCount}</strong> expired quotations: {$expiredList}.<br><br>These quotations are no longer valid for acceptance.";
    set_modal('warning', '⚠️ Expired Quotations', $modalMessage);
}

$counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) AS c FROM quotations GROUP BY status")->fetchAll() as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['c'];
    }
}
$pendingValue = (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM quotations WHERE status='pending'")->fetchColumn();

$pageTitle = t('quotations');
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>📋 <?= t('quotations') ?></h1>
    <div class="header-actions">
        <?php if (has_permission('sales', 'create')): ?>
        <a href="create.php" class="btn btn-accent">+ <?= t('new_quotation') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="background:#f39c12;">📝</div><div><div class="stat-value"><?= (int) $counts['pending'] ?></div><div class="stat-label">Pending</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#2ecc71;">✅</div><div><div class="stat-value"><?= (int) $counts['accepted'] ?></div><div class="stat-label">Accepted</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#e74c3c;">🚫</div><div><div class="stat-value"><?= (int) $counts['rejected'] ?></div><div class="stat-label">Rejected</div></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#3498db;">💰</div><div><div class="stat-value"><?= currency_symbol() . money($pendingValue) ?></div><div class="stat-label">Pending value</div></div></div>
</div>

<div class="panel">
    <form method="get" class="filter-form">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Quotation no or customer">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-accent">Filter</button>
        <a href="index.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Quotation No</th>
                <th>Customer</th>
                <th>Expiry Date</th>
                <th>Total Amount</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$quotations): ?>
            <tr><td colspan="7" style="text-align:center;color:#64748b;padding:24px;">No quotations match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($quotations as $quot): ?>
            <tr>
                <td><?= date('d M Y', strtotime($quot['quotation_date'])) ?></td>
                <td><strong><?= e($quot['quotation_no']) ?></strong></td>
                <td><?= e($quot['customer_name'] ?? 'Walk-in') ?></td>
                <td>
                    <?php 
                    $expiryDate = $quot['expiry_date'];
                    $today = date('Y-m-d');
                    
                    if (!$expiryDate) {
                        echo '—';
                    } else {
                        $expiryFormatted = date('d M Y', strtotime($expiryDate));
                        $isExpired = $expiryDate < $today && $quot['status'] === 'pending';
                        $isExpiringSoon = $expiryDate >= $today && $expiryDate <= date('Y-m-d', strtotime('+7 days')) && $quot['status'] === 'pending';
                        
                        if ($isExpired) {
                            echo '<div style="display: flex; align-items: center; gap: 5px;">';
                            echo $expiryFormatted;
                            echo '<span class="badge badge-danger">EXPIRED</span>';
                            echo '</div>';
                            echo '<small style="color: #dc3545;">Expired ' . floor((strtotime($today) - strtotime($expiryDate)) / (60*60*24)) . ' days ago</small>';
                        } elseif ($isExpiringSoon) {
                            echo '<div style="display: flex; align-items: center; gap: 5px;">';
                            echo $expiryFormatted;
                            echo '<span class="badge badge-warning">SOON</span>';
                            echo '</div>';
                            $daysLeft = floor((strtotime($expiryDate) - strtotime($today)) / (60*60*24));
                            echo '<small style="color: #ffc107;">Expires in ' . $daysLeft . ' day' . ($daysLeft !== 1 ? 's' : '') . '</small>';
                        } else {
                            echo $expiryFormatted;
                        }
                    }
                    ?>
                </td>
                <td><?= currency_symbol() . money($quot['total_amount']) ?></td>
                <td>
                    <?php 
                    $statusBadgeClass = 'badge-';
                    if ($quot['status'] === 'accepted') {
                        $statusBadgeClass .= 'success';
                    } elseif ($quot['status'] === 'rejected') {
                        $statusBadgeClass .= 'danger';
                    } else {
                        $expiryDate = $quot['expiry_date'];
                        $today = date('Y-m-d');
                        $isExpired = $expiryDate && $expiryDate < $today;
                        $statusBadgeClass .= $isExpired ? 'danger' : 'warning';
                    }
                    ?>
                    <span class="badge <?= $statusBadgeClass ?>"><?= e(ucfirst($quot['status'])) ?></span>
                </td>
                <td style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="view.php?id=<?= (int) $quot['id'] ?>" class="btn-sm">View</a>
                    <?php if ($quot['status'] !== 'accepted' && has_permission('sales', 'create')): ?>
                    <a href="../sales/create.php?quotation=<?= (int) $quot['id'] ?>" class="btn-sm" style="background:#28a745;color:#fff;">Convert to Sale</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
