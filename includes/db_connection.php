<?php
/**
 * Backwards-compatible connection include.
 *
 * New code should load config/database.php through the application bootstrap.
 * Existing routes retain their historical include path and `$conn` PDO API.
 */
require_once __DIR__ . '/../config/database.php';
