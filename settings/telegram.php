<?php
/**
 * Settings > Telegram Alerts.
 *
 * Saves the bot token / chat ID, the seven alert triggers, and offers the
 * three things an administrator needs while setting the bot up: test the
 * connection, capture the chat ID from the bot's updates, and send today's
 * report immediately.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../config/telegram.php";
require_permission('settings', 'view');

$canEdit = has_permission('settings', 'edit');
$settings = telegram_settings(true);
$timezone = apply_company_timezone();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        exit('Access denied');
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $token = trim((string) ($_POST['bot_token'] ?? ''));
        $chatId = trim((string) ($_POST['chat_id'] ?? ''));
        $errors = [];

        if ($token !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            $errors[] = 'The bot token does not look like a Telegram token (123456789:AA...). Copy it again from @BotFather.';
        }
        if ($chatId !== '' && !preg_match('/^(-?\d{3,}|@[A-Za-z0-9_]{4,})$/', $chatId)) {
            $errors[] = 'The chat ID must be a number such as 123456789, or @channelname for a channel.';
        }

        if ($errors) {
            set_flash('danger', implode(' ', $errors));
            header('Location: telegram.php');
            exit;
        }

        telegram_save_settings([
            'bot_token' => $token,
            'chat_id' => $chatId,
            'is_enabled' => $_POST['is_enabled'] ?? 0,
            'send_time' => $_POST['send_time'] ?? '18:00',
            'attach_csv' => $_POST['attach_csv'] ?? 0,
            'alert_missing_attendance' => $_POST['alert_missing_attendance'] ?? 0,
            'alert_absences' => $_POST['alert_absences'] ?? 0,
            'alert_late' => $_POST['alert_late'] ?? 0,
            'alert_pending_approvals' => $_POST['alert_pending_approvals'] ?? 0,
            'alert_low_stock' => $_POST['alert_low_stock'] ?? 0,
            'alert_overdue_balances' => $_POST['alert_overdue_balances'] ?? 0,
            'alert_asset_maintenance' => $_POST['alert_asset_maintenance'] ?? 0,
            'resolve_ip' => $_POST['resolve_ip'] ?? '',
        ] + array_combine(
            array_keys(telegram_extra_option_keys()),
            array_map(function ($key) { return $_POST[$key] ?? 0; }, array_keys(telegram_extra_option_keys()))
        ));

        log_activity('update', 'settings', 1, 'Telegram alert settings updated');
        set_flash('success', 'Telegram settings saved.');
        header('Location: telegram.php');
        exit;
    }

    if ($action === 'test') {
        $me = telegram_call('getMe');
        if (empty($me['ok'])) {
            $reason = (string) ($me['description'] ?? $me['_error'] ?? 'Unknown error');
            telegram_set_last_error('Connection test failed: ' . $reason);
            set_flash('danger', 'Could not reach the bot: ' . $reason);
        } else {
            $username = (string) ($me['result']['username'] ?? '');
            if ($username !== '') {
                try {
                    $pdo->prepare("UPDATE telegram_settings SET bot_username = ? WHERE id = 1")->execute([$username]);
                } catch (Throwable $e) {
                    // Ignore.
                }
            }

            $text = "✅ <b>Block Plant ERP</b>\n"
                . 'Test message from ' . telegram_escape((string) (company_settings()['company_name'] ?? 'Block Plant ERP')) . ".\n"
                . 'Bot: @' . telegram_escape($username !== '' ? $username : 'unknown') . "\n"
                . telegram_escape(date('d M Y H:i'));
            $result = telegram_send_message($text);
            telegram_log('test', $result['ok'] ? 'sent' : 'failed', $text, $result['ok'] ? 'sent' : $result['error']);
            if ($result['ok']) {
                telegram_set_last_error('');
                set_flash('success', 'Test message sent to ' . (string) ($settings['chat_id'] ?? '') . '. Check Telegram.');
            } else {
                telegram_set_last_error('Test message failed: ' . $result['error']);
                set_flash('danger', 'The bot answered, but the message could not be delivered: ' . $result['error']);
            }
        }
        header('Location: telegram.php');
        exit;
    }

    if ($action === 'send_now') {
        $result = telegram_send_daily_report(date('Y-m-d'), [
            'attach' => !empty($settings['attach_csv']),
        ]);
        if ($result['ok']) {
            set_flash('success', "Today's report was sent"
                . ($result['attachment'] ? ' with the attendance CSV.' : '.')
                . ' (' . $result['sent'] . ' message part' . ($result['sent'] === 1 ? '' : 's') . ')');
        } else {
            set_flash('danger', "The report could not be sent: " . implode(' ', $result['errors']));
        }
        header('Location: telegram.php');
        exit;
    }

    if ($action === 'find_chat') {
        $found = telegram_find_chats();
        if (empty($found['ok'])) {
            set_flash('danger', 'Could not read the bot updates: ' . $found['error']);
        } elseif (!$found['chats']) {
            set_flash('danger', 'No messages found yet. Open Telegram, start a chat with @'
                . (($settings['bot_username'] ?? '') !== '' ? $settings['bot_username'] : 'your bot')
                . ', tap Start (or send /start), then press "Find chat ID" again.');
        } else {
            $messages = [];
            foreach ($found['chats'] as $chat) {
                $messages[] = $chat['label'] . ' — ID ' . $chat['id']
                    . ($chat['username'] !== '' ? ' (@' . $chat['username'] . ')' : '');
            }

            if (trim((string) $settings['chat_id']) === '' && count($found['chats']) === 1) {
                telegram_save_settings(array_merge($settings, ['chat_id' => $found['chats'][0]['id']]));
                set_flash('success', 'Found ' . $messages[0] . '. It was saved as the chat ID.');
            } else {
                set_flash('success', 'Chats that have messaged the bot: ' . implode(' · ', $messages));
            }
        }
        header('Location: telegram.php');
        exit;
    }

    header('Location: telegram.php');
    exit;
}

$settings = telegram_settings(true);
$flash = get_flash();
$configured = telegram_is_configured($settings);
$enabled = !empty($settings['is_enabled']);
$botUsername = trim((string) ($settings['bot_username'] ?? ''));

$logRows = [];
try {
    $logRows = $pdo->query("SELECT * FROM telegram_message_log ORDER BY id DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {
    $logRows = [];
}

$pageTitle = 'Telegram Alerts';
require_once "../partials/header.php";
?>

<style>
    .tg-status { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:18px; }
    .tg-status__card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; border-top:3px solid #cbd5e1; }
    .tg-status__card.ok { border-top-color:#15803d; }
    .tg-status__card.warn { border-top-color:#ea580c; }
    .tg-status__card.bad { border-top-color:#dc2626; }
    .tg-status__card strong { display:block; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:6px; }
    .tg-status__card span { font-size:1.02rem; font-weight:700; color:#0f172a; }
    .tg-status__card small { display:block; color:#64748b; margin-top:4px; font-size:.76rem; }
    .tg-steps { margin:0; padding-left:20px; color:#475569; line-height:1.7; }
    .tg-steps code { background:#f1f5f9; padding:1px 5px; border-radius:4px; }
    .tg-toggles { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:6px 18px; margin-top:6px; }
    .tg-toggle { display:flex; align-items:flex-start; gap:9px; padding:7px 0; font-weight:500; }
    .tg-toggle input { width:auto; margin:3px 0 0; }
    .tg-toggle span small { display:block; color:#64748b; font-weight:400; font-size:.76rem; }
    .tg-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:16px; }
    .tg-code { background:#0f172a; color:#e2e8f0; border-radius:10px; padding:12px 14px; font-family:Consolas,Monaco,monospace; font-size:.8rem; overflow-x:auto; white-space:pre; }
    .tg-log { width:100%; border-collapse:collapse; font-size:.82rem; }
    .tg-log th, .tg-log td { text-align:left; padding:7px 9px; border-bottom:1px solid #e2e8f0; vertical-align:top; }
    .tg-log th { color:#64748b; font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; }
    .tg-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:700; }
    .tg-pill.sent { background:#dcfce7; color:#166534; }
    .tg-pill.failed { background:#fee2e2; color:#991b1b; }
    .tg-pill.skipped { background:#fef3c7; color:#92400e; }
</style>

<div class="page-header">
    <div>
        <h1>Telegram Alerts</h1>
        <p style="color:#64748b;margin:6px 0 0;">
            Send the daily attendance report to Telegram every working day and get a message when something needs your attention.
        </p>
    </div>
    <div class="header-actions no-print">
        <?php if ($botUsername !== ''): ?>
        <a href="https://t.me/<?= e($botUsername) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Open @<?= e($botUsername) ?></a>
        <?php endif; ?>
        <a href="/block_plant/hr/attendance_sheet.php" class="btn btn-secondary">Attendance sheet</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div class="tg-status">
    <div class="tg-status__card <?= $configured ? 'ok' : 'bad' ?>">
        <strong>Connection</strong>
        <span><?= $configured ? 'Ready' : 'Not configured' ?></span>
        <small><?= $configured ? 'Bot token and chat ID are saved.' : 'Save a bot token and a chat ID first.' ?></small>
    </div>
    <div class="tg-status__card <?= $enabled ? 'ok' : 'warn' ?>">
        <strong>Daily report</strong>
        <span><?= $enabled ? 'Enabled' : 'Paused' ?></span>
        <small>Target time <?= e(substr((string) $settings['send_time'], 0, 5)) ?> · Mon–Fri · <?= e($timezone) ?></small>
    </div>
    <div class="tg-status__card <?= !empty($settings['last_sent_at']) ? 'ok' : 'warn' ?>">
        <strong>Last sent</strong>
        <span><?= !empty($settings['last_sent_at']) ? e(format_datetime($settings['last_sent_at'])) : 'Never' ?></span>
        <small><?= !empty($settings['last_sent_date']) ? 'Report date ' . e(format_date($settings['last_sent_date'])) : 'No report has been delivered yet.' ?></small>
    </div>
    <div class="tg-status__card <?= !empty($settings['last_error']) ? 'bad' : 'ok' ?>">
        <strong>Last error</strong>
        <span><?= !empty($settings['last_error']) ? 'Needs attention' : 'None' ?></span>
        <small><?= !empty($settings['last_error']) ? e(mb_substr((string) $settings['last_error'], 0, 140)) : 'The last send completed cleanly.' ?></small>
    </div>
</div>

<?php if (!$configured): ?>
<div class="panel" style="max-width:980px;">
    <h3 style="margin-top:0;">Set up in three steps</h3>
    <ol class="tg-steps">
        <li>Open Telegram and start a chat with your bot (<strong>@<?= e($botUsername !== '' ? $botUsername : 'Allehrbot') ?></strong>). Tap <strong>Start</strong> or send <code>/start</code>.</li>
        <li>Paste the bot token from @BotFather below and press <strong>Save settings</strong>.</li>
        <li>Press <strong>Find chat ID</strong>. The bot reads the message you just sent and saves your chat automatically.</li>
    </ol>
</div>
<?php endif; ?>

<form method="post" class="panel" style="max-width:980px;">
    <input type="hidden" name="action" value="save">

    <h3 style="margin-top:0;">Bot connection</h3>
    <div class="grid-2">
        <div>
            <label>Bot token</label>
            <input type="password" name="bot_token" autocomplete="new-password"
                   placeholder="<?= $settings['bot_token'] !== '' ? e(telegram_mask_token($settings['bot_token'])) : '123456789:AA...' ?>">
            <p class="hint">From @BotFather. Leave blank to keep the saved token.</p>
        </div>
        <div>
            <label>Chat ID</label>
            <input type="text" name="chat_id" maxlength="64" value="<?= e($settings['chat_id']) ?>" placeholder="123456789">
            <p class="hint">Your personal ID, or a negative number for a group, or @channelname for a channel.</p>
        </div>
        <div>
            <label>Send time</label>
            <input type="time" name="send_time" value="<?= e(substr((string) $settings['send_time'], 0, 5)) ?>">
            <p class="hint">Used by the scheduled task. The script sends at or after this time, with a 15-minute grace. Times follow the company timezone (<?= e($timezone) ?>).</p>
        </div>
        <div>
            <label>Daily report</label>
            <label class="tg-toggle" style="margin-top:8px;">
                <input type="checkbox" name="is_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <span>Send the daily report automatically<small>Mon–Fri at the send time above. Manual "Send now" still works while paused.</small></span>
            </label>
        </div>
    </div>

    <h3>Attachment</h3>
    <label class="tg-toggle">
        <input type="checkbox" name="attach_csv" value="1" <?= !empty($settings['attach_csv']) ? 'checked' : '' ?>>
        <span>Attach today's attendance as a CSV file<small>Opens in Excel. One row per employee, including the people with no record.</small></span>
    </label>

    <h3>What to include in the daily message</h3>
    <p style="color:#64748b;font-size:.85rem;margin:-6px 0 10px;">Tick the sections you want to receive. Unticked sections are left out of the message.</p>
    <div class="tg-toggles">
        <label class="tg-toggle">
            <input type="checkbox" name="show_glance" value="1" <?= !empty($settings['show_glance']) ? 'checked' : '' ?>>
            <span>Attendance at a glance<small>Present, late, absent, leave and not-recorded counts with a progress bar.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="show_leave_halfday" value="1" <?= !empty($settings['show_leave_halfday']) ? 'checked' : '' ?>>
            <span>People on leave and half-day<small>Names of employees on approved leave or working half-days.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="show_payroll" value="1" <?= !empty($settings['show_payroll']) ? 'checked' : '' ?>>
            <span>Payroll period summary<small>Present, absent and leave days and late time for the 16th to 15th period.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="show_employee_days" value="1" <?= !empty($settings['show_employee_days']) ? 'checked' : '' ?>>
            <span>Days present by employee<small>One line per employee inside the payroll period.</small></span>
        </label>
    </div>

    <h3>Business figures to send</h3>
    <div class="tg-toggles">
        <label class="tg-toggle">
            <input type="checkbox" name="sum_sales" value="1" <?= !empty($settings['sum_sales']) ? 'checked' : '' ?>>
            <span>Sales<small>Total sales and number of invoices for the day.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="sum_payments" value="1" <?= !empty($settings['sum_payments']) ? 'checked' : '' ?>>
            <span>Payments collected<small>Money received from customers that day.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="sum_expenses" value="1" <?= !empty($settings['sum_expenses']) ? 'checked' : '' ?>>
            <span>Expenses<small>Approved expenses for the day.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="sum_production" value="1" <?= !empty($settings['sum_production']) ? 'checked' : '' ?>>
            <span>Blocks produced<small>Good blocks made that day.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="sum_stock" value="1" <?= !empty($settings['sum_stock']) ? 'checked' : '' ?>>
            <span>Finished blocks in stock<small>Total blocks currently in stock.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="sum_cash" value="1" <?= !empty($settings['sum_cash']) ? 'checked' : '' ?>>
            <span>Cash in and out<small>Cashbook money in and out for the day.</small></span>
        </label>
    </div>

    <h3>Other messages</h3>
    <div class="tg-toggles">
        <label class="tg-toggle">
            <input type="checkbox" name="send_labor_reminder" value="1" <?= !empty($settings['send_labor_reminder']) ? 'checked' : '' ?>>
            <span>Saturday labor payment reminder<small>Cement bags and the total to pay, sent before the 5:00 PM payday.</small></span>
        </label>
    </div>

    <h3>What counts as "needs my attention"</h3>
    <div class="tg-toggles">
        <label class="tg-toggle">
            <input type="checkbox" name="alert_missing_attendance" value="1" <?= !empty($settings['alert_missing_attendance']) ? 'checked' : '' ?>>
            <span>Missing attendance entries<small>Active employees with no row for the day.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_absences" value="1" <?= !empty($settings['alert_absences']) ? 'checked' : '' ?>>
            <span>Absences without approved leave<small>An absent row with no approved leave behind it.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_late" value="1" <?= !empty($settings['alert_late']) ? 'checked' : '' ?>>
            <span>Late arrivals<small>Everyone marked late, with the clock-in time and minutes.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_pending_approvals" value="1" <?= !empty($settings['alert_pending_approvals']) ? 'checked' : '' ?>>
            <span>Pending approvals<small>Leave, overtime and employee loan requests.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_low_stock" value="1" <?= !empty($settings['alert_low_stock']) ? 'checked' : '' ?>>
            <span>Low stock<small>Raw materials at or below the reorder point.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_overdue_balances" value="1" <?= !empty($settings['alert_overdue_balances']) ? 'checked' : '' ?>>
            <span>Overdue customer balances<small>Invoices unpaid for more than 30 days.</small></span>
        </label>
        <label class="tg-toggle">
            <input type="checkbox" name="alert_asset_maintenance" value="1" <?= !empty($settings['alert_asset_maintenance']) ? 'checked' : '' ?>>
            <span>Upcoming asset maintenance<small>Due in the next seven days, or already overdue.</small></span>
        </label>
    </div>

    <h3>Advanced</h3>
    <label>Telegram IP address (optional)</label>
    <input type="text" name="resolve_ip" maxlength="45" value="<?= e($settings['resolve_ip']) ?>" placeholder="149.154.167.220">
    <p class="hint">
        The office DNS does not resolve <code>api.telegram.org</code>, so the app connects to a pinned Telegram address.
        Leave this empty to use the built-in addresses; change it only if Telegram becomes unreachable.
    </p>

    <button type="submit" class="btn btn-accent" style="margin-top:20px;">Save settings</button>
</form>

<div class="panel" style="max-width:980px;">
    <h3 style="margin-top:0;">Actions</h3>
    <p style="color:#64748b;margin-top:0;">Save the settings first; these buttons use what is stored.</p>
    <div class="tg-actions">
        <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="find_chat">
            <button type="submit" class="btn btn-secondary">Find chat ID</button>
        </form>
        <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="test">
            <button type="submit" class="btn btn-secondary">Send test message</button>
        </form>
        <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="send_now">
            <button type="submit" class="btn btn-accent">Send today's report now</button>
        </form>
    </div>
</div>

<div class="panel" style="max-width:980px;">
    <h3 style="margin-top:0;">Automatic schedule</h3>
    <p style="color:#64748b;margin-top:0;">
        The app itself does not keep a timer. Windows Task Scheduler runs <code>cron\telegram_daily.php</code> every working day at the send time.
        The script is safe to run more than once: it remembers the last report date and skips a second send.
    </p>
    <div class="tg-code">schtasks /Create /TN "Block Plant Telegram Daily" /TR "\"G:\xampp\php\php.exe\" \"G:\xampp\htdocs\block_plant\cron\telegram_daily.php\"" /SC DAILY /ST 18:00 /F</div>
    <p style="color:#64748b;margin:10px 0 0;">
        The script skips Saturday and Sunday itself. Run <code>cron\telegram_daily.bat --dry-run</code> to preview the message without sending it.
    </p>
</div>

<div class="panel" style="max-width:980px;">
    <h3 style="margin-top:0;">Recent Telegram messages</h3>
    <?php if (!$logRows): ?>
    <p style="color:#64748b;margin:0;">Nothing has been sent yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="tg-log">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Type</th>
                    <th>Report date</th>
                    <th>Status</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logRows as $row): ?>
                <tr>
                    <td><?= e(format_datetime($row['created_at'])) ?></td>
                    <td><?= e($row['message_type']) ?></td>
                    <td><?= !empty($row['report_date']) ? e(format_date($row['report_date'])) : '—' ?></td>
                    <td><span class="tg-pill <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td>
                    <td><?= e(mb_substr((string) ($row['response'] ?? ''), 0, 120)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once "../partials/footer.php"; ?>
