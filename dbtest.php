<?php
header('Content-Type: text/plain');
$host = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? 'MISSING');
$port = getenv('DB_PORT') ?: ($_SERVER['DB_PORT'] ?? '3306');
$db   = getenv('DB_DATABASE') ?: ($_SERVER['DB_DATABASE'] ?? 'MISSING');
$user = getenv('DB_USERNAME') ?: ($_SERVER['DB_USERNAME'] ?? 'MISSING');
$pass = getenv('DB_PASSWORD') ?: ($_SERVER['DB_PASSWORD'] ?? '');
echo "host=$host port=$port db=$db user=$user\n";
echo "config file: " . realpath(__DIR__ . '/config/database.php') . "\n";
echo "first line of config: " . trim(explode("\n", file_get_contents(__DIR__ . '/config/database.php'))[1] ?? '') . "\n";
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "CONNECTED. Tables: " . count($pdo->query('SHOW TABLES')->fetchAll()) . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
