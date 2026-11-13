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

// Sidebar menu customization: ensure tables exist, seed on first run,
// and track usage of the current page.
ensure_sidebar_menu_schema();
$currentLinkKey = current_sidebar_link_key();
if ($currentLinkKey) {
    track_sidebar_usage($currentLinkKey);
}
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
    <link rel="stylesheet" href="/block_plant/assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/../assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="/block_plant/assets/css/theme-clean.css?v=<?= filemtime(__DIR__ . '/../assets/css/theme-clean.css') ?>">
    <link rel="stylesheet" href="/block_plant/assets/css/dark-theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/dark-theme.css') ?>">
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
            const syncThemeIcon = () => {
                if (btn) btn.textContent = document.body.classList.contains('dark-theme') ? '☀️' : '🌙';
            };
            syncThemeIcon();
            if (btn) {
                btn.addEventListener('click', () => {
                    document.body.classList.toggle('dark-theme');
                    localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light');
                    syncThemeIcon();
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
<script>
try {
    var bpTheme = localStorage.getItem('theme');
    if (bpTheme === 'dark' || (!bpTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.body.classList.add('dark-theme');
    }
} catch (e) {}
</script>
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
            <?php
            $sidebarItems = get_sidebar_items();
            $targetBlank = ['open_shop']; // links that open in a new tab
            foreach ($sidebarItems as $group):
            ?>
            <?php if (!empty($group['children'])): ?>
            <li class="dropdown">
                <a href="#"><?= e($group['label']) ?></a>
                <div class="dropdown-menu">
                    <?php foreach ($group['children'] as $child): ?>
                    <?php
                    $tb = in_array($child['link_key'], $targetBlank, true) ? ' target="_blank" rel="noopener"' : '';
                    ?>
                    <a href="<?= htmlspecialchars($child['url']) ?>"<?= $tb ?>><?= e($child['icon'] ? $child['icon'] . ' ' : '') ?><?= e($child['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </li>
            <?php else: ?>
            <li><a href="<?= htmlspecialchars($group['url']) ?>"><?= e($group['icon'] ? $group['icon'] . ' ' : '') ?><?= e($group['label']) ?></a></li>
            <?php endif; ?>
            <?php endforeach; ?>
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
    
    <?php if (is_file(__DIR__ . '/sidebar_extras.php')) { require __DIR__ . '/sidebar_extras.php'; } ?>

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
