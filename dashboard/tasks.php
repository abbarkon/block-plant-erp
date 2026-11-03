<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('dashboard', 'view');

$pdo->exec("CREATE TABLE IF NOT EXISTS task_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    priority VARCHAR(20) DEFAULT 'medium',
    status VARCHAR(20) DEFAULT 'pending',
    due_date DATE NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");

$pageTitle = 'Tasks & Reminders';
$filter = in_array($_GET['status'] ?? 'all', ['all', 'pending', 'in_progress', 'done'], true) ? ($_GET['status'] ?? 'all') : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_task'])) {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = isset($_POST['status']) && in_array($_POST['status'], ['pending', 'in_progress', 'done'], true) ? $_POST['status'] : 'pending';
        
        if ($taskId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM task_reminders WHERE id = ?");
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            
            if ($task && (has_permission('dashboard', 'edit') || $task['assigned_to'] == $_SESSION['user_id'])) {
                if ($task['status'] !== $status) {
                    $upd = $pdo->prepare("UPDATE task_reminders SET status = ? WHERE id = ?");
                    $upd->execute([$status, $taskId]);
                    
                    // Notify creator (admin)
                    if ($task['created_by'] && $task['created_by'] != $_SESSION['user_id']) {
                        ensure_notifications_schema();
                        $msg = "Task '{$task['title']}' was updated to " . str_replace('_', ' ', $status) . " by " . ($_SESSION['user_name'] ?? 'a user');
                        $nStmt = $pdo->prepare("INSERT INTO user_notifications (user_id, message, link) VALUES (?, ?, ?)");
                        $nStmt->execute([$task['created_by'], mb_substr($msg, 0, 255), '/block_plant/dashboard/tasks.php']);
                    }
                }
                set_flash('success', 'Task status updated.');
            } else {
                set_flash('error', 'Permission denied.');
            }
        }
        header('Location: tasks.php' . ($filter !== 'all' ? '?status=' . urlencode($filter) : ''));
        exit;
    }

    if (!has_permission('dashboard', 'create')) {
        set_flash('error', 'You do not have permission to create tasks.');
        header('Location: tasks.php');
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $priority = in_array($_POST['priority'] ?? 'medium', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium';
    $status = isset($_POST['status']) && in_array($_POST['status'], ['pending', 'in_progress', 'done'], true) ? $_POST['status'] : 'pending';
    $dueDate = trim($_POST['due_date'] ?? '');
    if ($dueDate === '') {
        $dueDate = null;
    } elseif (!is_valid_date_string($dueDate)) {
        set_flash('error', 'Enter a valid due date.');
        header('Location: tasks.php');
        exit;
    }
    $assignedTo = !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null;
    $description = trim($_POST['description'] ?? '');

    if ($title === '' || mb_strlen($title) > 150) {
        set_flash('error', 'Task title is required and must be 150 characters or fewer.');
        header('Location: tasks.php');
        exit;
    }
    if ($assignedTo !== null) {
        $assignee = $pdo->prepare("SELECT id FROM users WHERE id = ? AND (status = 1 OR status IS NULL)");
        $assignee->execute([$assignedTo]);
        if (!$assignee->fetchColumn()) {
            set_flash('error', 'The selected assignee is not an active user.');
            header('Location: tasks.php');
            exit;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO task_reminders(title, description, priority, status, due_date, assigned_to, created_by) VALUES(?,?,?,?,?,?,?)");
    $stmt->execute([$title, $description, $priority, $status, $dueDate, $assignedTo, $_SESSION['user_id']]);
    $newTaskId = (int) $pdo->lastInsertId();
    if ($assignedTo !== null && $assignedTo !== (int) $_SESSION['user_id']) {
        ensure_notifications_schema();
        $nStmt = $pdo->prepare("INSERT INTO user_notifications (user_id, message, link) VALUES (?, ?, ?)");
        $nStmt->execute([$assignedTo, mb_substr('New task assigned: ' . $title, 0, 255), '/block_plant/dashboard/tasks.php']);
    }
    log_activity('create', 'dashboard', $newTaskId, 'Task created: ' . $title);
    set_flash('success', 'Task added successfully.');
    header('Location: tasks.php');
    exit;
}

$isAdmin = in_array(strtolower(trim((string) ($_SESSION['role'] ?? ''))), ['admin', 'administrator'], true);
$sql = "SELECT tr.*, u.name AS assigned_name, cu.name AS creator_name FROM task_reminders tr LEFT JOIN users u ON u.id = tr.assigned_to LEFT JOIN users cu ON cu.id = tr.created_by WHERE " . ($isAdmin ? "1=1" : "(tr.assigned_to = ? OR tr.created_by = ? OR tr.assigned_to IS NULL)");
$params = $isAdmin ? [] : [$_SESSION['user_id'], $_SESSION['user_id']];
if ($filter !== 'all') {
    $sql .= " AND tr.status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY tr.created_at DESC, FIELD(tr.priority, 'high','medium','low'), tr.due_date IS NULL, tr.due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$users = $pdo->query("SELECT id, name FROM users WHERE status = 1 OR status IS NULL ORDER BY name")->fetchAll();
$flash = get_flash();
require_once "../partials/header.php";
?>

<div class="page-header">
    <div>
        <h1>Tasks & Reminders</h1>
        <p style="color:#64748b; margin:6px 0 0;">Track production, maintenance, and follow-up actions</p>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn btn-secondary">Back to dashboard</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (has_permission('dashboard', 'create')): ?>
<div class="panel" style="margin-bottom:20px;">
    <h3>Add a task</h3>
    <form method="post" class="grid-2">
        <div>
            <label>Task title</label>
            <input type="text" name="title" maxlength="150" required>
        </div>
        <div>
            <label>Priority</label>
            <select name="priority">
                <option value="high">High</option>
                <option value="medium" selected>Medium</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div>
            <label>Due date</label>
            <input type="date" name="due_date">
        </div>
        <div>
            <label>Assigned to</label>
            <select name="assigned_to">
                <option value="">Unassigned</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="grid-column:1 / -1;">
            <label>Description</label>
            <textarea name="description" rows="3"></textarea>
        </div>
        <div style="grid-column:1 / -1;">
            <button type="submit" class="btn btn-accent">Save task</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
        <h3 style="margin:0;">Task list</h3>
        <form method="get" style="display:flex; gap:8px; align-items:center;">
            <select name="status">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="in_progress" <?= $filter === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                <option value="done" <?= $filter === 'done' ? 'selected' : '' ?>>Done</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Task</th>
                <th>Priority</th>
                <th>Due date</th>
                <th>Assigned</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$tasks): ?>
                <tr><td colspan="6">No tasks found.</td></tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
            <?php 
            $isOverdue = $task['status'] !== 'done' && $task['due_date'] && $task['due_date'] < date('Y-m-d');
            $isRecent = (time() - strtotime($task['created_at'])) <= 86400 && $task['status'] !== 'done'; // 24 hours and not completed
            $rowStyle = '';
            if ($isRecent) {
                $rowStyle = 'background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-left: 4px solid #0ea5e9; box-shadow: 0 2px 4px rgba(14, 165, 233, 0.1);';
            } elseif ($isOverdue) {
                $rowStyle = 'background:#fff1f0;';
            }
            ?>
            <tr style="<?= $rowStyle ?>">
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div>
                            <strong><?= e($task['title']) ?></strong>
                            <?php if (!empty($task['description'])): ?>
                            <div style="font-size:0.82rem;color:#64748b; margin-top:4px;">
                                <?= e($task['description']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ((time() - strtotime($task['created_at'])) <= 86400 && $task['status'] !== 'done'): // 24 hours and not completed ?>
                        <span class="badge" style="background: #0ea5e9; color: white; font-size: 0.7rem; padding: 2px 6px;">NEW</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <span class="badge badge-<?= $task['priority'] === 'high' ? 'danger' : ($task['priority'] === 'medium' ? 'warning' : 'muted') ?>">
                        <?= e(ucfirst($task['priority'])) ?>
                    </span>
                </td>
                <td><?= $task['due_date'] ? format_date($task['due_date']) : '—' ?></td>
                <td><?= e($task['assigned_name'] ?? 'Unassigned') ?></td>
                <td>
                    <?php
                    $statusClass = 'muted';
                    if ($task['status'] === 'done') $statusClass = 'success';
                    if ($task['status'] === 'in_progress') $statusClass = 'info';
                    if ($task['status'] === 'pending') $statusClass = 'warning';
                    ?>
                    <span class="badge badge-<?= $statusClass ?>"><?= e(str_replace('_', ' ', $task['status'])) ?></span>
                </td>
                <td>
                    <?php if (has_permission('dashboard', 'edit') || $task['assigned_to'] == $_SESSION['user_id']): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;">
                        <input type="hidden" name="update_task" value="1">
                        <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <select name="status" aria-label="Update task status" style="width:auto;margin:0;padding:6px 8px;">
                            <option value="pending" <?= $task['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                            <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>Done</option>
                        </select>
                        <button type="submit" class="btn btn-sm">Update</button>
                    </form>
                    <?php else: ?>
                    <span style="color:#94a3b8;">View only</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>

<style>
/* New Entry Highlighting */
.data-table tr[style*="linear-gradient"] {
    position: relative;
    animation: highlightFadeIn 0.5s ease-in-out;
}

@keyframes highlightFadeIn {
    0% { 
        transform: translateX(-5px); 
        opacity: 0.7; 
    }
    100% { 
        transform: translateX(0); 
        opacity: 1; 
    }
}

.badge[style*="0ea5e9"] {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
</style>
