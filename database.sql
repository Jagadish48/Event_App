-- Event Management System Database Schema
-- Create database if not exists
-- CREATE DATABASE IF NOT EXISTS even_t;
-- USE even_t;

-- Users table for authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Employees table
CREATE TABLE employees (
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
);

-- Attendance table
CREATE TABLE attendance (
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
    check_in_notes TEXT NULL,
    check_out_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_attendance_user_date (user_id, date),
    INDEX idx_attendance_user_date_out (user_id, date, check_out)
);

-- Expenses table
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT NULL,
    image VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Leads table
CREATE TABLE leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NOT NULL,
    company VARCHAR(100) NULL,
    description TEXT NULL,
    status ENUM('new', 'contacted', 'converted', 'lost') DEFAULT 'new',
    assigned_to INT NULL,
    source VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

-- Clients table
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NOT NULL,
    company VARCHAR(100) NULL,
    address TEXT NULL,
    linked_lead_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (linked_lead_id) REFERENCES leads(id) ON DELETE SET NULL
);

-- Events table
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    client_id INT NULL,
    budget DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    venue VARCHAR(255) NULL,
    status ENUM('planning', 'active', 'completed', 'cancelled') DEFAULT 'planning',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Event team assignments
CREATE TABLE event_team (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(100) NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_user (event_id, user_id)
);

-- Event expenses (link expenses to events)
CREATE TABLE event_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    expense_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_expense (event_id, expense_id)
);

-- !! SECURITY WARNING !! --
-- The passwords below are SAMPLE credentials for development ONLY.
-- You MUST change these passwords immediately after deploying to production.
-- Default admin password is 'admin123' — change it via the admin panel or by running:
--   UPDATE users SET password = '$2y$10$<NEW_BCRYPT_HASH>' WHERE email = 'admin@eventmanager.com';
-- Insert default admin user (password: admin123)
INSERT INTO users (name, email, password, role) VALUES 
('Admin User', 'admin@eventmanager.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Insert sample employee (password: emp123)
INSERT INTO users (name, email, password, role) VALUES 
('John Employee', 'john@eventmanager.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee');

-- Insert employee details
INSERT INTO employees (user_id, designation, salary, phone, address, join_date) VALUES 
(1, 'System Administrator', 50000.00, '9876543210', '123 Admin Street, City', '2024-01-01'),
(2, 'Event Coordinator', 35000.00, '9876543211', '456 Employee Lane, City', '2024-02-01');

-- Insert sample leads
INSERT INTO leads (name, email, phone, company, description, status, assigned_to, source) VALUES 
('Alice Corporation', 'alice@corp.com', '9876543201', 'Alice Corp', 'Corporate event planning', 'new', 2, 'Website'),
('Bob Enterprises', 'bob@enterprise.com', '9876543202', 'Bob Ent', 'Wedding event', 'contacted', 2, 'Referral'),
('Charlie Solutions', 'charlie@solutions.com', '9876543203', 'Charlie Sol', 'Product launch event', 'converted', 2, 'Cold Call');

-- Insert sample clients
INSERT INTO clients (name, email, phone, company, address, linked_lead_id) VALUES 
('Alice Corporation', 'alice@corp.com', '9876543201', 'Alice Corp', '123 Business Ave, City', 3),
('Charlie Solutions', 'charlie@solutions.com', '9876543203', 'Charlie Sol', '456 Tech Park, City', 3);

-- Insert sample events
INSERT INTO events (name, description, client_id, budget, start_date, end_date, venue, status, created_by) VALUES 
('Annual Corporate Gala', 'Annual corporate celebration event', 1, 100000.00, '2024-12-15', '2024-12-16', 'Grand Hotel, City', 'planning', 1),
('Product Launch Event', 'New product launch ceremony', 2, 75000.00, '2024-11-20', '2024-11-20', 'Convention Center', 'planning', 1);

-- Assign team to events
INSERT INTO event_team (event_id, user_id, role) VALUES 
(1, 2, 'Event Coordinator'),
(2, 2, 'Lead Coordinator');

-- Insert sample expenses
INSERT INTO expenses (user_id, type, amount, description, status, approved_by) VALUES 
(2, 'Travel', 1500.00, 'Travel to client meeting', 'approved', 1),
(2, 'Food', 800.00, 'Client lunch meeting', 'pending', NULL),
(2, 'Supplies', 2000.00, 'Event supplies purchase', 'approved', 1);

-- Link expenses to events
INSERT INTO event_expenses (event_id, expense_id) VALUES 
(1, 1),
(1, 3);

-- Insert sample attendance
INSERT INTO attendance (user_id, date, check_in, check_out, latitude, longitude) VALUES 
(2, CURDATE(), '09:00:00', '18:00:00', 28.6139, 77.2090),
(2, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '09:15:00', '17:45:00', 28.6139, 77.2090);

-- Create indexes for better performance

CREATE INDEX idx_expenses_user_status ON expenses(user_id, status);
CREATE INDEX idx_leads_status_assigned ON leads(status, assigned_to);
CREATE INDEX idx_events_dates ON events(start_date, end_date);
CREATE INDEX idx_event_team_event ON event_team(event_id);
CREATE INDEX idx_event_team_user ON event_team(user_id);
