<?php
/**
 * attendance_submit.php — Legacy attendance endpoint (DEPRECATED)
 * This file is kept for backward compatibility only.
 * All new attendance submissions should use attendance_process.php
 * which uses PDO and the proper schema (user_id).
 */
require_once __DIR__ . '/../config/database.php';
requireEmployee();

header('Content-Type: application/json');

// Redirect to the proper PDO-based endpoint
// Just forward the request data to attendance_process.php logic

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$photo_data  = $_POST['photo'] ?? '';
$latitude    = $_POST['latitude'] ?? null;
$longitude   = $_POST['longitude'] ?? null;
$type        = $_POST['type'] ?? 'check_in'; // check_in or check_out

// Validate required fields
if (empty($latitude) || empty($longitude)) {
    echo json_encode(['success' => false, 'message' => 'Location is required to mark attendance']);
    exit;
}

try {
    // Process photo data if provided
    $imagePath = null;
    if (!empty($photo_data)) {
        $photo_data_clean = str_replace(['data:image/jpeg;base64,', 'data:image/png;base64,'], '', $photo_data);
        $photo_bytes = base64_decode($photo_data_clean);

        if ($photo_bytes !== false) {
            $uploadDir = __DIR__ . '/../uploads/attendance/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = 'attendance_' . $userId . '_' . date('Y-m-d_H-i-s') . '.jpg';
            $fullPath = $uploadDir . $filename;
            if (file_put_contents($fullPath, $photo_bytes) !== false) {
                $imagePath = 'uploads/attendance/' . $filename;
            }
        }
    }

    // Check today's attendance
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $todayRow = $stmt->fetch();

    if ($type === 'check_out') {
        // Find active session
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $activeRow = $stmt->fetch();

        if (!$activeRow) {
            echo json_encode(['success' => false, 'message' => 'No active check-in session found.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE attendance SET check_out = CURRENT_TIME(), check_out_image = ?, check_out_latitude = ?, check_out_longitude = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$imagePath, $latitude, $longitude, (int) $activeRow['id'], $userId]);

        echo json_encode(['success' => true, 'message' => 'Check-out successful!']);
    } else {
        // check_in
        // Check if active session exists
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL");
        $stmt->execute([$userId]);
        $activeCheck = $stmt->fetch();

        if ($activeCheck && (int) $activeCheck['cnt'] > 0) {
            echo json_encode(['success' => false, 'message' => 'You already have an active session. Please check out first.']);
            exit;
        }

        $checkInTime = date('H:i:s');
        $status = $checkInTime > '12:00:00' ? 'late' : 'present';

        // Count sessions for today
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE user_id = ? AND date = CURDATE()");
        $stmt->execute([$userId]);
        $sessionCount = $stmt->fetch();
        $sessionNo = (int) ($sessionCount['cnt'] ?? 0) + 1;

        // If there's a today row with no check_in (absent), update it
        if ($todayRow && empty($todayRow['check_in'])) {
            $stmt = $pdo->prepare("UPDATE attendance SET check_in = CURRENT_TIME(), image = ?, latitude = ?, longitude = ?, attendance_status = ?, session_no = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$imagePath, $latitude, $longitude, $status, $sessionNo, (int) $todayRow['id'], $userId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, image, latitude, longitude, attendance_status, session_no) VALUES (?, CURDATE(), CURRENT_TIME(), ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $imagePath, $latitude, $longitude, $status, $sessionNo]);
        }

        echo json_encode([
            'success' => true,
            'message' => $status === 'late' ? 'Checked in (Late — after 12:00 PM).' : 'Check-in successful — Present.',
            'status_key' => $status
        ]);
    }
} catch (PDOException $e) {
    error_log('attendance_submit.php PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
