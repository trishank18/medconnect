<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin-login.html');
    exit();
}

$configuredUsername = getenv('ADMIN_USERNAME') ?: '';
$configuredPasswordHash = getenv('ADMIN_PASSWORD_HASH') ?: '';
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