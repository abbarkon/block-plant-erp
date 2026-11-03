<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Training & Development";
require_once "../partials/header.php";

// Create training_programs table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS training_programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    trainer VARCHAR(200),
    start_date DATE,
    end_date DATE,
    location VARCHAR(200),
    cost DECIMAL(10,2),
    status ENUM('planned','ongoing','completed','cancelled') DEFAULT 'planned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create employee_training table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_training (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    training_id INT NOT NULL,
    enrollment_date DATE,
    completion_date DATE,
    status ENUM('enrolled','in_progress','completed','dropped') DEFAULT 'enrolled',
    score DECIMAL(5,2),
    certificate_path VARCHAR(500),
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (training_id) REFERENCES training_programs(id)
)");

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to manage training.');
    }
    if($_POST['action'] === 'add_program') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $trainer = trim((string) ($_POST['trainer'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $cost = round((float) ($_POST['cost'] ?? 0), 2);
        $status = (string) ($_POST['status'] ?? 'planned');
        if ($title === '' || mb_strlen($title) > 200 || !is_valid_date_string($startDate) || !is_valid_date_string($endDate) || $endDate < $startDate) {
            exit('Enter a valid title and start/end dates. The end date cannot be before the start date.');
        }
        if (!in_array($status, ['planned', 'ongoing', 'completed', 'cancelled'], true) || $cost < 0 || $cost > 99999999.99) {
            exit('Select a valid status and a non-negative cost.');
        }
        if (mb_strlen($description) > 5000 || mb_strlen($trainer) > 200 || mb_strlen($location) > 200) {
            exit('Description, trainer, or location is too long.');
        }
        $stmt = $pdo->prepare("INSERT INTO training_programs(title, description, trainer, start_date, end_date, location, cost, status) VALUES(?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $title,
            $description,
            $trainer,
            $startDate,
            $endDate,
            $location,
            $cost,
            $status
        ]);
        log_activity('create', 'hr', (int) $pdo->lastInsertId(), 'Training program created: ' . $title);
        echo "<script>alert('Training program added successfully!'); location.href='training.php';</script>";
    } elseif($_POST['action'] === 'enroll_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $trainingId = (int) ($_POST['training_id'] ?? 0);
        require_active_employee($employeeId);
        $programCheck = $pdo->prepare("SELECT id FROM training_programs WHERE id = ?");
        $programCheck->execute([$trainingId]);
        if (!$programCheck->fetchColumn()) {
            exit('Select a valid training program.');
        }
        $duplicate = $pdo->prepare("SELECT id FROM employee_training WHERE employee_id = ? AND training_id = ?");
        $duplicate->execute([$employeeId, $trainingId]);
        if ($duplicate->fetchColumn()) {
            exit('This employee is already enrolled in that program.');
        }
        $stmt = $pdo->prepare("INSERT INTO employee_training(employee_id, training_id, enrollment_date, status) VALUES(?,?,CURDATE(),'enrolled')");
        $stmt->execute([$employeeId, $trainingId]);
        log_activity('create', 'hr', (int) $pdo->lastInsertId(), 'Employee enrolled in training #' . $trainingId);
        echo "<script>alert('Employee enrolled successfully!'); location.href='training.php';</script>";
    }
}

// Get training programs
$programs = $pdo->query("SELECT * FROM training_programs ORDER BY start_date DESC")->fetchAll();

// Get employee training records
$employee_training = $pdo->query("SELECT et.*, e.name as employee_name, e.employee_code, tp.title as training_title FROM employee_training et JOIN employees e ON et.employee_id = e.id JOIN training_programs tp ON et.training_id = tp.id ORDER BY et.enrollment_date DESC LIMIT 20")->fetchAll();

// Get employees for enrollment
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>📚 Training & Development</h1>
    <button onclick="document.getElementById('programForm').style.display='flex'" class="btn btn-accent">+ New Training Program</button>
</div>

<!-- Training Program Form Modal -->
<div id="programForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:600px; width:90%; max-height:90vh; overflow-y:auto; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">New Training Program</h2>
            <button onclick="document.getElementById('programForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_program">
            <div style="margin-bottom:15px;">
                <label>Program Title:</label>
                <input type="text" name="title" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Description:</label>
                <textarea name="description" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <div style="margin-bottom:15px;">
                <label>Trainer:</label>
                <input type="text" name="trainer" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                <div>
                    <label>Start Date:</label>
                    <input type="date" name="start_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label>End Date:</label>
                    <input type="date" name="end_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
            </div>
            <div style="margin-bottom:15px;">
                <label>Location:</label>
                <input type="text" name="location" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Cost:</label>
                <input type="number" step="0.01" name="cost" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Status:</label>
                <select name="status" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="planned">Planned</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Add Program</button>
        </form>
    </div>
</div>

<!-- Enrollment Form Modal -->
<div id="enrollForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Enroll Employee</h2>
            <button onclick="document.getElementById('enrollForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="enroll_employee">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Training Program:</label>
                <select name="training_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($programs as $prog): ?>
                    <option value="<?= $prog['id'] ?>"><?= htmlspecialchars($prog['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Enroll Employee</button>
        </form>
    </div>
</div>

<!-- Training Programs -->
<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h3>Training Programs</h3>
        <button onclick="document.getElementById('enrollForm').style.display='flex'" class="btn btn-sm">Enroll Employee</button>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Trainer</th>
                    <th>Dates</th>
                    <th>Location</th>
                    <th>Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$programs): ?>
                <tr><td colspan="6">No training programs yet.</td></tr>
                <?php endif; ?>
                <?php foreach($programs as $prog): ?>
                <tr>
                    <td><?= htmlspecialchars($prog['title']) ?></td>
                    <td><?= htmlspecialchars($prog['trainer']) ?></td>
                    <td><?= date('d M Y', strtotime($prog['start_date'])) ?> - <?= date('d M Y', strtotime($prog['end_date'])) ?></td>
                    <td><?= htmlspecialchars($prog['location']) ?></td>
                    <td><?= $prog['cost'] ? e(currency_symbol()) . number_format($prog['cost'], 2) : '-' ?></td>
                    <td>
                        <span class="badge <?= $prog['status'] === 'completed' ? 'badge-success' : ($prog['status'] === 'ongoing' ? 'badge-info' : ($prog['status'] === 'cancelled' ? 'badge-danger' : 'badge-warning')) ?>">
                            <?= ucfirst($prog['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Employee Training Records -->
<div class="panel">
    <h3>Employee Training Records</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Training Program</th>
                    <th>Enrollment Date</th>
                    <th>Completion Date</th>
                    <th>Status</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$employee_training): ?>
                <tr><td colspan="6">No training records yet.</td></tr>
                <?php endif; ?>
                <?php foreach($employee_training as $record): ?>
                <tr>
                    <td><?= htmlspecialchars($record['employee_name']) ?> (<?= $record['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($record['training_title']) ?></td>
                    <td><?= date('d M Y', strtotime($record['enrollment_date'])) ?></td>
                    <td><?= $record['completion_date'] ? date('d M Y', strtotime($record['completion_date'])) : '-' ?></td>
                    <td>
                        <span class="badge <?= $record['status'] === 'completed' ? 'badge-success' : ($record['status'] === 'in_progress' ? 'badge-info' : 'badge-warning') ?>">
                            <?= ucfirst($record['status']) ?>
                        </span>
                    </td>
                    <td><?= $record['score'] ? number_format($record['score'], 1) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
