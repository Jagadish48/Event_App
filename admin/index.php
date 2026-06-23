<?php
/**
 * Admin Index Page - Fallback Redirect
 * Redirects to admin dashboard
 */

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is admin
if (isLoggedIn() && isAdmin()) {
    // Redirect to admin dashboard
    redirect(SITE_URL . 'admin/dashboard.php');
} else {
    // Redirect to main login page
    redirect(SITE_URL . 'index.php');
}
?>
