<?php
// Database Configuration
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? 'event_final';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';

// Create connection
$conn = null;
$dbCandidates = [$dbname];

foreach ($dbCandidates as $candidate) {
    if (!$candidate) {
        continue;
    }
    $conn = mysqli_connect($host, $username, $password, $candidate);
    if ($conn) {
        $dbname = $candidate;
        break;
    }
}

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
if (!function_exists('clean_input')) {
    function clean_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

// Authentication functions
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    $role = $_SESSION['role'] ?? '';
    return strtolower(trim((string) $role)) === 'admin';
}

function is_employee() {
    $role = $_SESSION['role'] ?? '';
    return strtolower(trim((string) $role)) === 'employee';
}

function require_admin() {
    if (!is_logged_in() || !is_admin()) {
        redirect('../index.php');
    }
}

function require_employee() {
    if (!is_logged_in() || !is_employee()) {
        redirect('../index.php');
    }
}

// Get current employee ID
function get_employee_id() {
    if (is_employee()) {
        global $conn;
        $user_id = $_SESSION['user_id'];
        $sql = "SELECT id FROM employees WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $employee = mysqli_fetch_assoc($result);
        return $employee ? $employee['id'] : null;
    }
    return null;
}

// Format currency
function format_currency($amount) {
    return 'Rs. ' . number_format($amount, 2);
}

// Format date
function format_date($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

if (!function_exists('ensure_app_settings_schema')) {
    function ensure_app_settings_schema() {
        global $conn;
        if (!$conn) return;
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(80) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('get_app_setting')) {
    function get_app_setting($key, $default = '') {
        global $conn;
        $key = trim((string) $key);
        if ($key === '') return $default;
        ensure_app_settings_schema();
        $sql = "SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return $default;
        mysqli_stmt_bind_param($stmt, "s", $key);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        $val = (string) ($row['value'] ?? '');
        return $val !== '' ? $val : $default;
    }
}

if (!function_exists('resolve_app_asset_url')) {
    function resolve_app_asset_url($relPath) {
        $p = trim((string) $relPath);
        if ($p === '') return '';
        $p = str_replace('\\', '/', $p);
        $p = ltrim($p, '/');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $projectFolder = basename(dirname(__DIR__));
        return $scheme . '://' . $hostName . '/' . $projectFolder . '/' . $p;
    }
}

if (!function_exists('get_app_name')) {
    function get_app_name() {
        $name = trim((string) get_app_setting('app_name', 'NETWORK EVENTS'));
        return $name !== '' ? $name : 'NETWORK EVENTS';
    }
}

// Get current month
function get_current_month() {
    return date('Y-m');
}

?>
