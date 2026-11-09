<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('crm', 'view');

$pageTitle = "Sales Pipeline";

$stages = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
$openStages = ['lead', 'qualified', 'proposal', 'negotiation'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $stage = in_array($_POST['stage'] ?? '', $stages, true) ? (string) $_POST['stage'] : 'lead';

    if ($action === 'add' && has_permission('crm', 'create')) {
        $name = trim((string) ($_POST['opportunity_name'] ?? ''));
        if ($name === '') {
            set_flash('error', 'Opportunity name is required.');
            header('Location: pipeline.php');
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO sales_pipeline(customer_id, opportunity_name, estimated_value, probability, stage, expected_close_date, assigned_to, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $_POST['customer_id'] ?: null,
            $name,
            (float) ($_POST['estimated_value'] ?? 0),
            min(100, max(0, (int) ($_POST['probability'] ?? 0))),
            in_array($stage, $openStages, true) ? $stage : 'lead',
            $_POST['expected_close_date'] ?: null,
            $_POST['assigned_to'] ?: null,
            trim($_POST['notes'] ?? ''),
            $_SESSION['user_id'],
        ]);
        log_activity('create', 'crm', (int) $pdo->lastInsertId(), 'Added pipeline opportunity');
        set_flash('success', 'Opportunity added.');
        header('Location: pipeline.php');
        exit;
    }

    if ($action === 'stage' && has_permission('crm', 'edit') && $id > 0) {
        $status = in_array($stage, ['won', 'lost'], true) ? 'closed' : 'active';
        $stmt = $pdo->prepare("UPDATE sales_pipeline SET stage=?, status=? WHERE id=?");
        $stmt->execute([$stage, $status, $id]);
        log_activity('update', 'crm', $id, "Stage changed to {$stage}");
        set_flash('success', 'Stage updated.');
        header('Location: pipeline.php');
        exit;
    }

    if ($action === 'update' && has_permission('crm', 'edit') && $id > 0) {
        $stmt = $pdo->prepare("UPDATE sales_pipeline SET opportunity_name=?, estimated_value=?, probability=?, expected_close_date=?, assigned_to=?, notes=? WHERE id=?");
        $name = trim((string) ($_POST['opportunity_name'] ?? ''));
        if ($name === '') {
            set_flash('error', 'Opportunity name is required.');
            header('Location: pipeline.php');
            exit;
        }
        $stmt->execute([
            $name,
            (float) ($_POST['estimated_value'] ?? 0),
            min(100, max(0, (int) ($_POST['probability'] ?? 0))),
            $_POST['expected_close_date'] ?: null,
            $_POST['assigned_to'] ?: null,
            trim($_POST['notes'] ?? ''),
            $id,
        ]);
        log_activity('update', 'crm', $id, 'Updated opportunity');
        set_flash('success', 'Opportunity updated.');
        header('Location: pipeline.php');
        exit;
    }

    if ($action === 'reopen' && has_permission('crm', 'edit') && $id > 0) {
        $reopenStage = in_array($stage, $openStages, true) ? $stage : 'lead';
        $stmt = $pdo->prepare("UPDATE sales_pipeline SET stage=?, status='active' WHERE id=?");
        $stmt->execute([$reopenStage, $id]);
        log_activity('update', 'crm', $id, 'Reopened opportunity');
        set_flash('success', 'Opportunity reopened.');
        header('Location: pipeline.php');
        exit;
    }

    if ($action === 'delete' && has_permission('crm', 'delete') && $id > 0) {
        $pdo->prepare("DELETE FROM sales_pipeline WHERE id=?")->execute([$id]);
        log_activity('delete', 'crm', $id, 'Deleted pipeline opportunity');
        set_flash('success', 'Opportunity deleted.');
        header('Location: pipeline.php');
        exit;
    }
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE status=1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, name FROM users WHERE status=1 ORDER BY name")->fetchAll();

$rows = $pdo->query("
    SELECT sp.*, c.name AS customer_name, u.name AS assigned_name
    FROM sales_pipeline sp
    LEFT JOIN customers c ON sp.customer_id = c.id
    LEFT JOIN users u ON sp.assigned_to = u.id
    WHERE sp.status = 'active'
    ORDER BY sp.expected_close_date IS NULL, sp.expected_close_date ASC
")->fetchAll();

$closed = $pdo->query("
    SELECT sp.*, c.name AS customer_name, u.name AS assigned_name
    FROM sales_pipeline sp
    LEFT JOIN customers c ON sp.customer_id = c.id
    LEFT JOIN users u ON sp.assigned_to = u.id
    WHERE sp.status = 'closed'
    ORDER BY sp.id DESC
    LIMIT 100
")->fetchAll();

$byStage = array_fill_keys($stages, []);
foreach ($rows as $row) {
    $stage = in_array($row['stage'], $stages, true) ? $row['stage'] : 'lead';
    $byStage[$stage][] = $row;
}

$totalValue = array_sum(array_column($rows, 'estimated_value'));
$weighted = 0;
foreach ($rows as $row) {
    $weighted += $row['estimated_value'] * ($row['probability'] / 100);
}
$overdueCount = 0;
$today = date('Y-m-d');
foreach ($rows as $row) {
    if ($row['expected_close_date'] && $row['expected_close_date'] < $today) {
        $overdueCount++;
    }
}
$wonValue = 0;
$lostCount = 0;
foreach ($closed as $row) {
    if ($row['stage'] === 'won') { $wonValue += (float) $row['estimated_value']; }
    if ($row['stage'] === 'lost') { $lostCount++; }
}

$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .pipeline-card.overdue { border-left: 4px solid #dc3545; }
    .pipeline-card details { margin-top: 8px; }
    .pipeline-card summary { cursor: pointer; font-size: .8rem; color: #4f46e5; }
    .pipeline-edit { display: grid; gap: 6px; margin-top: 8px; }
    .pipeline-edit input, .pipeline-edit select { width: 100%; padding: 6px; font-size: .8rem; border: 1px solid #cbd5e1; border-radius: 6px; }
    .closed-table td, .closed-table th { padding: 8px 10px; font-size: .85rem; }
    .pipeline-card[draggable="true"] { cursor: grab; }
    .pipeline-card.dragging { opacity: .45; }
    .pipeline-column.drag-over { border-color: #4f46e5; background: #eef2ff; }
    .pipeline-drag-handle { float: right; color: #94a3b8; font-size: .72rem; cursor: grab; user-select: none; }
</style>

<div class="page-header">
    <h1>Sales Pipeline</h1>
    <div class="header-actions">
        <a href="../customers/analytics.php" class="btn btn-secondary">CRM Analytics</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#9b59b6;">🎯</div>
        <div><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">Active opportunities</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#3498db;">💰</div>
        <div><div class="stat-value"><?= currency_symbol() . money($totalValue) ?></div><div class="stat-label">Pipeline value</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2f7d4f;">📊</div>
        <div><div class="stat-value"><?= currency_symbol() . money($weighted) ?></div><div class="stat-label">Weighted forecast</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?= $overdueCount ? '#dc3545' : '#64748b' ?>;">⏰</div>
        <div><div class="stat-value"><?= (int) $overdueCount ?></div><div class="stat-label">Past expected close</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#2ecc71;">🏆</div>
        <div><div class="stat-value"><?= currency_symbol() . money($wonValue) ?></div><div class="stat-label">Won value</div></div>
    </div>
</div>

<?php if (has_permission('crm', 'create')): ?>
<div class="panel">
    <h3>New opportunity</h3>
    <form method="post" class="filter-form">
        <input type="hidden" name="action" value="add">
        <input type="text" name="opportunity_name" placeholder="Opportunity name *" required>
        <select name="customer_id">
            <option value="">Customer (optional)</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="estimated_value" placeholder="Value *" required>
        <input type="number" name="probability" min="0" max="100" value="50" placeholder="Probability %">
        <select name="stage">
            <?php foreach ($openStages as $s): ?>
            <option value="<?= e($s) ?>"><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="expected_close_date">
        <select name="assigned_to">
            <option value="">Assign to</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="notes" placeholder="Notes">
        <button type="submit" class="btn btn-accent">Add</button>
    </form>
</div>
<?php endif; ?>

<?php if (has_permission('crm', 'edit')): ?>
<p style="color:#64748b;font-size:.85rem;margin:0 0 10px;">Tip: drag a card onto another column to change its stage.</p>
<?php endif; ?>
<div class="pipeline-board">
    <?php foreach ($openStages as $stage):
        $items = $byStage[$stage];
    ?>
    <div class="pipeline-column" data-stage="<?= e($stage) ?>">
        <h4><?= ucfirst($stage) ?> (<span class="stage-count"><?= count($items) ?></span>)</h4>
        <?php foreach ($items as $item): ?>
        <?php $overdue = $item['expected_close_date'] && $item['expected_close_date'] < $today; ?>
        <div class="pipeline-card<?= $overdue ? ' overdue' : '' ?>" data-id="<?= (int) $item['id'] ?>" data-stage="<?= e($stage) ?>"<?= has_permission('crm', 'edit') ? ' draggable="true"' : '' ?>>
            <?php if (has_permission('crm', 'edit')): ?><span class="pipeline-drag-handle" title="Drag to move">⠿</span><?php endif; ?>
            <strong><?= e($item['opportunity_name']) ?></strong>
            <div style="font-size:0.85rem;color:#64748b;"><?= e($item['customer_name'] ?? 'No customer') ?><?= $item['assigned_name'] ? ' · ' . e($item['assigned_name']) : '' ?></div>
            <div style="margin:8px 0;"><strong><?= currency_symbol() . money($item['estimated_value']) ?></strong> · <?= (int) $item['probability'] ?>%</div>
            <?php if ($item['expected_close_date']): ?>
            <div style="font-size:0.8rem;<?= $overdue ? 'color:#dc3545;font-weight:600;' : '' ?>">Close: <?= format_date($item['expected_close_date']) ?><?= $overdue ? ' (overdue)' : '' ?></div>
            <?php endif; ?>
            <?php if ($item['notes']): ?><div style="font-size:0.78rem;color:#94a3b8;margin-top:4px;"><?= e($item['notes']) ?></div><?php endif; ?>

            <?php if (has_permission('crm', 'edit')): ?>
            <form method="post" style="margin-top:8px;">
                <input type="hidden" name="action" value="stage">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <select name="stage" onchange="this.form.submit()" class="pipeline-stage-select" style="width:100%;padding:6px;font-size:0.8rem;">
                    <?php foreach ($stages as $s): ?>
                    <option value="<?= e($s) ?>" <?= $item['stage'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <details>
                <summary>Edit</summary>
                <form method="post" class="pipeline-edit">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                    <input type="text" name="opportunity_name" value="<?= e($item['opportunity_name']) ?>" required>
                    <input type="number" step="0.01" name="estimated_value" value="<?= e($item['estimated_value']) ?>">
                    <input type="number" min="0" max="100" name="probability" value="<?= (int) $item['probability'] ?>">
                    <input type="date" name="expected_close_date" value="<?= e($item['expected_close_date'] ?? '') ?>">
                    <select name="assigned_to">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $item['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="notes" value="<?= e($item['notes'] ?? '') ?>" placeholder="Notes">
                    <button type="submit" class="btn-sm">Save</button>
                </form>
            </details>
            <?php endif; ?>

            <?php if (has_permission('crm', 'delete')): ?>
            <form method="post" onsubmit="return confirm('Delete this opportunity?');" style="margin-top:6px;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <button type="submit" class="btn-sm" style="background:#dc3545;color:#fff;">Delete</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$items): ?><div class="pipeline-empty" style="color:#94a3b8;font-size:.82rem;padding:6px;">No deals</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="panel" style="margin-top:20px;">
    <h3>Closed deals <?php if ($closed): ?><span class="badge badge-muted"><?= count($closed) ?></span><?php endif; ?></h3>
    <?php if (!$closed): ?>
    <p style="color:#64748b;">No won or lost opportunities yet.</p>
    <?php else: ?>
    <div class="table-container">
        <table class="data-table closed-table">
            <thead>
                <tr>
                    <th>Opportunity</th>
                    <th>Customer</th>
                    <th>Value</th>
                    <th>Outcome</th>
                    <th>Assigned</th>
                    <?php if (has_permission('crm', 'edit') || has_permission('crm', 'delete')): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($closed as $item): ?>
                <tr>
                    <td><strong><?= e($item['opportunity_name']) ?></strong></td>
                    <td><?= e($item['customer_name'] ?? '—') ?></td>
                    <td><?= currency_symbol() . money($item['estimated_value']) ?></td>
                    <td><span class="badge badge-<?= $item['stage'] === 'won' ? 'success' : 'danger' ?>"><?= e(ucfirst($item['stage'])) ?></span></td>
                    <td><?= e($item['assigned_name'] ?? '—') ?></td>
                    <?php if (has_permission('crm', 'edit') || has_permission('crm', 'delete')): ?>
                    <td style="display:flex;gap:6px;align-items:center;">
                        <?php if ($item['stage'] === 'won' && $item['customer_id'] && has_permission('sales', 'create')): ?>
                        <a class="btn-sm" style="background:#28a745;color:#fff;" href="../sales/create.php?customer=<?= (int) $item['customer_id'] ?>">Create sale</a>
                        <?php endif; ?>
                        <?php if (has_permission('crm', 'edit')): ?>
                        <form method="post" style="display:flex;gap:6px;">
                            <input type="hidden" name="action" value="reopen">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <select name="stage" style="padding:4px;font-size:.8rem;">
                                <?php foreach ($openStages as $s): ?>
                                <option value="<?= e($s) ?>"><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-sm">Reopen</button>
                        </form>
                        <?php endif; ?>
                        <?php if (has_permission('crm', 'delete')): ?>
                        <form method="post" onsubmit="return confirm('Delete this opportunity?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="btn-sm" style="background:#dc3545;color:#fff;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (has_permission('crm', 'edit')): ?>
<script>
(function () {
    var board = document.querySelector('.pipeline-board');
    if (!board || !window.fetch) { return; }

    function placeCard(col, card) {
        var empty = col.querySelector('.pipeline-empty');
        if (empty) { col.insertBefore(card, empty); } else { col.appendChild(card); }
    }

    function refreshCounts() {
        board.querySelectorAll('.pipeline-column').forEach(function (col) {
            var n = col.querySelectorAll('.pipeline-card').length;
            var count = col.querySelector('.stage-count');
            if (count) { count.textContent = n; }
            var empty = col.querySelector('.pipeline-empty');
            if (n === 0 && !empty) {
                empty = document.createElement('div');
                empty.className = 'pipeline-empty';
                empty.style.cssText = 'color:#94a3b8;font-size:.82rem;padding:6px;';
                empty.textContent = 'No deals';
                col.appendChild(empty);
            } else if (n > 0 && empty) {
                empty.remove();
            }
        });
    }

    var dragId = null;

    board.querySelectorAll('.pipeline-card[draggable="true"]').forEach(function (card) {
        card.addEventListener('dragstart', function (e) {
            dragId = card.dataset.id;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('dragging');
            dragId = null;
        });
    });

    board.querySelectorAll('.pipeline-column').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', function () { col.classList.remove('drag-over'); });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('drag-over');

            var id = dragId || e.dataTransfer.getData('text/plain');
            if (!id) { return; }
            var card = board.querySelector('.pipeline-card[data-id="' + id + '"]');
            if (!card) { return; }
            var fromCol = card.closest('.pipeline-column');
            var toStage = col.dataset.stage;
            if (!fromCol || fromCol === col || !toStage) { return; }

            var fromStage = fromCol.dataset.stage;
            var select = card.querySelector('.pipeline-stage-select');

            // Optimistic move
            placeCard(col, card);
            card.dataset.stage = toStage;
            if (select) { select.value = toStage; }
            refreshCounts();

            var body = new URLSearchParams({ id: id, stage: toStage });
            fetch('pipeline_move.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.ok) { throw new Error(d.error || 'Move failed'); }
            }).catch(function (err) {
                // Revert on failure
                placeCard(fromCol, card);
                card.dataset.stage = fromStage;
                if (select) { select.value = fromStage; }
                refreshCounts();
                alert(err.message || 'Could not move the opportunity.');
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once "../partials/footer.php"; ?>
