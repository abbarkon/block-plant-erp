<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/helpers.php";

$stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION["user_id"]]);
$user = $stmt->fetch();
$brand = brand_colors();
$co = company_settings();
$htmlLang = app_lang() === 'am' ? 'am' : 'en';

// Determine if this user is an admin (used throughout the navbar)
$_navIsAdmin = in_array(strtolower(trim((string)($user['role'] ?? ''))), ['admin', 'administrator'], true);
$_SESSION['role'] = $user['role'] ?? '';

// Smart Task Notifications
$hasTaskTable = $pdo->query("SHOW TABLES LIKE 'task_reminders'")->rowCount() > 0;
$pendingTasksCount = 0;
$pendingTasksList = [];
if ($hasTaskTable) {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM task_reminders WHERE (assigned_to = ? OR assigned_to IS NULL) AND (status != 'done' OR status IS NULL OR status = '')");
    $notifStmt->execute([$_SESSION['user_id']]);
    $pendingTasksCount = $notifStmt->fetchColumn();
    
    $notifListStmt = $pdo->prepare("SELECT id, title, due_date, priority FROM task_reminders WHERE (assigned_to = ? OR assigned_to IS NULL) AND (status != 'done' OR status IS NULL OR status = '') ORDER BY due_date IS NULL, due_date ASC LIMIT 5");
    $notifListStmt->execute([$_SESSION['user_id']]);
    $pendingTasksList = $notifListStmt->fetchAll();
}

// System Notifications (in-app inbox). Count only here; the inbox page owns read-state.
$hasSysNotifTable = $pdo->query("SHOW TABLES LIKE 'user_notifications'")->rowCount() > 0;
$sysUnreadCount = 0;
$sysNotifications = [];
$sysToastNotifications = [];
if ($hasSysNotifTable) {
    $sysUnreadCount = unread_notification_count($_SESSION['user_id']);
    $sysStmt = $pdo->prepare("SELECT id, message, link, created_at FROM user_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 6");
    $sysStmt->execute([$_SESSION['user_id']]);
    $sysNotifications = $sysStmt->fetchAll();

    // Pop each unread notification as a toast only once per session so it does not
    // re-appear on every page load. The bell/inbox still shows all unread items.
    $toasted = $_SESSION['toasted_notifs'] ?? [];
    foreach ($sysNotifications as $sn) {
        if (!in_array((int) $sn['id'], $toasted, true)) {
            $sysToastNotifications[] = $sn;
            $toasted[] = (int) $sn['id'];
        }
    }
    // Keep only the most recent 50 ids to avoid unbounded session growth.
    $_SESSION['toasted_notifs'] = array_slice(array_values(array_unique($toasted)), -50);
}
?>
<!doctype html>
<html lang="<?= $htmlLang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $pageTitle ?? 'Block Plant ERP' ?></title>
    <link rel="stylesheet" href="/block_plant/assets/css/style.css">
    <link rel="stylesheet" href="/block_plant/assets/css/advanced.css?v=<?= filemtime(__DIR__ . '/../assets/css/advanced.css') ?>">
    <link rel="stylesheet" href="/block_plant/assets/css/dark-mode.css">
    <link rel="stylesheet" href="/block_plant/assets/css/glass.css?v=<?= filemtime(__DIR__ . '/../assets/css/glass.css') ?>">
    <link rel="stylesheet" href="/block_plant/assets/css/mobile.css?v=<?= filemtime(__DIR__ . '/../assets/css/mobile.css') ?>">
    <style>
        :root {
            --bp-primary: <?= htmlspecialchars($brand['primary']) ?>;
            --bp-primary-light: <?= htmlspecialchars($brand['primary']) ?>;
            --bp-accent: <?= htmlspecialchars($brand['secondary']) ?>;
        }
        .topbar {
            background: linear-gradient(90deg, <?= htmlspecialchars($brand['primary']) ?> 0%, <?= htmlspecialchars($brand['secondary']) ?> 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 200;
        }
        .topbar .logo {
            font-size: 20px;
            font-weight: bold;
        }
        .topbar .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .navbar {
            background: #34495e;
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .navbar ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
        }
        .navbar li {
            position: relative;
        }
        .navbar a {
            display: block;
            color: white;
            padding: 12px 18px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .navbar a:hover, .navbar a.active {
            background: #2c3e50;
        }
        .navbar .dropdown {
            position: relative;
        }
        .navbar .dropdown-menu {
            display: none;
            position: absolute;
            background: #2c3e50;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
        }
        .navbar .dropdown:hover .dropdown-menu {
            display: block;
        }
        /* Click/tap also opens a group, at every width. Needed on phones and
           tablets where hover does not exist, and when the window is narrow. */
        .navbar .dropdown.open > .dropdown-menu {
            display: block;
        }
        .navbar .dropdown-menu a {
            padding: 10px 15px;
            border-bottom: 1px solid #34495e;
        }
        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }
        @media (max-width: 768px) {
            .navbar ul {
                flex-direction: column;
            }
            .navbar .dropdown-menu {
                position: static;
                display: none;
                box-shadow: none;
            }
            .navbar .dropdown.open .dropdown-menu {
                display: block;
            }
        }
        
        /* Reduce chart sizes globally */
        .panel canvas {
            max-height: 140px !important;
        }
        @page { margin: 14mm; }
        @media print {
            .topbar,
            .navbar,
            footer,
            .header-actions,
            .no-print,
            button,
            .btn,
            .fab-wrap,
            .cmdk-overlay,
            .kbd-overlay,
            .notification-dropdown,
            #themeToggle,
            #cmdkOpen {
                display: none !important;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            html, body,
            body.theme-advanced,
            body.dark-theme {
                background: #fff !important;
                color: #000 !important;
                box-shadow: none !important;
            }
            body::before, body::after { display: none !important; }
            .container {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .page-header {
                margin-top: 0 !important;
                border-bottom: 2px solid #000 !important;
                padding-bottom: 6px !important;
            }
            a {
                color: inherit !important;
                text-decoration: none !important;
            }
            .card, .panel, .table-container, .stat-card,
            .dashboard-cards .card, .dashboard-data-panel,
            tr, thead, tfoot, img {
                box-shadow: none !important;
                backdrop-filter: none !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
            table { border-collapse: collapse !important; }
            .data-table th, .data-table td,
            table th, table td {
                border: 1px solid #ccc !important;
            }
            h1, h2, h3 {
                background: none !important;
                -webkit-text-fill-color: currentColor !important;
                color: #000 !important;
                page-break-after: avoid;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btn = document.getElementById('themeToggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark-theme');
            }
            if (btn) {
                btn.addEventListener('click', () => {
                    document.body.classList.toggle('dark-theme');
                    localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light');
                });
            }

            // Notification Dropdown logic
            const notifToggle = document.getElementById('notifToggle');
            const notifMenu = document.getElementById('notifMenu');
            if (notifToggle && notifMenu) {
                notifToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notifMenu.style.display = notifMenu.style.display === 'none' || notifMenu.style.display === '' ? 'block' : 'none';
                    const sysMenu = document.getElementById('sysNotifMenu');
                    if (sysMenu) sysMenu.style.display = 'none';
                });
                document.addEventListener('click', (e) => {
                    if (!notifToggle.contains(e.target) && !notifMenu.contains(e.target)) {
                        notifMenu.style.display = 'none';
                    }
                });
            }

            // System Notifications Dropdown logic
            const sysNotifToggle = document.getElementById('sysNotifToggle');
            const sysNotifMenu = document.getElementById('sysNotifMenu');
            if (sysNotifToggle && sysNotifMenu) {
                sysNotifToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sysNotifMenu.style.display = sysNotifMenu.style.display === 'none' || sysNotifMenu.style.display === '' ? 'block' : 'none';
                    if (notifMenu) notifMenu.style.display = 'none';
                });
                document.addEventListener('click', (e) => {
                    if (!sysNotifToggle.contains(e.target) && !sysNotifMenu.contains(e.target)) {
                        sysNotifMenu.style.display = 'none';
                    }
                });
            }
        });
    </script>
</head>
<body class="theme-advanced">
    <div class="topbar">
        <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="<?= e(t('toggle_navigation')) ?>" aria-controls="appSidebar" aria-expanded="false">☰</button>
        <div class="logo" style="display:flex;align-items:center;gap:10px;">
            <?php
            $logoFile = !empty($co['logo_path']) ? dirname(__DIR__) . preg_replace('#^/block_plant#', '', $co['logo_path']) : '';
            if ($logoFile && is_file($logoFile)):
            ?>
            <img src="<?= htmlspecialchars($co['logo_path']) ?>" alt="" style="height:36px;border-radius:4px;">
            <?php endif; ?>
            <span>🧱 <?= htmlspecialchars(company_display_name()) ?></span>
        </div>
        <div class="user-info">
            <form action="/block_plant/dashboard/search.php" method="get" style="display:flex;align-items:center;gap:8px;">
                <input type="text" name="q" placeholder="<?= e(t('search_placeholder')) ?>" style="min-width:220px; padding:8px 10px; border-radius:6px; border:1px solid rgba(255,255,255,0.3); background:rgba(255,255,255,0.12); color:white;">
                <button type="submit" class="btn btn-secondary" style="padding:8px 12px; font-size:0.8rem;"><?= t('find') ?></button>
            </form>
            
            <!-- Command palette trigger -->
            <button id="cmdkOpen" title="<?= e(t('search_everything')) ?>" style="background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.30);color:#fff;border-radius:999px;cursor:pointer;padding:6px 14px;font-size:0.8rem;display:flex;align-items:center;gap:8px;margin-right:6px;">
                <span>🔍</span><span style="opacity:.85;"><?= t('search_all') ?></span><span class="cmdk-kbd" style="border-color:rgba(255,255,255,0.35);color:rgba(255,255,255,0.85);background:rgba(255,255,255,0.12);">Ctrl K</span>
            </button>

            <!-- System Notifications Bell -->
            <div class="notification-dropdown" style="position:relative; margin-right: 5px;">
                <button id="sysNotifToggle" style="background:transparent;border:none;color:#fff;cursor:pointer;position:relative;padding:4px 8px;font-size:1.1rem;display:flex;align-items:center;">
                    📨
                    <?php if ($sysUnreadCount > 0): ?>
                    <span style="position:absolute;top:-2px;right:-2px;background:#e74c3c;color:white;border-radius:50%;padding:2px 5px;font-size:0.65rem;font-weight:bold;line-height:1;box-shadow:0 0 0 2px <?= htmlspecialchars($brand['primary']) ?>;"><?= $sysUnreadCount > 9 ? '9+' : $sysUnreadCount ?></span>
                    <?php endif; ?>
                </button>
                <div id="sysNotifMenu" style="display:none;position:absolute;right:0;top:120%;background:#fff;color:#333;width:340px;box-shadow:0 10px 25px rgba(0,0,0,0.2);border-radius:12px;z-index:1000;padding:0;overflow:hidden;border:1px solid rgba(0,0,0,0.05);">
                    <div style="padding:15px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:bold;display:flex;justify-content:space-between;align-items:center;">
                        <span style="color:#1e293b;font-size:0.95rem;">Notifications</span>
                        <a href="/block_plant/notifications/index.php" style="font-size:0.8rem;color:#4f46e5;text-decoration:none;font-weight:600;">View All</a>
                    </div>
                    <div style="max-height:320px;overflow-y:auto;">
                    <?php if (!empty($sysNotifications)): ?>
                        <?php foreach ($sysNotifications as $sn): ?>
                        <a href="<?= e($sn['link'] ?: '/block_plant/notifications/index.php') ?>" onclick="markNotificationRead(<?= $sn['id'] ?>)" style="display:block;padding:12px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;">
                            <div style="font-size:0.88rem;color:#334155;line-height:1.35;"><?= e($sn['message']) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;"><?= e(format_datetime($sn['created_at'])) ?></div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:30px 20px;text-align:center;color:#94a3b8;font-size:0.9rem;">
                            <div style="font-size:2rem;margin-bottom:10px;">🎉</div>
                            No unread notifications.
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Smart Task Notification Bell -->
            <div class="notification-dropdown" style="position:relative; margin-right: 5px;">
                <button id="notifToggle" style="background:transparent;border:none;color:#fff;cursor:pointer;position:relative;padding:4px 8px;font-size:1.1rem;display:flex;align-items:center;">
                    🔔
                    <?php if ($pendingTasksCount > 0): ?>
                    <span style="position:absolute;top:-2px;right:-2px;background:#e74c3c;color:white;border-radius:50%;padding:2px 5px;font-size:0.65rem;font-weight:bold;line-height:1;box-shadow:0 0 0 2px <?= htmlspecialchars($brand['primary']) ?>;"><?= $pendingTasksCount > 9 ? '9+' : $pendingTasksCount ?></span>
                    <?php endif; ?>
                </button>
                <div id="notifMenu" style="display:none;position:absolute;right:0;top:120%;background:#fff;color:#333;width:320px;box-shadow:0 10px 25px rgba(0,0,0,0.2);border-radius:12px;z-index:1000;padding:0;overflow:hidden;border:1px solid rgba(0,0,0,0.05);">
                    <div style="padding:15px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:bold;display:flex;justify-content:space-between;align-items:center;">
                        <span style="color:#1e293b;font-size:0.95rem;">Tasks & Reminders</span>
                        <a href="/block_plant/dashboard/tasks.php" style="font-size:0.8rem;color:#4f46e5;text-decoration:none;font-weight:600;">View All</a>
                    </div>
                    <div style="max-height:300px;overflow-y:auto;">
                    <?php if ($pendingTasksCount > 0 && !empty($pendingTasksList)): ?>
                        <?php foreach ($pendingTasksList as $t): 
                            $isOverdue = $t['due_date'] && $t['due_date'] < date('Y-m-d');
                        ?>
                        <a href="/block_plant/dashboard/tasks.php" style="display:block;padding:12px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;transition:background 0.2s;">
                            <div style="font-weight:600;font-size:0.9rem;<?= $isOverdue ? 'color:#ef4444;' : 'color:#334155;' ?>margin-bottom:4px;">
                                <?= e($t['title']) ?>
                            </div>
                            <div style="font-size:0.75rem;color:#64748b;display:flex;justify-content:space-between;align-items:center;">
                                <span><?= $t['due_date'] ? ($isOverdue ? '⚠️ Overdue: ' : '📅 Due: ') . format_date($t['due_date']) : '📌 No due date' ?></span>
                                <span style="text-transform:capitalize;font-weight:600;padding:2px 6px;border-radius:4px;background:<?= $t['priority'] == 'high' ? '#fee2e2;color:#ef4444' : ($t['priority'] == 'medium' ? '#fef3c7;color:#d97706' : '#f1f5f9;color:#64748b') ?>">
                                    <?= e($t['priority']) ?>
                                </span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:30px 20px;text-align:center;color:#94a3b8;font-size:0.9rem;">
                            <div style="font-size:2rem;margin-bottom:10px;">🎉</div>
                            <?= e(t('all_caught_up')) ?>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <span style="opacity:0.9;font-size:0.85rem;margin-left:10px;">
                <a href="?lang=en" style="color:#fff;margin-right:6px;<?= app_lang() === 'en' ? 'font-weight:bold;text-decoration:underline;' : '' ?>">EN</a>
                <a href="?lang=am" style="color:#fff;<?= app_lang() === 'am' ? 'font-weight:bold;text-decoration:underline;' : '' ?>">አማ</a>
            </span>
            <button id="themeToggle" title="<?= t('dark_mode') ?>" style="background:transparent;border:1px solid #fff;color:#fff;border-radius:4px;cursor:pointer;padding:4px 8px;">🌙</button>
            <span><?= htmlspecialchars($user['name']) ?> <small style="opacity:.75">(<?= htmlspecialchars($user['role']) ?>)</small></span>
            <a href="/block_plant/auth/logout.php" style="color: #ecf0f1; text-decoration: none;"><?= t('logout') ?></a>
        </div>
    </div>
    
    <nav class="navbar" id="appSidebar">
        <div class="sidebar-brand">
            <?php if ($logoFile && is_file($logoFile)): ?>
            <img src="<?= htmlspecialchars($co['logo_path']) ?>" alt="">
            <?php endif; ?>
            <span>🧱 <?= htmlspecialchars(company_display_name()) ?></span>
        </div>
        <ul>
            <?php if (has_permission('dashboard', 'view')): ?>
            <li><a href="/block_plant/dashboard/index.php"><?= t('dashboard') ?></a></li>
            <?php endif; ?>

            <?php if (has_permission('gate', 'view')): ?>
            <li><a href="/block_plant/gate/index.php"><?= t('gate') ?></a></li>
            <?php endif; ?>

            <?php if (has_permission('sales', 'view') || has_permission('crm', 'view') || has_permission('customers', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('sales_crm') ?> ▼</a>
                <div class="dropdown-menu">
                    <?php if (has_permission('sales', 'view')): ?>
                    <a href="/block_plant/quotations/index.php"><?= t('quotations') ?></a>
                    <a href="/block_plant/sales/index.php"><?= t('sales_invoices') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('crm', 'view')): ?>
                    <a href="/block_plant/crm/delivery.php"><?= t('delivery_notes') ?></a>
                    <a href="/block_plant/crm/commissions.php"><?= t('commissions') ?></a>
                    <a href="/block_plant/crm/feedback.php"><?= t('feedback') ?></a>
                    <a href="/block_plant/crm/pipeline.php"><?= t('sales_pipeline') ?></a>
                    <a href="/block_plant/crm/targets.php"><?= t('sales_targets') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('customers', 'view')): ?>
                    <a href="/block_plant/customers/index.php"><?= t('customers') ?></a>
                    <a href="/block_plant/customers/analytics.php"><?= t('customer_analytics') ?></a>
                    <a href="/block_plant/customers/reminders.php"><?= t('customer_reminders') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('sales', 'view')): ?>
                    <a href="/block_plant/payments/customer.php"><?= t('customer_payments') ?></a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('purchases', 'view') || has_permission('suppliers', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('purchasing') ?> ▼</a>
                <div class="dropdown-menu">
                    <?php if (has_permission('purchases', 'view')): ?>
                    <a href="/block_plant/purchases/index.php"><?= t('purchase_orders') ?></a>
                    <a href="/block_plant/purchases/planning.php"><?= t('purchase_planning') ?></a>
                    <a href="/block_plant/purchases/approvals.php"><?= t('purchase_approvals') ?></a>
                    <a href="/block_plant/purchases/analytics.php"><?= t('purchase_analytics') ?></a>
                    <a href="/block_plant/purchases/schedule.php"><?= t('purchase_schedule') ?></a>
                    <a href="/block_plant/purchases/supplier_prices.php"><?= t('supplier_prices') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('suppliers', 'view')): ?>
                    <a href="/block_plant/suppliers/index.php"><?= t('suppliers') ?></a>
                    <a href="/block_plant/suppliers/scorecards.php"><?= t('supplier_scorecards') ?></a>
                    <a href="/block_plant/suppliers/deliveries.php"><?= t('supplier_deliveries') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('purchases', 'view')): ?>
                    <a href="/block_plant/payments/supplier.php"><?= t('supplier_payments') ?></a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('production', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('production') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/production/index.php"><?= t('production_orders') ?></a>
                    <?php if (has_permission('production', 'create')): ?>
                    <a href="/block_plant/production/create.php"><?= t('new_production_run') ?></a>
                    <?php endif; ?>
                    <a href="/block_plant/production/planning.php"><?= t('production_planning') ?></a>
                    <a href="/block_plant/production/schedule.php"><?= t('production_schedule') ?></a>
                    <a href="/block_plant/production/targets.php"><?= t('production_targets') ?></a>
                    <a href="/block_plant/production/quality.php"><?= t('quality_checks') ?></a>
                    <a href="/block_plant/production/bom.php"><?= t('bill_of_materials') ?></a>
                    <a href="/block_plant/production/analytics.php"><?= t('production_analytics') ?></a>
                    <?php if (has_permission('inventory', 'view')): ?>
                    <a href="/block_plant/inventory/materials.php"><?= t('raw_materials') ?></a>
                    <a href="/block_plant/inventory/index.php"><?= t('finished_goods') ?></a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('inventory', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('inventory') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/inventory/overview.php"><?= t('inventory_overview') ?></a>
                    <a href="/block_plant/inventory/analytics.php"><?= t('inventory_analytics') ?></a>
                    <a href="/block_plant/inventory/materials.php"><?= t('materials_stock') ?></a>
                    <a href="/block_plant/inventory/index.php"><?= t('finished_stock') ?></a>
                    <a href="/block_plant/inventory/movement.php"><?= t('stock_movement') ?></a>
                    <a href="/block_plant/inventory/dispatch.php"><?= t('dispatch_tracking') ?></a>
                    <?php if (has_permission('inventory', 'edit')): ?>
                    <a href="/block_plant/inventory/adjustments.php"><?= t('stock_adjustments') ?></a>
                    <a href="/block_plant/inventory/reorder.php"><?= t('reorder_levels') ?></a>
                    <?php endif; ?>
                    <a href="/block_plant/inventory/low_stock_alert.php"><?= t('low_stock_alerts') ?></a>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('hr', 'view')): ?>
            <li class="dropdown">
                <a href="/block_plant/hr/index.php"><?= t('hr_payroll') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/hr/index.php"><?= t('hr_overview') ?></a>
                    <a href="/block_plant/hr/search.php"><?= t('hr_global_search') ?></a>
                    <a href="/block_plant/hr/exit_clearance.php"><?= t('employee_exit_clearance') ?></a>
                    <a href="/block_plant/hr/employees.php"><?= t('employees') ?></a>
                    <a href="/block_plant/hr/departments.php"><?= t('department_management') ?></a>
                    <?php if (can_manage_hr_permissions()): ?>
                    <a href="/block_plant/hr/permissions.php">🔑 <?= t('hr_permissions') ?></a>
                    <?php endif; ?>
                    <a href="/block_plant/hr/attendance.php"><?= t('attendance') ?></a>
                    <a href="/block_plant/hr/attendance_report.php"><?= t('attendance_report') ?></a>
                    <a href="/block_plant/hr/attendance_sheet.php">🧾 <?= t('attendance_sheet') ?></a>
                    <a href="/block_plant/hr/attendance_warnings.php">⚠ <?= e(attendance_warning_text('menu')) ?></a>
                    <a href="/block_plant/hr/attendance_submit.php"><?= t('submit_attendance_finance') ?></a>
                    <a href="/block_plant/hr/leaves.php"><?= t('leave_management') ?></a>
                    <a href="/block_plant/hr/performance.php"><?= t('performance_reviews') ?></a>
                    <a href="/block_plant/hr/training.php"><?= t('training_development') ?></a>
                    <a href="/block_plant/hr/shifts.php"><?= t('shift_management') ?></a>
                    <a href="/block_plant/hr/overtime.php"><?= t('overtime_tracking') ?></a>
                    <a href="/block_plant/hr/documents.php"><?= t('employee_documents') ?></a>
                    <a href="/block_plant/hr/loans.php"><?= t('loans_advances') ?></a>
                    <a href="/block_plant/hr/loan_repayments.php"><?= t('loan_repayment_history') ?></a>
                    <a href="/block_plant/hr/asset_issuance.php">Asset Issuance</a>
                    <a href="/block_plant/hr/asset_clearance.php">Asset Clearance</a>
                    <a href="/block_plant/finance/payslip.php"><?= t('payslip_run') ?></a>
                    <a href="/block_plant/hr/reports.php"><?= t('hr_reports') ?></a>
                    <a href="/block_plant/hr/department_report.php"><?= t('department_report') ?></a>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('assets', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('assets') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/assets/index.php"><?= t('fixed_assets') ?></a>
                    <a href="/block_plant/assets/categories.php"><?= t('asset_categories') ?></a>
                    <a href="/block_plant/assets/maintenance.php"><?= t('maintenance') ?></a>
                    <a href="/block_plant/assets/alerts.php"><?= t('asset_alerts') ?></a>
                    <a href="/block_plant/assets/depreciation.php"><?= t('depreciation') ?></a>
                    <?php if (has_permission('assets', 'create')): ?>
                    <a href="/block_plant/assets/journal.php">📒 <?= t('asset_journal_entry') ?></a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('finance', 'view') || has_permission('expenses', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('finance') ?> ▼</a>
                <div class="dropdown-menu">
                    <?php if (has_permission('expenses', 'view')): ?>
                    <a href="/block_plant/expenses/index.php"><?= t('expenses') ?></a>
                    <?php endif; ?>
                    <?php if (has_permission('finance', 'view')): ?>
                    <a href="/block_plant/finance/cashbook.php"><?= t('cash_book') ?></a>
                    <a href="/block_plant/finance/accounts.php"><?= t('chart_of_accounts') ?></a>
                    <a href="/block_plant/finance/general_ledger.php"><?= t('general_ledger') ?></a>
                    <a href="/block_plant/finance/trial_balance.php"><?= t('trial_balance') ?></a>
                    <a href="/block_plant/finance/journal.php"><?= t('journal_entries') ?></a>
                    <a href="/block_plant/finance/attendance_review.php"><?= t('attendance_review') ?></a>
                    <?php if (has_permission('hr', 'view')): ?>
                    <a href="/block_plant/finance/payroll.php"><?= t('payroll_generation') ?></a>
                    <?php endif; ?>
                    <a href="/block_plant/finance/payroll_bank.php"><?= t('bank_transfer_schedule') ?></a>
                    <a href="/block_plant/finance/payroll_journal.php"><?= t('payroll_journal') ?></a>
                    <a href="/block_plant/finance/payroll_approval.php"><?= t('payroll_approval') ?></a>
                    <a href="/block_plant/finance/loan_approval.php"><?= t('loan_approval') ?></a>
                    <a href="/block_plant/hr/loan_repayments.php"><?= t('loan_repayment_history') ?></a>
                    <?php if (has_permission('hr', 'view')): ?>
                    <a href="/block_plant/finance/unpaid_payroll.php">Unpaid Payroll</a>
                    <?php endif; ?>
                    <?php if (has_permission('finance', 'create')): ?>
                    <a href="/block_plant/finance/journal_form.php"><?= t('new_journal_entry') ?></a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <?php if (has_permission('reports', 'view')): ?>
            <li class="dropdown">
                <a href="#"><?= t('reports') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/reports/sales.php"><?= t('sales_reports') ?></a>
                    <a href="/block_plant/reports/purchases.php"><?= t('purchase_reports') ?></a>
                    <a href="/block_plant/reports/production.php"><?= t('production_reports') ?></a>
                    <a href="/block_plant/reports/inventory.php"><?= t('inventory_reports') ?></a>
                    <a href="/block_plant/reports/financial.php"><?= t('financial_reports') ?></a>
                    <a href="/block_plant/reports/payroll.php"><?= t('payroll_reports') ?></a>
                    <a href="/block_plant/reports/profit_loss.php"><?= t('profit_loss') ?></a>
                    <a href="/block_plant/reports/balance_sheet.php"><?= t('balance_sheet') ?></a>
                    <a href="/block_plant/reports/aging.php"><?= t('aging_reports') ?></a>
                    <a href="/block_plant/reports/customer_statement.php"><?= t('customer_statement') ?></a>
                    <?php if (has_permission('gate', 'view')): ?>
                    <a href="/block_plant/reports/gate.php"><?= t('gate_exit_report') ?></a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>

            <!-- Settings always shown – content differs by role -->
            <li class="dropdown">
                <a href="#">⚙️ <?= t('settings') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/settings/profile.php"><?= t('my_profile') ?></a>
                    <?php if ($_navIsAdmin || has_permission('settings', 'edit')): ?>
                    <a href="/block_plant/settings/company.php"><?= t('company_settings') ?></a>
                    <a href="/block_plant/settings/users.php"><?= t('user_management') ?></a>
                    <a href="/block_plant/settings/permissions.php"><?= t('role_permissions') ?></a>
                    <a href="/block_plant/settings/blocks.php"><?= t('block_types') ?></a>
                    <a href="/block_plant/settings/backup.php"><?= t('backup_restore') ?></a>
                    <a href="/block_plant/settings/telegram.php"><?= t('telegram_alerts') ?></a>
                    <?php endif; ?>
                    <?php if ($_navIsAdmin || has_permission('settings', 'view')): ?>
                    <a href="/block_plant/settings/activity_log.php"><?= t('activity_log') ?></a>
                    <a href="/block_plant/settings/timeline.php"><?= t('activity_timeline') ?></a>
                    <a href="/block_plant/settings/documents.php"><?= t('document_library') ?></a>
                    <?php endif; ?>
                </div>
            </li>

            <?php if ($_navIsAdmin): ?>
            <li class="dropdown">
                <a href="#"><?= t('advanced') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/dashboard/widgets.php"><?= t('advanced_widgets') ?></a>
                    <a href="/block_plant/dashboard/insights.php"><?= t('operational_insights') ?></a>
                    <a href="/block_plant/dashboard/operations.php"><?= t('operations_overview') ?></a>
                    <a href="/block_plant/dashboard/admin.php"><?= t('admin_overview') ?></a>
                    <a href="/block_plant/dashboard/forecast.php"><?= t('budget_forecast') ?></a>
                    <a href="/block_plant/dashboard/tasks.php"><?= t('tasks_reminders') ?></a>
                    <a href="/block_plant/dashboard/fleet.php"><?= t('fleet_management') ?></a>
                    <a href="/block_plant/expenses/recurring.php"><?= t('recurring_costs') ?></a>
                    <a href="/block_plant/assets/maintenance.php"><?= t('asset_maintenance') ?></a>
                    <a href="/block_plant/crm/interactions.php"><?= t('followups_interactions') ?></a>
                    <a href="/block_plant/inventory/low_stock_alert.php"><?= t('low_stock_alerts') ?></a>
                    <a href="/block_plant/api/dashboard_data.php" target="_blank" rel="noopener"><?= t('dashboard_api') ?></a>
                </div>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <div id="sidebarBackdrop" class="sidebar-backdrop"></div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var body = document.body;
        var sidebar = document.getElementById('appSidebar');
        var toggle = document.getElementById('sidebarToggle');
        var backdrop = document.getElementById('sidebarBackdrop');

        function setSidebar(open) {
            body.classList.toggle('sidebar-open', open);
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                setSidebar(!body.classList.contains('sidebar-open'));
            });
        }
        if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });

        // Accordion groups — delegated so it survives re-renders and fires reliably
        if (sidebar) {
            sidebar.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('.dropdown > a') : null;
                if (!a || !sidebar.contains(a)) return;
                e.preventDefault();
                var li = a.parentElement;
                var willOpen = !li.classList.contains('open');
                sidebar.querySelectorAll('.dropdown.open').forEach(function (o) { o.classList.remove('open'); });
                if (willOpen) li.classList.add('open');
            });
        }

        // Close the off-canvas drawer after picking a destination on small screens
        if (sidebar) {
            sidebar.querySelectorAll('.dropdown-menu a, li:not(.dropdown) > a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 900) setSidebar(false);
                });
            });
        }

        // Highlight the current page and open its group
        var here = location.pathname.replace(/\/+$/, '');
        if (sidebar) {
            sidebar.querySelectorAll('a[href]').forEach(function (a) {
                var href = a.getAttribute('href') || '';
                if (!href || href === '#' || a.target === '_blank') return;
                var path = '';
                try { path = new URL(href, location.origin).pathname.replace(/\/+$/, ''); } catch (err) { return; }
                if (path && path === here) {
                    a.classList.add('active');
                    var group = a.closest('.dropdown');
                    if (group) { group.classList.add('open', 'has-active'); }
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setSidebar(false);
        });

        // Mark notification as read when clicked
        function markNotificationRead(notificationId) {
            fetch('/block_plant/notifications/mark_read.php?id=' + notificationId, {
                method: 'GET'
            }).then(response => {
                if(response.ok) {
                    // Update the unread count in the bell
                    const bellBadge = document.querySelector('#sysNotifToggle span');
                    if(bellBadge) {
                        const currentCount = parseInt(bellBadge.textContent) || 0;
                        if(currentCount > 1) {
                            bellBadge.textContent = currentCount - 1;
                        } else {
                            bellBadge.remove();
                        }
                    }
                }
            }).catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }
    });
    </script>
    
    <?php if (isset($_SESSION["just_logged_in"]) && $_SESSION["just_logged_in"]): ?>
        <?php $_SESSION["just_logged_in"] = false; ?>
        <?php if ($pendingTasksCount > 0): ?>
        <!-- Animated Login Task Toast Notification -->
        <div id="loginTaskToast" style="position:fixed; bottom:30px; right:30px; background:#fff; border-left:4px solid #4f46e5; box-shadow:0 10px 25px rgba(0,0,0,0.2); padding:20px; border-radius:8px; z-index:9999; max-width:350px; transform:translateY(150%); opacity:0; transition:all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <h4 style="margin:0; color:#1e293b; font-size:1.1rem; display:flex; align-items:center; gap:8px;">
                    <span style="font-size:1.4rem;">👋</span> Welcome back!
                </h4>
                <button onclick="document.getElementById('loginTaskToast').style.display='none'" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#94a3b8; padding:0; line-height:1; transition:color 0.2s;">&times;</button>
            </div>
            <p style="margin:0 0 15px 0; color:#64748b; font-size:0.95rem; line-height:1.5;">
                You have <strong style="color:#ef4444; font-size:1.05rem;"><?= $pendingTasksCount ?></strong> pending task(s) assigned to you by the admin. 
            </p>
            <div style="display:flex; gap:10px;">
                <a href="/block_plant/dashboard/tasks.php" style="display:inline-block; background:#4f46e5; color:#fff; text-decoration:none; padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:600; box-shadow:0 2px 4px rgba(79, 70, 229, 0.3); transition:background 0.2s;">View Tasks</a>
                <button onclick="document.getElementById('loginTaskToast').style.display='none'" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:background 0.2s;">Dismiss</button>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const toast = document.getElementById('loginTaskToast');
                    if (toast) {
                        toast.style.transform = 'translateY(0)';
                        toast.style.opacity = '1';
                        // Auto hide after 10 seconds
                        setTimeout(() => {
                            toast.style.transform = 'translateY(150%)';
                            toast.style.opacity = '0';
                            setTimeout(() => { toast.style.display = 'none'; }, 500);
                        }, 10000);
                    }
                }, 800); // Small delay to allow the page to load first
            });
        </script>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- System Activity Notifications -->
    <?php if (!empty($sysToastNotifications)): ?>
    <div id="sysNotifContainer" style="position:fixed; bottom:30px; left:30px; z-index:9999; display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($sysToastNotifications as $index => $notif): ?>
        <div class="sys-toast" id="sysToast<?= $index ?>" style="background:#fff; border-left:4px solid #10b981; box-shadow:0 10px 25px rgba(0,0,0,0.2); padding:16px 20px; border-radius:8px; max-width:350px; transform:translateX(-150%); opacity:0; transition:all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                <h4 style="margin:0; color:#0f172a; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <span>✅</span> System Update
                </h4>
                <button onclick="document.getElementById('sysToast<?= $index ?>').style.display='none'" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8; padding:0; line-height:1; transition:color 0.2s;">&times;</button>
            </div>
            <p style="margin:0; color:#475569; font-size:0.9rem; line-height:1.4;">
                <?= e($notif['message']) ?>
            </p>
            <?php if (!empty($notif['link'])): ?>
            <a href="<?= e($notif['link']) ?>" style="display:inline-block; margin-top:10px; color:#10b981; text-decoration:none; font-size:0.85rem; font-weight:600;">View Details &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toasts = document.querySelectorAll('.sys-toast');
            toasts.forEach((toast, i) => {
                setTimeout(() => {
                    toast.style.transform = 'translateX(0)';
                    toast.style.opacity = '1';
                    
                    // Auto hide after 8 seconds
                    setTimeout(() => {
                        toast.style.transform = 'translateX(-150%)';
                        toast.style.opacity = '0';
                        setTimeout(() => { toast.style.display = 'none'; }, 500);
                    }, 8000 + (i * 1000));
                }, 1000 + (i * 400));
            });
        });
    </script>
    <?php endif; ?>

    <!-- Include Beautiful Modal System -->
    <?php include_once __DIR__ . '/modal_system.php'; ?>

    <div class="container">
