<?php
require_once __DIR__ . '/includes/session.php';
$role = $_GET['role'] ?? '';
$destination = $role === 'patient'
    ? 'patient-login.html'
    : ($role === 'doctor' ? 'doctor-login.html' : 'choose-role.html');

// Always end every role session so an older mixed-role session cannot survive sign-out.
session_unset();
session_regenerate_id(true);
header('Location: ' . $destination);
exit();
?>
