<?php
// Multiple departments per user (safe to load more than once).
// ---- Multiple departments per user ----

if (!function_exists('ensure_extra_departments_schema')) {
function ensure_extra_departments_schema() {
    static $done = false;
    if ($done) {
        return;
    }
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_extra_departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        department VARCHAR(40) NOT NULL,
        department_role VARCHAR(20) NOT NULL DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_dept (user_id, department)
    )");
    $done = true;
}
}

/** All department assignments for a user: the primary one plus any extra ones. */

if (!function_exists('user_department_assignments')) {
function user_department_assignments($userId, $userRow = null) {
    global $pdo;
    ensure_extra_departments_schema();
    $out = [];
    if ($userRow === null) {
        $st = $pdo->prepare("SELECT role, department, department_role FROM users WHERE id = ?");
        $st->execute([(int) $userId]);
        $userRow = $st->fetch() ?: [];
    }
    $dept = strtolower(trim((string) ($userRow['department'] ?? '')));
    $role = strtolower(trim((string) ($userRow['department_role'] ?? '')));
    if ($dept !== '' && in_array($role, ['manager', 'user'], true)) {
        $out[$dept] = ['department' => $dept, 'role' => $role];
    }
    $st = $pdo->prepare("SELECT department, department_role FROM user_extra_departments WHERE user_id = ?");
    $st->execute([(int) $userId]);
    foreach ($st->fetchAll() as $r) {
        $d = strtolower(trim((string) $r['department']));
        $rl = strtolower(trim((string) $r['department_role']));
        if ($d === '' || !in_array($rl, ['manager', 'user'], true)) {
            continue;
        }
        // If the same department appears twice, the stronger role wins.
        if (!isset($out[$d]) || ($rl === 'manager' && $out[$d]['role'] !== 'manager')) {
            $out[$d] = ['department' => $d, 'role' => $rl];
        }
    }
    return array_values($out);
}
}

if (!function_exists('user_extra_department_map')) {
function user_extra_department_map() {
    global $pdo;
    ensure_extra_departments_schema();
    $map = [];
    foreach ($pdo->query("SELECT id, user_id, department, department_role FROM user_extra_departments ORDER BY department")->fetchAll() as $r) {
        $map[(int) $r['user_id']][] = $r;
    }
    return $map;
}
}
