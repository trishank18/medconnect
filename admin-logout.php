<?php
require_once __DIR__ . '/includes/session.php';
medconnect_clear_role_auth('admin');
session_regenerate_id(true);
header('Location: admin-login.html');
exit();
