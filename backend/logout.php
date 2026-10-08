<?php
session_start();
session_unset();
session_destroy();

// Redirect based on referrer or to home page
if (isset($_SERVER['HTTP_REFERER'])) {
    if (strpos($_SERVER['HTTP_REFERER'], 'doctor') !== false) {
        header("Location: ../frontend/choose-role.html");
    } elseif (strpos($_SERVER['HTTP_REFERER'], 'patient') !== false) {
        header("Location: ../frontend/choose-role.html");
    } else {
        header("Location: ../frontend/choose-role.html");
    }
} else {
    header("Location: ../frontend/choose-role.html");
}
exit();
?>