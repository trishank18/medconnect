<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['patient_id'])) {
    header("Location: patient-login.html");
    exit();
}
$_SESSION['patient_csrf'] ??= bin2hex(random_bytes(32));

require_once __DIR__ . '/includes/db_connection.php';

// Fetch current patient data
try {
    $stmt = $conn->prepare("SELECT id, fullname, email, phone, age, username, doctor_id, created_at FROM patients WHERE id = :id");
    $stmt->bindParam(':id', $_SESSION['patient_id']);
    $stmt->execute();
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        unset($_SESSION['patient_id'], $_SESSION['patient_name'], $_SESSION['patient_csrf']);
        header("Location: patient-login.html?error=patient_not_found");
        exit();
    }
} catch(PDOException $e) {
    error_log('MedConnect patient profile load failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The profile is temporarily unavailable. Please try again later.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!hash_equals($_SESSION['patient_csrf'], (string) ($_POST['patient_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $age = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT);
    $username = trim((string) ($_POST['username'] ?? ''));
    $plainPassword = (string) ($_POST['password'] ?? '');

    if ($fullname === '' || strlen($fullname) > 100 || preg_match('/[\r\n]/', $fullname)
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
        || !preg_match('/^[+0-9(). -]{7,20}$/', $phone)
        || $age === false || $age < 1 || $age > 150
        || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
        || ($plainPassword !== '' && (strlen($plainPassword) < 8 || strlen($plainPassword) > 200))) {
        $error = 'Check the profile details and password length, then try again.';
    } else {
      $password = $plainPassword !== '' ? password_hash($plainPassword, PASSWORD_DEFAULT) : null;

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
        error_log('MedConnect patient profile update failed: ' . $e->getMessage());
        $error = 'Profile update failed. Check for duplicate email or username and try again.';
      }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Update Patient Profile - MedConnect</title>
  <link rel="stylesheet" href="css/style.css?v=20261025-2" />
  <link rel="stylesheet" href="css/theme.css" />
  <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
</head>
<body class="login-page">

  <div class="login-container">
    <h2>🛠️ Update Patient Profile</h2>
    <?php if (!empty($error)): ?>
      <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="patient_csrf" value="<?= htmlspecialchars($_SESSION['patient_csrf'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="text" name="fullname" placeholder="Full Name" maxlength="100" value="<?php echo htmlspecialchars($patient['fullname']); ?>" required />
      <input type="email" name="email" placeholder="Email" maxlength="100" value="<?php echo htmlspecialchars($patient['email']); ?>" required />
      <input type="text" name="phone" placeholder="Phone Number" maxlength="20" value="<?php echo htmlspecialchars($patient['phone']); ?>" required />
      <input type="number" name="age" placeholder="Age" value="<?php echo htmlspecialchars($patient['age']); ?>" required />
      <input type="text" name="username" placeholder="Username" maxlength="50" value="<?php echo htmlspecialchars($patient['username']); ?>" required />
      <input type="password" name="password" placeholder="New Password (leave blank to keep current)" />
      <button type="submit" class="btn login-btn">Save Changes</button>
      <p class="switch-link"><a href="patient-dashboard.php">← Back to Dashboard</a></p>
    </form>
  </div>

  <script src="js/design-switch.js?v=2"></script>
</body>
</html>
