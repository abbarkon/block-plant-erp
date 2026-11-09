<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "HR Global Search";
require_once "../partials/header.php";

$searchQuery = $_GET['q'] ?? '';
$searchType = $_GET['type'] ?? 'all';

$results = [];

if($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    
    // Search Employees
    if($searchType === 'all' || $searchType === 'employees') {
        try {
            $stmt = $pdo->prepare("SELECT id, name, employee_code, designation, department, status FROM employees WHERE (name LIKE ? OR employee_code LIKE ? OR designation LIKE ? OR department LIKE ?) LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results['employees'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['employees'] = [];
        }
    }
    
    // Search Attendance
    if($searchType === 'all' || $searchType === 'attendance') {
        try {
            $stmt = $pdo->prepare("SELECT a.*, e.name as employee_name, e.employee_code FROM attendance a JOIN employees e ON a.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR a.status LIKE ?) AND (e.date_of_joining IS NULL OR a.attendance_date >= e.date_of_joining) ORDER BY a.attendance_date DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $results['attendance'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['attendance'] = [];
        }
    }
    
    // Search Leave Requests
    if($searchType === 'all' || $searchType === 'leaves') {
        try {
            $stmt = $pdo->prepare("SELECT lr.*, e.name as employee_name, e.employee_code FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR lr.leave_type LIKE ? OR lr.status LIKE ?) ORDER BY lr.from_date DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results['leaves'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['leaves'] = [];
        }
    }
    
    // Search Payroll
    if($searchType === 'all' || $searchType === 'payroll') {
        try {
            $stmt = $pdo->prepare("SELECT p.*, e.name as employee_name, e.employee_code FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR p.payment_status LIKE ?) ORDER BY p.year DESC, p.month DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $results['payroll'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['payroll'] = [];
        }
    }
    
    // Search Performance Reviews
    if($searchType === 'all' || $searchType === 'performance') {
        try {
            $stmt = $pdo->prepare("SELECT pr.*, e.name as employee_name, e.employee_code FROM performance_reviews pr JOIN employees e ON pr.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR pr.strengths LIKE ? OR pr.areas_for_improvement LIKE ?) ORDER BY pr.review_date DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results['performance'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['performance'] = [];
        }
    }
    
    // Search Training Programs
    if($searchType === 'all' || $searchType === 'training') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM training_programs WHERE (title LIKE ? OR description LIKE ? OR trainer LIKE ?) ORDER BY start_date DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $results['training'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['training'] = [];
        }
    }
    
    // Search Loan Requests
    if($searchType === 'all' || $searchType === 'loans') {
        try {
            $stmt = $pdo->prepare("SELECT el.*, e.name as employee_name, e.employee_code FROM employee_loans el JOIN employees e ON el.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR el.purpose LIKE ? OR el.approval_status LIKE ?) ORDER BY el.request_date DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results['loans'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['loans'] = [];
        }
    }
    
    // Search Documents
    if($searchType === 'all' || $searchType === 'documents') {
        try {
            $stmt = $pdo->prepare("SELECT ed.*, e.name as employee_name, e.employee_code FROM employee_documents ed JOIN employees e ON ed.employee_id = e.id WHERE (e.name LIKE ? OR e.employee_code LIKE ? OR ed.document_name LIKE ? OR ed.document_type LIKE ?) ORDER BY ed.uploaded_at DESC LIMIT 20");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $results['documents'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            $results['documents'] = [];
        }
    }
}
?>

<h1>🔍 HR Global Search</h1>

<!-- Search Form -->
<form method="get" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <div style="flex:1; min-width:300px;">
            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search employees, attendance, leaves, payroll..." style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:14px;">
        </div>
        <div>
            <select name="type" style="padding:12px; border:1px solid #ddd; border-radius:6px;">
                <option value="all" <?= $searchType === 'all' ? 'selected' : '' ?>>All Categories</option>
                <option value="employees" <?= $searchType === 'employees' ? 'selected' : '' ?>>Employees</option>
                <option value="attendance" <?= $searchType === 'attendance' ? 'selected' : '' ?>>Attendance</option>
                <option value="leaves" <?= $searchType === 'leaves' ? 'selected' : '' ?>>Leave Requests</option>
                <option value="payroll" <?= $searchType === 'payroll' ? 'selected' : '' ?>>Payroll</option>
                <option value="performance" <?= $searchType === 'performance' ? 'selected' : '' ?>>Performance Reviews</option>
                <option value="training" <?= $searchType === 'training' ? 'selected' : '' ?>>Training Programs</option>
                <option value="loans" <?= $searchType === 'loans' ? 'selected' : '' ?>>Loan Requests</option>
                <option value="documents" <?= $searchType === 'documents' ? 'selected' : '' ?>>Documents</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if($searchQuery): ?>
        <a href="search.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if($searchQuery): ?>
<?php $totalResults = array_sum(array_map('count', $results)); ?>

<!-- Search Summary -->
<div style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px;">
    <strong>Found <?= number_format($totalResults) ?> results for "<?= htmlspecialchars($searchQuery) ?>"</strong>
</div>

<?php if($totalResults === 0): ?>
<div class="panel">
    <p style="text-align:center; padding:40px; color:#6a6259;">No results found. Try different keywords or search in a specific category.</p>
</div>
<?php else: ?>

<!-- Employees Results -->
<?php if(isset($results['employees']) && !empty($results['employees'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>👥 Employees (<?= count($results['employees']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Designation</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['employees'] as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td><?= htmlspecialchars($emp['employee_code']) ?></td>
                    <td><?= htmlspecialchars($emp['designation'] ?: 'N/A') ?></td>
                    <td><?= htmlspecialchars($emp['department'] ?: 'N/A') ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $emp['status'] ? '#d1fae5' : '#fee2e2' ?>;">
                            <?= $emp['status'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td><a href="employees.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Attendance Results -->
<?php if(isset($results['attendance']) && !empty($results['attendance'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>📅 Attendance Records (<?= count($results['attendance']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Working Hours</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['attendance'] as $att): ?>
                <tr>
                    <td><?= htmlspecialchars($att['employee_name']) ?> (<?= $att['employee_code'] ?>)</td>
                    <td><?= date('d M Y', strtotime($att['attendance_date'])) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $att['status'] === 'present' ? '#d1fae5' : ($att['status'] === 'absent' ? '#fee2e2' : '#fef3c7') ?>;">
                            <?= ucfirst($att['status']) ?>
                        </span>
                    </td>
                    <td><?= $att['working_hours'] ?: '-' ?></td>
                    <td><a href="attendance.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Leave Results -->
<?php if(isset($results['leaves']) && !empty($results['leaves'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>🏖 Leave Requests (<?= count($results['leaves']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Dates</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['leaves'] as $leave): ?>
                <tr>
                    <td><?= htmlspecialchars($leave['employee_name']) ?> (<?= $leave['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($leave['leave_type']) ?></td>
                    <td><?= date('d M Y', strtotime($leave['from_date'])) ?> - <?= date('d M Y', strtotime($leave['to_date'])) ?></td>
                    <td><?= $leave['total_days'] ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $leave['status'] === 'approved' ? '#d1fae5' : ($leave['status'] === 'rejected' ? '#fee2e2' : '#fef3c7') ?>;">
                            <?= ucfirst($leave['status']) ?>
                        </span>
                    </td>
                    <td><a href="leaves.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Payroll Results -->
<?php if(isset($results['payroll']) && !empty($results['payroll'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>💰 Payroll Records (<?= count($results['payroll']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Month/Year</th>
                    <th>Basic Salary</th>
                    <th>Net Salary</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['payroll'] as $pay): ?>
                <tr>
                    <td><?= htmlspecialchars($pay['employee_name']) ?> (<?= $pay['employee_code'] ?>)</td>
                    <td><?= date('F Y', mktime(0,0,0,$pay['month'],1,$pay['year'])) ?></td>
                    <td><?= e(currency_symbol()) . number_format($pay['basic_salary'], 2) ?></td>
                    <td><?= e(currency_symbol()) . number_format($pay['net_salary'], 2) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $pay['payment_status'] === 'paid' ? '#d1fae5' : '#fef3c7' ?>;">
                            <?= ucfirst($pay['payment_status'] ?: 'Pending') ?>
                        </span>
                    </td>
                    <td><a href="payroll.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Performance Results -->
<?php if(isset($results['performance']) && !empty($results['performance'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>⭐ Performance Reviews (<?= count($results['performance']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Review Period</th>
                    <th>Rating</th>
                    <th>Review Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['performance'] as $perf): ?>
                <tr>
                    <td><?= htmlspecialchars($perf['employee_name']) ?> (<?= $perf['employee_code'] ?>)</td>
                    <td><?= date('F Y', strtotime($perf['review_period'])) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:#fef3c7;">
                            <?= $perf['rating'] ?>/5
                        </span>
                    </td>
                    <td><?= date('d M Y', strtotime($perf['review_date'])) ?></td>
                    <td><a href="performance.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Training Results -->
<?php if(isset($results['training']) && !empty($results['training'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>📚 Training Programs (<?= count($results['training']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Trainer</th>
                    <th>Start Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['training'] as $train): ?>
                <tr>
                    <td><?= htmlspecialchars($train['title']) ?></td>
                    <td><?= htmlspecialchars($train['trainer']) ?></td>
                    <td><?= date('d M Y', strtotime($train['start_date'])) ?></td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $train['status'] === 'completed' ? '#d1fae5' : '#fef3c7' ?>;">
                            <?= ucfirst($train['status']) ?>
                        </span>
                    </td>
                    <td><a href="training.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Loan Results -->
<?php if(isset($results['loans']) && !empty($results['loans'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>💸 Loan Requests (<?= count($results['loans']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Amount</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Remaining Balance</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['loans'] as $loan): ?>
                <tr>
                    <td><?= htmlspecialchars($loan['employee_name']) ?> (<?= $loan['employee_code'] ?>)</td>
                    <td><?= e(currency_symbol()) . number_format($loan['loan_amount'], 2) ?></td>
                    <td><?= $loan['duration_months'] ?> months</td>
                    <td>
                        <span style="padding:5px 10px; border-radius:5px; background:<?= $loan['approval_status'] === 'approved' ? '#d1fae5' : ($loan['approval_status'] === 'rejected' ? '#fee2e2' : '#fef3c7') ?>;">
                            <?= ucfirst($loan['approval_status']) ?>
                        </span>
                    </td>
                    <td><?= e(currency_symbol()) . number_format($loan['remaining_balance'], 2) ?></td>
                    <td><a href="loans.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Document Results -->
<?php if(isset($results['documents']) && !empty($results['documents'])): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>📄 Employee Documents (<?= count($results['documents']) ?>)</h3>
    <div class="table-container" style="margin-top:15px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Document Name</th>
                    <th>Type</th>
                    <th>Uploaded Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results['documents'] as $doc): ?>
                <tr>
                    <td><?= htmlspecialchars($doc['employee_name']) ?> (<?= $doc['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($doc['document_name']) ?></td>
                    <td><?= htmlspecialchars($doc['document_type']) ?></td>
                    <td><?= date('d M Y', strtotime($doc['uploaded_at'])) ?></td>
                    <td><a href="documents.php" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<!-- Search Tips -->
<?php if(!$searchQuery): ?>
<div class="panel">
    <h3>Search Tips</h3>
    <ul style="margin-top:15px; line-height:1.8;">
        <li>Search by employee name, code, or department</li>
        <li>Search by dates (e.g., specific month or year)</li>
        <li>Search by status (e.g., "approved", "pending", "active")</li>
        <li>Use specific category filters for faster results</li>
        <li>Wildcards are automatically added for partial matches</li>
    </ul>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
