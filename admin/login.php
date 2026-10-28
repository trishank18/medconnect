<?php
require_once __DIR__ . '/../includes/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf_admin_login'])
    || !hash_equals($_SESSION['csrf_admin_login'], (string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: admin-login.html?error=invalid_request');
    exit();
}
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-login.html');
    exit();
}

$credentials = get_admin_credentials($conn);
$configuredUsername = $credentials['username'];
$configuredPasswordHash = $credentials['password_hash'];
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($configuredUsername !== '' && $configuredPasswordHash !== ''
    && hash_equals($configuredUsername, $username)
    && password_verify($password, $configuredPasswordHash)) {
    medconnect_login_role('admin', [
        'id' => (int) ($credentials['id'] ?? 1),
        'username' => $username,
        'name' => $username,
    ]);
    header('Location: admin-dashboard.php');
    exit();
}

header('Location: admin-login.html?error=invalid_credentials');
exit();
