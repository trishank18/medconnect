<?php
session_start();
if (!isset($_SESSION['patient_id'])) {
    header("Location: patient-login.html");
    exit();
}

require_once 'includes/db_connection.php';

// Fetch current patient data
try {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['patient_id']);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        session_destroy();
        header("Location: patient-login.html?error=patient_not_found");
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
    $age = $_POST['age'];
    $username = $_POST['username'];
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    try {
        if ($password) {
            $stmt = $conn->prepare("UPDATE patients SET fullname = :fullname, email = :email, phone = :phone, 
                                  age = :age, username = :username, password = :password 
                                  WHERE id = :id");
            $stmt->bindParam(':password', $password);
        } else {
            $stmt = $conn->prepare("UPDATE patients SET fullname = :fullname, email = :email, phone = :phone, 
                                  age = :age, username = :username 
                                  WHERE id = :id");
        }
        
        $stmt->bindParam(':fullname', $fullname);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':age', $age);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':id', $_SESSION['patient_id']);
        $stmt->execute();
        
        header("Location: patient-dashboard.php?update=success");
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
  <title>Update Patient Profile - MedConnect</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body class="login-page">

  <div class="login-container">
    <h2>🛠️ Update Patient Profile</h2>
    <?php if (!empty($error)): ?>
      <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="text" name="fullname" placeholder="Full Name" value="<?php echo htmlspecialchars($patient['fullname']); ?>" required />
      <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($patient['email']); ?>" required />
      <input type="text" name="phone" placeholder="Phone Number" value="<?php echo htmlspecialchars($patient['phone']); ?>" required />
      <input type="number" name="age" placeholder="Age" value="<?php echo htmlspecialchars($patient['age']); ?>" required />
      <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($patient['username']); ?>" required />
      <input type="password" name="password" placeholder="New Password (leave blank to keep current)" />
      <button type="submit" class="btn login-btn">Save Changes</button>
      <p class="switch-link"><a href="patient-dashboard.php">← Back to Dashboard</a></p>
    </form>
  </div>

</body>
</html>