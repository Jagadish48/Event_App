<?php
require_once __DIR__ . '/../config/database.php';
requireEmployee();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

function stampAttendanceImageOnTmp(string $tmpPath, array $lines): void {
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return;
    }

    $raw = @file_get_contents($tmpPath);
    if ($raw === false) {
        return;
    }

    if (!function_exists('imagecreatefromstring')) {
        return;
    }

    $im = @imagecreatefromstring($raw);
    if (!$im) {
        return;
    }

    $width = imagesx($im);
    $height = imagesy($im);

    // Compact design settings
    $font = 2; // Smaller font
    $padding = 6;
    $margin = 10;
    $lineGap = 2;
    $lines = array_values(array_filter(array_map(function ($v) {
        return trim((string) $v);
    }, $lines), function ($v) {
        return $v !== '';
    }));

    if (!$lines) {
        imagedestroy($im);
        return;
    }

    $lineHeight = imagefontheight($font);
    $maxTextWidth = 0;
    $maxBoxWidth = $width * 0.4; // Max 40% of image width
    $wrappedLines = [];
    
    // Wrap lines if they're too long
    foreach ($lines as $line) {
        $currentLine = '';
        $words = explode(' ', $line);
        foreach ($words as $word) {
            $testLine = $currentLine ? $currentLine . ' ' . $word : $word;
            $testWidth = imagefontwidth($font) * strlen($testLine);
            if ($testWidth > $maxBoxWidth - $padding * 2 && $currentLine) {
                $wrappedLines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        if ($currentLine) {
            $wrappedLines[] = $currentLine;
        }
    }

    // Recalculate max width for wrapped lines
    foreach ($wrappedLines as $line) {
        $w = imagefontwidth($font) * strlen($line);
        if ($w > $maxTextWidth) {
            $maxTextWidth = $w;
        }
    }

    $boxW = min($maxBoxWidth, $maxTextWidth + $padding * 2);
    $boxH = ($padding * 2) + (count($wrappedLines) * $lineHeight) + ((count($wrappedLines) - 1) * $lineGap);

    // Position at bottom-left corner with margin
    $x = $margin;
    $y = $height - $boxH - $margin;
    if ($y < $margin) {
        $y = $margin;
    }

    if (function_exists('imagealphablending') && function_exists('imagesavealpha')) {
        imagealphablending($im, true);
        imagesavealpha($im, true);
    }

    // Semi-transparent background (55% opacity)
    $bg = imagecolorallocatealpha($im, 0, 0, 0, 114); // Alpha: 0-127, 114 is ~55% opaque
    $text = imagecolorallocate($im, 255, 255, 255); // White text

    // Draw rounded rectangle (simulated with filled rectangle)
    imagefilledrectangle($im, $x, $y, $x + $boxW, $y + $boxH, $bg);

    // Draw text
    $cursorY = $y + $padding;
    foreach ($wrappedLines as $line) {
        imagestring($im, $font, $x + $padding, $cursorY, $line, $text);
        $cursorY += $lineHeight + $lineGap;
    }

    @imagejpeg($im, $tmpPath, 90);
    imagedestroy($im);
}

function ensureMultiSessionAttendanceSchema(): void {
    global $pdo;

    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN session_no INT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_image VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_in_notes TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_notes TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_latitude DECIMAL(10,8) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_longitude DECIMAL(11,8) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_original VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_updated_at DATETIME NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN attendance_status_update_note VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_reason VARCHAR(50) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_description TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_day VARCHAR(15) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN absent_time TIME NULL"); } catch (PDOException $e) {}
    // New fields for enhanced attendance
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN address TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_address TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN camera_type VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN check_out_camera_type VARCHAR(20) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE attendance ADD COLUMN total_hours DECIMAL(5,2) NULL"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE UNIQUE INDEX idx_attendance_user_date_unique ON attendance(user_id, date)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_attendance_user_date ON attendance(user_id, date)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_attendance_user_date_out ON attendance(user_id, date, check_out)"); } catch (PDOException $e) {}
}

try {
    ensureMultiSessionAttendanceSchema();
    
    // Debug: Log all incoming POST data and files
    error_log('ATTENDANCE DEBUG: $_POST = ' . print_r($_POST, true));
    error_log('ATTENDANCE DEBUG: $_FILES = ' . print_r($_FILES, true));
    error_log('ATTENDANCE DEBUG: $_SESSION[user_id] = ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    
    // Validate input
    if (!isset($_POST['type']) || !in_array($_POST['type'], ['check_in', 'check_out', 'absent'], true)) {
        error_log('ATTENDANCE DEBUG: Invalid attendance type');
        echo json_encode(['success' => false, 'error' => 'Invalid attendance type']);
        exit;
    }
    
    $type = clean_input($_POST['type']);
    $latitude = isset($_POST['latitude']) ? clean_input($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? clean_input($_POST['longitude']) : null;
    $address = isset($_POST['address']) ? clean_input($_POST['address']) : null;
    $camera_type = isset($_POST['camera_type']) ? clean_input($_POST['camera_type']) : null;
    
    error_log('ATTENDANCE DEBUG: Type: ' . $type . ', Lat: ' . $latitude . ', Lon: ' . $longitude);
    
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $todayRow = $stmt->fetch();

    $isMarkedAbsent = false;
    $todayStatusRaw = '';
    if ($todayRow) {
        $todayStatusRaw = strtolower(trim((string) ($todayRow['attendance_status'] ?? '')));
        $notesRaw = strtolower(trim((string) ($todayRow['check_in_notes'] ?? '')));
        $isMarkedAbsent = empty($todayRow['check_in']) && ($todayStatusRaw === 'absent' || $notesRaw === 'marked absent');
    }
    
    if ($type === 'absent') {
        if ($todayRow && !empty($todayRow['check_in']) && empty($todayRow['check_out'])) {
            echo json_encode(['success' => false, 'error' => 'You have an active session. Please check out first.']);
            exit;
        }

        if ($todayRow && !empty($todayRow['check_in'])) {
            echo json_encode(['success' => false, 'error' => 'Attendance already started for today. You cannot mark absent after checking in.']);
            exit;
        }

        $reason = strtolower(trim((string) ($_POST['reason'] ?? '')));
        $description = trim((string) ($_POST['description'] ?? ''));
        $allowedReasons = ['sick leave', 'personal work', 'emergency', 'family function', 'not available', 'other'];
        if ($reason === '' || !in_array($reason, $allowedReasons, true)) {
            echo json_encode(['success' => false, 'error' => 'Please select a valid reason.']);
            exit;
        }
        $descLen = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
        if ($reason === 'other' && $descLen < 3) {
            echo json_encode(['success' => false, 'error' => 'Please enter a short description for "Other".']);
            exit;
        }
        if ($descLen > 280) {
            echo json_encode(['success' => false, 'error' => 'Description is too long (max 280 characters).']);
            exit;
        }

        if ($isMarkedAbsent) {
            echo json_encode(['success' => true, 'message' => 'You are already marked absent for today.']);
            exit;
        }

        $day = date('l');
        $originalStatusToPersist = 'absent';
        if ($todayRow) {
            $existingOriginal = strtolower(trim((string) ($todayRow['attendance_status_original'] ?? '')));
            if ($existingOriginal !== '') {
                $originalStatusToPersist = $existingOriginal;
            } elseif ($todayStatusRaw !== '') {
                $originalStatusToPersist = $todayStatusRaw;
            }
        }
        if ($todayRow) {
            $stmt = $pdo->prepare("UPDATE attendance
                                   SET check_in = NULL,
                                       check_out = NULL,
                                       attendance_status = 'absent',
                                       attendance_status_original = ?,
                                       attendance_status_updated_at = NOW(),
                                       attendance_status_update_note = ?,
                                       absent_reason = ?,
                                       absent_description = ?,
                                       absent_day = ?,
                                       absent_time = CURRENT_TIME(),
                                       check_in_notes = ?,
                                       latitude = NULL,
                                       longitude = NULL
                                   WHERE id = ? AND user_id = ? AND date = CURDATE()
                                   LIMIT 1");
            $stmt->execute([$originalStatusToPersist, 'Marked absent', $reason, $description, $day, 'Marked absent', (int) ($todayRow['id'] ?? 0), $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, check_out, attendance_status, attendance_status_original, attendance_status_updated_at, attendance_status_update_note, absent_reason, absent_description, absent_day, absent_time, check_in_notes)
                                   VALUES (?, CURDATE(), NULL, NULL, 'absent', 'absent', NOW(), 'Marked absent', ?, ?, ?, CURRENT_TIME(), ?)");
            $stmt->execute([$_SESSION['user_id'], $reason, $description, $day, 'Marked absent']);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Absent marked successfully for today.'
        ]);
        exit;
    }

    if ($type === 'check_in') {
        // Check if already checked in today
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $todayAttendance = $stmt->fetch();
        
        if ($todayAttendance && !empty($todayAttendance['check_in'])) {
            echo json_encode(['success' => false, 'error' => 'You have already checked in today.']);
            exit;
        }
        
        // Handle check-in
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            error_log('ATTENDANCE DEBUG: Image uploaded, tmp name: ' . $_FILES['image']['tmp_name']);
            try {
                $stampLines = [];
                $stampLines[] = date('d M Y | h:i A');
                $stampLines[] = (string) ($_SESSION['name'] ?? '');
                if ($latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== '') {
                    $stampLines[] = 'Lat: ' . $latitude . '  Lng: ' . $longitude;
                }
                stampAttendanceImageOnTmp((string) $_FILES['image']['tmp_name'], $stampLines);
                $imagePath = uploadFile($_FILES['image'], 'attendance');
                error_log('ATTENDANCE DEBUG: Image saved to: ' . $imagePath);
            } catch (Exception $e) {
                error_log('ATTENDANCE DEBUG: Image upload exception: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
                echo json_encode(['success' => false, 'error' => 'Image upload failed: ' . $e->getMessage()]);
                exit;
            }
        } else {
            error_log('ATTENDANCE DEBUG: No image uploaded or error');
        }
        
        $checkInTime = date('H:i:s');
        $newStatus = ($checkInTime > '12:00:00') ? 'late' : 'present';
        $wasAbsent = $isMarkedAbsent || ($todayRow && empty($todayRow['check_in']) && $todayStatusRaw === 'absent');
        $statusLabel = $newStatus === 'late' ? 'Late' : 'Present';
        $notePrefix = $wasAbsent ? 'Attendance updated after check-in. ' : 'Check-in successful — ';
        $noteSuffix = $newStatus === 'late'
            ? 'Checked in after 12:00 PM — Marked Late.'
            : 'Checked in before 12:00 PM — Marked Present.';
        $statusNote = $notePrefix . $noteSuffix;

        $originalStatusToPersist = $newStatus;
        if ($todayRow) {
            $existingOriginal = strtolower(trim((string) ($todayRow['attendance_status_original'] ?? '')));
            if ($existingOriginal !== '') {
                $originalStatusToPersist = $existingOriginal;
            } elseif ($todayStatusRaw !== '') {
                $originalStatusToPersist = $todayStatusRaw;
            }
        }

        $sessionNo = 1;

        error_log('ATTENDANCE DEBUG: Before DB write - wasAbsent: ' . $wasAbsent . ', imagePath: ' . $imagePath . ', sessionNo: ' . $sessionNo);
        
        if ($todayAttendance) {
            // Update existing row
            $stmt = $pdo->prepare("UPDATE attendance
                                   SET check_in = CURRENT_TIME(),
                                       check_out = NULL,
                                       total_hours = NULL,
                                       image = ?,
                                       latitude = ?,
                                       longitude = ?,
                                       address = ?,
                                       camera_type = ?,
                                       check_in_notes = ?,
                                       attendance_status = ?,
                                       attendance_status_original = ?,
                                       attendance_status_updated_at = NOW(),
                                       attendance_status_update_note = ?,
                                       session_no = ?
                                   WHERE id = ? AND user_id = ? AND date = CURDATE()
                                   LIMIT 1");
            $stmt->execute([$imagePath, $latitude, $longitude, $address, $camera_type, $wasAbsent ? 'Attendance updated after check-in' : 'Mobile check-in', $newStatus, $originalStatusToPersist, $statusNote, $sessionNo, (int) ($todayAttendance['id'] ?? 0), $_SESSION['user_id']]);
            error_log('ATTENDANCE DEBUG: Updated existing record, affected rows: ' . $stmt->rowCount());
        } else {
            // Insert new row
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, image, latitude, longitude, address, camera_type, check_in_notes, attendance_status, attendance_status_original, attendance_status_updated_at, attendance_status_update_note, session_no)
                                   VALUES (?, CURDATE(), CURRENT_TIME(), ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $imagePath, $latitude, $longitude, $address, $camera_type, 'Mobile check-in', $newStatus, $newStatus, $statusNote, $sessionNo]);
            error_log('ATTENDANCE DEBUG: Inserted new record, last ID: ' . $pdo->lastInsertId());
        }
        
        echo json_encode([
            'success' => true,
            'message' => $statusNote,
            'status_key' => $newStatus,
            'status_label' => $statusLabel,
            'was_updated' => $wasAbsent ? 1 : 0
        ]);
        
    } elseif ($type === 'check_out') {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $activeSession = $stmt->fetch();

        if (!$activeSession) {
            echo json_encode(['success' => false, 'error' => 'No active session found. Please check in first.']);
            exit;
        }
        
        $checkOutImagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            try {
                $stampLines = [];
                $stampLines[] = date('d M Y | h:i A');
                $stampLines[] = (string) ($_SESSION['name'] ?? '');
                if ($latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== '') {
                    $stampLines[] = 'Lat: ' . $latitude . '  Lng: ' . $longitude;
                }
                stampAttendanceImageOnTmp((string) $_FILES['image']['tmp_name'], $stampLines);
                $checkOutImagePath = uploadFile($_FILES['image'], 'attendance');
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Image upload failed: ' . $e->getMessage()]);
                exit;
            }
        }
        
        // Calculate total hours
        $checkInTime = strtotime($activeSession['check_in']);
        $checkOutTime = time();
        $totalHours = round(($checkOutTime - $checkInTime) / 3600, 2);
        
        $stmt = $pdo->prepare("UPDATE attendance 
                               SET check_out = CURRENT_TIME(), 
                                   check_out_image = ?, 
                                   check_out_latitude = ?, 
                                   check_out_longitude = ?,
                                   check_out_address = ?,
                                   check_out_camera_type = ?,
                                   check_out_notes = ?,
                                   total_hours = ?
                               WHERE id = ? AND user_id = ? AND date = CURDATE()");
        $stmt->execute([$checkOutImagePath, $latitude, $longitude, $address, $camera_type, 'Mobile check-out', $totalHours, (int) $activeSession['id'], $_SESSION['user_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Check-out successful!']);
    }
    
} catch(PDOException $e) {
    error_log('ATTENDANCE DEBUG: PDO Exception: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    error_log('ATTENDANCE DEBUG: Exception: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>
