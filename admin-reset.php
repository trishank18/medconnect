<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/admin_auth.php';

$_SESSION['admin_reset_csrf'] ??= bin2hex(random_bytes(32));
$message = '';
$error = '';

try {
    $credentials = get_admin_credentials($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['admin_reset_csrf'], (string) ($_POST['admin_reset_csrf'] ?? ''))) {
            http_response_code(403);
            exit('This reset form expired. Reload the page and try again.');
        }
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
            unset($_SESSION['admin_reset_csrf']);
            session_regenerate_id(true);
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
  <link rel="stylesheet" href="css/style.css?v=20261025-2" />
  <link rel="stylesheet" href="css/theme.css" />
  <script src="js/theme-init.js"></script>
  <link rel="stylesheet" href="css/app-ui.css?v=20261025-8" />
  <link rel="stylesheet" href="css/admin-auth.css?v=20261027-1" />
</head>
<body class="login-page admin-login-page">
  <div class="auth-layout">
    <aside class="auth-visual"><img src="https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=900&q=85" alt="Healthcare professional reviewing information on a tablet" /><span class="auth-mark" aria-hidden="true">&#9881;</span><p class="eyebrow">Administrator workspace</p><h1>Protect your workspace.</h1><p class="admin-auth-summary">Update access credentials after verifying the current administrator account.</p></aside>
    <div class="login-container">
      <p class="admin-auth-kicker">Account security</p>
      <h2>Reset credentials</h2>
      <p class="admin-auth-intro">Confirm your current credentials before choosing replacements.</p>
      <?php if ($message): ?><p class="admin-auth-feedback is-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      <?php if ($error): ?><p class="admin-auth-feedback is-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      <form class="admin-auth-form" method="POST">
        <input type="hidden" name="admin_reset_csrf" value="<?= htmlspecialchars($_SESSION['admin_reset_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
        <div class="admin-auth-field"><label for="current-username">Current username</label><input id="current-username" type="text" name="current_username" required autocomplete="username" /></div>
        <div class="admin-auth-field"><label for="current-password">Current password</label><div class="admin-password-control"><input id="current-password" data-password-name="current password" type="password" name="current_password" required autocomplete="current-password" /><button class="admin-password-toggle" type="button" data-password-toggle aria-controls="current-password" aria-label="Show current password" aria-pressed="false">Show</button></div></div>
        <div class="admin-auth-field"><label for="new-username">New username</label><input id="new-username" type="text" name="new_username" required autocomplete="username" /></div>
        <div class="admin-auth-field"><label for="new-password">New password</label><div class="admin-password-control"><input id="new-password" data-password-name="new password" type="password" name="new_password" required autocomplete="new-password" minlength="8" /><button class="admin-password-toggle" type="button" data-password-toggle aria-controls="new-password" aria-label="Show new password" aria-pressed="false">Show</button></div></div>
        <div class="admin-auth-field"><label for="confirm-password">Confirm new password</label><div class="admin-password-control"><input id="confirm-password" data-password-name="confirmation password" type="password" name="confirm_password" required autocomplete="new-password" minlength="8" /><button class="admin-password-toggle" type="button" data-password-toggle aria-controls="confirm-password" aria-label="Show confirmation password" aria-pressed="false">Show</button></div></div>
        <button type="submit" class="btn login-btn">Update credentials</button>
      </form>
      <nav class="admin-auth-links" aria-label="Administrator account links"><a href="admin-login.html">Back to administrator sign in</a></nav>
    </div>
  </div>
  <script src="js/admin-auth.js" defer></script>
  <script src="js/design-switch.js?v=2"></script>
</body>
</html>
