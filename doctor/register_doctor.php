<?php
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $department = $_POST['department'];
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("INSERT INTO doctors (fullname, email, phone, department, username, password) 
                               VALUES (:fullname, :email, :phone, :department, :username, :password)");
        $stmt->bindParam(':fullname', $fullname);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':department', $department);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password);
        $stmt->execute();
        
        header("Location: ../doctor-login.html?registration=success");
        exit();
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            // Duplicate entry
            header("Location: ../doctor-register.html?error=duplicate_entry");
        } else {
            header("Location: ../doctor-register.html?error=database_error");
        }
        exit();
    }
} else {
    header("Location: ../doctor-register.html");
    exit();
}
?>