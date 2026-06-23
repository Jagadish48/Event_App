<?php
/**
 * API: Employees
 * GET /api/employees          — List employees (admin: all; employee: own profile)
 * GET /api/employees/{id}     — Get single employee
 * POST /api/employees         — Create employee (admin only)
 * PUT /api/employees/{id}     — Update employee (admin only)
 * DELETE /api/employees/{id}  — Delete employee (admin only)
 */

$user = requireAuth();
$role = normalizeUserRole($user['role'] ?? 'employee');

switch ($requestMethod) {
    // ── List / Single ────────────────────────────────────────────
    case 'GET':
        try {
            if ($resourceId) {
                // Single employee
                $stmt = $pdo->prepare("SELECT e.id, e.user_id, u.name, u.email, u.phone, u.address,
                    e.designation, e.department, e.joining_date, e.salary, e.employment_type,
                    u.role, e.verification_status
                    FROM employees e JOIN users u ON u.id = e.user_id
                    WHERE e.id = ? LIMIT 1");
                $stmt->execute([$resourceId]);
                $emp = $stmt->fetch();
                if (!$emp) apiError('Employee not found.', 404);
                // Non-admins can only view their own profile
                if ($role !== 'admin' && (int) $emp['user_id'] !== (int) $user['id']) {
                    apiError('Forbidden.', 403);
                }
                apiResponse($emp);
            } else {
                // List
                if ($role === 'admin') {
                    $stmt = $pdo->query("SELECT e.id, e.user_id, u.name, u.email, u.phone,
                        e.designation, e.department, e.joining_date, e.salary, e.employment_type,
                        u.role, e.verification_status
                        FROM employees e JOIN users u ON u.id = e.user_id
                        ORDER BY u.name ASC");
                    $employees = $stmt->fetchAll();
                    apiResponse($employees, '', true, 200, ['count' => count($employees)]);
                } else {
                    // Employee sees own data
                    $stmt = $pdo->prepare("SELECT e.id, e.user_id, u.name, u.email, u.phone, u.address,
                        e.designation, e.department, e.joining_date, e.employment_type
                        FROM employees e JOIN users u ON u.id = e.user_id WHERE u.id = ? LIMIT 1");
                    $stmt->execute([(int) $user['id']]);
                    $emp = $stmt->fetch();
                    apiResponse($emp ?: []);
                }
            }
        } catch (PDOException $e) {
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    // ── Create employee (admin only) ─────────────────────────────
    case 'POST':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name       = trim((string) ($body['name'] ?? ''));
        $email      = trim((string) ($body['email'] ?? ''));
        $password   = (string) ($body['password'] ?? '');
        $designation = trim((string) ($body['designation'] ?? ''));
        $department = trim((string) ($body['department'] ?? ''));
        $salary     = (float) ($body['salary'] ?? 0);

        if ($name === '' || $email === '' || $password === '') {
            apiError('name, email, and password are required.', 422);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'employee')");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO employees (user_id, designation, department, salary, joining_date) VALUES (?, ?, ?, ?, CURDATE())");
            $stmt->execute([$userId, $designation, $department, $salary]);
            $empId = (int) $pdo->lastInsertId();
            $pdo->commit();

            apiResponse(['id' => $empId, 'user_id' => $userId], 'Employee created.', true, 201);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                apiError('Email already exists.', 409);
            }
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    // ── Update employee (admin only) ─────────────────────────────
    case 'PUT':
    case 'PATCH':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        if (!$resourceId) apiError('Employee ID is required.', 422);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        try {
            $stmt = $pdo->prepare("SELECT e.id, e.user_id FROM employees e WHERE e.id = ? LIMIT 1");
            $stmt->execute([$resourceId]);
            $emp = $stmt->fetch();
            if (!$emp) apiError('Employee not found.', 404);

            if (isset($body['name']) || isset($body['email']) || isset($body['phone'])) {
                $fields = [];
                $vals = [];
                if (isset($body['name']))  { $fields[] = 'name = ?';  $vals[] = $body['name']; }
                if (isset($body['email'])) { $fields[] = 'email = ?'; $vals[] = $body['email']; }
                if (isset($body['phone'])) { $fields[] = 'phone = ?'; $vals[] = $body['phone']; }
                $vals[] = (int) $emp['user_id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($vals);
            }
            if (isset($body['designation']) || isset($body['department']) || isset($body['salary'])) {
                $fields = [];
                $vals = [];
                if (isset($body['designation'])) { $fields[] = 'designation = ?'; $vals[] = $body['designation']; }
                if (isset($body['department']))  { $fields[] = 'department = ?';  $vals[] = $body['department']; }
                if (isset($body['salary']))      { $fields[] = 'salary = ?';      $vals[] = (float) $body['salary']; }
                $vals[] = $resourceId;
                $pdo->prepare("UPDATE employees SET " . implode(', ', $fields) . " WHERE id = ?")->execute($vals);
            }
            apiResponse(null, 'Employee updated.');
        } catch (PDOException $e) {
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    // ── Delete employee (admin only) ─────────────────────────────
    case 'DELETE':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        if (!$resourceId) apiError('Employee ID is required.', 422);
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ? LIMIT 1");
            $stmt->execute([$resourceId]);
            $emp = $stmt->fetch();
            if (!$emp) apiError('Employee not found.', 404);

            $pdo->prepare("DELETE FROM employees WHERE id = ? LIMIT 1")->execute([$resourceId]);
            $pdo->prepare("UPDATE users SET role = 'employee' WHERE id = ? LIMIT 1")->execute([(int) $emp['user_id']]);
            apiResponse(null, 'Employee removed.');
        } catch (PDOException $e) {
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    default:
        apiError('Method not allowed.', 405);
}
