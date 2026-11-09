<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('assets', 'create');

// Ensure required accounts exist for asset accounting
function ensure_asset_accounts(PDO $pdo): void {
    // Fetch parent IDs
    $parents = [];
    foreach (['1200', '6000', '2100'] as $code) {
        $s = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ? LIMIT 1");
        $s->execute([$code]);
        $parents[$code] = $s->fetchColumn() ?: null;
    }

    $requiredAccounts = [
        ['1210', 'Fixed Assets – Cost',                 'asset',   $parents['1200']],
        ['1211', 'Accumulated Depreciation',            'asset',   $parents['1200']],
        ['6300', 'Depreciation Expense',                'expense', $parents['6000']],
        ['6310', 'Loss on Asset Disposal',              'expense', $parents['6000']],
        ['4200', 'Gain on Asset Disposal',              'revenue', null],
        ['2150', 'Accounts Payable – Asset Purchase',   'liability', $parents['2100']],
    ];

    $ins = $pdo->prepare("INSERT IGNORE INTO chart_of_accounts
        (account_code, account_name, account_type, parent_id, balance, status)
        VALUES (?,?,?,?,0,1)");
    foreach ($requiredAccounts as [$code, $name, $type, $pid]) {
        try { $ins->execute([$code, $name, $type, $pid]); } catch (PDOException $e) {}
    }
}

ensure_asset_accounts($pdo);

$pageTitle = "Asset Journal Entry";
$flash     = get_flash();
$error     = null;
$success   = null;
if ($flash) {
    if ($flash['type'] === 'success') { $success = $flash['message']; }
    else                              { $error   = $flash['message']; }
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_asset_journal'])) {

    $journalType  = $_POST['journal_type']  ?? '';
    $assetId      = (int) ($_POST['asset_id'] ?? 0);
    $entryDate    = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $referenceNo  = trim($_POST['reference_no'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    if (!in_array($journalType, ['acquisition', 'depreciation', 'disposal', 'revaluation'], true)) {
        $error = 'Choose a valid asset journal type.';
    } elseif (!is_valid_date_string($entryDate)) {
        $error = 'Enter a valid journal entry date.';
    } elseif ($entryDate > date('Y-m-d')) {
        $error = 'Journal entry date cannot be in the future.';
    } elseif (mb_strlen($referenceNo) > 60) {
        $error = 'Reference number must be 60 characters or fewer.';
    } elseif (mb_strlen($notes) > 150) {
        $error = 'Notes must be 150 characters or fewer.';
    }

    // Fetch the asset
    $assetStmt = $pdo->prepare("SELECT a.*, ac.name as cat_name, ac.depreciation_rate
        FROM assets a
        JOIN asset_categories ac ON ac.id = a.category_id
        WHERE a.id = ? AND a.status = 1");
    $assetStmt->execute([$assetId]);
    $asset = $assetStmt->fetch();

    if (!empty($error)) {
        // Keep the validation message from the checks above.
    } elseif (!$asset) {
        $error = 'Active asset not found. Please select a valid asset.';
    } else {
        try {
            $pdo->beginTransaction();

            $entryNo = 'AJE-' . date('YmdHis') . '-' . $assetId;
            $userId  = $_SESSION['user_id'];

            // Helper: resolve account id by code
            $getAcct = function(string $code) use ($pdo): array {
                $s = $pdo->prepare("SELECT id, account_type FROM chart_of_accounts WHERE account_code = ? AND status = 1");
                $s->execute([$code]);
                $row = $s->fetch();
                if (!$row) throw new RuntimeException("Account {$code} not found.");
                return $row;
            };

            // Helper: update account balance
            $updateBal = function(int $accountId, string $accountType, float $debit, float $credit) use ($pdo): void {
                $normalDebit = in_array(strtolower($accountType), ['asset','expense'], true);
                $delta = $normalDebit ? $debit - $credit : $credit - $debit;
                $s = $pdo->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE id = ?");
                $s->execute([$delta, $accountId]);
            };

            $lines       = [];
            $totalDebit  = 0.0;
            $totalCredit = 0.0;
            $description = '';

            // ── ACQUISITION ───────────────────────────────────────────────────
            if ($journalType === 'acquisition') {
                $purchaseCost   = (float) ($_POST['purchase_cost']    ?? $asset['purchase_cost']);
                $paymentMethod  = $_POST['payment_method'] ?? 'cash';
                if ($purchaseCost <= 0 || $purchaseCost > 999999999.99) {
                    throw new InvalidArgumentException('Purchase cost must be greater than zero.');
                }
                if (!in_array($paymentMethod, ['cash', 'credit'], true)) {
                    throw new InvalidArgumentException('Choose a valid acquisition payment method.');
                }
                $creditAcctCode = ($paymentMethod === 'credit') ? '2150' : '1110';

                $debitAcct  = $getAcct('1210');
                $creditAcct = $getAcct($creditAcctCode);

                $description = "Asset acquisition: {$asset['name']} ({$asset['asset_code']})";
                $lines = [
                    [$debitAcct['id'],  $purchaseCost, 0,             "Fixed asset cost – {$asset['name']}"],
                    [$creditAcct['id'], 0,             $purchaseCost, ($paymentMethod === 'credit' ? 'Accounts payable – asset purchase' : 'Cash payment for asset')],
                ];
                $totalDebit  = $purchaseCost;
                $totalCredit = $purchaseCost;

                // Update asset account types for balance calculation
                $lines[0][] = $debitAcct['account_type'];
                $lines[1][] = $creditAcct['account_type'];

            // ── DEPRECIATION ─────────────────────────────────────────────────
            } elseif ($journalType === 'depreciation') {
                $depAmount = (float) ($_POST['depreciation_amount'] ?? 0);
                if ($depAmount <= 0) throw new InvalidArgumentException('Depreciation amount must be greater than zero.');
                $remaining = max(0, (float) $asset['current_value'] - (float) $asset['salvage_value']);
                if ($depAmount > $remaining + 0.009) {
                    throw new InvalidArgumentException('Depreciation cannot exceed the remaining depreciable value of ' . number_format($remaining, 2) . '.');
                }

                $monthStart = date('Y-m-01', strtotime($entryDate));
                $monthEnd = date('Y-m-t', strtotime($entryDate));
                $dup = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_no LIKE 'AJE-%' AND entry_date BETWEEN ? AND ? AND description LIKE 'Depreciation%' AND description LIKE ?");
                $dup->execute([$monthStart, $monthEnd, '%' . $asset['asset_code'] . '%']);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new InvalidArgumentException('A depreciation entry already exists for this asset in ' . date('F Y', strtotime($entryDate)) . '.');
                }

                $depExpAcct  = $getAcct('6300');
                $accumDepAcct = $getAcct('1211');

                $description = "Depreciation – {$asset['name']} ({$asset['asset_code']})";
                $lines = [
                    [$depExpAcct['id'],   $depAmount, 0,          "Depreciation expense – {$asset['name']}", $depExpAcct['account_type']],
                    [$accumDepAcct['id'], 0,          $depAmount, "Accumulated depreciation – {$asset['name']}", $accumDepAcct['account_type']],
                ];
                $totalDebit  = $depAmount;
                $totalCredit = $depAmount;

                // Update asset current_value
                $newVal = max(0, (float)$asset['current_value'] - $depAmount);
                $pdo->prepare("UPDATE assets SET current_value = ? WHERE id = ?")->execute([$newVal, $assetId]);

            // ── DISPOSAL ──────────────────────────────────────────────────────
            } elseif ($journalType === 'disposal') {
                $saleProceeds    = (float) ($_POST['sale_proceeds']    ?? 0);
                $accumulatedDep  = (float) ($_POST['accumulated_dep']  ?? 0);
                $purchaseCost    = (float) $asset['purchase_cost'];

                if ($saleProceeds < 0 || $saleProceeds > 999999999.99) {
                    throw new InvalidArgumentException('Sale proceeds must be zero or a positive amount.');
                }
                if ($accumulatedDep < 0) {
                    throw new InvalidArgumentException('Accumulated depreciation cannot be negative.');
                }
                $accumulatedDep = min($accumulatedDep, $purchaseCost);
                $bookValue = max(0, $purchaseCost - $accumulatedDep);
                $gainLoss = $saleProceeds - $bookValue;

                $cashAcct     = $getAcct('1110');
                $accumDepAcct = $getAcct('1211');
                $assetAcct    = $getAcct('1210');

                $description = "Asset disposal: {$asset['name']} ({$asset['asset_code']})";

                // Dr. Cash (proceeds), Dr. Accum Dep, Cr. Fixed Asset Cost
                // Cr. Gain OR Dr. Loss
                $lines = [];

                if ($saleProceeds > 0) {
                    $lines[] = [$cashAcct['id'], $saleProceeds, 0, "Cash proceeds from disposal", $cashAcct['account_type']];
                }
                if ($accumulatedDep > 0) {
                    $lines[] = [$accumDepAcct['id'], $accumulatedDep, 0, "Remove accumulated depreciation", $accumDepAcct['account_type']];
                }
                $lines[] = [$assetAcct['id'], 0, $purchaseCost, "Remove asset at cost", $assetAcct['account_type']];

                if ($gainLoss > 0) {
                    $gainAcct = $getAcct('4200');
                    $lines[]  = [$gainAcct['id'], 0, $gainLoss, "Gain on disposal of {$asset['name']}", $gainAcct['account_type']];
                } elseif ($gainLoss < 0) {
                    $lossAcct = $getAcct('6310');
                    $lines[]  = [$lossAcct['id'], abs($gainLoss), 0, "Loss on disposal of {$asset['name']}", $lossAcct['account_type']];
                }

                foreach ($lines as $l) { $totalDebit += $l[1]; $totalCredit += $l[2]; }

                // Mark asset as disposed
                $pdo->prepare("UPDATE assets SET status = 0, notes = CONCAT(IFNULL(notes,''), '\n[Disposed on {$entryDate}]') WHERE id = ?")->execute([$assetId]);

            // ── REVALUATION ───────────────────────────────────────────────────
            } elseif ($journalType === 'revaluation') {
                $newValue     = (float) ($_POST['new_value'] ?? 0);
                $currentValue = (float) $asset['current_value'];
                $diff         = $newValue - $currentValue;

                if ($newValue < 0 || $newValue > 999999999.99) {
                    throw new InvalidArgumentException('New fair value must be zero or a positive amount.');
                }
                if (abs($diff) < 0.005) throw new InvalidArgumentException('New value is the same as current value. No entry needed.');

                $assetAcct = $getAcct('1210');

                // Revaluation surplus goes to equity or P&L depending on direction
                $otherCode  = ($diff > 0) ? '3100' : '6310'; // Capital or Loss
                $otherAcct  = $getAcct($otherCode);

                $description = "Asset revaluation: {$asset['name']} – new value " . number_format($newValue, 2);

                if ($diff > 0) {
                    // Increase in value: Dr Asset / Cr Equity (Revaluation Surplus)
                    $lines = [
                        [$assetAcct['id'],  abs($diff), 0,         "Upward revaluation – {$asset['name']}", $assetAcct['account_type']],
                        [$otherAcct['id'],  0,          abs($diff),"Revaluation surplus – {$asset['name']}", $otherAcct['account_type']],
                    ];
                } else {
                    // Decrease in value: Dr Loss / Cr Asset
                    $lines = [
                        [$otherAcct['id'],  abs($diff), 0,         "Revaluation loss – {$asset['name']}", $otherAcct['account_type']],
                        [$assetAcct['id'],  0,          abs($diff),"Downward revaluation – {$asset['name']}", $assetAcct['account_type']],
                    ];
                }

                $totalDebit  = abs($diff);
                $totalCredit = abs($diff);

                // Update asset current value
                $pdo->prepare("UPDATE assets SET current_value = ? WHERE id = ?")->execute([$newValue, $assetId]);

            } else {
                throw new InvalidArgumentException('Invalid journal type selected.');
            }

            if (abs($totalDebit - $totalCredit) > 0.009) {
                throw new InvalidArgumentException('Debits and credits do not balance. Please check the amounts.');
            }

            // Insert journal entry header
            $headerStmt = $pdo->prepare("INSERT INTO journal_entries
                (entry_no, entry_date, description, reference_no, total_debit, total_credit, status, created_by, posted_by, posted_at)
                VALUES (?,?,?,?,?,?,'posted',?,?,NOW())");
            $headerDescription = mb_substr($description . ($notes ? ' – ' . $notes : ''), 0, 255);
            $headerStmt->execute([$entryNo, $entryDate, $headerDescription, $referenceNo ?: null, $totalDebit, $totalCredit, $userId, $userId]);
            $entryId = (int) $pdo->lastInsertId();

            // Insert lines and update balances
            $lineStmt = $pdo->prepare("INSERT INTO journal_entry_lines (entry_id, account_id, debit, credit, description) VALUES (?,?,?,?,?)");
            $balStmt  = $pdo->prepare("UPDATE chart_of_accounts SET balance = balance + ? WHERE id = ?");
            foreach ($lines as $line) {
                [$accountId, $debit, $credit, $lineDesc, $accountType] = $line;
                $lineStmt->execute([$entryId, $accountId, $debit, $credit, $lineDesc]);
                $normalDebit = in_array(strtolower($accountType), ['asset', 'expense'], true);
                $delta = $normalDebit ? $debit - $credit : $credit - $debit;
                $balStmt->execute([$delta, $accountId]);
            }

            // Log activity
            log_activity('post_journal', 'assets', $assetId, "Asset journal ({$journalType}): {$entryNo}");

            $pdo->commit();
            set_flash('success', "Journal entry {$entryNo} posted successfully. <a href='/block_plant/finance/journal_view.php?id={$entryId}'>View entry ›</a>");
            header("Location: journal.php?asset_id={$assetId}");
            exit;

        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $ex->getMessage();
        }
    }
}

// Load data
$selectedAssetId = (int) ($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);
$initialType = $_POST['journal_type'] ?? $_GET['type'] ?? 'acquisition';
if (!in_array($initialType, ['acquisition', 'depreciation', 'disposal', 'revaluation'], true)) {
    $initialType = 'acquisition';
}
$assets = $pdo->query("SELECT a.*, ac.name as cat_name, ac.depreciation_rate
    FROM assets a
    JOIN asset_categories ac ON ac.id = a.category_id
    WHERE a.status = 1
    ORDER BY a.name")->fetchAll();

$selectedAsset = null;
foreach ($assets as $a) {
    if ((int) $a['id'] === $selectedAssetId) { $selectedAsset = $a; break; }
}

// Recent asset journal entries (linked via description or reference)
$recentEntries = $pdo->query("SELECT je.*, u.name AS posted_by_name
    FROM journal_entries je
    LEFT JOIN users u ON u.id = je.posted_by
    WHERE je.entry_no LIKE 'AJE-%'
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT 20")->fetchAll();

require_once "../partials/header.php";
?>

<style>
/* ── Page layout ── */
.asset-journal-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 22px;
    align-items: start;
}
@media (max-width: 900px) {
    .asset-journal-grid { grid-template-columns: 1fr; }
}

/* ── Cards ── */
.aj-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
    padding: 24px 26px;
    margin-bottom: 20px;
}
.aj-card h3 {
    margin: 0 0 18px;
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Journal-type tabs ── */
.journal-type-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.journal-type-tabs label {
    cursor: pointer;
    padding: 8px 16px;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    font-weight: 600;
    font-size: .85rem;
    transition: all .2s;
    color: #475569;
}
.journal-type-tabs input[type=radio]:checked + label {
    border-color: var(--bp-primary, #b8481b);
    background: var(--bp-primary, #b8481b);
    color: #fff;
}
.journal-type-tabs input[type=radio] { display: none; }

/* ── Section panels ── */
.jtype-section { display: none; }
.jtype-section.active { display: block; }

/* ── Entry preview ── */
.entry-preview {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin-top: 16px;
}
.entry-preview table {
    width: 100%;
    border-collapse: collapse;
    font-size: .88rem;
}
.entry-preview th {
    background: #f1f5f9;
    padding: 6px 10px;
    text-align: left;
    color: #64748b;
    font-weight: 600;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.entry-preview td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; }
.entry-preview tr:last-child td { border-bottom: none; }
.dr { color: #1d4ed8; font-weight: 600; }
.cr { color: #047857; font-weight: 600; padding-left: 28px; }
.total-row td { background: #f1f5f9; font-weight: 700; }

/* ── Asset info card ── */
.asset-info-box {
    background: linear-gradient(135deg, #eef2ff, #e6fffa);
    border: 1px solid #c7d2fe;
    border-radius: 10px;
    padding: 14px 16px;
    font-size: .88rem;
    margin-bottom: 16px;
}
.asset-info-box strong { display: block; margin-bottom: 10px; font-size: .95rem; color: #1e293b; }
.asset-info-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    border-bottom: 1px dashed #c7d2fe;
    color: #374151;
}
.asset-info-row:last-child { border-bottom: none; }
.asset-info-row span { font-weight: 600; }

/* ── History table ── */
.history-table { font-size: .84rem; }
.history-table th { background: #f8fafc; color: #64748b; font-size: .78rem; text-transform: uppercase; }
.history-table tr:hover td { background: #f0f9ff; }
.badge-posted { background: #dcfce7; color: #166534; padding: 2px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; }
.badge-draft  { background: #fef9c3; color: #854d0e; padding: 2px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; }

/* ── Input styling ── */
.form-row { margin-bottom: 14px; }
.form-row label { display: block; font-weight: 600; font-size: .85rem; color: #374151; margin-bottom: 5px; }
.form-row input, .form-row select, .form-row textarea {
    width: 100%; padding: 9px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px; font-size: .9rem;
    background: #fff; color: #1e293b;
    transition: border-color .2s;
}
.form-row input:focus, .form-row select:focus { border-color: var(--bp-primary, #b8481b); outline: none; box-shadow: 0 0 0 3px rgba(57,73,171,.1); }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width: 600px){ .grid-2 { grid-template-columns: 1fr; } }

/* ── Hint box ── */
.hint-box {
    background: #fffbeb;
    border-left: 4px solid #f59e0b;
    border-radius: 0 8px 8px 0;
    padding: 10px 14px;
    font-size: .83rem;
    color: #78350f;
    margin-bottom: 16px;
    line-height: 1.5;
}
</style>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
        <h1>🏗️ Asset Journal Entry</h1>
        <p style="color:#64748b;margin:4px 0 0;">Post double-entry accounting for fixed asset transactions</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="index.php" class="btn btn-secondary">← Assets</a>
        <a href="/block_plant/finance/journal.php" class="btn btn-secondary">Journal List</a>
    </div>
</div>

<?php if ($success): ?>
<div class="flash flash-success" style="margin-bottom:18px;padding:14px 18px;background:#dcfce7;border-left:4px solid #16a34a;border-radius:8px;color:#14532d;">
    ✅ <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash flash-error" style="margin-bottom:18px;padding:14px 18px;background:#fee2e2;border-left:4px solid #dc2626;border-radius:8px;color:#7f1d1d;">
    ❌ <?= e($error) ?>
</div>
<?php endif; ?>

<div class="asset-journal-grid">

    <!-- ── LEFT: Form ──────────────────────────────────────────────────────── -->
    <div>
        <form method="post" id="assetJournalForm">
            <input type="hidden" name="post_asset_journal" value="1">

            <!-- Asset selector -->
            <div class="aj-card">
                <h3>🔍 Select Asset</h3>
                <div class="form-row">
                    <label>Fixed Asset *</label>
                    <select name="asset_id" id="assetSelect" required onchange="this.form.submit()">
                        <option value="">— Choose an asset —</option>
                        <?php foreach ($assets as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"
                            data-cost="<?= (float)$a['purchase_cost'] ?>"
                            data-value="<?= (float)$a['current_value'] ?>"
                            data-life="<?= (int)$a['useful_life'] ?>"
                            data-rate="<?= (float)$a['depreciation_rate'] ?>"
                            data-salvage="<?= (float)$a['salvage_value'] ?>"
                            <?= $a['id'] === $selectedAssetId ? 'selected' : '' ?>>
                            <?= e($a['asset_code'] . ' – ' . $a['name'] . ' (' . $a['cat_name'] . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($selectedAsset): ?>
                <div class="asset-info-box">
                    <strong>📋 <?= e($selectedAsset['name']) ?></strong>
                    <div class="asset-info-row">Purchase Cost <span><?= number_format((float)$selectedAsset['purchase_cost'], 2) ?> ETB</span></div>
                    <div class="asset-info-row">Current Value <span><?= number_format((float)$selectedAsset['current_value'], 2) ?> ETB</span></div>
                    <div class="asset-info-row">Accum. Depr. <span><?= number_format(max(0, (float)$selectedAsset['purchase_cost'] - (float)$selectedAsset['current_value']), 2) ?> ETB</span></div>
                    <div class="asset-info-row">Depr. Rate <span><?= $selectedAsset['depreciation_rate'] ?>%/yr</span></div>
                    <div class="asset-info-row">Useful Life <span><?= (int)$selectedAsset['useful_life'] ?> yrs</span></div>
                    <div class="asset-info-row">Category <span><?= e($selectedAsset['cat_name']) ?></span></div>
                    <div class="asset-info-row">Condition <span><?= ucfirst(e($selectedAsset['condition_status'])) ?></span></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Journal type selection -->
            <?php if ($selectedAsset): ?>
            <div class="aj-card">
                <h3>📝 Transaction Type</h3>
                <div class="journal-type-tabs">
                    <input type="radio" name="journal_type" id="jt_acquisition" value="acquisition" <?= $initialType === 'acquisition' ? 'checked' : '' ?>>
                    <label for="jt_acquisition">🏠 Acquisition</label>

                    <input type="radio" name="journal_type" id="jt_depreciation" value="depreciation" <?= $initialType === 'depreciation' ? 'checked' : '' ?>>
                    <label for="jt_depreciation">📉 Depreciation</label>

                    <input type="radio" name="journal_type" id="jt_disposal" value="disposal" <?= $initialType === 'disposal' ? 'checked' : '' ?>>
                    <label for="jt_disposal">🗑️ Disposal</label>

                    <input type="radio" name="journal_type" id="jt_revaluation" value="revaluation" <?= $initialType === 'revaluation' ? 'checked' : '' ?>>
                    <label for="jt_revaluation">⚖️ Revaluation</label>
                </div>

                <!-- Common fields -->
                <div class="grid-2">
                    <div class="form-row">
                        <label>Entry Date *</label>
                        <input type="date" name="entry_date" value="<?= e($_POST['entry_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="form-row">
                        <label>Reference No.</label>
                        <input type="text" name="reference_no" value="<?= e($_POST['reference_no'] ?? '') ?>" placeholder="Invoice / voucher ref">
                    </div>
                </div>
                <div class="form-row">
                    <label>Notes / Narration</label>
                    <textarea name="notes" rows="2" placeholder="Optional notes for this entry"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

                <!-- ── ACQUISITION fields ── -->
                <div id="section_acquisition" class="jtype-section">
                    <div class="hint-box">
                        <strong>Acquisition Entry:</strong><br>
                        Dr. Fixed Assets – Cost &nbsp;|&nbsp; Cr. Cash <em>or</em> Accounts Payable
                    </div>
                    <div class="grid-2">
                        <div class="form-row">
                            <label>Purchase Cost (ETB) *</label>
                            <input type="number" step="0.01" min="0.01" name="purchase_cost"
                                value="<?= e($_POST['purchase_cost'] ?? number_format((float)$selectedAsset['purchase_cost'], 2, '.', '')) ?>"
                                id="acq_cost" oninput="updatePreview()">
                        </div>
                        <div class="form-row">
                            <label>Payment Method</label>
                            <select name="payment_method" id="acq_method" onchange="updatePreview()">
                                <option value="cash" <?= ($_POST['payment_method'] ?? 'cash') === 'cash' ? 'selected' : '' ?>>Cash (1110)</option>
                                <option value="credit" <?= ($_POST['payment_method'] ?? '') === 'credit' ? 'selected' : '' ?>>On Credit / Payable (2150)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── DEPRECIATION fields ── -->
                <div id="section_depreciation" class="jtype-section">
                    <div class="hint-box">
                        <strong>Depreciation Entry:</strong><br>
                        Dr. Depreciation Expense &nbsp;|&nbsp; Cr. Accumulated Depreciation
                    </div>
                    <?php
                        $annualDep  = (float)$selectedAsset['purchase_cost'] * ((float)$selectedAsset['depreciation_rate'] / 100);
                        $monthlyDep = $annualDep / 12;
                    ?>
                    <p style="font-size:.83rem;color:#64748b;margin:0 0 12px;">
                        Annual: <strong><?= number_format($annualDep, 2) ?> ETB</strong> &nbsp;|&nbsp;
                        Monthly: <strong><?= number_format($monthlyDep, 2) ?> ETB</strong>
                        (<?= $selectedAsset['depreciation_rate'] ?>% of <?= number_format((float)$selectedAsset['purchase_cost'], 2) ?>)
                    </p>
                    <div class="grid-2">
                        <div class="form-row">
                            <label>Depreciation Amount (ETB) *</label>
                            <input type="number" step="0.01" min="0.01" name="depreciation_amount"
                                value="<?= e($_POST['depreciation_amount'] ?? number_format($monthlyDep, 2, '.', '')) ?>"
                                id="dep_amount" oninput="updatePreview()">
                        </div>
                        <div class="form-row">
                            <label>Period</label>
                            <select id="dep_period" onchange="applyDepPeriod()">
                                <option value="monthly">Monthly</option>
                                <option value="annual">Annual</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── DISPOSAL fields ── -->
                <div id="section_disposal" class="jtype-section">
                    <div class="hint-box">
                        <strong>Disposal Entry:</strong><br>
                        Dr. Cash (proceeds) + Dr. Accum. Depr. &nbsp;|&nbsp; Cr. Fixed Assets – Cost + Cr. Gain / Dr. Loss
                    </div>
                    <div class="grid-2">
                        <div class="form-row">
                            <label>Sale Proceeds (ETB)</label>
                            <input type="number" step="0.01" min="0" name="sale_proceeds"
                                value="<?= e($_POST['sale_proceeds'] ?? '0') ?>"
                                id="dis_proceeds" oninput="updatePreview()">
                        </div>
                        <div class="form-row">
                            <label>Accumulated Depreciation (ETB)</label>
                            <input type="number" step="0.01" min="0" name="accumulated_dep"
                                value="<?= e($_POST['accumulated_dep'] ?? number_format(max(0,(float)$selectedAsset['purchase_cost']-(float)$selectedAsset['current_value']), 2, '.', '')) ?>"
                                id="dis_accumdep" oninput="updatePreview()">
                        </div>
                        <div class="form-row">
                            <label>Computed Book Value (ETB)</label>
                            <input type="number" step="0.01" readonly name="book_value_display"
                                value="<?= number_format(max(0,(float)$selectedAsset['current_value']), 2, '.', '') ?>"
                                id="dis_book" style="background:#f8fafc;color:#64748b;">
                        </div>
                    </div>
                    <div id="disposal_gainloss" style="font-size:.88rem;padding:8px 12px;border-radius:8px;margin-top:4px;"></div>
                </div>

                <!-- ── REVALUATION fields ── -->
                <div id="section_revaluation" class="jtype-section">
                    <div class="hint-box">
                        <strong>Revaluation Entry:</strong><br>
                        Upward: Dr. Fixed Assets &nbsp;|&nbsp; Cr. Capital / Revaluation Surplus<br>
                        Downward: Dr. Loss on Revaluation &nbsp;|&nbsp; Cr. Fixed Assets
                    </div>
                    <div class="grid-2">
                        <div class="form-row">
                            <label>Current Book Value (ETB)</label>
                            <input type="number" step="0.01" name="current_value_display"
                                value="<?= number_format((float)$selectedAsset['current_value'], 2, '.', '') ?>" readonly
                                style="background:#f8fafc;color:#64748b;">
                        </div>
                        <div class="form-row">
                            <label>New Fair Value (ETB) *</label>
                            <input type="number" step="0.01" min="0" name="new_value"
                                value="<?= e($_POST['new_value'] ?? number_format((float)$selectedAsset['current_value'], 2, '.', '')) ?>"
                                id="rev_newval" oninput="updatePreview()">
                        </div>
                    </div>
                    <div id="rev_diff" style="font-size:.88rem;padding:8px 12px;border-radius:8px;margin-top:4px;"></div>
                </div>

                <!-- Entry Preview -->
                <div class="entry-preview" id="entryPreview">
                    <strong style="font-size:.85rem;color:#374151;">📊 Journal Preview</strong>
                    <div id="previewTable" style="margin-top:10px;"></div>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn btn-accent" style="width:100%;padding:12px;font-size:1rem;" id="postBtn">
                        ✅ Post Journal Entry
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── RIGHT: Recent entries ───────────────────────────────────────────── -->
    <div>
        <div class="aj-card">
            <h3>📜 Recent Asset Journal Entries</h3>
            <?php if (!$recentEntries): ?>
            <p style="color:#94a3b8;font-size:.88rem;text-align:center;padding:20px 0;">No asset journal entries posted yet.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="history-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:8px 10px;text-align:left;">Date</th>
                            <th style="padding:8px 10px;text-align:left;">Entry No</th>
                            <th style="padding:8px 10px;text-align:left;">Description</th>
                            <th style="padding:8px 10px;text-align:right;">Debit</th>
                            <th style="padding:8px 10px;text-align:right;">Credit</th>
                            <th style="padding:8px 10px;text-align:center;">Status</th>
                            <th style="padding:8px 10px;text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEntries as $je): ?>
                        <tr>
                            <td style="padding:8px 10px;"><?= format_date($je['entry_date']) ?></td>
                            <td style="padding:8px 10px;font-family:monospace;font-size:.82rem;"><?= e($je['entry_no']) ?></td>
                            <td style="padding:8px 10px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($je['description']) ?>"><?= e($je['description']) ?></td>
                            <td style="padding:8px 10px;text-align:right;color:#1d4ed8;font-weight:600;"><?= number_format((float)$je['total_debit'], 2) ?></td>
                            <td style="padding:8px 10px;text-align:right;color:#047857;font-weight:600;"><?= number_format((float)$je['total_credit'], 2) ?></td>
                            <td style="padding:8px 10px;text-align:center;">
                                <span class="badge-<?= $je['status'] === 'posted' ? 'posted' : 'draft' ?>"><?= ucfirst(e($je['status'])) ?></span>
                            </td>
                            <td style="padding:8px 10px;text-align:center;">
                                <a href="/block_plant/finance/journal_view.php?id=<?= (int)$je['id'] ?>" class="btn btn-sm">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Accounting Reference Card -->
        <div class="aj-card">
            <h3>📚 Accounting Reference</h3>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="padding:7px 10px;text-align:left;color:#64748b;">Transaction</th>
                        <th style="padding:7px 10px;text-align:left;color:#1d4ed8;">Debit</th>
                        <th style="padding:7px 10px;text-align:left;color:#047857;">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:7px 10px;font-weight:600;">Acquisition (Cash)</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">1210 Fixed Assets</td>
                        <td style="padding:7px 10px;color:#047857;">1110 Cash</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:7px 10px;font-weight:600;">Acquisition (Credit)</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">1210 Fixed Assets</td>
                        <td style="padding:7px 10px;color:#047857;">2150 Accts Payable</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:7px 10px;font-weight:600;">Depreciation</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">6300 Depr. Expense</td>
                        <td style="padding:7px 10px;color:#047857;">1211 Accum. Depr.</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:7px 10px;font-weight:600;">Disposal (Gain)</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">1110 Cash + 1211 Accum.</td>
                        <td style="padding:7px 10px;color:#047857;">1210 Asset + 4200 Gain</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:7px 10px;font-weight:600;">Disposal (Loss)</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">Cash + Accum + 6310 Loss</td>
                        <td style="padding:7px 10px;color:#047857;">1210 Fixed Assets</td>
                    </tr>
                    <tr>
                        <td style="padding:7px 10px;font-weight:600;">Upward Revaluation</td>
                        <td style="padding:7px 10px;color:#1d4ed8;">1210 Fixed Assets</td>
                        <td style="padding:7px 10px;color:#047857;">3100 Capital / Surplus</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ── Show/hide journal type sections ──────────────────────────────────────────
const typeInputs = document.querySelectorAll('input[name="journal_type"]');
function showSection() {
    document.querySelectorAll('.jtype-section').forEach(s => s.classList.remove('active'));
    const checked = document.querySelector('input[name="journal_type"]:checked');
    if (checked) {
        const sec = document.getElementById('section_' + checked.value);
        if (sec) sec.classList.add('active');
    }
    updatePreview();
}
typeInputs.forEach(r => r.addEventListener('change', showSection));
showSection(); // on load

// ── Depreciation period quick-fill ───────────────────────────────────────────
const purchaseCostVal = <?= (float)($selectedAsset['purchase_cost'] ?? 0) ?>;
const deprRate        = <?= (float)($selectedAsset['depreciation_rate'] ?? 0) ?>;
const annualDep       = purchaseCostVal * (deprRate / 100);
const monthlyDep      = annualDep / 12;

function applyDepPeriod() {
    const period = document.getElementById('dep_period').value;
    const field  = document.getElementById('dep_amount');
    if (!field) return;
    if (period === 'monthly') field.value = monthlyDep.toFixed(2);
    else if (period === 'annual') field.value = annualDep.toFixed(2);
    updatePreview();
}

// ── Preview renderer ─────────────────────────────────────────────────────────
function fmt(n) { return parseFloat(n||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function updatePreview() {
    const checked = document.querySelector('input[name="journal_type"]:checked');
    if (!checked) return;
    const type = checked.value;
    const container = document.getElementById('previewTable');
    let rows = [];
    let totalDr = 0, totalCr = 0;
    const currentVal = <?= (float)($selectedAsset['current_value'] ?? 0) ?>;
    const purchaseCost = <?= (float)($selectedAsset['purchase_cost'] ?? 0) ?>;
    const accumDep = <?= max(0, (float)($selectedAsset['purchase_cost'] ?? 0) - (float)($selectedAsset['current_value'] ?? 0)) ?>;

    if (type === 'acquisition') {
        const cost   = parseFloat(document.getElementById('acq_cost')?.value || 0);
        const method = document.getElementById('acq_method')?.value || 'cash';
        const crAcct = method === 'credit' ? '2150 – Accounts Payable' : '1110 – Cash';
        rows = [
            { acct: '1210 – Fixed Assets – Cost', dr: cost, cr: 0 },
            { acct: crAcct, dr: 0, cr: cost },
        ];
        totalDr = totalCr = cost;

    } else if (type === 'depreciation') {
        const dep = parseFloat(document.getElementById('dep_amount')?.value || 0);
        rows = [
            { acct: '6300 – Depreciation Expense', dr: dep, cr: 0 },
            { acct: '1211 – Accumulated Depreciation', dr: 0, cr: dep },
        ];
        totalDr = totalCr = dep;

    } else if (type === 'disposal') {
        const proceeds = parseFloat(document.getElementById('dis_proceeds')?.value || 0);
        const accum    = parseFloat(document.getElementById('dis_accumdep')?.value || accumDep);
        const bookVal  = Math.max(0, purchaseCost - accum);
        const bookField = document.getElementById('dis_book');
        if (bookField) bookField.value = bookVal.toFixed(2);
        const gainLoss = proceeds - bookVal;

        if (proceeds > 0) rows.push({ acct: '1110 – Cash', dr: proceeds, cr: 0 });
        if (accum > 0)    rows.push({ acct: '1211 – Accum. Depreciation', dr: accum, cr: 0 });
        rows.push({ acct: '1210 – Fixed Assets – Cost', dr: 0, cr: purchaseCost });

        if (gainLoss > 0) {
            rows.push({ acct: '4200 – Gain on Disposal', dr: 0, cr: gainLoss });
            document.getElementById('disposal_gainloss').innerHTML = `<span style="color:#166534;background:#dcfce7;padding:4px 10px;border-radius:6px;">✅ Gain on disposal: <strong>${fmt(gainLoss)} ETB</strong></span>`;
        } else if (gainLoss < 0) {
            rows.push({ acct: '6310 – Loss on Disposal', dr: Math.abs(gainLoss), cr: 0 });
            document.getElementById('disposal_gainloss').innerHTML = `<span style="color:#991b1b;background:#fee2e2;padding:4px 10px;border-radius:6px;">⚠️ Loss on disposal: <strong>${fmt(Math.abs(gainLoss))} ETB</strong></span>`;
        } else {
            document.getElementById('disposal_gainloss').innerHTML = `<span style="color:#1d4ed8;background:#dbeafe;padding:4px 10px;border-radius:6px;">Break-even disposal – no gain or loss</span>`;
        }

        rows.forEach(r => { totalDr += r.dr; totalCr += r.cr; });

    } else if (type === 'revaluation') {
        const newVal = parseFloat(document.getElementById('rev_newval')?.value || currentVal);
        const diff   = newVal - currentVal;
        const absDiff = Math.abs(diff);

        if (diff > 0) {
            rows = [
                { acct: '1210 – Fixed Assets – Cost', dr: absDiff, cr: 0 },
                { acct: '3100 – Capital / Revaluation Surplus', dr: 0, cr: absDiff },
            ];
            document.getElementById('rev_diff').innerHTML = `<span style="color:#166534;background:#dcfce7;padding:4px 10px;border-radius:6px;">↑ Upward revaluation: <strong>${fmt(absDiff)} ETB</strong></span>`;
        } else if (diff < 0) {
            rows = [
                { acct: '6310 – Loss on Revaluation', dr: absDiff, cr: 0 },
                { acct: '1210 – Fixed Assets – Cost', dr: 0, cr: absDiff },
            ];
            document.getElementById('rev_diff').innerHTML = `<span style="color:#991b1b;background:#fee2e2;padding:4px 10px;border-radius:6px;">↓ Downward revaluation: <strong>${fmt(absDiff)} ETB</strong></span>`;
        } else {
            document.getElementById('rev_diff').innerHTML = `<span style="color:#64748b;">No change in value</span>`;
        }
        totalDr = totalCr = absDiff;
    }

    if (!rows.length) { container.innerHTML = '<p style="color:#94a3b8;font-size:.83rem;">Select a type and fill in amounts to see preview.</p>'; return; }

    let html = `<table style="width:100%;border-collapse:collapse;">
        <thead><tr>
            <th style="text-align:left;padding:6px 8px;background:#f1f5f9;font-size:.78rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Account</th>
            <th style="text-align:right;padding:6px 8px;background:#f1f5f9;font-size:.78rem;color:#1d4ed8;text-transform:uppercase;">Debit</th>
            <th style="text-align:right;padding:6px 8px;background:#f1f5f9;font-size:.78rem;color:#047857;text-transform:uppercase;">Credit</th>
        </tr></thead><tbody>`;
    rows.forEach(r => {
        html += `<tr>
            <td style="padding:6px 8px;border-bottom:1px solid #f1f5f9;">${r.acct}</td>
            <td style="padding:6px 8px;border-bottom:1px solid #f1f5f9;text-align:right;color:#1d4ed8;font-weight:${r.dr>0?'600':'400'}">${r.dr > 0 ? fmt(r.dr) : '—'}</td>
            <td style="padding:6px 8px;border-bottom:1px solid #f1f5f9;text-align:right;color:#047857;font-weight:${r.cr>0?'600':'400'}">${r.cr > 0 ? fmt(r.cr) : '—'}</td>
        </tr>`;
    });
    html += `<tr style="background:#f8fafc;font-weight:700;">
        <td style="padding:6px 8px;">Total</td>
        <td style="padding:6px 8px;text-align:right;color:#1d4ed8;">${fmt(totalDr)}</td>
        <td style="padding:6px 8px;text-align:right;color:#047857;">${fmt(totalCr)}</td>
    </tr></tbody></table>`;

    const balanced = Math.abs(totalDr - totalCr) < 0.01;
    html += `<div style="margin-top:8px;font-size:.8rem;${balanced?'color:#166534':'color:#991b1b'}">
        ${balanced ? '✅ Balanced' : '❌ NOT balanced – debits must equal credits'}
    </div>`;
    container.innerHTML = html;

    const btn = document.getElementById('postBtn');
    if (btn) btn.disabled = !balanced || totalDr <= 0;
}

// Initialise
updatePreview();
</script>

<?php require_once "../partials/footer.php"; ?>
