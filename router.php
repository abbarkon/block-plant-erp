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

    // Add the "install as app" tags to every HTML page.
    ob_start(function ($html) use ($prefix) {
        $isHtml = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') !== false) $isHtml = true;
        }
        if (!$isHtml && stripos(ltrim($html), '<!doctype html') !== 0 && stripos(ltrim($html), '<html') !== 0) return $html;
        if (stripos($html, 'rel="manifest"') !== false || stripos($html, '</head>') === false) return $html;
        $tags = '<link rel="manifest" href="' . $prefix . '/manifest.webmanifest">'
              . '<meta name="theme-color" content="#0f4c5c">'
              . '<meta name="mobile-web-app-capable" content="yes">'
              . '<meta name="apple-mobile-web-app-capable" content="yes">'
              . '<meta name="apple-mobile-web-app-title" content="Block Plant">'
              . '<link rel="apple-touch-icon" href="' . $prefix . '/assets/pwa/apple-touch-icon.png">'
              . '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("' . $prefix . '/sw.js",{scope:"' . $prefix . '/"});});}</script>';
        return preg_replace('#</head>#i', $tags . '</head>', $html, 1);
    });
    require $file;
    return true;
}

$types = ['css'=>'text/css','js'=>'application/javascript','json'=>'application/json','svg'=>'image/svg+xml',
 'webmanifest'=>'application/manifest+json',
 'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp',
 'ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','pdf'=>'application/pdf',
 'csv'=>'text/csv','txt'=>'text/plain','html'=>'text/html'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
if (basename($file) === 'sw.js') header('Cache-Control: no-cache');
header('Content-Length: ' . filesize($file));
readfile($file);
return true;
