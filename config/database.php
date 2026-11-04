<?php
function env_or($k, $d = '') {
    $v = getenv($k);
    if ($v === false || $v === '') $v = $_ENV[$k] ?? $_SERVER[$k] ?? '';
    return ($v === '' || $v === false) ? $d : $v;
}

$host    = env_or('DB_HOST', 'localhost');
$port    = env_or('DB_PORT', '3306');
$db      = env_or('DB_DATABASE', 'block_plant');
$user    = env_or('DB_USERNAME', 'root');
$pass    = env_or('DB_PASSWORD', '');
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=$charset",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// Used by import.php
if (!class_exists('Database')) {
    class Database {
        public function getConnection() { global $pdo; return $pdo; }
    }
}
