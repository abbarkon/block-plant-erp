<?php
/**
 * Authenticated download for evidence attached to a revoked warning letter.
 *
 * Evidence is stored under uploads/warning_evidence, which is denied to direct
 * web access. This endpoint checks HR view permission and the file's revocation
 * record before streaming the document.
 */
session_start();
require_once "../config/database.php";
require_once "../config/helpers.php";
require_login();
require_permission('hr', 'view');

ensure_attendance_warning_schema();

$fileId = (int) ($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    render_not_found(
        'Evidence document not found',
        'The requested evidence document does not exist.',
        'attendance_warnings.php',
        'Back to attendance warnings'
    );
}

$stmt = $pdo->prepare(
    "SELECT f.*, r.employee_id, r.period_from, r.period_to,
            e.name AS employee_name, e.employee_code
     FROM attendance_warning_revocation_files f
     JOIN attendance_warning_revocations r ON r.id = f.revocation_id
     JOIN employees e ON e.id = r.employee_id
     WHERE f.id = ?
     LIMIT 1"
);
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    render_not_found(
        'Evidence document not found',
        'The requested evidence document does not exist or was removed when the warning was restored.',
        'attendance_warnings.php',
        'Back to attendance warnings'
    );
}

$base = warning_evidence_base_dir(false);
$baseReal = $base !== '' ? realpath($base) : false;
$path = $base !== ''
    ? $base . DIRECTORY_SEPARATOR . (int) $file['revocation_id'] . DIRECTORY_SEPARATOR . basename((string) $file['stored_name'])
    : '';
$real = $path !== '' ? realpath($path) : false;
$insideBase = $real !== false
    && $baseReal !== false
    && strpos($real, $baseReal . DIRECTORY_SEPARATOR) === 0;

if (!$insideBase || !is_file($real)) {
    render_not_found(
        'Evidence document not found',
        'The evidence file is missing from storage.',
        'attendance_warnings.php',
        'Back to attendance warnings'
    );
}

$allowedMimes = [];
foreach (warning_evidence_allowed_types() as $mimes) {
    foreach ($mimes as $mime) {
        $allowedMimes[$mime] = true;
    }
}
$mime = (string) ($file['mime_type'] ?? '');
if (!isset($allowedMimes[$mime])) {
    $mime = 'application/octet-stream';
}

$downloadName = (string) ($file['original_name'] ?? 'evidence');
if ($downloadName === '') {
    $downloadName = 'evidence.' . pathinfo((string) $file['stored_name'], PATHINFO_EXTENSION);
}
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $downloadName);
$asciiName = str_replace(['"', '\\', "\r", "\n"], '_', (string) $asciiName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($real));
header(
    'Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\''
    . rawurlencode($downloadName)
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');

readfile($real);
exit;
