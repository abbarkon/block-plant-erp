<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$assetId = $_GET['id'] ?? 0;

if(!$assetId) {
    echo "<script>alert('Invalid asset ID'); location.href='asset_issuance.php';</script>";
    exit;
}

// Get asset details with employee information
$stmt = $pdo->prepare("SELECT ea.*, e.name as employee_name, e.employee_code, e.designation, e.department, u.name as issued_by_name FROM employee_assets ea JOIN employees e ON ea.employee_id = e.id LEFT JOIN users u ON ea.issued_by = u.id WHERE ea.id=?");
$stmt->execute([$assetId]);
$asset = $stmt->fetch();

if(!$asset) {
    echo "<script>alert('Asset not found'); location.href='asset_issuance.php';</script>";
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

$pageTitle = "Asset Handover Form";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Handover Form</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.4;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .paper {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .paper {
                box-shadow: none;
                margin: 0;
                padding: 10mm;
            }
            .no-print {
                display: none !important;
            }
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 18pt;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 10pt;
            color: #666;
        }
        
        .form-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 20px 0;
            text-decoration: underline;
        }
        
        .section {
            margin-bottom: 20px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 10px;
            background: #f9f9f9;
            padding: 5px 10px;
            border-left: 3px solid #333;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 120px;
        }
        
        .info-value {
            flex: 1;
            border-bottom: 1px dotted #333;
        }
        
        .asset-details {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }
        
        .terms {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            font-size: 10pt;
        }
        
        .terms ol {
            margin-left: 20px;
            margin-top: 10px;
        }
        
        .terms li {
            margin-bottom: 5px;
        }
        
        .signature-section {
            margin-top: 40px;
            padding: 20px;
            border: 1px solid #ddd;
        }
        
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 60px;
            padding-top: 5px;
            font-size: 10pt;
        }
        
        .stamp-area {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            border: 2px dashed #999;
        }
        
        .form-id {
            text-align: right;
            font-size: 9pt;
            color: #666;
            margin-bottom: 10px;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .print-btn:hover {
            background: #0056b3;
        }
        
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            text-decoration: none;
        }
        
        .back-btn:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <button class="back-btn no-print" onclick="window.location.href='asset_issuance.php'">← Back</button>
    <button class="print-btn no-print" onclick="window.print()">🖨 Print</button>
    
    <div class="paper">
        <div class="form-id">Form ID: AHF-<?= str_pad($asset['id'], 6, '0', STR_PAD_LEFT) ?></div>
        <div class="form-id">Date: <?= date('d F Y') ?></div>
        
        <div class="header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <p>Asset Management System</p>
        </div>
        
        <div class="form-title">ASSET HANDOVER FORM</div>
        
        <div class="section">
            <div class="section-title">EMPLOYEE INFORMATION</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><?= htmlspecialchars($asset['employee_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Employee Code:</span>
                    <span class="info-value"><?= htmlspecialchars($asset['employee_code']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Designation:</span>
                    <span class="info-value"><?= htmlspecialchars($asset['designation'] ?: 'N/A') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Department:</span>
                    <span class="info-value"><?= htmlspecialchars($asset['department'] ?: 'N/A') ?></span>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">ASSET DETAILS</div>
            <div class="asset-details">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Asset Name:</span>
                        <span class="info-value"><?= htmlspecialchars($asset['asset_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Asset Type:</span>
                        <span class="info-value"><?= htmlspecialchars($asset['asset_type']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Serial Number:</span>
                        <span class="info-value"><?= htmlspecialchars($asset['serial_number'] ?: 'N/A') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Issue Date:</span>
                        <span class="info-value"><?= date('d F Y', strtotime($asset['issue_date'])) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Condition:</span>
                        <span class="info-value"><?= htmlspecialchars($asset['condition_issued']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Issued By:</span>
                        <span class="info-value"><?= htmlspecialchars($asset['issued_by_name'] ?: 'N/A') ?></span>
                    </div>
                </div>
                <?php if($asset['description']): ?>
                <div style="margin-top: 10px;">
                    <span class="info-label" style="display:block; margin-bottom:5px;">Description:</span>
                    <div style="border: 1px solid #ddd; padding: 10px; min-height: 40px;"><?= htmlspecialchars($asset['description']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="terms">
            <strong>TERMS AND CONDITIONS:</strong>
            <ol>
                <li>I acknowledge receipt of the above-mentioned asset in the condition stated.</li>
                <li>I agree to take reasonable care of the asset and use it only for company-related work.</li>
                <li>I will be responsible for any damage, loss, or theft of the asset while in my possession.</li>
                <li>I must return the asset in good condition upon request or when leaving the company.</li>
                <li>I will report any issues or damage to the asset immediately to the HR department.</li>
                <li>I understand that misuse or negligence may result in disciplinary action or financial liability.</li>
                <li>The company reserves the right to inspect the asset at any time.</li>
            </ol>
        </div>
        
        <div class="signature-section">
            <div class="section-title">SIGNATURES</div>
            <div class="signature-grid">
                <div class="signature-box">
                    <div style="height: 80px;"></div>
                    <div class="signature-line">Employee Signature</div>
                    <div style="margin-top: 10px; font-size: 9pt;">Date: _______________</div>
                </div>
                <div class="signature-box">
                    <div style="height: 80px;"></div>
                    <div class="signature-line">HR Manager Signature</div>
                    <div style="margin-top: 10px; font-size: 9pt;">Date: _______________</div>
                </div>
                <div class="signature-box">
                    <div style="height: 80px;"></div>
                    <div class="signature-line">Asset Manager Signature</div>
                    <div style="margin-top: 10px; font-size: 9pt;">Date: _______________</div>
                </div>
            </div>
        </div>
        
        <div class="stamp-area">
            <strong>COMPANY STAMP</strong>
            <div style="height: 60px; margin-top: 10px;"></div>
        </div>
        
        <div style="margin-top: 30px; text-align: center; font-size: 9pt; color: #666;">
            <p>This document is an official record of asset handover.</p>
            <p>For any queries, please contact the HR department.</p>
        </div>
    </div>
</body>
</html>
