<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';
require_login();
require_permission('hr', 'view');
ensure_employee_photo_schema();

$employeeId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT employee_code, name, designation, department, date_of_joining, status, photo_path FROM employees WHERE id = ?');
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) {
    http_response_code(404);
    exit('Employee not found.');
}
require_active_employee($employeeId);

$photoFile = __DIR__ . '/../uploads/employee_photos/' . basename((string) ($employee['photo_path'] ?? ''));
$photoUrl = !empty($employee['photo_path']) && is_file($photoFile) ? $employee['photo_path'] : null;
$initial = strtoupper(substr(trim((string) $employee['name']), 0, 1));
$companyName = company_display_name();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee ID - <?= e($employee['employee_code']) ?></title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:28px;background:#e9efed;color:#173b4f;font-family:Arial,sans-serif}
        .toolbar{display:flex;justify-content:center;gap:10px;margin:0 auto 22px}
        .toolbar a,.toolbar button{padding:10px 16px;border:0;border-radius:4px;background:#b8481b;color:#fff;font-size:14px;text-decoration:none;cursor:pointer}
        .toolbar a{background:#52616b}
        .card{position:relative;display:flex;width:85.6mm;height:54mm;margin:0 auto;overflow:hidden;border:1px solid #c8d5d1;border-radius:3mm;background:#fff;box-shadow:0 12px 35px rgba(23,59,79,.18)}
        .card-main{display:flex;flex:1;flex-direction:column;min-width:0;padding:4mm 4mm 3mm}
        .company{padding-bottom:2mm;border-bottom:1px solid #e3ddd4;color:#b8481b;font-size:9pt;font-weight:700;line-height:1.15;overflow-wrap:anywhere}
        .card-content{display:flex;flex:1;align-items:center;gap:3mm;padding-top:2mm}
        .photo{flex:none;width:23mm;height:30mm;overflow:hidden;border:1px solid #e3ddd4;border-radius:1.5mm;background:#e7f0ed}
        .photo img{display:block;width:100%;height:100%;object-fit:cover}
        .photo-fallback{display:grid;width:100%;height:100%;place-items:center;color:#b8481b;font-size:24pt;font-weight:700}
        .details{min-width:0;align-self:center}
        .details h1{margin:0 0 2mm;font-size:12pt;line-height:1.05;overflow-wrap:anywhere}
        .role{margin:0 0 2.5mm;color:#52616b;font-size:8pt;line-height:1.15;overflow-wrap:anywhere}
        .field{margin-top:1.5mm;font-size:7pt;line-height:1.15;overflow-wrap:anywhere}
        .field span{display:block;margin-bottom:.4mm;color:#6a6259;font-size:5.5pt;text-transform:uppercase}
        .code{color:#173b4f;font-size:8pt;font-weight:700}
        .card-band{display:flex;width:5mm;align-items:flex-end;justify-content:center;padding:2mm 0;background:linear-gradient(180deg,#b8481b,#1f2933)}
        .card-band span{color:#fff;font-size:5.5pt;font-weight:700;writing-mode:vertical-rl;transform:rotate(180deg)}
        .status{position:absolute;right:7mm;bottom:3mm;color:<?= (int) $employee['status'] === 1 ? '#17643a' : '#8b2525' ?>;font-size:6pt;font-weight:700;text-transform:uppercase}
        @page{size:85.6mm 54mm;margin:0}
        @media print{html,body{width:85.6mm;height:54mm;margin:0;padding:0;background:#fff}.toolbar{display:none}.card{margin:0;border:0;border-radius:0;box-shadow:none;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="ID card actions">
        <a href="employees.php">Back to employees</a>
        <button type="button" onclick="window.print()">Print ID card</button>
    </nav>
    <main class="card" aria-label="Employee ID card">
        <section class="card-main">
            <div class="company"><?= e($companyName) ?></div>
            <div class="card-content">
                <div class="photo">
                    <?php if ($photoUrl): ?>
                    <img src="<?= e($photoUrl) ?>" alt="Photo of <?= e($employee['name']) ?>">
                    <?php else: ?>
                    <div class="photo-fallback" aria-label="Photo not available"><?= e($initial) ?></div>
                    <?php endif; ?>
                </div>
                <div class="details">
                    <h1><?= e($employee['name']) ?></h1>
                    <p class="role"><?= e($employee['designation'] ?: 'Employee') ?><?= $employee['department'] ? ' · ' . e($employee['department']) : '' ?></p>
                    <div class="field"><span>Employee ID</span><strong class="code"><?= e($employee['employee_code']) ?></strong></div>
                    <div class="field"><span>Joined</span><?= $employee['date_of_joining'] ? e(date('d M Y', strtotime($employee['date_of_joining']))) : '—' ?></div>
                </div>
            </div>
        </section>
        <aside class="card-band"><span>EMPLOYEE ID</span></aside>
        <div class="status"><?= (int) $employee['status'] === 1 ? 'Active' : 'Inactive' ?></div>
    </main>
</body>
</html>