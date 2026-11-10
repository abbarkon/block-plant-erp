<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Employee Documents";

$pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    issue_date DATE,
    expiry_date DATE,
    description TEXT,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
)");

$uploadDir = __DIR__ . '/../uploads/employee_documents/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
// Defence in depth: even if a script is written here, Apache must not execute it.
$htaccess = $uploadDir . '.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
}

$documentTypes = ['Contract', 'ID Copy', 'CV/Resume', 'Educational Certificate', 'Professional Certificate', 'Experience Letter', 'Passport', 'Visa', 'Work Permit', 'Medical Certificate', 'Other'];
$allowedExtensions = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'doc' => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
];
$maxUploadBytes = 10 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to upload employee documents.');
    }

    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $documentType = trim((string) ($_POST['document_type'] ?? ''));
    $documentName = trim((string) ($_POST['document_name'] ?? ''));
    $issueDate = trim((string) ($_POST['issue_date'] ?? ''));
    $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $file = $_FILES['document'];

    $errors = [];
    $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND status = 1");
    $employeeCheck->execute([$employeeId]);
    if (!$employeeCheck->fetchColumn()) {
        $errors[] = 'Select an active employee.';
    }
    if (!in_array($documentType, $documentTypes, true)) {
        $errors[] = 'Select a valid document type.';
    }
    if ($documentName === '' || mb_strlen($documentName) > 255) {
        $errors[] = 'Document name is required and must be 255 characters or fewer.';
    }
    if ($issueDate !== '' && !is_valid_date_string($issueDate)) {
        $errors[] = 'Enter a valid issue date.';
    }
    if ($expiryDate !== '' && !is_valid_date_string($expiryDate)) {
        $errors[] = 'Enter a valid expiry date.';
    }
    if ($issueDate !== '' && $expiryDate !== '' && $expiryDate < $issueDate) {
        $errors[] = 'The expiry date cannot be before the issue date.';
    }
    if (mb_strlen($description) > 2000) {
        $errors[] = 'Description must be 2,000 characters or fewer.';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a file to upload (maximum 10 MB).';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'The upload could not be verified.';
    } elseif ((int) $file['size'] <= 0 || (int) $file['size'] > $maxUploadBytes) {
        $errors[] = 'The file must be between 1 byte and 10 MB.';
    }

    $extension = '';
    if (!$errors) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!isset($allowedExtensions[$extension])) {
            $errors[] = 'Allowed file types: PDF, JPG, JPEG, PNG, DOC, DOCX.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowedExtensions[$extension], true)) {
                $errors[] = 'The file content does not match its extension.';
            }
        }
    }

    if (!$errors) {
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $uploadDir . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $errors[] = 'Could not save the uploaded file.';
        } else {
            @chmod($targetPath, 0640);
            try {
                $stmt = $pdo->prepare("INSERT INTO employee_documents(employee_id, document_type, document_name, file_path, issue_date, expiry_date, description, uploaded_by) VALUES(?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $employeeId,
                    $documentType,
                    $documentName,
                    '/block_plant/uploads/employee_documents/' . $storedName,
                    $issueDate !== '' ? $issueDate : null,
                    $expiryDate !== '' ? $expiryDate : null,
                    $description !== '' ? $description : null,
                    (int) $_SESSION['user_id'],
                ]);
                log_activity('create', 'hr', (int) $pdo->lastInsertId(), 'Uploaded employee document: ' . $documentName);
                set_flash('success', 'Document uploaded successfully.');
                header('Location: documents.php');
                exit;
            } catch (Throwable $e) {
                @unlink($targetPath);
                $errors[] = 'Could not save the document record.';
            }
        }
    }

    if ($errors) {
        set_flash('error', implode(' ', $errors));
        header('Location: documents.php');
        exit;
    }
}

$documents = $pdo->query("SELECT ed.*, e.name as employee_name, e.employee_code, u.name as uploaded_by_name FROM employee_documents ed JOIN employees e ON ed.employee_id = e.id LEFT JOIN users u ON ed.uploaded_by = u.id ORDER BY ed.uploaded_at DESC LIMIT 30")->fetchAll();
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();
$flash = get_flash();
require_once "../partials/header.php";
?>

<?php if ($flash): ?><div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>📄 Employee Documents</h1>
    <?php if (has_permission('hr', 'create')): ?>
    <button onclick="document.getElementById('documentForm').style.display='flex'" class="btn btn-accent">+ Upload Document</button>
    <?php endif; ?>
</div>

<?php if (has_permission('hr', 'create')): ?>
<!-- Document Upload Form Modal -->
<div id="documentForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Upload Document</h2>
            <button onclick="document.getElementById('documentForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Document Type:</label>
                <select name="document_type" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($documentTypes as $type): ?>
                    <option value="<?= $type ?>"><?= $type ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Document Name:</label>
                <input type="text" name="document_name" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>File:</label>
                <input type="file" name="document" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                <div>
                    <label>Issue Date:</label>
                    <input type="date" name="issue_date" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label>Expiry Date:</label>
                    <input type="date" name="expiry_date" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
            </div>
            <div style="margin-bottom:15px;">
                <label>Description:</label>
                <textarea name="description" rows="2" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Upload Document</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Documents List -->
<div class="panel">
    <h3>Employee Documents</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Document Type</th>
                    <th>Document Name</th>
                    <th>Issue Date</th>
                    <th>Expiry Date</th>
                    <th>Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$documents): ?>
                <tr><td colspan="7">No documents uploaded yet.</td></tr>
                <?php endif; ?>
                <?php foreach($documents as $doc): ?>
                <tr>
                    <td><?= htmlspecialchars($doc['employee_name']) ?> (<?= $doc['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($doc['document_type']) ?></td>
                    <td><?= htmlspecialchars($doc['document_name']) ?></td>
                    <td><?= $doc['issue_date'] ? date('d M Y', strtotime($doc['issue_date'])) : '-' ?></td>
                    <td>
                        <?php if($doc['expiry_date']): ?>
                        <span class="badge <?= strtotime($doc['expiry_date']) < strtotime('+30 days') ? 'badge-danger' : 'badge-success' ?>">
                            <?= date('d M Y', strtotime($doc['expiry_date'])) ?>
                        </span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($doc['uploaded_at'])) ?></td>
                    <td>
                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn-sm">View</a>
                        <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="btn-sm" style="background:#64748b;">Download</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Expiring Documents Alert -->
<?php
$expiringDocs = $pdo->query("SELECT ed.*, e.name as employee_name, e.employee_code FROM employee_documents ed JOIN employees e ON ed.employee_id = e.id WHERE ed.expiry_date IS NOT NULL AND ed.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY ed.expiry_date ASC LIMIT 10")->fetchAll();
if($expiringDocs): ?>
<div class="panel" style="margin-top:20px; border-left:4px solid #22b8cf;">
    <h3 style="color:#22b8cf;">⚠️ Expiring Documents (Next 30 Days)</h3>
    <ul style="margin-top:10px;">
        <?php foreach($expiringDocs as $doc): ?>
        <li style="margin-bottom:8px;">
            <strong><?= htmlspecialchars($doc['employee_name']) ?></strong> - <?= htmlspecialchars($doc['document_type']) ?> (<?= htmlspecialchars($doc['document_name']) ?>) expires on <?= date('d M Y', strtotime($doc['expiry_date'])) ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
