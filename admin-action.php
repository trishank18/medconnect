<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin-login.html');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-dashboard.php');
    exit();
}

$doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
$status = $_POST['status'] ?? '';

if (!$doctorId || !in_array($status, ['approved', 'rejected'], true)) {
    header('Location: admin-dashboard.php?error=invalid_action');
    exit();
}

$stmt = $conn->prepare('UPDATE doctors SET verification_status = :status WHERE id = :id');
$stmt->execute([':status' => $status, ':id' => $doctorId]);
header('Location: admin-dashboard.php?updated=' . rawurlencode($status));
exit();