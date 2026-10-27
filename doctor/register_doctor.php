<?php
require_once __DIR__ . '/../includes/session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf_doctor_register'])
    || !hash_equals($_SESSION['csrf_doctor_register'], (string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ../doctor-register.html?error=invalid_request');
    exit();
}
    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $department = $_POST['department'] ?? '';
    $allowedDepartments = [
        'Cardiology', 'Dermatology', 'General Medicine', 'Gynecology',
        'Neurology', 'Ophthalmology', 'Orthopedics', 'Pediatrics',
        'Psychiatry', 'Pulmonology', 'Dentistry', 'ENT'
    ];

    if (!in_array($department, $allowedDepartments, true)) {
        header("Location: ../doctor-register.html?error=invalid_department");
        exit();
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $plainPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($fullname === '' || strlen($fullname) > 100 || preg_match('/[\r\n]/', $fullname)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 100 || !preg_match('/^[+0-9(). -]{7,20}$/', $phone)
        || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
        || strlen($plainPassword) < 8 || strlen($plainPassword) > 200
        || $plainPassword !== $confirmPassword) {
        header('Location: ../doctor-register.html?error=invalid_input');
        exit();
    }
    require_once __DIR__ . '/../includes/db_connection.php';
    $password = password_hash($plainPassword, PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("INSERT INTO doctors (fullname, email, phone, department, username, password, verification_status)
                       VALUES (:fullname, :email, :phone, :department, :username, :password, 'pending')");
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
?>
