<?php
/**
 * Network Events EMS — REST API Router
 * Base URL: http://localhost/Backup_Files/api/
 *
 * Authentication: Bearer token via Authorization header
 * All responses are JSON with {success, data, message, meta} structure.
 */

header('Content-Type: application/json; charset=utf-8');

// CORS: Restrict to same-origin or configured allowed origins
$allowedOrigins = [];
$envOrigins = trim((string) ($_ENV['API_ALLOWED_ORIGINS'] ?? getenv('API_ALLOWED_ORIGINS') ?: ''));
if ($envOrigins !== '') {
    $allowedOrigins = array_map('trim', explode(',', $envOrigins));
}
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
} elseif (empty($allowedOrigins)) {
    // If no origins are configured, allow same-origin only (no CORS header)
    // TODO(security): Configure API_ALLOWED_ORIGINS in .env for cross-origin clients
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// ─────────────────────────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────────────────────────
function apiResponse($data = null, $message = '', $success = true, $status = 200, $meta = []) {
    http_response_code($status);
    echo json_encode(array_filter([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
        'meta'    => $meta ?: null,
    ], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function apiError($message, $status = 400, $data = null) {
    apiResponse($data, $message, false, $status);
}

function getAuthUser(): ?array {
    global $pdo;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = '';
    if ($authHeader !== '') {
        $token = str_replace('Bearer ', '', $authHeader);
    }
    // NOTE: api_token via query param removed — tokens must be sent via Authorization header only
    $token = trim((string) $token);
    if ($token === '') return null;

    try {
        $stmt = $pdo->prepare("SELECT u.*, e.id as employee_id
            FROM api_tokens t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN employees e ON e.user_id = u.id
            WHERE t.token = ? AND (t.expires_at IS NULL OR t.expires_at > NOW())
            LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function requireAuth(): array {
    $user = getAuthUser();
    if (!$user) {
        apiError('Unauthorized. Please provide a valid Bearer token.', 401);
    }
    return $user;
}

function ensureApiTokensTable(): void {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(80) NULL,
            last_used_at TIMESTAMP NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_token (token),
            INDEX idx_api_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
}

// ─────────────────────────────────────────────────────────────────
// Route dispatcher
// ─────────────────────────────────────────────────────────────────
$requestUri    = $_SERVER['REQUEST_URI'] ?? '';
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Strip base path: /Backup_Files/api/ or /Backup_Files/api/index.php
$path = parse_url($requestUri, PHP_URL_PATH);
// Normalize: remove index.php if present, strip the api/ prefix
$path = preg_replace('#/index\.php$#', '/', $path);
// Find the 'api/' marker in the path and take everything after it
if (preg_match('#/api/(.*)$#', $path, $m)) {
    $path = $m[1];
} else {
    $path = '';
}
$path = trim($path, '/');
$segments = $path !== '' ? explode('/', $path) : [];

$endpoint = $segments[0] ?? '';
$resourceId = isset($segments[1]) && is_numeric($segments[1]) ? (int) $segments[1] : null;

ensureApiTokensTable();

// Route map
switch ($endpoint) {
    case '':
    case 'info':
        apiResponse([
            'name'     => 'Network Events EMS API',
            'version'  => '1.0.0',
            'endpoints' => [
                'POST /api/auth/login'          => 'Login and get token',
                'POST /api/auth/logout'         => 'Invalidate token',
                'GET  /api/employees'           => 'List all employees (admin)',
                'GET  /api/employees/{id}'      => 'Get single employee',
                'GET  /api/attendance'          => 'Get attendance records',
                'POST /api/attendance/checkin'  => 'Mark check-in',
                'POST /api/attendance/checkout' => 'Mark check-out',
                'GET  /api/expenses'            => 'List expenses',
                'POST /api/expenses'            => 'Submit expense',
                'PUT  /api/expenses/{id}'       => 'Approve/reject expense (admin)',
                'GET  /api/leaves'              => 'List leave requests',
                'POST /api/leaves'              => 'Submit leave request',
                'PUT  /api/leaves/{id}'         => 'Approve/reject leave (admin)',
                'GET  /api/dashboard'           => 'Dashboard summary stats',
            ],
        ], 'Network Events EMS REST API v1.0.0');
        break; // apiResponse calls exit(), but break is here for correctness

    case 'auth':
        require_once __DIR__ . '/auth.php';
        break;

    case 'employees':
        require_once __DIR__ . '/employees.php';
        break;

    case 'attendance':
        require_once __DIR__ . '/attendance.php';
        break;

    case 'expenses':
        require_once __DIR__ . '/expenses.php';
        break;

    case 'leaves':
        require_once __DIR__ . '/leaves.php';
        break;

    case 'dashboard':
        require_once __DIR__ . '/dashboard.php';
        break;

    default:
        apiError("Endpoint '$endpoint' not found. GET /api/info for documentation.", 404);
}
