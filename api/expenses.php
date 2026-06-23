<?php
/**
 * API: Expenses
 * GET  /api/expenses           — List expenses (own or all if admin)
 * POST /api/expenses           — Submit expense (employee)
 * PUT  /api/expenses/{id}      — Approve/reject expense (admin)
 * DELETE /api/expenses/{id}    — Delete expense (admin)
 */

$user = requireAuth();
$role = normalizeUserRole($user['role'] ?? 'employee');
$userId = (int) $user['id'];

switch ($requestMethod) {
    case 'GET':
        try {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;
            $status = $_GET['status'] ?? '';

            if ($role === 'admin') {
                $where = "WHERE 1=1";
                $params = [];
                if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
                    $where .= " AND e.status = ?";
                    $params[] = $status;
                }
                if ($resourceId) {
                    $where .= " AND e.id = ?";
                    $params[] = $resourceId;
                }
                $stmt = $pdo->prepare("SELECT e.*, u.name as employee_name
                    FROM expenses e JOIN users u ON u.id = e.user_id
                    $where ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset");
                $stmt->execute($params);
            } else {
                $where = "WHERE e.user_id = ?";
                $params = [$userId];
                if ($resourceId) {
                    $where .= " AND e.id = ?";
                    $params[] = $resourceId;
                }
                $stmt = $pdo->prepare("SELECT e.* FROM expenses e $where ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset");
                $stmt->execute($params);
            }
            $expenses = $stmt->fetchAll();

            if ($resourceId && count($expenses) === 1) {
                apiResponse($expenses[0]);
            }
            apiResponse($expenses, '', true, 200, ['page' => $page, 'per_page' => $perPage, 'count' => count($expenses)]);
        } catch (PDOException $e) {
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount      = (float) ($body['amount'] ?? 0);
        $type        = trim((string) ($body['type'] ?? ($body['expense_type'] ?? '')));
        $description = trim((string) ($body['description'] ?? ''));
        $expenseDate = trim((string) ($body['date'] ?? date('Y-m-d')));

        if ($amount <= 0) apiError('amount must be greater than 0.', 422);
        if ($type === '')  apiError('type (expense type) is required.', 422);

        try {
            $stmt = $pdo->prepare("INSERT INTO expenses (user_id, amount, type, description, date, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$userId, $amount, $type, $description, $expenseDate]);
            $id = (int) $pdo->lastInsertId();
            apiResponse(['id' => $id], 'Expense submitted for approval.', true, 201);
        } catch (PDOException $e) {
            apiError('Database error: ' . $e->getMessage(), 500);
        }
        break;

    case 'PUT':
    case 'PATCH':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        if (!$resourceId) apiError('Expense ID is required.', 422);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = trim((string) ($body['action'] ?? ($_GET['action'] ?? '')));
        $reason = trim((string) ($body['rejection_reason'] ?? ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            apiError("action must be 'approve' or 'reject'.", 422);
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ? LIMIT 1");
            $stmt->execute([$resourceId]);
            if (!$stmt->fetch()) apiError('Expense not found.', 404);

            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE expenses SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
            $stmt->execute([$newStatus, $userId, $reason ?: null, $resourceId]);
            apiResponse(null, "Expense {$newStatus}.");
        } catch (PDOException $e) {
            apiError('Database error.', 500);
        }
        break;

    case 'DELETE':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        if (!$resourceId) apiError('Expense ID is required.', 422);
        try {
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? LIMIT 1");
            $stmt->execute([$resourceId]);
            apiResponse(null, 'Expense deleted.');
        } catch (PDOException $e) {
            apiError('Database error.', 500);
        }
        break;

    default:
        apiError('Method not allowed.', 405);
}
