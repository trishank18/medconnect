<?php
require_once dirname(__DIR__, 2) . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/db_connection.php';

if (!medconnect_role_authenticated('admin')) {
    header('Location: admin-login.html');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-dashboard.php');
    exit();
}

if (!isset($_SESSION['admin_csrf']) || !hash_equals($_SESSION['admin_csrf'], (string) ($_POST['admin_csrf'] ?? ''))) {
    header('Location: admin-dashboard.php?error=invalid_request');
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
