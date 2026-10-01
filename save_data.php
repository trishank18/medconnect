<?php
$servername = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'medconnect';

$conn = new mysqli($servername, $username, $password, $dbname, (int) $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$heart_rate = filter_input(INPUT_GET, 'heart_rate', FILTER_VALIDATE_INT);
$spo2 = filter_input(INPUT_GET, 'spo2', FILTER_VALIDATE_INT);
$temperature = filter_input(INPUT_GET, 'temperature', FILTER_VALIDATE_FLOAT);
$patient_id = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT)
    ?: (int) (getenv('DEFAULT_PATIENT_ID') ?: 4);

$stmt = $conn->prepare(
    'INSERT INTO health_metrics (patient_id, heart_rate, spo2, temperature)
     VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('iidd', $patient_id, $heart_rate, $spo2, $temperature);

if ($stmt->execute()) {
    echo "Data saved successfully.";
} else {
    echo "Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>
