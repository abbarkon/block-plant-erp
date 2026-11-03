<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$pageTitle = "Shift Management";
require_once "../partials/header.php";

// Create shifts table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_duration INT DEFAULT 0,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create employee_shifts table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    effective_date DATE NOT NULL,
    end_date DATE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (shift_id) REFERENCES shifts(id)
)");

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!has_permission('hr', 'create')) {
        http_response_code(403);
        exit('You do not have permission to manage shifts.');
    }
    $timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if($_POST['action'] === 'add_shift') {
        $shiftName = trim((string) ($_POST['shift_name'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $breakDuration = (int) ($_POST['break_duration'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($shiftName === '' || mb_strlen($shiftName) > 100 || !preg_match($timePattern, $startTime) || !preg_match($timePattern, $endTime) || $breakDuration < 0 || $breakDuration > 480 || mb_strlen($description) > 2000) {
            exit('Enter a valid shift name, times, break duration (0-480 minutes), and description.');
        }
        $stmt = $pdo->prepare("INSERT INTO shifts(shift_name, start_time, end_time, break_duration, description, is_active) VALUES(?,?,?,?,?,?)");
        $stmt->execute([
            $shiftName,
            $startTime,
            $endTime,
            $breakDuration,
            $description !== '' ? $description : null,
            isset($_POST['is_active']) ? 1 : 0
        ]);
        log_activity('create', 'hr', (int) $pdo->lastInsertId(), 'Shift created: ' . $shiftName);
        echo "<script>alert('Shift added successfully!'); location.href='shifts.php';</script>";
    } elseif($_POST['action'] === 'assign_shift') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? '')) ?: null;
        require_active_employee($employeeId);
        if (!is_valid_date_string($effectiveDate) || ($endDate !== null && (!is_valid_date_string($endDate) || $endDate < $effectiveDate))) {
            exit('Enter a valid effective date and an end date on or after it.');
        }
        $shiftCheck = $pdo->prepare("SELECT id FROM shifts WHERE id = ? AND is_active = 1");
        $shiftCheck->execute([$shiftId]);
        if (!$shiftCheck->fetchColumn()) {
            exit('Select an active shift.');
        }
        $stmt = $pdo->prepare("INSERT INTO employee_shifts(employee_id, shift_id, effective_date, end_date, created_by) VALUES(?,?,?,?,?)");
        $stmt->execute([
            $employeeId,
            $shiftId,
            $effectiveDate,
            $endDate,
            $_SESSION['user_id']
        ]);
        log_activity('create', 'hr', (int) $pdo->lastInsertId(), 'Shift assigned to employee #' . $employeeId);
        echo "<script>alert('Shift assigned successfully!'); location.href='shifts.php';</script>";
    }
}

// Get shifts
$shifts = $pdo->query("SELECT * FROM shifts ORDER BY start_time")->fetchAll();

// Get employee shift assignments
$assignments = $pdo->query("SELECT es.*, e.name as employee_name, e.employee_code, s.shift_name, s.start_time, s.end_time FROM employee_shifts es JOIN employees e ON es.employee_id = e.id JOIN shifts s ON es.shift_id = s.id WHERE es.end_date IS NULL OR es.end_date >= CURDATE() ORDER BY es.effective_date DESC LIMIT 20")->fetchAll();

// Get employees for assignment
$employees = $pdo->query("SELECT id, name, employee_code FROM employees WHERE status=1 ORDER BY name")->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>⏰ Shift Management</h1>
    <button onclick="document.getElementById('shiftForm').style.display='flex'" class="btn btn-accent">+ New Shift</button>
</div>

<!-- Shift Form Modal -->
<div id="shiftForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">New Shift</h2>
            <button onclick="document.getElementById('shiftForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add_shift">
            <div style="margin-bottom:15px;">
                <label>Shift Name:</label>
                <input type="text" name="shift_name" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                <div>
                    <label>Start Time:</label>
                    <input type="time" name="start_time" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label>End Time:</label>
                    <input type="time" name="end_time" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
            </div>
            <div style="margin-bottom:15px;">
                <label>Break Duration (minutes):</label>
                <input type="number" name="break_duration" value="0" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:15px;">
                <label>Description:</label>
                <textarea name="description" rows="2" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;"></textarea>
            </div>
            <div style="margin-bottom:15px;">
                <label>
                    <input type="checkbox" name="is_active" checked> Active
                </label>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Add Shift</button>
        </form>
    </div>
</div>

<!-- Assignment Form Modal -->
<div id="assignForm" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:30px; border-radius:12px; max-width:500px; width:90%; position:relative; margin:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">Assign Shift to Employee</h2>
            <button onclick="document.getElementById('assignForm').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="assign_shift">
            <div style="margin-bottom:15px;">
                <label>Employee:</label>
                <select name="employee_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['employee_code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:15px;">
                <label>Shift:</label>
                <select name="shift_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <?php foreach($shifts as $shift): ?>
                    <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['shift_name']) ?> (<?= substr($shift['start_time'], 0, 5) ?> - <?= substr($shift['end_time'], 0, 5) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                <div>
                    <label>Effective Date:</label>
                    <input type="date" name="effective_date" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
                <div>
                    <label>End Date (optional):</label>
                    <input type="date" name="end_date" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Assign Shift</button>
        </form>
    </div>
</div>

<!-- Shifts -->
<div class="panel" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h3>Available Shifts</h3>
        <button onclick="document.getElementById('assignForm').style.display='flex'" class="btn btn-sm">Assign to Employee</button>
    </div>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Shift Name</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Break</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$shifts): ?>
                <tr><td colspan="5">No shifts defined yet.</td></tr>
                <?php endif; ?>
                <?php foreach($shifts as $shift): ?>
                <tr>
                    <td><?= htmlspecialchars($shift['shift_name']) ?></td>
                    <td><?= substr($shift['start_time'], 0, 5) ?></td>
                    <td><?= substr($shift['end_time'], 0, 5) ?></td>
                    <td><?= $shift['break_duration'] ?> min</td>
                    <td>
                        <span class="badge <?= $shift['is_active'] ? 'badge-success' : 'badge-muted' ?>">
                            <?= $shift['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Current Assignments -->
<div class="panel">
    <h3>Current Shift Assignments</h3>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Shift</th>
                    <th>Time</th>
                    <th>Effective From</th>
                    <th>End Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$assignments): ?>
                <tr><td colspan="5">No active shift assignments.</td></tr>
                <?php endif; ?>
                <?php foreach($assignments as $assignment): ?>
                <tr>
                    <td><?= htmlspecialchars($assignment['employee_name']) ?> (<?= $assignment['employee_code'] ?>)</td>
                    <td><?= htmlspecialchars($assignment['shift_name']) ?></td>
                    <td><?= substr($assignment['start_time'], 0, 5) ?> - <?= substr($assignment['end_time'], 0, 5) ?></td>
                    <td><?= date('d M Y', strtotime($assignment['effective_date'])) ?></td>
                    <td><?= $assignment['end_date'] ? date('d M Y', strtotime($assignment['end_date'])) : 'Ongoing' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../partials/footer.php"; ?>
