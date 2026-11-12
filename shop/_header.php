<?php
/**
 * Public shop layout - header. Expects:
 *   $shopTitle  - page <title>
 *   $shopActive - home|cart|track|account|login|register
 */
if (!defined('SHOP_PREFIX')) {
    require_once __DIR__ . '/bootstrap.php';
}
ensure_shop_schema();
$shopAccount = shop_current_account();
$shopCartCount = shop_cart_count();
$shopFlash = shop_get_flash();
$shopBrand = brand_colors();
$shopCompany = company_settings();
$shopLang = app_lang();
$shopToggleLang = $shopLang === 'am' ? 'en' : 'am';
$shopCurrentUrl = $_SERVER['REQUEST_URI'] ?? shop_url();
$shopLangUrl = preg_replace('/([?&])lang=[^&]*/', '${1}lang=' . $shopToggleLang, $shopCurrentUrl);
if ($shopLangUrl === $shopCurrentUrl) {
    $shopLangUrl .= (strpos($shopCurrentUrl, '?') === false ? '?' : '&') . 'lang=' . $shopToggleLang;
}
$shopActive = $shopActive ?? '';
?>
<!doctype html>
<html lang="<?= $shopLang === 'am' ? 'am' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($shopTitle ?? shop_t('Online Shop')) ?> — <?= e(company_display_name()) ?></title>
    <meta name="description" content="<?= e(shop_t('Order blocks online')) ?> — <?= e(company_display_name()) ?>">
    <style>
        :root {
            --p: <?= e($shopBrand['primary']) ?>;
            --s: <?= e($shopBrand['secondary']) ?>;
            --ink: #14262e;
            --muted: #62767e;
            --line: #dbe4e7;
            --bg: #f3f6f7;
            --card: #ffffff;
            --ok: #157f4a;
            --warn: #b45309;
            --danger: #b42318;
            --radius: 16px;
            --shadow: 0 10px 30px rgba(15, 45, 55, .08);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, "Noto Sans Ethiopic", sans-serif;
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--p); }
        img { max-width: 100%; }
        .wrap { width: min(1180px, calc(100% - 40px)); margin: 0 auto; }

        /* ---------- header ---------- */
        .shop-top {
            background: #fff;
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .shop-top-inner { display: flex; align-items: center; gap: 22px; min-height: 68px; }
        .shop-brand { display: flex; align-items: center; gap: 11px; text-decoration: none; color: var(--ink); font-weight: 800; font-size: 1.06rem; white-space: nowrap; }
        .shop-brand .mark {
            width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
            background: linear-gradient(145deg, var(--p), var(--s)); color: #fff; font-size: 1.05rem;
            box-shadow: 0 6px 14px color-mix(in srgb, var(--p) 35%, transparent);
        }
        .shop-brand img { max-height: 40px; max-width: 150px; border-radius: 8px; }
        .shop-nav { display: flex; align-items: center; gap: 4px; margin-left: auto; }
        .shop-nav a {
            text-decoration: none; color: #43565e; font-weight: 600; font-size: .92rem;
            padding: 9px 12px; border-radius: 10px; white-space: nowrap;
        }
        .shop-nav a:hover, .shop-nav a.active { color: var(--p); background: color-mix(in srgb, var(--p) 9%, #fff); }
        .cart-pill {
            display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
            background: var(--p); color: #fff !important; padding: 9px 14px !important; border-radius: 999px;
            font-weight: 700; font-size: .9rem;
        }
        .cart-pill .count {
            background: #fff; color: var(--p); border-radius: 999px; min-width: 21px; height: 21px;
            display: inline-grid; place-items: center; font-size: .76rem; padding: 0 6px;
        }
        .lang-switch { font-size: .82rem; font-weight: 700; text-decoration: none; border: 1px solid var(--line); border-radius: 999px; padding: 7px 12px; color: var(--muted); }
        .lang-switch:hover { border-color: var(--p); color: var(--p); }
        .shop-menu-toggle { display: none; }

        /* ---------- hero ---------- */
        .hero {
            position: relative; overflow: hidden; color: #fff;
            background: linear-gradient(120deg, var(--p) 0%, color-mix(in srgb, var(--p) 55%, var(--s)) 55%, var(--s) 100%);
        }
        .hero::after {
            content: ""; position: absolute; inset: 0; opacity: .16; pointer-events: none;
            background-image:
                linear-gradient(90deg, rgba(255,255,255,.65) 1px, transparent 1px),
                linear-gradient(rgba(255,255,255,.65) 1px, transparent 1px);
            background-size: 54px 26px;
            transform: skewY(-3deg) scale(1.15);
        }
        .hero-inner { position: relative; z-index: 1; padding: 58px 0 62px; display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr); gap: 32px; align-items: center; }
        .hero h1 { margin: 0 0 12px; font-size: clamp(1.8rem, 4vw, 3rem); line-height: 1.08; letter-spacing: -.01em; }
        .hero p { margin: 0; max-width: 60ch; font-size: 1.02rem; line-height: 1.65; opacity: .92; }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        .hero-card {
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28); border-radius: 18px;
            padding: 20px; backdrop-filter: blur(6px);
        }
        .hero-card h3 { margin: 0 0 12px; font-size: .82rem; text-transform: uppercase; letter-spacing: .08em; opacity: .8; }
        .hero-card ol { margin: 0; padding-left: 20px; display: grid; gap: 10px; font-size: .92rem; line-height: 1.45; }
        .hero-card li::marker { font-weight: 800; }
        .hero-card strong { display: block; }

        /* ---------- buttons / forms ---------- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            border: 0; border-radius: 11px; padding: 11px 18px; cursor: pointer;
            font: inherit; font-weight: 700; font-size: .93rem; text-decoration: none;
            transition: transform .15s, filter .15s, background .15s;
        }
        .btn:hover { transform: translateY(-1px); filter: brightness(1.05); }
        .btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        .btn-primary { background: linear-gradient(90deg, var(--p), var(--s)); color: #fff; box-shadow: 0 8px 18px color-mix(in srgb, var(--p) 28%, transparent); }
        .btn-light { background: #fff; color: var(--p); }
        .btn-outline { background: transparent; color: var(--p); border: 1.5px solid color-mix(in srgb, var(--p) 35%, #fff); }
        .btn-soft { background: color-mix(in srgb, var(--p) 10%, #fff); color: var(--p); }
        .btn-danger { background: #fdecec; color: var(--danger); }
        .btn-sm { padding: 8px 12px; font-size: .84rem; border-radius: 9px; }
        .btn-block { width: 100%; }
        .muted { color: var(--muted); }
        .small { font-size: .84rem; }

        label { display: block; font-weight: 650; font-size: .86rem; margin: 14px 0 6px; }
        input[type=text], input[type=email], input[type=tel], input[type=password], input[type=number], input[type=date], textarea, select {
            width: 100%; padding: 11px 12px; font: inherit; color: var(--ink);
            border: 1.5px solid var(--line); border-radius: 11px; background: #fbfcfc;
            transition: border-color .18s, box-shadow .18s, background .18s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none; border-color: var(--p); background: #fff;
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--p) 14%, transparent);
        }
        textarea { resize: vertical; min-height: 90px; }

        /* ---------- sections ---------- */
        .section { padding: 46px 0; }
        .section-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
        .section-head h2 { margin: 0; font-size: 1.5rem; }
        .section-head p { margin: 5px 0 0; color: var(--muted); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); }
        .card-pad { padding: 24px; }
        .grid-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 18px; }
        .product {
            display: flex; flex-direction: column; overflow: hidden;
            background: #fff; border: 1px solid var(--line); border-radius: var(--radius);
            box-shadow: 0 4px 18px rgba(15,45,55,.05);
            transition: transform .18s, box-shadow .18s;
        }
        .product:hover { transform: translateY(-3px); box-shadow: 0 14px 34px rgba(15,45,55,.12); }
        .product-art {
            height: 116px; display: grid; place-items: center; position: relative;
            background: linear-gradient(145deg, color-mix(in srgb, var(--p) 8%, #fff), color-mix(in srgb, var(--s) 12%, #fff));
            border-bottom: 1px solid var(--line);
        }
        .brick-wall { display: flex; flex-direction: column; gap: 4px; transform: rotate(-2deg); }
        .brick-row { display: flex; gap: 4px; }
        .brick-row.off { margin-left: 17px; }
        .brick { width: 34px; height: 15px; border-radius: 3px; background: linear-gradient(180deg, color-mix(in srgb, var(--p) 72%, #fff), var(--p)); box-shadow: inset 0 -3px 0 rgba(0,0,0,.13); }
        .brick:nth-child(2n) { background: linear-gradient(180deg, color-mix(in srgb, var(--s) 65%, #fff), var(--s)); }
        .product-body { padding: 18px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .product-name { margin: 0; font-size: 1.06rem; }
        .product-size { color: var(--muted); font-size: .86rem; }
        .product-price { font-size: 1.3rem; font-weight: 800; color: var(--p); }
        .product-price small { font-size: .72rem; font-weight: 600; color: var(--muted); }
        .stock-line { font-size: .8rem; font-weight: 650; color: var(--ok); }
        .stock-line.low { color: var(--warn); }
        .stock-line.out { color: var(--muted); }
        .product form { margin-top: auto; display: flex; gap: 8px; }
        .product form input[type=number] { width: 82px; padding: 9px; }
        .product form .btn { flex: 1; padding: 9px 12px; }

        .flash { border-radius: 12px; padding: 12px 15px; margin: 0 0 16px; font-size: .92rem; font-weight: 600; border: 1px solid; }
        .flash-success { background: #eafaf1; border-color: #b6e7cc; color: #146c43; }
        .flash-error { background: #fdecec; border-color: #f5c2c2; color: #8f1d1d; }
        .flash-info { background: #eef4ff; border-color: #c7d8ff; color: #1e3a8a; }

        /* ---------- cart / tables ---------- */
        .cart-layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(300px, .7fr); gap: 22px; align-items: start; }
        .table-wrap { overflow-x: auto; }
        table.shop-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        .shop-table th { text-align: left; font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); padding: 10px 12px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        .shop-table td { padding: 12px; border-bottom: 1px solid #eef2f3; vertical-align: middle; }
        .shop-table tr:last-child td { border-bottom: 0; }
        .shop-table .num { text-align: right; white-space: nowrap; }
        .qty-input { width: 82px; padding: 8px; text-align: center; }
        .summary-line { display: flex; justify-content: space-between; gap: 18px; padding: 11px 0; color: #43565e; border-bottom: 1px solid #eef2f3; font-size: .93rem; }
        .summary-line strong { color: var(--ink); text-align: right; }
        .summary-total { display: flex; justify-content: space-between; align-items: baseline; padding: 16px 0 4px; font-size: 1.35rem; font-weight: 800; color: var(--p); }
        .summary-note { color: var(--muted); font-size: .78rem; line-height: 1.5; margin: 10px 0 0; }
        .empty { text-align: center; padding: 54px 20px; }
        .empty .big { font-size: 2.6rem; margin-bottom: 10px; }

        /* ---------- checkout / auth ---------- */
        .two-col { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr); gap: 22px; align-items: start; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
        .form-grid .full { grid-column: 1 / -1; }
        .check { display: flex; align-items: flex-start; gap: 9px; margin-top: 14px; font-size: .9rem; color: #43565e; }
        .check input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--p); }
        .account-banner { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; background: color-mix(in srgb, var(--p) 7%, #fff); border: 1px solid color-mix(in srgb, var(--p) 18%, #fff); border-radius: 12px; padding: 12px 14px; font-size: .9rem; margin-bottom: 18px; }
        .auth-card { max-width: 520px; margin: 40px auto; }
        .auth-card h1 { margin: 0 0 6px; font-size: 1.7rem; }
        .auth-card .sub { margin: 0 0 12px; color: var(--muted); }
        .auth-alt { margin-top: 18px; text-align: center; font-size: .9rem; color: var(--muted); }

        /* ---------- order status ---------- */
        .badge-status { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 10px; font-size: .76rem; font-weight: 750; white-space: nowrap; }
        .badge-status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .badge-status.pending { background: #fff7e6; color: #a15c00; }
        .badge-status.confirmed { background: #eef4ff; color: #1e50a2; }
        .badge-status.ready { background: #e8f7f4; color: #0c6b5f; }
        .badge-status.completed { background: #eafaf1; color: #146c43; }
        .badge-status.cancelled { background: #fdecec; color: #8f1d1d; }
        .timeline { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        .timeline li { display: flex; gap: 12px; font-size: .9rem; }
        .timeline .dot { flex: none; width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; background: var(--line); }
        .timeline li.done .dot { background: var(--ok); }
        .timeline li.now .dot { background: var(--warn); box-shadow: 0 0 0 4px #fff3d6; }
        .timeline strong { display: block; }

        .kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
        .kpi { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--p); border-radius: 12px; padding: 14px 16px; box-shadow: 0 4px 14px rgba(15,23,42,.04); }
        .kpi span { display: block; color: var(--muted); font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; }
        .kpi strong { display: block; margin-top: 4px; font-size: 1.4rem; }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .filters .field { min-width: 160px; }
        .filters label { margin-top: 0; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }

        .shop-foot { background: #10262e; color: #c8d6db; margin-top: 50px; padding: 34px 0; font-size: .88rem; }
        .shop-foot-inner { display: flex; flex-wrap: wrap; gap: 22px; justify-content: space-between; align-items: center; }
        .shop-foot a { color: #fff; text-decoration: none; }
        .shop-foot strong { color: #fff; display: block; margin-bottom: 5px; }

        @media (max-width: 900px) {
            .hero-inner, .cart-layout, .two-col { grid-template-columns: 1fr; }
            .hero-card { display: none; }
            .shop-nav { gap: 0; }
            .shop-nav a { padding: 8px 9px; font-size: .86rem; }
            .shop-nav .nav-label { display: none; }
            .kpi-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 680px) {
            .wrap { width: calc(100% - 26px); }
            .shop-top-inner { min-height: 60px; gap: 10px; }
            .shop-brand span.name { display: none; }
            .shop-brand img { max-width: 92px; }
            .hero-inner { padding: 40px 0 44px; }
            .section { padding: 34px 0; }
            .form-grid { grid-template-columns: 1fr; }
            .card-pad { padding: 18px; }
            .grid-products { grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
            .shop-table th:nth-child(2), .shop-table td:nth-child(2) { display: none; }
            .shop-table td, .shop-table th { padding: 9px 8px; }
            .kpi-row { gap: 10px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<header class="shop-top">
    <div class="wrap shop-top-inner">
        <a class="shop-brand" href="<?= e(shop_url('index.php')) ?>">
            <?php if (!empty($shopCompany['logo_path'])): ?>
            <img src="<?= e($shopCompany['logo_path']) ?>" alt="<?= e(company_display_name()) ?>">
            <?php else: ?>
            <span class="mark" aria-hidden="true">🧱</span>
            <?php endif; ?>
            <span class="name"><?= e(company_display_name()) ?></span>
        </a>
        <nav class="shop-nav" aria-label="<?= e(shop_t('Shop')) ?>">
            <a href="<?= e(shop_url('index.php')) ?>" class="<?= $shopActive === 'home' ? 'active' : '' ?>"><span class="nav-label"><?= e(shop_t('Shop')) ?></span></a>
            <a href="<?= e(shop_url('track.php')) ?>" class="<?= $shopActive === 'track' ? 'active' : '' ?>"><span class="nav-label"><?= e(shop_t('Track')) ?></span></a>
            <?php if ($shopAccount): ?>
            <a href="<?= e(shop_url('account.php')) ?>" class="<?= $shopActive === 'account' ? 'active' : '' ?>"><span class="nav-label"><?= e(shop_t('My orders')) ?></span></a>
            <?php else: ?>
            <a href="<?= e(shop_url('login.php')) ?>" class="<?= $shopActive === 'login' ? 'active' : '' ?>"><span class="nav-label"><?= e(shop_t('Sign in')) ?></span></a>
            <?php endif; ?>
            <a href="<?= e($shopLangUrl) ?>" class="lang-switch" title="<?= e(shop_t('Language')) ?>"><?= $shopLang === 'am' ? 'EN' : 'አማ' ?></a>
            <a class="cart-pill" href="<?= e(shop_url('cart.php')) ?>">
                <span aria-hidden="true">🛒</span>
                <span class="nav-label"><?= e(shop_t('Cart')) ?></span>
                <?php if ($shopCartCount > 0): ?><span class="count"><?= number_format($shopCartCount) ?></span><?php endif; ?>
            </a>
        </nav>
    </div>
</header>
<main>
    <?php if ($shopFlash): ?>
    <div class="wrap" style="padding-top:18px;">
        <div class="flash flash-<?= e($shopFlash['type']) ?>"><?= e($shopFlash['message']) ?></div>
    </div>
    <?php endif; ?>
