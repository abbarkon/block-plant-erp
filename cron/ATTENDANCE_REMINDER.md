# Attendance Reminder

`cron/attendance_reminder.php` checks the active employees for a date and sends
one reminder when any of them have no attendance row.

- **Working days:** Monday-Friday by default. To include Saturday, define
  `ATTENDANCE_WORKING_DAYS` as `[1, 2, 3, 4, 5, 6]` before `config/helpers.php`
  loads, or change the fallback list in `is_working_day()`.
- **Send time:** the scheduler decides. Schedule it after the workday (the
  example below uses 5:00 PM local time). The script does not enforce a clock
  time, so use `--dry-run` for a safe daytime preview.
- **Recipients:** active admins, HR users/managers, and users with HR **edit**
  permission.
- **Channels:** in-app notifications for every recipient plus an email entry in
  `notification_log.txt`. The existing `send_email_notification()` is a mock;
  configure real SMTP before relying on delivered email.
- **No duplicates:** `attendance_reminder_log` allows one reminder per date.
  A completed register, a weekend, and a repeated run are skipped.

## Manual test

```bat
cron\attendance_reminder.bat --dry-run
cron\attendance_reminder.bat --date=2026-10-15 --dry-run
```

`--force` bypasses the working-day check. It does not bypass the
one-reminder-per-date log.

## Windows Task Scheduler

Run **Task Scheduler > Create Task** and use:

- **Trigger:** Weekly, Monday through Friday, 5:00 PM
- **Action:** Start a program
- **Program/script:** `cmd.exe`
- **Add arguments:** `/c "G:\xampp\htdocs\block_plant\cron\attendance_reminder.bat"`

The task runs as the logged-on Windows user, so XAMPP/MySQL needs to be
running. The in-app HR banner still reminds users who open the app later.

Or create it from an elevated Command Prompt:

```bat
schtasks /Create /SC WEEKLY /D MON,TUE,WED,THU,FRI /TN "BlockPlant Attendance Reminder" /TR "cmd.exe /c G:\xampp\htdocs\block_plant\cron\attendance_reminder.bat" /ST 17:00 /F
```

On a Linux host, use:

```cron
0 17 * * 1-5 php /path/to/block_plant/cron/attendance_reminder.php
```
