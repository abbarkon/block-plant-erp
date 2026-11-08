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
$rule = '━━━━━━━━━━━━━━━━━━━━';
$row = function ($label, $value) {
    return '<code>' . str_pad($label, 16, ' ') . str_pad((string) $value, 14, ' ', STR_PAD_LEFT) . '</code>';
};
$company = (string) (company_settings()['company_name'] ?? 'Block Plant ERP');
$lines = [];
$lines[] = '🏭 <b>' . telegram_escape(mb_strtoupper($company)) . '</b>';
$lines[] = '💰 <i>Weekly labor payment</i>';
$lines[] = '⏰ <b>Payday: today, Saturday 5:00 PM</b>';
$lines[] = '🗓 ' . telegram_escape(telegram_date($ws) . ' → ' . telegram_date($we));
$lines[] = $rule;
$lines[] = $row('🧱 Cement used', number_format($sum['bags'], 1) . ' bags');
$lines[] = $row('💵 Rate per bag', $cs . money($sum['rate']));
$lines[] = $row('🏗 Blocks made', number_format($sum['blocks']));
$lines[] = $rule;
$lines[] = '✅ <b>TOTAL TO PAY: ' . telegram_escape($cs . money($sum['due'])) . '</b>';
if ($sum['days']) {
    $lines[] = '';
    $lines[] = '📅 <b>BY DAY</b>';
    foreach ($sum['days'] as $d => $r) {
        $lines[] = '▫️ ' . telegram_escape(date('D d M', strtotime($d))) . ' · ' . number_format($r['bags'], 1) . ' bags · <b>' . telegram_escape($cs . money($r['due'])) . '</b>';
    }
}
$lines[] = '';
$lines[] = '📝 <i>After paying, record it in the app: Production → Weekly Labor Payment.</i>';
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
