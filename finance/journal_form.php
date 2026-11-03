<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('finance', 'create');

$pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_no VARCHAR(50) UNIQUE NOT NULL,
    entry_date DATE NOT NULL,
    description VARCHAR(255),
    reference_no VARCHAR(60),
    total_debit DECIMAL(14,2) DEFAULT 0,
    total_credit DECIMAL(14,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'posted',
    posted_by INT,
    posted_at TIMESTAMP NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(created_by) REFERENCES users(id),
    FOREIGN KEY(posted_by) REFERENCES users(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS journal_entry_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    account_id INT NOT NULL,
    debit DECIMAL(14,2) DEFAULT 0,
    credit DECIMAL(14,2) DEFAULT 0,
    description VARCHAR(255),
    FOREIGN KEY(entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY(account_id) REFERENCES chart_of_accounts(id)
)");

$accounts = $pdo->query("SELECT * FROM chart_of_accounts WHERE status = 1 ORDER BY account_code")->fetchAll();
$accountById = [];
foreach ($accounts as $account) {
    $accountById[(int) $account['id']] = $account;
}

$blankLine = ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
$formLines = [$blankLine, $blankLine];
$error = null;
$entryNo = 'JE-' . date('YmdHis');
$entryDate = date('Y-m-d');
$description = '';
$referenceNo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_journal'])) {
    $entryNo = trim($_POST['entry_no'] ?? '');
    $entryDate = trim($_POST['entry_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $referenceNo = trim($_POST['reference_no'] ?? '');
    $postedLines = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];

    // Keep whatever the user submitted so the form can be repopulated on error.
    $formLines = [];
    foreach ($postedLines as $line) {
        $formLines[] = [
            'account_id' => (string) (is_array($line) ? ($line['account_id'] ?? '') : ''),
            'debit' => (string) (is_array($line) ? ($line['debit'] ?? '') : ''),
            'credit' => (string) (is_array($line) ? ($line['credit'] ?? '') : ''),
            'description' => (string) (is_array($line) ? ($line['description'] ?? '') : ''),
        ];
    }
    if (!$formLines) {
        $formLines = [$blankLine];
    }

    if ($entryNo === '') {
        $entryNo = 'JE-' . date('YmdHis');
    }

    $dateOk = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate);
    if ($dateOk) {
        [$y, $m, $d] = array_map('intval', explode('-', $entryDate));
        $dateOk = checkdate($m, $d, $y);
    }

    if (strlen($entryNo) > 50) {
        $error = 'Entry number must be 50 characters or fewer.';
    } elseif (!$dateOk) {
        $error = 'Entry date must be a valid date.';
    } elseif (strlen($description) > 255) {
        $error = 'Description must be 255 characters or fewer.';
    } elseif (strlen($referenceNo) > 60) {
        $error = 'Reference number must be 60 characters or fewer.';
    }

    $linesByCode = [];
    $lineErrors = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $rowNumber = 0;

    if ($error === null) {
        foreach ($postedLines as $line) {
            $rowNumber++;
            if (!is_array($line)) {
                continue;
            }
            $accountId = (int) ($line['account_id'] ?? 0);
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($accountId <= 0 && $debit === 0.0 && $credit === 0.0) {
                continue; // untouched row
            }
            if ($accountId <= 0) {
                $lineErrors[] = "Line {$rowNumber}: choose an account or clear the amounts.";
                continue;
            }
            if (!isset($accountById[$accountId])) {
                $lineErrors[] = "Line {$rowNumber}: the selected account no longer exists or is inactive.";
                continue;
            }
            if ($debit < 0 || $credit < 0) {
                $lineErrors[] = "Line {$rowNumber}: amounts cannot be negative.";
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                $lineErrors[] = "Line {$rowNumber}: a line can hold a debit or a credit, not both.";
                continue;
            }
            if ($debit === 0.0 && $credit === 0.0) {
                continue; // account picked but no amounts yet - not part of the entry
            }

            $linesByCode[] = [
                (string) $accountById[$accountId]['account_code'],
                $debit,
                $credit,
                mb_substr(trim((string) ($line['description'] ?? '')), 0, 255),
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if ($lineErrors) {
            $error = implode(' ', $lineErrors);
        } elseif (count($linesByCode) < 2) {
            $error = 'Journal entry must contain at least two valid account lines.';
        } elseif (abs($totalDebit - $totalCredit) > 0.009) {
            $error = 'Total debits (' . money($totalDebit) . ') must equal total credits (' . money($totalCredit) . '). Difference: ' . money(abs($totalDebit - $totalCredit)) . '.';
        }
    }

    if ($error === null) {
        try {
            $pdo->beginTransaction();
            $entryId = post_accounting_journal($entryNo, $entryDate, $description, $referenceNo, $linesByCode, (int) $_SESSION['user_id']);

            if ($entryId === false) {
                $pdo->rollBack();
                $error = "Entry number {$entryNo} already exists — choose a different one.";
            } else {
                $pdo->commit();
                log_activity('create', 'finance', (int) $entryId, "Posted journal entry {$entryNo} dated {$entryDate} (debits " . money($totalDebit) . ', credits ' . money($totalCredit) . ')');
                set_flash('success', "Journal entry {$entryNo} posted successfully.");
                header('Location: journal.php');
                exit;
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to post journal entry: ' . $ex->getMessage();
        }
    }
}

$pageTitle = 'New Journal Entry';
require_once "../partials/header.php";
$cur = currency_symbol();
?>

<div class="page-header jl-header">
    <div>
        <span class="jl-eyebrow">FINANCE · DOUBLE ENTRY</span>
        <h1>New Journal Entry</h1>
        <p>Post double-entry accounting transactions. Debits must equal credits before posting.</p>
    </div>
    <div class="header-actions no-print">
        <a href="journal.php" class="btn btn-secondary">Back to entries</a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" id="journalForm" novalidate>
    <input type="hidden" name="save_journal" value="1">
    <div class="panel" style="margin-bottom:20px;">
        <div class="grid-2">
            <div>
                <label for="entry_no">Entry No</label>
                <input type="text" id="entry_no" name="entry_no" value="<?= e($entryNo) ?>" maxlength="50">
            </div>
            <div>
                <label for="entry_date">Entry Date</label>
                <input type="date" id="entry_date" name="entry_date" value="<?= e($entryDate) ?>" required>
            </div>
            <div style="grid-column:1 / -1;">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2" maxlength="255" placeholder="Narration for this journal entry"><?= e($description) ?></textarea>
            </div>
            <div style="grid-column:1 / -1;">
                <label for="reference_no">Reference No</label>
                <input type="text" id="reference_no" name="reference_no" maxlength="60" value="<?= e($referenceNo) ?>" placeholder="Invoice / PO / voucher ref">
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="jl-bar">
            <h3>Journal lines</h3>
            <div class="jl-totals" aria-live="polite">
                <span>Total debit <strong id="jlDebit"><?= e($cur) ?>0.00</strong></span>
                <span>Total credit <strong id="jlCredit"><?= e($cur) ?>0.00</strong></span>
                <span class="jl-state" id="jlState">Not balanced</span>
            </div>
        </div>

        <div class="flash flash-error" id="jlError" hidden></div>

        <div class="table-container">
            <table class="data-table jl-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th class="jl-num">Debit</th>
                        <th class="jl-num">Credit</th>
                        <th>Description</th>
                        <th class="jl-remove-col no-print"></th>
                    </tr>
                </thead>
                <tbody id="journal-lines">
                <?php foreach ($formLines as $i => $line): ?>
                    <tr data-idx="<?= (int) $i ?>">
                        <td>
                            <select name="lines[<?= (int) $i ?>][account_id]">
                                <option value="">Select account</option>
                                <?php foreach ($accounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>" <?= (string) $account['id'] === (string) ($line['account_id'] ?? '') ? 'selected' : '' ?>><?= e($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="jl-num"><input type="number" step="0.01" min="0" name="lines[<?= (int) $i ?>][debit]" value="<?= e((string) ($line['debit'] === '' ? '' : $line['debit'])) ?>" placeholder="0.00"></td>
                        <td class="jl-num"><input type="number" step="0.01" min="0" name="lines[<?= (int) $i ?>][credit]" value="<?= e((string) ($line['credit'] === '' ? '' : $line['credit'])) ?>" placeholder="0.00"></td>
                        <td><input type="text" maxlength="255" name="lines[<?= (int) $i ?>][description]" value="<?= e((string) ($line['description'] ?? '')) ?>" placeholder="Optional line narration"></td>
                        <td class="jl-remove-col no-print"><button type="button" class="jl-remove" title="Remove line" aria-label="Remove line">×</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <template id="jlRowTemplate">
            <tr>
                <td>
                    <select name="">
                        <option value="">Select account</option>
                        <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"><?= e($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="jl-num"><input type="number" step="0.01" min="0" name="" placeholder="0.00"></td>
                <td class="jl-num"><input type="number" step="0.01" min="0" name="" placeholder="0.00"></td>
                <td><input type="text" maxlength="255" name="" placeholder="Optional line narration"></td>
                <td class="jl-remove-col no-print"><button type="button" class="jl-remove" title="Remove line" aria-label="Remove line">×</button></td>
            </tr>
        </template>

        <div class="jl-actions no-print">
            <button type="button" class="btn btn-secondary" id="addLineBtn">+ Add line</button>
            <button type="submit" class="btn btn-accent">Post Journal Entry</button>
        </div>
        <p class="jl-hint">Each row is one leg of the entry. Rows left blank are ignored — an entry needs at least two funded lines whose debits and credits are equal.</p>
    </div>
</form>

<style>
.jl-header p{color:#64748b;margin:6px 0 0}
.jl-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#246a73}
.jl-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.jl-bar h3{margin:0}
.jl-totals{display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:.85rem;color:#667a80}
.jl-totals strong{display:inline-block;margin-left:5px;color:#183b56;font-size:1rem;font-variant-numeric:tabular-nums}
.jl-state{padding:5px 12px;border-radius:20px;font-size:.76rem;font-weight:800;background:#fdeee9;color:#b3452f}
.jl-state.is-balanced{background:#e6f4ec;color:#1d7a4f}
.jl-table th.jl-num,.jl-table td.jl-num{text-align:right}
.jl-table td.jl-num input{text-align:right;font-variant-numeric:tabular-nums}
.jl-table select{min-width:210px}
.jl-remove-col{width:44px;text-align:center}
.jl-remove{width:28px;height:28px;border:1px solid #e6c4ba;border-radius:8px;background:#fff;color:#b3452f;font-size:1.05rem;line-height:1;cursor:pointer;transition:.15s}
.jl-remove:hover{background:#b3452f;border-color:#b3452f;color:#fff}
.jl-row-invalid td{background:#fff6f4}
.jl-row-invalid select,.jl-row-invalid input{border-color:#e08b76}
.jl-actions{display:flex;justify-content:space-between;gap:12px;margin-top:16px;flex-wrap:wrap}
.jl-hint{margin:12px 0 0;font-size:.82rem;color:#667a80}
@media(max-width:700px){.jl-bar{align-items:flex-start;flex-direction:column}.jl-actions{flex-direction:column-reverse}.jl-actions .btn{width:100%}}
@media print {
    .no-print, .jl-actions, .jl-remove-col { display: none !important; }
    .jl-hint { display: none !important; }
}
</style>

<script>
(function () {
    var body = document.getElementById('journal-lines');
    var template = document.getElementById('jlRowTemplate');
    var addBtn = document.getElementById('addLineBtn');
    var form = document.getElementById('journalForm');
    var debitOut = document.getElementById('jlDebit');
    var creditOut = document.getElementById('jlCredit');
    var stateOut = document.getElementById('jlState');
    var errorOut = document.getElementById('jlError');
    var currency = <?= json_encode($cur) ?>;

    function money(v) {
        return currency + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function rows() {
        return Array.prototype.slice.call(body.querySelectorAll('tr'));
    }

    function num(input) {
        var v = parseFloat(input && input.value);
        return isNaN(v) ? 0 : v;
    }

    function refresh() {
        var debit = 0;
        var credit = 0;
        var all = rows();
        all.forEach(function (row) {
            debit += num(row.querySelector('[name$="[debit]"]'));
            credit += num(row.querySelector('[name$="[credit]"]'));
            row.querySelector('.jl-remove').disabled = all.length <= 2;
        });
        debitOut.textContent = money(debit);
        creditOut.textContent = money(credit);
        var balanced = debit > 0 && Math.abs(debit - credit) < 0.005;
        stateOut.textContent = balanced ? 'Balanced' : 'Off by ' + money(Math.abs(debit - credit));
        stateOut.classList.toggle('is-balanced', balanced);
        addBtn.disabled = all.length >= 12;
        return { debit: debit, credit: credit, balanced: balanced, count: all.length };
    }

    addBtn.addEventListener('click', function () {
        var idx = rows().reduce(function (max, row) {
            return Math.max(max, parseInt(row.getAttribute('data-idx'), 10) || 0);
        }, -1) + 1;
        var node = template.content.cloneNode(true);
        var tr = node.querySelector('tr');
        tr.setAttribute('data-idx', String(idx));
        tr.querySelector('select').name = 'lines[' + idx + '][account_id]';
        var inputs = tr.querySelectorAll('input');
        inputs[0].name = 'lines[' + idx + '][debit]';
        inputs[1].name = 'lines[' + idx + '][credit]';
        inputs[2].name = 'lines[' + idx + '][description]';
        body.appendChild(node);
        refresh();
        var last = body.querySelector('tr[data-idx="' + idx + '"] select');
        if (last) last.focus();
    });

    body.addEventListener('click', function (event) {
        var btn = event.target.closest('.jl-remove');
        if (!btn || btn.disabled) return;
        var row = btn.closest('tr');
        if (rows().length <= 2) return;
        row.remove();
        refresh();
    });

    body.addEventListener('input', function () {
        refresh();
        errorOut.hidden = true;
    });

    form.addEventListener('submit', function (event) {
        var state = refresh();
        var problems = [];

        rows().forEach(function (row, i) {
            var lineNo = i + 1;
            var select = row.querySelector('select');
            var debit = num(row.querySelector('[name$="[debit]"]'));
            var credit = num(row.querySelector('[name$="[credit]"]'));
            row.classList.remove('jl-row-invalid');
            if ((debit > 0 || credit > 0) && !select.value) {
                row.classList.add('jl-row-invalid');
                problems.push('Line ' + lineNo + ': choose an account or clear the amounts.');
            } else if (debit > 0 && credit > 0) {
                row.classList.add('jl-row-invalid');
                problems.push('Line ' + lineNo + ': a line can hold a debit or a credit, not both.');
            } else if (debit < 0 || credit < 0) {
                row.classList.add('jl-row-invalid');
                problems.push('Line ' + lineNo + ': amounts cannot be negative.');
            }
        });

        if (state.count < 2) {
            problems.push('Journal entry must contain at least two valid account lines.');
        } else if (!state.balanced) {
            problems.push('Total debits must equal total credits.');
        }

        if (problems.length) {
            event.preventDefault();
            errorOut.textContent = problems.join(' ');
            errorOut.hidden = false;
            errorOut.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    refresh();
})();
</script>

<?php require_once "../partials/footer.php"; ?>
