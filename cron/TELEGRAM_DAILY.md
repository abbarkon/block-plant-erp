# Daily Telegram Report

Sends the attendance report and the "needs attention" alerts to Telegram every
working day. Configure it once in **Settings → Telegram Alerts**, then let
Windows Task Scheduler run `cron\telegram_daily.php` at 18:00.

## What the message contains

- Today's counts: active employees, present, late, absent, on leave, half-day, not recorded.
- Late arrivals with clock-in time and minutes.
- Absences without approved leave.
- Approved leave and half-day employees.
- Employees with no attendance row.
- The 16–15 payroll period: present/absent/leave totals, late time, and days present per employee.
- Alerts: pending approvals, low stock, overdue customer balances, upcoming asset maintenance.
- Optionally a CSV of the day's attendance (opens in Excel).

The report uses the same `attendance_sheet_rows()` calculation as the printable
sheet, so the numbers always match.

## Setup

1. Open **Settings → Telegram Alerts**.
2. Paste the bot token from @BotFather and press **Save settings**.
3. Open Telegram, start a chat with the bot, and tap **Start** (or send `/start`).
4. Press **Find chat ID**. The saved chat is filled in automatically.
5. Press **Send test message** to confirm delivery.
6. Tick **Send the daily report automatically** and press **Save settings**.

## Manual runs

```bat
cd G:\xampp\htdocs\block_plant\cron

rem Preview the message without sending
telegram_daily.bat --dry-run

rem Send today's report now
telegram_daily.bat --force

rem Re-send a specific date
telegram_daily.bat --date=2026-10-31 --force

rem Text only, no CSV attachment
telegram_daily.bat --force --no-attachment
```

| Flag | Meaning |
| --- | --- |
| `--date=YYYY-MM-DD` | Report date (default: today) |
| `--force` | Ignore "already sent", the working-day rule, the send time and the enabled toggle |
| `--dry-run` | Print the message and exit without sending |
| `--no-attachment` | Do not attach the CSV |

Exit codes: `0` success or a deliberate skip, `1` send failure, `2` bad date.

## Windows Task Scheduler

### Command line (run once, no admin needed)

```bat
schtasks /Create /TN "Block Plant Telegram Daily" /TR "\"G:\xampp\php\php.exe\" \"G:\xampp\htdocs\block_plant\cron\telegram_daily.php\"" /SC DAILY /ST 18:00 /F
```

The script skips Saturday and Sunday itself, so a daily trigger is enough.

### Task Scheduler window

1. Open **Task Scheduler → Create Basic Task**.
2. Name: `Block Plant Telegram Daily`.
3. Trigger: **Daily**, start `18:00`.
4. Action: **Start a program**.
   - Program: `G:\xampp\php\php.exe`
   - Arguments: `"G:\xampp\htdocs\block_plant\cron\telegram_daily.php"`
   - Start in: `G:\xampp\htdocs\block_plant\cron`
5. Finish, then open the task's properties and tick **Run task as soon as possible after a scheduled start is missed** (in case the PC was off at 18:00).

If the task is scheduled more often (for example every 15 minutes), the script
still sends only once: it refuses to run before the saved send time and skips a
date that already has a successful log row.

## Timezone

The send time is a wall-clock time in the **company timezone**
(`company_settings.timezone`). Both scheduled scripts call
`apply_company_timezone()`, so "18:00" means 18:00 in the office even when the
PHP default or the server clock uses another zone. If the stored timezone is
empty or not a valid identifier, the scripts fall back to
`Africa/Addis_Ababa`.

A mismatch here is the usual reason a report "never sends": the task runs at
18:00, but the script thinks it is 15:00 and skips because it is too early. The
settings page shows the timezone it is using on the **Daily report** card.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `Skipped: the bot token or the chat ID is missing` | Save both fields on the settings page. |
| `Skipped: Telegram alerts are disabled` | Tick "Send the daily report automatically", or use `--force`. |
| `Skipped: the report for ... was already sent` | Expected. Use `--force` to re-send. |
| `Skipped: scheduled for 18:00, it is 15:20 now` | The timezone is wrong. Set `company_settings.timezone` to the office zone (`Africa/Addis_Ababa`). |
| `401 Unauthorized` | The token is wrong or was revoked in @BotFather. |
| `403 Forbidden: bot was blocked by the user` | Open the chat in Telegram and press Start again. |
| `chat not found` | The chat ID is wrong. Press **Find chat ID** after sending `/start` to the bot. |
| `Could not reach api.telegram.org` | The office DNS does not resolve Telegram. The script already tries pinned Telegram IPs; if Telegram moved, set the new address under **Advanced → Telegram IP address**. |

## Files

| File | Purpose |
| --- | --- |
| `config/telegram.php` | Schema, Bot API client, report builder, CSV, logging |
| `settings/telegram.php` | Settings screen and test/send-now/find-chat actions |
| `cron/telegram_daily.php` | Scheduled send with lock, flags and idempotency |
| `cron/telegram_daily.bat` | Task Scheduler wrapper using the XAMPP PHP binary |
| `install/upgrade_telegram.sql` | Optional SQL for an existing database |

The app creates `telegram_settings` and `telegram_message_log` automatically on
first use, so the SQL file is only needed when you prefer to run the DDL by hand.
