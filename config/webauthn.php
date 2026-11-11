<?php
// Passkey (fingerprint / face) sign-in using WebAuthn. No external libraries.
// Supports ES256 (Android, iPhone, most laptops) and RS256 (some Windows Hello setups). Attestation is not requested.

function wa_ensure_schema() {
    static $done = false;
    if ($done) return;
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_passkeys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        credential_id VARCHAR(512) NOT NULL,
        public_key TEXT NOT NULL,
        sign_count BIGINT NOT NULL DEFAULT 0,
        label VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        UNIQUE KEY uniq_credential (credential_id(255)),
        KEY idx_user (user_id)
    )");
    $done = true;
}

function wa_b64u_enc($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function wa_b64u_dec($str) {
    $str = strtr((string) $str, '-_', '+/');
    return base64_decode($str . str_repeat('=', (4 - strlen($str) % 4) % 4), true);
}

function wa_host() {
    $h = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $h);
}
function wa_rp_id() { return wa_host(); }
function wa_origin() {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $local = in_array(wa_host(), ['localhost', '127.0.0.1'], true);
    return ($local ? 'http://' : 'https://') . $host;
}

// ---------- minimal CBOR decoder ----------
function wa_cbor_len($d, &$o, $ai) {
    if ($ai < 24) return $ai;
    if ($ai === 24) return ord($d[$o++]);
    if ($ai === 25) { $v = unpack('n', substr($d, $o, 2))[1]; $o += 2; return $v; }
    if ($ai === 26) { $v = unpack('N', substr($d, $o, 4))[1]; $o += 4; return $v; }
    if ($ai === 27) { $v = unpack('J', substr($d, $o, 8))[1]; $o += 8; return $v; }
    throw new RuntimeException('Unsupported CBOR length');
}
function wa_cbor_decode($d, &$o = 0) {
    if ($o >= strlen($d)) throw new RuntimeException('CBOR: unexpected end');
    $b = ord($d[$o++]);
    $mt = $b >> 5;
    $ai = $b & 31;
    if ($mt === 7) {
        if ($ai === 20) return false;
        if ($ai === 21) return true;
        if ($ai === 22 || $ai === 23) return null;
        throw new RuntimeException('Unsupported CBOR simple value');
    }
    $len = wa_cbor_len($d, $o, $ai);
    switch ($mt) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2:
        case 3:
            $v = substr($d, $o, $len);
            $o += $len;
            return $v;
        case 4:
            $arr = [];
            for ($i = 0; $i < $len; $i++) $arr[] = wa_cbor_decode($d, $o);
            return $arr;
        case 5:
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $k = wa_cbor_decode($d, $o);
                $map[$k] = wa_cbor_decode($d, $o);
            }
            return $map;
    }
    throw new RuntimeException('Unsupported CBOR type');
}

// ---------- DER helpers for RSA public keys ----------
function wa_der_len($n) {
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function wa_der($tag, $content) { return chr($tag) . wa_der_len(strlen($content)) . $content; }
function wa_der_int($bin) {
    $bin = ltrim($bin, "\0");
    if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\0" . $bin;
    return wa_der(0x02, $bin);
}
function wa_pem($der) {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** COSE key (CBOR map) -> PEM public key. */
function wa_cose_to_pem(array $key) {
    $kty = $key[1] ?? null;
    if ($kty === 2) {                         // EC2, P-256
        if (($key[-1] ?? null) !== 1) throw new RuntimeException('Unsupported curve');
        $x = $key[-2] ?? ''; $y = $key[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('Bad EC key');
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        return wa_pem($prefix . "\x04" . $x . $y);
    }
    if ($kty === 3) {                         // RSA
        $n = $key[-1] ?? ''; $e = $key[-2] ?? '';
        $rsaKey = wa_der(0x30, wa_der_int($n) . wa_der_int($e));
        $alg = wa_der(0x30, wa_der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
        return wa_pem(wa_der(0x30, $alg . wa_der(0x03, "\0" . $rsaKey)));
    }
    throw new RuntimeException('Unsupported key type');
}

/** Parse authenticator data. */
function wa_parse_auth_data($ad) {
    if (strlen($ad) < 37) throw new RuntimeException('Authenticator data too short');
    $out = [
        'rpIdHash' => substr($ad, 0, 32),
        'flags' => ord($ad[32]),
        'signCount' => unpack('N', substr($ad, 33, 4))[1],
        'credentialId' => null,
        'publicKey' => null,
    ];
    if ($out['flags'] & 0x40) {
        $o = 37 + 16;                         // skip AAGUID
        $len = unpack('n', substr($ad, $o, 2))[1];
        $o += 2;
        $out['credentialId'] = substr($ad, $o, $len);
        $o += $len;
        $out['publicKey'] = wa_cbor_decode($ad, $o);
    }
    return $out;
}

function wa_check_client_data($json, $type, $expectedChallenge) {
    $c = json_decode($json, true);
    if (!is_array($c)) throw new RuntimeException('Bad client data');
    if (($c['type'] ?? '') !== $type) throw new RuntimeException('Wrong request type');
    if (!hash_equals((string) $expectedChallenge, (string) ($c['challenge'] ?? ''))) throw new RuntimeException('Challenge mismatch');
    if (($c['origin'] ?? '') !== wa_origin()) throw new RuntimeException('Origin mismatch');
}

function wa_check_rp_hash($authDataParsed) {
    if (!hash_equals(hash('sha256', wa_rp_id(), true), $authDataParsed['rpIdHash'])) {
        throw new RuntimeException('Relying party mismatch');
    }
    if (!($authDataParsed['flags'] & 0x01)) throw new RuntimeException('User presence missing');
    if (!($authDataParsed['flags'] & 0x04)) throw new RuntimeException('Fingerprint / face check was not performed');
}

/** Where a user lands after signing in (same rules as the password login). */
function wa_landing_url() {
    $landingPages = [
        'dashboard' => '../dashboard/', 'sales' => '../sales/', 'crm' => '../crm/pipeline.php', 'customers' => '../customers/',
        'inventory' => '../inventory/', 'production' => '../production/', 'purchases' => '../purchases/', 'expenses' => '../expenses/',
        'assets' => '../assets/', 'suppliers' => '../suppliers/', 'hr' => '../hr/', 'finance' => '../finance/accounts.php',
        'reports' => '../reports/financial.php', 'settings' => '../settings/profile.php',
    ];
    foreach ($landingPages as $module => $page) {
        if (has_permission($module, 'view')) return $page;
    }
    return '../auth/login.php';
}
