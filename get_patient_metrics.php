<?php
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!medconnect_role_authenticated('doctor')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    $patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
    if (!$patientId || $patientId < 1) {
        http_response_code(422);
        throw new Exception('A valid patient ID is required.');
    }

    // Allow only an approved doctor with an explicit assignment or appointment relationship.
    $stmt = $conn->prepare("
        SELECT 1
        FROM patients p
        INNER JOIN doctors d ON d.id = :doctor_id
        WHERE p.id = :patient_id
          AND d.verification_status = 'approved'
          AND (p.doctor_id = d.id OR EXISTS (
              SELECT 1 FROM appointments a
              WHERE a.patient_id = p.id AND a.doctor_id = d.id
          ))
        LIMIT 1
    ");
    $stmt->execute([':doctor_id' => (int) $_SESSION['auth']['doctor']['id'], ':patient_id' => $patientId]);
    
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        throw new Exception('Patient not found or access denied');
    }

    // Get last 30 days of health metrics
    $stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, 
               blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY recorded_at ASC, id ASC
    ");
    $stmt->bindParam(':patient_id', $patientId);
    $stmt->execute();
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'metrics' => $metrics
    ]);

} catch (Throwable $e) {
    if ($e instanceof PDOException) {
        error_log('MedConnect doctor metrics query failed: ' . $e->getMessage());
        if (http_response_code() < 400) {
            http_response_code(500);
        }
    } elseif (http_response_code() < 400) {
        error_log('MedConnect doctor metrics request failed: ' . get_class($e));
        http_response_code(500);
    }

    $errorMessage = match (http_response_code()) {
        422 => 'A valid patient ID is required.',
        403 => 'Patient not found or access denied.',
        default => 'Health data is temporarily unavailable.',
    };
    echo json_encode([
        'success' => false,
        'error' => $errorMessage
    ]);
}
