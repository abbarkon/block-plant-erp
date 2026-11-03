<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (!isset($_SESSION['pending_2fa_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = '';
$co = company_settings();
$brand = brand_colors();
$lang = app_lang();

if ($_SERVER["REQUEST_METHOD"] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $userId = $_SESSION['pending_2fa_user_id'];
    
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ? AND two_factor_code = ? AND two_factor_expires_at > NOW()");
    $s->execute([$userId, $code]);
    $u = $s->fetch();
    
    if ($u) {
        // Code is correct, clear it and login
        $pdo->prepare("UPDATE users SET two_factor_code = NULL, two_factor_expires_at = NULL WHERE id = ?")->execute([$userId]);
        
        $_SESSION["user_id"] = $u["id"];
        $_SESSION["user_name"] = $u["name"];
        $_SESSION["role"] = $u["role"];
        unset($_SESSION['pending_2fa_user_id']);
        
        header("Location: ../dashboard/");
        exit;
    } else {
        $error = 'Invalid or expired code.';
    }
}
?>
<!doctype html>
<html lang="<?= $lang === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars(company_display_name()) ?> — <?= t('verify_2fa') ?></title>
    <style>
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: linear-gradient(135deg, <?= htmlspecialchars($brand['primary']) ?> 0%, <?= htmlspecialchars($brand['secondary']) ?> 100%);
        }
        .wrap {
            max-width: 400px; width: 92%; background: #fff;
            border-radius: 16px; padding: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            text-align: center;
        }
        h2 { margin: 0 0 16px; }
        p { color: #64748b; font-size: 0.95rem; margin-bottom: 24px; }
        input {
            width: 100%; padding: 12px; margin-bottom: 16px;
            border: 1px solid #e2e8f0; border-radius: 8px; box-sizing: border-box;
            font-size: 1.5rem; text-align: center; letter-spacing: 4px;
        }
        button {
            width: 100%; padding: 14px;
            background: linear-gradient(90deg, <?= htmlspecialchars($brand['primary']) ?>, <?= htmlspecialchars($brand['secondary']) ?>);
            color: #fff; border: 0; border-radius: 8px; font-weight: 600; cursor: pointer;
        }
        .err { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; }
        a { display: block; margin-top: 16px; color: #64748b; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2><?= t('verify_2fa') ?></h2>
        <p><?= t('code_sent') ?></p>
        <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="text" name="code" required autocomplete="one-time-code" maxlength="6" placeholder="000000">
            <button type="submit"><?= t('verify') ?></button>
        </form>
        <a href="login.php">Back to Login</a>
    </div>
</body>
</html>
