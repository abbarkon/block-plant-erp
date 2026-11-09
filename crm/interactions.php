<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('crm', 'view');

$pageTitle = "Follow-ups & Interactions";
$customer_id = (int) ($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_interaction']) && has_permission('crm', 'create')) {
    $postedCustomerId = (int) ($_POST['customer_id'] ?? 0);
    $interactionType = $_POST['interaction_type'] ?? '';
    $interactionDate = trim($_POST['interaction_date'] ?? '');
    $nextActionDate = trim($_POST['next_action_date'] ?? '');
    $validTypes = ['call', 'visit', 'email', 'meeting', 'quote'];
    $parsedInteractionDate = DateTime::createFromFormat('Y-m-d', $interactionDate);
    $parsedNextActionDate = $nextActionDate === '' ? null : DateTime::createFromFormat('Y-m-d', $nextActionDate);

    if ($postedCustomerId <= 0 || !in_array($interactionType, $validTypes, true) || !$parsedInteractionDate || $parsedInteractionDate->format('Y-m-d') !== $interactionDate || ($nextActionDate !== '' && (!$parsedNextActionDate || $parsedNextActionDate->format('Y-m-d') !== $nextActionDate))) {
        set_flash('error', 'Select a customer and interaction type, and enter valid interaction and follow-up dates.');
        header('Location: interactions.php' . ($customer_id ? "?customer_id={$customer_id}" : ''));
        exit;
    }

    $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 1");
    $customerStmt->execute([$postedCustomerId]);
    if (!$customerStmt->fetchColumn()) {
        set_flash('error', 'Select an active customer.');
        header('Location: interactions.php' . ($customer_id ? "?customer_id={$customer_id}" : ''));
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO customer_interactions(customer_id, interaction_type, interaction_date, subject, description, next_action, next_action_date, status, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $postedCustomerId,
        $interactionType,
        $interactionDate,
        trim($_POST['subject'] ?? ''),
        trim($_POST['description'] ?? ''),
        trim($_POST['next_action'] ?? ''),
        $nextActionDate !== '' ? $nextActionDate : null,
        'pending',
        $_SESSION['user_id'],
    ]);
    log_activity('create', 'crm', (int) $pdo->lastInsertId(), 'Logged customer interaction');
    header('Location: interactions.php' . ($customer_id ? "?customer_id={$customer_id}" : ''));
    exit;
}

if (isset($_POST['complete_id']) && has_permission('crm', 'edit')) {
    $stmt = $pdo->prepare("UPDATE customer_interactions SET status='completed' WHERE id=? AND status='pending'");
    $stmt->execute([(int) $_POST['complete_id']]);
    set_flash('success', 'Follow-up marked complete.');
    header('Location: interactions.php');
    exit;
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE status=1 ORDER BY name")->fetchAll();

$sql = "SELECT ci.*, c.name AS customer_name, u.name AS created_by_name
        FROM customer_interactions ci
        JOIN customers c ON ci.customer_id = c.id
        LEFT JOIN users u ON ci.created_by = u.id
        WHERE 1=1";
$params = [];
if ($customer_id) {
    $sql .= " AND ci.customer_id = ?";
    $params[] = $customer_id;
}
$sql .= " ORDER BY ci.interaction_date DESC, ci.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$interactions = $stmt->fetchAll();

$pending = (int) $pdo->query("SELECT COUNT(*) FROM customer_interactions WHERE status='pending'")->fetchColumn();
$flash = get_flash();

require_once "../partials/header.php";
?>

<div class="page-header">
    <h1>Follow-ups &amp; Interactions</h1>
    <div class="header-actions">
        <span class="badge badge-warning"><?= $pending ?> pending</span>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'error' ? 'flash-error' : 'flash-success' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (has_permission('crm', 'create')): ?>
<div class="panel">
    <h3>Log interaction</h3>
    <form method="post">
        <input type="hidden" name="add_interaction" value="1">
        <div class="grid-2">
            <div>
                <label>Customer *</label>
                <select name="customer_id" required>
                    <option value="">Select customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $customer_id === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Type *</label>
                <select name="interaction_type" required>
                    <option value="call">Phone call</option>
                    <option value="visit">Site visit</option>
                    <option value="email">Email</option>
                    <option value="meeting">Meeting</option>
                    <option value="quote">Quotation follow-up</option>
                </select>
            </div>
            <div>
                <label>Date *</label>
                <input type="date" name="interaction_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Subject</label>
                <input type="text" name="subject">
            </div>
        </div>
        <label>Description</label>
        <textarea name="description" rows="3"></textarea>
        <div class="grid-2">
            <div>
                <label>Next action</label>
                <input type="text" name="next_action">
            </div>
            <div>
                <label>Follow-up date</label>
                <input type="date" name="next_action_date">
            </div>
        </div>
        <button type="submit" class="btn btn-accent" style="margin-top:12px;">Save interaction</button>
    </form>
</div>
<?php endif; ?>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Subject</th>
                <th>Next action</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($interactions as $i): ?>
            <tr>
                <td><?= format_date($i['interaction_date']) ?></td>
                <td><a href="../customers/view.php?id=<?= (int) $i['customer_id'] ?>"><?= e($i['customer_name']) ?></a></td>
                <td><?= e(ucfirst($i['interaction_type'])) ?></td>
                <td><?= e($i['subject']) ?></td>
                <td>
                    <?= e($i['next_action']) ?>
                    <?php if ($i['next_action_date']): ?>
                    <br><small><?= format_date($i['next_action_date']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $i['status'] === 'completed' ? 'success' : ($i['status'] === 'pending' ? 'warning' : 'muted') ?>">
                        <?= e(ucfirst($i['status'])) ?>
                    </span>
                </td>
                <td>
                    <?php if ($i['status'] === 'pending' && has_permission('crm', 'edit')): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="complete_id" value="<?= (int) $i['id'] ?>">
                        <button type="submit" class="btn-sm" style="background:#2f7d4f;border:0;cursor:pointer;">Done</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../partials/footer.php"; ?>
