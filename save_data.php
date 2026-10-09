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
$patient_id = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT)
    ?: (int) (getenv('DEFAULT_PATIENT_ID') ?: 4);
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
    echo "Data saved successfully.";
} else {
    http_response_code(500);
    echo "Unable to save data.";
}
$stmt->close();
$conn->close();
?>
