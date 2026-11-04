<?php
header('Content-Type: text/plain; charset=utf-8');
$e = fn($k) => getenv($k) ?: ($_SERVER[$k] ?? '');
$pdo = new PDO("mysql:host={$e('DB_HOST')};port={$e('DB_PORT')};dbname={$e('DB_DATABASE')};charset=utf8mb4",
    $e('DB_USERNAME'), $e('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$sql = file_get_contents(__DIR__ . '/database.sql');
$sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/mi', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$made = 0;
foreach (preg_split('/;\s*[\r\n]+/', $sql) as $chunk) {
    if (!preg_match('/;\s*(?:\\\\n)+\s*\S/', $chunk)) continue;
    $parts = preg_split('/;\s*(?:\\\\n)+\s*/', $chunk);
    array_shift($parts); // first piece already ran during the first import
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^CREATE TABLE/i', $p)) {
            $p = preg_replace('/^CREATE TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $p);
            try { $pdo->exec($p); $made++; echo "OK: " . substr($p, 0, 90) . "\n"; }
            catch (Throwable $x) { echo "ERROR: " . $x->getMessage() . "\n  in: " . substr($p, 0, 120) . "\n"; }
        } else {
            echo "SKIPPED (check manually): " . substr($p, 0, 120) . "\n";
        }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "Tables created: $made\n";
echo "Tables now: " . count($pdo->query('SHOW TABLES')->fetchAll()) . "\n";
echo "attendance_warning_revocation_files exists: " .
    (count($pdo->query("SHOW TABLES LIKE 'attendance_warning_revocation_files'")->fetchAll()) ? 'yes' : 'no') . "\n";
