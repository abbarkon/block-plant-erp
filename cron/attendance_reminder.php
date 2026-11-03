<?php
// cron/attendance_reminder.php
//
// Sends one reminder per working day when active employees have no attendance
// row for the date. Intended for Windows Task Scheduler or a normal cron job.
//
// Examples:
//   php cron/attendance_reminder.php
//   php cron/attendance_reminder.php --dry-run
//   php cron/attendance_reminder.php --date=2026-10-15 --force
//
// Default rule: Monday-Friday. The scheduler controls the send time; schedule
// it after the workday (for example 17:00 local time).
// To include Saturday, define ATTENDANCE_WORKING_DAYS before helpers load:
//   define('ATTENDANCE_WORKING_DAYS', [1, 2, 3, 4, 5, 6]);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$options = getopt('', ['date:', 'force', 'dry-run', 'help']);
if (isset($options['help'])) {
    echo "Usage: php attendance_reminder.php [--date=YYYY-MM-DD] [--force] [--dry-run]\n";
    exit(0);
}

$settings = company_settings();
$timezone = apply_company_timezone();

$date = isset($options['date']) ? trim((string) $options['date']) : date('Y-m-d');
if (!is_valid_date_string($date)) {
    fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD.\n");
    exit(2);
}
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);
$dateLabel = format_date($date);

$lockHandle = @fopen(sys_get_temp_dir() . '/block_plant_attendance_reminder.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Skipped: another attendance reminder run is already in progress.\n";
    if ($lockHandle) {
        fclose($lockHandle);
    }
    exit(0);
}

try {
    $exitCode = run_attendance_reminder($pdo, $date, $force, $dryRun, $dateLabel, $settings);
} catch (Throwable $e) {
    fwrite(STDERR, "Attendance reminder failed: " . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);

/**
 * @return int process exit code
 */
function run_attendance_reminder(PDO $pdo, string $date, bool $force, bool $dryRun, string $dateLabel, array $settings): int
{
    if (!$force && !is_working_day($date)) {
        echo "Skipped: {$dateLabel} is not a working day.\n";
        return 0;
    }

    // One log row per date makes repeated cron runs safe.
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_reminder_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_date DATE NOT NULL,
        active_count INT NOT NULL DEFAULT 0,
        missing_count INT NOT NULL DEFAULT 0,
        recipients INT NOT NULL DEFAULT 0,
        sent_at DATETIME NOT NULL,
        UNIQUE KEY unique_attendance_reminder_date (reminder_date)
    )");

    $logStmt = $pdo->prepare("SELECT id FROM attendance_reminder_log WHERE reminder_date = ?");
    $logStmt->execute([$date]);
    if ($logStmt->fetchColumn()) {
        echo "Skipped: a reminder was already sent for {$dateLabel}.\n";
        return 0;
    }

    $missing = attendance_missing_for_date($date);
    if ($missing['is_complete']) {
        echo "Skipped: attendance is complete for {$dateLabel} ({$missing['recorded_count']} of {$missing['active_count']} active employees recorded).\n";
        return 0;
    }

    $recipients = attendance_reminder_recipients($pdo);
    if (!$recipients) {
        echo "Skipped: no HR/admin recipients found.\n";
        return 0;
    }

    $message = "Attendance for {$dateLabel} is incomplete: {$missing['missing_count']} of {$missing['active_count']} active employees have no record.";
    $message = mb_substr($message, 0, 255);
    $link = '/block_plant/hr/attendance.php?date=' . rawurlencode($date);

    $missingNames = [];
    foreach (array_slice($missing['missing'], 0, 30) as $employee) {
        $name = trim((string) ($employee['name'] ?? ''));
        $code = trim((string) ($employee['employee_code'] ?? ''));
        $department = trim((string) ($employee['department'] ?? ''));
        $parts = array_filter([
            $name,
            $code !== '' ? '(' . $code . ')' : '',
            $department !== '' ? '— ' . $department : '',
        ]);
        $missingNames[] = '- ' . implode(' ', $parts);
    }
    if ($missing['missing_count'] > 30) {
        $missingNames[] = '- ... and ' . ($missing['missing_count'] - 30) . ' more';
    }

    $emailBody = "Attendance for {$dateLabel} is incomplete.\n"
        . "{$missing['missing_count']} of {$missing['active_count']} active employees have no attendance record.\n\n"
        . implode("\n", $missingNames) . "\n\n"
        . "Open Daily Attendance: /block_plant/hr/attendance.php?date=" . $date . "\n";

    echo "Date: {$dateLabel} ({$date})\n";
    echo "Active employees: {$missing['active_count']}\n";
    echo "Recorded: {$missing['recorded_count']}\n";
    echo "Missing: {$missing['missing_count']}\n";
    echo "Recipients: " . count($recipients) . "\n";
    foreach ($recipients as $recipient) {
        echo "  - #" . (int) $recipient['id'] . " " . (string) $recipient['name'] . "\n";
    }

    if ($dryRun) {
        echo "Dry run: no notifications or log rows were written.\n";
        return 0;
    }

    ensure_notifications_schema();

    $pdo->beginTransaction();
    try {
        foreach ($recipients as $recipient) {
            if (!notify_user((int) $recipient['id'], $message, $link)) {
                throw new RuntimeException('Could not create a notification for user #' . (int) $recipient['id']);
            }
        }
        $insert = $pdo->prepare("INSERT INTO attendance_reminder_log
            (reminder_date, active_count, missing_count, recipients, sent_at)
            VALUES (?, ?, ?, ?, ?)");
        $insert->execute([
            $date,
            $missing['active_count'],
            $missing['missing_count'],
            count($recipients),
            date('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $email = trim((string) ($settings['email'] ?? ''));
    if ($email === '') {
        $email = 'admin@blockplant.test';
    }
    send_email_notification($email, 'Attendance reminder - ' . $dateLabel, $emailBody);

    echo "Sent " . count($recipients) . " in-app notification(s) and one email log entry.\n";
    return 0;
}

/**
 * Users who can see and save the daily attendance register.
 *
 * @return array<int,array{id:int|string,name:string}>
 */
function attendance_reminder_recipients(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT DISTINCT u.id, u.name
             FROM users u
             LEFT JOIN user_permissions up ON up.user_id = u.id AND up.module = 'hr'
             WHERE u.status = 1
               AND (
                   LOWER(u.role) IN ('admin', 'administrator', 'hr')
                   OR (LOWER(COALESCE(u.department, '')) IN ('hr', 'human resources')
                       AND (LOWER(COALESCE(u.department_role, '')) = 'manager' OR LOWER(u.role) = 'manager'))
                   OR up.can_edit = 1
               )
             ORDER BY u.id"
        )->fetchAll();
    } catch (Throwable $e) {
        // Older databases may not have user_permissions/department_role yet.
        return $pdo->query(
            "SELECT id, name FROM users
             WHERE status = 1 AND LOWER(role) IN ('admin', 'administrator', 'hr', 'manager')
             ORDER BY id"
        )->fetchAll();
    }
}
