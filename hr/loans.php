<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Employee Loans & Advances";
require_once "../partials/header.php";

// Create employee_loans table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    loan_amount DECIMAL(10,2) NOT NULL,
    duration_months INT NOT NULL,
    monthly_payment DECIMAL(10,2) NOT NULL,
    purpose TEXT,
    request_date DATE NOT NULL,
    approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    approval_date DATE,
    start_deduction_date DATE,
    end_deduction_date DATE,
    remaining_balance DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
)");

ensure_loan_repayment_schema();

// Process loan request
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_loan'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to request employee loans.');
    }
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    require_active_employee($employeeId);
    $loanAmount = round((float) ($_POST['loan_amount'] ?? 0), 2);
    $durationMonths = (int) ($_POST['duration_months'] ?? 0);
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    if ($loanAmount <= 0) {
        exit('Enter a loan amount greater than zero.');
    }
    if (mb_strlen($purpose) > 2000) {
        exit('Purpose must be 2,000 characters or fewer.');
    }
    
    // Get employee's basic salary and joining date
    $stmt = $pdo->prepare("SELECT basic_salary, date_of_joining FROM employees WHERE id=? AND status=1");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if(!$employee) {
        $showModal = true;
        $modalType = 'error';
        $modalTitle = '❌ Employee Not Found';
        $modalMessage = 'The selected employee could not be found in the system.';
    } else {
        // Perform actual validation checks at submission time
        $reasons = [];
        
        // Check employment duration
        $joiningDate = $employee['date_of_joining'];
        $twoMonthsAgo = date('Y-m-d', strtotime('-2 months'));
        
        if($joiningDate && $joiningDate > $twoMonthsAgo) {
            $monthsWorked = floor((strtotime(date('Y-m-d')) - strtotime($joiningDate)) / (30 * 24 * 60 * 60));
            $reasons[] = "Less than 2 months employed ({$monthsWorked} month(s))";
        }
        
        // Check active loans
        $activeLoanStmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(remaining_balance) as balance FROM employee_loans WHERE employee_id = ? AND approval_status = 'approved' AND remaining_balance > 0");
        $activeLoanStmt->execute([$employeeId]);
        $activeLoan = $activeLoanStmt->fetch();
        if($activeLoan['count'] > 0) {
            $reasons[] = "Active loan balance: " . e(currency_symbol()) . number_format($activeLoan['balance'], 2);
        }
        
        // Check pending loans
        $pendingLoanStmt = $pdo->prepare("SELECT COUNT(*) as count FROM employee_loans WHERE employee_id = ? AND approval_status = 'pending'");
        $pendingLoanStmt->execute([$employeeId]);
        $pendingLoan = $pendingLoanStmt->fetch();
        if($pendingLoan['count'] > 0) {
            $reasons[] = "Pending loan request";
        }
        
        // Check exit clearance
        $exitClearanceTableExists = $pdo->query("SHOW TABLES LIKE 'employee_exit_clearances'")->fetchColumn();
        if($exitClearanceTableExists) {
            $exitStmt = $pdo->prepare("SELECT COUNT(*) as count FROM employee_exit_clearances WHERE employee_id = ? AND status IN ('pending', 'approved')");
            $exitStmt->execute([$employeeId]);
            $exitClearance = $exitStmt->fetch();
            if($exitClearance['count'] > 0) {
                $reasons[] = "Exit clearance in progress";
            }
        }
        
        if(!empty($reasons)) {
            $showModal = true;
            $modalType = 'error';
            $modalTitle = '❌ Employee Not Eligible';
            $modalMessage = 'Employee is not eligible for loan: ' . implode(', ', $reasons);
        } else {
            $basicSalary = (float)$employee['basic_salary'];
            $maxLoan = $basicSalary * 2; // Maximum 2 months salary
            
            if($loanAmount > $maxLoan) {
                $showModal = true;
                $modalType = 'error';
                $modalTitle = '💰 Loan Amount Exceeded';
                $modalMessage = 'Loan amount cannot exceed 2 months salary (maximum: ' . e(currency_symbol()) . number_format($maxLoan, 2) . ').';
            } elseif($durationMonths < 1 || $durationMonths > 12) {
                $showModal = true;
                $modalType = 'error';
                $modalTitle = '📅 Invalid Duration';
                $modalMessage = 'Loan duration must be between 1 and 12 months.';
            } else {
                $monthlyPayment = $loanAmount / $durationMonths;
                $requestDate = date('Y-m-d');
                
                $stmt = $pdo->prepare("INSERT INTO employee_loans(employee_id, loan_amount, duration_months, monthly_payment, purpose, request_date, remaining_balance) VALUES(?,?,?,?,?,?,?)");
                $stmt->execute([$employeeId, $loanAmount, $durationMonths, $monthlyPayment, $purpose, $requestDate, $loanAmount]);
                
                // Notify finance users
                $financeUsers = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'accountant', 'finance')")->fetchAll();
                foreach($financeUsers as $financeUser) {
                    $pdo->prepare("INSERT INTO user_notifications(user_id, message, link, created_at) VALUES(?,?,?,NOW())")->execute([
                        $financeUser['id'],
                        "New loan request of " . e(currency_symbol()) . number_format($loanAmount, 2) . " submitted for approval",
                        "/block_plant/finance/loan_approval.php"
                    ]);
                }
                
                $showModal = true;
                $modalType = 'success';
                $modalTitle = '✅ Loan Request Submitted';
                $modalMessage = 'Loan request submitted successfully! Pending finance approval.';
                $modalRedirect = 'loans.php';
            }
        }
    }
}

// Get all active employees
$employees = $pdo->query("SELECT e.id, e.name, e.employee_code, e.basic_salary, e.date_of_joining FROM employees e WHERE e.status = 1 ORDER BY e.name")->fetchAll();

// Check each employee's loan eligibility
$employeeEligibility = [];
$twoMonthsAgo = date('Y-m-d', strtotime('-2 months'));
$exitClearanceTableExists = $pdo->query("SHOW TABLES LIKE 'employee_exit_clearances'")->fetchColumn();

foreach($employees as $emp) {
    $reasons = [];
    $eligible = true;
    
    // Check employment duration
    if(!empty($emp['date_of_joining']) && $emp['date_of_joining'] > $twoMonthsAgo) {
        $monthsWorked = floor((strtotime(date('Y-m-d')) - strtotime($emp['date_of_joining'])) / (30 * 24 * 60 * 60));
        $reasons[] = "Less than 2 months employed ({$monthsWorked} month(s))";
        $eligible = false;
    }
    
    // Check active loans
    $activeLoanStmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(remaining_balance) as balance FROM employee_loans WHERE employee_id = ? AND approval_status = 'approved' AND remaining_balance > 0");
    $activeLoanStmt->execute([$emp['id']]);
    $activeLoan = $activeLoanStmt->fetch();
    if($activeLoan['count'] > 0) {
        $reasons[] = "Active loan balance: " . e(currency_symbol()) . number_format($activeLoan['balance'], 2);
        $eligible = false;
    }
    
    // Check pending loans
    $pendingLoanStmt = $pdo->prepare("SELECT COUNT(*) as count FROM employee_loans WHERE employee_id = ? AND approval_status = 'pending'");
    $pendingLoanStmt->execute([$emp['id']]);
    $pendingLoan = $pendingLoanStmt->fetch();
    if($pendingLoan['count'] > 0) {
        $reasons[] = "Pending loan request";
        $eligible = false;
    }
    
    // Check exit clearance
    if($exitClearanceTableExists) {
        $exitStmt = $pdo->prepare("SELECT COUNT(*) as count FROM employee_exit_clearances WHERE employee_id = ? AND status IN ('pending', 'approved')");
        $exitStmt->execute([$emp['id']]);
        $exitClearance = $exitStmt->fetch();
        if($exitClearance['count'] > 0) {
            $reasons[] = "Exit clearance in progress";
            $eligible = false;
        }
    }
    
    $employeeEligibility[$emp['id']] = [
        'eligible' => $eligible,
        'reasons' => $reasons
    ];
}

// Get loan requests
$loans = $pdo->query("SELECT el.*, e.name as employee_name, e.employee_code, e.basic_salary, u.name as approved_by_name FROM employee_loans el JOIN employees e ON el.employee_id = e.id LEFT JOIN users u ON el.approved_by = u.id ORDER BY el.request_date DESC LIMIT 50")->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>💰 Employee Loans & Advances</h1>
    <button onclick="document.getElementById('loanForm').style.display='flex'" class="btn btn-accent">+ Request Loan</button>
</div>

<!-- Loan Policy Notice -->
<div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 15px 20px; margin-bottom: 20px; border-radius: 6px;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.2rem;">ℹ️</span>
        <div>
            <strong>Loan Eligibility Policy</strong>
            <div style="color: #6a6259; margin-top: 4px; font-size: 0.9rem;">
                • Employee must work for at least <strong>2 months</strong> before requesting a loan<br>
                • <strong>One loan at a time</strong> - no active or pending loans allowed<br>
                • Maximum loan amount: <strong>2 months basic salary</strong><br>
                • Loan duration: <strong>1-12 months</strong>
            </div>
        </div>
    </div>
</div>

<!-- Loan Request Form Modal -->
<div id="loanForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Request Salary Advance/Loan</h2>
            <button onclick="document.getElementById('loanForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="request_loan" value="1">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" id="employeeSelect" required onchange="updateMaxLoan()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="">Select Employee</option>
                    <?php foreach($employees as $emp): 
                        $eligibility = $employeeEligibility[$emp['id']];
                        $disabled = !$eligibility['eligible'] ? 'disabled' : '';
                        $label = htmlspecialchars($emp['name']) . ' (' . $emp['employee_code'] . ') - ' . e(currency_symbol()) . number_format($emp['basic_salary'], 2) . '/month';
                        if(!$eligibility['eligible']) {
                            $label .= ' ⚠️ ' . implode(', ', $eligibility['reasons']);
                        }
                    ?>
                    <option value="<?= $emp['id'] ?>" data-salary="<?= $emp['basic_salary'] ?>" <?= $disabled ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#6a6259; display:block; margin-top:5px;">📋 Employees with ⚠️ are not eligible for loans. Click on disabled options to see reasons.</small>
            </div>
            <div style="margin-bottom:15px;">
                <label>Loan Amount:</label>
                <input type="number" step="0.01" name="loan_amount" id="loanAmount" required placeholder="Enter loan amount" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#6a6259;">Maximum: <span id="maxLoanDisplay">-</span></small>
            </div>
            <div style="margin-bottom:15px;">
                <label>Duration (Months):</label>
                <select name="duration_months" id="durationMonths" required onchange="calculateMonthlyPayment()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?= $m ?>"><?= $m ?> month<?= $m > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Monthly Payment:</label>
                <div id="monthlyPaymentDisplay" style="font-size:1.2rem; font-weight:bold; color:#3b82f6;">-</div>
            </div>
            <div style="margin-bottom:15px;">
                <label>Purpose:</label>
                <textarea name="purpose" rows="3" required placeholder="Reason for loan request" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Submit Loan Request</button>
        </form>
    </div>
</div>

<!-- Loans List -->
<div class="panel">
    <h3>Loan Requests & Status</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Loan Amount</th>
                    <th>Duration</th>
                    <th>Monthly Payment</th>
                    <th>Purpose</th>
                    <th>Request Date</th>
                    <th>Status</th>
                    <th>Remaining Balance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$loans): ?>
                <tr><td colspan="9">No loan requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach($loans as $loan): ?>
                <tr>
                    <td><?= htmlspecialchars($loan['employee_name']) ?> (<?= $loan['employee_code'] ?>)</td>
                    <td><strong><?= e(currency_symbol()) . number_format($loan['loan_amount'], 2) ?></strong></td>
                    <td><?= $loan['duration_months'] ?> month<?= $loan['duration_months'] > 1 ? 's' : '' ?></td>
                    <td><?= e(currency_symbol()) . number_format($loan['monthly_payment'], 2) ?>/month</td>
                    <td><?= htmlspecialchars(substr($loan['purpose'], 0, 50)) ?><?= strlen($loan['purpose']) > 50 ? '...' : '' ?></td>
                    <td><?= date('d M Y', strtotime($loan['request_date'])) ?></td>
                    <td>
                        <?php 
                        $statusBg = '#fff3cd'; // pending (yellow)
                        $statusColor = '#856404';
                        if($loan['approval_status'] === 'approved') {
                            if($loan['remaining_balance'] > 0) {
                                $statusBg = '#d1fae5'; // active (green)  
                                $statusColor = '#065f46';
                                $statusText = 'Active - ' . e(currency_symbol()) . number_format($loan['remaining_balance'], 2) . ' remaining';
                            } else {
                                $statusBg = '#e5e7eb'; // completed (gray)
                                $statusColor = '#374151'; 
                                $statusText = 'Completed';
                            }
                        } elseif($loan['approval_status'] === 'rejected') {
                            $statusBg = '#fee2e2'; // rejected (red)
                            $statusColor = '#991b1b';
                            $statusText = 'Rejected';
                        } else {
                            $statusText = 'Pending Approval';
                        }
                        ?>
                        <span style="padding: 6px 12px; border-radius: 6px; background: <?= $statusBg ?>; color: <?= $statusColor ?>; font-size: 0.85rem; font-weight: 500;">
                            <?= $statusText ?? ucfirst($loan['approval_status']) ?>
                        </span>
                        <?php if($loan['approval_status'] === 'approved' && $loan['remaining_balance'] > 0): ?>
                        <div style="margin-top: 4px; font-size: 0.75rem; color: #f59e0b;">🔒 Blocks new loan requests</div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(currency_symbol()) . number_format($loan['remaining_balance'], 2) ?></td>
                    <td>
                        <a href="loan_request_form.php?loan_id=<?= $loan['id'] ?>" target="_blank" class="btn-sm">📄 Form</a>
                        <?php if($loan['approval_status'] === 'approved'): ?>
                        <a href="loan_repayments.php?employee_id=<?= $loan['employee_id'] ?>" class="btn-sm">Repayments</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Loan Summary -->
<?php 
$totalActiveLoans = 0;
$totalPendingLoans = 0;
$totalOutstanding = 0;
foreach($loans as $loan) {
    if($loan['approval_status'] === 'approved') {
        $totalActiveLoans++;
        $totalOutstanding += $loan['remaining_balance'];
    } elseif($loan['approval_status'] === 'pending') {
        $totalPendingLoans++;
    }
}
?>
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-top:20px;">
    <div style="text-align:center; padding:15px; background:#fff3cd; border-radius:8px;">
        <div style="font-size:1.3rem; font-weight:bold; color:#f59e0b;"><?= number_format($totalPendingLoans) ?></div>
        <div style="color:#6a6259; font-size:0.85rem;">Pending Requests</div>
    </div>
    <div style="text-align:center; padding:15px; background:#d1fae5; border-radius:8px;">
        <div style="font-size:1.3rem; font-weight:bold; color:#10b981;"><?= number_format($totalActiveLoans) ?></div>
        <div style="color:#6a6259; font-size:0.85rem;">Active Loans</div>
    </div>
    <div style="text-align:center; padding:15px; background:#eff6ff; border-radius:8px;">
        <div style="font-size:1.3rem; font-weight:bold; color:#3b82f6;"><?= e(currency_symbol()) . number_format($totalOutstanding, 2) ?></div>
        <div style="color:#6a6259; font-size:0.85rem;">Total Outstanding</div>
    </div>
</div>

<script>
function updateMaxLoan() {
    const select = document.getElementById('employeeSelect');
    const selectedOption = select.options[select.selectedIndex];
    const salary = parseFloat(selectedOption.dataset.salary);
    const maxLoan = salary * 2;
    document.getElementById('maxLoanDisplay').textContent = '<?= e(currency_symbol()) ?>' + maxLoan.toFixed(2);
    calculateMonthlyPayment();
}

function calculateMonthlyPayment() {
    const loanAmount = parseFloat(document.getElementById('loanAmount').value) || 0;
    const duration = parseInt(document.getElementById('durationMonths').value) || 1;
    const monthlyPayment = loanAmount / duration;
    document.getElementById('monthlyPaymentDisplay').textContent = '<?= e(currency_symbol()) ?>' + monthlyPayment.toFixed(2);
}

document.getElementById('loanAmount').addEventListener('input', calculateMonthlyPayment);
</script>

<?php require_once "../partials/footer.php"; ?>

<!-- Beautiful Modal Popup System -->
<?php if (isset($showModal) && $showModal): ?>
<div id="beautifulModal" class="modal-overlay">
    <div class="modal-content <?= $modalType ?>">
        <div class="modal-header">
            <h3><?= htmlspecialchars($modalTitle) ?></h3>
            <button onclick="handleModalClose()" class="modal-close-btn" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p><?= htmlspecialchars($modalMessage) ?></p>
        </div>
        <div class="modal-footer">
            <button onclick="handleModalClose()" class="modal-btn modal-btn-primary">OK</button>
        </div>
    </div>
</div>

<script>
function handleModalClose() {
    const modal = document.getElementById('beautifulModal');
    modal.style.opacity = '0';
    setTimeout(() => {
        <?php if (isset($modalRedirect)): ?>
        window.location.href = <?= json_encode($modalRedirect) ?>;
        <?php else: ?>
        modal.style.display = 'none';
        <?php endif; ?>
    }, 200);
}

// Auto-show modal
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('beautifulModal');
    setTimeout(() => {
        modal.style.opacity = '1';
    }, 100);
});

// Close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        handleModalClose();
    }
});
</script>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-content {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    transform: scale(0.9);
    transition: all 0.3s ease;
    animation: modalSlideIn 0.4s ease-out forwards;
}

@keyframes modalSlideIn {
    0% {
        transform: translateY(-30px) scale(0.9);
        opacity: 0;
    }
    100% {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

.modal-content.success {
    border-top: 6px solid #10b981;
}

.modal-content.error {
    border-top: 6px solid #ef4444;
}

.modal-content.warning {
    border-top: 6px solid #f59e0b;
}

.modal-content.info {
    border-top: 6px solid #3b82f6;
}

.modal-header {
    padding: 24px 24px 16px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: #1e293b;
}

.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    line-height: 1;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close-btn:hover {
    background: #f1f5f9;
    color: #64748b;
    transform: scale(1.1);
}

.modal-close-btn:active {
    transform: scale(0.95);
}

.modal-body {
    padding: 20px 24px;
    color: #475569;
    line-height: 1.6;
}

.modal-body p {
    margin: 0;
    font-size: 1rem;
}

.modal-footer {
    padding: 16px 24px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.modal-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn-primary {
    background: #3b82f6;
    color: white;
}

.modal-btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
}

.modal-content.success .modal-btn-primary {
    background: #10b981;
}

.modal-content.success .modal-btn-primary:hover {
    background: #059669;
}

.modal-content.error .modal-btn-primary {
    background: #ef4444;
}

.modal-content.error .modal-btn-primary:hover {
    background: #dc2626;
}

.modal-content.warning .modal-btn-primary {
    background: #f59e0b;
}

.modal-content.warning .modal-btn-primary:hover {
    background: #d97706;
}

@media (max-width: 640px) {
    .modal-content {
        margin: 20px;
        width: calc(100% - 40px);
    }
    
    .modal-header, .modal-body, .modal-footer {
        padding-left: 20px;
        padding-right: 20px;
    }
    
    .modal-header h3 {
        font-size: 1.1rem;
    }
    
    .modal-close-btn {
        font-size: 1.3rem;
        width: 28px;
        height: 28px;
    }
}
</style>
<?php endif; ?>
