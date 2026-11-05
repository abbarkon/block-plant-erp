<?php
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

if (isset($_SESSION["user_id"])) {
    header("Location: ../dashboard/");
    exit;
}

$co = company_settings();
$brand = brand_colors();
$error = '';

if ($_SERVER["REQUEST_METHOD"] === 'POST') {
    $s = $pdo->prepare("SELECT * FROM users WHERE username=? AND status=1");
    $s->execute([trim($_POST["username"] ?? '')]);
    $u = $s->fetch();
    if ($u && password_verify($_POST["password"] ?? '', $u["password"])) {
        $_SESSION["user_id"] = $u["id"];
        $_SESSION["user_name"] = $u["name"];
        $_SESSION["role"] = strtolower(trim((string) $u["role"]));
        $_SESSION["just_logged_in"] = true;
        $landingPages = [
            'dashboard' => '../dashboard/',
            'sales' => '../sales/',
            'crm' => '../crm/pipeline.php',
            'customers' => '../customers/',
            'inventory' => '../inventory/',
            'production' => '../production/',
            'purchases' => '../purchases/',
            'expenses' => '../expenses/',
            'assets' => '../assets/',
            'suppliers' => '../suppliers/',
            'hr' => '../hr/',
            'finance' => '../finance/accounts.php',
            'reports' => '../reports/financial.php',
            'settings' => '../settings/profile.php',
        ];
        $landingUrl = '../auth/login.php';
        foreach ($landingPages as $module => $page) {
            if (has_permission($module, 'view')) {
                $landingUrl = $page;
                break;
            }
        }
        header("Location: {$landingUrl}");
        exit;
    }
    $error = 'Invalid username or password.';
}

$lang = app_lang();
?>
<!doctype html>
<html lang="<?= $lang === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars(company_display_name()) ?> — Login</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            place-items: safe center;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            background: linear-gradient(135deg, <?= htmlspecialchars($brand['primary']) ?> 0%, <?= htmlspecialchars($brand['secondary']) ?> 100%);
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }
        /* Animated aurora orbs */
        body::before, body::after {
            content: "";
            position: fixed;
            border-radius: 50%;
            filter: blur(60px);
            opacity: .55;
            z-index: 0;
            animation: orb-float 14s ease-in-out infinite;
        }
        body::before { width: 460px; height: 460px; background: #2a9d8f; top: -120px; left: -80px; }
        body::after { width: 420px; height: 420px; background: #e76f51; bottom: -140px; right: -60px; animation-delay: -7s; }
        @keyframes orb-float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(40px, 30px) scale(1.12); }
        }
        .login-wrap {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 940px;
            width: 92%;
            background: rgba(255, 255, 255, 0.72);
            -webkit-backdrop-filter: blur(26px) saturate(180%);
            backdrop-filter: blur(26px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.55);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 30px 70px rgba(9, 26, 33, 0.38);
            animation: card-in .55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        @keyframes card-in { from { opacity: 0; transform: translateY(22px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .login-brand {
            background: linear-gradient(160deg, rgba(24, 59, 86, 0.92), rgba(36, 106, 115, 0.88));
            color: #fff;
            padding: 48px 36px;
        }
        .login-brand h1 { margin: 0 0 8px; font-size: 1.6rem; }
        .login-brand .tag { opacity: 0.9; margin-bottom: 20px; line-height: 1.5; }
        .login-brand ul { margin: 0; padding-left: 18px; opacity: 0.92; font-size: 0.9rem; line-height: 1.9; }
        .login-brand img { max-height: 56px; margin-bottom: 12px; border-radius: 8px; }
        .login-form { padding: 48px 36px; }
        .login-form h2 { margin: 0 0 8px; color: #183b56; }
        .lang { margin-bottom: 16px; font-size: 0.85rem; }
        .lang a { color: <?= htmlspecialchars($brand['primary']) ?>; margin-right: 10px; text-decoration: none; font-weight: 600; }
        .lang a.active { font-weight: bold; text-decoration: underline; }
        label { display: block; font-weight: 600; margin-top: 14px; font-size: 0.85rem; color: #19323c; }
        input {
            width: 100%;
            padding: 12px 14px;
            margin-top: 6px;
            border: 1px solid rgba(24, 59, 86, 0.16);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.7);
            font-size: 1rem;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        input:focus {
            outline: none;
            border-color: #2a9d8f;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(42, 157, 143, 0.22);
        }
        button {
            width: 100%;
            padding: 14px;
            margin-top: 24px;
            background: linear-gradient(90deg, <?= htmlspecialchars($brand['primary']) ?>, <?= htmlspecialchars($brand['secondary']) ?>);
            color: #fff;
            border: 0;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(24, 59, 86, 0.28);
            transition: transform .18s cubic-bezier(0.22, 1, 0.36, 1), filter .18s ease, box-shadow .18s ease;
        }
        button:hover { transform: translateY(-2px); filter: brightness(1.07); box-shadow: 0 14px 30px rgba(24, 59, 86, 0.34); }
        button:active { transform: translateY(0); }
        .err { background: rgba(254, 226, 226, 0.9); color: #991b1b; padding: 11px 14px; border-radius: 10px; margin-bottom: 12px; border: 1px solid rgba(153, 27, 27, 0.2); }
        .hint { font-size: 0.8rem; color: #64748b; margin-top: 16px; }
        @media (max-width: 700px) { .login-wrap { grid-template-columns: 1fr; } .login-brand { padding: 32px 28px; } .login-form { padding: 32px 28px; } body { padding: 16px 0; } }
        @media (prefers-reduced-motion: reduce) { body::before, body::after, .login-wrap { animation: none; } }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-brand">
            <?php if (!empty($co['logo_path'])): ?>
            <img src="<?= htmlspecialchars($co['logo_path']) ?>" alt="">
            <?php endif; ?>
            <h1><?= htmlspecialchars(company_display_name()) ?></h1>
            <?php if (company_tagline()): ?><p class="tag"><?= htmlspecialchars(company_tagline()) ?></p><?php endif; ?>
            <ul>
                <li><?= $lang === 'am' ? 'ቀጥተኛ ዳሽቦርድ እና Chart.js ትንታኔ' : 'Live dashboard & Chart.js analytics' ?></li>
                <li><?= $lang === 'am' ? 'መላኪያ፣ ኮሚሽን እና ግብረመልስ' : 'Delivery notes, commissions & feedback' ?></li>
                <li><?= $lang === 'am' ? 'EN / አማርኛ ብራንዲንግ' : 'English & Amharic branding' ?></li>
            </ul>
        </div>
        <div class="login-form">
            <div class="lang">
                <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">English</a>
                <a href="?lang=am" class="<?= $lang === 'am' ? 'active' : '' ?>">አማርኛ</a>
            </div>
            <h2><?= t('sign_in') ?></h2>
            <p style="color:#64748b;margin:0 0 16px;"><?= t('login_hint') ?></p>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <label><?= t('username') ?></label>
                <input name="username" required autocomplete="username" placeholder="Username">
                <label><?= t('password') ?></label>
                <input type="password" name="password" required autocomplete="current-password">
                <button type="submit"><?= t('sign_in') ?></button>
            </form>
            <p class="hint"><?= t('default_login_hint') ?></p>
        </div>
    </div>
</body>
</html>
