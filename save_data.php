<?php
$servername = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'medconnect';

$conn = new mysqli($servername, $username, $password, $dbname, (int) $port);

if ($conn->connect_error) {
    http_response_code(503);
    exit('Database unavailable.');
}

$deviceToken = getenv('DEVICE_INGEST_TOKEN');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$deviceToken || !hash_equals($deviceToken, (string)($_SERVER['HTTP_X_MEDCONNECT_DEVICE_TOKEN'] ?? ''))) {
    http_response_code(401);
    exit('Unauthorized.');
}

$heart_rate = filter_input(INPUT_POST, 'heart_rate', FILTER_VALIDATE_INT);
$spo2 = filter_input(INPUT_POST, 'spo2', FILTER_VALIDATE_INT);
$temperature = filter_input(INPUT_POST, 'temperature', FILTER_VALIDATE_FLOAT);
$patient_id = (int) (getenv('DEVICE_PATIENT_ID') ?: getenv('DEFAULT_PATIENT_ID'));
if ($patient_id < 1 || $heart_rate === false || $spo2 === false || $temperature === false
    || $heart_rate < 20 || $heart_rate > 250 || $spo2 < 50 || $spo2 > 100
    || $temperature < 20 || $temperature > 50) {
    http_response_code(422);
    exit('Invalid health measurement.');
}

$stmt = $conn->prepare(
    'INSERT INTO health_metrics (patient_id, heart_rate, spo2, temperature)
     VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('iidd', $patient_id, $heart_rate, $spo2, $temperature);

if ($stmt->execute()) {
    // Keep observed data separate and reconcile only as nullable prediction ground truth.
    try {
        $actualByTarget = ['heart_rate' => $heart_rate, 'spo2' => $spo2, 'temperature' => $temperature];
        $reconcile = $conn->prepare("UPDATE ml_predictions SET actual_value = ?
            WHERE patient_id = ? AND target_name = ? AND actual_value IS NULL
              AND target_timestamp <= NOW() AND target_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        foreach ($actualByTarget as $target => $actual) {
            $reconcile->bind_param('dis', $actual, $patient_id, $target);
            $reconcile->execute();
        }
    } catch (Throwable $ignored) {
        // This optional table must not prevent saving an actual sensor reading.
    }

    // Predict best-effort from the genuine MAX30102 readings and supplied temperature only.
    $modelInputs = ['heart_rate' => (float) $heart_rate, 'spo2' => (float) $spo2, 'temperature' => (float) $temperature];
    $apiUrl = rtrim(getenv('ML_API_URL') ?: 'http://127.0.0.1:8001', '/');
    try {
      $insertPrediction = $conn->prepare('INSERT INTO ml_predictions
          (patient_id, target_name, predicted_value, model_version, prediction_timestamp, target_timestamp)
          VALUES (?, ?, ?, ?, ?, ?)');
      foreach (array_merge(array_keys($modelInputs), ['blood_pressure_sys', 'blood_pressure_dia']) as $target) {
        $features = $modelInputs;
        unset($features[$target]);
        $headers = "Content-Type: application/json\r\n";
        $mlToken = getenv('ML_API_TOKEN');
        if ($mlToken) $headers .= 'X-ML-Token: ' . $mlToken . "\r\n";
        $context = stream_context_create(['http' => ['method'=>'POST','header'=>$headers,
            'content'=>json_encode(['target_name'=>$target,'features'=>$features]),'timeout'=>2,'ignore_errors'=>true]]);
        $response = @file_get_contents($apiUrl . '/predict', false, $context);
        $prediction = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($prediction) || !isset($prediction['predicted_value'], $prediction['model_version'])) continue;
        $predicted = (float) $prediction['predicted_value'];
        $modelVersion = (string) $prediction['model_version'];
        $createdAt = date('Y-m-d H:i:s', strtotime($prediction['prediction_timestamp'] ?? 'now'));
        $targetAt = date('Y-m-d H:i:s', strtotime($prediction['target_timestamp'] ?? 'now'));
        $insertPrediction->bind_param('isdsss', $patient_id, $target, $predicted, $modelVersion, $createdAt, $targetAt);
        $insertPrediction->execute();
      }
      $insertPrediction->close();
    } catch (Throwable $ignored) {
        // Actual IoT reading was already persisted; prediction failures are non-blocking.
    }
    echo "Data saved successfully.";
} else {
    http_response_code(500);
    echo "Unable to save data.";
}
$stmt->close();
$conn->close();
?>
