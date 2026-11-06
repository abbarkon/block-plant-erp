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
              . '#mbSplash{position:fixed;inset:0;z-index:99999;background:#0f4c5c;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;transition:opacity .6s ease}'
              . '#mbSplash.mb-out{opacity:0;pointer-events:none}'
              . '#mbSplash .mb-wall{display:flex;flex-direction:column;gap:5px}'
              . '#mbSplash .mb-course{display:flex;gap:5px}'
              . '#mbSplash .mb-course.mb-off{margin-left:calc(clamp(34px,11vw,56px)/2 + 2.5px)}'
              . '#mbSplash .mb-brick{width:clamp(34px,11vw,56px);height:clamp(20px,6vw,30px);border-radius:4px;background:#d9a441;box-shadow:inset 0 -4px 0 rgba(0,0,0,.18);'
              . 'opacity:0;transform:translateY(-110vh);animation:mbLay .55s cubic-bezier(.3,1.35,.5,1) forwards;animation-delay:calc(var(--i)*70ms)}'
              . '#mbSplash .mb-brick:nth-child(3n){background:#c98f2c}#mbSplash .mb-brick:nth-child(3n+1){background:#e6b85a}'
              . '#mbSplash .mb-name{display:flex;flex-direction:column;align-items:center;gap:6px;opacity:0;animation:mbFade .6s ease forwards;animation-delay:2.1s}'
              . '#mbSplash .mb-l1{font:800 clamp(22px,7vw,36px)/1 system-ui,sans-serif;letter-spacing:.14em;color:#f2c14e}'
              . '#mbSplash .mb-l2{font:700 clamp(13px,4vw,18px)/1 system-ui,sans-serif;letter-spacing:.5em;color:#fff}'
              . '@keyframes mbLay{0%{opacity:0;transform:translateY(-110vh)}50%{opacity:1}100%{opacity:1;transform:translateY(0)}}'
              . '@keyframes mbFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}'
              . '@media (prefers-reduced-motion:reduce){#mbSplash{display:none}}'
              . '</style>';
        $html = preg_replace('#</head>#i', $tags . '</head>', $html, 1);

        $wall = '';
        $n = 0;
        // 4 courses of bricks, laid bottom-to-top like a real block wall.
        for ($course = 3; $course >= 0; $course--) {
            $count = ($course % 2) ? 4 : 5;
            $wall .= '<div class="mb-course' . ($course % 2 ? ' mb-off' : '') . '">';
            for ($k = 0; $k < $count; $k++) {
                $wall .= '<span class="mb-brick" style="--i:' . $n++ . '"></span>';
            }
            $wall .= '</div>';
        }
        $splash = '<div id="mbSplash" aria-hidden="true"><div class="mb-wall">' . $wall . '</div>'
                . '<div class="mb-name"><div class="mb-l1">MARIAMAWIT</div><div class="mb-l2">BLOCKS</div></div></div>'
                . '<script>(function(){var s=document.getElementById("mbSplash");if(!s)return;'
                . 'try{if(sessionStorage.getItem("mbSplash")){s.remove();return;}sessionStorage.setItem("mbSplash","1");}catch(e){}'
                . 'setTimeout(function(){s.classList.add("mb-out");},3400);setTimeout(function(){s.remove();},4100);})();</script>';
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
