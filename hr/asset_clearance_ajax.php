<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

$employeeId = (int) ($_GET['employee_id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'clear' ? 'clear' : 'view';
$canEdit = has_permission('hr', 'edit');
$conditions = asset_return_conditions();

if ($employeeId <= 0) {
    echo '<p>Invalid employee ID.</p>';
    exit;
}

$stmt = $pdo->prepare("SELECT name, employee_code FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) {
    echo '<p>Employee not found.</p>';
    exit;
}

if ($mode === 'clear') {
    $stmt = $pdo->prepare(
        "SELECT ea.*
         FROM employee_assets ea
         WHERE ea.employee_id = ? AND ea.status = 'issued'
         ORDER BY ea.asset_name, ea.id"
    );
    $stmt->execute([$employeeId]);
    $assets = $stmt->fetchAll();
    ?>
    <h3 style="margin:0 0 14px;"><?= e($employee['name']) ?> (<?= e($employee['employee_code']) ?>)</h3>

    <?php if (!$assets): ?>
        <div style="padding:12px 14px;border-left:4px solid #10b981;background:#ecfdf5;color:#065f46;">
            No assets are pending return for this employee.
        </div>
    <?php elseif (!$canEdit): ?>
        <div style="padding:12px 14px;border-left:4px solid #f59e0b;background:#fffbeb;color:#92400e;">
            You can view this employee's assets, but clearing them requires HR edit permission.
        </div>
    <?php else: ?>
    <form method="post" action="asset_clearance.php" onsubmit="return confirm('Clear the selected assets with the recorded conditions?');">
        <input type="hidden" name="clear_all_assets" value="1">
        <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">

        <div style="margin-bottom:15px;max-width:260px;">
            <label for="clearance_date">Clearance Date</label>
            <input type="date" id="clearance_date" name="clearance_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Serial Number</th>
                        <th>Issued Condition</th>
                        <th>Returned Condition *</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $asset): ?>
                    <tr>
                        <td>
                            <strong><?= e($asset['asset_name']) ?></strong>
                            <div style="color:#64748b;font-size:0.82rem;"><?= e($asset['asset_type']) ?></div>
                            <input type="hidden" name="asset_ids[]" value="<?= (int) $asset['id'] ?>">
                        </td>
                        <td><?= e($asset['serial_number'] ?: '-') ?></td>
                        <td><?= e($asset['condition_issued']) ?></td>
                        <td>
                            <select name="conditions[<?= (int) $asset['id'] ?>]" required style="min-width:150px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Select condition</option>
                                <?php foreach ($conditions as $condition): ?>
                                <option value="<?= e($condition) ?>"><?= e($condition) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="notes[<?= (int) $asset['id'] ?>]" maxlength="2000" placeholder="Optional notes" style="width:100%;min-width:160px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="background:#fef3c7;padding:12px 14px;border-radius:6px;margin:15px 0;border-left:4px solid #f59e0b;color:#92400e;">
            <strong>⚠️ Maintenance:</strong> Poor, Damaged, Needs Repair, Not Working or Broken assets automatically create a high-priority maintenance request due the next day.
        </div>

        <button type="submit" class="btn btn-primary" style="background:#10b981;">Clear Selected Assets</button>
    </form>
    <?php endif; ?>
    <?php
    exit;
}

// View all assets, including returned ones.
$stmt = $pdo->prepare(
    "SELECT ea.*, u.name AS issued_by_name
     FROM employee_assets ea
     LEFT JOIN users u ON ea.issued_by = u.id
     WHERE ea.employee_id = ?
     ORDER BY ea.issue_date DESC, ea.id DESC"
);
$stmt->execute([$employeeId]);
$assets = $stmt->fetchAll();
?>

<h3 style="margin:0 0 15px;"><?= e($employee['name']) ?> (<?= e($employee['employee_code']) ?>)</h3>

<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Asset Name</th>
                <th>Type</th>
                <th>Serial Number</th>
                <th>Issue Date</th>
                <th>Issued Condition</th>
                <th>Returned Condition</th>
                <th>Status</th>
                <th>Return Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$assets): ?>
            <tr><td colspan="9">No assets found for this employee.</td></tr>
            <?php endif; ?>
            <?php foreach ($assets as $asset): ?>
            <tr>
                <td><strong><?= e($asset['asset_name']) ?></strong></td>
                <td><?= e($asset['asset_type']) ?></td>
                <td><?= e($asset['serial_number'] ?: '-') ?></td>
                <td><?= e(format_date($asset['issue_date'])) ?></td>
                <td><?= e($asset['condition_issued'] ?: '-') ?></td>
                <td>
                    <?php if ($asset['condition_returned']): ?>
                    <strong style="color:<?= asset_return_condition_requires_maintenance($asset['condition_returned']) ? '#b91c1c' : '#047857' ?>;"><?= e($asset['condition_returned']) ?></strong>
                    <?php else: ?>
                    <span style="color:#94a3b8;">Not recorded</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="padding:5px 10px;border-radius:5px;background:<?= $asset['status'] === 'issued' ? '#d1fae5' : ($asset['status'] === 'returned' ? '#e0e7ff' : '#fee2e2') ?>;">
                        <?= e(ucfirst($asset['status'])) ?>
                    </span>
                </td>
                <td><?= $asset['return_date'] ? e(format_date($asset['return_date'])) : '-' ?></td>
                <td>
                    <a href="asset_handover_form.php?id=<?= (int) $asset['id'] ?>" target="_blank" rel="noopener" class="btn-sm" style="background:#6366f1;">📄 Form</a>
                    <?php if ($asset['status'] === 'issued'): ?>
                        <?php if ($canEdit): ?>
                        <form method="post" action="asset_clearance.php" onsubmit="return confirm('Clear this asset with the selected condition?');" style="display:grid;gap:6px;min-width:210px;margin-top:8px;">
                            <input type="hidden" name="clear_asset" value="1">
                            <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                            <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                            <select name="condition_returned" required style="padding:8px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">Returned condition</option>
                                <?php foreach ($conditions as $condition): ?>
                                <option value="<?= e($condition) ?>"><?= e($condition) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="notes" maxlength="2000" placeholder="Optional notes" style="padding:8px;border:1px solid #ddd;border-radius:6px;">
                            <button type="submit" class="btn-sm" style="background:#10b981;">Clear Asset</button>
                        </form>
                        <?php else: ?>
                        <div style="color:#64748b;font-size:0.85rem;margin-top:6px;">View only</div>
                        <?php endif; ?>
                    <?php else: ?>
                    <span style="display:inline-block;color:#10b981;margin-top:6px;">✓ Cleared</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
