<?php
header('Content-Type: text/plain; charset=utf-8');
$e = fn($k) => getenv($k) ?: ($_SERVER[$k] ?? '');
$pdo = new PDO("mysql:host={$e('DB_HOST')};port={$e('DB_PORT')};dbname={$e('DB_DATABASE')};charset=utf8mb4",
    $e('DB_USERNAME'), $e('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$sql = file_get_contents(__DIR__ . '/database.sql');
$sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/mi', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$n = 0;
foreach (preg_split('/;\s*[\r\n]+/', $sql) as $s) {
    $s = trim($s);
    if (!preg_match('/^(?:\\\\n)+/', $s)) continue;
    $s = trim(preg_replace('/^(?:\\\\n)+\s*/', '', $s));
    $s = preg_replace('/^CREATE TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $s);
    try { $pdo->exec($s); $n++; echo "OK: " . substr($s, 0, 80) . "\n"; }
    catch (Throwable $x) { echo "ERROR: " . $x->getMessage() . "\n  in: " . substr($s, 0, 120) . "\n"; }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "Repaired statements: $n\n";
echo "Tables now: " . count($pdo->query('SHOW TABLES')->fetchAll()) . "\n";
