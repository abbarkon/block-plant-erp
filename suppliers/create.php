<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('suppliers', 'create');

$errors = [];
$form = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'opening_balance' => (string) ($_POST['opening_balance'] ?? '0.00'),
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($form['name'] === '') {
        $errors[] = 'Supplier name is required.';
    } elseif (mb_strlen($form['name']) > 150) {
        $errors[] = 'Supplier name must be 150 characters or fewer.';
    }
    if (mb_strlen($form['phone']) > 50) {
        $errors[] = 'Phone number must be 50 characters or fewer.';
    }
    if (mb_strlen($form['address']) > 255) {
        $errors[] = 'Address must be 255 characters or fewer.';
    }
    if ((float) $form['opening_balance'] < 0 || (float) $form['opening_balance'] > 9999999999.99) {
        $errors[] = 'Opening balance must be between 0 and 9,999,999,999.99.';
    }

    if (!$errors) {
        $code = '';
        for ($attempt = 0; $attempt < 5 && $code === ''; $attempt++) {
            $candidate = 'SUP-' . date('YmdHis') . random_int(10, 99);
            $exists = $pdo->prepare("SELECT id FROM suppliers WHERE supplier_code = ?");
            $exists->execute([$candidate]);
            if (!$exists->fetchColumn()) {
                $code = $candidate;
            }
        }
        if ($code === '') {
            $errors[] = 'Could not generate a unique supplier code. Please try again.';
        }
    }

    if (!$errors) {
        $s = $pdo->prepare("INSERT INTO suppliers(supplier_code, name, phone, address, opening_balance) VALUES(?, ?, ?, ?, ?)");
        $s->execute([
            $code,
            $form['name'],
            $form['phone'] !== '' ? $form['phone'] : null,
            $form['address'] !== '' ? $form['address'] : null,
            (float) $form['opening_balance'],
        ]);
        $supplierId = (int) $pdo->lastInsertId();
        log_activity('create', 'suppliers', $supplierId, 'Created supplier ' . $form['name']);
        set_flash('success', 'Supplier created successfully.');
        header("Location: index.php");
        exit;
    }
}

$pageTitle = "Add New Supplier";
require_once "../partials/header.php";
?>
<style>
    .form-container {
        max-width: 800px; margin: 0 auto;
        background: #fff; border-radius: 16px; padding: 35px 45px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.06); border: 1px solid #edf2f9;
    }
    .form-header {
        text-align: center; margin-bottom: 35px;
    }
    .form-header h2 { margin: 0 0 10px; color: #e65100; font-size: 2rem; }
    .form-header p { color: #64748b; margin: 0; font-size: 1.05rem; }
    
    .form-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 24px;
    }
    .form-group { margin-bottom: 5px; }
    .form-group.full-width { grid-column: span 2; }
    
    .form-group label {
        display: block; margin-bottom: 8px; font-weight: 600; color: #475569; font-size: 0.9rem; letter-spacing: 0.3px;
    }
    .form-group input, .form-group textarea {
        width: 100%; padding: 14px 18px; border: 2px solid #e2e8f0;
        border-radius: 10px; font-size: 1rem; color: #1e293b; background: #f8fafc;
        transition: all 0.2s; box-sizing: border-box;
    }
    .form-group input:focus, .form-group textarea:focus {
        border-color: #ffa000; background: #fff; box-shadow: 0 0 0 4px rgba(255,160,0,0.1); outline: none;
    }
    .form-group textarea { resize: vertical; min-height: 120px; font-family: inherit; }
    
    .btn-submit {
        background: linear-gradient(90deg, #e65100, #ffa000); color: #fff;
        padding: 16px 24px; border-radius: 10px; font-weight: 700; font-size: 1.15rem;
        border: none; cursor: pointer; width: 100%; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 6px 20px rgba(230,81,0,0.25); text-transform: uppercase; letter-spacing: 1px;
    }
    .btn-submit:hover { filter: brightness(1.1); transform: translateY(-3px); box-shadow: 0 10px 25px rgba(230,81,0,0.35); }
    
    .back-link {
        display: inline-block; margin-bottom: 25px; color: #64748b; text-decoration: none; font-weight: 600;
        transition: color 0.2s;
    }
    .back-link:hover { color: #e65100; }
    
    /* Dark mode overrides */
    body.dark-theme .form-container { background: #1a1a1a; border-color: #333; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
    body.dark-theme .form-header h2 { color: #ffb300; }
    body.dark-theme .form-group label { color: #cbd5e1; }
    body.dark-theme .form-group input, body.dark-theme .form-group textarea { background: #222; border-color: #444; color: #f8fafc; }
    body.dark-theme .form-group input:focus, body.dark-theme .form-group textarea:focus { border-color: #ffb300; background: #111; box-shadow: 0 0 0 4px rgba(255,179,0,0.15); }
    
    @media (max-width: 650px) { .form-grid { grid-template-columns: 1fr; } .form-group.full-width { grid-column: span 1; } }
</style>

<div style="max-width: 800px; margin: 0 auto;">
    <a href="index.php" class="back-link">← Return to Suppliers</a>
</div>

<div class="form-container">
    <div class="form-header">
        <h2>🏢 Register Supplier</h2>
        <p>Enter the vendor's profile and initial account details below.</p>
    </div>

    <?php if ($errors): ?>
    <div class="flash flash-error" style="margin-bottom:20px;">
        <strong>Please fix the following:</strong>
        <ul style="margin:8px 0 0;padding-left:20px;">
            <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post">
        <div class="form-grid">
            <div class="form-group">
                <label>Company Name *</label>
                <input type="text" name="name" required maxlength="150" value="<?= e($form['name']) ?>" placeholder="e.g. Acme Cement Co.">
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" maxlength="50" value="<?= e($form['phone']) ?>" placeholder="e.g. +1 234 567 8900">
            </div>
            
            <div class="form-group full-width">
                <label>Physical Address</label>
                <textarea name="address" maxlength="255" placeholder="Enter full street address..."><?= e($form['address']) ?></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Opening Balance (<?= currency_symbol() ?>)</label>
                <input type="number" step="0.01" min="0" name="opening_balance" value="<?= e($form['opening_balance']) ?>" style="font-family: monospace; font-size: 1.3rem; font-weight: bold; letter-spacing: 1px;">
            </div>
        </div>
        
        <div style="margin-top: 35px;">
            <button type="submit" class="btn-submit">💾 Save Supplier Profile</button>
        </div>
    </form>
</div>

<?php require_once "../partials/footer.php"; ?>