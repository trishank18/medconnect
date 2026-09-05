<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

if (!isset($_SESSION['patient_id'])) {
    header("Location: patient-login.html");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $patient_id = $_SESSION['patient_id'];
    $doctor_id = $_POST['doctor_id'];
    $date = $_POST['appointment_date'];
    $time = $_POST['appointment_time'];
    $notes = $_POST['notes'] ?? '';

    try {
        $stmt = $conn->prepare("
            INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, notes)
            VALUES (:patient_id, :doctor_id, :appointment_date, :appointment_time, :notes)
        ");
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':doctor_id' => $doctor_id,
            ':appointment_date' => $date,
            ':appointment_time' => $time,
            ':notes' => $notes
        ]);

        header("Location: patient-dashboard.php?success=1");
    } catch (PDOException $e) {
        die("Error booking appointment: " . $e->getMessage());
    }
}
?>
