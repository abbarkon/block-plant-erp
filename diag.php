<?php
header('Content-Type: text/plain');
echo "=== Dockerfile ===\n" . @file_get_contents('/app/Dockerfile');
echo "\n=== laravel-entrypoint.sh ===\n" . @file_get_contents('/app/laravel-entrypoint.sh');
echo "\n=== php sapi ===\n" . php_sapi_name() . "\n";
echo "=== SERVER_SOFTWARE ===\n" . ($_SERVER['SERVER_SOFTWARE'] ?? '') . "\n";
