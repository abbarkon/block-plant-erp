<?php
// cron/labor_reminder_web.php
//
// Sends the Saturday "weekly labor payment" reminder to Telegram. Schedule it (for example at 3:00 PM
// every Saturday) on a free external scheduler such as cron-job.org:
//   https://YOUR-SITE/block_plant/cron/labor_reminder_web.php?key=YOUR_CRON_SECRET
// Options: &dry=1 shows the message without sending, &force=1 sends even if it is not Saturday
// or was already sent this week. It sends nothing when the week is already recorded as paid.

header('Content-Type: text/plain; charset=utf-8');
$secret = (string) (getenv('CRON_SECRET') ?: ($_SERVER['CRON_SECRET'] ?? ''));
if ($secret === '' || !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/telegram.php';
require_once __DIR__ . '/../config/labor_settings.php';

apply_company_timezone();
$force = !empty($_GET['force']);
$dry = !empty($_GET['dry']);

if (!$force && (int) date('w') !== 6) {
    exit("Skipped: today is not Saturday.\n");
}
[$ws, $we] = labor_week_bounds(date('Y-m-d'));
if (!$force && labor_payment_for_week($we)) {
    exit("Skipped: this week's labor payment is already recorded.\n");
}
if (!$force && !$dry && labor_setting('last_reminder_week', '') === $we) {
    exit("Skipped: the reminder for this week was already sent.\n");
}

$sum = labor_week_summary($ws, $we);
if ($sum['rate'] <= 0) {
    exit("Skipped: no labor rate per cement bag is set (Production > Weekly Labor Payment).\n");
}
if ($sum['due'] <= 0) {
    exit("Skipped: no cement was used this week, so nothing is due.\n");
}

$cs = currency_symbol();
$lines = [];
$lines[] = '<b>Weekly labor payment - today 5:00 PM</b>';
$lines[] = telegram_escape(telegram_date($ws) . ' to ' . telegram_date($we));
$lines[] = '';
$lines[] = 'Cement used: <b>' . number_format($sum['bags'], 1) . ' bags</b>';
$lines[] = 'Rate per bag: ' . telegram_escape($cs . money($sum['rate']));
$lines[] = 'Total to pay: <b>' . telegram_escape($cs . money($sum['due'])) . '</b>';
$lines[] = 'Blocks produced: ' . number_format($sum['blocks']);
if ($sum['days']) {
    $lines[] = '';
    foreach ($sum['days'] as $d => $row) {
        $lines[] = telegram_escape(date('D d M', strtotime($d))) . ': ' . number_format($row['bags'], 1) . ' bags - ' . telegram_escape($cs . money($row['due']));
    }
}
$lines[] = '';
$lines[] = 'Record it in the app: Production > Weekly Labor Payment.';
$message = implode("\n", $lines);

if ($dry) {
    echo strip_tags($message) . "\n";
    exit;
}
$settings = telegram_settings(true);
if (!telegram_is_configured($settings)) {
    exit("Skipped: the Telegram bot token or chat ID is missing (Settings > Telegram Alerts).\n");
}
$result = telegram_send_message($message);
if (!empty($result['ok'])) {
    labor_save_setting('last_reminder_week', $we);
    echo "Reminder sent.\n";
} else {
    http_response_code(500);
    echo 'Telegram send failed: ' . ($result['error'] ?? 'unknown error') . "\n";
}
