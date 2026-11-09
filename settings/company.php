<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_permission('settings', 'view');

$pageTitle = "Company & Branding";
$uploadDir = dirname(__DIR__) . '/assets/uploads';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission('settings', 'edit')) {
        http_response_code(403);
        exit('Access denied');
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $companyNameAm = trim($_POST['company_name_am'] ?? '');
    $tagline = trim($_POST['tagline'] ?? '');
    $taglineAm = trim($_POST['tagline_am'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $taxId = trim($_POST['tax_id'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? 'ETB'));
    $currencySymbol = trim($_POST['currency_symbol'] ?? 'Br');
    $locale = $_POST['locale_default'] ?? 'en';
    $primary = strtolower(trim($_POST['primary_color'] ?? '#b8481b'));
    $secondary = strtolower(trim($_POST['secondary_color'] ?? '#7c2d12'));
    $commissionRaw = trim($_POST['default_commission_rate'] ?? '0');
    $removeLogo = !empty($_POST['remove_logo']);

    $errors = [];
    if ($companyName === '' || mb_strlen($companyName) > 150) {
        $errors[] = 'Company name is required (maximum 150 characters).';
    }
    foreach (['Company name (Amharic)' => [$companyNameAm, 150], 'Tagline' => [$tagline, 200], 'Tagline (Amharic)' => [$taglineAm, 200], 'Address' => [$address, 500]] as $label => [$value, $max]) {
        if (mb_strlen($value) > $max) {
            $errors[] = "{$label} must be {$max} characters or fewer.";
        }
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid company email address.';
    }
    if (!preg_match('/^[A-Z]{2,10}$/', $currency)) {
        $errors[] = 'Currency code must be 2–10 uppercase letters (for example ETB).';
    }
    if ($currencySymbol === '' || mb_strlen($currencySymbol) > 8) {
        $errors[] = 'Currency symbol is required (maximum 8 characters).';
    }
    if (!in_array($locale, ['en', 'am'], true)) {
        $errors[] = 'Choose a valid default language.';
    }
    foreach (['Primary color' => $primary, 'Secondary color' => $secondary] as $label => $color) {
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $errors[] = "{$label} must be a valid hex color.";
        }
    }
    if (!is_numeric($commissionRaw) || (float) $commissionRaw < 0 || (float) $commissionRaw > 100) {
        $errors[] = 'Default commission rate must be between 0 and 100 percent.';
    }
    if (mb_strlen($phone) > 50 || mb_strlen($taxId) > 50) {
        $errors[] = 'Phone and Tax ID must be 50 characters or fewer.';
    }

    $current = company_settings(true);
    $logoPath = $current['logo_path'] ?? null;
    $newUpload = null;

    if (!$errors && !empty($_FILES['logo']['name'])) {
        $file = $_FILES['logo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The logo upload failed. Please try again.';
        } elseif ((int) $file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo must be 2 MB or smaller.';
        } else {
            $mime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
                if ($finfo) {
                    finfo_close($finfo);
                }
            }
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $imageInfo = @getimagesize($file['tmp_name']);
            if (!isset($allowed[$mime]) || $imageInfo === false) {
                $errors[] = 'Logo must be a valid PNG, JPG, WEBP, or GIF image.';
            } else {
                $fname = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fname)) {
                    $newUpload = $fname;
                    $logoPath = '/block_plant/assets/uploads/' . $fname;
                } else {
                    $errors[] = 'The logo could not be saved. Check the uploads folder permissions.';
                }
            }
        }
    }

    if (!$errors && $removeLogo && $newUpload === null) {
        $logoPath = null;
    }

    if ($errors) {
        set_flash('danger', implode(' ', $errors));
        header('Location: company.php');
        exit;
    }

    $stmt = $pdo->prepare("UPDATE company_settings SET company_name=?, company_name_am=?, tagline=?, tagline_am=?, address=?, phone=?, email=?, tax_id=?, currency=?, currency_symbol=?, locale_default=?, primary_color=?, secondary_color=?, default_commission_rate=?, logo_path=? WHERE id=1");
    $stmt->execute([
        $companyName,
        $companyNameAm,
        $tagline,
        $taglineAm,
        $address,
        $phone,
        $email,
        $taxId,
        $currency,
        $currencySymbol,
        $locale,
        $primary,
        $secondary,
        round((float) $commissionRaw, 2),
        $logoPath,
    ]);

    // Remove the previous logo file only after the new settings are safely stored.
    $previousLogo = $current['logo_path'] ?? null;
    if ($previousLogo && $previousLogo !== $logoPath && strpos($previousLogo, '/assets/uploads/') !== false) {
        $oldFile = $uploadDir . '/' . basename($previousLogo);
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    company_settings(true);
    log_activity('update', 'settings', 1, 'Company branding and contact details updated');
    set_flash('success', 'Company settings saved.');
    header('Location: company.php');
    exit;
}

$company = company_settings(true);
$flash = get_flash();
require_once "../partials/header.php";
?>

<style>
    .brand-preview { display:flex; gap:18px; align-items:center; padding:20px; border:1px dashed #cbd5e1; border-radius:12px; background:#f8fafc; margin-bottom:6px; }
    .brand-preview__logo { width:72px; height:72px; border-radius:14px; object-fit:contain; background:#fff; border:1px solid #e2e8f0; display:grid; place-items:center; font-size:1.8rem; font-weight:800; color:#fff; flex:0 0 auto; }
    .brand-preview__name { font-size:1.25rem; font-weight:800; color:#1f2933; margin:0; }
    .brand-preview__tagline { color:#64748b; margin:3px 0 0; font-size:.88rem; }
    .brand-swatches { display:flex; gap:8px; margin-top:8px; }
    .brand-swatches span { display:inline-flex; align-items:center; gap:6px; font-size:.75rem; color:#475569; }
    .brand-swatches i { width:16px; height:16px; border-radius:50%; border:1px solid rgba(15,23,42,.15); display:inline-block; }
    .company-form h3 { margin-top:26px; }
    .company-form h3:first-child { margin-top:0; }
    .company-form label { margin-top:12px; }
    .company-form .hint { color:#64748b; font-size:.78rem; margin-top:4px; }
    .meta-strip { display:flex; flex-wrap:wrap; gap:10px 22px; color:#64748b; font-size:.82rem; margin-top:14px; }
    @media print { .no-print { display:none !important; } body { background:#fff; } }
</style>

<div class="page-header">
    <div>
        <h1>Company &amp; Branding</h1>
        <p style="color:#64748b;margin:6px 0 0;">Identity, contact details, and the colors used across invoices, reports, and the navigation bar.</p>
    </div>
    <div class="header-actions no-print">
        <a href="documents.php" class="btn btn-secondary">Document library</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="panel" style="max-width:980px;">
    <h3 style="margin-top:0;">Brand preview</h3>
    <div class="brand-preview">
        <?php if (!empty($company['logo_path'])): ?>
        <img class="brand-preview__logo" id="brandLogoPreview" src="<?= e($company['logo_path']) ?>" alt="Company logo" style="background:#fff;">
        <?php else: ?>
        <div class="brand-preview__logo" id="brandLogoFallback" style="background:linear-gradient(135deg, <?= e(brand_colors()['primary']) ?>, <?= e(brand_colors()['secondary']) ?>);">
            <?= e(mb_strtoupper(mb_substr($company['company_name'] ?? 'BP', 0, 2))) ?>
        </div>
        <?php endif; ?>
        <div>
            <p class="brand-preview__name" id="brandNamePreview"><?= e($company['company_name'] ?? 'Company name') ?></p>
            <p class="brand-preview__tagline" id="brandTaglinePreview"><?= e($company['tagline'] ?? 'Your tagline appears here') ?></p>
            <div class="brand-swatches">
                <span><i id="primarySwatch" style="background:<?= e(brand_colors()['primary']) ?>;"></i> Primary <code id="primaryHex"><?= e(brand_colors()['primary']) ?></code></span>
                <span><i id="secondarySwatch" style="background:<?= e(brand_colors()['secondary']) ?>;"></i> Secondary <code id="secondaryHex"><?= e(brand_colors()['secondary']) ?></code></span>
            </div>
        </div>
    </div>
    <div class="meta-strip">
        <span>Currency: <strong><?= e($company['currency'] ?? 'ETB') ?> (<?= e($company['currency_symbol'] ?? 'Br') ?>)</strong></span>
        <span>Default language: <strong><?= ($company['locale_default'] ?? 'en') === 'am' ? 'Amharic' : 'English' ?></strong></span>
        <span>Last updated: <strong><?= e(!empty($company['updated_at']) ? format_datetime($company['updated_at']) : '—') ?></strong></span>
    </div>
</div>

<form method="post" enctype="multipart/form-data" class="panel company-form no-print" style="max-width:980px;">
    <h3>Identity (English / Amharic)</h3>
    <div class="grid-2">
        <div>
            <label>Company name (English) *</label>
            <input type="text" name="company_name" maxlength="150" required value="<?= e($company['company_name'] ?? '') ?>" oninput="document.getElementById('brandNamePreview').textContent = this.value || 'Company name';">
        </div>
        <div>
            <label>Company name (Amharic)</label>
            <input type="text" name="company_name_am" maxlength="150" value="<?= e($company['company_name_am'] ?? '') ?>">
        </div>
        <div>
            <label>Tagline (English)</label>
            <input type="text" name="tagline" maxlength="200" value="<?= e($company['tagline'] ?? '') ?>" oninput="document.getElementById('brandTaglinePreview').textContent = this.value || 'Your tagline appears here';">
        </div>
        <div>
            <label>Tagline (Amharic)</label>
            <input type="text" name="tagline_am" maxlength="200" value="<?= e($company['tagline_am'] ?? '') ?>">
        </div>
    </div>

    <h3>Logo &amp; colors</h3>
    <div class="grid-2">
        <div>
            <label>Logo upload</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
            <p class="hint">PNG, JPG, WEBP, or GIF · maximum 2 MB. A wide or square logo works best.</p>
            <?php if (!empty($company['logo_path'])): ?>
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;margin-top:10px;">
                <input type="checkbox" name="remove_logo" value="1" style="width:auto;margin:0;"> Remove current logo
            </label>
            <?php endif; ?>
        </div>
        <div>
            <label>Default language</label>
            <select name="locale_default">
                <option value="en" <?= ($company['locale_default'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                <option value="am" <?= ($company['locale_default'] ?? '') === 'am' ? 'selected' : '' ?>>Amharic (አማርኛ)</option>
            </select>
            <p class="hint">Applies to new sessions. Users can still switch language from the top bar.</p>
        </div>
        <div>
            <label>Primary color</label>
            <input type="color" name="primary_color" value="<?= e(brand_colors()['primary']) ?>" oninput="document.getElementById('primarySwatch').style.background=this.value;document.getElementById('primaryHex').textContent=this.value;">
        </div>
        <div>
            <label>Secondary / accent color</label>
            <input type="color" name="secondary_color" value="<?= e(brand_colors()['secondary']) ?>" oninput="document.getElementById('secondarySwatch').style.background=this.value;document.getElementById('secondaryHex').textContent=this.value;">
        </div>
    </div>
    <p style="margin-top:10px;">
        <button type="button" class="btn btn-sm btn-secondary" onclick="resetBrandColors()">Reset colors to defaults</button>
    </p>

    <h3>Contact &amp; finance</h3>
    <label>Address</label>
    <textarea name="address" rows="2" maxlength="500"><?= e($company['address'] ?? '') ?></textarea>
    <div class="grid-2">
        <div><label>Phone</label><input type="text" name="phone" maxlength="50" value="<?= e($company['phone'] ?? '') ?>"></div>
        <div><label>Email</label><input type="email" name="email" maxlength="150" value="<?= e($company['email'] ?? '') ?>"></div>
        <div><label>Tax ID</label><input type="text" name="tax_id" maxlength="50" value="<?= e($company['tax_id'] ?? '') ?>"></div>
        <div>
            <label>Default commission %</label>
            <input type="number" step="0.01" min="0" max="100" name="default_commission_rate" value="<?= e($company['default_commission_rate'] ?? '0') ?>">
            <p class="hint">Used as the starting commission rate for sales staff.</p>
        </div>
        <div>
            <label>Currency code</label>
            <input type="text" name="currency" maxlength="10" pattern="[A-Za-z]{2,10}" value="<?= e($company['currency'] ?? 'ETB') ?>">
        </div>
        <div>
            <label>Currency symbol</label>
            <input type="text" name="currency_symbol" maxlength="8" value="<?= e($company['currency_symbol'] ?? 'Br') ?>">
        </div>
    </div>
    <button type="submit" class="btn btn-accent" style="margin-top:20px;"><?= t('save') ?></button>
</form>

<script>
function resetBrandColors() {
    var primary = document.querySelector('input[name="primary_color"]');
    var secondary = document.querySelector('input[name="secondary_color"]');
    primary.value = '#b8481b';
    secondary.value = '#2f7d4f';
    primary.dispatchEvent(new Event('input'));
    secondary.dispatchEvent(new Event('input'));
}
</script>

<?php require_once "../partials/footer.php"; ?>
