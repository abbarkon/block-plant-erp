<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$loanId = $_GET['loan_id'] ?? null;

if(!$loanId) {
    echo "<script>alert('Loan ID required!'); location.href='loans.php';</script>";
    exit;
}

// Get loan details
$stmt = $pdo->prepare("SELECT el.*, e.name as employee_name, e.employee_code, e.designation, e.department, e.basic_salary, e.phone, e.address FROM employee_loans el JOIN employees e ON el.employee_id = e.id WHERE el.id=?");
$stmt->execute([$loanId]);
$loan = $stmt->fetch();

if(!$loan) {
    echo "<script>alert('Loan not found!'); location.href='loans.php';</script>";
    exit;
}

// Get company name from database
$companyName = 'Block Plant ERP'; // Default fallback
try {
    $companyStmt = $pdo->query("SELECT company_name FROM company_settings LIMIT 1");
    $companyData = $companyStmt->fetch();
    if($companyData && !empty($companyData['company_name'])) {
        $companyName = $companyData['company_name'];
    }
} catch (PDOException $e) {
    // Use default if table doesn't exist
}

$pageTitle = "Loan Request Form";
require_once "../partials/header.php";
?>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #loanFormPrint, #loanFormPrint * {
        visibility: visible;
    }
    #loanFormPrint {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
    }
    .no-print {
        display: none !important;
    }
}

#loanFormPrint {
    max-width: 800px;
    margin: 0 auto;
    padding: 40px;
    background: white;
    border: 2px solid #333;
    font-family: 'Times New Roman', serif;
}

#loanFormPrint h1 {
    text-align: center;
    border-bottom: 3px solid #333;
    padding-bottom: 15px;
    margin-bottom: 30px;
    font-size: 24px;
    text-transform: uppercase;
}

#loanFormPrint .company-header {
    text-align: center;
    margin-bottom: 30px;
}

#loanFormPrint .company-header h2 {
    margin: 0;
    font-size: 18px;
}

#loanFormPrint .company-header p {
    margin: 5px 0;
    font-size: 14px;
}

#loanFormPrint .form-section {
    margin-bottom: 25px;
}

#loanFormPrint .form-section h3 {
    background: #f5f5f5;
    padding: 10px;
    margin: 0 0 15px 0;
    font-size: 16px;
    border-bottom: 2px solid #333;
}

#loanFormPrint .form-row {
    display: flex;
    margin-bottom: 15px;
}

#loanFormPrint .form-group {
    flex: 1;
    margin-right: 20px;
}

#loanFormPrint .form-group:last-child {
    margin-right: 0;
}

#loanFormPrint label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
    font-size: 14px;
}

#loanFormPrint .value {
    padding: 8px;
    border-bottom: 1px solid #999;
    font-size: 14px;
    min-height: 24px;
}

#loanFormPrint .signature-section {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 2px solid #333;
}

#loanFormPrint .signature-row {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
}

#loanFormPrint .signature-box {
    width: 45%;
}

#loanFormPrint .signature-box label {
    display: block;
    font-weight: bold;
    margin-bottom: 10px;
}

#loanFormPrint .signature-line {
    border-bottom: 2px solid #333;
    height: 50px;
    margin-bottom: 10px;
}

#loanFormPrint .date-line {
    border-bottom: 1px solid #999;
    padding: 8px;
    min-width: 150px;
}

#loanFormPrint .stamp-area {
    border: 2px dashed #999;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    font-size: 12px;
    margin-top: 10px;
}

#loanFormPrint .footer {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #999;
    font-size: 12px;
    text-align: center;
    color: #666;
}
</style>

<div class="no-print" style="margin-bottom: 20px;">
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print Form</button>
    <button onclick="window.location.href='loans.php'" class="btn btn-secondary">← Back to Loans</button>
</div>

<div id="loanFormPrint">
    <div class="company-header">
        <h2><?= htmlspecialchars($companyName) ?></h2>
        <p>Company Address</p>
        <p><strong>Salary Advance / Loan Request Form</strong></p>
    </div>

    <h1>LOAN REQUEST FORM</h1>

    <div class="form-section">
        <h3>Employee Information</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Employee Name:</label>
                <div class="value"><?= htmlspecialchars($loan['employee_name']) ?></div>
            </div>
            <div class="form-group">
                <label>Employee Code:</label>
                <div class="value"><?= htmlspecialchars($loan['employee_code']) ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Designation:</label>
                <div class="value"><?= htmlspecialchars($loan['designation'] ?: 'N/A') ?></div>
            </div>
            <div class="form-group">
                <label>Department:</label>
                <div class="value"><?= htmlspecialchars($loan['department'] ?: 'N/A') ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Phone Number:</label>
                <div class="value"><?= htmlspecialchars($loan['phone'] ?: 'N/A') ?></div>
            </div>
            <div class="form-group">
                <label>Basic Salary:</label>
                <div class="value"><?= e(currency_symbol()) . number_format($loan['basic_salary'], 2) ?></div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h3>Loan Details</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Loan Amount Requested:</label>
                <div class="value"><strong><?= e(currency_symbol()) . number_format($loan['loan_amount'], 2) ?></strong></div>
            </div>
            <div class="form-group">
                <label>Duration (Months):</label>
                <div class="value"><?= $loan['duration_months'] ?> month<?= $loan['duration_months'] > 1 ? 's' : '' ?></div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Monthly Payment:</label>
                <div class="value"><?= e(currency_symbol()) . number_format($loan['monthly_payment'], 2) ?></div>
            </div>
            <div class="form-group">
                <label>Request Date:</label>
                <div class="value"><?= date('d F Y', strtotime($loan['request_date'])) ?></div>
            </div>
        </div>
        <div class="form-group">
            <label>Purpose of Loan:</label>
            <div class="value" style="min-height: 60px;"><?= htmlspecialchars($loan['purpose']) ?></div>
        </div>
    </div>

    <div class="form-section">
        <h3>Terms & Conditions</h3>
        <div style="font-size: 13px; line-height: 1.6;">
            <ol style="margin: 0; padding-left: 20px;">
                <li>I understand that the maximum loan amount is 2 months of my basic salary.</li>
                <li>I agree that the loan amount will be deducted from my monthly salary in equal installments.</li>
                <li>Deductions will start from the next month's payroll after approval.</li>
                <li>I authorize the company to deduct the monthly installment from my salary until the loan is fully repaid.</li>
                <li>I understand that failure to repay the loan may affect my employment status.</li>
                <li>I confirm that all information provided in this form is accurate and complete.</li>
            </ol>
        </div>
    </div>

    <div class="signature-section">
        <h3>Signatures</h3>
        <div class="signature-row">
            <div class="signature-box">
                <label>Employee Signature:</label>
                <div class="signature-line"></div>
                <label>Date:</label>
                <div class="date-line"></div>
            </div>
            <div class="signature-box">
                <label>HR Manager Signature:</label>
                <div class="signature-line"></div>
                <label>Date:</label>
                <div class="date-line"></div>
            </div>
        </div>
        <div class="signature-row">
            <div class="signature-box">
                <label>Finance Manager Signature:</label>
                <div class="signature-line"></div>
                <label>Date:</label>
                <div class="date-line"></div>
            </div>
            <div class="signature-box">
                <label>Company Stamp:</label>
                <div class="stamp-area">[Company Stamp]</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>This form must be signed by the employee, HR Manager, and Finance Manager before loan approval.</p>
        <p>Form ID: #<?= str_pad($loan['id'], 6, '0', STR_PAD_LEFT) ?> | Generated: <?= date('d F Y H:i') ?></p>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
