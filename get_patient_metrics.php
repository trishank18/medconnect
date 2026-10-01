<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/includes/db_connection.php';

try {
    if (!isset($_GET['patient_id'])) {
        throw new Exception('Patient ID not provided');
    }

    $patientId = $_GET['patient_id'];

    // Verify the doctor has access to this patient
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE doctor_id = :doctor_id AND patient_id = :patient_id
    ");
    $stmt->bindParam(':doctor_id', $_SESSION['doctor_id']);
    $stmt->bindParam(':patient_id', $patientId);
    $stmt->execute();
    
    if ($stmt->fetchColumn() == 0) {
        throw new Exception('Patient not found or access denied');
    }

    // Get last 30 days of health metrics
    $stmt = $conn->prepare("
        SELECT heart_rate, spo2, temperature, 
               blood_pressure_sys, blood_pressure_dia, recorded_at
        FROM health_metrics
        WHERE patient_id = :patient_id
        AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY recorded_at ASC
    ");
    $stmt->bindParam(':patient_id', $patientId);
    $stmt->execute();
    $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'metrics' => $metrics
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}