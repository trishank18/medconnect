<?php
require_once __DIR__ . '/includes/session.php';
$role = $_GET['role'] ?? '';
if (!in_array($role, ['patient', 'doctor'], true)) {
    header('Location: choose-role.html');
    exit();
}
medconnect_clear_role_auth($role);
session_regenerate_id(true);
$destination = $role === 'patient' ? 'patient-login.html' : 'doctor-login.html';
header('Location: ' . $destination);
exit();
?>
