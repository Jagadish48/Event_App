<?php
/**
 * API: Attendance
 * GET  /api/attendance                — Get attendance (own or all if admin)
 * POST /api/attendance/checkin        — Mark check-in
 * POST /api/attendance/checkout       — Mark check-out
 * POST /api/attendance/absent         — Mark absent
 * GET  /api/attendance/status         — Get today's attendance status
 * GET  /api/attendance/summary        — Monthly summary
 */

$user = requireAuth();
$role = normalizeUserRole($user['role'] ?? 'employee');
$userId = (int) $user['id'];

$action = $segments[1] ?? '';

switch ($requestMethod) {
    case 'GET':
        switch ($action) {
            case 'status':
                // Today's status
                try {
                    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$userId]);
                    $today = $stmt->fetch();

                    $status = 'not_started';
                    $canCheckIn = true;
                    $canCheckOut = false;

                    if ($today) {
                        if (!empty($today['check_in']) && empty($today['check_out'])) {
                            $status = 'checked_in';
                            $canCheckIn = false;
                            $canCheckOut = true;
                        } elseif (!empty($today['check_in']) && !empty($today['check_out'])) {
                            $status = 'checked_out';
                            $canCheckIn = true; // multiple sessions allowed
                            $canCheckOut = false;
                        } elseif (($today['attendance_status'] ?? '') === 'absent') {
                            $status = 'absent';
                            $canCheckIn = false;
                            $canCheckOut = false;
                        }
                    }

                    apiResponse([
                        'date'        => date('Y-m-d'),
                        'status'      => $status,
                        'can_checkin' => $canCheckIn,
                        'can_checkout'=> $canCheckOut,
                        'check_in'    => $today['check_in'] ?? null,
                        'check_out'   => $today['check_out'] ?? null,
                    ]);
                } catch (PDOException $e) {
                    apiError('Database error.', 500);
                }
                break;

            case 'summary':
                $month = $_GET['month'] ?? date('Y-m');
                try {
                    $summary = getMonthlyPolicySummary($userId, $month);
                    apiResponse($summary);
                } catch (Exception $e) {
                    apiError('Could not get summary.', 500);
                }
                break;

            default:
                // List attendance records
                $month = $_GET['month'] ?? date('Y-m');
                $empUserId = $userId;
                if ($role === 'admin' && isset($_GET['user_id'])) {
                    $empUserId = (int) $_GET['user_id'];
                }
                try {
                    $stmt = $pdo->prepare("SELECT id, user_id, date, check_in, check_out,
                        ROUND(COALESCE(TIMESTAMPDIFF(SECOND, check_in, check_out), 0) / 3600, 2) as hours_worked,
                        attendance_status, image, latitude, longitude, session_no
                        FROM attendance
                        WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                        ORDER BY date DESC, session_no ASC");
                    $stmt->execute([$empUserId, substr($month, 0, 7)]);
                    $records = $stmt->fetchAll();
                    apiResponse($records, '', true, 200, ['month' => $month, 'count' => count($records)]);
                } catch (PDOException $e) {
                    apiError('Database error.', 500);
                }
        }
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $type = trim((string) ($body['type'] ?? $action));

        switch ($type) {
            case 'checkin':
            case 'check_in':
                try {
                    // Check for active session
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL");
                    $stmt->execute([$userId]);
                    $active = $stmt->fetch();
                    if ((int) ($active['cnt'] ?? 0) > 0) {
                        apiError('You already have an active check-in session. Please check out first.', 409);
                    }

                    $latitude  = (string) ($body['latitude'] ?? '');
                    $longitude = (string) ($body['longitude'] ?? '');
                    $address   = (string) ($body['address'] ?? '');
                    $checkInTime = date('H:i:s');
                    $status = $checkInTime > '12:00:00' ? 'late' : 'present';

                    // Count sessions
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE user_id = ? AND date = CURDATE()");
                    $stmt->execute([$userId]);
                    $sc = $stmt->fetch();
                    $sessionNo = (int) ($sc['cnt'] ?? 0) + 1;

                    $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, latitude, longitude, address, attendance_status, session_no) VALUES (?, CURDATE(), CURRENT_TIME(), ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $latitude ?: null, $longitude ?: null, $address ?: null, $status, $sessionNo]);

                    apiResponse([
                        'status'     => $status,
                        'check_in'   => date('H:i:s'),
                        'session_no' => $sessionNo,
                    ], $status === 'late' ? 'Checked in (Late — after 12:00 PM).' : 'Check-in successful.', true, 201);
                } catch (PDOException $e) {
                    apiError('Database error.', 500);
                }
                break;

            case 'checkout':
            case 'check_out':
                try {
                    $stmt = $pdo->prepare("SELECT id, check_in FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$userId]);
                    $active = $stmt->fetch();
                    if (!$active) apiError('No active check-in session found.', 404);

                    $latitude  = (string) ($body['latitude'] ?? '');
                    $longitude = (string) ($body['longitude'] ?? '');

                    $stmt = $pdo->prepare("UPDATE attendance SET check_out = CURRENT_TIME(), check_out_latitude = ?, check_out_longitude = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$latitude ?: null, $longitude ?: null, (int) $active['id'], $userId]);

                    $hoursWorked = 0;
                    if (!empty($active['check_in'])) {
                        $inTs  = strtotime(date('Y-m-d') . ' ' . $active['check_in']);
                        $outTs = time();
                        $hoursWorked = round(($outTs - $inTs) / 3600, 2);
                    }

                    apiResponse(['hours_worked' => $hoursWorked, 'check_out' => date('H:i:s')], 'Check-out successful.');
                } catch (PDOException $e) {
                    apiError('Database error.', 500);
                }
                break;

            case 'absent':
                try {
                    $stmt = $pdo->prepare("SELECT id, check_in FROM attendance WHERE user_id = ? AND date = CURDATE() ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$userId]);
                    $today = $stmt->fetch();
                    if ($today && !empty($today['check_in'])) {
                        apiError('Cannot mark absent after checking in.', 409);
                    }

                    $reason = strtolower(trim((string) ($body['reason'] ?? '')));
                    $description = trim((string) ($body['description'] ?? ''));
                    $allowedReasons = ['sick leave', 'personal work', 'emergency', 'family function', 'not available', 'other'];
                    if (!in_array($reason, $allowedReasons, true)) {
                        apiError('Invalid reason. Allowed: ' . implode(', ', $allowedReasons), 422);
                    }

                    if ($today) {
                        $stmt = $pdo->prepare("UPDATE attendance SET attendance_status = 'absent', absent_reason = ?, absent_description = ?, check_in_notes = 'Marked absent' WHERE id = ? AND user_id = ?");
                        $stmt->execute([$reason, $description, (int) $today['id'], $userId]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, attendance_status, absent_reason, absent_description, check_in_notes) VALUES (?, CURDATE(), 'absent', ?, ?, 'Marked absent')");
                        $stmt->execute([$userId, $reason, $description]);
                    }
                    apiResponse(null, 'Absence recorded.', true, 201);
                } catch (PDOException $e) {
                    apiError('Database error.', 500);
                }
                break;

            default:
                apiError("Unknown attendance action. Use: checkin, checkout, absent.", 400);
        }
        break;

    default:
        apiError('Method not allowed.', 405);
}
