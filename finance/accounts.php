<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'view');

$accountTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
$canCreate = has_permission('finance', 'create');
$canEdit = has_permission('finance', 'edit');
$showInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1';

$allAccounts = $pdo->query(
    "SELECT a.*, p.account_code AS parent_code, p.account_name AS parent_name
     FROM chart_of_accounts a
     LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
     ORDER BY a.account_code"
)->fetchAll();

$byId = [];
foreach ($allAccounts as $row) {
    $byId[(int) $row['id']] = $row;
}

$redirect = static function (bool $showInactive): string {
    return 'accounts.php' . ($showInactive ? '?inactive=1' : '');
};

$formError = null;
$formValues = ['id' => 0, 'account_code' => '', 'account_name' => '', 'account_type' => 'asset', 'parent_id' => ''];
$editingId = max(0, (int) ($_GET['edit'] ?? 0));
if ($editingId > 0 && isset($byId[$editingId])) {
    $account = $byId[$editingId];
    $formValues = [
        'id' => $editingId,
        'account_code' => $account['account_code'],
        'account_name' => $account['account_name'],
        'account_type' => $account['account_type'],
        'parent_id' => $account['parent_id'] === null ? '' : (string) $account['parent_id'],
    ];
}

/** True when $candidateId is $accountId itself or one of its descendants (prevents parent cycles). */
$createsCycle = static function (int $candidateId, int $accountId) use ($byId): bool {
    $seen = [];
    $cursor = $candidateId;
    while ($cursor > 0 && isset($byId[$cursor]) && !isset($seen[$cursor])) {
        if ($cursor === $accountId) {
            return true;
        }
        $seen[$cursor] = true;
        $parent = $byId[$cursor]['parent_id'];
        $cursor = $parent === null ? 0 : (int) $parent;
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedInactive = ($_POST['show_inactive'] ?? '') === '1';

    if ($action === 'save_account') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $isEdit = $accountId > 0;
        require_permission('finance', $isEdit ? 'edit' : 'create');

        $code = trim((string) ($_POST['account_code'] ?? ''));
        $name = trim((string) ($_POST['account_name'] ?? ''));
        $type = strtolower(trim((string) ($_POST['account_type'] ?? '')));
        $parentRaw = trim((string) ($_POST['parent_id'] ?? ''));
        $parentId = $parentRaw === '' ? null : (int) $parentRaw;

        $formValues = [
            'id' => $accountId,
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type !== '' ? $type : 'asset',
            'parent_id' => $parentRaw,
        ];

        if ($code === '') {
            $formError = 'Account code is required.';
        } elseif (strlen($code) > 40) {
            $formError = 'Account code must be 40 characters or fewer.';
        } elseif (!preg_match('/^[\x20-\x7E]+$/', $code)) {
            $formError = 'Account code may only contain printable characters.';
        } elseif ($name === '') {
            $formError = 'Account name is required.';
        } elseif (strlen($name) > 150) {
            $formError = 'Account name must be 150 characters or fewer.';
        } elseif (!in_array($type, $accountTypes, true)) {
            $formError = 'Account type must be one of: asset, liability, equity, revenue or expense.';
        } elseif ($parentId !== null && (!isset($byId[$parentId]) || (int) $byId[$parentId]['status'] !== 1)) {
            $formError = 'The selected parent account does not exist or is inactive.';
        } elseif ($parentId !== null && $isEdit && $createsCycle($parentId, $accountId)) {
            $formError = 'An account cannot be its own parent or sit underneath one of its own sub-accounts.';
        }

        if ($formError === null) {
            $dupe = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? AND id <> ?");
            $dupe->execute([$code, $accountId]);
            if ($dupe->fetchColumn()) {
                $formError = "Account code {$code} is already in use.";
            }
        }

        if ($formError === null && $isEdit) {
            $existing = $byId[$accountId] ?? null;
            if (!$existing) {
                $formError = 'That account no longer exists.';
            } else {
                $lineStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_lines WHERE account_id = ?");
                $lineStmt->execute([$accountId]);
                $lineCount = (int) $lineStmt->fetchColumn();
                $hasBalance = abs((float) $existing['balance']) > 0.004;
                $locked = $lineCount > 0 || $hasBalance;

                if ($code !== $existing['account_code'] && $locked) {
                    $formError = 'The account code cannot be changed once the account has journal activity or a non-zero balance.';
                } elseif ($type !== strtolower($existing['account_type']) && $locked) {
                    $formError = 'The account type cannot be changed once the account has journal activity or a non-zero balance.';
                } elseif ($type !== strtolower($existing['account_type'])) {
                    $childStmt = $pdo->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE parent_id = ?");
                    $childStmt->execute([$accountId]);
                    if ((int) $childStmt->fetchColumn() > 0) {
                        $formError = 'The account type cannot be changed while the account has sub-accounts.';
                    }
                }
            }
        }

        if ($formError === null) {
            try {
                if ($isEdit) {
                    $pdo->prepare("UPDATE chart_of_accounts SET account_code = ?, account_name = ?, account_type = ?, parent_id = ? WHERE id = ?")
                        ->execute([$code, $name, $type, $parentId, $accountId]);
                    log_activity('update', 'finance', $accountId, "Updated chart of accounts entry {$code} ({$name})");
                    set_flash('success', "Account {$code} updated.");
                } else {
                    $pdo->prepare("INSERT INTO chart_of_accounts(account_code, account_name, account_type, parent_id, balance, status) VALUES(?,?,?,?,0,1)")
                        ->execute([$code, $name, $type, $parentId]);
                    $newId = (int) $pdo->lastInsertId();
                    log_activity('create', 'finance', $newId, "Created chart of accounts entry {$code} ({$name})");
                    set_flash('success', "Account {$code} added to the chart of accounts.");
                }
                header('Location: ' . $redirect($postedInactive));
                exit;
            } catch (PDOException $ex) {
                $formError = 'Unable to save the account. Please check the code is unique and the parent is valid.';
            }
        }
    } elseif ($action === 'deactivate' || $action === 'activate') {
        require_permission('finance', 'edit');
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $account = $byId[$accountId] ?? null;

        if (!$account) {
            set_flash('error', 'That account no longer exists.');
        } elseif ($action === 'deactivate') {
            $lineStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_lines WHERE account_id = ?");
            $lineStmt->execute([$accountId]);
            $lineCount = (int) $lineStmt->fetchColumn();
            $childStmt = $pdo->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE parent_id = ? AND status = 1");
            $childStmt->execute([$accountId]);
            $childCount = (int) $childStmt->fetchColumn();
            $balance = (float) $account['balance'];

            if (abs($balance) > 0.004) {
                set_flash('error', "Account {$account['account_code']} cannot be deactivated while it holds a balance of " . money($balance) . '.');
            } elseif ($lineCount > 0) {
                set_flash('error', "Account {$account['account_code']} cannot be deactivated because it has {$lineCount} journal line(s) on record.");
            } elseif ($childCount > 0) {
                set_flash('error', "Account {$account['account_code']} cannot be deactivated while it still has {$childCount} active sub-account(s).");
            } else {
                $pdo->prepare("UPDATE chart_of_accounts SET status = 0 WHERE id = ?")->execute([$accountId]);
                log_activity('deactivate', 'finance', $accountId, "Deactivated chart of accounts entry {$account['account_code']}");
                set_flash('success', "Account {$account['account_code']} deactivated. It no longer appears in reports or pickers.");
            }
        } else {
            $pdo->prepare("UPDATE chart_of_accounts SET status = 1 WHERE id = ?")->execute([$accountId]);
            log_activity('activate', 'finance', $accountId, "Reactivated chart of accounts entry {$account['account_code']}");
            set_flash('success', "Account {$account['account_code']} reactivated.");
        }
        header('Location: ' . $redirect($postedInactive));
        exit;
    }
}

$listSql = "SELECT a.*, p.account_code AS parent_code, p.account_name AS parent_name
            FROM chart_of_accounts a
            LEFT JOIN chart_of_accounts p ON p.id = a.parent_id";
if (!$showInactive) {
    $listSql .= " WHERE a.status = 1";
}
$listSql .= " ORDER BY a.account_code";
$accounts = $pdo->query($listSql)->fetchAll();

$parentOptions = array_values(array_filter($allAccounts, static function (array $a): bool {
    return (int) $a['status'] === 1;
}));

$activeCount = count(array_filter($allAccounts, static fn(array $a): bool => (int) $a['status'] === 1));
$inactiveCount = count($allAccounts) - $activeCount;
$parentCount = count(array_filter($allAccounts, static fn(array $a): bool => $a['parent_id'] === null));
$childCount = count($allAccounts) - $parentCount;
$journalCount = (int) $pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart_of_accounts_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Code', 'Name', 'Type', 'Parent', 'Balance', 'Status']);
    foreach ($accounts as $row) {
        fputcsv($out, [
            $row['account_code'],
            $row['account_name'],
            $row['account_type'],
            $row['parent_code'] ? $row['parent_code'] . ' - ' . $row['parent_name'] : '',
            number_format((float) $row['balance'], 2, '.', ''),
            (int) $row['status'] === 1 ? 'Active' : 'Inactive',
        ]);
    }
    fclose($out);
    exit;
}

$flash = get_flash();
$pageTitle = "Chart of Accounts";
require_once "../partials/header.php";
$cur = currency_symbol();

$typeBadge = static function (string $type): string {
    $map = ['asset' => 'coa-asset', 'liability' => 'coa-liability', 'equity' => 'coa-equity', 'revenue' => 'coa-revenue', 'expense' => 'coa-expense'];
    return $map[strtolower($type)] ?? 'coa-other';
};
?>

<div class="coa-hero">
    <div>
        <span class="coa-eyebrow">FINANCE · LEDGER STRUCTURE</span>
        <h1>Chart of Accounts</h1>
        <p>Accounts that classify every transaction. Deactivated accounts disappear from reports and pickers.</p>
    </div>
    <div class="coa-hero-actions no-print">
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        <a class="btn btn-secondary" href="?export=csv&amp;inactive=<?= $showInactive ? '1' : '0' ?>">Export CSV</a>
        <a class="btn btn-secondary" href="?inactive=<?= $showInactive ? '0' : '1' ?>"><?= $showInactive ? 'Hide inactive' : 'Show inactive' ?></a>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="flash flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<?php if (!empty($formError)): ?>
<div class="flash flash-error"><?= e($formError) ?></div>
<?php endif; ?>

<div class="coa-summary">
    <div><span>Active accounts</span><strong><?= number_format($activeCount) ?></strong></div>
    <div><span>Deactivated</span><strong><?= number_format($inactiveCount) ?></strong></div>
    <div><span>Top-level accounts</span><strong><?= number_format($parentCount) ?></strong></div>
    <div><span>Sub-accounts</span><strong><?= number_format($childCount) ?></strong></div>
</div>

<?php if ($canCreate || $canEdit): ?>
<div class="panel coa-form-panel no-print">
    <div class="coa-form-head">
        <h3><?= $formValues['id'] > 0 ? 'Edit account' : 'Add account' ?></h3>
        <?php if ($formValues['id'] > 0): ?>
        <a class="btn btn-secondary" href="<?= e($redirect($showInactive)) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <?php if ($formValues['id'] > 0 && !$canEdit): ?>
    <p class="coa-note">You need the <strong>finance edit</strong> permission to change accounts.</p>
    <?php else: ?>
    <form method="post" class="coa-form">
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="account_id" value="<?= (int) $formValues['id'] ?>">
        <input type="hidden" name="show_inactive" value="<?= $showInactive ? '1' : '0' ?>">
        <div>
            <label for="account_code">Code</label>
            <input type="text" id="account_code" name="account_code" maxlength="40" required value="<?= e($formValues['account_code']) ?>" placeholder="e.g. 1140">
        </div>
        <div>
            <label for="account_name">Name</label>
            <input type="text" id="account_name" name="account_name" maxlength="150" required value="<?= e($formValues['account_name']) ?>" placeholder="e.g. Prepaid Rent">
        </div>
        <div>
            <label for="account_type">Type</label>
            <select id="account_type" name="account_type" required>
                <?php foreach ($accountTypes as $type): ?>
                <option value="<?= e($type) ?>" <?= strtolower($formValues['account_type']) === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="parent_id">Parent account</label>
            <select id="parent_id" name="parent_id">
                <option value="">— Top level —</option>
                <?php foreach ($parentOptions as $option): ?>
                <?php if ((int) $option['id'] === (int) $formValues['id']) { continue; } ?>
                <option value="<?= (int) $option['id'] ?>" <?= (string) $formValues['parent_id'] === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['account_code'] . ' - ' . $option['account_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-accent"><?= $formValues['id'] > 0 ? 'Save changes' : 'Add account' ?></button>
    </form>
    <p class="coa-note">Codes must be unique. Codes and types lock automatically once an account carries a balance or journal activity, so posted history can never be misclassified.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="flash">Your role can review the chart of accounts but not change it.</div>
<?php endif; ?>

<div class="table-container coa-table-wrap">
    <table class="data-table coa-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Account name</th>
                <th>Type</th>
                <th>Parent</th>
                <th class="coa-num">Balance</th>
                <th>Status</th>
                <th class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$accounts): ?>
            <tr><td colspan="7" class="coa-empty">No accounts to show.</td></tr>
        <?php endif; ?>
        <?php foreach ($accounts as $account): ?>
            <?php
            $isActive = (int) $account['status'] === 1;
            $balance = (float) $account['balance'];
            ?>
            <tr class="<?= $isActive ? '' : 'coa-inactive' ?>">
                <td><strong><?= e($account['account_code']) ?></strong></td>
                <td><?= e($account['account_name']) ?></td>
                <td><span class="coa-type <?= $typeBadge((string) $account['account_type']) ?>"><?= e(ucfirst($account['account_type'])) ?></span></td>
                <td><?= $account['parent_code'] ? e($account['parent_code'] . ' · ' . $account['parent_name']) : '—' ?></td>
                <td class="coa-num"><?= e($cur) . money($balance) ?></td>
                <td><span class="coa-status <?= $isActive ? 'is-active' : 'is-off' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></td>
                <td class="no-print coa-actions">
                    <?php if ($canEdit): ?>
                    <a class="btn btn-sm" href="?edit=<?= (int) $account['id'] ?>&amp;inactive=<?= $showInactive ? '1' : '0' ?>">Edit</a>
                    <form method="post" class="coa-inline">
                        <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                        <input type="hidden" name="show_inactive" value="<?= $showInactive ? '1' : '0' ?>">
                        <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-danger' : 'btn-secondary' ?>"><?= $isActive ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <?php else: ?>
                    <span class="coa-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="coa-footnote"><?= number_format($journalCount) ?> journal entries currently reference this chart. Deactivating an account never deletes history — it only removes the account from active use.</p>

<style>
.coa-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;padding:28px 32px;border-radius:18px;background:linear-gradient(120deg,#1f2933,#b8481b 65%,#d9742f);color:#fff;box-shadow:0 14px 34px rgba(24,59,86,.16)}
.coa-hero h1{margin:4px 0;color:#fff;font-size:2.05rem}
.coa-hero p{margin:0;color:rgba(255,255,255,.76)}
.coa-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#bce6df}
.coa-hero-actions{display:flex;gap:10px;flex-wrap:wrap}
.coa-hero-actions .btn{background:rgba(255,255,255,.15)!important;border:1px solid rgba(255,255,255,.25);color:#fff!important;box-shadow:none}
.coa-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 18px}
.coa-summary div{padding:15px 17px;background:#fff;border:1px solid #e3ddd4;border-radius:11px;box-shadow:0 5px 16px rgba(24,59,86,.05)}
.coa-summary span{display:block;font-size:.76rem;color:#6a6259}
.coa-summary strong{display:block;margin-top:5px;font-size:1.3rem;color:#1f2933}
.coa-form-panel{padding:20px;margin-bottom:18px}
.coa-form-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.coa-form-head h3{margin:0}
.coa-form{display:grid;grid-template-columns:1fr 2fr 1fr 2fr auto;gap:14px;align-items:end}
.coa-form label{display:block;margin:0 0 5px}
.coa-form input,.coa-form select{margin:0}
.coa-form button{min-height:38px;white-space:nowrap}
.coa-note{margin:12px 0 0;font-size:.82rem;color:#6a6259}
.coa-table-wrap{overflow-x:auto}
.coa-table{min-width:900px}
.coa-table th.coa-num,.coa-table td.coa-num{text-align:right;font-variant-numeric:tabular-nums}
.coa-table td.coa-actions{display:flex;gap:6px;align-items:center}
.coa-inline{display:inline;margin:0}
.coa-inactive{opacity:.62}
.coa-type{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.coa-asset{background:#e6f0fb;color:#1f5f9e}
.coa-liability{background:#fdeee9;color:#b3452f}
.coa-equity{background:#efe9fb;color:#5b3fa8}
.coa-revenue{background:#e6f4ec;color:#1d7a4f}
.coa-expense{background:#fff4e0;color:#a86a12}
.coa-other{background:#eef2f5;color:#4a5f6b}
.coa-status{font-size:.78rem;font-weight:800}
.coa-status.is-active{color:#1d7a4f}
.coa-status.is-off{color:#8b98a1}
.coa-muted{color:#b8c2c8}
.coa-empty{text-align:center;padding:34px!important;color:#6a6259}
.coa-footnote{margin:12px 0 0;font-size:.82rem;color:#6a6259}
@media(max-width:900px){.coa-form{grid-template-columns:1fr 1fr}.coa-form button{grid-column:1/-1}.coa-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.coa-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.coa-hero h1{font-size:1.75rem}.coa-form{grid-template-columns:1fr}.coa-summary{grid-template-columns:1fr 1fr;gap:8px}}
@media print {
    .no-print, .coa-hero-actions, .coa-form-panel, .coa-actions, .coa-footnote { display: none !important; }
    .coa-hero { background: #1f2933 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; }
    .coa-table { min-width: 0; }
    .table-container { overflow: visible; }
}
</style>

<?php require_once "../partials/footer.php"; ?>
