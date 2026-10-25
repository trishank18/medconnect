<?php
require_once __DIR__ . '/includes/env.php';
medconnect_load_env_file();

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

$deviceToken = (string) (getenv('DEVICE_INGEST_TOKEN') ?: '');
$providedToken = (string) ($_SERVER['HTTP_X_MEDCONNECT_DEVICE_TOKEN'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $deviceToken === '' || !hash_equals($deviceToken, $providedToken)) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 401 : 405);
    exit('Unauthorized.');
}

$heartRate = filter_input(INPUT_POST, 'heart_rate', FILTER_VALIDATE_INT);
$spo2 = filter_input(INPUT_POST, 'spo2', FILTER_VALIDATE_INT);
$temperature = filter_input(INPUT_POST, 'temperature', FILTER_VALIDATE_FLOAT);
$patientId = filter_var(getenv('DEVICE_PATIENT_ID'), FILTER_VALIDATE_INT);

if (!$patientId || $patientId < 1 || $heartRate === false || $heartRate === null
    || $spo2 === false || $spo2 === null || $temperature === false || $temperature === null
    || $heartRate < 20 || $heartRate > 250 || $spo2 < 50 || $spo2 > 100
    || $temperature < 25 || $temperature > 45) {
    http_response_code(422);
    exit('Invalid health measurement.');
}

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/ml_client.php';

try {
    $stmt = $conn->prepare(
        'INSERT INTO health_metrics (patient_id, heart_rate, spo2, temperature)
         VALUES (:patient_id, :heart_rate, :spo2, :temperature)'
    );
    $stmt->execute([
        ':patient_id' => $patientId,
        ':heart_rate' => $heartRate,
        ':spo2' => $spo2,
        ':temperature' => $temperature,
    ]);

    // Reconcile observed values only with predictions already due for this patient.
    try {
        $reconcile = $conn->prepare(
            'UPDATE ml_predictions SET actual_value = :actual_value
             WHERE patient_id = :patient_id AND target_name = :target_name AND actual_value IS NULL
               AND target_timestamp <= NOW() AND target_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        foreach (['heart_rate' => $heartRate, 'spo2' => $spo2, 'temperature' => $temperature] as $target => $value) {
            $reconcile->execute([
                ':actual_value' => $value,
                ':patient_id' => $patientId,
                ':target_name' => $target,
            ]);
        }
    } catch (PDOException $ignored) {
        // The optional ML table must never prevent a real sensor reading from being saved.
    }

    // Prediction estimates are separate from observed readings and remain best-effort.
    try {
        $predictionResponse = request_patient_predictions($patientId);
        if ($predictionResponse['ok']) {
            store_patient_predictions($conn, $patientId, $predictionResponse['data']);
        }
    } catch (Throwable $ignored) {
        // Sensor ingestion stays available when the optional ML service is offline.
    }

    echo 'Data saved successfully.';
} catch (PDOException $exception) {
    error_log('MedConnect sensor ingestion failed: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Unable to save data.';
}
?>
