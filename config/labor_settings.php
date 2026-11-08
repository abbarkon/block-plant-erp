<?php
// Weekly direct-labor payment by cement used (piece rate). Helpers shared by the weekly labor page,
// the production form and the dashboard notice.

function labor_ensure_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS labor_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(100) NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_labor_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        cement_bags DECIMAL(12,2) NOT NULL DEFAULT 0,
        rate_per_bag DECIMAL(12,4) NOT NULL DEFAULT 0,
        blocks_produced INT NOT NULL DEFAULT 0,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        paid_at DATETIME NOT NULL,
        note VARCHAR(255) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_week_end (week_end)
    )");
    $done = true;
}

function labor_setting($key, $default = '') {
    global $pdo;
    labor_ensure_schema();
    $st = $pdo->prepare("SELECT setting_value FROM labor_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function labor_save_setting($key, $value) {
    global $pdo;
    labor_ensure_schema();
    $pdo->prepare("INSERT INTO labor_settings(setting_key, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, (string) $value]);
}

function labor_rate_per_bag() { return max(0, (float) labor_setting('rate_per_bag', '0')); }
function labor_kg_per_bag() { $v = (float) labor_setting('kg_per_bag', '50'); return $v > 0 ? $v : 50; }

/** The raw material counted as cement (first active one with "cement" in its name). */
function labor_cement_material() {
    global $pdo;
    try {
        $r = $pdo->query("SELECT id, name, unit FROM raw_materials WHERE status=1 AND name LIKE '%cement%' ORDER BY id LIMIT 1")->fetch();
        return $r ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Convert a cement quantity (in the material's own unit) to bags. */
function labor_to_bags($qty, $unit) {
    return preg_match('/\bkg\b|kilo/i', (string) $unit) ? $qty / labor_kg_per_bag() : (float) $qty;
}

/** Sunday..Saturday week that contains the given date (pay day is Saturday). */
function labor_week_bounds($date) {
    $ts = strtotime($date ?: date('Y-m-d'));
    $w = (int) date('w', $ts);                 // 0 = Sunday .. 6 = Saturday
    $end = strtotime('+' . ((6 - $w + 7) % 7) . ' days', $ts);
    return [date('Y-m-d', strtotime('-6 days', $end)), date('Y-m-d', $end)];
}

/** Runs in the week with the cement they used, plus totals. */
function labor_week_summary($start, $end) {
    global $pdo;
    $cement = labor_cement_material();
    $rate = labor_rate_per_bag();
    $runs = [];
    $days = [];
    $totBags = 0.0; $totBlocks = 0; $totRecorded = 0.0;
    if ($cement) {
        $st = $pdo->prepare("SELECT p.id, p.production_no, p.production_date, p.good_quantity, p.labor_cost, bt.name, bt.size,
                (SELECT COALESCE(SUM(pm.quantity),0) FROM production_materials pm WHERE pm.production_id = p.id AND pm.material_id = ?) AS cement_qty
            FROM production p JOIN block_types bt ON bt.id = p.block_type_id
            WHERE p.production_date BETWEEN ? AND ? ORDER BY p.production_date, p.id");
        $st->execute([(int) $cement['id'], $start, $end]);
        foreach ($st->fetchAll() as $r) {
            $bags = labor_to_bags((float) $r['cement_qty'], $cement['unit']);
            $r['bags'] = $bags;
            $r['due'] = $bags * $rate;
            $runs[] = $r;
            $d = $r['production_date'];
            $days[$d] = $days[$d] ?? ['runs' => 0, 'blocks' => 0, 'bags' => 0.0, 'due' => 0.0];
            $days[$d]['runs']++;
            $days[$d]['blocks'] += (int) $r['good_quantity'];
            $days[$d]['bags'] += $bags;
            $days[$d]['due'] += $bags * $rate;
            $totBags += $bags; $totBlocks += (int) $r['good_quantity']; $totRecorded += (float) $r['labor_cost'];
        }
    }
    return [
        'cement' => $cement, 'rate' => $rate, 'runs' => $runs, 'days' => $days,
        'bags' => $totBags, 'blocks' => $totBlocks, 'recorded' => $totRecorded, 'due' => $totBags * $rate,
    ];
}

function labor_payment_for_week($end) {
    global $pdo;
    labor_ensure_schema();
    $st = $pdo->prepare("SELECT * FROM weekly_labor_payments WHERE week_end = ?");
    $st->execute([$end]);
    return $st->fetch() ?: null;
}
