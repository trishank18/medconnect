<?php
require_once __DIR__ . '/includes/db_connection.php';

$message = '';
$messageType = 'info';
$role = $_POST['role'] ?? $_GET['role'] ?? 'patient';

if (!in_array($role, ['doctor', 'patient'], true)) {
    $role = 'patient';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid username and registered email address.';
        $messageType = 'danger';
    } elseif (strlen($newPassword) < 8) {
        $message = 'The new password must be at least 8 characters long.';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
        $messageType = 'danger';
    } else {
        $table = $role === 'doctor' ? 'doctors' : 'patients';
        $loginPage = $role === 'doctor' ? 'doctor-login.html' : 'patient-login.html';

        try {
            $stmt = $conn->prepare("SELECT id FROM {$table} WHERE username = :username AND email = :email LIMIT 1");
            $stmt->execute([':username' => $username, ':email' => $email]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($account) {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE {$table} SET password = :password WHERE id = :id");
                $updateStmt->execute([
                    ':password' => $passwordHash,
                    ':id' => $account['id']
                ]);

                header("Location: {$loginPage}?reset=success");
                exit();
            }

            $message = 'The username and email address do not match our records.';
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = 'Unable to reset the password right now. Please try again.';
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Reset your MedConnect password">
  <title>Reset Password - MedConnect</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/business.css">
</head>
<body class="login-page">
  <div class="auth-layout">
    <aside class="auth-visual"><span class="auth-mark" aria-hidden="true">&#10003;</span><p class="eyebrow">Account support</p><h1>Secure access to the care you count on.</h1><img src="https://images.unsplash.com/photo-1559757175-0eb30cd8c063?auto=format&fit=crop&w=900&q=85" alt="Person checking health information on a device"></aside>
    <div class="login-container">
    <h2>Create New Password</h2>
    <p>Verify your account with your username and registered email.</p>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="alert">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="forgot-password.php">
      <label for="role">Account type</label>
      <select id="role" name="role" required>
        <option value="patient" <?= $role === 'patient' ? 'selected' : '' ?>>Patient</option>
        <option value="doctor" <?= $role === 'doctor' ? 'selected' : '' ?>>Doctor</option>
      </select>

      <input type="text" name="username" placeholder="Username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      <input type="email" name="email" placeholder="Registered email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <input type="password" name="new_password" placeholder="New password (8+ characters)" required minlength="8" autocomplete="new-password">
      <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
      <button type="submit" class="btn login-btn">Create New Password</button>
      <p class="switch-link"><a href="patient-login.html">Patient login</a> | <a href="doctor-login.html">Doctor login</a></p>
    </form>
    </div>
  </div>

  <script src="js/design-switch.js"></script>
</body>
</html>
