<?php
session_start();
require_once __DIR__ . '/includes/db_connection.php';

$message = '';
$messageType = 'info';
$role = $_POST['role'] ?? $_GET['role'] ?? 'patient';
$resetRequest = $_SESSION['password_reset_request'] ?? null;
$step = $resetRequest ? 'verify' : 'request';

if (!in_array($role, ['doctor', 'patient'], true)) {
    $role = 'patient';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    $conn->exec("CREATE TABLE IF NOT EXISTS password_reset_otps (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      role VARCHAR(20) NOT NULL,
      account_id INT NOT NULL,
      otp_hash VARCHAR(255) NOT NULL,
      expires_at DATETIME NOT NULL,
      attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY account_lookup (role, account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'request_otp') {
      $username = trim($_POST['username'] ?? '');
      $email = trim($_POST['email'] ?? '');

      if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid username and registered email address.';
        $messageType = 'danger';
      } else {
        $table = $role === 'doctor' ? 'doctors' : 'patients';
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE username = :username AND email = :email LIMIT 1");
        $stmt->execute([':username' => $username, ':email' => $email]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
          $otp = (string) random_int(100000, 999999);
          $otpHash = password_hash($otp, PASSWORD_DEFAULT);
          $expiresAt = date('Y-m-d H:i:s', time() + 600);

          $conn->prepare("DELETE FROM password_reset_otps WHERE role = :role AND account_id = :account_id")
            ->execute([':role' => $role, ':account_id' => $account['id']]);
          $otpStmt = $conn->prepare("INSERT INTO password_reset_otps (role, account_id, otp_hash, expires_at) VALUES (:role, :account_id, :otp_hash, :expires_at)");
          $otpStmt->execute([
            ':role' => $role,
            ':account_id' => $account['id'],
            ':otp_hash' => $otpHash,
            ':expires_at' => $expiresAt
          ]);

          $subject = 'MedConnect password reset code';
          $body = "Your MedConnect password reset code is {$otp}. It expires in 10 minutes.";
          $from = getenv('MAIL_FROM') ?: 'no-reply@medconnect.local';
          $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
          mail($email, $subject, $body, $headers);

          $_SESSION['password_reset_request'] = [
            'role' => $role,
            'account_id' => (int) $account['id'],
            'email' => $email
          ];
          $resetRequest = $_SESSION['password_reset_request'];
          $step = 'verify';
        }

        $message = 'If the account details match, a verification code has been sent to the registered email.';
        $messageType = 'success';
      }
    } elseif ($action === 'verify_otp' && $resetRequest) {
      $otp = trim($_POST['otp'] ?? '');
      $newPassword = $_POST['new_password'] ?? '';
      $confirmPassword = $_POST['confirm_password'] ?? '';
      $otpStmt = $conn->prepare("SELECT * FROM password_reset_otps WHERE role = :role AND account_id = :account_id ORDER BY id DESC LIMIT 1");
      $otpStmt->execute([':role' => $resetRequest['role'], ':account_id' => $resetRequest['account_id']]);
      $otpRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

      if (!$otpRecord || (int) $otpRecord['attempts'] >= 5 || strtotime($otpRecord['expires_at']) < time() || !password_verify($otp, $otpRecord['otp_hash'])) {
        if ($otpRecord && (int) $otpRecord['attempts'] < 5) {
          $conn->prepare("UPDATE password_reset_otps SET attempts = attempts + 1 WHERE id = :id")
            ->execute([':id' => $otpRecord['id']]);
        }
        $message = 'The code is invalid, expired, or has too many attempts.';
        $messageType = 'danger';
      } elseif (strlen($newPassword) < 8) {
        $message = 'The new password must be at least 8 characters long.';
        $messageType = 'danger';
      } elseif ($newPassword !== $confirmPassword) {
        $message = 'The new passwords do not match.';
        $messageType = 'danger';
      } else {
        $table = $resetRequest['role'] === 'doctor' ? 'doctors' : 'patients';
        $updateStmt = $conn->prepare("UPDATE {$table} SET password = :password WHERE id = :id");
        $updateStmt->execute([
          ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
          ':id' => $resetRequest['account_id']
        ]);
        $conn->prepare("DELETE FROM password_reset_otps WHERE id = :id")
          ->execute([':id' => $otpRecord['id']]);
        unset($_SESSION['password_reset_request']);
        $loginPage = $resetRequest['role'] === 'doctor' ? 'doctor-login.html' : 'patient-login.html';
        header("Location: {$loginPage}?reset=success");
        exit();
      }
    }
  } catch (Throwable $e) {
    $message = 'Unable to process the password reset right now. Please try again.';
    $messageType = 'danger';
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
    <h2><?= $step === 'verify' ? 'Verify Reset Code' : 'Reset Password' ?></h2>
    <p><?= $step === 'verify' ? 'Enter the code sent to your registered email address.' : 'Receive a one-time code using your registered account details.' ?></p>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="alert">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="forgot-password.php">
      <?php if ($step === 'request'): ?>
      <label for="role">Account type</label>
      <select id="role" name="role" required>
        <option value="patient" <?= $role === 'patient' ? 'selected' : '' ?>>Patient</option>
        <option value="doctor" <?= $role === 'doctor' ? 'selected' : '' ?>>Doctor</option>
      </select>

      <input type="text" name="username" placeholder="Username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      <input type="email" name="email" placeholder="Registered email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <input type="hidden" name="action" value="request_otp">
      <button type="submit" class="btn login-btn">Send Verification Code</button>
      <?php else: ?>
      <input type="hidden" name="action" value="verify_otp">
      <input type="text" name="otp" placeholder="6-digit verification code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
      <input type="password" name="new_password" placeholder="New password (8+ characters)" required minlength="8" autocomplete="new-password">
      <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
      <button type="submit" class="btn login-btn">Create New Password</button>
      <?php endif; ?>
      <p class="switch-link"><a href="patient-login.html">Patient login</a> | <a href="doctor-login.html">Doctor login</a></p>
    </form>
    </div>
  </div>

  <script src="js/design-switch.js"></script>
</body>
</html>
