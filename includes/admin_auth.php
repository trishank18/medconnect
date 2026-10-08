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

    $credentials = [
        'username' => 'Sunny',
        'password_hash' => '$2y$10$HE7C1qay5QOO64bfjHUWj.ekj7BsicZjntkcDBrcamee3iFtBxcmy'
    ];
    save_admin_credentials($conn, $credentials['username'], $credentials['password_hash']);
    return $credentials;
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