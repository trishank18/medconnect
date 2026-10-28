<?php

if (!defined('MEDCONNECT_ROLE_IDLE_TIMEOUT_SECONDS')) {
    define('MEDCONNECT_ROLE_IDLE_TIMEOUT_SECONDS', 90 * 60);
}

/** Role-scoped authentication helpers. The PHP session itself is shared, but identities are not. */
function medconnect_valid_role(string $role): bool
{
    return in_array($role, ['doctor', 'patient', 'admin'], true);
}

/** Migrate legacy flat identity keys once, preserving existing signed-in sessions. */
function medconnect_migrate_legacy_auth_session(): void
{
    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        $_SESSION['auth'] = [];
    }

    foreach (['doctor', 'patient'] as $role) {
        $id = filter_var($_SESSION[$role . '_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id && $id > 0 && !isset($_SESSION['auth'][$role])) {
            $_SESSION['auth'][$role] = [
                'id' => $id,
                'name' => (string) ($_SESSION[$role . '_name'] ?? ''),
                'username' => '',
                'role' => $role,
                'authenticated_at' => time(),
                'last_activity_at' => time(),
            ];
        }
        unset($_SESSION[$role . '_id'], $_SESSION[$role . '_name']);
    }

    if (!empty($_SESSION['admin_authenticated']) && !isset($_SESSION['auth']['admin'])) {
        $_SESSION['auth']['admin'] = [
            'id' => null,
            'name' => (string) ($_SESSION['admin_username'] ?? 'Administrator'),
            'username' => (string) ($_SESSION['admin_username'] ?? ''),
            'role' => 'admin',
            'authenticated_at' => time(),
            'authenticated' => true,
        ];
    }
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_username']);
}

function medconnect_auth_user(string $role): ?array
{
    if (!medconnect_valid_role($role)) {
        return null;
    }
    $user = $_SESSION['auth'][$role] ?? null;
    if (!is_array($user) || ($user['role'] ?? null) !== $role) {
        return null;
    }
    if ($role === 'admin') {
        return !empty($user['authenticated']) ? $user : null;
    }
    $id = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
    return $id && $id > 0 ? $user : null;
}

function medconnect_role_authenticated(string $role): bool
{
    $user = medconnect_auth_user($role);
    if ($user === null) {
        return false;
    }

    // Doctor and patient activity is tracked independently. Requests in one
    // workspace must not keep another idle role authenticated.
    if ($role === 'doctor' || $role === 'patient') {
        $lastActivity = filter_var(
            $user['last_activity_at'] ?? $user['authenticated_at'] ?? null,
            FILTER_VALIDATE_INT
        );
        if (!$lastActivity || time() - $lastActivity >= MEDCONNECT_ROLE_IDLE_TIMEOUT_SECONDS) {
            medconnect_clear_role_auth($role);
            return false;
        }
        $_SESSION['auth'][$role]['last_activity_at'] = time();
    }

    return true;
}

function medconnect_role_user_id(string $role): ?int
{
    $user = medconnect_auth_user($role);
    if (!$user || $role === 'admin') {
        return null;
    }
    return (int) $user['id'];
}

function medconnect_login_role(string $role, array $identity): void
{
    if (!medconnect_valid_role($role)) {
        throw new InvalidArgumentException('Unsupported authentication role.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be active before authentication.');
    }

    // Rotate the browser session identifier without clearing any other role's auth state.
    session_regenerate_id(true);
    $_SESSION['auth'] ??= [];
    unset($_SESSION[$role . '_csrf']);
    $user = [
        'role' => $role,
        'name' => trim((string) ($identity['name'] ?? $identity['username'] ?? '')),
        'username' => trim((string) ($identity['username'] ?? '')),
        'authenticated_at' => time(),
        'last_activity_at' => time(),
    ];
    if ($role === 'admin') {
        $adminId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT);
        $user['id'] = $adminId && $adminId > 0 ? (int) $adminId : null;
        $user['authenticated'] = true;
    } else {
        $id = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new InvalidArgumentException('A valid account ID is required.');
        }
        $user['id'] = (int) $id;
        $user['authenticated'] = true;
    }
    $_SESSION['auth'][$role] = $user;
}

function medconnect_clear_role_auth(string $role): void
{
    if (!medconnect_valid_role($role)) {
        return;
    }
    unset($_SESSION['auth'][$role]);
    // Remove only that role's legacy role-specific CSRF key; shared session and other roles remain.
    unset($_SESSION[$role . '_csrf']);
    if (isset($_SESSION['assistant_conversation_ids']) && is_array($_SESSION['assistant_conversation_ids'])) {
        foreach (array_keys($_SESSION['assistant_conversation_ids']) as $key) {
            if (str_starts_with((string) $key, $role . ':')) {
                unset($_SESSION['assistant_conversation_ids'][$key]);
            }
        }
    }
}

function medconnect_require_role(string $role, string $loginUrl): void
{
    if (!medconnect_role_authenticated($role)) {
        header('Location: ' . $loginUrl);
        exit();
    }
}
