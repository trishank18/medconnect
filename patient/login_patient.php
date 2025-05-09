<?php
session_start();
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $patient['password'])) {
                $_SESSION['patient_id'] = $patient['id'];
                $_SESSION['patient_name'] = $patient['fullname'];
                header("Location: ../patient-dashboard.php");
                exit();
            } else {
                header("Location: ../patient-login.html?error=invalid_credentials");
                exit();
            }
        } else {
            header("Location: ../patient-login.html?error=user_not_found");
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