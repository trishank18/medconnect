<?php
require_once __DIR__ . '/env.php';
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
    $conn = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4;connect_timeout=$connectTimeout", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    error_log('MedConnect database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The database is temporarily unavailable. Please try again later.');
}
?>
