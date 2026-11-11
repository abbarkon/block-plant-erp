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
        :root {
            --p: <?= htmlspecialchars($brand['primary']) ?>;
            --s: <?= htmlspecialchars($brand['secondary']) ?>;
            --ink: #14262e;
            --muted: #62767e;
            --line: #dbe4e7;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, "Noto Sans Ethiopic", sans-serif;
            -webkit-font-smoothing: antialiased;
            color: var(--ink);
            background: #eef3f4;
            display: grid;
            place-items: center;
            padding: 20px;
        }
        .shell {
            width: 100%;
            max-width: 980px;
            min-height: 580px;
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            background: #fff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(15, 45, 55, .22);
            animation: rise .6s cubic-bezier(.22, 1, .36, 1) both;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }

        /* ---- Brand panel with block wall ---- */
        .brand {
            position: relative;
            padding: 44px 40px;
            color: #fff;
            background: linear-gradient(155deg, var(--p), var(--s));
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }
        .brand::after {
            content: "";
            position: absolute; inset: auto -60px -80px auto;
            width: 280px; height: 280px; border-radius: 50%;
            background: rgba(255, 255, 255, .07);
        }
        .logo { max-height: 54px; max-width: 160px; border-radius: 10px; margin-bottom: 16px; background: rgba(255,255,255,.12); padding: 4px; }
        .brand h1 { margin: 0 0 8px; font-size: 1.9rem; line-height: 1.15; letter-spacing: .01em; }
        .brand .tag { margin: 0; opacity: .9; line-height: 1.55; max-width: 34ch; }
        .wall { display: flex; flex-direction: column; gap: 5px; margin: 26px 0; position: relative; z-index: 1; }
        .course { display: flex; gap: 5px; }
        .course.off { margin-left: 26px; }
        .brick {
            width: 50px; height: 24px; border-radius: 4px;
            background: #b4b9ff;
            box-shadow: inset 0 -4px 0 rgba(0, 0, 0, .16);
            opacity: 0; transform: translateY(-40px);
            animation: lay .5s cubic-bezier(.3, 1.4, .5, 1) forwards;
            animation-delay: calc(var(--i) * 55ms + .35s);
        }
        .brick:nth-child(3n) { background: #9a88ee; }
        .brick:nth-child(3n+1) { background: #d3d6ff; }
        @keyframes lay { to { opacity: 1; transform: none; } }
        .points { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; position: relative; z-index: 1; }
        .points li { display: flex; align-items: center; gap: 10px; font-size: .92rem; opacity: .95; }
        .points li::before {
            content: ""; flex: none; width: 18px; height: 18px; border-radius: 5px;
            background: rgba(255,255,255,.2) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12l5 5 9-10'/%3E%3C/svg%3E") center/12px no-repeat;
        }

        /* ---- Form panel ---- */
        .panel { padding: 44px 44px 32px; display: flex; flex-direction: column; justify-content: center; }
        .lang { display: inline-flex; align-self: flex-end; background: #eef3f4; border-radius: 999px; padding: 3px; margin-bottom: 26px; }
        .lang a { text-decoration: none; font-size: .8rem; font-weight: 600; color: var(--muted); padding: 6px 14px; border-radius: 999px; }
        .lang a.active { background: #fff; color: var(--p); box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .panel h2 { margin: 0 0 6px; font-size: 1.7rem; }
        .sub { margin: 0 0 22px; color: var(--muted); }
        .err {
            background: #fdecec; color: #8f1d1d; border: 1px solid #f5c2c2;
            padding: 11px 14px; border-radius: 12px; margin-bottom: 14px; font-size: .92rem;
            animation: shake .4s ease;
        }
        @keyframes shake { 20%,60% { transform: translateX(-6px); } 40%,80% { transform: translateX(6px); } }
        label { display: block; font-weight: 600; font-size: .85rem; margin: 16px 0 6px; }
        .field { position: relative; }
        .field svg.ic { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; stroke: var(--muted); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
        input {
            width: 100%; padding: 13px 44px 13px 42px; font-size: 1rem; color: var(--ink);
            border: 1.5px solid var(--line); border-radius: 12px; background: #f9fbfb;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        input:focus { outline: none; border-color: var(--p); background: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--p) 18%, transparent); }
        .eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 38px; height: 38px; border: 0; background: transparent; cursor: pointer; border-radius: 10px; display: grid; place-items: center; }
        .eye:hover { background: #eef3f4; }
        .eye svg { width: 20px; height: 20px; stroke: var(--muted); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .go {
            width: 100%; margin-top: 24px; padding: 14px; border: 0; border-radius: 12px; cursor: pointer;
            font-size: 1rem; font-weight: 700; color: #fff;
            background: linear-gradient(90deg, var(--p), var(--s));
            box-shadow: 0 12px 26px color-mix(in srgb, var(--p) 35%, transparent);
            transition: transform .18s, filter .18s;
        }
        .go:hover { transform: translateY(-2px); filter: brightness(1.07); }
        .go:disabled { opacity: .75; cursor: progress; transform: none; }
        .foot { margin-top: 26px; text-align: center; color: var(--muted); font-size: .78rem; }

        @media (max-width: 760px) {
            body { padding: 0; place-items: stretch; background: #fff; }
            .shell { grid-template-columns: 1fr; min-height: 100vh; border-radius: 0; box-shadow: none; }
            .brand { padding: 28px 24px 22px; }
            .brand h1 { font-size: 1.5rem; }
            .wall, .points { display: none; }
            .panel { padding: 26px 24px 28px; justify-content: flex-start; }
        }
        @media (prefers-reduced-motion: reduce) { .shell, .brick, .err { animation: none; opacity: 1; transform: none; } }

        /* ---- Dark mode (saved choice, or the device setting) ---- */
        body.dark-theme { --ink: #eef0ff; --muted: #9aa1c9; --line: #3a4272; background: #111429; color: #d3d7f0; color-scheme: dark; }
        body.dark-theme .shell { background: #1a1f3d; box-shadow: 0 30px 80px rgba(0,0,0,.55); }
        body.dark-theme .panel h2 { color: #eef0ff; }
        body.dark-theme label { color: #d3d7f0; }
        body.dark-theme input { background: #141836; color: #eef0ff; }
        body.dark-theme input:focus { background: #181d42; }
        body.dark-theme .eye:hover { background: #262d5c; }
        body.dark-theme .eye svg, body.dark-theme .field svg.ic { stroke: #9aa1c9; }
        body.dark-theme .lang { background: #141836; }
        body.dark-theme .lang a.active { background: #262d5c; color: #fff; box-shadow: none; }
        body.dark-theme .err { background: #40202a; color: #ffb3bf; border-color: #6a2d3a; }
        @media (max-width: 760px) { body.dark-theme { background: #1a1f3d; } }
        .theme-btn {
            position: fixed; top: 14px; right: 14px; z-index: 10;
            width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line);
            background: #fff; cursor: pointer; font-size: 1.1rem; line-height: 1;
            box-shadow: 0 6px 18px rgba(28, 34, 80, .18);
        }
        body.dark-theme .theme-btn { background: #262d5c; border-color: #3a4272; }
        .pk-or { display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: .8rem; margin: 18px 0 12px; }
        .pk-or::before, .pk-or::after { content: ""; flex: 1; height: 1px; background: var(--line); }
        .pk-btn {
            width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px;
            padding: 13px; border-radius: 12px; cursor: pointer; font-size: 1rem; font-weight: 700;
            border: 1.5px solid var(--p); color: var(--p); background: transparent;
            transition: background .18s;
        }
        .pk-btn:hover { background: color-mix(in srgb, var(--p) 10%, transparent); }
        .pk-btn:disabled { opacity: .6; cursor: progress; }
    </style>
</head>
<body>
    <script>
        try {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.body.classList.add('dark-theme');
            }
        } catch (e) {}
    </script>
    <button type="button" class="theme-btn" id="themeBtn" aria-label="Dark mode" title="Dark mode">🌙</button>
    <main class="shell">
        <section class="brand">
            <div>
                <?php if (!empty($co['logo_path'])): ?>
                <img class="logo" src="<?= htmlspecialchars($co['logo_path']) ?>" alt="">
                <?php endif; ?>
                <h1><?= htmlspecialchars(company_display_name()) ?></h1>
                <?php if (company_tagline()): ?><p class="tag"><?= htmlspecialchars(company_tagline()) ?></p><?php endif; ?>
            </div>
            <div class="wall" aria-hidden="true">
                <?php $n = 0; for ($c = 3; $c >= 0; $c--): $cnt = ($c % 2) ? 4 : 5; ?>
                <div class="course<?= $c % 2 ? ' off' : '' ?>">
                    <?php for ($k = 0; $k < $cnt; $k++): ?><span class="brick" style="--i:<?= $n++ ?>"></span><?php endfor; ?>
                </div>
                <?php endfor; ?>
            </div>
            <ul class="points">
                <li><?= $lang === 'am' ? 'የምርት እና የክምችት ቁጥጥር' : 'Production & stock control' ?></li>
                <li><?= $lang === 'am' ? 'ሽያጭ፣ ደንበኞች እና ርክክብ' : 'Sales, customers & deliveries' ?></li>
                <li><?= $lang === 'am' ? 'ፋይናንስ እና ሪፖርቶች በአንድ ቦታ' : 'Finance & reports in one place' ?></li>
            </ul>
        </section>

        <section class="panel">
            <div class="lang">
                <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">English</a>
                <a href="?lang=am" class="<?= $lang === 'am' ? 'active' : '' ?>">አማርኛ</a>
            </div>
            <h2><?= t('sign_in') ?></h2>
            <p class="sub"><?= t('login_hint') ?></p>
            <?php if ($error): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" id="loginForm">
                <label for="u"><?= t('username') ?></label>
                <div class="field">
                    <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
                    <input id="u" name="username" value="" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
                </div>
                <label for="pw"><?= t('password') ?></label>
                <div class="field">
                    <svg class="ic" viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input id="pw" type="password" name="password" required autocomplete="current-password">
                    <button type="button" class="eye" id="eye" aria-label="Show password">
                        <svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <button type="submit" class="go" id="go"><?= t('sign_in') ?></button>
            </form>
            <div id="pkBox" style="display:none;">
                <div class="pk-or"><span><?= $lang === 'am' ? 'ወይም' : 'or' ?></span></div>
                <button type="button" class="pk-btn" id="pkBtn">
                    <span style="font-size:1.25rem;">🔐</span>
                    <span><?= $lang === 'am' ? 'በጣት አሻራ ወይም በፊት ግባ' : 'Sign in with fingerprint / face' ?></span>
                </button>
                <div class="err" id="pkErr" style="display:none;margin-top:10px;"></div>
            </div>
            <div class="foot">© <?= date('Y') ?> <?= htmlspecialchars(company_display_name()) ?></div>
        </section>
    </main>
    <script>
        (function () {
            if (!window.PublicKeyCredential || !navigator.credentials) return;
            var box = document.getElementById('pkBox'), btn = document.getElementById('pkBtn'), err = document.getElementById('pkErr');
            box.style.display = '';
            function b64d(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var bin = atob(s), a = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a.buffer; }
            function b64e(buf) { var a = new Uint8Array(buf), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
            function fail(m) { err.textContent = m; err.style.display = ''; btn.disabled = false; }
            function api(action, body) {
                return fetch('passkey_api.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) })
                    .then(function (r) { return r.json(); });
            }
            btn.addEventListener('click', function () {
                err.style.display = 'none'; btn.disabled = true;
                api('login_options').then(function (o) {
                    if (o.error) throw new Error(o.error);
                    o.challenge = b64d(o.challenge);
                    return navigator.credentials.get({ publicKey: o });
                }).then(function (cred) {
                    return api('login_finish', {
                        id: b64e(cred.rawId),
                        clientDataJSON: b64e(cred.response.clientDataJSON),
                        authenticatorData: b64e(cred.response.authenticatorData),
                        signature: b64e(cred.response.signature)
                    });
                }).then(function (res) {
                    if (res.error) throw new Error(res.error);
                    window.location.href = res.redirect || '../dashboard/';
                }).catch(function (e) {
                    fail(e && e.name === 'NotAllowedError' ? 'Cancelled or not recognised. Try again or use your password.' : (e.message || 'Could not sign in.'));
                });
            });
        })();
    </script>
    <script>
        (function () {
            var b = document.getElementById('themeBtn');
            function icon() { b.textContent = document.body.classList.contains('dark-theme') ? '☀️' : '🌙'; }
            icon();
            b.addEventListener('click', function () {
                document.body.classList.toggle('dark-theme');
                try { localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light'); } catch (e) {}
                icon();
            });
        })();
    </script>
    <script>
        (function () {
            var pw = document.getElementById('pw'), eye = document.getElementById('eye');
            eye.addEventListener('click', function () {
                var show = pw.type === 'password';
                pw.type = show ? 'text' : 'password';
                eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
            document.getElementById('loginForm').addEventListener('submit', function () {
                var b = document.getElementById('go');
                b.disabled = true; b.textContent = '…';
            });
        })();
    </script>
</body>
</html>
