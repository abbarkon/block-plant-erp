<?php
header('Content-Type: text/plain');
foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DATABASE_URL'] as $k) {
    $v = getenv($k) ?: ($_ENV[$k] ?? $_SERVER[$k] ?? '');
    echo $k . ': ' . ($v === '' ? 'NOT SET' : 'set') . "\n";
}
echo 'DB_HOST value: ' . (getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? '')) . "\n";
