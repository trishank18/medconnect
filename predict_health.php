<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    $stmt = $conn->prepare(
        'SELECT heart_rate, spo2, temperature, blood_pressure_sys, blood_pressure_dia, recorded_at
         FROM health_metrics
         WHERE patient_id = :patient_id
         ORDER BY recorded_at DESC
         LIMIT 100'
    );
    $stmt->bindValue(':patient_id', $_SESSION['patient_id'], PDO::PARAM_INT);
    $stmt->execute();
    $records = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'predict_health.py';
    $command = 'py ' . escapeshellarg($script);
    $process = proc_open($command, $descriptors, $pipes, __DIR__);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the ML process.');
    }

    fwrite($pipes[0], json_encode($records));
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || trim($result) === '') {
        throw new RuntimeException(trim($error) ?: 'The ML process returned no result.');
    }

    $prediction = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
    echo json_encode($prediction);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}
