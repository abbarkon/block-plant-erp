<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$pageTitle = 'Document Library';
$uploadDir = dirname(__DIR__) . '/assets/uploads/documents';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Defence in depth: never let Apache execute anything inside the upload folder,
// even if a dangerous file somehow reaches it.
$htaccess = $uploadDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|exe)$\">\n    Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS document_library (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
)");

$categories = [
    'general' => 'General',
    'certificate' => 'Certificate',
    'report' => 'Report',
    'quality' => 'Quality',
    'legal' => 'Legal',
    'operations' => 'Operations',
];
$allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp'];
$maxBytes = 10 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (!has_permission('settings', 'edit')) {
            set_flash('danger', 'You do not have permission to upload documents.');
            header('Location: documents.php');
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $file = $_FILES['document'] ?? null;

        $errors = [];
        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Title is required (maximum 150 characters).';
        }
        if (!isset($categories[$category])) {
            $errors[] = 'Choose a valid document category.';
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a file to upload.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The upload failed. The file may be larger than the server limit.';
        } elseif ((int) $file['size'] > $maxBytes) {
            $errors[] = 'The file must be 10 MB or smaller.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                $errors[] = 'Allowed file types: ' . implode(', ', $allowedExtensions) . '.';
            }
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }
                $dangerous = ['text/x-php', 'application/x-php', 'application/x-dosexec', 'application/x-executable', 'application/x-msdownload', 'application/x-sharedlib', 'application/x-shellscript'];
                if (in_array($mime, $dangerous, true)) {
                    $errors[] = 'That file type is not allowed.';
                }
            }
        }

        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: documents.php');
            exit;
        }

        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            set_flash('danger', 'The file could not be saved. Check the uploads folder permissions.');
            header('Location: documents.php');
            exit;
        }

        $relative = '/block_plant/assets/uploads/documents/' . $storedName;
        $stmt = $pdo->prepare("INSERT INTO document_library(title, category, file_name, file_path, file_size, uploaded_by) VALUES(?,?,?,?,?,?)");
        $stmt->execute([$title, $category, $storedName, $relative, (int) $file['size'], $_SESSION['user_id']]);
        $docId = (int) $pdo->lastInsertId();
        log_activity('create', 'settings', $docId, "Document uploaded: {$title} ({$storedName})");
        set_flash('success', 'Document uploaded.');
        header('Location: documents.php');
        exit;
    }

    if ($action === 'delete') {
        if (!has_permission('settings', 'delete')) {
            set_flash('danger', 'You do not have permission to delete documents.');
            header('Location: documents.php');
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM document_library WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            set_flash('danger', 'That document no longer exists.');
        } else {
            $pdo->prepare("DELETE FROM document_library WHERE id = ?")->execute([$id]);
            $path = $uploadDir . '/' . basename($doc['file_path']);
            if (is_file($path)) {
                @unlink($path);
            }
            log_activity('delete', 'settings', $id, "Document deleted: {$doc['title']} ({$doc['file_name']})");
            set_flash('success', 'Document deleted.');
        }
        header('Location: documents.php');
        exit;
    }

    header('Location: documents.php');
    exit;
}

$categoryFilter = $_GET['category'] ?? '';
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($categoryFilter !== '' && isset($categories[$categoryFilter])) {
    $where[] = 'd.category = ?';
    $params[] = $categoryFilter;
}
if ($q !== '') {
    $where[] = '(d.title LIKE ? OR d.file_name LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT d.*, u.name AS uploaded_by_name FROM document_library d LEFT JOIN users u ON u.id = d.uploaded_by{$whereSql} ORDER BY d.uploaded_at DESC LIMIT 500");
$stmt->execute($params);
$documents = $stmt->fetchAll();

$totalDocs = (int) $pdo->query("SELECT COUNT(*) FROM document_library")->fetchColumn();
$totalSize = (int) $pdo->query("SELECT COALESCE(SUM(file_size), 0) FROM document_library")->fetchColumn();
$categoryCounts = [];
foreach ($pdo->query("SELECT category, COUNT(*) AS c FROM document_library GROUP BY category")->fetchAll() as $row) {
    $categoryCounts[$row['category']] = (int) $row['c'];
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .doc-layout { display:grid; grid-template-columns: minmax(280px, 1fr) minmax(0, 2.4fr); gap:20px; align-items:start; }
    @media (max-width: 960px) { .doc-layout { grid-template-columns: 1fr; } }
    .doc-form label { margin-top:12px; }
    .doc-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .doc-meta { color:#64748b; font-size:.78rem; }
    .doc-table td { vertical-align:middle; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>📁 Document Library</h1>
        <p style="color:#64748b;margin:6px 0 0;">Certificates, reports, checklists, legal papers, and operational files in one place.</p>
    </div>
    <div class="header-actions no-print">
        <a href="company.php" class="btn btn-secondary">Company settings</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="doc-layout">
    <?php if (has_permission('settings', 'edit')): ?>
    <div class="panel doc-form no-print">
        <h3>Upload document</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <label>Title *</label>
            <input type="text" name="title" maxlength="150" required placeholder="e.g., Business licence 2026">
            <label>Category *</label>
            <select name="category" required>
                <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label>File *</label>
            <input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.webp">
            <p class="hint">Maximum 10 MB. Allowed: PDF, Word, Excel, CSV, TXT, PNG, JPG, WEBP. Executable and script files are blocked.</p>
            <button type="submit" class="btn btn-accent" style="margin-top:16px;">Upload document</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h3>Uploaded files (<?= number_format($totalDocs) ?>) <span class="doc-meta">· <?= e(format_bytes($totalSize)) ?> total</span></h3>
        <form method="get" class="filter-form no-print" style="margin-bottom:14px;">
            <input type="text" name="q" placeholder="Search title or file name" value="<?= e($q) ?>">
            <select name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>>
                    <?= e($label) ?><?= !empty($categoryCounts[$key]) ? ' (' . $categoryCounts[$key] . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="documents.php" class="btn btn-secondary">Reset</a>
        </form>

        <div class="table-container">
            <table class="data-table doc-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>File</th>
                        <th>Uploaded by</th>
                        <th>Date</th>
                        <?php if (has_permission('settings', 'delete')): ?><th class="no-print">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$documents): ?>
                    <tr><td colspan="<?= has_permission('settings', 'delete') ? 6 : 5 ?>">No documents match the selected filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td><strong><?= e($doc['title']) ?></strong></td>
                        <td><span class="badge badge-muted"><?= e($categories[$doc['category']] ?? ucfirst($doc['category'])) ?></span></td>
                        <td>
                            <a href="<?= e($doc['file_path']) ?>" target="_blank" rel="noopener"><?= e($doc['file_name']) ?></a>
                            <div class="doc-meta"><?= e(format_bytes($doc['file_size'])) ?></div>
                        </td>
                        <td><?= e($doc['uploaded_by_name'] ?? 'System') ?></td>
                        <td><?= e(format_datetime($doc['uploaded_at'])) ?></td>
                        <?php if (has_permission('settings', 'delete')): ?>
                        <td class="no-print">
                            <form method="post" onsubmit="return confirm('Delete this document permanently?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
