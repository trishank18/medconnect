<?php

function request_patient_predictions(int $patientId): array
{
    if ($patientId < 1 || !function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'The prediction service is unavailable.'];
    }

    $baseUrl = rtrim((string) (getenv('ML_API_URL') ?: 'http://127.0.0.1:8001'), '/');
    $token = (string) getenv('ML_API_TOKEN');
    if ($token === '') {
        return ['ok' => false, 'error' => 'The prediction service is not configured.'];
    }

    $handle = curl_init($baseUrl . '/predict/patient/' . $patientId);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-ML-Token: ' . $token],
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $payload = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($payload) || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'The prediction service is temporarily unavailable.'];
    }
    return ['ok' => true, 'data' => $payload];
}

function store_patient_predictions(PDO $conn, int $patientId, array $payload): void
{
    if (($payload['status'] ?? '') !== 'ok' || empty($payload['predictions'])) {
        return;
    }
    $bounds = [
        'heart_rate' => [20, 250],
        'spo2' => [50, 100],
        'temperature' => [25, 45],
        'blood_pressure_sys' => [50, 250],
        'blood_pressure_dia' => [30, 150],
    ];
    $stmt = $conn->prepare(
        'INSERT INTO ml_predictions
            (patient_id, target_name, predicted_value, model_version, prediction_timestamp, target_timestamp)
         SELECT ?, ?, ?, ?, ?, ?
         WHERE NOT EXISTS (
            SELECT 1 FROM ml_predictions
            WHERE patient_id = ? AND target_name = ?
              AND model_version = ? AND prediction_timestamp = ?
         )'
    );
    foreach ($payload['predictions'] as $prediction) {
        $target = (string) ($prediction['target_name'] ?? '');
        $value = $prediction['predicted_value'] ?? null;
        $modelVersion = (string) ($prediction['model_version'] ?? '');
        $predictionTime = strtotime((string) ($prediction['prediction_timestamp'] ?? ''));
        $targetTime = strtotime((string) ($prediction['target_timestamp'] ?? ''));
        if (!isset($bounds[$target]) || !is_numeric($value) || !is_finite((float) $value)
            || (float) $value < $bounds[$target][0] || (float) $value > $bounds[$target][1]
            || $modelVersion === '' || strlen($modelVersion) > 80
            || $predictionTime === false || $targetTime === false) {
            continue;
        }
        $timestamp = date('Y-m-d H:i:s', $predictionTime);
        $stmt->execute([
            $patientId,
            $target,
            (float) $value,
            $modelVersion,
            $timestamp,
            date('Y-m-d H:i:s', $targetTime),
            $patientId,
            $target,
            $modelVersion,
            $timestamp,
        ]);
    }
}
