<?php
require_once dirname(__DIR__, 2) . '/includes/env.php';
medconnect_load_env_file();

$url = $argv[1] ?? '';
$token = getenv('RAG_SERVICE_TOKEN');
if (!filter_var($url, FILTER_VALIDATE_URL) || !$token || !function_exists('curl_init')) {
    fwrite(STDERR, "Integration prerequisites are unavailable.\n"); exit(2);
}

$payload = json_encode([
    'question' => 'What is my latest heart rate?',
    'request_id' => bin2hex(random_bytes(16)),
    'conversation_id' => str_repeat('a', 64),
]);
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-MedConnect-Token: ' . $token],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$failed = $response === false;
curl_close($curl);
$decoded = json_decode((string) $response, true);

// The service must deny personal data at its boundary without asking Gemini.
if ($failed || $status !== 403 || !is_array($decoded) || empty($decoded['detail'])) {
    fwrite(STDERR, "PHP-to-Python protected contract failed.\n"); exit(1);
}
echo "PHP-to-Python protected request contract passed.\n";
