<?php
$root   = __DIR__;
$prefix = '/block_plant';
$path   = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

$stripped = false;
if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
    $path = substr($path, strlen($prefix)) ?: '/';
    $stripped = true;
}

// Never serve sensitive files
if (preg_match('#(^|/)\.#', $path)
    || preg_match('#(^|/)(Dockerfile|laravel-entrypoint\.sh|composer\.(json|lock))$#i', $path)
    || preg_match('#\.(sql|sh|log|bak)$#i', $path)) {
    http_response_code(404); echo 'Not found'; return true;
}

if (!$stripped) return false; // normal behaviour

$file = realpath($root . $path);
if ($file !== false && is_dir($file)) $file .= '/index.php';
if ($file === false || strpos($file, $root) !== 0 || !is_file($file)) {
    http_response_code(404); echo 'Not found'; return true;
}

if (substr($file, -4) === '.php') {
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $prefix . substr($file, strlen($root));
    chdir(dirname($file));
    require $file;
    return true;
}

$types = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json','svg'=>'image/svg+xml',
 'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
 'ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','pdf'=>'application/pdf',
 'csv'=>'text/csv','txt'=>'text/plain','html'=>'text/html'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
readfile($file);
return true;
