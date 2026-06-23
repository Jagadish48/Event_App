<?php
if (ob_get_level() === 0) {
    ob_start();
}

// Set timezone (adjust to your timezone, e.g., 'Asia/Kolkata' for India)
date_default_timezone_set('Asia/Kolkata');

// Parse .env if it exists
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Database Configuration
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'event_final';
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

// Create connection
/**
 * @var PDO|null $pdo
 */
$pdo = null;

try {
    $lastException = null;
    $dbCandidates = [$dbname];

    foreach ($dbCandidates as $candidate) {
        if (!$candidate) {
            continue;
        }

        try {
            $pdo = new PDO("mysql:host=$host;dbname=$candidate;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Set MySQL timezone
            $pdo->exec("SET time_zone = '+05:30'");
            $dbname = $candidate;
            $lastException = null;
            break;
        } catch(PDOException $e) {
            $lastException = $e;
        }
    }

    if (!$pdo) {
        throw $lastException ?: new PDOException('Unable to connect to database.');
    }
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Application settings
if (!defined('SITE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hostName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Dynamically calculate base path based on document root
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']) : '';
    $dir = str_replace('\\', '/', dirname(__DIR__));
    
    // Calculate base path
    if ($docRoot !== '' && strpos($dir, $docRoot) === 0) {
        $basePath = substr($dir, strlen($docRoot));
    } else {
        // Fallback: extract the last directory name from dirname(__DIR__) as the project folder
        $dirParts = explode('/', $dir);
        $projectDir = end($dirParts);
        if ($projectDir) {
            // Check if we have a parent directory that's likely "htdocs" or similar
            $possibleParent = prev($dirParts);
            if ($possibleParent && in_array(strtolower($possibleParent), ['htdocs', 'public', 'www', 'web'])) {
                $basePath = '/' . $projectDir;
            } else {
                // If not, try using script name to find the base
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                if ($scriptName !== '') {
                    $scriptParts = explode('/', $scriptName);
                    // Find the first part that matches our project directory name
                    $key = array_search($projectDir, $scriptParts);
                    if ($key !== false) {
                        $basePath = '/' . implode('/', array_slice($scriptParts, 0, $key + 1));
                    } else {
                        $basePath = '/' . $projectDir;
                    }
                } else {
                    $basePath = '/' . $projectDir;
                }
            }
        } else {
            $basePath = '';
        }
    }
    
    // Clean up basePath
    if ($basePath === '.') {
        // If we're in CLI and scriptName is just filename, get project dir
        $dirParts = explode('/', $dir);
        $projectDir = end($dirParts);
        if ($projectDir) {
            $basePath = '/' . $projectDir;
        } else {
            $basePath = '';
        }
    }
    if (!empty($basePath) && substr($basePath, 0, 1) !== '/') {
        $basePath = '/' . $basePath;
    }
    if (substr($basePath, -1) === '/') {
        $basePath = substr($basePath, 0, -1);
    }
    
    define('SITE_URL', $scheme . '://' . $hostName . $basePath . '/');
}
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
if (!defined('IDENTITY_MAX_FILE_SIZE')) {
    define('IDENTITY_MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
}

// Load WhatsApp service layer
if (!function_exists('isWhatsAppEnabled')) {
    require_once __DIR__ . '/whatsapp.php';
}

function getAppEncryptionKey(): string {
    $key = (string) getAppSetting('ems_crypto_key', '');
    $key = trim($key);
    if ($key !== '') {
        return $key;
    }
    $raw = random_bytes(32);
    $b64 = base64_encode($raw);
    setAppSetting('ems_crypto_key', $b64);
    return $b64;
}

function encryptSensitiveValue($plainText): string {
    $plainText = (string) $plainText;
    if ($plainText === '') return '';

    $keyB64 = getAppEncryptionKey();
    $key = base64_decode($keyB64, true);
    if ($key === false || strlen($key) < 32) {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipherRaw = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherRaw === false || $tag === '') {
        return '';
    }

    return base64_encode($iv . $tag . $cipherRaw);
}

function decryptSensitiveValue($cipherText): string {
    $cipherText = (string) $cipherText;
    if ($cipherText === '') return '';

    $raw = base64_decode($cipherText, true);
    if ($raw === false || strlen($raw) < (12 + 16 + 1)) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipherRaw = substr($raw, 28);

    $keyB64 = getAppEncryptionKey();
    $key = base64_decode($keyB64, true);
    if ($key === false || strlen($key) < 32) {
        return '';
    }

    $plain = openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : (string) $plain;
}

function hashSensitiveValue($plainText): string {
    $plainText = trim((string) $plainText);
    if ($plainText === '') return '';
    $keyB64 = getAppEncryptionKey();
    $key = base64_decode($keyB64, true);
    if ($key === false || $key === '') {
        return hash('sha256', $plainText);
    }
    return hash_hmac('sha256', $plainText, $key);
}

function uploadIdentityFile($file, $folder = 'identity') {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file parameters.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error.');
    }

    if ($file['size'] > IDENTITY_MAX_FILE_SIZE) {
        throw new RuntimeException('File size too large.');
    }

    $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes, true)) {
        throw new RuntimeException('Invalid file type.');
    }

    return uploadFile($file, $folder);
}

function maskAadhaarLast4($last4): string {
    $last4 = preg_replace('/\D+/', '', (string) $last4);
    if (strlen($last4) !== 4) return '-';
    return 'XXXXXXXX' . $last4;
}

function ensureEmployeeIdentitySchema(): void {
    global $pdo;
    if (!$pdo) return;

    try { $pdo->exec("ALTER TABLE employees ADD COLUMN aadhaar_number TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN aadhaar_last4 CHAR(4) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN aadhaar_hash CHAR(64) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN pan_number TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN pan_hash CHAR(64) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN aadhaar_file VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN pan_file VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN uploaded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE UNIQUE INDEX uniq_employees_aadhaar_hash ON employees(aadhaar_hash)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX uniq_employees_pan_hash ON employees(pan_hash)"); } catch (PDOException $e) {}
}

function ensureEmployeePolicySchema(): void {
    global $pdo;
    if (!$pdo) return;

    try { $pdo->exec("ALTER TABLE employees ADD COLUMN weekly_off_day TINYINT NULL DEFAULT 7"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN monthly_leave_quota INT NULL DEFAULT 4"); } catch (PDOException $e) {}
}

function ensureLeaveRequestsSchema(): void {
    global $pdo;
    if (!$pdo) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        from_date DATE NOT NULL,
        to_date DATE NOT NULL,
        reason VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        admin_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_leave_user (user_id),
        INDEX idx_leave_status (status),
        INDEX idx_leave_range (from_date, to_date),
        INDEX idx_leave_user_range (user_id, from_date, to_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureSalaryDeductionsSchema(): void {
    global $pdo;
    if (!$pdo) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_deductions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        type ENUM('absent','unpaid_leave') NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_deduction_user_date_type (user_id, date, type),
        INDEX idx_deduction_user_month (user_id, date),
        INDEX idx_deduction_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureAttendancePolicySchema(): void {
    global $pdo;
    if (!$pdo) return;

    // First, make sure we have the unique index to prevent duplicates
    try { $pdo->exec("CREATE UNIQUE INDEX idx_attendance_user_date_unique ON attendance(user_id, date)"); } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_original VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_updated_at DATETIME NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_update_note VARCHAR(255) NULL"); } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN session_no INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_image VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_in_notes TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_notes TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_latitude DECIMAL(10,8) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_longitude DECIMAL(11,8) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_reason VARCHAR(50) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_description TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_day VARCHAR(15) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_time TIME NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN leave_request_id INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN weekly_off_worked TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN total_hours DECIMAL(5,2) NULL"); } catch (PDOException $e) {}
}

function getEmployeePolicyRow($userId): array {
    global $pdo;
    ensureEmployeePolicySchema();

    $stmt = $pdo->prepare("SELECT salary, COALESCE(weekly_off_day, 7) as weekly_off_day, COALESCE(monthly_leave_quota, 4) as monthly_leave_quota
                           FROM employees WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int) $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['salary' => 0, 'weekly_off_day' => 7, 'monthly_leave_quota' => 4];
    }
    return [
        'salary' => (float) ($row['salary'] ?? 0),
        'weekly_off_day' => (int) ($row['weekly_off_day'] ?? 7),
        'monthly_leave_quota' => (int) ($row['monthly_leave_quota'] ?? 4),
    ];
}

function getMonthStartEnd($month): array {
    $month = substr((string) $month, 0, 7);
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function getApprovedLeaveRequestsOverlapping($userId, $month): array {
    global $pdo;
    ensureLeaveRequestsSchema();

    [$start, $end] = getMonthStartEnd($month);
    $stmt = $pdo->prepare("SELECT * FROM leave_requests
                           WHERE user_id = ?
                             AND status = 'approved'
                             AND to_date >= ?
                             AND from_date <= ?
                           ORDER BY from_date ASC, id ASC");
    $stmt->execute([(int) $userId, $start, $end]);
    return $stmt->fetchAll();
}

function getApprovedLeaveDatesMap($userId, $month): array {
    $rows = getApprovedLeaveRequestsOverlapping($userId, $month);
    [$start, $end] = getMonthStartEnd($month);
    $map = [];

    $startTs = strtotime($start);
    $endTs = strtotime($end);
    foreach ($rows as $r) {
        $fromTs = max($startTs, strtotime((string) ($r['from_date'] ?? $start)));
        $toTs = min($endTs, strtotime((string) ($r['to_date'] ?? $end)));
        for ($ts = $fromTs; $ts <= $toTs; $ts = strtotime('+1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            $map[$d] = true;
        }
    }
    return $map;
}

function getMonthlyAttendanceAggregatesMap($userId, $month): array {
    global $pdo;
    ensureAttendancePolicySchema();

    $stmt = $pdo->prepare("SELECT date,
                                  MIN(check_in) as first_in,
                                  MAX(check_out) as last_out,
                                  SUM(CASE WHEN check_in IS NOT NULL AND check_out IS NOT NULL THEN TIMESTAMPDIFF(SECOND, check_in, check_out) ELSE 0 END) as seconds_worked,
                                  MAX(CASE WHEN COALESCE(attendance_status, '') = 'absent' OR COALESCE(attendance_status_original, '') = 'absent' OR COALESCE(check_in_notes, '') = 'Marked absent' THEN 1 ELSE 0 END) as absent_marked,
                                  MAX(COALESCE(weekly_off_worked, 0)) as weekly_off_worked
                           FROM attendance
                           WHERE user_id = ?
                             AND DATE_FORMAT(date, '%Y-%m') = ?
                           GROUP BY date");
    $stmt->execute([(int) $userId, substr((string) $month, 0, 7)]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[(string) $r['date']] = $r;
    }
    return $map;
}

function getMonthlyWorkingDaysCount($month, $weeklyOffDayN = 7): int {
    [$start, $end] = getMonthStartEnd($month);
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $offDay = (int) $weeklyOffDayN;
    if ($offDay < 1 || $offDay > 7) $offDay = 7;

    $working = 0;
    for ($ts = $startTs; $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
        $n = (int) date('N', $ts);
        if ($n === $offDay) continue;
        $working++;
    }
    return max(1, $working);
}

function getMonthlyPolicySummary($userId, $month): array {
    $month = substr((string) $month, 0, 7);
    $policy = getEmployeePolicyRow($userId);
    $weeklyOffDayN = (int) ($policy['weekly_off_day'] ?? 7);
    $leaveQuota = max(0, (int) ($policy['monthly_leave_quota'] ?? 4));

    [$start, $end] = getMonthStartEnd($month);
    $today = date('Y-m-d');
    $effectiveEnd = ($month === substr($today, 0, 7)) ? min($end, $today) : $end;

    $aggMap = getMonthlyAttendanceAggregatesMap($userId, $month);
    $leaveMap = getApprovedLeaveDatesMap($userId, $month);

    $lateThreshold = '12:00:00';
    $presentDays = 0;
    $lateDays = 0;
    $absentDays = 0;
    $weeklyOffDays = 0;
    $weeklyOffWorkedDays = 0;
    $leaveDaysWorking = 0;

    $daily = [];
    $startTs = strtotime($start);
    $endTs = strtotime($effectiveEnd);
    for ($ts = $startTs; $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        $n = (int) date('N', $ts);
        $isWeeklyOff = ($n === $weeklyOffDayN);
        $row = $aggMap[$date] ?? null;
        $firstIn = $row['first_in'] ?? null;
        $lastOut = $row['last_out'] ?? null;
        $secondsWorked = (int) ($row['seconds_worked'] ?? 0);
        $absentMarked = (int) ($row['absent_marked'] ?? 0) === 1;
        $weeklyOffWorked = (int) ($row['weekly_off_worked'] ?? 0) === 1;
        $hasCheckIn = !empty($firstIn);

        $statusKey = 'absent';
        if ($hasCheckIn) {
            $statusKey = ((string) $firstIn > $lateThreshold) ? 'late' : 'present';
        } elseif ($isWeeklyOff && $weeklyOffWorked) {
            $statusKey = 'present';
        } elseif ($isWeeklyOff) {
            $statusKey = 'weekly_off';
        } elseif (!empty($leaveMap[$date])) {
            $statusKey = 'leave';
        } elseif ($absentMarked) {
            $statusKey = 'absent';
        }

        if ($statusKey === 'present' || $statusKey === 'late') {
            $presentDays++;
            if ($statusKey === 'late') $lateDays++;
            if ($isWeeklyOff) $weeklyOffWorkedDays++;
        } elseif ($statusKey === 'weekly_off') {
            $weeklyOffDays++;
        } elseif ($statusKey === 'leave') {
            if (!$isWeeklyOff) {
                $leaveDaysWorking++;
            } else {
                $weeklyOffDays++;
            }
        } else {
            if (!$isWeeklyOff) {
                $absentDays++;
            } else {
                $weeklyOffDays++;
            }
        }

        $daily[] = [
            'date' => $date,
            'check_in' => $firstIn,
            'check_out' => $lastOut,
            'hours' => $secondsWorked > 0 ? round($secondsWorked / 3600, 2) : 0,
            'status_key' => $statusKey,
            'is_weekly_off' => $isWeeklyOff ? 1 : 0,
        ];
    }

    $paidLeaveDays = min($leaveQuota, $leaveDaysWorking);
    $unpaidLeaveDays = max(0, $leaveDaysWorking - $leaveQuota);
    $remainingLeaves = max(0, $leaveQuota - $leaveDaysWorking);

    return [
        'month' => $month,
        'weekly_off_day' => $weeklyOffDayN,
        'leave_quota' => $leaveQuota,
        'present_days' => $presentDays,
        'late_days' => $lateDays,
        'absent_days' => $absentDays,
        'weekly_offs' => $weeklyOffDays,
        'weekly_offs_worked' => $weeklyOffWorkedDays,
        'approved_leaves' => $leaveDaysWorking,
        'paid_leaves' => $paidLeaveDays,
        'unpaid_leaves' => $unpaidLeaveDays,
        'remaining_leaves' => $remainingLeaves,
        'daily' => $daily,
    ];
}

function ensureMonthlyDeductionsStored($userId, $month, $perDaySalary, $summary): void {
    global $pdo;
    ensureSalaryDeductionsSchema();

    $perDay = round((float) $perDaySalary, 2);
    if ($perDay <= 0) return;

    $leaveQuota = (int) ($summary['leave_quota'] ?? 4);
    $leaveCount = 0;

    foreach (($summary['daily'] ?? []) as $d) {
        $date = (string) ($d['date'] ?? '');
        $statusKey = (string) ($d['status_key'] ?? '');
        $isWeeklyOff = (int) ($d['is_weekly_off'] ?? 0) === 1;
        if ($date === '' || $isWeeklyOff) continue;

        if ($statusKey === 'absent') {
            $stmt = $pdo->prepare("INSERT IGNORE INTO salary_deductions (user_id, date, type, amount, note) VALUES (?, ?, 'absent', ?, ?)");
            $stmt->execute([(int) $userId, $date, $perDay, 'Auto deduction']);
        } elseif ($statusKey === 'leave') {
            $leaveCount++;
            if ($leaveCount > $leaveQuota) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO salary_deductions (user_id, date, type, amount, note) VALUES (?, ?, 'unpaid_leave', ?, ?)");
                $stmt->execute([(int) $userId, $date, $perDay, 'Leave quota exceeded']);
            }
        }
    }
}

function ensureAppSettingsSchema() {
    /**
     * @var PDO $pdo
     */
    global $pdo;
    if (!$pdo) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(80) NOT NULL PRIMARY KEY,
            `value` TEXT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
    }
}

function getAppSetting($key, $default = '') {
    /**
     * @var PDO $pdo
     */
    global $pdo;
    $key = trim((string) $key);
    if ($key === '') return $default;
    ensureAppSettingsSchema();
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) return $default;
        $val = (string) ($row['value'] ?? '');
        return $val !== '' ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setAppSetting($key, $value) {
    /**
     * @var PDO $pdo
     */
    global $pdo;
    $key = trim((string) $key);
    if ($key === '') return false;
    ensureAppSettingsSchema();
    try {
        $stmt = $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([$key, $value !== '' ? (string) $value : null]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function deleteAppSetting($key) {
    /**
     * @var PDO $pdo
     */
    global $pdo;
    $key = trim((string) $key);
    if ($key === '') return false;
    ensureAppSettingsSchema();
    try {
        $stmt = $pdo->prepare("DELETE FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function resolveAppAssetUrl($relPath) {
    $p = trim((string) $relPath);
    if ($p === '') return '';
    $p = str_replace('\\', '/', $p);
    $p = ltrim($p, '/');
    return SITE_URL . $p;
}

function getBrandingSettings() {
    return [
        'app_name' => getAppSetting('app_name', 'NETWORK EVENTS'),
        'logo_main' => getAppSetting('logo_main', ''),
        'logo_dark' => getAppSetting('logo_dark', ''),
        'logo_light' => getAppSetting('logo_light', ''),
        'logo_login' => getAppSetting('logo_login', ''),
        'favicon' => getAppSetting('favicon', '')
    ];
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
        $target = (string) $url;
        if ($target === '') {
            return;
        }

        if (!headers_sent()) {
            header("Location: $target");
            exit();
        }

        $safe = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        echo "<script>window.location.href='{$safe}';</script>";
        echo "<noscript><meta http-equiv=\"refresh\" content=\"0;url={$safe}\"></noscript>";
        exit();
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function normalizeUserRole($role) {
    $r = strtolower(trim((string) $role));
    if ($r === 'admin' || $r === 'employee') {
        return $r;
    }
    if ($r === '1') {
        return 'admin';
    }
    if ($r === '0') {
        return 'employee';
    }
    if ($r === 'administrator' || $r === 'superadmin' || $r === 'super_admin' || $r === 'root') {
        return 'admin';
    }
    if ($r === 'staff' || $r === 'emp') {
        return 'employee';
    }
    return 'employee';
}

function ensureSessionUserLoaded() {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $existingRole = (string) ($_SESSION['role'] ?? '');
    if ($existingRole !== '') {
        $_SESSION['role'] = normalizeUserRole($existingRole);
        return;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT name, email, role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        $_SESSION['role'] = normalizeUserRole($user['role'] ?? 'employee');
        if (!isset($_SESSION['name']) && isset($user['name'])) {
            $_SESSION['name'] = $user['name'];
        }
        if (!isset($_SESSION['email']) && isset($user['email'])) {
            $_SESSION['email'] = $user['email'];
        }
    } catch (PDOException $e) {
    }
}

function isAdmin() {
    ensureSessionUserLoaded();
    return normalizeUserRole($_SESSION['role'] ?? '') === 'admin';
}

function isEmployee() {
    ensureSessionUserLoaded();
    return normalizeUserRole($_SESSION['role'] ?? '') === 'employee';
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect(SITE_URL . 'login.php');
    }
    ensureSessionUserLoaded();
}

function requireEmployee() {
    if (!isEmployee()) {
        redirect(SITE_URL . 'login.php');
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        redirect(SITE_URL . 'login.php');
    }
}

if (!function_exists('get_employee_id')) {
    function get_employee_id() {
        if (!isset($_SESSION) || !isset($_SESSION['user_id'])) {
            return null;
        }
        if (!isEmployee()) {
            return null;
        }

        if (isset($_SESSION['employee_id']) && is_numeric($_SESSION['employee_id'])) {
            return (int) $_SESSION['employee_id'];
        }

        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
            $stmt->execute([(int) $_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row && isset($row['id'])) {
                $_SESSION['employee_id'] = (int) $row['id'];
                return (int) $row['id'];
            }
            return null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isLoggedIn();
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return isAdmin();
    }
}

if (!function_exists('is_employee')) {
    function is_employee() {
        return isEmployee();
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        requireAdmin();
    }
}

if (!function_exists('require_employee')) {
    function require_employee() {
        requireEmployee();
    }
}

if (!function_exists('get_current_month')) {
    function get_current_month() {
        return getCurrentMonth();
    }
}

if (!function_exists('format_currency')) {
    function format_currency($amount) {
        return formatCurrency($amount);
    }
}

if (!function_exists('format_date')) {
    function format_date($date, $format = 'd M Y') {
        return formatDate($date, $format);
    }
}

function formatCurrency($amount) {
    return 'Rs. ' . number_format($amount, 2);
}

function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

function getAttendanceStatusMeta($checkInTime) {
    if (empty($checkInTime)) {
        return ['key' => 'absent', 'label' => 'Absent', 'badge' => 'danger', 'icon' => 'times-circle'];
    }
    if (strtotime((string) $checkInTime) > strtotime('12:00:00')) {
        return ['key' => 'late', 'label' => 'Late', 'badge' => 'warning', 'icon' => 'clock'];
    }
    return ['key' => 'present', 'label' => 'Present', 'badge' => 'success', 'icon' => 'check-circle'];
}

function renderAttendanceStatusBadge($checkInTime) {
    $m = getAttendanceStatusMeta($checkInTime);
    return '<span class="badge bg-' . htmlspecialchars((string) $m['badge']) . '"><i class="fas fa-' . htmlspecialchars((string) $m['icon']) . ' me-1"></i>' . htmlspecialchars((string) $m['label']) . '</span>';
}

function renderAttendanceStatusBadgeFromKey($key) {
    $key = strtolower(trim((string) $key));
    if ($key === 'late') return renderAttendanceStatusBadge('12:01:00');
    if ($key === 'present') return renderAttendanceStatusBadge('11:00:00');
    if ($key === 'weekly_off') {
        return '<span class="badge bg-purple"><i class="fas fa-mug-hot me-1"></i>Weekly Off</span>';
    }
    if ($key === 'leave') {
        return '<span class="badge bg-primary"><i class="fas fa-plane-departure me-1"></i>Approved Leave</span>';
    }
    return renderAttendanceStatusBadge(null);
}

function uploadFile($file, $folder = '') {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file parameters.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error.');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size too large.');
    }

    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowedTypes)) {
        throw new RuntimeException('Invalid file type.');
    }

    $filename = uniqid() . '.' . $ext;
    $folder = trim((string) $folder, "/\\");
    $uploadBase = rtrim((string) UPLOAD_PATH, "/\\") . DIRECTORY_SEPARATOR;
    $uploadDir = $uploadBase . ($folder !== '' ? ($folder . DIRECTORY_SEPARATOR) : '');

    if ($folder !== '' && !is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }
    
    return ($folder !== '' ? ($folder . '/') : '') . $filename;
}

function normalizeUploadRelativePath($storedPath) {
    $path = trim((string) $storedPath);
    if ($path === '') return '';

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if (substr($path, 0, 8) === 'uploads/') {
        $path = substr($path, strlen('uploads/'));
    }
    return $path;
}

function resolveUploadPathInfo($storedPath) {
    $normalized = normalizeUploadRelativePath($storedPath);
    if ($normalized === '') {
        return ['exists' => false, 'url' => '', 'debug' => ''];
    }

    $primaryAbs = rtrim((string) UPLOAD_PATH, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $primaryUrl = SITE_URL . 'uploads/' . $normalized;

    if (is_file($primaryAbs)) {
        return ['exists' => true, 'url' => $primaryUrl, 'debug' => $primaryUrl];
    }

    $pos = strpos($normalized, '/');
    if ($pos !== false) {
        $fallback = substr($normalized, 0, $pos) . substr($normalized, $pos + 1);
        $fallbackAbs = rtrim((string) UPLOAD_PATH, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fallback);
        $fallbackUrl = SITE_URL . 'uploads/' . $fallback;
        if (is_file($fallbackAbs)) {
            return ['exists' => true, 'url' => $fallbackUrl, 'debug' => $fallbackUrl . ' (fallback)'];
        }
        return [
            'exists' => false,
            'url' => $primaryUrl,
            'debug' => $primaryUrl . ' (missing) | ' . $fallbackUrl . ' (missing)'
        ];
    }

    return ['exists' => false, 'url' => $primaryUrl, 'debug' => $primaryUrl . ' (missing)'];
}

function ensureProfileImageSchema(): void {
    global $pdo;
    if (!$pdo) return;
    try { $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employees ADD COLUMN profile_image VARCHAR(255) NULL"); } catch (PDOException $e) {}
}

function deleteUploadedFileByStoredPath($storedPath): bool {
    $normalized = normalizeUploadRelativePath($storedPath);
    if ($normalized === '') return false;
    if (strpos($normalized, '..') !== false) return false;

    $base = rtrim((string) UPLOAD_PATH, "/\\");
    $abs = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $realBase = realpath($base);
    $realAbs = realpath($abs);

    if ($realBase && $realAbs) {
        $realBase = rtrim(str_replace('\\', '/', $realBase), '/') . '/';
        $realAbs = str_replace('\\', '/', $realAbs);
        if (strpos($realAbs, $realBase) !== 0) {
            return false;
        }
    }

    if (!is_file($abs)) return false;
    return @unlink($abs);
}

function uploadProfileImage(array $file, int $userId): string {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file parameters.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error.');
    }

    $maxBytes = 2 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Max file size is 2MB.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Only JPG, JPEG, PNG, and WEBP files are allowed.');
    }

    $imgInfo = @getimagesize($tmp);
    $mime = (string) ($imgInfo['mime'] ?? '');
    if (class_exists('finfo')) {
        try {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $detected = (string) $fi->file($tmp);
            if ($detected !== '') $mime = $detected;
        } catch (Exception $e) {
        }
    }
    if (!$imgInfo || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Invalid image file.');
    }

    $folder = 'profile';
    $uploadBase = rtrim((string) UPLOAD_PATH, "/\\") . DIRECTORY_SEPARATOR;
    $uploadDir = $uploadBase . $folder . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $rand = (string) mt_rand(100000, 999999);
    }

    $canProcess =
        extension_loaded('gd') &&
        function_exists('imagecreatefromstring') &&
        function_exists('imagecreatetruecolor') &&
        function_exists('imagecopyresampled') &&
        function_exists('imagejpeg') &&
        function_exists('imagedestroy');

    if ($canProcess) {
        $raw = @file_get_contents($tmp);
        if ($raw === false) {
            $canProcess = false;
        } else {
            $src = @imagecreatefromstring($raw);
            if (!$src) {
                $canProcess = false;
            } else {
                $srcW = imagesx($src);
                $srcH = imagesy($src);
                if ($srcW < 1 || $srcH < 1) {
                    imagedestroy($src);
                    $canProcess = false;
                } else {
                    $maxSide = 512;
                    $scale = min(1, $maxSide / $srcW, $maxSide / $srcH);
                    $dstW = (int) max(1, floor($srcW * $scale));
                    $dstH = (int) max(1, floor($srcH * $scale));

                    $dst = imagecreatetruecolor($dstW, $dstH);
                    if (!$dst) {
                        imagedestroy($src);
                        $canProcess = false;
                    } else {
                        $white = imagecolorallocate($dst, 255, 255, 255);
                        imagefill($dst, 0, 0, $white);
                        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                        imagedestroy($src);

                        $filename = (int) $userId . '_' . date('YmdHis') . '_' . $rand . '.jpg';
                        $destAbs = $uploadDir . $filename;
                        $ok = @imagejpeg($dst, $destAbs, 82);
                        imagedestroy($dst);
                        if ($ok) {
                            return $folder . '/' . $filename;
                        }
                        $canProcess = false;
                    }
                }
            }
        }
    }

    $mimeExt = 'jpg';
    if ($mime === 'image/png') $mimeExt = 'png';
    if ($mime === 'image/webp') $mimeExt = 'webp';
    if ($mime === 'image/jpeg') $mimeExt = 'jpg';

    $filename = (int) $userId . '_' . date('YmdHis') . '_' . $rand . '.' . $mimeExt;
    $destAbs = $uploadDir . $filename;

    if (!move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException('Failed to save profile image.');
    }

    return $folder . '/' . $filename;
}

function uploadExcelFile($file, $folder = '') {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file parameters.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error.');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size too large.');
    }

    $allowedTypes = ['xls', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedTypes, true)) {
        throw new RuntimeException('Invalid file type.');
    }

    $filename = uniqid('report_', true) . '.' . $ext;
    $folder = trim((string) $folder, "/\\");
    $uploadBase = rtrim((string) UPLOAD_PATH, "/\\") . DIRECTORY_SEPARATOR;
    $uploadDir = $uploadBase . ($folder !== '' ? ($folder . DIRECTORY_SEPARATOR) : '');

    if ($folder !== '' && !is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    return ($folder !== '' ? ($folder . '/') : '') . $filename;
}

function ensureClientWorkflowSchema() {
    global $pdo;

    try { $pdo->exec("ALTER TABLE clients ADD COLUMN assigned_to INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN workflow_status VARCHAR(40) NOT NULL DEFAULT 'New Lead'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN workflow_updated_at TIMESTAMP NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN workflow_notes TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN booking_date DATE NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE INDEX idx_clients_assigned_to ON clients(assigned_to)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_clients_workflow_status ON clients(workflow_status)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_clients_booking_date ON clients(booking_date)"); } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        user_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_notes_client (client_id),
        INDEX idx_client_notes_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getClientBookingDailyLimit(): int {
    return 4;
}

function isValidISODate($date): bool {
    $date = trim((string) $date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function getClientBookingsCountForDate(string $date, int $excludeClientId = 0): int {
    global $pdo;
    if (!$pdo) return 0;
    if (!isValidISODate($date)) return 0;

    try {
        if ($excludeClientId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM clients WHERE booking_date = ? AND id <> ?");
            $stmt->execute([$date, $excludeClientId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM clients WHERE booking_date = ?");
            $stmt->execute([$date]);
        }
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function getClientBookingAvailability(string $date, int $excludeClientId = 0): array {
    $limit = getClientBookingDailyLimit();
    $count = getClientBookingsCountForDate($date, $excludeClientId);
    $remaining = max(0, $limit - $count);

    $status = 'Available';
    if ($count >= $limit) {
        $status = 'Packed';
    } elseif ($remaining <= 1) {
        $status = 'Limited Slots';
    }

    return [
        'date' => $date,
        'limit' => $limit,
        'count' => $count,
        'remaining' => $remaining,
        'status' => $status
    ];
}

function getPackedClientBookingDates(string $fromDate, string $toDate): array {
    global $pdo;
    if (!$pdo) return [];
    if (!isValidISODate($fromDate) || !isValidISODate($toDate)) return [];
    $limit = getClientBookingDailyLimit();

    try {
        $stmt = $pdo->prepare("SELECT booking_date, COUNT(*) as c
                               FROM clients
                               WHERE booking_date IS NOT NULL
                                 AND booking_date BETWEEN ? AND ?
                               GROUP BY booking_date
                               HAVING c >= ?
                               ORDER BY booking_date ASC");
        $stmt->execute([$fromDate, $toDate, $limit]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $d = (string) ($r['booking_date'] ?? '');
            if ($d !== '') $out[] = $d;
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

function ensureExpenseCategorizationSchema() {
    global $pdo;

    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN expense_category ENUM('personal','client') NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN personal_type VARCHAR(100) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN client_id INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN event_id INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN purpose VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN reimbursable TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN approved_by INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN approved_at TIMESTAMP NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE expenses ADD COLUMN rejection_reason TEXT NULL"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE INDEX idx_expenses_category ON expenses(expense_category)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_expenses_client ON expenses(client_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_expenses_event ON expenses(event_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_expenses_user_category ON expenses(user_id, expense_category)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_expenses_user_status_category ON expenses(user_id, status, expense_category)"); } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        expense_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_event_expense (event_id, expense_id),
        INDEX idx_event_expenses_event (event_id),
        INDEX idx_event_expenses_expense (expense_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function normalizeExpenseCategoryName($name): string {
    $name = trim((string) $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

function expenseCategoryKey($name): string {
    $name = normalizeExpenseCategoryName($name);
    $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return $key;
}

function ensureExpenseCategoriesSchema(): void {
    global $pdo;
    if (!$pdo) return;

    ensureAppSettingsSchema();

    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        name_key VARCHAR(90) NOT NULL,
        global_key VARCHAR(90) NULL,
        scope ENUM('user','global') NOT NULL DEFAULT 'user',
        created_by_user_id INT NOT NULL DEFAULT 0,
        status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
        usage_count INT NOT NULL DEFAULT 0,
        last_used_at TIMESTAMP NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cat_scope_creator_key (scope, created_by_user_id, name_key),
        UNIQUE KEY uniq_cat_global_key (global_key),
        INDEX idx_cat_scope_status (scope, status),
        INDEX idx_cat_usage (usage_count),
        INDEX idx_cat_last_used (last_used_at),
        INDEX idx_cat_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $pdo->exec("ALTER TABLE expense_categories ADD COLUMN global_key VARCHAR(90) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX uniq_cat_global_key ON expense_categories(global_key)"); } catch (PDOException $e) {}
    try { $pdo->exec("UPDATE expense_categories SET global_key = name_key WHERE scope = 'global' AND global_key IS NULL"); } catch (PDOException $e) {}

    $defaults = [
        'Travel',
        'Food',
        'Supplies',
        'Marketing',
        'Communication',
        'Fuel',
        'Internet'
    ];

    foreach ($defaults as $d) {
        $name = normalizeExpenseCategoryName($d);
        $key = expenseCategoryKey($name);
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO expense_categories (name, name_key, global_key, scope, created_by_user_id, status, is_active)
                                   VALUES (?, ?, ?, 'global', 0, 'approved', 1)");
            $stmt->execute([$name, $key, $key]);
        } catch (PDOException $e) {}
    }

    $backfilled = getAppSetting('expense_categories_backfilled', '0');
    if ($backfilled !== '1') {
        try {
            $stmt = $pdo->query("SELECT LOWER(TRIM(COALESCE(NULLIF(personal_type,''), NULLIF(type,'')))) as k,
                                        TRIM(COALESCE(NULLIF(personal_type,''), NULLIF(type,''))) as n,
                                        COUNT(*) as c,
                                        MAX(created_at) as last_used
                                 FROM expenses
                                 WHERE expense_category = 'personal'
                                   AND COALESCE(NULLIF(personal_type,''), NULLIF(type,'')) IS NOT NULL
                                   AND LOWER(TRIM(COALESCE(NULLIF(personal_type,''), NULLIF(type,'')))) NOT IN ('other', 'client expense')
                                 GROUP BY LOWER(TRIM(COALESCE(NULLIF(personal_type,''), NULLIF(type,''))))");
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                $n = normalizeExpenseCategoryName((string) ($r['n'] ?? ''));
                if ($n === '') continue;
                $k = expenseCategoryKey($n);
                $c = (int) ($r['c'] ?? 0);
                $lastUsed = (string) ($r['last_used'] ?? '');

                $stmt2 = $pdo->prepare("INSERT IGNORE INTO expense_categories (name, name_key, global_key, scope, created_by_user_id, status, usage_count, last_used_at, is_active)
                                        VALUES (?, ?, ?, 'global', 0, 'approved', ?, ?, 1)");
                $stmt2->execute([$n, $k, $k, $c, $lastUsed !== '' ? $lastUsed : null]);

                $stmt3 = $pdo->prepare("UPDATE expense_categories
                                        SET usage_count = GREATEST(usage_count, ?),
                                            last_used_at = CASE WHEN last_used_at IS NULL OR last_used_at < ? THEN ? ELSE last_used_at END
                                        WHERE scope = 'global' AND global_key = ? LIMIT 1");
                $stmt3->execute([$c, $lastUsed, $lastUsed, $k]);
            }
        } catch (PDOException $e) {}
        setAppSetting('expense_categories_backfilled', '1');
    }
}

function getExpenseCategorySettings(): array {
    $scope = strtolower(trim((string) getAppSetting('expense_custom_category_scope', 'user')));
    if (!in_array($scope, ['user', 'global'], true)) $scope = 'user';
    $requireApproval = getAppSetting('expense_custom_category_require_approval', '0') === '1';
    return ['scope' => $scope, 'require_approval' => $requireApproval];
}

function upsertExpenseCategory($name, $creatorUserId): array {
    global $pdo;
    ensureExpenseCategoriesSchema();

    $name = normalizeExpenseCategoryName($name);
    if ($name === '') {
        throw new RuntimeException('Expense name is required.');
    }
    $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($len > 80) {
        throw new RuntimeException('Expense name is too long.');
    }

    $key = expenseCategoryKey($name);
    if ($key === 'other' || $key === 'client expense') {
        throw new RuntimeException('Invalid expense name.');
    }

    $settings = getExpenseCategorySettings();
    $scope = (string) ($settings['scope'] ?? 'user');
    $requireApproval = (bool) ($settings['require_approval'] ?? false);

    $creatorUserId = (int) $creatorUserId;
    $createdBy = $creatorUserId;
    $status = $requireApproval ? 'pending' : 'approved';

    if ($scope === 'global') {
        $stmt = $pdo->prepare("SELECT id, name, status, is_active, created_by_user_id
                               FROM expense_categories
                               WHERE scope = 'global' AND global_key = ?
                               LIMIT 1");
        $stmt->execute([$key]);
    } else {
        $stmt = $pdo->prepare("SELECT id, name, status, is_active
                               FROM expense_categories
                               WHERE scope = 'user' AND created_by_user_id = ? AND name_key = ?
                               LIMIT 1");
        $stmt->execute([$creatorUserId, $key]);
    }
    $existing = $stmt->fetch();
    if ($existing) {
        if ((int) ($existing['is_active'] ?? 1) !== 1) {
            $stmt = $pdo->prepare("UPDATE expense_categories SET is_active = 1, status = CASE WHEN status = 'rejected' THEN ? ELSE status END WHERE id = ? LIMIT 1");
            $stmt->execute([$status, (int) $existing['id']]);
        }
        return [
            'id' => (int) $existing['id'],
            'name' => (string) ($existing['name'] ?? $name),
            'status' => (string) ($existing['status'] ?? $status),
            'scope' => $scope
        ];
    }

    if ($scope === 'global') {
        $stmt = $pdo->prepare("INSERT INTO expense_categories (name, name_key, global_key, scope, created_by_user_id, status, usage_count, is_active)
                               VALUES (?, ?, ?, 'global', ?, ?, 0, 1)");
        $stmt->execute([$name, $key, $key, $createdBy, $status]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO expense_categories (name, name_key, global_key, scope, created_by_user_id, status, usage_count, is_active)
                               VALUES (?, ?, NULL, 'user', ?, ?, 0, 1)");
        $stmt->execute([$name, $key, $createdBy, $status]);
    }
    return [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $name,
        'status' => $status,
        'scope' => $scope
    ];
}

function listExpenseCategoriesForUser($userId): array {
    global $pdo;
    ensureExpenseCategoriesSchema();

    $userId = (int) $userId;
    $stmt = $pdo->prepare("SELECT *
                           FROM expense_categories
                           WHERE is_active = 1
                             AND (
                                (scope = 'global' AND status = 'approved')
                                OR
                                (scope = 'global' AND created_by_user_id = ? AND status = 'pending')
                                OR
                                (scope = 'user' AND created_by_user_id = ? AND status IN ('approved','pending'))
                             )
                           ORDER BY (last_used_at IS NULL) ASC, last_used_at DESC, usage_count DESC, name ASC");
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

function getPersonalExpenseTypeOptionsForUser($userId): array {
    $rows = listExpenseCategoriesForUser($userId);
    $out = [];
    foreach ($rows as $r) {
        $name = (string) ($r['name'] ?? '');
        $key = expenseCategoryKey($name);
        if ($name === '' || $key === 'other' || $key === 'client expense') continue;
        $out[] = $name;
    }
    $fallback = ['Travel', 'Food', 'Supplies', 'Marketing', 'Communication', 'Fuel', 'Internet'];
    foreach ($fallback as $f) {
        if (!in_array($f, $out, true)) $out[] = $f;
    }
    return $out;
}

function incrementExpenseCategoryUsageForUser($userId, $categoryName): void {
    global $pdo;
    ensureExpenseCategoriesSchema();

    $userId = (int) $userId;
    $name = normalizeExpenseCategoryName($categoryName);
    if ($name === '') return;
    $key = expenseCategoryKey($name);
    if ($key === 'other' || $key === 'client expense') return;

    $stmt = $pdo->prepare("SELECT id FROM expense_categories
                           WHERE is_active = 1 AND scope = 'user' AND created_by_user_id = ? AND name_key = ?
                           LIMIT 1");
    $stmt->execute([$userId, $key]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt = $pdo->prepare("SELECT id FROM expense_categories
                               WHERE is_active = 1 AND scope = 'global' AND global_key = ?
                               LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
    }
    if (!$row) return;

    $stmt = $pdo->prepare("UPDATE expense_categories
                           SET usage_count = usage_count + 1, last_used_at = NOW()
                           WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $row['id']]);
}

function ensureEventProfitFeedbackIncentiveSchema(): void {
    global $pdo;
    if (!$pdo) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_other_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        label VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_other_costs_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_employee_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_employee_payment (event_id, user_id),
        INDEX idx_event_employee_payments_event (event_id),
        INDEX idx_event_employee_payments_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_financials (
        event_id INT NOT NULL PRIMARY KEY,
        budget DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
        employee_payments DECIMAL(12,2) NOT NULL DEFAULT 0,
        other_costs DECIMAL(12,2) NOT NULL DEFAULT 0,
        net_profit DECIMAL(12,2) NOT NULL DEFAULT 0,
        profit_status ENUM('profit','loss') NOT NULL DEFAULT 'profit',
        computed_at TIMESTAMP NULL,
        INDEX idx_event_financials_status (profit_status),
        INDEX idx_event_financials_profit (net_profit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_feedback_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        token CHAR(64) NOT NULL,
        channel ENUM('whatsapp','sms','manual') NOT NULL DEFAULT 'manual',
        recipient VARCHAR(120) NULL,
        status ENUM('sent','received','expired') NOT NULL DEFAULT 'sent',
        sent_at TIMESTAMP NULL,
        received_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_feedback_token (token),
        INDEX idx_feedback_req_event (event_id),
        INDEX idx_feedback_req_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        token CHAR(64) NULL,
        rating TINYINT NOT NULL,
        message TEXT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        source ENUM('client','admin') NOT NULL DEFAULT 'client',
        INDEX idx_feedback_event (event_id),
        INDEX idx_feedback_rating (rating)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS event_incentives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        month CHAR(7) NOT NULL,
        rating TINYINT NULL,
        net_profit DECIMAL(12,2) NOT NULL DEFAULT 0,
        incentive_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('earned','paid','cancelled') NOT NULL DEFAULT 'earned',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event_incentive (event_id, user_id),
        INDEX idx_incentives_month_user (month, user_id),
        INDEX idx_incentives_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_monthly_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        month CHAR(7) NOT NULL,
        score INT NOT NULL DEFAULT 0,
        grade VARCHAR(30) NOT NULL DEFAULT 'Needs Improvement',
        events_handled INT NOT NULL DEFAULT 0,
        profitable_events INT NOT NULL DEFAULT 0,
        avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
        incentives_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
        computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_score_user_month (user_id, month),
        INDEX idx_score_month (month),
        INDEX idx_score_score (score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_monthly_rankings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        month CHAR(7) NOT NULL,
        user_id INT NOT NULL,
        rank_pos INT NOT NULL DEFAULT 0,
        score INT NOT NULL DEFAULT 0,
        incentives_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
        avg_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
        computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rank_month_user (month, user_id),
        INDEX idx_rank_month (month),
        INDEX idx_rank_pos (rank_pos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_outbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('whatsapp','sms') NOT NULL,
        recipient VARCHAR(120) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL,
        error TEXT NULL,
        INDEX idx_comm_status (status),
        INDEX idx_comm_channel (channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getIncentiveAmountForRating($rating): float {
    $r = (int) $rating;
    if ($r >= 5) return 5000.0;
    if ($r === 4) return 3000.0;
    if ($r === 3) return 1000.0;
    return 0.0;
}

function computeEventFinancials($eventId): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();
    ensureExpenseCategorizationSchema();

    $eventId = (int) $eventId;
    $stmt = $pdo->prepare("SELECT id, budget FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        return ['event_id' => $eventId, 'budget' => 0, 'total_expenses' => 0, 'employee_payments' => 0, 'other_costs' => 0, 'net_profit' => 0, 'profit_status' => 'loss'];
    }

    $budget = (float) ($event['budget'] ?? 0);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ex.amount), 0) as total
                           FROM event_expenses ee
                           JOIN expenses ex ON ex.id = ee.expense_id
                           WHERE ee.event_id = ?
                             AND ex.status = 'approved'
                             AND COALESCE(ex.expense_category, 'personal') = 'client'");
    $stmt->execute([$eventId]);
    $totalExpenses = (float) (($stmt->fetch()['total'] ?? 0));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM event_employee_payments WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $employeePayments = (float) (($stmt->fetch()['total'] ?? 0));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM event_other_costs WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $otherCosts = (float) (($stmt->fetch()['total'] ?? 0));

    $netProfit = $budget - $totalExpenses - $employeePayments - $otherCosts;
    $profitStatus = $netProfit >= 0 ? 'profit' : 'loss';

    $stmt = $pdo->prepare("INSERT INTO event_financials (event_id, budget, total_expenses, employee_payments, other_costs, net_profit, profit_status, computed_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE
                            budget = VALUES(budget),
                            total_expenses = VALUES(total_expenses),
                            employee_payments = VALUES(employee_payments),
                            other_costs = VALUES(other_costs),
                            net_profit = VALUES(net_profit),
                            profit_status = VALUES(profit_status),
                            computed_at = NOW()");
    $stmt->execute([$eventId, $budget, $totalExpenses, $employeePayments, $otherCosts, $netProfit, $profitStatus]);

    return [
        'event_id' => $eventId,
        'budget' => $budget,
        'total_expenses' => $totalExpenses,
        'employee_payments' => $employeePayments,
        'other_costs' => $otherCosts,
        'net_profit' => $netProfit,
        'profit_status' => $profitStatus
    ];
}

function getLatestEventFeedback($eventId): ?array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();
    $stmt = $pdo->prepare("SELECT * FROM event_feedback WHERE event_id = ? ORDER BY submitted_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int) $eventId]);
    $row = $stmt->fetch();
    return $row ? $row : null;
}

function createFeedbackRequest($eventId, $channel, $recipient): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();

    $eventId = (int) $eventId;
    $channel = strtolower(trim((string) $channel));
    if (!in_array($channel, ['whatsapp', 'sms', 'manual'], true)) $channel = 'manual';
    $recipient = trim((string) $recipient);

    $token = hash('sha256', random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO event_feedback_requests (event_id, token, channel, recipient, status, sent_at)
                           VALUES (?, ?, ?, ?, 'sent', NOW())");
    $stmt->execute([$eventId, $token, $channel, $recipient !== '' ? $recipient : null]);

    $link = rtrim(SITE_URL, '/\\') . '/feedback.php?t=' . $token;
    $message = "Thank you for choosing us. Please rate your event experience (1-5 stars): " . $link;

    // Fetch event name for WhatsApp template
    $evNameForMsg = '';
    try {
        $stmtEn = $pdo->prepare("SELECT name FROM events WHERE id = ? LIMIT 1");
        $stmtEn->execute([$eventId]);
        $evNameForMsg = (string)(($stmtEn->fetch()['name'] ?? ''));
    } catch (PDOException $e) {}

    if ($channel === 'whatsapp' && $recipient !== '' && wa_isTriggerEnabled('feedback_request')) {
        sendWhatsAppMessage($recipient, 'feedback_request', [
            $evNameForMsg ?: ('Event #' . $eventId),
            $link
        ], ['related_type' => 'event', 'related_id' => $eventId]);
    } elseif (in_array($channel, ['whatsapp', 'sms'], true) && $recipient !== '') {
        $stmt = $pdo->prepare("INSERT INTO comm_outbox (channel, recipient, message, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$channel, $recipient, $message]);
    }

    return ['token' => $token, 'link' => $link];
}

function submitEventFeedbackByToken($token, $rating, $message): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();

    $token = trim((string) $token);
    $rating = (int) $rating;
    $message = trim((string) $message);
    if ($token === '') throw new RuntimeException('Invalid token.');
    if ($rating < 1 || $rating > 5) throw new RuntimeException('Invalid rating.');

    $stmt = $pdo->prepare("SELECT * FROM event_feedback_requests WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $req = $stmt->fetch();
    if (!$req) throw new RuntimeException('Feedback request not found.');
    if (($req['status'] ?? '') === 'expired') throw new RuntimeException('Feedback link expired.');

    $eventId = (int) ($req['event_id'] ?? 0);
    if ($eventId < 1) throw new RuntimeException('Invalid event.');

    $stmt = $pdo->prepare("INSERT INTO event_feedback (event_id, token, rating, message, source) VALUES (?, ?, ?, ?, 'client')");
    $stmt->execute([$eventId, $token, $rating, $message !== '' ? $message : null]);

    $stmt = $pdo->prepare("UPDATE event_feedback_requests SET status = 'received', received_at = NOW() WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);

    generateIncentivesForEvent($eventId);

    return ['event_id' => $eventId, 'rating' => $rating];
}

function generateIncentivesForEvent($eventId): void {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();

    $eventId = (int) $eventId;
    $eventName = '';
    try {
        $stmt = $pdo->prepare("SELECT name FROM events WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $evn = $stmt->fetch();
        $eventName = (string) ($evn['name'] ?? '');
    } catch (PDOException $e) {}

    $financials = computeEventFinancials($eventId);
    $profit = (float) ($financials['net_profit'] ?? 0);
    if ($profit <= 0) return;

    $fb = getLatestEventFeedback($eventId);
    if (!$fb) return;
    $rating = (int) ($fb['rating'] ?? 0);
    $amount = getIncentiveAmountForRating($rating);
    if ($amount <= 0) return;

    $stmt = $pdo->prepare("SELECT end_date FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $ev = $stmt->fetch();
    $month = $ev && !empty($ev['end_date']) ? date('Y-m', strtotime((string) $ev['end_date'])) : getCurrentMonth();

    $stmt = $pdo->prepare("SELECT user_id FROM event_team WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $team = $stmt->fetchAll();
    if (empty($team)) return;

    foreach ($team as $t) {
        $uid = (int) ($t['user_id'] ?? 0);
        if ($uid < 1) continue;
        $stmt2 = $pdo->prepare("INSERT IGNORE INTO event_incentives (event_id, user_id, month, rating, net_profit, incentive_amount, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'earned')");
        $stmt2->execute([$eventId, $uid, $month, $rating, $profit, $amount]);

        if ($stmt2->rowCount() > 0) {
            try {
                $stmtP = $pdo->prepare("SELECT phone, user_id FROM employees WHERE user_id = ? LIMIT 1");
                $stmtP->execute([$uid]);
                $pRow = $stmtP->fetch();
                $phone = trim((string) ($pRow['phone'] ?? ''));
                // Fetch employee name
                $empName = '';
                try {
                    $stmtN = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
                    $stmtN->execute([$uid]);
                    $empName = (string)(($stmtN->fetch()['name'] ?? ''));
                } catch (PDOException $e) {}
                if ($phone !== '') {
                    // Try WhatsApp first; fall back to comm_outbox SMS
                    if (wa_isTriggerEnabled('incentive_earned')) {
                        sendWhatsAppMessage($phone, 'incentive_earned', [
                            $empName ?: ('Employee #' . $uid),
                            formatCurrency($amount),
                            $eventName !== '' ? $eventName : ('#' . $eventId),
                            (string)$rating
                        ], ['related_type' => 'event', 'related_id' => $eventId, 'user_id' => $uid]);
                    } else {
                        $msg = "Incentive earned: " . formatCurrency($amount) . ". Event: " . ($eventName !== '' ? $eventName : ('#' . $eventId)) . ". Rating: " . $rating . "/5.";
                        $stmtM = $pdo->prepare("INSERT INTO comm_outbox (channel, recipient, message, status) VALUES ('sms', ?, ?, 'pending')");
                        $stmtM->execute([$phone, $msg]);
                    }
                }
            } catch (PDOException $e) {}
        }
    }

    recomputeMonthlyScoresAndRankings($month);
}

function getEmployeeEventMetricsForMonth($userId, $month): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();

    $userId = (int) $userId;
    $month = substr((string) $month, 0, 7);

    $stmt = $pdo->prepare("SELECT e.id, e.end_date
                           FROM events e
                           JOIN event_team et ON et.event_id = e.id
                           WHERE et.user_id = ?
                             AND e.status = 'completed'
                             AND DATE_FORMAT(e.end_date, '%Y-%m') = ?");
    $stmt->execute([$userId, $month]);
    $events = $stmt->fetchAll();
    $eventIds = array_map(function($r){ return (int) ($r['id'] ?? 0); }, $events);
    $eventIds = array_values(array_filter($eventIds, function($v){ return $v > 0; }));
    $eventsHandled = count($eventIds);

    $avgRating = 0.0;
    $ratingCount = 0;
    $profitableEvents = 0;
    $profitMarginSum = 0.0;

    foreach ($eventIds as $eid) {
        $fin = computeEventFinancials($eid);
        $budget = (float) ($fin['budget'] ?? 0);
        $profit = (float) ($fin['net_profit'] ?? 0);
        if ($profit > 0) {
            $profitableEvents++;
        }
        if ($budget > 0) {
            $profitMarginSum += max(0.0, min(1.0, $profit / $budget));
        }

        $fb = getLatestEventFeedback($eid);
        if ($fb && isset($fb['rating'])) {
            $avgRating += (float) ($fb['rating'] ?? 0);
            $ratingCount++;
        }
    }

    $avgRating = $ratingCount > 0 ? round($avgRating / $ratingCount, 2) : 0.0;
    $avgProfitMargin = $eventsHandled > 0 ? round($profitMarginSum / $eventsHandled, 4) : 0.0;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(incentive_amount), 0) as total
                           FROM event_incentives
                           WHERE user_id = ? AND month = ? AND status IN ('earned','paid')");
    $stmt->execute([$userId, $month]);
    $incentivesEarned = (float) (($stmt->fetch()['total'] ?? 0));

    return [
        'events_handled' => $eventsHandled,
        'profitable_events' => $profitableEvents,
        'avg_rating' => $avgRating,
        'avg_profit_margin' => $avgProfitMargin,
        'incentives_earned' => $incentivesEarned
    ];
}

function computeEmployeeMonthlyScore($userId, $month): array {
    ensurePerformanceSchema();
    ensureEventProfitFeedbackIncentiveSchema();

    $month = substr((string) $month, 0, 7);
    $perf = getEmployeeMonthlyPerformance((int) $userId, $month);
    $m = getEmployeeEventMetricsForMonth((int) $userId, $month);

    $avgRating = (float) ($m['avg_rating'] ?? 0);
    $eventsHandled = (int) ($m['events_handled'] ?? 0);
    $profitableEvents = (int) ($m['profitable_events'] ?? 0);
    $avgProfitMargin = (float) ($m['avg_profit_margin'] ?? 0);
    $incentivesEarned = (float) ($m['incentives_earned'] ?? 0);

    $attendancePercent = (float) ($perf['attendance_percent'] ?? 0);
    $attendancePoints = max(0.0, min(15.0, ($attendancePercent / 100.0) * 15.0));

    $completedTasks = (int) ($perf['tasks_completed'] ?? 0);
    $inProgressTasks = (int) ($perf['tasks_in_progress'] ?? 0);
    $taskDen = max(1, $completedTasks + $inProgressTasks);
    $taskPoints = max(0.0, min(15.0, ($completedTasks / $taskDen) * 15.0));

    $ratingPoints = max(0.0, min(30.0, ($avgRating / 5.0) * 30.0));
    $profitabilityPoints = $eventsHandled > 0 ? max(0.0, min(25.0, ($profitableEvents / $eventsHandled) * 25.0)) : 0.0;
    $budgetPoints = max(0.0, min(15.0, $avgProfitMargin * 15.0));

    $score = (int) round($ratingPoints + $profitabilityPoints + $budgetPoints + $attendancePoints + $taskPoints);
    $score = max(0, min(100, $score));

    $grade = 'Needs Improvement';
    if ($score >= 90) $grade = 'Excellent';
    elseif ($score >= 75) $grade = 'Very Good';
    elseif ($score >= 60) $grade = 'Good';

    return [
        'month' => $month,
        'user_id' => (int) $userId,
        'score' => $score,
        'grade' => $grade,
        'events_handled' => $eventsHandled,
        'profitable_events' => $profitableEvents,
        'avg_rating' => $avgRating,
        'incentives_earned' => $incentivesEarned
    ];
}

function recomputeMonthlyScoresAndRankings($month): void {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();

    $month = substr((string) $month, 0, 7);
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'employee'");
    $employees = $stmt->fetchAll();

    $rows = [];
    foreach ($employees as $e) {
        $uid = (int) ($e['id'] ?? 0);
        if ($uid < 1) continue;
        $s = computeEmployeeMonthlyScore($uid, $month);
        $stmt2 = $pdo->prepare("INSERT INTO employee_monthly_scores (user_id, month, score, grade, events_handled, profitable_events, avg_rating, incentives_earned)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    score = VALUES(score),
                                    grade = VALUES(grade),
                                    events_handled = VALUES(events_handled),
                                    profitable_events = VALUES(profitable_events),
                                    avg_rating = VALUES(avg_rating),
                                    incentives_earned = VALUES(incentives_earned),
                                    computed_at = NOW()");
        $stmt2->execute([$uid, $month, (int) $s['score'], (string) $s['grade'], (int) $s['events_handled'], (int) $s['profitable_events'], (float) $s['avg_rating'], (float) $s['incentives_earned']]);
        $rows[] = $s;
    }

    usort($rows, function($a, $b) {
        if (($b['score'] ?? 0) !== ($a['score'] ?? 0)) return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if (($b['incentives_earned'] ?? 0) !== ($a['incentives_earned'] ?? 0)) return ($b['incentives_earned'] ?? 0) <=> ($a['incentives_earned'] ?? 0);
        if (($b['avg_rating'] ?? 0) !== ($a['avg_rating'] ?? 0)) return ($b['avg_rating'] ?? 0) <=> ($a['avg_rating'] ?? 0);
        return ($a['user_id'] ?? 0) <=> ($b['user_id'] ?? 0);
    });

    $rank = 1;
    foreach ($rows as $r) {
        $stmt3 = $pdo->prepare("INSERT INTO employee_monthly_rankings (month, user_id, rank_pos, score, incentives_earned, avg_rating)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    rank_pos = VALUES(rank_pos),
                                    score = VALUES(score),
                                    incentives_earned = VALUES(incentives_earned),
                                    avg_rating = VALUES(avg_rating),
                                    computed_at = NOW()");
        $stmt3->execute([$month, (int) $r['user_id'], $rank, (int) $r['score'], (float) $r['incentives_earned'], (float) $r['avg_rating']]);
        $rank++;
    }
}

function getEmployeeRankingForMonth($userId, $month): ?array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();
    $month = substr((string) $month, 0, 7);
    $stmt = $pdo->prepare("SELECT * FROM employee_monthly_rankings WHERE month = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$month, (int) $userId]);
    $r = $stmt->fetch();
    return $r ? $r : null;
}

function getTopEmployeeRankings($month, $limit = 5): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();
    $month = substr((string) $month, 0, 7);
    $limit = max(1, min(25, (int) $limit));
    $stmt = $pdo->prepare("SELECT r.*, u.name, u.email, e.designation
                           FROM employee_monthly_rankings r
                           JOIN users u ON u.id = r.user_id
                           LEFT JOIN employees e ON e.user_id = u.id
                           WHERE r.month = ?
                           ORDER BY r.rank_pos ASC
                           LIMIT $limit");
    $stmt->execute([$month]);
    return $stmt->fetchAll();
}

function getCompanyProfitSummaryForMonth($month): array {
    global $pdo;
    ensureEventProfitFeedbackIncentiveSchema();
    $month = substr((string) $month, 0, 7);

    $stmt = $pdo->prepare("SELECT id FROM events WHERE status = 'completed' AND DATE_FORMAT(end_date, '%Y-%m') = ?");
    $stmt->execute([$month]);
    $events = $stmt->fetchAll();

    $totalProfit = 0.0;
    $totalLoss = 0.0;
    $profitEvents = 0;
    $lossEvents = 0;

    foreach ($events as $e) {
        $eid = (int) ($e['id'] ?? 0);
        if ($eid < 1) continue;
        $fin = computeEventFinancials($eid);
        $p = (float) ($fin['net_profit'] ?? 0);
        if ($p >= 0) {
            $totalProfit += $p;
            $profitEvents++;
        } else {
            $totalLoss += abs($p);
            $lossEvents++;
        }
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(incentive_amount), 0) as total
                           FROM event_incentives
                           WHERE month = ? AND status IN ('earned','paid')");
    $stmt->execute([$month]);
    $incentives = (float) (($stmt->fetch()['total'] ?? 0));

    return [
        'month' => $month,
        'total_profit' => $totalProfit,
        'total_loss' => $totalLoss,
        'profit_events' => $profitEvents,
        'loss_events' => $lossEvents,
        'incentive_payouts' => $incentives
    ];
}

function ensureProjectExpenseReportsSchema() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS project_expense_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        client_id INT NULL,
        event_id INT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        summary TEXT NULL,
        remarks TEXT NULL,
        file_path VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        admin_comment TEXT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_project_reports_user (user_id),
        INDEX idx_project_reports_status (status),
        INDEX idx_project_reports_event (event_id),
        INDEX idx_project_reports_client (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureTaskWorkflowSchema() {
    global $pdo;
    ensurePerformanceSchema();

    try { $pdo->exec("ALTER TABLE employee_tasks ADD COLUMN description TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employee_tasks ADD COLUMN assigned_by INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employee_tasks ADD COLUMN client_id INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE employee_tasks ADD COLUMN event_id INT NULL"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE INDEX idx_employee_tasks_client ON employee_tasks(client_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_employee_tasks_event ON employee_tasks(event_id)"); } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task_updates_task (task_id),
        INDEX idx_task_updates_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getCurrentMonth() {
    return date('Y-m');
}

function getCurrentDate() {
    return date('Y-m-d');
}

function getDaysInMonth($month = null) {
    if (!$month) $month = getCurrentMonth();
    return date('t', strtotime($month . '-01'));
}

function calculateSalary($employeeId, $month = null) {
    global $pdo;
    
    if (!$month) $month = getCurrentMonth();
    $month = substr((string) $month, 0, 7);
    ensureLeaveRequestsSchema();
    ensureSalaryDeductionsSchema();
    ensureEmployeePolicySchema();
    
    // Get employee details
    $policy = getEmployeePolicyRow($employeeId);
    $baseSalary = (float) ($policy['salary'] ?? 0);
    $weeklyOffDayN = (int) ($policy['weekly_off_day'] ?? 7);
    $leaveQuota = (int) ($policy['monthly_leave_quota'] ?? 4);
    
    if ($baseSalary <= 0) {
        return [
            'base_salary' => 0,
            'earned_salary' => 0,
            'approved_expenses' => 0,
            'total_payable' => 0,
            'working_days' => 0,
            'present_days' => 0,
            'absent_days' => 0,
            'late_days' => 0,
            'approved_leaves' => 0,
            'remaining_leaves' => max(0, $leaveQuota),
            'weekly_offs' => 0,
            'deduction_days' => 0,
            'deduction_amount' => 0
        ];
    }

    $workingDays = getMonthlyWorkingDaysCount($month, $weeklyOffDayN);
    $perDaySalary = $workingDays > 0 ? ($baseSalary / $workingDays) : 0;
    $summary = getMonthlyPolicySummary($employeeId, $month);

    $presentDays = (int) ($summary['present_days'] ?? 0);
    $lateDays = (int) ($summary['late_days'] ?? 0);
    $absentDays = (int) ($summary['absent_days'] ?? 0);
    $approvedLeaves = (int) ($summary['approved_leaves'] ?? 0);
    $paidLeaves = (int) ($summary['paid_leaves'] ?? 0);
    $unpaidLeaves = (int) ($summary['unpaid_leaves'] ?? 0);
    $remainingLeaves = (int) ($summary['remaining_leaves'] ?? 0);
    $weeklyOffs = (int) ($summary['weekly_offs'] ?? 0);

    $deductionDays = max(0, $absentDays + $unpaidLeaves);
    $deductionAmount = $perDaySalary * $deductionDays;
    $earnedSalary = max(0, ($baseSalary - $deductionAmount));
    
    // Get approved expenses
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_expenses FROM expenses 
                          WHERE user_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ? 
                          AND status = 'approved'");
    $stmt->execute([(int) $employeeId, $month]);
    $expenses = $stmt->fetch();
    
    $approvedExpenses = $expenses['total_expenses'] ?: 0;

    ensureMonthlyDeductionsStored((int) $employeeId, $month, $perDaySalary, $summary);
    
    return [
        'base_salary' => $baseSalary,
        'earned_salary' => $earnedSalary,
        'approved_expenses' => $approvedExpenses,
        'total_payable' => $earnedSalary + $approvedExpenses,
        'working_days' => $workingDays,
        'present_days' => $presentDays,
        'late_days' => $lateDays,
        'absent_days' => $absentDays,
        'approved_leaves' => $approvedLeaves,
        'paid_leaves' => $paidLeaves,
        'unpaid_leaves' => $unpaidLeaves,
        'remaining_leaves' => $remainingLeaves,
        'weekly_offs' => $weeklyOffs,
        'deduction_days' => $deductionDays,
        'deduction_amount' => $deductionAmount,
        'daily' => $summary['daily'] ?? []
    ];
}

function ensurePerformanceSchema() {
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        due_at DATETIME NULL,
        status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_employee_tasks_user (user_id),
        INDEX idx_employee_tasks_user_status (user_id, status),
        INDEX idx_employee_tasks_user_due (user_id, due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_performance_monthly (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        month CHAR(7) NOT NULL,
        attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        attendance_points INT NOT NULL DEFAULT 0,
        tasks_completed INT NOT NULL DEFAULT 0,
        tasks_in_progress INT NOT NULL DEFAULT 0,
        task_points INT NOT NULL DEFAULT 0,
        events_participated INT NOT NULL DEFAULT 0,
        event_points INT NOT NULL DEFAULT 0,
        late_days INT NOT NULL DEFAULT 0,
        late_penalty INT NOT NULL DEFAULT 0,
        overdue_tasks INT NOT NULL DEFAULT 0,
        overdue_penalty INT NOT NULL DEFAULT 0,
        total_score INT NOT NULL DEFAULT 0,
        incentive_tier ENUM('none','basic','medium','high') NOT NULL DEFAULT 'none',
        incentive_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_month (user_id, month),
        INDEX idx_perf_month (month),
        INDEX idx_perf_score (total_score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getWorkingDaysInMonth($month) {
    return getMonthlyWorkingDaysCount($month, 7);
}

function getEmployeeMonthlyPerformance($userId, $month = null, $forceRecompute = false) {
    global $pdo;
    ensurePerformanceSchema();

    if (!$month) {
        $month = getCurrentMonth();
    }

    $userId = (int) $userId;
    $month = substr((string) $month, 0, 7);

    if (!$forceRecompute) {
        $stmt = $pdo->prepare("SELECT * FROM employee_performance_monthly WHERE user_id = ? AND month = ? LIMIT 1");
        $stmt->execute([$userId, $month]);
        $cached = $stmt->fetch();
        if ($cached) {
            $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, computed_at, NOW()) as age_min FROM employee_performance_monthly WHERE user_id = ? AND month = ? LIMIT 1");
            $stmt->execute([$userId, $month]);
            $ageRow = $stmt->fetch();
            $ageMin = (int) ($ageRow['age_min'] ?? 9999);
            if ($ageMin <= 30) {
                return $cached;
            }
        }
    }

    $workingDays = getWorkingDaysInMonth($month);

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT date) as present_days
                           FROM attendance
                           WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                           AND check_in IS NOT NULL");
    $stmt->execute([$userId, $month]);
    $presentDays = (int) (($stmt->fetch()['present_days'] ?? 0));

    $attendancePercent = $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 2) : 0;
    $attendancePoints = (int) round(min(100, $attendancePercent) * 20 / 100);

    $lateThreshold = '10:15:00';
    $stmt = $pdo->prepare("SELECT COUNT(*) as late_days
                           FROM attendance
                           WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                           AND check_in IS NOT NULL AND check_in > ?");
    $stmt->execute([$userId, $month, $lateThreshold]);
    $lateDays = (int) (($stmt->fetch()['late_days'] ?? 0));
    $latePenalty = -2 * $lateDays;

    $stmt = $pdo->prepare("SELECT COUNT(*) as completed_tasks
                           FROM employee_tasks
                           WHERE user_id = ? AND status = 'completed'
                           AND DATE_FORMAT(updated_at, '%Y-%m') = ?");
    $stmt->execute([$userId, $month]);
    $tasksCompleted = (int) (($stmt->fetch()['completed_tasks'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(*) as in_progress_tasks
                           FROM employee_tasks
                           WHERE user_id = ? AND status = 'in_progress'");
    $stmt->execute([$userId]);
    $tasksInProgress = (int) (($stmt->fetch()['in_progress_tasks'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(*) as overdue_tasks
                           FROM employee_tasks
                           WHERE user_id = ? AND status <> 'completed'
                           AND due_at IS NOT NULL
                           AND due_at < NOW()
                           AND DATE_FORMAT(due_at, '%Y-%m') = ?");
    $stmt->execute([$userId, $month]);
    $overdueTasks = (int) (($stmt->fetch()['overdue_tasks'] ?? 0));
    $overduePenalty = -5 * $overdueTasks;

    $taskPoints = ($tasksCompleted * 10) + min(10, $tasksInProgress) * 1;
    $taskPoints = min(40, $taskPoints);

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.id) as events_participated
                           FROM event_team et
                           JOIN events e ON e.id = et.event_id
                           WHERE et.user_id = ? AND DATE_FORMAT(e.start_date, '%Y-%m') = ?");
    $stmt->execute([$userId, $month]);
    $eventsParticipated = (int) (($stmt->fetch()['events_participated'] ?? 0));
    $eventPoints = min(30, $eventsParticipated * 15);

    $rawScore = $attendancePoints + $taskPoints + $eventPoints + $latePenalty + $overduePenalty;
    $totalScore = max(0, min(100, (int) round($rawScore)));

    $tier = 'none';
    if ($totalScore >= 90) $tier = 'high';
    elseif ($totalScore >= 75) $tier = 'medium';
    elseif ($totalScore >= 60) $tier = 'basic';

    $stmt = $pdo->prepare("SELECT salary FROM employees WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $employeeRow = $stmt->fetch();
    $baseSalary = (float) ($employeeRow['salary'] ?? 0);

    $rate = 0;
    if ($tier === 'high') $rate = 0.10;
    elseif ($tier === 'medium') $rate = 0.05;
    elseif ($tier === 'basic') $rate = 0.02;

    $incentiveAmount = round($baseSalary * $rate, 2);

    $stmt = $pdo->prepare("INSERT INTO employee_performance_monthly
        (user_id, month, attendance_percent, attendance_points, tasks_completed, tasks_in_progress, task_points, events_participated, event_points, late_days, late_penalty, overdue_tasks, overdue_penalty, total_score, incentive_tier, incentive_amount)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            attendance_percent = VALUES(attendance_percent),
            attendance_points = VALUES(attendance_points),
            tasks_completed = VALUES(tasks_completed),
            tasks_in_progress = VALUES(tasks_in_progress),
            task_points = VALUES(task_points),
            events_participated = VALUES(events_participated),
            event_points = VALUES(event_points),
            late_days = VALUES(late_days),
            late_penalty = VALUES(late_penalty),
            overdue_tasks = VALUES(overdue_tasks),
            overdue_penalty = VALUES(overdue_penalty),
            total_score = VALUES(total_score),
            incentive_tier = VALUES(incentive_tier),
            incentive_amount = VALUES(incentive_amount),
            computed_at = NOW()");
    $stmt->execute([
        $userId,
        $month,
        $attendancePercent,
        $attendancePoints,
        $tasksCompleted,
        $tasksInProgress,
        $taskPoints,
        $eventsParticipated,
        $eventPoints,
        $lateDays,
        $latePenalty,
        $overdueTasks,
        $overduePenalty,
        $totalScore,
        $tier,
        $incentiveAmount
    ]);

    $stmt = $pdo->prepare("SELECT * FROM employee_performance_monthly WHERE user_id = ? AND month = ? LIMIT 1");
    $stmt->execute([$userId, $month]);
    return $stmt->fetch() ?: [];
}

function ensurePasswordResetSchema(): void {
    global $pdo;
    if (!$pdo) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_reset_user (user_id),
        INDEX idx_password_reset_token (token_hash),
        INDEX idx_password_reset_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function generatePasswordResetToken(int $userId, int $expiryMinutes = 60): ?string {
    global $pdo;
    ensurePasswordResetSchema();

    try {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $tokenHash, $expiresAt]);

        return $rawToken;
    } catch (Exception $e) {
        return null;
    }
}

function validatePasswordResetToken(int $userId, string $token): bool {
    global $pdo;
    ensurePasswordResetSchema();

    try {
        $stmt = $pdo->prepare("SELECT id, token_hash, expires_at, used_at FROM password_resets 
                               WHERE user_id = ? AND used_at IS NULL 
                               ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $reset = $stmt->fetch();

        if (!$reset) {
            return false;
        }

        if (strtotime($reset['expires_at']) < time()) {
            return false;
        }

        if (!password_verify($token, $reset['token_hash'])) {
            return false;
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function usePasswordResetToken(int $userId, string $token, string $newPassword): bool {
    global $pdo;
    ensurePasswordResetSchema();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, token_hash, expires_at, used_at FROM password_resets 
                               WHERE user_id = ? AND used_at IS NULL 
                               ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $pdo->rollBack();
            return false;
        }

        if (strtotime($reset['expires_at']) < time()) {
            $pdo->rollBack();
            return false;
        }

        if (!password_verify($token, $reset['token_hash'])) {
            $pdo->rollBack();
            return false;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
        $stmt->execute([$hashedPassword, $userId]);

        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$reset['id']]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

?>
