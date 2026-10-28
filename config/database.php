<?php
declare(strict_types=1);

/**
 * The single MedConnect PDO connection.
 *
 * Existing PHP pages receive the connection as `$conn` (PDO) for backwards
 * compatibility. Load this file with require_once through the application
 * bootstrap or the legacy includes/db_connection.php shim.
 */
require_once __DIR__ . '/../includes/env.php';
medconnect_load_env_file();

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'medconnect';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$connectTimeout = filter_var(getenv('DB_CONNECT_TIMEOUT') ?: '5', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 30],
]);
$connectTimeout = $connectTimeout === false ? 5 : $connectTimeout;

try {
    $conn = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4;connect_timeout={$connectTimeout}",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $exception) {
    // Keep connection details out of the HTTP response and avoid logging DSNs.
    error_log('MedConnect database connection failed (SQLSTATE ' . (string) $exception->getCode() . ').');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit('The database is temporarily unavailable. Please try again later.');
}
