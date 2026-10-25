<?php

function ensure_admin_credentials_table(PDO $conn): void
{
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS admin_credentials (
            id TINYINT UNSIGNED NOT NULL,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function get_admin_credentials(PDO $conn): array
{
    ensure_admin_credentials_table($conn);
    $stmt = $conn->query('SELECT username, password_hash FROM admin_credentials WHERE id = 1');
    $credentials = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($credentials) {
        return $credentials;
    }

    // First-run setup can bootstrap the row from server-only configuration.
    // Existing credentials remain authoritative and are never overwritten here.
    $username = trim((string) (getenv('ADMIN_USERNAME') ?: ''));
    $passwordHash = (string) (getenv('ADMIN_PASSWORD_HASH') ?: '');
    $hashInfo = password_get_info($passwordHash);
    if (preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)
        && strlen($passwordHash) <= 255
        && ($hashInfo['algoName'] ?? 'unknown') !== 'unknown') {
        save_admin_credentials($conn, $username, $passwordHash);
        return ['username' => $username, 'password_hash' => $passwordHash];
    }

    return ['username' => '', 'password_hash' => ''];
}

function save_admin_credentials(PDO $conn, string $username, string $passwordHash): void
{
    $stmt = $conn->prepare(
        'INSERT INTO admin_credentials (id, username, password_hash)
         VALUES (1, :username, :password_hash)
         ON DUPLICATE KEY UPDATE username = VALUES(username), password_hash = VALUES(password_hash)'
    );
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash
    ]);
}
