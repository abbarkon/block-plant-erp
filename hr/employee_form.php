<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
$employeeId = (int) ($_POST['employee_id'] ?? $_GET['id'] ?? 0);
require_permission('hr', $employeeId > 0 ? 'edit' : 'create');
ensure_department_management_schema();
ensure_employee_photo_schema();
ensure_attendance_shift_schema();

$employee = null;
if($employeeId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        http_response_code(404);
        exit('Employee not found.');
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string) ($_POST['employee_code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $designation = trim((string) ($_POST['designation'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $joining = trim((string) ($_POST['date_of_joining'] ?? ''));
    $salary = round((float) ($_POST['basic_salary'] ?? 0), 2);
    $bank = trim((string) ($_POST['bank_account'] ?? ''));
    $status = isset($_POST['status']) ? 1 : 0;
    $shift = strtolower(trim((string) ($_POST['shift'] ?? 'morning')));
    if (!isset(attendance_shift_options()[$shift])) {
        $shift = 'morning';
    }

    $validationError = '';
    if ($code === '' || mb_strlen($code) > 40) {
        $validationError = 'Employee code is required and must be 40 characters or fewer.';
    } elseif ($name === '' || mb_strlen($name) > 150) {
        $validationError = 'Employee name is required and must be 150 characters or fewer.';
    } elseif (mb_strlen($phone) > 50 || mb_strlen($address) > 255 || mb_strlen($designation) > 100 || mb_strlen($department) > 100 || mb_strlen($bank) > 100) {
        $validationError = 'One or more text fields exceed their maximum length.';
    } elseif ($email !== '' && (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $validationError = 'Enter a valid email address.';
    } elseif (!is_valid_date_string($joining)) {
        $validationError = 'Enter a valid joining date.';
    } elseif ($salary < 0 || $salary > 9999999999.99) {
        $validationError = 'Basic salary must be between 0 and 9,999,999,999.99.';
    } else {
        $codeCheck = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ? AND id <> ?");
        $codeCheck->execute([$code, $employeeId]);
        if ($codeCheck->fetchColumn()) {
            $validationError = 'That employee code is already in use.';
        }
    }

    $departmentStmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE name = ? AND (status = 1 OR name = ?)");
    $departmentStmt->execute([$department, $employee['department'] ?? '']);
    $validDepartment = $department === '' || (int) $departmentStmt->fetchColumn() > 0;
    
    $photoPath = $employee['photo_path'] ?? null;
    $newPhotoFile = null;
    if ($validationError !== '') {
        $error = $validationError;
    } elseif (!$validDepartment) {
        $error = "Select an active department.";
    } elseif($employee && (int) $employee['status'] === 0 && $status === 0) {
        $error = "Inactive employees must be reactivated before their record can be changed.";
    } elseif($employee && (int) $employee['status'] === 1 && $status === 0) {
        $error = "Active employees must complete exit clearance before being made inactive.";
    } else {
        try {
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $photo = $_FILES['photo'];
                if ($photo['error'] !== UPLOAD_ERR_OK || $photo['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('Choose an image smaller than 5 MB.');
                }
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
                $imageInfo = getimagesize($photo['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!$imageInfo || $imageInfo['mime'] !== $mimeType || !isset($extensions[$mimeType])) {
                    throw new RuntimeException('Photo must be a valid JPG, PNG, or WebP image.');
                }

                $uploadDir = __DIR__ . '/../uploads/employee_photos/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Could not prepare the employee photo folder.');
                }
                $photoHtaccess = $uploadDir . '.htaccess';
                if (!file_exists($photoHtaccess)) {
                    @file_put_contents($photoHtaccess, "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
                }
                $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
                $newPhotoFile = $uploadDir . $fileName;
                if (!move_uploaded_file($photo['tmp_name'], $newPhotoFile)) {
                    throw new RuntimeException('Could not save the employee photo.');
                }
                $photoPath = '/block_plant/uploads/employee_photos/' . $fileName;
            }

            if($employee) {
                $stmt = $pdo->prepare("UPDATE employees SET employee_code=?, name=?, phone=?, email=?, address=?, designation=?, department=?, date_of_joining=?, basic_salary=?, bank_account=?, status=?, shift=?, photo_path=? WHERE id=?");
                $stmt->execute([$code, $name, $phone, $email, $address, $designation, $department, $joining, $salary, $bank, $status, $shift, $photoPath, $employee['id']]);
                $msg = "Employee updated successfully!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO employees(employee_code, name, phone, email, address, designation, department, date_of_joining, basic_salary, bank_account, status, shift, photo_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$code, $name, $phone, $email, $address, $designation, $department, $joining, $salary, $bank, $status, $shift, $photoPath]);
                $msg = "Employee added successfully!";
            }

            if ($newPhotoFile && !empty($employee['photo_path'])) {
                $oldPhotoFile = __DIR__ . '/../uploads/employee_photos/' . basename($employee['photo_path']);
                if (is_file($oldPhotoFile)) {
                    unlink($oldPhotoFile);
                }
            }
            set_modal('success', '✅ Employee Updated', $msg, 'employees.php');
        } catch(Throwable $exception) {
            if ($newPhotoFile && is_file($newPhotoFile)) {
                unlink($newPhotoFile);
            }
            $error = "Error: " . $exception->getMessage();
        }
    }
}

$pageTitle = "Employee Form";
require_once "../partials/header.php";

// Generate employee code
$nextCode = $employee ? $employee['employee_code'] : 'EMP' . str_pad($pdo->query("SELECT COUNT(*)+1 FROM employees")->fetchColumn(), 4, '0', STR_PAD_LEFT);
$departmentStmt = $pdo->prepare("SELECT name, status FROM departments WHERE status = 1 OR name = ? ORDER BY status DESC, name");
$departmentStmt->execute([$employee['department'] ?? '']);
$departments = $departmentStmt->fetchAll();
?>

<h1><?= $employee ? 'Edit' : 'Add' ?> Employee</h1>

<?php if(isset($error)): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
    <?= e($error) ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <?php if ($employee): ?><input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>"><?php endif; ?>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
        <div>
            <label>Employee Code *</label>
            <input type="text" name="employee_code" value="<?= htmlspecialchars($employee['employee_code'] ?? $nextCode) ?>" required>
        </div>
        
        <div>
            <label>Full Name *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($employee['name'] ?? '') ?>" required>
        </div>
        
        <div>
            <label>Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
        </div>
        
        <div>
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($employee['email'] ?? '') ?>">
        </div>
        
        <div>
            <label>Designation *</label>
            <input type="text" name="designation" value="<?= htmlspecialchars($employee['designation'] ?? '') ?>" required>
        </div>
        
        <div>
            <label>Department</label>
            <select name="department">
                <option value="">Select Department</option>
                <?php foreach ($departments as $departmentOption): ?>
                <option value="<?= e($departmentOption['name']) ?>" <?= ($employee['department'] ?? '') === $departmentOption['name'] ? 'selected' : '' ?>><?= e($departmentOption['name']) ?><?= !$departmentOption['status'] ? ' (Inactive)' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <small><a href="departments.php">Manage departments</a></small>
        </div>

        <div>
            <label>Work Shift</label>
            <select name="shift">
                <?php foreach (attendance_shift_options() as $shiftKey => $shiftLabels): ?>
                <option value="<?= e($shiftKey) ?>" <?= strtolower(trim((string) ($employee['shift'] ?? 'morning'))) === $shiftKey ? 'selected' : '' ?>>
                    <?= e(app_lang() === 'am' ? $shiftLabels['am'] : $shiftLabels['en']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php $shiftSessions = attendance_work_sessions(date('Y-m-d')); ?>
            <small>
                <?= e(attendance_shift_label('morning')) ?>: <?= e(attendance_local_time_label($shiftSessions['morning']['start'])) ?>–<?= e(attendance_local_time_label($shiftSessions['morning']['end'])) ?> ·
                <?= e(attendance_shift_label('afternoon')) ?>: <?= e(attendance_local_time_label($shiftSessions['afternoon']['start'])) ?>–<?= e(attendance_local_time_label($shiftSessions['afternoon']['end'])) ?>
                (local time). Late after <?= e(attendance_local_time_label($shiftSessions['morning']['grace'])) ?> / <?= e(attendance_local_time_label($shiftSessions['afternoon']['grace'])) ?>.
            </small>
        </div>

        <div>
            <label>Date of Joining *</label>
            <input type="date" name="date_of_joining" value="<?= $employee['date_of_joining'] ?? date('Y-m-d') ?>" required>
        </div>
        
        <div>
            <label>Basic Salary *</label>
            <input type="number" step="0.01" name="basic_salary" value="<?= $employee['basic_salary'] ?? 0 ?>" required>
        </div>
        
        <div>
            <label>Bank Account</label>
            <input type="text" name="bank_account" value="<?= htmlspecialchars($employee['bank_account'] ?? '') ?>">
        </div>
        
        <div>
            <label>
                <?php if($employee && (int) $employee['status'] === 1): ?>
                <input type="checkbox" checked disabled>
                <input type="hidden" name="status" value="1">
                Active
                <?php else: ?>
                <input type="checkbox" name="status" <?= ($employee['status'] ?? 1) ? 'checked' : '' ?>>
                Active
                <?php endif; ?>
            </label>
            <?php if($employee && (int) $employee['status'] === 1): ?>
            <small>To record a departure, use <a href="exit_clearance.php?employee_id=<?= (int) $employee['id'] ?>">employee exit clearance</a>.</small>
            <?php endif; ?>
        </div>
        
        <div style="grid-column: 1 / -1;">
            <label>Address</label>
            <textarea name="address" rows="3"><?= htmlspecialchars($employee['address'] ?? '') ?></textarea>
        </div>

        <div style="grid-column: 1 / -1;">
            <label for="employeePhoto">Employee photo</label>
            <?php if (!empty($employee['photo_path'])): ?>
            <img src="<?= e($employee['photo_path']) ?>" alt="Current photo of <?= e($employee['name']) ?>" style="display:block;width:112px;height:136px;object-fit:cover;border-radius:6px;margin:8px 0;">
            <?php endif; ?>
            <input type="file" name="photo" id="employeePhoto" accept="image/jpeg,image/png,image/webp">
            <small>JPG, PNG, or WebP; maximum 5 MB.</small>
        </div>
    </div>
    
    <div style="margin-top: 20px; display: flex; gap: 10px;">
        <button type="submit" class="btn">Save Employee</button>
        <a href="employees.php" class="btn" style="background: #6c757d;">Cancel</a>
    </div>
</form>

<?php require_once "../partials/footer.php"; ?>
