<?php
header('Content-Type: text/plain; charset=utf-8');
$e = fn($k) => getenv($k) ?: ($_SERVER[$k] ?? '');
$pdo = new PDO("mysql:host={$e('DB_HOST')};port={$e('DB_PORT')};dbname={$e('DB_DATABASE')};charset=utf8mb4",
    $e('DB_USERNAME'), $e('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "Files in /app: " . implode(', ', array_diff(scandir(__DIR__), ['.', '..'])) . "\n";
echo "Files in /app/config: " . (is_dir(__DIR__ . '/config') ? implode(', ', array_diff(scandir(__DIR__ . '/config'), ['.', '..'])) : '(folder missing)') . "\n";

if (count($pdo->query('SHOW TABLES')->fetchAll()) > 0) { echo "Database already has tables. Import refused.\n"; exit; }

$found = [];
foreach (['', 'install/', 'database/', 'sql/', 'db/'] as $d)
    foreach (glob(__DIR__ . '/' . $d . '*.sql') ?: [] as $f) $found[] = ltrim(str_replace(__DIR__, '', $f), '/');

if (!isset($_GET['run'])) {
    echo "SQL files found: " . ($found ? implode(', ', $found) : '(none)') . "\n";
    if ($found) echo "To import: /import.php?run=1&file={$found[0]}\n";
    exit;
}
$file = $_GET['file'] ?? '';
if (!in_array($file, $found, true)) { echo "Unknown file.\n"; exit; }

$sql = file_get_contents(__DIR__ . '/' . $file);
$sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/mi', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$ok = $fail = 0;
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (preg_split('/;\s*[\r\n]+/', $sql) as $s) {
    $s = trim($s);
    if ($s === '') continue;
    try { $pdo->exec($s); $ok++; } catch (Throwable $x) { $fail++; echo "ERROR: " . $x->getMessage() . "\n"; }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "Done. OK: $ok, failed: $fail\n";
