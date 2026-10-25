<?php
require_once __DIR__ . '/../includes/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf_patient_register'])
    || !hash_equals($_SESSION['csrf_patient_register'], (string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ../patient-register.html?error=invalid_request');
    exit();
}
require_once __DIR__ . '/../includes/db_connection.php';

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $age = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT);
    $username = trim((string) ($_POST['username'] ?? ''));
    $plainPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($fullname === '' || strlen($fullname) > 100 || preg_match('/[\r\n]/', $fullname)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 100 || !preg_match('/^[+0-9(). -]{7,20}$/', $phone)
        || $age === false || $age < 1 || $age > 150
        || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
        || strlen($plainPassword) < 8 || strlen($plainPassword) > 200
        || $plainPassword !== $confirmPassword) {
        header('Location: ../patient-register.html?error=invalid_input');
        exit();
    }
    $password = password_hash($plainPassword, PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("INSERT INTO patients (fullname, email, phone, age, username, password) 
                               VALUES (:fullname, :email, :phone, :age, :username, :password)");
        $stmt->bindParam(':fullname', $fullname);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':age', $age);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password);
        $stmt->execute();
        
        header("Location: ../patient-login.html?registration=success");
        exit();
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            // Duplicate entry
            header("Location: ../patient-register.html?error=duplicate_entry");
        } else {
            header("Location: ../patient-register.html?error=database_error");
        }
        exit();
    }
?>
