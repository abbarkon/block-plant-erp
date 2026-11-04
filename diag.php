<?php
header('Content-Type: text/plain');
echo shell_exec("cd /app && grep -rn --include=*.php -E \"block_plant|BASE_URL|base_url|APP_URL\" . 2>&1 | grep -v '^./diag.php' | head -60");
