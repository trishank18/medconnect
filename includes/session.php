<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('MEDCONNECTSESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// These sessions are used for health and account data; prevent shared/browser caches from retaining pages.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
