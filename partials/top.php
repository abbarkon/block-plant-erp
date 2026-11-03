<?php
// Compatibility file - redirects to new header
// This ensures old files still work
if(!isset($_SESSION)) {
    session_start();
}

// Include the new header if not already included
if(!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
    
    // If database not connected, connect it
    if(!isset($pdo)) {
        require_once __DIR__ . "/../config/database.php";
    }
    
    // If helpers not loaded, load them
    if(!function_exists('e')) {
        require_once __DIR__ . "/../config/helpers.php";
    }
    
    // Check if user is logged in
    if(!isset($_SESSION["user_id"])) {
        header("Location: /block_plant/auth/login.php");
        exit;
    }
    
    // Get user info
    $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $pageTitle ?? 'Block Plant ERP' ?></title>
    <link rel="stylesheet" href="/block_plant/assets/css/style.css">
    <style>
        .topbar {
            background: #2c3e50;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .navbar .dropdown-menu a {
            padding: 10px 15px;
            border-bottom: 1px solid #34495e;
        }
        .container, main {
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
    </style>
</head>
<body>
    <div class="topbar">
        <div class="logo">🧱 Block Plant ERP</div>
                <a href="#"><?= t('production') ?> ▼</a>
            <span><?= htmlspecialchars($user['name'] ?? 'User') ?> (<?= htmlspecialchars($user['role'] ?? 'user') ?>)</span>
                    <a href="/block_plant/production/index.php"><?= t('production_orders') ?></a>
                    <a href="/block_plant/production/planning.php"><?= t('production_planning') ?></a>
                    <a href="/block_plant/inventory/materials.php"><?= t('raw_materials') ?></a>
                    <a href="/block_plant/inventory/index.php"><?= t('finished_goods') ?></a>
    <nav class="navbar">
        <ul>
            <li><a href="/block_plant/dashboard/index.php"><?= t('dashboard') ?></a></li>
            
                <a href="#"><?= t('inventory') ?> ▼</a>
                <a href="#"><?= t('sales_crm') ?> ▼</a>
                    <a href="/block_plant/inventory/materials.php"><?= t('materials_stock') ?></a>
                    <a href="/block_plant/inventory/index.php"><?= t('finished_stock') ?></a>
                    <a href="/block_plant/inventory/movement.php"><?= t('stock_movement') ?></a>
                    <a href="/block_plant/inventory/adjustments.php"><?= t('stock_adjustments') ?></a>
                    <a href="/block_plant/inventory/reorder.php"><?= t('reorder_levels') ?></a>
                </div>
            </li>
            
            <li class="dropdown">
                <a href="#"><?= t('hr_payroll') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/hr/employees.php"><?= t('employees') ?></a>
                    <a href="/block_plant/hr/attendance.php"><?= t('attendance') ?></a>
                    <a href="/block_plant/hr/leaves.php"><?= t('leave_management') ?></a>
                    <a href="/block_plant/hr/payroll.php"><?= t('payroll') ?></a>
            </li>
            
            <li class="dropdown">
                <a href="#">Production ▼</a>
                <a href="#"><?= t('assets') ?> ▼</a>
                    <a href="/block_plant/production/index.php">Production Orders</a>
                    <a href="/block_plant/assets/index.php"><?= t('fixed_assets') ?></a>
                    <a href="/block_plant/assets/categories.php"><?= t('asset_categories') ?></a>
                    <a href="/block_plant/assets/maintenance.php"><?= t('maintenance') ?></a>
                    <a href="/block_plant/assets/depreciation.php"><?= t('depreciation') ?></a>
            </li>
            
            <li class="dropdown">
                <a href="#">Inventory ▼</a>
                <a href="#"><?= t('finance') ?> ▼</a>
                    <a href="/block_plant/inventory/materials.php">Materials Stock</a>
                    <a href="/block_plant/expenses/index.php"><?= t('expenses') ?></a>
                    <a href="/block_plant/finance/cashbook.php"><?= t('cash_book') ?></a>
                    <a href="/block_plant/finance/accounts.php"><?= t('chart_of_accounts') ?></a>
                    <a href="/block_plant/finance/general_ledger.php"><?= t('general_ledger') ?></a>
                    <a href="/block_plant/finance/trial_balance.php"><?= t('trial_balance') ?></a>
                    <a href="/block_plant/finance/journal.php"><?= t('journal_entries') ?></a>
            
            <li class="dropdown">
                <a href="#">HR & Payroll ▼</a>
                <div class="dropdown-menu">
                <a href="#"><?= t('reports') ?> ▼</a>
                    <a href="/block_plant/hr/attendance.php">Attendance</a>
                    <a href="/block_plant/reports/sales.php"><?= t('sales_reports') ?></a>
                    <a href="/block_plant/reports/purchases.php"><?= t('purchase_reports') ?></a>
                    <a href="/block_plant/reports/production.php"><?= t('production_reports') ?></a>
                    <a href="/block_plant/reports/inventory.php"><?= t('inventory_reports') ?></a>
                    <a href="/block_plant/reports/financial.php"><?= t('financial_reports') ?></a>
            <li class="dropdown">
                <a href="#">Assets ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/assets/index.php">Fixed Assets</a>
                    <a href="/block_plant/reports/profit_loss.php"><?= t('profit_loss') ?></a>
                    <a href="/block_plant/reports/balance_sheet.php"><?= t('balance_sheet') ?></a>
                    <a href="/block_plant/reports/aging.php"><?= t('aging_reports') ?></a>
                </div>
            </li>
            
            <li class="dropdown">
                <a href="#"><?= t('settings') ?> ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/settings/company.php"><?= t('company_settings') ?></a>
                    <a href="/block_plant/settings/users.php"><?= t('user_management') ?></a>
                    <a href="/block_plant/settings/blocks.php"><?= t('block_types') ?></a>
                    <a href="/block_plant/settings/backup.php"><?= t('backup_restore') ?></a>
                    <a href="/block_plant/finance/trial_balance.php">Trial Balance</a>
                    <a href="/block_plant/finance/journal.php">Journal Entries</a>
                </div>
            </li>
            
            <li class="dropdown">
                <a href="#">Reports ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/reports/sales.php">Sales Reports</a>
                    <a href="/block_plant/reports/purchases.php">Purchase Reports</a>
                    <a href="/block_plant/reports/production.php">Production Reports</a>
                    <a href="/block_plant/reports/inventory.php">Inventory Reports</a>
                    <a href="/block_plant/reports/financial.php">Financial Reports</a>
                    <a href="/block_plant/reports/profit_loss.php">Profit &amp; Loss</a>
                    <a href="/block_plant/reports/balance_sheet.php">Balance Sheet</a>
                    <a href="/block_plant/reports/aging.php">Aging Reports</a>
                </div>
            </li>
            
            <li class="dropdown">
                <a href="#">Settings ▼</a>
                <div class="dropdown-menu">
                    <a href="/block_plant/settings/company.php">Company Settings</a>
                    <a href="/block_plant/settings/users.php">User Management</a>
                    <a href="/block_plant/settings/blocks.php">Block Types</a>
                    <a href="/block_plant/settings/backup.php">Backup & Restore</a>
                </div>
            </li>
        </ul>
    </nav>
    
    <main>
