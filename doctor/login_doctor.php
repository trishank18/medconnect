<?php
require_once __DIR__ . '/../includes/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf_doctor_login'])
    || !hash_equals($_SESSION['csrf_doctor_login'], (string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ../doctor-login.html?error=invalid_request');
    exit();
}
require_once __DIR__ . '/../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        header('Location: ../doctor-login.html?error=invalid_credentials');
        exit();
    }

    try {
        $stmt = $conn->prepare('SELECT id, fullname, username, password FROM doctors WHERE username = :username LIMIT 1');
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($doctor = $stmt->fetch(PDO::FETCH_ASSOC)) {
            
            if (password_verify($password, $doctor['password'])) {
                medconnect_login_role('doctor', [
                    'id' => (int) $doctor['id'],
                    'name' => $doctor['fullname'],
                    'username' => $doctor['username'],
                ]);
                header("Location: ../doctor-dashboard.php");
                exit();
            } else {
                header("Location: ../doctor-login.html?error=invalid_credentials");
                exit();
            }
        } else {
            header('Location: ../doctor-login.html?error=invalid_credentials');
            exit();
        }
    } catch(PDOException $e) {
        header("Location: ../doctor-login.html?error=database_error");
        exit();
    }
} else {
    header("Location: ../doctor-login.html");
    exit();
}
?>
