<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin-login.html');
    exit();
}

if (!isset($_SESSION['admin_csrf']) || !hash_equals($_SESSION['admin_csrf'], (string)($_POST['admin_csrf'] ?? ''))) {
    header('Location: admin-dashboard.php?error=invalid_request');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-dashboard.php');
    exit();
}

$patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    header('Location: admin-dashboard.php?error=invalid_patient');
    exit();
}

try {
    $patientStmt = $conn->prepare('SELECT fullname FROM patients WHERE id = :id');
    $patientStmt->execute([':id' => $patientId]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        header('Location: admin-dashboard.php?error=patient_not_found');
        exit();
    }

    $conn->beginTransaction();
    $deleteSuggestions = $conn->prepare('DELETE FROM patient_suggestions WHERE patient_id = :patient_id');
    $deleteSuggestions->execute([':patient_id' => $patientId]);
    $deletePrescriptions = $conn->prepare('DELETE FROM patient_prescriptions WHERE patient_id = :patient_id');
    $deletePrescriptions->execute([':patient_id' => $patientId]);
    $deleteAppointments = $conn->prepare('DELETE FROM appointments WHERE patient_id = :patient_id');
    $deleteAppointments->execute([':patient_id' => $patientId]);
    $deleteMetrics = $conn->prepare('DELETE FROM health_metrics WHERE patient_id = :patient_id');
    $deleteMetrics->execute([':patient_id' => $patientId]);
    $deletePatient = $conn->prepare('DELETE FROM patients WHERE id = :patient_id');
    $deletePatient->execute([':patient_id' => $patientId]);
    $conn->commit();

    header('Location: admin-dashboard.php?deleted_patient=' . rawurlencode($patient['fullname']));
    exit();
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    header('Location: admin-dashboard.php?error=patient_delete_failed');
    exit();
}
