<?php
// JSON endpoints for fingerprint / face (passkey) registration and sign-in.
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_once "../config/webauthn.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pk_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$action = (string) ($_GET['action'] ?? ($in['action'] ?? ''));

try {
    wa_ensure_schema();

    // ---------- start registering a device (signed-in users only) ----------
    if ($action === 'register_options') {
        if (empty($_SESSION['user_id'])) pk_out(['error' => 'Please sign in first.'], 401);
        $uid = (int) $_SESSION['user_id'];
        $st = $pdo->prepare("SELECT username, name FROM users WHERE id = ? AND status = 1");
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u) pk_out(['error' => 'User not found.'], 404);
        $challenge = wa_b64u_enc(random_bytes(32));
        $_SESSION['wa_reg_challenge'] = $challenge;
        $exclude = [];
        $ex = $pdo->prepare("SELECT credential_id FROM user_passkeys WHERE user_id = ?");
        $ex->execute([$uid]);
        foreach ($ex->fetchAll() as $r) $exclude[] = ['type' => 'public-key', 'id' => $r['credential_id']];
        pk_out([
            'challenge' => $challenge,
            'rp' => ['name' => (string) (company_settings()['company_name'] ?? 'Block Plant ERP'), 'id' => wa_rp_id()],
            'user' => ['id' => wa_b64u_enc('u' . $uid), 'name' => (string) $u['username'], 'displayName' => (string) ($u['name'] ?: $u['username'])],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
            'excludeCredentials' => $exclude,
            'timeout' => 60000,
            'attestation' => 'none',
        ]);
    }

    // ---------- finish registering ----------
    if ($action === 'register_finish') {
        if (empty($_SESSION['user_id'])) pk_out(['error' => 'Please sign in first.'], 401);
        $challenge = $_SESSION['wa_reg_challenge'] ?? '';
        unset($_SESSION['wa_reg_challenge']);
        if ($challenge === '') pk_out(['error' => 'Registration expired. Try again.'], 400);

        $clientJson = wa_b64u_dec($in['clientDataJSON'] ?? '');
        $attObj = wa_b64u_dec($in['attestationObject'] ?? '');
        if ($clientJson === false || $attObj === false) pk_out(['error' => 'Bad data.'], 400);
        wa_check_client_data($clientJson, 'webauthn.create', $challenge);

        $att = wa_cbor_decode($attObj);
        $parsed = wa_parse_auth_data($att['authData'] ?? '');
        wa_check_rp_hash($parsed);
        if ($parsed['credentialId'] === null || !is_array($parsed['publicKey'])) pk_out(['error' => 'No credential received.'], 400);
        $pem = wa_cose_to_pem($parsed['publicKey']);
        $credId = wa_b64u_enc($parsed['credentialId']);

        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') $label = 'This device';
        $pdo->prepare("INSERT INTO user_passkeys(user_id, credential_id, public_key, sign_count, label) VALUES(?,?,?,?,?)")
            ->execute([(int) $_SESSION['user_id'], $credId, $pem, $parsed['signCount'], mb_substr($label, 0, 120)]);
        log_activity('create', 'settings', (int) $_SESSION['user_id'], 'Fingerprint / face login enabled: ' . $label);
        pk_out(['ok' => true]);
    }

    // ---------- start signing in ----------
    if ($action === 'login_options') {
        $challenge = wa_b64u_enc(random_bytes(32));
        $_SESSION['wa_login_challenge'] = $challenge;
        pk_out(['challenge' => $challenge, 'rpId' => wa_rp_id(), 'userVerification' => 'required', 'timeout' => 60000, 'allowCredentials' => []]);
    }

    // ---------- finish signing in ----------
    if ($action === 'login_finish') {
        $challenge = $_SESSION['wa_login_challenge'] ?? '';
        unset($_SESSION['wa_login_challenge']);
        if ($challenge === '') pk_out(['error' => 'Sign-in expired. Try again.'], 400);

        $credId = wa_b64u_enc(wa_b64u_dec($in['id'] ?? ''));
        $clientJson = wa_b64u_dec($in['clientDataJSON'] ?? '');
        $authData = wa_b64u_dec($in['authenticatorData'] ?? '');
        $sig = wa_b64u_dec($in['signature'] ?? '');
        if ($clientJson === false || $authData === false || $sig === false) pk_out(['error' => 'Bad data.'], 400);

        $st = $pdo->prepare("SELECT p.*, u.id AS uid, u.name, u.role, u.status FROM user_passkeys p JOIN users u ON u.id = p.user_id WHERE p.credential_id = ?");
        $st->execute([$credId]);
        $row = $st->fetch();
        if (!$row || (int) $row['status'] !== 1) pk_out(['error' => 'This fingerprint is not registered. Sign in with your password.'], 401);

        wa_check_client_data($clientJson, 'webauthn.get', $challenge);
        $parsed = wa_parse_auth_data($authData);
        wa_check_rp_hash($parsed);

        $signed = $authData . hash('sha256', $clientJson, true);
        if (openssl_verify($signed, $sig, $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            pk_out(['error' => 'Fingerprint check failed.'], 401);
        }
        // A counter that goes backwards means a cloned authenticator.
        if ($parsed['signCount'] > 0 && $parsed['signCount'] <= (int) $row['sign_count']) {
            pk_out(['error' => 'This passkey looks cloned and was refused.'], 401);
        }
        $pdo->prepare("UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?")->execute([$parsed['signCount'], $row['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $row['uid'];
        $_SESSION['user_name'] = $row['name'];
        $_SESSION['role'] = strtolower(trim((string) $row['role']));
        $_SESSION['just_logged_in'] = true;
        log_activity('login', 'settings', (int) $row['uid'], 'Signed in with fingerprint / face');
        pk_out(['ok' => true, 'redirect' => wa_landing_url()]);
    }

    // ---------- remove a registered device ----------
    if ($action === 'delete') {
        if (empty($_SESSION['user_id'])) pk_out(['error' => 'Please sign in first.'], 401);
        $pdo->prepare("DELETE FROM user_passkeys WHERE id = ? AND user_id = ?")->execute([(int) ($in['id'] ?? 0), (int) $_SESSION['user_id']]);
        pk_out(['ok' => true]);
    }

    pk_out(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    pk_out(['error' => 'Passkey error: ' . $e->getMessage()], 400);
}
