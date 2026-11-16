<?php
require_once __DIR__ . '/../includes/functions.php';

// Logging out is a state change, so it only works via POST with a valid CSRF token.
if (!is_post()) {
    redirect(empty($_SESSION['uid']) ? 'auth/login.php' : 'pages/dashboard.php');
}
csrf_verify();
logout_user();
session_start();
flash('success', 'You have been logged out.');
redirect('auth/login.php');
