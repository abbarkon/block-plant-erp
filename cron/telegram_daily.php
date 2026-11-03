<?php
// cron/telegram_daily.php
//
// Sends the daily attendance report (and the "needs attention" alerts) to the
// Telegram chat configured in Settings > Telegram Alerts.
//
// Intended for Windows Task Scheduler or a normal cron job. The script is
// idempotent: it remembers the last report date in telegram_message_log and
// refuses to send the same day twice.
//
// Examples:
//   php cron/telegram_daily.php
//   php cron/telegram_daily.php --dry-run
//   php cron/telegram_daily.php --date=2026-10-31 --force
//   php cron/telegram_daily.php --no-attachment
//
// Default rule: Monday-Friday. The scheduler controls the exact run time; the
// script also refuses to send before the saved send time (default 18:00). All
// times follow the company timezone (company_settings.timezone).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/telegram.php';

$options = getopt('', ['date:', 'force', 'dry-run', 'no-attachment', 'help']);
if (isset($options['help'])) {
    echo "Usage: php telegram_daily.php [--date=YYYY-MM-DD] [--force] [--dry-run] [--no-attachment]\n";
    exit(0);
}

apply_company_timezone();

$date = isset($options['date']) ? trim((string) $options['date']) : date('Y-m-d');
if (!is_valid_date_string($date)) {
    fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD.\n");
    exit(2);
}

$force = isset($options['force']);
$dryRun = isset($options['dry-run']);
$noAttachment = isset($options['no-attachment']);

$lockHandle = @fopen(sys_get_temp_dir() . '/block_plant_telegram_daily.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Skipped: another Telegram daily run is already in progress.\n";
    if ($lockHandle) {
        fclose($lockHandle);
    }
    exit(0);
}

try {
    $exitCode = run_telegram_daily($date, $force, $dryRun, $noAttachment);
} catch (Throwable $e) {
    fwrite(STDERR, "Telegram daily report failed: " . $e->getMessage() . "\n");
    telegram_set_last_error($e->getMessage());
    $exitCode = 1;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);

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
        fwrite(STDERR, "Telegram send failed: " . implode(' ', $result['errors']) . "\n");
        return 1;
    }

    echo "Sent {$result['sent']} message part(s)" . ($result['attachment'] ? ' with the attendance CSV' : '') . ".\n";
    return 0;
}
