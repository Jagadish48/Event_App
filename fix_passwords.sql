-- Fix Password Hashes for Event Management System
-- This script updates plain text passwords to properly hashed passwords
-- Run this script once to fix login authentication

-- Update admin password (admin123 -> hashed)
UPDATE users 
SET password = '$2y$12$D1hjVic4G/Kg8fZIk1v8zu00Zrn43IcEoDP/ViExyTLAmGDui3Fsu'
WHERE email = 'admin@eventmanager.com';

-- Update employee password (emp123 -> hashed)
UPDATE users 
SET password = '$2y$12$zt.C.nRBwL.D94jJFdabZexaYCwbgGbGhNkbIYi3Oz1nFuga5BbqS'
WHERE email = 'john@eventmanager.com';

-- Verify the updates
SELECT email, role, LEFT(password, 20) as password_preview 
FROM users 
WHERE email IN ('admin@eventmanager.com', 'john@eventmanager.com');

-- Note: After running this script, the login system will work correctly
-- because it uses password_verify() to compare hashed passwords
