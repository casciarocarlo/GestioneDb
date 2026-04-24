<?php
require_once 'auth.php';

// Logout user
logoutUser();

// Redirect to login page
header('Location: login.php?message=logged_out');
exit;
?>
