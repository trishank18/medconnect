<?php
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json');

if (!medconnect_role_authenticated('patient')) {
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
         ORDER BY recorded_at DESC, id DESC
         LIMIT 100'
    );
    $stmt->bindValue(':patient_id', $_SESSION['auth']['patient']['id'], PDO::PARAM_INT);
    $stmt->execute();
    $records = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'predict_health.py';
    $python = getenv('PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');
    // Use argv mode so an environment-provided executable path is never parsed by a shell.
    $process = proc_open([$python, $script], $descriptors, $pipes, __DIR__);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the ML process.');
    }

    fwrite($pipes[0], json_encode($records, JSON_THROW_ON_ERROR));
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
    echo json_encode($prediction, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $error) {
    error_log('MedConnect experimental prediction request failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The prediction service is temporarily unavailable.']);
}
