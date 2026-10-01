<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/admin_auth.php';

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
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $username;
    header('Location: admin-dashboard.php');
    exit();
}

header('Location: admin-login.html?error=invalid_credentials');
exit();