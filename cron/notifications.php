<?php
// cron/notifications.php
// This script should be run via cron (e.g. every day at 8 AM)
// 0 8 * * * php /path/to/block_plant/cron/notifications.php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/helpers.php";

echo "Starting Notifications Cron Job...\n";

$recipient = $pdo->query("SELECT email FROM company_settings ORDER BY id LIMIT 1")->fetchColumn();
$recipient = $recipient ?: 'admin@blockplant.test';

// 1. Low stock alerts using the current inventory and reorder-level tables.
$lowStockItems = $pdo->query("SELECT rm.name, rm.unit, rm.current_stock, rl.reorder_point, rl.reorder_quantity FROM raw_materials rm JOIN reorder_levels rl ON rl.material_id = rm.id WHERE rm.status = 1 AND rm.current_stock <= rl.reorder_point ORDER BY rm.current_stock / NULLIF(rl.reorder_point, 0)")->fetchAll();
if (count($lowStockItems) > 0) {
    $message = "Low Stock Alert for the following items:\n";
    foreach ($lowStockItems as $item) {
        $message .= "- {$item['name']} (Current: {$item['current_stock']} {$item['unit']}, Reorder point: {$item['reorder_point']} {$item['unit']}, Suggested order: {$item['reorder_quantity']} {$item['unit']})\n";
    }

    send_email_notification($recipient, 'Low Stock Alert', $message);
    
    echo "Sent Low Stock Alerts for " . count($lowStockItems) . " items.\n";
}

// 2. Overdue customer balances (sales older than 30 days with an unpaid balance).
$pendingInvoices = $pdo->query("SELECT s.invoice_no, s.sale_date, s.balance, c.name AS customer_name, c.email FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.payment_status != 'paid' AND s.balance > 0 AND s.sale_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY s.sale_date ASC LIMIT 20")->fetchAll();
if (count($pendingInvoices) > 0) {
    $summary = "Overdue customer balances:\n";
    foreach ($pendingInvoices as $inv) {
        $summary .= "- {$inv['customer_name']} | Invoice {$inv['invoice_no']} | Balance: {$inv['balance']} | Sale date: {$inv['sale_date']}\n";
    }
    send_email_notification($recipient, 'Overdue Customer Balances', $summary);
    echo "Sent Pending Invoice Reminders for " . count($pendingInvoices) . " invoices.\n";
}

// 3. Upcoming asset maintenance due within seven days.
$maintenance = $pdo->query("SELECT a.name, a.asset_code, am.next_maintenance_date FROM assets a JOIN asset_maintenance am ON am.id = (SELECT latest.id FROM asset_maintenance latest WHERE latest.asset_id = a.id ORDER BY latest.maintenance_date DESC LIMIT 1) WHERE a.status = 1 AND am.next_maintenance_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY am.next_maintenance_date")->fetchAll();
if (count($maintenance) > 0) {
    $message = "Upcoming asset maintenance:\n";
    foreach ($maintenance as $item) {
        $message .= "- {$item['name']} ({$item['asset_code']}) due on {$item['next_maintenance_date']}\n";
    }
    send_email_notification($recipient, 'Upcoming Asset Maintenance', $message);
    echo "Sent Asset Maintenance Alerts for " . count($maintenance) . " assets.\n";
}

echo "Cron Job Complete.\n";
