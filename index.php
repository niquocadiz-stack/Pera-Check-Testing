<?php
require_once __DIR__ . '/includes/functions.php';
redirect(empty($_SESSION['uid']) ? 'auth/login.php' : 'pages/dashboard.php');
