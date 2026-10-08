<?php
session_start();
require_once __DIR__ . '/../backend/includes/db_connection.php';
require_once __DIR__ . '/../backend/includes/admin_auth.php';

$message = '';
$error = '';

try {
    $credentials = get_admin_credentials($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $currentUsername = trim($_POST['current_username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newUsername = trim($_POST['new_username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!hash_equals($credentials['username'], $currentUsername)
            || !password_verify($currentPassword, $credentials['password_hash'])) {
            $error = 'Current admin credentials are incorrect.';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $newUsername)) {
            $error = 'The new username must contain 3-50 letters, numbers, dots, dashes, or underscores.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'The new password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new passwords do not match.';
        } else {
            save_admin_credentials($conn, $newUsername, password_hash($newPassword, PASSWORD_DEFAULT));
            $message = 'Admin credentials updated. Use the new username and password to sign in.';
        }
    }
} catch (PDOException $exception) {
    $error = 'Unable to update admin credentials right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Admin Credentials - MedConnect</title>
  <link rel="stylesheet" href="../frontend/css/style.css" />
</head>
<body class="login-page admin-login-page">
  <div class="auth-layout">
    <aside class="auth-visual"><img src="https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=900&q=85" alt="Healthcare professional reviewing information on a tablet" /><span class="auth-mark" aria-hidden="true">&#9881;</span><p class="eyebrow">Operations workspace</p><h1>Protect your workspace.</h1></aside>
    <div class="login-container">
      <h2>Reset admin credentials</h2>
      <p class="form-note">Confirm the current credentials before choosing replacements.</p>
      <?php if ($message): ?><p class="form-note"><?= htmlspecialchars($message) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="form-note"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="POST">
        <input type="text" placeholder="Current username" name="current_username" required autocomplete="username" />
        <input type="password" placeholder="Current password" name="current_password" required autocomplete="current-password" />
        <input type="text" placeholder="New username" name="new_username" required autocomplete="username" />
        <input type="password" placeholder="New password (8+ characters)" name="new_password" required autocomplete="new-password" />
        <input type="password" placeholder="Confirm new password" name="confirm_password" required autocomplete="new-password" />
        <button type="submit" class="btn login-btn">Update credentials</button>
      </form>
      <p class="switch-link"><a href="admin-login.html">Back to admin login</a></p>
    </div>
  </div>
  <script src="../frontend/js/design-switch.js"></script>
</body>
</html>