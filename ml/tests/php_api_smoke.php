<?php
// Smoke test for the same PHP stream transport used by the application.
$baseUrl = getenv('ML_API_URL') ?: 'http://127.0.0.1:8001';
$token = getenv('ML_API_TOKEN');
if (!$token) {
    fwrite(STDERR, "ML_API_TOKEN is required.\n");
    exit(2);
}
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nX-ML-Token: {$token}\r\n",
    'content' => json_encode(['target_name' => 'heart_rate', 'features' => ['spo2' => 97, 'temperature' => 36.8]]),
    'timeout' => 4,
    'ignore_errors' => true,
]]);
$response = file_get_contents(rtrim($baseUrl, '/') . '/predict', false, $context);
$status = $http_response_header[0] ?? '';
$data = is_string($response) ? json_decode($response, true) : null;
if (!str_contains($status, '200') || !is_array($data) || !isset($data['predicted_value'], $data['model_version'])) {
    fwrite(STDERR, "PHP-to-ML API smoke test failed ({$status}).\n");
    exit(1);
}
echo "PHP-to-ML API smoke test passed for target {$data['target_name']} (model {$data['model_version']}).\n";
