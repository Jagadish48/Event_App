<?php
/**
 * API: Leave Requests
 * GET  /api/leaves        — List leave requests (own or all if admin)
 * POST /api/leaves        — Submit leave request (employee)
 * PUT  /api/leaves/{id}   — Approve/reject leave (admin only)
 */

$user = requireAuth();
$role = normalizeUserRole($user['role'] ?? 'employee');
$userId = (int) $user['id'];

ensureLeaveRequestsSchema();

switch ($requestMethod) {
    case 'GET':
        try {
            $status = $_GET['status'] ?? '';
            if ($role === 'admin') {
                $where = "WHERE 1=1";
                $params = [];
                if ($status !== '' && in_array($status, ['pending','approved','rejected'], true)) {
                    $where .= " AND lr.status = ?";
                    $params[] = $status;
                }
                if ($resourceId) {
                    $where .= " AND lr.id = ?";
                    $params[] = $resourceId;
                }
                $stmt = $pdo->prepare("SELECT lr.*, u.name as employee_name, u.email as employee_email
                    FROM leave_requests lr JOIN users u ON u.id = lr.user_id
                    $where ORDER BY lr.created_at DESC LIMIT 100");
                $stmt->execute($params);
            } else {
                $where = "WHERE lr.user_id = ?";
                $params = [$userId];
                $stmt = $pdo->prepare("SELECT lr.* FROM leave_requests lr $where ORDER BY lr.created_at DESC LIMIT 50");
                $stmt->execute($params);
            }
            $leaves = $stmt->fetchAll();
            if ($resourceId && count($leaves) === 1) {
                apiResponse($leaves[0]);
            }
            apiResponse($leaves, '', true, 200, ['count' => count($leaves)]);
        } catch (PDOException $e) {
            apiError('Database error.', 500);
        }
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $fromDate = trim((string) ($body['from_date'] ?? ''));
        $toDate   = trim((string) ($body['to_date'] ?? $fromDate));
        $reason   = trim((string) ($body['reason'] ?? ''));

        if ($fromDate === '') apiError('from_date is required.', 422);
        if ($reason === '')   apiError('reason is required.', 422);

        try {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, from_date, to_date, reason, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$userId, $fromDate, $toDate, $reason]);
            apiResponse(['id' => (int) $pdo->lastInsertId()], 'Leave request submitted.', true, 201);
        } catch (PDOException $e) {
            apiError('Database error.', 500);
        }
        break;

    case 'PUT':
    case 'PATCH':
        if ($role !== 'admin') apiError('Forbidden.', 403);
        if (!$resourceId) apiError('Leave request ID is required.', 422);

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = trim((string) ($body['action'] ?? ($_GET['action'] ?? '')));
        $adminNote = trim((string) ($body['admin_note'] ?? ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            apiError("action must be 'approve' or 'reject'.", 422);
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM leave_requests WHERE id = ? LIMIT 1");
            $stmt->execute([$resourceId]);
            if (!$stmt->fetch()) apiError('Leave request not found.', 404);

            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ?");
            $stmt->execute([$newStatus, $userId, $adminNote ?: null, $resourceId]);
            apiResponse(null, "Leave request {$newStatus}.");
        } catch (PDOException $e) {
            apiError('Database error.', 500);
        }
        break;

    default:
        apiError('Method not allowed.', 405);
}
