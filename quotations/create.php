<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('sales', 'create');

$pageTitle = "Create Quotation";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer = !empty($_POST['customer_id']) ? (int) $_POST['customer_id'] : null;
    $date = trim((string) ($_POST['quotation_date'] ?? ''));
    $expiry = trim((string) ($_POST['expiry_date'] ?? '')) ?: null;
    $discount = round(max(0, (float) ($_POST['discount'] ?? 0)), 2);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!is_valid_date_string($date)) {
        $error = 'A valid quotation date is required.';
    } elseif ($expiry !== null && !is_valid_date_string($expiry)) {
        $error = 'Enter a valid expiry date or leave it blank.';
    } elseif ($expiry !== null && $expiry < $date) {
        $error = 'The expiry date cannot be before the quotation date.';
    } elseif (mb_strlen($notes) > 2000) {
        $error = 'Notes must be 2,000 characters or fewer.';
    } elseif ($customer !== null) {
        $customerCheck = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 1");
        $customerCheck->execute([$customer]);
        if (!$customerCheck->fetchColumn()) {
            $error = 'Select an active customer.';
        }
    }

    if (empty($error)) {
        $subtotal = 0.0;
        $items = [];
        $blockIds = (array) ($_POST['block_type'] ?? []);
        $quantities = (array) ($_POST['quantity'] ?? []);
        $prices = (array) ($_POST['unit_price'] ?? []);
        $validBlocks = $pdo->query("SELECT id FROM block_types WHERE status = 1")->fetchAll(PDO::FETCH_COLUMN);
        $validBlocks = array_map('intval', $validBlocks);

        foreach ($blockIds as $i => $blockId) {
            $blockId = (int) $blockId;
            $qty = (int) ($quantities[$i] ?? 0);
            $price = round((float) ($prices[$i] ?? 0), 2);
            if ($blockId <= 0 && $qty <= 0) {
                continue;
            }
            if (!in_array($blockId, $validBlocks, true)) {
                $error = 'One of the selected block types is invalid or inactive.';
                break;
            }
            if ($qty <= 0 || $qty > 1000000) {
                $error = 'Each line must have a quantity between 1 and 1,000,000.';
                break;
            }
            if ($price < 0 || $price > 99999999.99) {
                $error = 'Unit price must be between 0 and 99,999,999.99.';
                break;
            }
            $total = round($qty * $price, 2);
            $subtotal += $total;
            $items[] = [$blockId, $qty, $price, $total];
        }

        if (empty($error) && !$items) {
            $error = 'Add at least one line item with a block type and quantity.';
        }
        if (empty($error) && $discount > $subtotal) {
            $error = 'Discount cannot be greater than the subtotal.';
        }

        if (empty($error)) {
            $total_amount = round($subtotal - $discount, 2);
            $quot_no = '';
            for ($attempt = 0; $attempt < 5 && $quot_no === ''; $attempt++) {
                $candidate = 'QT' . date('Ymd') . random_int(1000, 9999);
                $exists = $pdo->prepare("SELECT id FROM quotations WHERE quotation_no = ?");
                $exists->execute([$candidate]);
                if (!$exists->fetchColumn()) {
                    $quot_no = $candidate;
                }
            }
            if ($quot_no === '') {
                $error = 'Could not generate a quotation number. Please try again.';
            }
        }

        if (empty($error)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO quotations(quotation_no, customer_id, quotation_date, expiry_date, subtotal, discount, total_amount, notes, created_by) VALUES(?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$quot_no, $customer, $date, $expiry, $subtotal, $discount, $total_amount, $notes, $_SESSION['user_id']]);
                $quot_id = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO quotation_items(quotation_id, block_type_id, quantity, unit_price, total_price) VALUES(?,?,?,?,?)");
                foreach ($items as $item) {
                    $stmt->execute(array_merge([$quot_id], $item));
                }
                $pdo->commit();
                log_activity('create', 'quotations', $quot_id, 'Quotation ' . $quot_no . ' created');
                
                // Show expiry warning if expiry date is too close
                $today = date('Y-m-d');
                if ($expiry) {
                    $daysBetween = floor((strtotime($expiry) - strtotime($today)) / (60*60*24));
                    if ($daysBetween < 0) {
                        // This shouldn't happen because we validated earlier, but just in case
                        $modalMessage = "Warning: This quotation's expiry date is in the past!";
                        $modalType = 'warning';
                    } elseif ($daysBetween <= 7) {
                        $modalMessage = "Note: This quotation will expire in {$daysBetween} day" . ($daysBetween !== 1 ? 's' : '') . " on " . date('d M Y', strtotime($expiry)) . ".";
                        $modalType = 'warning';
                    } else {
                        $modalMessage = "Quotation created successfully.";
                        $modalType = 'success';
                    }
                } else {
                    $modalMessage = "Quotation created successfully (no expiry date set).";
                    $modalType = 'success';
                }
                
                set_modal($modalType, '✅ Quotation Created', $modalMessage, 'view.php?id=' . $quot_id);
                $showModal = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not create the quotation: ' . $e->getMessage();
            }
        }
    }
}


$customers = $pdo->query("SELECT id, name FROM customers WHERE status=1 ORDER BY name")->fetchAll();
$blocks = $pdo->query("SELECT id, name, size, selling_price FROM block_types WHERE status=1 ORDER BY name, size")->fetchAll();

require_once "../partials/header.php";
?>

<h1>Create Quotation</h1>

<?php if(isset($error)): ?>
<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="post" style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
        <div>
            <label>Customer</label>
            <select name="customer_id">
                <option value="">Walk-in Customer</option>
                <?php foreach($customers as $cust): ?>
                <option value="<?= $cust['id'] ?>"><?= htmlspecialchars($cust['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label>Quotation Date *</label>
            <input type="date" name="quotation_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div>
            <label>Expiry Date</label>
            <input type="date" name="expiry_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
        </div>
    </div>
    
    <h3>Items</h3>
    <table id="itemsTable">
        <thead>
            <tr>
                <th>Block Type</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <select name="block_type[]" onchange="updatePrice(this)">
                        <option value="">Select Block</option>
                        <?php foreach($blocks as $block): ?>
                        <option value="<?= $block['id'] ?>" data-price="<?= $block['selling_price'] ?>">
                            <?= htmlspecialchars($block['name']) ?> (<?= $block['size'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" name="quantity[]" value="0" onchange="calculateRow(this)"></td>
                <td><input type="number" step="0.01" name="unit_price[]" value="0" onchange="calculateRow(this)"></td>
                <td><input type="number" step="0.01" class="row-total" readonly value="0"></td>
                <td><button type="button" onclick="removeRow(this)" class="btn btn-sm" style="background: #dc3545;">Remove</button></td>
            </tr>
        </tbody>
    </table>
    
    <button type="button" onclick="addRow()" class="btn" style="margin-top: 10px;">+ Add Item</button>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
        <div>
            <label>Notes</label>
            <textarea name="notes" rows="4"></textarea>
        </div>
        
        <div>
            <div style="margin-bottom: 10px;">
                <label>Subtotal:</label>
                <input type="number" step="0.01" id="subtotal" readonly value="0">
            </div>
            <div style="margin-bottom: 10px;">
                <label>Discount:</label>
                <input type="number" step="0.01" name="discount" id="discount" value="0" onchange="calculateTotal()">
            </div>
            <div style="margin-bottom: 10px;">
                <label><strong>Total Amount:</strong></label>
                <input type="number" step="0.01" id="total" readonly value="0" style="font-weight: bold; font-size: 18px;">
            </div>
        </div>
    </div>
    
    <div style="margin-top: 20px; display: flex; gap: 10px;">
        <button type="submit" class="btn">Create Quotation</button>
        <a href="index.php" class="btn" style="background: #6c757d;">Cancel</a>
    </div>
</form>

<script>
function updatePrice(select) {
    const row = select.closest('tr');
    const option = select.options[select.selectedIndex];
    const price = option.getAttribute('data-price') || 0;
    row.querySelector('input[name="unit_price[]"]').value = price;
    calculateRow(row.querySelector('input[name="quantity[]"]'));
}

function calculateRow(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('input[name="quantity[]"]').value) || 0;
    const price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
    const total = qty * price;
    row.querySelector('.row-total').value = total.toFixed(2);
    calculateTotal();
}

function calculateTotal() {
    let subtotal = 0;
    document.querySelectorAll('.row-total').forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const total = subtotal - discount;
    
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('total').value = total.toFixed(2);
}

function addRow() {
    const table = document.getElementById('itemsTable').querySelector('tbody');
    const row = table.rows[0].cloneNode(true);
    row.querySelector('select').value = '';
    row.querySelectorAll('input').forEach(input => input.value = 0);
    table.appendChild(row);
}

function removeRow(btn) {
    const table = document.getElementById('itemsTable').querySelector('tbody');
    if(table.rows.length > 1) {
        btn.closest('tr').remove();
        calculateTotal();
    }
}
</script>

<?php require_once "../partials/footer.php"; ?>
