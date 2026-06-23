<?php
require_once 'config/database.php';

// Destroy all session variables
session_unset();
session_destroy();

// Redirect to login page
redirect('login.php');
?>
