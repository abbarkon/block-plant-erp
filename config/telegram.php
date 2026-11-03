<?php
/**
 * Telegram bot integration.
 *
 * Sends the daily attendance report and the "needs attention" alerts to a
 * Telegram chat. Everything is configured from Settings > Telegram Alerts and
 * the actual send is triggered by cron/telegram_daily.php (Windows Task
 * Scheduler or a normal cron job).
 *
 * The office DNS servers do not resolve api.telegram.org, so every request
 * first tries the normal resolver and then falls back to pinned Telegram IPs
 * (CURLOPT_RESOLVE). A custom IP can be saved in the settings when Telegram
 * moves its edge servers.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Create the two Telegram tables. Idempotent, safe to call on every request
 * that touches Telegram data.
 */
function ensure_telegram_schema() {
    global $pdo;
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_token VARCHAR(200) NULL,
        bot_username VARCHAR(100) NULL,
        chat_id VARCHAR(64) NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        send_time TIME NOT NULL DEFAULT '18:00:00',
        attach_csv TINYINT(1) NOT NULL DEFAULT 1,
        alert_missing_attendance TINYINT(1) NOT NULL DEFAULT 1,
        alert_absences TINYINT(1) NOT NULL DEFAULT 1,
        alert_late TINYINT(1) NOT NULL DEFAULT 1,
        alert_pending_approvals TINYINT(1) NOT NULL DEFAULT 1,
        alert_low_stock TINYINT(1) NOT NULL DEFAULT 1,
        alert_overdue_balances TINYINT(1) NOT NULL DEFAULT 1,
        alert_asset_maintenance TINYINT(1) NOT NULL DEFAULT 1,
        resolve_ip VARCHAR(45) NULL,
        last_sent_date DATE NULL,
        last_sent_at DATETIME NULL,
        last_error TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_message_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_type VARCHAR(30) NOT NULL DEFAULT 'daily',
        report_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'sent',
        chat_id VARCHAR(64) NULL,
        message MEDIUMTEXT NULL,
        response TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_telegram_log_date (report_date, message_type),
        INDEX idx_telegram_log_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO telegram_settings (id, send_time) VALUES (1, '18:00:00')
                ON DUPLICATE KEY UPDATE id = id");

    $checked = true;
}

/**
 * Default values used before the first save.
 */
function telegram_default_settings() {
    return [
        'id' => 1,
        'bot_token' => '',
        'bot_username' => '',
        'chat_id' => '',
        'is_enabled' => 0,
        'send_time' => '18:00:00',
        'attach_csv' => 1,
        'alert_missing_attendance' => 1,
        'alert_absences' => 1,
        'alert_late' => 1,
        'alert_pending_approvals' => 1,
        'alert_low_stock' => 1,
        'alert_overdue_balances' => 1,
        'alert_asset_maintenance' => 1,
        'resolve_ip' => '',
        'last_sent_date' => null,
        'last_sent_at' => null,
        'last_error' => null,
        'updated_at' => null,
    ];
}

/**
 * Read the single settings row, creating it when missing.
 *
 * @param bool $reload Bypass the in-request cache after a save.
 */
function telegram_settings($reload = false) {
    global $pdo;
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }

    ensure_telegram_schema();
    try {
        $row = $pdo->query("SELECT * FROM telegram_settings WHERE id = 1")->fetch();
    } catch (Throwable $e) {
        $row = false;
    }

    $cache = array_merge(telegram_default_settings(), is_array($row) ? $row : []);
    return $cache;
}

/**
 * Save the settings form. A blank token keeps the stored token, so the field
 * can be left masked/empty without wiping the bot.
 */
function telegram_save_settings(array $data) {
    global $pdo;
    ensure_telegram_schema();
    $current = telegram_settings(true);

    $token = trim((string) ($data['bot_token'] ?? ''));
    if ($token === '') {
        $token = (string) $current['bot_token'];
    }

    $chatId = trim((string) ($data['chat_id'] ?? ''));
    $sendTime = trim((string) ($data['send_time'] ?? '18:00'));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $sendTime)) {
        $sendTime = '18:00';
    }
    if (strlen($sendTime) === 5) {
        $sendTime .= ':00';
    }

    $resolveIp = trim((string) ($data['resolve_ip'] ?? ''));
    if ($resolveIp !== '' && !filter_var($resolveIp, FILTER_VALIDATE_IP)) {
        $resolveIp = '';
    }

    $bool = static function ($value) {
        return !empty($value) ? 1 : 0;
    };

    $stmt = $pdo->prepare("UPDATE telegram_settings SET
        bot_token = ?, chat_id = ?, is_enabled = ?, send_time = ?, attach_csv = ?,
        alert_missing_attendance = ?, alert_absences = ?, alert_late = ?,
        alert_pending_approvals = ?, alert_low_stock = ?, alert_overdue_balances = ?,
        alert_asset_maintenance = ?, resolve_ip = ?
        WHERE id = 1");
    $stmt->execute([
        $token,
        $chatId,
        $bool($data['is_enabled'] ?? 0),
        $sendTime,
        $bool($data['attach_csv'] ?? 0),
        $bool($data['alert_missing_attendance'] ?? 0),
        $bool($data['alert_absences'] ?? 0),
        $bool($data['alert_late'] ?? 0),
        $bool($data['alert_pending_approvals'] ?? 0),
        $bool($data['alert_low_stock'] ?? 0),
        $bool($data['alert_overdue_balances'] ?? 0),
        $bool($data['alert_asset_maintenance'] ?? 0),
        $resolveIp !== '' ? $resolveIp : null,
    ]);

    return telegram_settings(true);
}

/**
 * True when a token and a chat ID are saved.
 */
function telegram_is_configured($settings = null) {
    $settings = $settings ?: telegram_settings();
    return trim((string) ($settings['bot_token'] ?? '')) !== ''
        && trim((string) ($settings['chat_id'] ?? '')) !== '';
}

/**
 * Show only the bot id and the last four token characters in the UI.
 */
function telegram_mask_token($token) {
    $token = trim((string) $token);
    if ($token === '') {
        return '';
    }
    if (strpos($token, ':') === false) {
        return str_repeat('•', 8);
    }
    [$id, $secret] = explode(':', $token, 2);
    return $id . ':••••••••' . substr($secret, -4);
}

/**
 * Escape text for Telegram's HTML parse mode.
 */
function telegram_escape($text) {
    // Telegram only requires &, < and > to be escaped in message text. Quotes
    // are left alone so names like O'Brien stay readable.
    return htmlspecialchars((string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 157 minutes -> "2:37", 0 -> "" (or "0:00" when $zero is given).
 */
function telegram_format_minutes($minutes, $zero = '') {
    $minutes = max(0, (int) round((float) $minutes));
    if ($minutes === 0) {
        return $zero;
    }
    return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
}

/**
 * "5.67" / "6" from a day count.
 */
function telegram_format_days($value) {
    $value = (float) $value;
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

/**
 * Gregorian date label for the English Telegram message. format_date() follows
 * the app language, which would print an Ethiopian date inside an English
 * report when the company default is Amharic.
 */
function telegram_date($date) {
    $ts = strtotime((string) $date);
    return $ts === false ? '' : date('d M Y', $ts);
}

/**
 * Perform one Bot API call.
 *
 * Tries the system resolver first (when it works) and then each pinned IP.
 * Returns the raw body plus a small transport description for the log.
 *
 * @return array{ok:bool,http_code:int,body:string,error:string,transport:string}
 */
function telegram_http_request($method, array $payload = [], $isMultipart = false) {
    $settings = telegram_settings();
    $token = trim((string) ($settings['bot_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'No bot token is saved.', 'transport' => ''];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/' . ltrim((string) $method, '/');

    // Pinned IPs go first: the office DNS does not resolve Telegram at all, and
    // a failed lookup costs about twelve seconds. The system resolver is only
    // tried after every pinned address failed, in case Telegram moved.
    $candidates = [];
    $custom = trim((string) ($settings['resolve_ip'] ?? ''));
    if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_IP)) {
        $candidates[] = $custom;
    }
    foreach (['149.154.167.220', '149.154.166.110'] as $ip) {
        if (!in_array($ip, $candidates, true)) {
            $candidates[] = $ip;
        }
    }

    $perform = static function ($ip, $label) use ($url, $payload, $isMultipart) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $isMultipart ? $payload : http_build_query($payload),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'BlockPlantERP/1.0 (+telegram)',
        ];
        if (!$isMultipart) {
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
        if ($ip !== null) {
            $options[CURLOPT_RESOLVE] = ['api.telegram.org:443:' . $ip];
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode === 0) {
            return [
                'ok' => false,
                'http_code' => 0,
                'body' => '',
                'error' => $curlError !== '' ? $curlError : 'Connection failed via ' . $label,
                'transport' => '',
            ];
        }

        return [
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => (string) $body,
            'error' => '',
            'transport' => $label,
        ];
    };

    $lastError = 'Could not reach api.telegram.org.';
    foreach ($candidates as $ip) {
        $result = $perform($ip, 'ip:' . $ip);
        if ($result['http_code'] > 0) {
            return $result;
        }
        $lastError = $result['error'];
    }

    // Last resort: the system resolver (slow or broken on the office network).
    $resolved = @gethostbyname('api.telegram.org');
    if ($resolved !== 'api.telegram.org' && filter_var($resolved, FILTER_VALIDATE_IP)) {
        $result = $perform(null, 'dns:' . $resolved);
        if ($result['http_code'] > 0) {
            return $result;
        }
        $lastError = $result['error'];
    }

    return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => $lastError, 'transport' => ''];
}

/**
 * Call the Bot API and decode the JSON response.
 *
 * The returned array is Telegram's own response plus `_http_code`,
 * `_error` and `_transport`.
 */
function telegram_call($method, array $payload = [], $isMultipart = false) {
    $transport = telegram_http_request($method, $payload, $isMultipart);
    $decoded = json_decode($transport['body'], true);
    if (!is_array($decoded)) {
        $decoded = [
            'ok' => false,
            'description' => $transport['error'] !== ''
                ? $transport['error']
                : 'Telegram returned an unreadable response (HTTP ' . $transport['http_code'] . ').',
        ];
    }
    $decoded['_http_code'] = $transport['http_code'];
    $decoded['_error'] = $transport['error'];
    $decoded['_transport'] = $transport['transport'];
    return $decoded;
}

/**
 * Split a long message into Telegram-safe chunks on line boundaries.
 *
 * @return array<int,string>
 */
function telegram_chunk_text($text, $limit = 3800) {
    $text = trim((string) $text);
    if ($text === '') {
        return [''];
    }
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return [$text];
    }

    $chunks = [];
    $current = '';
    foreach (preg_split("/\r\n|\r|\n/", $text) as $line) {
        if (mb_strlen($line, 'UTF-8') > $limit) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            while (mb_strlen($line, 'UTF-8') > $limit) {
                $chunks[] = mb_substr($line, 0, $limit, 'UTF-8');
                $line = mb_substr($line, $limit, null, 'UTF-8');
            }
            $current = $line;
            continue;
        }

        $candidate = $current === '' ? $line : $current . "\n" . $line;
        if (mb_strlen($candidate, 'UTF-8') > $limit) {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return $chunks ?: [''];
}

/**
 * Send one message, splitting it when needed.
 *
 * @param array{chat_id?:string,silent?:bool} $options
 * @return array{ok:bool,sent:int,error:string,errors:array<int,string>}
 */
function telegram_send_message($text, array $options = []) {
    $settings = telegram_settings();
    $chatId = trim((string) ($options['chat_id'] ?? ($settings['chat_id'] ?? '')));
    if ($chatId === '') {
        return ['ok' => false, 'sent' => 0, 'error' => 'No chat ID is saved.', 'errors' => ['No chat ID is saved.']];
    }

    $chunks = telegram_chunk_text($text, 3800);
    $sent = 0;
    $errors = [];
    $last = count($chunks) - 1;

    foreach ($chunks as $index => $chunk) {
        if ($chunk === '') {
            continue;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $chunk,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ];
        if (!empty($options['silent'])) {
            $payload['disable_notification'] = 'true';
        }

        $response = telegram_call('sendMessage', $payload);
        if (!empty($response['ok'])) {
            $sent++;
        } else {
            // A malformed entity must never cost the whole report: retry the
            // same chunk as plain text.
            unset($payload['parse_mode']);
            $payload['text'] = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $retry = telegram_call('sendMessage', $payload);
            if (!empty($retry['ok'])) {
                $sent++;
            } else {
                $reason = (string) ($retry['description'] ?? $response['description'] ?? 'Unknown Telegram error');
                $errors[] = 'Part ' . ($index + 1) . ': ' . $reason;
            }
        }

        if ($index < $last) {
            usleep(250000);
        }
    }

    return [
        'ok' => $errors === [],
        'sent' => $sent,
        'error' => $errors ? implode(' ', $errors) : '',
        'errors' => $errors,
    ];
}

/**
 * Upload a file to the configured chat.
 *
 * @return array{ok:bool,error:string}
 */
function telegram_send_document($path, $filename, $caption = '') {
    $settings = telegram_settings();
    $chatId = trim((string) ($settings['chat_id'] ?? ''));
    if ($chatId === '') {
        return ['ok' => false, 'error' => 'No chat ID is saved.'];
    }
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Attachment not found: ' . $filename];
    }

    $payload = [
        'chat_id' => $chatId,
        'document' => new CURLFile($path, 'text/csv', $filename),
    ];
    if ($caption !== '') {
        $payload['caption'] = mb_substr($caption, 0, 1000, 'UTF-8');
        $payload['parse_mode'] = 'HTML';
    }

    $response = telegram_call('sendDocument', $payload, true);
    if (!empty($response['ok'])) {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => (string) ($response['description'] ?? 'Telegram rejected the attachment.')];
}

/**
 * Append a row to telegram_message_log. Never throws: logging must not break
 * a send that already succeeded.
 */
function telegram_log($type, $status, $message, $response = null, $reportDate = null) {
    global $pdo;
    try {
        ensure_telegram_schema();
        $settings = telegram_settings();
        $stmt = $pdo->prepare("INSERT INTO telegram_message_log
            (message_type, report_date, status, chat_id, message, response, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            mb_substr((string) $type, 0, 30, 'UTF-8'),
            $reportDate !== null && $reportDate !== '' ? $reportDate : null,
            mb_substr((string) $status, 0, 20, 'UTF-8'),
            (string) ($settings['chat_id'] ?? ''),
            (string) $message,
            $response !== null ? mb_substr((string) $response, 0, 60000, 'UTF-8') : null,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Ignore log failures.
    }
}

/**
 * Store or clear the last error shown on the settings page.
 */
function telegram_set_last_error($message) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE telegram_settings SET last_error = ? WHERE id = 1");
        $stmt->execute([$message !== null && $message !== '' ? (string) $message : null]);
        telegram_settings(true);
    } catch (Throwable $e) {
        // Ignore.
    }
}

/**
 * Chats that have talked to the bot (used by "Find chat ID").
 *
 * @return array{ok:bool,error:string,chats:array<int,array{id:string,label:string,username:string,type:string}>}
 */
function telegram_find_chats() {
    $response = telegram_call('getUpdates', ['limit' => 100, 'timeout' => 0]);
    if (empty($response['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($response['description'] ?? 'Could not read bot updates.'),
            'chats' => [],
        ];
    }

    $chats = [];
    foreach ((array) ($response['result'] ?? []) as $update) {
        $message = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? null;
        if (!is_array($message) || empty($message['chat'])) {
            continue;
        }
        $chat = $message['chat'];
        $id = (string) ($chat['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $label = trim((string) ($chat['title'] ?? ''));
        if ($label === '') {
            $label = trim(((string) ($chat['first_name'] ?? '')) . ' ' . ((string) ($chat['last_name'] ?? '')));
        }
        if ($label === '') {
            $label = (string) ($chat['username'] ?? ('Chat ' . $id));
        }
        $chats[$id] = [
            'id' => $id,
            'label' => $label,
            'username' => (string) ($chat['username'] ?? ''),
            'type' => (string) ($chat['type'] ?? ''),
        ];
    }

    return ['ok' => true, 'error' => '', 'chats' => array_values($chats)];
}

/**
 * The 16–15 payroll period that contains a given date.
 *
 * @return array{0:string,1:string}
 */
function telegram_payroll_period_for_date($date) {
    $parts = ethiopian_date_parts($date);
    if ($parts === null) {
        $ts = strtotime((string) $date);
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }

    $year = (int) $parts['year'];
    $month = (int) $parts['month'];
    $day = (int) $parts['day'];

    if ($month === 13) {
        $startYear = $year;
        $startMonth = 12;
    } elseif ($day >= 16) {
        $startYear = $year;
        $startMonth = $month;
    } elseif ($month === 1) {
        $startYear = $year - 1;
        $startMonth = 12;
    } else {
        $startYear = $year;
        $startMonth = $month - 1;
    }

    if ($startMonth === 12) {
        $endYear = $startYear + 1;
        $endMonth = 1;
    } else {
        $endYear = $startYear;
        $endMonth = $startMonth + 1;
    }

    return [
        ethiopian_to_gregorian($startYear, $startMonth, 16),
        ethiopian_to_gregorian($endYear, $endMonth, 15),
    ];
}

/**
 * Today's attendance rows and counts for one date.
 *
 * @return array{date:string,active_count:int,recorded_count:int,missing_count:int,missing:array,rows:array,counts:array,late:array,absent:array,on_leave:array,half_day:array}
 */
function telegram_attendance_day($date) {
    global $pdo;

    $missing = attendance_missing_for_date($date);
    $rows = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT a.employee_id, a.status, a.shift, a.clock_in, a.clock_out, a.notes,
                    e.name, e.employee_code, e.department
             FROM attendance a
             JOIN employees e ON e.id = a.employee_id
             WHERE a.attendance_date = ?
             ORDER BY e.name"
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $approvedLeave = [];
    try {
        $leaveStmt = $pdo->prepare(
            "SELECT DISTINCT employee_id FROM leave_requests
             WHERE status = 'approved' AND from_date <= ? AND to_date >= ?"
        );
        $leaveStmt->execute([$date, $date]);
        foreach ($leaveStmt->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
            $approvedLeave[(int) $employeeId] = true;
        }
    } catch (Throwable $e) {
        // Leave table unavailable: treat absences as unapproved.
    }

    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'half-day' => 0, 'leave' => 0, 'other' => 0];
    $late = [];
    $absent = [];
    $onLeave = [];
    $halfDay = [];

    foreach ($rows as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if (!isset($counts[$status])) {
            $status = 'other';
        }
        $counts[$status]++;

        $entry = [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'code' => (string) ($row['employee_code'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'clock_in' => substr((string) ($row['clock_in'] ?? ''), 0, 5),
            'clock_out' => substr((string) ($row['clock_out'] ?? ''), 0, 5),
            'shift' => (string) ($row['shift'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
        ];

        if ($status === 'late') {
            $entry['late_minutes'] = attendance_late_minutes($row['clock_in'] ?? '', $row['shift'] ?? '', $date);
            $late[] = $entry;
        } elseif ($status === 'absent') {
            if (empty($approvedLeave[(int) ($row['employee_id'] ?? 0)])) {
                $absent[] = $entry;
            }
        } elseif ($status === 'leave') {
            $onLeave[] = $entry;
        } elseif ($status === 'half-day') {
            $halfDay[] = $entry;
        }
    }

    return [
        'date' => $date,
        'active_count' => (int) ($missing['active_count'] ?? 0),
        'recorded_count' => (int) ($missing['recorded_count'] ?? 0),
        'missing_count' => (int) ($missing['missing_count'] ?? 0),
        'missing' => (array) ($missing['missing'] ?? []),
        'rows' => $rows,
        'counts' => $counts,
        'late' => $late,
        'absent' => $absent,
        'on_leave' => $onLeave,
        'half_day' => $halfDay,
    ];
}

/**
 * Period totals and per-employee day counts for the 16–15 period that contains
 * the report date. Reuses attendance_sheet_rows() so the Telegram numbers can
 * never disagree with the printable sheet.
 *
 * @return array{from:string,to:string,days_elapsed:int,employees:int,rows:array,totals:array}
 */
function telegram_period_summary($date) {
    [$from, $to] = telegram_payroll_period_for_date($date);

    $groups = attendance_sheet_rows($from, $to, '');
    $employees = [];
    $totals = [
        'present_days' => 0.0,
        'absent_days' => 0.0,
        'leave_days' => 0.0,
        'unpaid_leave_days' => 0.0,
        'late_minutes' => 0,
    ];

    foreach ($groups as $rows) {
        foreach ($rows as $row) {
            $employees[] = $row;
            $totals['present_days'] += (float) ($row['present_days'] ?? 0);
            $totals['absent_days'] += (float) ($row['absent_days'] ?? 0);
            $totals['leave_days'] += (float) ($row['leave_days'] ?? 0);
            $totals['unpaid_leave_days'] += (float) ($row['unpaid_leave_days'] ?? 0);
            $totals['late_minutes'] += (int) ($row['late_minutes'] ?? 0);
        }
    }

    usort($employees, static function ($a, $b) {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    // attendance_sheet_rows() counts up to today inside the current period, so
    // the elapsed-day figure must do the same.
    $today = date('Y-m-d');
    $effectiveTo = $to > $today ? $today : $to;
    if ($effectiveTo < $from) {
        $effectiveTo = $from;
    }

    // The printable sheet counts calendar days (maximum 30 per payroll month)
    // and skips Pagume. Mirror that here so the Telegram figure matches it.
    $daysElapsed = 0;
    for ($ts = strtotime($from); $ts !== false && $ts <= strtotime($effectiveTo); $ts = strtotime('+1 day', $ts)) {
        $day = date('Y-m-d', $ts);
        $parts = ethiopian_date_parts($day);
        if ($parts !== null && (int) $parts['month'] === 13) {
            continue;
        }
        $daysElapsed++;
    }
    $daysElapsed = min(30, max(0, $daysElapsed));

    return [
        'from' => $from,
        'to' => $to,
        'days_elapsed' => $daysElapsed,
        'employees' => count($employees),
        'rows' => $employees,
        'totals' => $totals,
    ];
}

/**
 * Business alerts: pending approvals, low stock, overdue balances and
 * upcoming maintenance.
 *
 * @return array<int,array{key:string,icon:string,title:string,count:int,lines:array<int,string>}>
 */
function telegram_business_alerts($date, array $settings) {
    global $pdo;
    $alerts = [];
    $symbol = (string) (company_settings()['currency_symbol'] ?? 'Br');

    // Pending approvals.
    if (!empty($settings['alert_pending_approvals'])) {
        $leave = 0;
        $overtime = 0;
        $loans = 0;
        $details = [];
        try {
            $leave = (int) $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
            $overtime = (int) $pdo->query("SELECT COUNT(*) FROM overtime_requests WHERE status = 'pending'")->fetchColumn();
            $loans = (int) $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE approval_status = 'pending'")->fetchColumn();

            if ($leave > 0) {
                $stmt = $pdo->query(
                    "SELECT e.name, lr.leave_type, lr.from_date, lr.to_date
                     FROM leave_requests lr
                     JOIN employees e ON e.id = lr.employee_id
                     WHERE lr.status = 'pending'
                     ORDER BY lr.from_date, lr.id LIMIT 5"
                );
                foreach ($stmt->fetchAll() as $row) {
                    $details[] = '- ' . $row['name'] . ' · ' . $row['leave_type'] . ' · '
                        . telegram_date($row['from_date']) . ' → ' . telegram_date($row['to_date']);
                }
            }
            if ($overtime > 0) {
                $stmt = $pdo->query(
                    "SELECT e.name, o.overtime_date, o.hours
                     FROM overtime_requests o
                     JOIN employees e ON e.id = o.employee_id
                     WHERE o.status = 'pending'
                     ORDER BY o.overtime_date, o.id LIMIT 5"
                );
                foreach ($stmt->fetchAll() as $row) {
                    $details[] = '- ' . $row['name'] . ' · overtime ' . $row['hours'] . 'h · ' . telegram_date($row['overtime_date']);
                }
            }
            if ($loans > 0) {
                $stmt = $pdo->query(
                    "SELECT e.name, l.loan_amount, l.request_date
                     FROM employee_loans l
                     JOIN employees e ON e.id = l.employee_id
                     WHERE l.approval_status = 'pending'
                     ORDER BY l.request_date, l.id LIMIT 5"
                );
                foreach ($stmt->fetchAll() as $row) {
                    $details[] = '- ' . $row['name'] . ' · loan ' . $symbol . number_format((float) $row['loan_amount'], 2)
                        . ' · ' . telegram_date($row['request_date']);
                }
            }
        } catch (Throwable $e) {
            // Tables unavailable.
        }

        $count = $leave + $overtime + $loans;
        if ($count > 0) {
            $summary = [];
            if ($leave > 0) {
                $summary[] = $leave . ' leave request' . ($leave === 1 ? '' : 's');
            }
            if ($overtime > 0) {
                $summary[] = $overtime . ' overtime request' . ($overtime === 1 ? '' : 's');
            }
            if ($loans > 0) {
                $summary[] = $loans . ' loan request' . ($loans === 1 ? '' : 's');
            }
            $alerts[] = [
                'key' => 'pending_approvals',
                'icon' => '🟠',
                'title' => 'Pending approvals',
                'count' => $count,
                'lines' => array_merge(['- ' . implode(', ', $summary) . ' waiting.'], $details),
            ];
        }
    }

    // Low stock.
    if (!empty($settings['alert_low_stock'])) {
        $items = [];
        try {
            $items = $pdo->query(
                "SELECT rm.name, rm.unit, rm.current_stock, rl.reorder_point
                 FROM raw_materials rm
                 JOIN reorder_levels rl ON rl.material_id = rm.id
                 WHERE rm.status = 1 AND rm.current_stock <= rl.reorder_point
                 ORDER BY (rm.current_stock / NULLIF(rl.reorder_point, 0)) ASC, rm.name
                 LIMIT 10"
            )->fetchAll();
        } catch (Throwable $e) {
            $items = [];
        }
        if ($items) {
            $lines = [];
            foreach ($items as $item) {
                $lines[] = '- ' . $item['name'] . ' — ' . telegram_format_days($item['current_stock']) . ' ' . $item['unit']
                    . ' left (reorder at ' . telegram_format_days($item['reorder_point']) . ' ' . $item['unit'] . ')';
            }
            $alerts[] = [
                'key' => 'low_stock',
                'icon' => '🔴',
                'title' => 'Low stock',
                'count' => count($items),
                'lines' => $lines,
            ];
        }
    }

    // Overdue customer balances (unpaid for more than 30 days).
    if (!empty($settings['alert_overdue_balances'])) {
        $count = 0;
        $total = 0.0;
        $lines = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS invoices, COALESCE(SUM(balance), 0) AS total
                 FROM sales
                 WHERE payment_status <> 'paid' AND balance > 0
                   AND sale_date < DATE_SUB(?, INTERVAL 30 DAY)"
            );
            $stmt->execute([$date]);
            $summary = $stmt->fetch() ?: ['invoices' => 0, 'total' => 0];
            $count = (int) ($summary['invoices'] ?? 0);
            $total = (float) ($summary['total'] ?? 0);

            if ($count > 0) {
                $stmt = $pdo->prepare(
                    "SELECT s.invoice_no, s.sale_date, s.balance, c.name AS customer_name
                     FROM sales s
                     LEFT JOIN customers c ON c.id = s.customer_id
                     WHERE s.payment_status <> 'paid' AND s.balance > 0
                       AND s.sale_date < DATE_SUB(?, INTERVAL 30 DAY)
                     ORDER BY s.sale_date ASC LIMIT 5"
                );
                $stmt->execute([$date]);
                foreach ($stmt->fetchAll() as $row) {
                    $lines[] = '- ' . ($row['customer_name'] ?: 'Walk-in') . ' · ' . $row['invoice_no']
                        . ' · ' . $symbol . number_format((float) $row['balance'], 2)
                        . ' · ' . telegram_date($row['sale_date']);
                }
                $lines[] = '- Total overdue: ' . $symbol . number_format($total, 2) . ' across ' . $count . ' invoice' . ($count === 1 ? '' : 's') . '.';
            }
        } catch (Throwable $e) {
            // Sales table unavailable.
        }
        if ($count > 0) {
            $alerts[] = [
                'key' => 'overdue_balances',
                'icon' => '💰',
                'title' => 'Overdue customer balances',
                'count' => $count,
                'lines' => $lines,
            ];
        }
    }

    // Upcoming asset maintenance (next seven days, plus anything already due).
    if (!empty($settings['alert_asset_maintenance'])) {
        $items = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT a.name, a.asset_code, am.next_maintenance_date AS due_date
                 FROM assets a
                 JOIN asset_maintenance am ON am.id = (
                     SELECT latest.id FROM asset_maintenance latest
                     WHERE latest.asset_id = a.id
                     ORDER BY latest.maintenance_date DESC, latest.id DESC LIMIT 1
                 )
                 WHERE a.status = 1 AND am.next_maintenance_date IS NOT NULL
                   AND am.next_maintenance_date <= DATE_ADD(?, INTERVAL 7 DAY)
                 ORDER BY am.next_maintenance_date"
            );
            $stmt->execute([$date]);
            $items = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT a.name, a.asset_code, am.due_date
                 FROM asset_maintenance am
                 JOIN assets a ON a.id = am.asset_id
                 WHERE a.status = 1 AND am.status <> 'completed' AND am.due_date IS NOT NULL
                   AND am.due_date <= DATE_ADD(?, INTERVAL 7 DAY)
                 ORDER BY am.due_date"
            );
            $stmt->execute([$date]);
            foreach ($stmt->fetchAll() as $row) {
                $items[] = [
                    'name' => $row['name'],
                    'asset_code' => $row['asset_code'],
                    'due_date' => $row['due_date'],
                ];
            }
        } catch (Throwable $e) {
            // Asset tables unavailable.
        }

        $unique = [];
        foreach ($items as $item) {
            $key = (string) ($item['asset_code'] ?? '') . '|' . (string) ($item['due_date'] ?? '');
            if (!isset($unique[$key])) {
                $unique[$key] = $item;
            }
        }
        if ($unique) {
            $lines = [];
            foreach (array_slice(array_values($unique), 0, 10) as $item) {
                $due = (string) ($item['due_date'] ?? '');
                $when = $due !== '' && $due < $date ? 'overdue since ' . telegram_date($due) : 'due ' . telegram_date($due);
                $lines[] = '- ' . $item['name'] . ' (' . $item['asset_code'] . ') — ' . $when;
            }
            $alerts[] = [
                'key' => 'asset_maintenance',
                'icon' => '🛠️',
                'title' => 'Upcoming asset maintenance',
                'count' => count($unique),
                'lines' => $lines,
            ];
        }
    }

    return $alerts;
}

/**
 * Everything the daily message and CSV need, built once.
 */
function telegram_daily_report_data($date) {
    $settings = telegram_settings();
    $day = telegram_attendance_day($date);
    $period = telegram_period_summary($date);
    $alerts = telegram_business_alerts($date, $settings);

    return [
        'date' => $date,
        'generated_at' => date('Y-m-d H:i:s'),
        'day' => $day,
        'period' => $period,
        'alerts' => $alerts,
        'settings' => $settings,
    ];
}

/**
 * Count every enabled item that needs attention, for the headline.
 */
function telegram_attention_total(array $data) {
    $settings = $data['settings'] ?? telegram_settings();
    $day = $data['day'] ?? [];
    $total = 0;

    if (!empty($settings['alert_late'])) {
        $total += count($day['late'] ?? []);
    }
    if (!empty($settings['alert_absences'])) {
        $total += count($day['absent'] ?? []);
    }
    if (!empty($settings['alert_missing_attendance'])) {
        $total += (int) ($day['missing_count'] ?? 0);
    }
    foreach (($data['alerts'] ?? []) as $alert) {
        $total += (int) ($alert['count'] ?? 0);
    }

    return $total;
}

/**
 * Render the daily HTML message for Telegram.
 */
function telegram_build_daily_message($date, $data = null) {
    $data = $data ?: telegram_daily_report_data($date);
    $settings = $data['settings'] ?? telegram_settings();
    $day = $data['day'];
    $period = $data['period'];
    $counts = $day['counts'];

    $weekday = date('l', strtotime($date));
    $ethiopian = format_ethiopian_date($date);
    $lines = [];

    $lines[] = '🏭 <b>' . telegram_escape((string) (company_settings()['company_name'] ?? 'Block Plant ERP')) . ' — Daily Attendance</b>';
    $lines[] = '📅 <b>' . telegram_escape(telegram_date($date)) . '</b> (' . telegram_escape($weekday) . ')'
        . ($ethiopian !== '' ? ' · ' . telegram_escape($ethiopian) : '');

    $attention = telegram_attention_total($data);
    if ($attention > 0) {
        $lines[] = '';
        $lines[] = '⚠️ <b>' . $attention . ' item' . ($attention === 1 ? '' : 's') . ' need your attention</b> — see the sections below.';
    } else {
        $lines[] = '';
        $lines[] = '✅ <b>Nothing needs your attention today.</b>';
    }

    $lines[] = '';
    $lines[] = '<b>Today at a glance</b>';
    $lines[] = '👥 Active employees: ' . $day['active_count'];
    $lines[] = '✅ Present: ' . $counts['present'];
    $lines[] = '⏰ Late: ' . $counts['late'];
    $lines[] = '❌ Absent: ' . $counts['absent'];
    $lines[] = '🏖 On leave: ' . $counts['leave'];
    $lines[] = '🕐 Half-day: ' . $counts['half-day'];
    $lines[] = '❓ Not recorded: ' . $day['missing_count'];

    if (!empty($settings['alert_late']) && $day['late']) {
        $lines[] = '';
        $lines[] = '<b>⏰ Late arrivals (' . count($day['late']) . ')</b>';
        foreach ($day['late'] as $row) {
            $lateMinutes = (int) ($row['late_minutes'] ?? 0);
            $lines[] = '• ' . telegram_escape($row['name'])
                . ($row['clock_in'] !== '' ? ' — clocked in ' . $row['clock_in'] : '')
                . ($lateMinutes > 0 ? ', late ' . telegram_format_minutes($lateMinutes) : '');
        }
    }

    if (!empty($settings['alert_absences']) && $day['absent']) {
        $lines[] = '';
        $lines[] = '<b>❌ Absent without approved leave (' . count($day['absent']) . ')</b>';
        foreach ($day['absent'] as $row) {
            $lines[] = '• ' . telegram_escape($row['name'])
                . ($row['code'] !== '' ? ' (' . telegram_escape($row['code']) . ')' : '')
                . ($row['department'] !== '' ? ' — ' . telegram_escape($row['department']) : '');
        }
    }

    if ($day['on_leave']) {
        $lines[] = '';
        $lines[] = '<b>🏖 On approved leave (' . count($day['on_leave']) . ')</b>';
        foreach ($day['on_leave'] as $row) {
            $lines[] = '• ' . telegram_escape($row['name'])
                . ($row['department'] !== '' ? ' — ' . telegram_escape($row['department']) : '');
        }
    }

    if ($day['half_day']) {
        $lines[] = '';
        $lines[] = '<b>🕐 Half-day (' . count($day['half_day']) . ')</b>';
        foreach ($day['half_day'] as $row) {
            $lines[] = '• ' . telegram_escape($row['name']);
        }
    }

    if (!empty($settings['alert_missing_attendance']) && $day['missing']) {
        $lines[] = '';
        $lines[] = '<b>❓ No attendance recorded (' . $day['missing_count'] . ')</b>';
        foreach (array_slice($day['missing'], 0, 25) as $row) {
            $lines[] = '• ' . telegram_escape((string) ($row['name'] ?? ''))
                . (!empty($row['employee_code']) ? ' (' . telegram_escape((string) $row['employee_code']) . ')' : '')
                . (!empty($row['department']) ? ' — ' . telegram_escape((string) $row['department']) : '');
        }
        if ($day['missing_count'] > 25) {
            $lines[] = '• … and ' . ($day['missing_count'] - 25) . ' more';
        }
    }

    // Payroll period.
    $totals = $period['totals'];
    $lines[] = '';
    $lines[] = '<b>📊 Payroll period 16–15</b>';
    $lines[] = telegram_escape(telegram_date($period['from'])) . ' → ' . telegram_escape(telegram_date($period['to']))
        . ' · ' . $period['days_elapsed'] . ' of 30 days counted';
    $lines[] = 'Present days: <b>' . telegram_format_days($totals['present_days']) . '</b>'
        . ' · Absent: ' . telegram_format_days($totals['absent_days'])
        . ' · Leave: ' . telegram_format_days($totals['leave_days']);
    $lines[] = 'Late time: ' . (telegram_format_minutes($totals['late_minutes'], '0:00'))
        . ' (≈ ' . telegram_format_days($totals['late_minutes'] / 480) . ' day deducted)';

    if ($period['rows']) {
        $lines[] = '';
        $lines[] = '<b>👤 Days present by employee</b>';
        foreach ($period['rows'] as $row) {
            $late = (int) ($row['late_minutes'] ?? 0);
            $lines[] = '• ' . telegram_escape((string) $row['name']) . ' — '
                . telegram_format_days($row['present_days']) . ' present'
                . ($late > 0 ? ' · late ' . telegram_format_minutes($late) : '')
                . ((float) ($row['absent_days'] ?? 0) > 0 ? ' · absent ' . telegram_format_days($row['absent_days']) : '');
        }
    }

    // Business alerts.
    if ($data['alerts']) {
        $lines[] = '';
        $lines[] = '<b>⚠️ Needs your attention</b>';
        foreach ($data['alerts'] as $alert) {
            $lines[] = '';
            $lines[] = $alert['icon'] . ' <b>' . telegram_escape($alert['title']) . ' (' . $alert['count'] . ')</b>';
            foreach ($alert['lines'] as $line) {
                $lines[] = telegram_escape($line);
            }
        }
    } elseif ($attention === 0) {
        $lines[] = '';
        $lines[] = '✅ No pending approvals, low stock, overdue balances or maintenance due.';
    }

    $lines[] = '';
    $lines[] = '<i>Generated ' . telegram_escape(date('d M Y H:i', strtotime($data['generated_at']))) . ' · Block Plant ERP</i>';

    return implode("\n", $lines);
}

/**
 * One CSV cell, always quoted so Excel keeps codes and leading zeros.
 */
function telegram_csv_cell($value) {
    return '"' . str_replace('"', '""', (string) $value) . '"';
}

/**
 * Today's attendance as a CSV file body (UTF-8 with BOM for Excel).
 */
function telegram_build_daily_csv($date, $data = null) {
    $data = $data ?: telegram_daily_report_data($date);
    $day = $data['day'];

    $out = "\xEF\xBB\xBF";
    $out .= "Employee Code,Name,Department,Shift,Clock In,Clock Out,Status,Late (minutes),Notes\n";

    foreach ($day['rows'] as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $late = $status === 'late'
            ? attendance_late_minutes($row['clock_in'] ?? '', $row['shift'] ?? '', $date)
            : 0;
        $cells = [
            (string) ($row['employee_code'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['department'] ?? ''),
            (string) ($row['shift'] ?? ''),
            substr((string) ($row['clock_in'] ?? ''), 0, 5),
            substr((string) ($row['clock_out'] ?? ''), 0, 5),
            $status,
            $late > 0 ? (string) $late : '',
            (string) ($row['notes'] ?? ''),
        ];
        $out .= implode(',', array_map('telegram_csv_cell', $cells)) . "\n";
    }

    foreach ($day['missing'] as $row) {
        $cells = [
            (string) ($row['employee_code'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['department'] ?? ''),
            '',
            '',
            '',
            'not recorded',
            '',
            'No attendance row for this date',
        ];
        $out .= implode(',', array_map('telegram_csv_cell', $cells)) . "\n";
    }

    return $out;
}

/**
 * Build, send and log the daily report.
 *
 * @param array{attach?:bool,dry_run?:bool,chat_id?:string} $options
 * @return array{ok:bool,sent:int,errors:array<int,string>,message:string,data:array,attachment:bool}
 */
function telegram_send_daily_report($date, array $options = []) {
    global $pdo;
    $settings = telegram_settings();
    $data = telegram_daily_report_data($date);
    $message = telegram_build_daily_message($date, $data);

    if (!empty($options['dry_run'])) {
        return [
            'ok' => true,
            'sent' => 0,
            'errors' => [],
            'message' => $message,
            'data' => $data,
            'attachment' => false,
        ];
    }

    $result = telegram_send_message($message, array_filter([
        'chat_id' => $options['chat_id'] ?? null,
    ], static function ($value) {
        return $value !== null && $value !== '';
    }));

    $errors = $result['errors'];
    $attachmentSent = false;

    $attach = array_key_exists('attach', $options)
        ? (bool) $options['attach']
        : !empty($settings['attach_csv']);
    if ($attach && $result['ok']) {
        $filename = 'attendance_' . str_replace('-', '', $date) . '.csv';
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'block_plant_' . $filename;
        if (@file_put_contents($path, telegram_build_daily_csv($date, $data)) !== false) {
            $upload = telegram_send_document($path, $filename, 'Attendance for ' . telegram_date($date));
            @unlink($path);
            if ($upload['ok']) {
                $attachmentSent = true;
            } else {
                $errors[] = 'Attachment: ' . $upload['error'];
            }
        } else {
            $errors[] = 'Attachment: could not write the temporary CSV file.';
        }
    }

    $ok = $errors === [];
    $status = $ok ? 'sent' : 'failed';
    telegram_log('daily', $status, $message, $result['error'] !== '' ? $result['error'] : 'sent ' . $result['sent'] . ' message(s)' . ($attachmentSent ? ' + CSV' : ''), $date);

    if ($ok) {
        try {
            $stmt = $pdo->prepare("UPDATE telegram_settings SET last_sent_date = ?, last_sent_at = ?, last_error = NULL WHERE id = 1");
            $stmt->execute([$date, date('Y-m-d H:i:s')]);
            telegram_settings(true);
        } catch (Throwable $e) {
            // Ignore.
        }
    } else {
        telegram_set_last_error(implode(' ', $errors));
    }

    return [
        'ok' => $ok,
        'sent' => $result['sent'],
        'errors' => $errors,
        'message' => $message,
        'data' => $data,
        'attachment' => $attachmentSent,
    ];
}

/**
 * True when the clock has reached the saved send time.
 *
 * A 15-minute grace is allowed so a Task Scheduler run that starts a little
 * early does not push the report to the next day.
 *
 * @param string      $sendTime Saved time, "HH:MM" or "HH:MM:SS".
 * @param string|null $now      Clock to test, defaults to the current time.
 */
function telegram_send_time_reached($sendTime, $now = null) {
    $toMinutes = static function ($time) {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim((string) $time), $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + ((int) $m[2]);
    };

    $target = $toMinutes($sendTime);
    if ($target === null) {
        return true; // Nothing valid saved: never block the report.
    }

    $current = $toMinutes($now !== null ? $now : date('H:i'));
    if ($current === null) {
        return true;
    }

    return $current >= $target - 15;
}

/**
 * True when the daily report was already sent for a date.
 */
function telegram_already_sent($date) {
    global $pdo;
    try {
        ensure_telegram_schema();
        $stmt = $pdo->prepare(
            "SELECT id FROM telegram_message_log
             WHERE message_type = 'daily' AND report_date = ? AND status = 'sent'
             LIMIT 1"
        );
        $stmt->execute([$date]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
