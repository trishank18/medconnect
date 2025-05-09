<?php
session_start();
if (!isset($_SESSION['doctor_id'])) {
    header("Location: doctor-login.html");
    exit();
}

require_once 'includes/db_connection.php';

// Fetch current doctor data
try {
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['doctor_id']);
    $stmt->execute();
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        session_destroy();
        header("Location: doctor-login.html?error=doctor_not_found");
        exit();
    }
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $department = $_POST['department'];
    $username = $_POST['username'];
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    try {
        if ($password) {
            $stmt = $conn->prepare("UPDATE doctors SET fullname = :fullname, email = :email, phone = :phone, 
                                  department = :department, username = :username, password = :password 
                                  WHERE id = :id");
            $stmt->bindParam(':password', $password);
        } else {
            $stmt = $conn->prepare("UPDATE doctors SET fullname = :fullname, email = :email, phone = :phone, 
                                  department = :department, username = :username 
                                  WHERE id = :id");
        }
        
        $stmt->bindParam(':fullname', $fullname);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':department', $department);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':id', $_SESSION['doctor_id']);
        $stmt->execute();
        
        header("Location: doctor-dashboard.php?update=success");
        exit();
    } catch(PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Update Doctor Profile - MedConnect</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body class="login-page">

  <div class="login-container">
    <h2>🛠️ Update Doctor Profile</h2>
    <?php if (!empty($error)): ?>
      <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="text" name="fullname" placeholder="Full Name" value="<?php echo htmlspecialchars($doctor['fullname']); ?>" required />
      <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($doctor['email']); ?>" required />
      <input type="text" name="phone" placeholder="Phone Number" value="<?php echo htmlspecialchars($doctor['phone']); ?>" required />
      <input type="text" name="department" placeholder="Department" value="<?php echo htmlspecialchars($doctor['department']); ?>" required />
      <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($doctor['username']); ?>" required />
      <input type="password" name="password" placeholder="New Password (leave blank to keep current)" />
      <button type="submit" class="btn login-btn">Save Changes</button>
      <p class="switch-link"><a href="doctor-dashboard.php">← Back to Dashboard</a></p>
    </form>
  </div>

</body>
</html>