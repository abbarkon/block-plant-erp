<?php
// cron/telegram_daily_web.php
//
// Web version of telegram_daily.php for hosts without a command-line scheduler (AletCloud).
// A free external scheduler (for example cron-job.org) opens this address every working day:
//   https://YOUR-SITE/block_plant/cron/telegram_daily_web.php?key=YOUR_CRON_SECRET
// The secret comes from the CRON_SECRET environment variable; without it this page refuses to run.
// Options (optional): &dry=1 to preview, &force=1 to send even if already sent, &date=YYYY-MM-DD

header('Content-Type: text/plain; charset=utf-8');
$secret = (string) (getenv('CRON_SECRET') ?: ($_SERVER['CRON_SECRET'] ?? ''));
$given = (string) ($_GET['key'] ?? '');
if ($secret === '' || !hash_equals($secret, $given)) {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/telegram.php';

apply_company_timezone();

$date = isset($_GET['date']) ? trim((string) $_GET['date']) : date('Y-m-d');
if (!is_valid_date_string($date)) {
    http_response_code(400);
    exit("Invalid date. Use YYYY-MM-DD.\n");
}
$force = !empty($_GET['force']);
$dryRun = !empty($_GET['dry']);
$noAttachment = !empty($_GET['noattach']);

$lockHandle = @fopen(sys_get_temp_dir() . '/block_plant_telegram_daily.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("Skipped: another Telegram daily run is already in progress.\n");
}
try {
    $exitCode = run_telegram_daily($date, $force, $dryRun, $noAttachment);
} catch (Throwable $e) {
    echo "Telegram daily report failed: " . $e->getMessage() . "\n";
    telegram_set_last_error($e->getMessage());
    http_response_code(500);
    $exitCode = 1;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
if ($exitCode !== 0) {
    http_response_code(500);
}
exit;

/**
 * @return int process exit code
 */
function run_telegram_daily(string $date, bool $force, bool $dryRun, bool $noAttachment): int
{
    $settings = telegram_settings(true);
    $label = telegram_date($date);

    if (!$force && !$dryRun && empty($settings['is_enabled'])) {
        echo "Skipped: Telegram alerts are disabled in Settings > Telegram Alerts.\n";
        return 0;
    }

    if (!$dryRun && !telegram_is_configured($settings)) {
        echo "Skipped: the bot token or the chat ID is missing. Open Settings > Telegram Alerts.\n";
        return 0;
    }

    if (!$force && !is_working_day($date)) {
        echo "Skipped: {$label} is not a working day.\n";
        return 0;
    }

    if (!$force && telegram_already_sent($date)) {
        echo "Skipped: the report for {$label} was already sent.\n";
        return 0;
    }

    if (!$force && $date === date('Y-m-d') && !telegram_send_time_reached($settings['send_time'] ?? '')) {
        $sendTime = substr((string) ($settings['send_time'] ?? ''), 0, 5);
        echo "Skipped: scheduled for {$sendTime}, it is " . date('H:i') . " now.\n";
        return 0;
    }

    $data = telegram_daily_report_data($date);
    $message = telegram_build_daily_message($date, $data);
    $counts = $data['day']['counts'];
    $attention = telegram_attention_total($data);

    echo "Date: {$label} ({$date})\n";
    echo "Active employees: {$data['day']['active_count']} · recorded: {$data['day']['recorded_count']} · not recorded: {$data['day']['missing_count']}\n";
    echo "Present: {$counts['present']} · late: {$counts['late']} · absent: {$counts['absent']} · leave: {$counts['leave']} · half-day: {$counts['half-day']}\n";
    echo "Needs attention: {$attention}\n";
    echo "Message: " . mb_strlen($message, 'UTF-8') . " characters in " . count(telegram_chunk_text($message)) . " part(s)\n";

    if ($dryRun) {
        echo "--- message preview ---\n{$message}\n--- end preview ---\n";
        echo "Dry run: nothing was sent.\n";
        return 0;
    }

    $result = telegram_send_daily_report($date, [
        'attach' => !$noAttachment && !empty($settings['attach_csv']),
    ]);

    if (!$result['ok']) {
        echo ("Telegram send failed: " . implode(' ', $result['errors']) . "\n");
        return 1;
    }

    echo "Sent {$result['sent']} message part(s)" . ($result['attachment'] ? ' with the attendance CSV' : '') . ".\n";
    return 0;
}
