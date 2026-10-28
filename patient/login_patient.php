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
        $stmt = $conn->prepare('SELECT id, fullname, username, password FROM patients WHERE username = :username LIMIT 1');
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($patient = $stmt->fetch(PDO::FETCH_ASSOC)) {
            
            if (password_verify($password, $patient['password'])) {
                medconnect_login_role('patient', [
                    'id' => (int) $patient['id'],
                    'name' => $patient['fullname'],
                    'username' => $patient['username'],
                ]);
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
