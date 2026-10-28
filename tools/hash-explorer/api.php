<?php
declare(strict_types=1);

// Local development helper only. No database, file storage, or request logging.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    respond(403, ['error' => 'The password hashing helper is limited to loopback requests.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'capabilities') {
    respond(200, ['bcrypt' => true, 'argon2id' => defined('PASSWORD_ARGON2ID')]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Use a local POST request.']);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $requestOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if (strcasecmp(rtrim($origin, '/'), $requestOrigin) !== 0) {
        respond(403, ['error' => 'Cross-origin requests are not accepted.']);
    }
}
if (strlen((string)file_get_contents('php://input')) > 16384) {
    respond(413, ['error' => 'Input exceeds the 16 KB local demo limit.']);
}
$raw = file_get_contents('php://input');
$data = json_decode($raw === false ? '' : $raw, true);
if (!is_array($data) || !isset($data['action'], $data['algorithm'], $data['text']) || !is_string($data['action']) || !is_string($data['algorithm']) || !is_string($data['text'])) {
    respond(400, ['error' => 'Expected string action, algorithm, and text fields.']);
}
$algorithm = $data['algorithm'];
$text = $data['text'];
if (!in_array($algorithm, ['bcrypt', 'argon2id'], true)) {
    respond(400, ['error' => 'Unsupported password hashing algorithm.']);
}
if ($algorithm === 'argon2id' && !defined('PASSWORD_ARGON2ID')) {
    respond(501, ['error' => 'Argon2id is unavailable in this PHP build.']);
}
if ($algorithm === 'bcrypt' && strlen($data['text']) > 72) {
    respond(400, ['error' => 'bcrypt accepts at most 72 input bytes in this PHP implementation. Use a shorter input or Argon2id.']);
}
$algo = $algorithm === 'bcrypt' ? PASSWORD_BCRYPT : PASSWORD_ARGON2ID;

try {
    if ($data['action'] === 'hash') {
        $hash = password_hash($text, $algo);
        if (!is_string($hash)) {
            respond(500, ['error' => 'PHP could not generate the password hash.']);
        }
        respond(200, ['hash' => $hash, 'algorithm' => $algorithm, 'parameters' => password_get_info($hash)]);
    }
    if ($data['action'] === 'verify' && isset($data['hash']) && is_string($data['hash'])) {
        respond(200, ['match' => password_verify($text, $data['hash'])]);
    }
    respond(400, ['error' => 'Unsupported action or missing hash for verification.']);
} catch (Throwable $e) {
    // Do not return exception messages or input values to the client.
    respond(500, ['error' => 'The local password hash operation failed.']);
}
