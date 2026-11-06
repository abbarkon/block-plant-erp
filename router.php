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

    // Add the "install as app" tags and the animated name splash to every HTML page.
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
              . '<meta name="apple-mobile-web-app-title" content="Mariamawit">'
              . '<link rel="apple-touch-icon" href="' . $prefix . '/assets/pwa/apple-touch-icon.png">'
              . '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("' . $prefix . '/sw.js",{scope:"' . $prefix . '/"});});}</script>'
              . '<style>'
              . '#mbSplash{position:fixed;inset:0;z-index:99999;background:#0f4c5c;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;transition:opacity .6s ease}'
              . '#mbSplash.mb-out{opacity:0;pointer-events:none}'
              . '#mbSplash .mb-row{display:flex;gap:6px;flex-wrap:nowrap}'
              . '#mbSplash .mb-b{width:clamp(26px,8.5vw,44px);height:clamp(30px,9.5vw,50px);display:flex;align-items:center;justify-content:center;'
              . 'background:#f2c14e;color:#0f4c5c;font:800 clamp(16px,5vw,26px)/1 system-ui,sans-serif;border-radius:6px;box-shadow:0 4px 0 #c99a2e;'
              . 'transform:translateY(-120vh) rotate(-8deg);opacity:0;animation:mbDrop .7s cubic-bezier(.3,1.4,.5,1) forwards;animation-delay:calc(var(--i)*90ms)}'
              . '#mbSplash .mb-row.mb-2 .mb-b{background:#fff;box-shadow:0 4px 0 #c9d6d9;animation-delay:calc(var(--i)*90ms + 900ms)}'
              . '@keyframes mbDrop{0%{transform:translateY(-120vh) rotate(-8deg);opacity:0}60%{opacity:1}100%{transform:translateY(0) rotate(0);opacity:1}}'
              . '@media (prefers-reduced-motion:reduce){#mbSplash{display:none}}'
              . '</style>';
        $html = preg_replace('#</head>#i', $tags . '</head>', $html, 1);

        $row = function ($text, $cls) {
            $out = '<div class="mb-row ' . $cls . '">';
            foreach (str_split(strtoupper($text)) as $i => $ch) {
                $out .= '<span class="mb-b" style="--i:' . $i . '">' . htmlspecialchars($ch) . '</span>';
            }
            return $out . '</div>';
        };
        $splash = '<div id="mbSplash" aria-hidden="true">' . $row('Mariamawit', 'mb-1') . $row('Blocks', 'mb-2') . '</div>'
                . '<script>(function(){var s=document.getElementById("mbSplash");if(!s)return;'
                . 'try{if(sessionStorage.getItem("mbSplash")){s.remove();return;}sessionStorage.setItem("mbSplash","1");}catch(e){}'
                . 'setTimeout(function(){s.classList.add("mb-out");},2600);setTimeout(function(){s.remove();},3300);})();</script>';
        if (stripos($html, '<body') !== false) {
            $html = preg_replace('#(<body[^>]*>)#i', '$1' . $splash, $html, 1);
        }
        return $html;
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
