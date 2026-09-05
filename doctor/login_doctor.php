<?php
session_start();
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        $stmt = $conn->prepare("SELECT * FROM doctors WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $doctor['password'])) {
                $_SESSION['doctor_id'] = $doctor['id'];
                $_SESSION['doctor_name'] = $doctor['fullname'];
                header("Location: ../doctor-dashboard.php");
                exit();
            } else {
                header("Location: ../doctor-login.html?error=invalid_credentials");
                exit();
            }
        } else {
            header("Location: ../doctor-login.html?error=user_not_found");
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