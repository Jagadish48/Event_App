<?php
/**
 * Database Connection Configuration
 * 
 * This file establishes a connection to the MySQL database using MySQLi (object-oriented)
 * Database: even_t
 * Host: 127.0.0.1 (MySQL Workbench/MySQL Server)
 * Port: 3306
 * 
 * @author Event Management System
 * @version 1.0
 */

// Database connection parameters
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', ''); // Leave empty if no password, or update with actual password
define('DB_NAME', 'event_app');
define('DB_PORT', 3306);

/**
 * Create database connection
 * Uses MySQLi object-oriented approach with proper error handling
 * 
 * @return mysqli|false Returns mysqli connection object on success, false on failure
 */
function getDatabaseConnection() {
    try {
        // Create MySQLi connection with specified parameters
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        // Check if connection was successful
        if ($connection->connect_error) {
            $errorNumber = $connection->connect_errno;
            if ($errorNumber === 1049) {
                $fallbackDb = 'even_t';
                $fallbackConnection = new mysqli(DB_HOST, DB_USER, DB_PASS, $fallbackDb, DB_PORT);
                if (!$fallbackConnection->connect_error) {
                    $fallbackConnection->set_charset("utf8mb4");
                    return $fallbackConnection;
                }
            }

            // Log error for debugging (in production, use proper logging)
            error_log("Database connection failed: " . $connection->connect_error);
            
            // Return false to indicate connection failure
            return false;
        }
        
        // Set character set to UTF-8 for proper encoding
        $connection->set_charset("utf8mb4");
        
        // Return the successful connection object
        return $connection;
        
    } catch (Exception $e) {
        // Handle any exceptions that might occur during connection
        error_log("Database connection exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Close database connection safely
 * 
 * @param mysqli $connection The database connection to close
 */
function closeDatabaseConnection($connection) {
    if ($connection instanceof mysqli && $connection->ping()) {
        $connection->close();
    }
}

/**
 * Execute a prepared statement query safely
 * 
 * @param mysqli $connection Database connection
 * @param string $query SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @param string $types Parameter types (i=integer, s=string, d=double, b=blob)
 * @return mysqli_stmt|false Returns statement object on success, false on failure
 */
function executeQuery($connection, $query, $params = [], $types = '') {
    try {
        // Prepare the statement
        $stmt = $connection->prepare($query);
        if (!$stmt) {
            error_log("Query preparation failed: " . $connection->error);
            return false;
        }
        
        // Bind parameters if provided
        if (!empty($params)) {
            // Build type string if not provided
            if (empty($types)) {
                $types = str_repeat('s', count($params)); // Default to string types
            }
            
            // Bind parameters
            $bindParams = array_merge([$stmt, $types], $params);
            call_user_func_array('mysqli_stmt_bind_param', $bindParams);
        }
        
        // Execute the statement
        if (!$stmt->execute()) {
            error_log("Query execution failed: " . $stmt->error);
            return false;
        }
        
        return $stmt;
        
    } catch (Exception $e) {
        error_log("Query execution exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Get multiple rows from a query result
 * 
 * @param mysqli_stmt $stmt Prepared statement result
 * @return array Array of associative arrays
 */
function fetchAllResults($stmt) {
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get single row from a query result
 * 
 * @param mysqli_stmt $stmt Prepared statement result
 * @return array|null Associative array or null if no results
 */
function fetchSingleResult($stmt) {
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Sanitize input data to prevent XSS
 * 
 * @param string $data Input data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Redirect to a specific URL
 * 
 * @param string $url Target URL for redirect
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

// Establish database connection and make it available globally
$database_connection = getDatabaseConnection();

// Check if connection was successful
if (!$database_connection) {
    // In production, show a generic error message
    // In development, you might want to show detailed error
    die("Database connection failed. Please check your configuration and try again.");
}

// Set timezone for consistent date/time handling
date_default_timezone_set('Asia/Kolkata');

?>
