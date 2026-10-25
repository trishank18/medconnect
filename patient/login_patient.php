<?php
require_once __DIR__ . '/../includes/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf_patient_login'])
    || !hash_equals($_SESSION['csrf_patient_login'], (string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ../patient-login.html?error=invalid_request');
    exit();
}
require_once __DIR__ . '/../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        header('Location: ../patient-login.html?error=invalid_credentials');
        exit();
    }

    try {
        $stmt = $conn->prepare('SELECT id, fullname, password FROM patients WHERE username = :username LIMIT 1');
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($patient = $stmt->fetch(PDO::FETCH_ASSOC)) {
            
            if (password_verify($password, $patient['password'])) {
                session_regenerate_id(true);
                unset(
                    $_SESSION['patient_id'], $_SESSION['patient_name'], $_SESSION['patient_csrf'],
                    $_SESSION['doctor_id'], $_SESSION['doctor_name'], $_SESSION['doctor_csrf'],
                    $_SESSION['admin_authenticated'], $_SESSION['admin_username'], $_SESSION['admin_csrf']
                );
                $_SESSION['patient_id'] = $patient['id'];
                $_SESSION['patient_name'] = $patient['fullname'];
                header("Location: ../patient-dashboard.php");
                exit();
            } else {
                header("Location: ../patient-login.html?error=invalid_credentials");
                exit();
            }
        } else {
            header('Location: ../patient-login.html?error=invalid_credentials');
            exit();
        }
    } catch(PDOException $e) {
        header("Location: ../patient-login.html?error=database_error");
        exit();
    }
} else {
    header("Location: ../patient-login.html");
    exit();
}
?>
