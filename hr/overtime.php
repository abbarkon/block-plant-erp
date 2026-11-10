<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

// Create overtime_requests table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS overtime_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    overtime_date DATE NOT NULL,
    hours DECIMAL(4,2) NOT NULL,
    reason TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    approved_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
)");

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if($_POST['action'] === 'request_overtime') {
        require_permission('hr', 'create');
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        require_active_employee($employeeId);
        $stmt = $pdo->prepare("INSERT INTO overtime_requests(employee_id, overtime_date, hours, reason) VALUES(?,?,?,?)");
        $stmt->execute([
            $employeeId,
            $_POST['overtime_date'],
            $_POST['hours'],
            $_POST['reason']
        ]);
        set_modal('success', '✅ Request Submitted', 'Overtime request submitted successfully!', 'overtime.php');
    } elseif($_POST['action'] === 'approve' || $_POST['action'] === 'reject') {
        require_permission('hr', 'approve');
        $requestStmt = $pdo->prepare("SELECT employee_id FROM overtime_requests WHERE id = ? AND status = 'pending'");
        $requestStmt->execute([(int) ($_POST['id'] ?? 0)]);
        $employeeId = $requestStmt->fetchColumn();
        require_active_employee($employeeId);
        $status = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE overtime_requests SET status=?, approved_by=?, approved_date=CURDATE() WHERE id=? AND status='pending'");
        $stmt->execute([$status, $_SESSION['user_id'], (int) $_POST['id']]);
        $actionText = $status === 'approved' ? 'approved' : 'rejected';
        $modalTitle = $status === 'approved' ? '✅ Request Approved' : '❌ Request Rejected';
        set_modal('success', $modalTitle, "Overtime request {$actionText} successfully!", 'overtime.php');
    }
}

$pageTitle = "Overtime Tracking";
require_once "../partials/header.php";

// Get overtime requests
$requests = $pdo->query("SELECT o.*, e.name as employee_name, e.employee_code, e.status AS employee_status, u.name as approved_by_name FROM overtime_requests o JOIN employees e ON o.employee_id = e.id LEFT JOIN users u ON o.approved_by = u.id ORDER BY o.overtime_date DESC LIMIT 30")->fetchAll();

// Get employees for request form
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();

// Calculate overtime summary
$summary = $pdo->query("SELECT 
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
    SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END) as total_approved_hours
    FROM overtime_requests WHERE overtime_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>⏱️ Overtime Tracking</h1>
    <?php if (has_permission('hr', 'create')): ?>
    <button onclick="document.getElementById('overtimeForm').style.display='flex'" class="btn btn-accent">+ Request Overtime</button>
    <?php endif; ?>
</div>

<!-- Overtime Request Form Modal -->
<div id="overtimeForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Request Overtime</h2>
            <button onclick="document.getElementById('overtimeForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="request_overtime">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Overtime Date:</label>
                <input type="date" name="overtime_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Hours:</label>
                <input type="number" step="0.5" min="0.5" name="hours" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Reason:</label>
                <textarea name="reason" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Submit Request</button>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-bottom:20px;">
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#22b8cf;"><?= number_format($summary['pending']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Pending Requests</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#7a5cd6;"><?= number_format($summary['approved']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Approved (30 days)</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#1c2250;"><?= number_format($summary['total_approved_hours'], 1) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Total Hours (30 days)</div>
    </div>
    <div class="panel" style="margin-bottom:0; text-align:center;">
        <div style="font-size:2rem; font-weight:bold; color:#64748b;"><?= number_format($summary['rejected']) ?></div>
        <div style="color:#646b8c; font-size:0.85rem;">Rejected (30 days)</div>
    </div>
</div>

<!-- Overtime Requests -->
<div class="panel">
    <h3>Overtime Requests</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Hours</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$requests): ?>
                <tr><td colspan="6">No overtime requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach($requests as $request): ?>
                <tr>
                    <td><?= htmlspecialchars($request['employee_name']) ?> (<?= $request['employee_code'] ?>)</td>
                    <td><?= date('d M Y', strtotime($request['overtime_date'])) ?></td>
                    <td><?= number_format($request['hours'], 1) ?></td>
                    <td><?= htmlspecialchars(substr($request['reason'], 0, 50)) ?><?= strlen($request['reason']) > 50 ? '...' : '' ?></td>
                    <td>
                        <span class="badge <?= $request['status'] === 'approved' ? 'badge-success' : ($request['status'] === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                            <?= ucfirst($request['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if($request['status'] === 'pending' && (int) $request['employee_status'] === 1 && has_permission('hr', 'approve')): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $request['id'] ?>">
                            <button type="submit" name="action" value="approve" class="btn-sm" style="background:#10b981;">Approve</button>
                            <button type="submit" name="action" value="reject" class="btn-sm" style="background:#ef4444;">Reject</button>
                        </form>
                        <?php elseif ($request['status'] === 'pending' && (int) $request['employee_status'] !== 1): ?>
                        <small>Employee inactive; action unavailable</small>
                        <?php else: ?>
                        <small><?= $request['approved_by_name'] ? 'By ' . htmlspecialchars($request['approved_by_name']) : '' ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
