<?php
/**
 * API: Authentication
 * POST /api/auth/login   — Login with email+password, returns token
 * POST /api/auth/logout  — Invalidate current token
 * GET  /api/auth/me      — Get current user info
 */

$action = $segments[1] ?? 'login';

switch ($action) {
    // ── Login ───────────────────────────────────────────────────
    case 'login':
        if ($requestMethod !== 'POST') apiError('Use POST for login.', 405);

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $email    = trim((string) ($body['email'] ?? ($_POST['email'] ?? '')));
        $password = (string) ($body['password'] ?? ($_POST['password'] ?? ''));
        $tokenName = trim((string) ($body['token_name'] ?? ($_POST['token_name'] ?? 'mobile')));

        if ($email === '' || $password === '') {
            apiError('email and password are required.', 422);
        }

        try {
            $stmt = $pdo->prepare("SELECT id, name, email, role, password FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) ($user['password'] ?? ''))) {
                apiError('Invalid email or password.', 401);
            }

            // Generate token
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token, name, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
            $stmt->execute([(int) $user['id'], $token, $tokenName]);

            apiResponse([
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_in' => 30 * 24 * 3600,
                'user'       => [
                    'id'    => (int) $user['id'],
                    'name'  => $user['name'],
                    'email' => $user['email'],
                    'role'  => normalizeUserRole($user['role']),
                ],
            ], 'Login successful.');
        } catch (PDOException $e) {
            apiError('Database error during login.', 500);
        }
        break;

    // ── Logout ──────────────────────────────────────────────────
    case 'logout':
        $user = requireAuth();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = trim(str_replace('Bearer ', '', $authHeader));
        try {
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
        } catch (PDOException $e) {}
        apiResponse(null, 'Logged out successfully.');
        break;

    // ── Current user ────────────────────────────────────────────
    case 'me':
        $user = requireAuth();
        try {
            $stmt = $pdo->prepare("SELECT e.id as employee_id, e.designation, e.department, e.salary, u.phone, u.address
                FROM employees e JOIN users u ON u.id = e.user_id WHERE e.user_id = ? LIMIT 1");
            $stmt->execute([(int) $user['id']]);
            $emp = $stmt->fetch();
        } catch (PDOException $e) {
            $emp = null;
        }
        apiResponse([
            'id'          => (int) $user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'],
            'role'        => normalizeUserRole($user['role'] ?? 'employee'),
            'employee_id' => $emp ? (int) $emp['employee_id'] : null,
            'designation' => $emp['designation'] ?? null,
            'department'  => $emp['department'] ?? null,
            'phone'       => $emp['phone'] ?? null,
        ]);
        break;

    default:
        apiError("Unknown auth action: $action", 404);
}
