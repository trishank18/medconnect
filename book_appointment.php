<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db_connection.php';

if (!medconnect_role_authenticated('patient')) {
    header("Location: patient-login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Only appointment form submissions are supported.');
}

    if (!isset($_SESSION['patient_csrf']) || !hash_equals($_SESSION['patient_csrf'], (string) ($_POST['patient_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
    $patient_id = $_SESSION['auth']['patient']['id'];
    $doctor_id = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $schedule = DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
    $scheduleErrors = DateTime::getLastErrors();
    $hasScheduleErrors = is_array($scheduleErrors) && ($scheduleErrors['warning_count'] > 0 || $scheduleErrors['error_count'] > 0);
    if (!$doctor_id || !$schedule || $hasScheduleErrors || $schedule->getTimestamp() <= time() || strlen($notes) > 3000) {
        header('Location: patient-dashboard.php?error=invalid_schedule#book-appointment');
        exit();
    }

    try {
        $doctorStmt = $conn->prepare("SELECT id FROM doctors WHERE id = :doctor_id AND verification_status = 'approved'");
        $doctorStmt->execute([':doctor_id' => $doctor_id]);
        if (!$doctorStmt->fetchColumn()) {
            header("Location: patient-dashboard.php?error=doctor_not_verified");
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, notes)
            VALUES (:patient_id, :doctor_id, :appointment_date, :appointment_time, :notes)
        ");
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':doctor_id' => $doctor_id,
            ':appointment_date' => $schedule->format('Y-m-d'),
            ':appointment_time' => $schedule->format('H:i:s'),
            ':notes' => $notes
        ]);

        header('Location: patient-dashboard.php?success=1#appointments');
        exit();
    } catch (PDOException $e) {
        error_log('MedConnect appointment insert failed: ' . $e->getMessage());
        header('Location: patient-dashboard.php?error=booking_failed#book-appointment');
        exit();
    }
?>
