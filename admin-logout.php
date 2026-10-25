<?php
require_once __DIR__ . '/includes/session.php';
session_unset();
session_regenerate_id(true);
header('Location: admin-login.html');
exit();
