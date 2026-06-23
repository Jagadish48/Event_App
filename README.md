# Event Management System

A comprehensive full-stack Event Planning & Management System built with PHP, MySQL, Bootstrap 5, and JavaScript.

## Features

### Admin Panel
- **Dashboard**: Real-time statistics with charts
- **Employee Management**: Add, edit, delete employees with role assignment
- **Attendance Management**: View, edit, and correct attendance with GPS and image support
- **Expense Management**: Approve/reject employee expenses with proof verification
- **Payroll System**: Automatic salary calculation based on attendance and approved expenses
- **CRM System**: Lead management with conversion to clients
- **Event Management**: Create events, assign teams, track budgets and expenses
- **Reports & Analytics**: Comprehensive reporting with charts and exports

### Employee Panel
- **Dashboard**: Quick attendance marking and overview
- **Attendance**: Check-in/check-out with selfie capture and GPS location
- **Expense Management**: Submit expenses with image proof (max 2 days backdated)
- **Profile Management**: Update personal information and change password
- **Payroll View**: View salary breakdown and monthly summaries

## Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5
- **Backend**: Core PHP (no frameworks)
- **Database**: MySQL
- **Libraries**: Chart.js, Font Awesome
- **Security**: Prepared statements, password hashing, session management

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- PHP extensions: PDO, MySQL, GD, Fileinfo

### Setup Instructions

1. **Database Setup**
   ```bash
   # Create database
   mysql -u root -p
   CREATE DATABASE event_management;
   
   # Import the database schema
   mysql -u root -p event_management < database.sql
   ```

2. **Configuration**
   - Open `config/database.php`
   - Update database credentials:
     ```php
     $host = 'localhost';
     $dbname = 'even_t';
     $username = 'root';
     $password = 'your_password';
     ```

3. **File Permissions**
   ```bash
   # Set permissions for uploads directory
   chmod 755 uploads/
   chmod 666 uploads/*
   ```

4. **Web Server Configuration**
   - Place the project in your web root (e.g., `/var/www/html/event_management`)
   - Ensure `AllowOverride` is enabled for `.htaccess` if using Apache

## Default Login Credentials

### Admin Account
- **Email**: admin@eventmanager.com
- **Password**: admin123

### Employee Account
- **Email**: john@eventmanager.com
- **Password**: emp123

## Project Structure

```
event_management/
|
|-- admin/                  # Admin panel pages
|   |-- dashboard.php
|   |-- employees.php
|   |-- attendance.php
|   |-- expenses.php
|   |-- payroll.php
|   |-- leads.php
|   |-- clients.php
|   |-- events.php
|   |-- reports.php
|   |-- payroll_details.php
|   |-- client_events.php
|   |-- event_team.php
|
|-- employee/               # Employee panel pages
|   |-- dashboard.php
|   |-- attendance.php
|   |-- attendance_process.php
|   |-- expenses.php
|   |-- profile.php
|   |-- payroll.php
|
|-- includes/               # Shared components
|   |-- header.php
|   |-- footer.php
|
|-- config/                 # Configuration files
|   |-- database.php
|
|-- assets/                 # Static assets
|   |-- css/
|   |   |-- style.css
|   |-- js/
|   |   |-- script.js
|   |-- images/
|   |-- fonts/
|
|-- uploads/                # File uploads
|   |-- attendance/
|   |-- expenses/
|
|-- index.php              # Login page
|-- logout.php             # Logout handler
|-- database.sql           # Database schema
|-- README.md              # This file
```

## Key Features

### Attendance System
- GPS location tracking
- Selfie capture using device camera
- Automatic check-out prevention
- Admin can edit/correct attendance

### Expense Management
- Image/PDF proof upload
- Approval workflow
- Backdated entry restriction (max 2 days)
- Category-based reporting

### Payroll Calculation
- Automatic salary calculation based on attendance
- Approved expense inclusion
- Monthly summaries
- Historical tracking

### CRM System
- Lead management with stages (New, Contacted, Converted, Lost)
- Lead to client conversion
- Assignment to employees
- Source tracking

### Event Management
- Team assignment
- Budget tracking
- Expense linking
- Profit/loss calculation

## Security Features

- SQL injection prevention using prepared statements
- XSS protection with input sanitization
- Session-based authentication
- Role-based access control
- Password hashing with bcrypt
- File upload validation

## Browser Support

- Chrome 80+
- Firefox 75+
- Safari 13+
- Edge 80+

## Mobile Responsiveness

The system is fully responsive and works on:
- Desktop computers
- Tablets
- Mobile phones

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check database credentials in `config/database.php`
   - Ensure MySQL server is running
   - Verify database exists

2. **File Upload Issues**
   - Check `uploads/` directory permissions
   - Ensure PHP file upload limits are sufficient
   - Verify GD library is installed for image processing

3. **Camera Access Issues**
   - Use HTTPS for camera access
   - Ensure browser permissions are granted
   - Check device camera functionality

4. **GPS Location Issues**
   - Ensure location services are enabled
   - Grant location permissions in browser
   - Use HTTPS for geolocation

## Development Notes

- The system uses PDO for database operations
- All user inputs are sanitized using `clean_input()` function
- Passwords are hashed using PHP's `password_hash()`
- File uploads are validated for type and size
- Sessions are used for authentication with secure configuration

## Future Enhancements

- Email notifications for expense approvals
- Advanced reporting with filters
- Mobile app development
- Integration with calendar applications
- Multi-language support

## Support

For support and issues, please check:
1. Database connection and permissions
2. PHP error logs
3. Browser console for JavaScript errors
4. File permissions for uploads directory

---

**Note**: This system is designed for demonstration and educational purposes. For production use, additional security measures and optimizations may be required.
