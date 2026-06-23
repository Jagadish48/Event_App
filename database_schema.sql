-- Event Management System Database Schema
-- Create database and tables for role-based system

-- Create database if not exists
-- CREATE DATABASE IF NOT EXISTS event_management;
-- USE event_management;

-- Users table for authentication
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Employees table for additional employee details
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    designation VARCHAR(100) NOT NULL,
    department VARCHAR(50),
    salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    join_date DATE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Attendance table for employee attendance tracking
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    date DATE NOT NULL,
    session_no INT NULL,
    check_in_time TIME NULL,
    check_out_time TIME NULL,
    photo VARCHAR(255) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    location_address TEXT NULL,
    status ENUM('present', 'absent', 'half_day') DEFAULT 'present',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_attendance_employee_date (employee_id, date)
);

-- Salary table for employee salary records
CREATE TABLE IF NOT EXISTS salary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    month VARCHAR(7) NOT NULL, -- Format: YYYY-MM
    total_days INT NOT NULL DEFAULT 30,
    present_days INT NOT NULL DEFAULT 0,
    absent_days INT NOT NULL DEFAULT 0,
    half_days INT NOT NULL DEFAULT 0,
    base_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    allowance DECIMAL(10,2) NOT NULL DEFAULT 0,
    deduction DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status ENUM('pending', 'paid') DEFAULT 'pending',
    payment_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_employee_month (employee_id, month)
);

-- Profile requests table for employee profile update requests
CREATE TABLE IF NOT EXISTS profile_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    current_name VARCHAR(100) NULL,
    new_name VARCHAR(100) NULL,
    current_phone VARCHAR(20) NULL,
    new_phone VARCHAR(20) NULL,
    current_address TEXT NULL,
    new_address TEXT NULL,
    request_type ENUM('name', 'phone', 'address', 'all') NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_comment TEXT NULL,
    processed_by INT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Leads table for CRM/lead management
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    client_name VARCHAR(100) NOT NULL,
    company_name VARCHAR(100) NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    service_required TEXT NULL,
    budget DECIMAL(10,2) NULL,
    status ENUM('new', 'contacted', 'interested', 'converted', 'lost') DEFAULT 'new',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    next_follow_up DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Insert default admin user
INSERT INTO users (name, email, password, role, phone, address) 
VALUES ('Admin User', 'admin@eventmanager.com', 'admin123', 'admin', '9876543210', 'Admin Office')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Insert default employee user
INSERT INTO users (name, email, password, role, phone, address) 
VALUES ('John Employee', 'john@eventmanager.com', 'emp123', 'employee', '9876543211', 'Employee Address')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Create employee records for users
INSERT INTO employees (user_id, employee_code, designation, department, salary, join_date, status)
SELECT id, CONCAT('EMP', LPAD(id, 4, '0')), designation, department, 25000, CURDATE(), 'active'
FROM users 
WHERE role = 'employee' 
AND id NOT IN (SELECT user_id FROM employees)
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code);

-- Create sample salary records for current month
INSERT INTO salary (employee_id, month, total_days, present_days, base_salary, total_salary)
SELECT e.id, DATE_FORMAT(CURDATE(), '%Y-%m'), 30, 0, e.salary, e.salary
FROM employees e
WHERE e.status = 'active'
AND NOT EXISTS (
    SELECT 1 FROM salary s 
    WHERE s.employee_id = e.id AND s.month = DATE_FORMAT(CURDATE(), '%Y-%m')
);

-- Create sample leads for testing
INSERT INTO leads (employee_id, client_name, company_name, phone, email, service_required, budget, status, priority)
SELECT e.id, 'Sample Client', 'Sample Company', '9876543212', 'client@example.com', 'Event Management', 50000, 'new', 'medium'
FROM employees e 
WHERE e.status = 'active'
LIMIT 1
ON DUPLICATE KEY UPDATE client_name = VALUES(client_name);

-- Show table structure
SHOW TABLES;
