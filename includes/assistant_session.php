<?php

/** Return a per-account assistant conversation ID, separated by role. */
function medconnect_assistant_session_id(string $role): string
{
    if (!in_array($role, ['patient', 'doctor'], true)) {
        throw new InvalidArgumentException('Unsupported assistant role.');
    }

    $userId = filter_var($_SESSION[$role . '_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        throw new RuntimeException('An authenticated account is required.');
    }

    $key = $role . ':' . $userId;
    if (!isset($_SESSION['assistant_conversation_ids']) || !is_array($_SESSION['assistant_conversation_ids'])) {
        $_SESSION['assistant_conversation_ids'] = [];
    }
    $current = $_SESSION['assistant_conversation_ids'][$key] ?? null;
    if (!is_string($current) || !preg_match('/^[a-f0-9]{64}$/', $current)) {
        $current = bin2hex(random_bytes(32));
        $_SESSION['assistant_conversation_ids'][$key] = $current;
    }

    return $current;
}
