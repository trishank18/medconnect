<?php
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$form = (string) ($_GET['form'] ?? '');
$allowedForms = ['doctor_login', 'patient_login', 'admin_login', 'doctor_register', 'patient_register'];
if (!in_array($form, $allowedForms, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid form.']);
    exit();
}

$sessionKey = 'csrf_' . $form;
$_SESSION[$sessionKey] ??= bin2hex(random_bytes(32));
echo json_encode(['token' => $_SESSION[$sessionKey]]);
