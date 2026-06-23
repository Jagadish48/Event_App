<?php
/**
 * Database Setup Script for Event Management System
 * Creates database, tables, and inserts default users
 */

// Database configuration
$host = 'localhost';
$dbname = 'even_t';
$username = 'root';
$password = 'punam@2006';

try {
    // Connect to MySQL without database
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<!DOCTYPE html><html><head><title>Database Setup</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
    echo "</head><body><div class='container mt-5'>";
    echo "<h1>Event Management System - Database Setup</h1>";
    
    // Create database
    echo "<div class='card mb-3'><div class='card-header'><h3>Creating Database</h3></div>";
    echo "<div class='card-body'>";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    echo "<div class='alert alert-success'>✓ Database '$dbname' created successfully</div>";
    echo "</div></div>";
    
    // Select the database
    $pdo->exec("USE $dbname");
    
    // Create users table
    echo "<div class='card mb-3'><div class='card-header'><h3>Creating Users Table</h3></div>";
    echo "<div class='card-body'>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "<div class='alert alert-success'>✓ Users table created successfully</div>";
    echo "</div></div>";
    
    // Insert default users (plain text passwords)
    echo "<div class='card mb-3'><div class='card-header'><h3>Inserting Default Users</h3></div>";
    echo "<div class='card-body'>";
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = 'admin@eventmanager.com'");
    $stmt->execute();
    $adminExists = $stmt->fetchColumn();
    
    if ($adminExists == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Admin User', 'admin@eventmanager.com', 'admin123', 'admin', 'active']);
        echo "<div class='alert alert-success'>✓ Admin user created: admin@eventmanager.com / admin123</div>";
    } else {
        echo "<div class='alert alert-info'>ℹ Admin user already exists</div>";
    }
    
    // Check if employee user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = 'john@eventmanager.com'");
    $stmt->execute();
    $employeeExists = $stmt->fetchColumn();
    
    if ($employeeExists == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['John Employee', 'john@eventmanager.com', 'emp123', 'employee', 'active']);
        echo "<div class='alert alert-success'>✓ Employee user created: john@eventmanager.com / emp123</div>";
    } else {
        echo "<div class='alert alert-info'>ℹ Employee user already exists</div>";
    }
    
    echo "</div></div>";
    
    // Create employees table
    echo "<div class='card mb-3'><div class='card-header'><h3>Creating Employees Table</h3></div>";
    echo "<div class='card-body'>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNIQUE NOT NULL,
        designation VARCHAR(100) NOT NULL,
        salary DECIMAL(10,2) NOT NULL DEFAULT 0,
        phone VARCHAR(20),
        address TEXT,
        join_date DATE,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    echo "<div class='alert alert-success'>✓ Employees table created successfully</div>";
    echo "</div></div>";
    
    // Create attendance table
    echo "<div class='card mb-3'><div class='card-header'><h3>Creating Attendance Table</h3></div>";
    echo "<div class='card-body'>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        session_no INT NULL,
        check_in TIME NULL,
        check_out TIME NULL,
        image VARCHAR(255) NULL,
        check_out_image VARCHAR(255) NULL,
        latitude DECIMAL(10,8) NULL,
        longitude DECIMAL(11,8) NULL,
        check_out_latitude DECIMAL(10,8) NULL,
        check_out_longitude DECIMAL(11,8) NULL,
        check_in_notes TEXT NULL,
        check_out_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_attendance_user_date (user_id, date),
        INDEX idx_attendance_user_date_out (user_id, date, check_out)
    )");
    echo "<div class='alert alert-success'>✓ Attendance table created successfully</div>";
    echo "</div></div>";
    
    // Create expenses table
    echo "<div class='card mb-3'><div class='card-header'><h3>Creating Expenses Table</h3></div>";
    echo "<div class='card-body'>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        description TEXT,
        date DATE NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        proof VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    echo "<div class='alert alert-success'>✓ Expenses table created successfully</div>";
    echo "</div></div>";
    
    echo "<div class='card mb-3'><div class='card-header'><h3>Setup Complete!</h3></div>";
    echo "<div class='card-body'>";
    echo "<div class='alert alert-success'><h4>✓ Database setup completed successfully!</h4></div>";
    echo "<p><strong>Default Login Credentials:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> admin@eventmanager.com / admin123</li>";
    echo "<li><strong>Employee:</strong> john@eventmanager.com / emp123</li>";
    echo "</ul>";
    echo "<a href='index.php' class='btn btn-primary mt-3'>Go to Login Page</a>";
    echo "</div></div>";
    
    echo "</div></body></html>";
    
} catch(PDOException $e) {
    echo "<div class='alert alert-danger'>Database setup failed: " . $e->getMessage() . "</div>";
}
?>
