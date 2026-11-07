<?php
// Translates English text still hard-coded on pages into Amharic (runs only when the language is Amharic).
// Sources: (1) every English -> Amharic pair already in config/lang.php, (2) config/am_extra.php.

function am_dictionary(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $lang = require __DIR__ . '/lang.php';
    foreach (($lang['en'] ?? []) as $key => $text) {
        if (isset($lang['am'][$key]) && trim($text) !== '' && trim($lang['am'][$key]) !== '') {
            $map[mb_strtolower(trim($text))] = $lang['am'][$key];
        }
    }
    $extra = @include __DIR__ . '/am_extra.php';
    if (is_array($extra)) {
        foreach ($extra as $en => $am) {
            $map[mb_strtolower(trim($en))] = $am;
        }
    }
    return $map;
}

function am_lookup(string $text, array $map): ?string {
    $trim = trim($text);
    if ($trim === '' || !preg_match('/[A-Za-z]/', $trim)) return null;
    $key = mb_strtolower($trim);
    if (isset($map[$key])) return $map[$key];
    if (substr($key, -1) === ':' && isset($map[rtrim($key, ': ')])) return $map[rtrim($key, ': ')] . ':';
    return null;
}

function am_translate_html(string $html, array &$missing = []): string {
    $map = am_dictionary();
    $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) continue; // script / style / textarea untouched
        // visible text between tags
        $part = preg_replace_callback('/>([^<>]+)</u', function ($m) use ($map, &$missing) {
            $text = $m[1];
            if (strpos($text, '<?') !== false) return $m[0];
            $am = am_lookup(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), $map);
            if ($am === null) {
                $t = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                if (preg_match('/^[A-Z][A-Za-z][A-Za-z ,\'&\/\-\.\(\):%]{2,80}$/', $t)) $missing[$t] = true;
                return $m[0];
            }
            preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $w);
            return '>' . $w[1] . htmlspecialchars($am, ENT_NOQUOTES, 'UTF-8') . $w[3] . '<';
        }, $part);
        // placeholder / title / alt / aria-label attributes
        $part = preg_replace_callback('/\b(placeholder|title|alt|aria-label)="([^"<>]+)"/u', function ($m) use ($map) {
            $am = am_lookup(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $map);
            return $am === null ? $m[0] : $m[1] . '="' . htmlspecialchars($am, ENT_QUOTES, 'UTF-8') . '"';
        }, $part);
        // text on submit / button inputs
        $part = preg_replace_callback('/(<input\b[^>]*\btype="(?:submit|button)"[^>]*\bvalue=")([^"]+)(")/iu', function ($m) use ($map) {
            $am = am_lookup(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $map);
            return $am === null ? $m[0] : $m[1] . htmlspecialchars($am, ENT_QUOTES, 'UTF-8') . $m[3];
        }, $part);
        $parts[$i] = $part;
    }
    return implode('', $parts);
}
