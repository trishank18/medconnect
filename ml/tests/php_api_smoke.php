<?php
// Read-only smoke test for the patient-scoped prediction API.
$baseUrl = rtrim((string) (getenv('ML_API_URL') ?: 'http://127.0.0.1:8001'), '/');
$token = (string) (getenv('ML_API_TOKEN') ?: '');
$patientId = filter_var(getenv('ML_SMOKE_TEST_PATIENT_ID'), FILTER_VALIDATE_INT);
if ($token === '' || !$patientId || $patientId < 1) {
    fwrite(STDERR, "Set ML_API_TOKEN and ML_SMOKE_TEST_PATIENT_ID for the read-only smoke test.\n");
    exit(2);
}

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Accept: application/json\r\nX-ML-Token: {$token}\r\n",
    'content' => '',
    'timeout' => 4,
    'ignore_errors' => true,
]]);
$response = @file_get_contents($baseUrl . '/predict/patient/' . $patientId, false, $context);
$status = $http_response_header[0] ?? '';
$data = is_string($response) ? json_decode($response, true) : null;
if (!str_contains($status, '200') || !is_array($data)
    || !isset($data['status']) || !is_array($data['predictions'] ?? null)) {
    fwrite(STDERR, "PHP-to-ML patient endpoint smoke test failed ({$status}).\n");
    exit(1);
}

echo "PHP-to-ML patient endpoint smoke test passed (status {$data['status']}, predictions " . count($data['predictions']) . ").\n";
