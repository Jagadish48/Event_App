<?php
$pageTitle = 'Attendance Management';
require_once '../includes/header.php';
requireAdmin();

$success = '';
$error = '';

ensureLeaveRequestsSchema();
ensureEmployeePolicySchema();
ensureAttendancePolicySchema();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'edit') {
            try {
                $checkInRaw = clean_input($_POST['check_in']) ?: null;
                $checkInCmp = $checkInRaw;
                if (is_string($checkInCmp) && preg_match('/^\d{2}:\d{2}$/', $checkInCmp)) {
                    $checkInCmp .= ':00';
                }
                $newStatus = $checkInCmp ? (($checkInCmp > '12:00:00') ? 'late' : 'present') : 'absent';
                $statusNote = 'Updated by admin';

                $stmt = $pdo->prepare("UPDATE attendance
                                       SET check_in = ?,
                                           check_out = ?,
                                           latitude = ?,
                                           longitude = ?,
                                           check_in_notes = ?,
                                           check_out_notes = ?,
                                           attendance_status = ?,
                                           attendance_status_original = COALESCE(NULLIF(attendance_status_original, ''), COALESCE(NULLIF(attendance_status, ''), ?)),
                                           attendance_status_updated_at = NOW(),
                                           attendance_status_update_note = ?
                                       WHERE id = ?");
                $stmt->execute([
                    $checkInRaw,
                    clean_input($_POST['check_out']) ?: null,
                    clean_input($_POST['latitude']) ?: null,
                    clean_input($_POST['longitude']) ?: null,
                    clean_input($_POST['check_in_notes']),
                    clean_input($_POST['check_out_notes']),
                    $newStatus,
                    $newStatus,
                    $statusNote,
                    clean_input($_POST['attendance_id'])
                ]);
                $success = 'Attendance updated successfully!';
            } catch(PDOException $e) {
                $error = 'Error updating attendance: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
                $stmt->execute([clean_input($_POST['attendance_id'])]);
                $success = 'Attendance deleted successfully!';
            } catch(PDOException $e) {
                $error = 'Error deleting attendance: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'approve_leave') {
            try {
                $leaveId = (int) clean_input($_POST['leave_id'] ?? 0);
                if ($leaveId < 1) {
                    throw new RuntimeException('Invalid leave request.');
                }
                $stmt = $pdo->prepare("UPDATE leave_requests
                                       SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), admin_note = ?
                                       WHERE id = ? AND status = 'pending'");
                $stmt->execute([(int) $_SESSION['user_id'], clean_input($_POST['admin_note'] ?? ''), $leaveId]);
                $success = 'Leave request approved.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'reject_leave') {
            try {
                $leaveId = (int) clean_input($_POST['leave_id'] ?? 0);
                if ($leaveId < 1) {
                    throw new RuntimeException('Invalid leave request.');
                }
                $stmt = $pdo->prepare("UPDATE leave_requests
                                       SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_note = ?
                                       WHERE id = ? AND status = 'pending'");
                $stmt->execute([(int) $_SESSION['user_id'], clean_input($_POST['admin_note'] ?? ''), $leaveId]);
                $success = 'Leave request rejected.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'weekly_off_worked') {
            try {
                ensureAttendancePolicySchema();
                $employeeUserId = (int) clean_input($_POST['employee_user_id'] ?? 0);
                $date = clean_input($_POST['date'] ?? '');
                if ($employeeUserId < 1 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    throw new RuntimeException('Invalid weekly off request.');
                }

                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee' LIMIT 1");
                $stmt->execute([$employeeUserId]);
                if (!$stmt->fetch()) {
                    throw new RuntimeException('Employee not found.');
                }

                $policy = getEmployeePolicyRow($employeeUserId);
                $weeklyOffDayN = (int) ($policy['weekly_off_day'] ?? 7);
                $dayN = (int) date('N', strtotime($date));
                if ($dayN !== $weeklyOffDayN) {
                    throw new RuntimeException('Selected date is not the employee weekly off day.');
                }

                $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$employeeUserId, $date]);
                $row = $stmt->fetch();
                if ($row) {
                    $stmt = $pdo->prepare("UPDATE attendance
                                           SET weekly_off_worked = 1,
                                               attendance_status = 'present',
                                               attendance_status_original = COALESCE(NULLIF(attendance_status_original, ''), COALESCE(NULLIF(attendance_status, ''), 'present')),
                                               attendance_status_updated_at = NOW(),
                                               attendance_status_update_note = 'Weekly off worked',
                                               check_in_notes = COALESCE(NULLIF(check_in_notes,''), 'Weekly off worked')
                                           WHERE id = ? AND user_id = ?");
                    $stmt->execute([(int) $row['id'], $employeeUserId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, attendance_status, attendance_status_original, attendance_status_updated_at, attendance_status_update_note, weekly_off_worked, check_in_notes) VALUES (?, ?, 'present', 'present', NOW(), 'Weekly off worked', 1, ?)");
                    $stmt->execute([$employeeUserId, $date, 'Weekly off worked']);
                }

                $success = 'Marked as working on weekly off.';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filter_date = isset($_GET['date']) ? clean_input($_GET['date']) : '';
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();
$filter_status = isset($_GET['status']) ? clean_input($_GET['status']) : '';

    // Get attendance records
try {
    $query = "SELECT a.*, u.name, u.email, e.designation
              FROM attendance a
              JOIN (
                  SELECT user_id, date, MAX(id) as id
                  FROM attendance
                  GROUP BY user_id, date
              ) latest ON latest.id = a.id
              JOIN users u ON a.user_id = u.id
              LEFT JOIN employees e ON e.user_id = u.id
              WHERE 1=1";
    
    $params = [];
    
    if ($filter_date !== '') {
        $query .= " AND a.date = ?";
        $params[] = $filter_date;
    } elseif ($filter_from !== '' || $filter_to !== '') {
        if ($filter_from !== '') {
            $query .= " AND a.date >= ?";
            $params[] = $filter_from;
        }
        if ($filter_to !== '') {
            $query .= " AND a.date <= ?";
            $params[] = $filter_to;
        }
    } elseif ($filter_month) {
        $query .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
        $params[] = $filter_month;
    }
    
    if ($filter_employee) {
        $query .= " AND a.user_id = ?";
        $params[] = $filter_employee;
    }

    if ($filter_status !== '') {
        if ($filter_status === 'present') {
            $query .= " AND a.check_in IS NOT NULL";
        } elseif ($filter_status === 'absent') {
            $query .= " AND a.check_in IS NULL";
        } elseif ($filter_status === 'late') {
            $query .= " AND a.check_in IS NOT NULL AND a.check_in > '12:00:00'";
        } elseif ($filter_status === 'checked_out') {
            $query .= " AND a.check_out IS NOT NULL";
        } elseif ($filter_status === 'active_session') {
            $query .= " AND a.check_in IS NOT NULL AND a.check_out IS NULL";
        }
    }
    
    $query .= " ORDER BY a.date DESC, a.check_in DESC, a.id DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendanceRecords = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching attendance: ' . $e->getMessage();
    $attendanceRecords = [];
}

try {
    $monthKey = substr((string) $filter_month, 0, 7);
    $stmt = $pdo->prepare("SELECT lr.*, u.name, u.email
                           FROM leave_requests lr
                           JOIN users u ON u.id = lr.user_id
                           WHERE lr.status = 'pending'
                             AND (DATE_FORMAT(lr.from_date, '%Y-%m') = ? OR DATE_FORMAT(lr.to_date, '%Y-%m') = ?)
                           ORDER BY lr.created_at DESC, lr.id DESC");
    $stmt->execute([$monthKey, $monthKey]);
    $pendingLeaveRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    $pendingLeaveRequests = [];
}

// Get employees for filter dropdown + monthly summaries
$employees = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation
                        FROM users u
                        LEFT JOIN employees e ON e.user_id = u.id
                        WHERE u.role = 'employee'
                        ORDER BY u.name");
    $employees = $stmt->fetchAll();
} catch(PDOException $e) {
    $employees = [];
}

$monthlyEmployeeSummaries = [];
try {
    foreach (($employees ?? []) as $emp) {
        $uid = (int) ($emp['id'] ?? 0);
        if ($uid < 1) continue;
        $sal = calculateSalary($uid, $filter_month);
        $monthlyEmployeeSummaries[] = [
            'id' => $uid,
            'name' => (string) ($emp['name'] ?? ''),
            'designation' => (string) ($emp['designation'] ?? ''),
            'present_days' => (int) ($sal['present_days'] ?? 0),
            'late_days' => (int) ($sal['late_days'] ?? 0),
            'absent_days' => (int) ($sal['absent_days'] ?? 0),
            'approved_leaves' => (int) ($sal['approved_leaves'] ?? 0),
            'remaining_leaves' => (int) ($sal['remaining_leaves'] ?? 0),
            'weekly_offs' => (int) ($sal['weekly_offs'] ?? 0),
            'deduction_amount' => (float) ($sal['deduction_amount'] ?? 0),
        ];
    }
} catch (Exception $e) {
    $monthlyEmployeeSummaries = [];
}

// Get attendance for editing
$editAttendance = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT a.*, u.name, u.email 
                              FROM attendance a 
                              JOIN users u ON a.user_id = u.id 
                              WHERE a.id = ?");
        $stmt->execute([clean_input($_GET['edit'])]);
        $editAttendance = $stmt->fetch();
    } catch(PDOException $e) {
        $error = 'Error fetching attendance record: ' . $e->getMessage();
    }
}

// Calculate today's statistics
$today = date('Y-m-d');
$todayStats = [
    'present_today' => 0,
    'checked_in' => 0,
    'checked_out' => 0,
    'absent_today' => 0
];

try {
    $stmt = $pdo->prepare("SELECT a.*, u.role
                          FROM attendance a
                          JOIN users u ON a.user_id = u.id
                          WHERE a.date = ? AND u.role = 'employee'");
    $stmt->execute([$today]);
    $todayAttendance = $stmt->fetchAll();
    
    foreach ($todayAttendance as $record) {
        if (!empty($record['check_in'])) {
            $todayStats['present_today']++;
            if (!empty($record['check_out'])) {
                $todayStats['checked_out']++;
            } else {
                $todayStats['checked_in']++;
            }
        } elseif (!empty($record['attendance_status']) && $record['attendance_status'] === 'absent') {
            $todayStats['absent_today']++;
        }
    }
} catch (PDOException $e) {
    // Ignore errors for today stats
}

// Calculate overall statistics
$stats = [
    'total_present' => 0,
    'total_late' => 0,
    'total_absent' => 0,
    'avg_hours' => 0
];

if (!empty($attendanceRecords)) {
    $presentUsers = [];
    $lateUsers = [];
    foreach ($attendanceRecords as $record) {
        if ($record['check_in']) {
            $presentUsers[(int) $record['user_id']] = true;

            if (strtotime($record['check_in']) > strtotime('12:00:00')) {
                $lateUsers[(int) $record['user_id']] = true;
            }

            if ($record['check_out']) {
                $checkIn = strtotime($record['check_in']);
                $checkOut = strtotime($record['check_out']);
                $hours = ($checkOut - $checkIn) / 3600;
                $stats['avg_hours'] += $hours;
            }
        } else {
            $stats['total_absent']++;
        }
    }

    $stats['total_present'] = count($presentUsers);
    $stats['total_late'] = count($lateUsers);
    
    $sessionCountForAvg = 0;
    foreach ($attendanceRecords as $record) {
        if ($record['check_in'] && $record['check_out']) {
            $sessionCountForAvg++;
        }
    }
    if ($sessionCountForAvg > 0) {
        $stats['avg_hours'] = round($stats['avg_hours'] / $sessionCountForAvg, 2);
    }
}
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Admin</div>
            <div class="sidebar-subtitle">Manage events & teams</div>
        </div>

        <div class="sidebar-quick">
            <a class="btn btn-secondary btn-sm" href="events.php?open=add">
                <i class="fas fa-plus me-2"></i>Event
            </a>
            <a class="btn btn-primary btn-sm" href="employees.php?open=add">
                <i class="fas fa-user-plus me-2"></i>Employee
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav flex-column">
            <div class="nav-section-title">Overview</div>
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <div class="nav-section-title">Operations</div>
            <a class="nav-link" href="events.php">
                <i class="fas fa-calendar-check"></i> Events
            </a>
            <a class="nav-link" href="employees.php">
                <i class="fas fa-users"></i> Employees
            </a>
            <a class="nav-link" href="attendance.php">
                <i class="fas fa-clock"></i> Attendance
            </a>

            <div class="nav-section-title">Sales</div>
            <a class="nav-link" href="leads.php">
                <i class="fas fa-handshake"></i> Leads
            </a>
            <a class="nav-link" href="clients.php">
                <i class="fas fa-building"></i> Clients
            </a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php">
                <i class="fas fa-money-bill-wave"></i> Expenses
            </a>
            <a class="nav-link" href="project_expense_reports.php">
                <i class="fas fa-file-excel"></i> Project Reports
            </a>
            <a class="nav-link" href="payroll.php">
                <i class="fas fa-calculator"></i> Payroll
            </a>

            <div class="nav-section-title">Analytics</div>
            <a class="nav-link" href="reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a>

            <div class="nav-section-title">Communication</div>
            <a class="nav-link" href="whatsapp.php">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php">
                <i class="fas fa-gear"></i> Settings
            </a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Attendance</h1>
            <div class="page-subtitle">Review attendance sessions, locations, and proof images</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" onclick="exportAttendance()">
                <i class="fas fa-download me-2"></i>Export
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="employees.php"><i class="fas fa-users me-2"></i>Employees</a>
        <a class="btn btn-secondary" href="reports.php"><i class="fas fa-chart-bar me-2"></i>Reports</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Leave Approvals</h5>
            <span class="badge bg-warning"><?php echo (int) count($pendingLeaveRequests); ?> pending</span>
        </div>
        <div class="card-body">
            <?php if (empty($pendingLeaveRequests)): ?>
                <p class="text-muted mb-0">No pending leave requests for the selected month.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Reason</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingLeaveRequests as $lr): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string) ($lr['name'] ?? '')); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string) ($lr['email'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo formatDate($lr['from_date']); ?></td>
                                    <td><?php echo formatDate($lr['to_date']); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($lr['reason'] ?? '')); ?></td>
                                    <td><?php echo !empty($lr['created_at']) ? htmlspecialchars(date('d M Y', strtotime((string) $lr['created_at']))) : '-'; ?></td>
                                    <td>
                                        <div class="table-action-group">
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="approve_leave">
                                                <input type="hidden" name="leave_id" value="<?php echo (int) $lr['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                                            </form>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="reject_leave">
                                                <input type="hidden" name="leave_id" value="<?php echo (int) $lr['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-xmark"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Monthly Attendance & Deductions</h5>
            <span class="text-muted small"><?php echo date('F Y', strtotime($filter_month . '-01')); ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($monthlyEmployeeSummaries)): ?>
                <p class="text-muted mb-0">No employee summary available.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm" data-smart-table data-export-name="attendance_policy_summary.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Designation</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Leaves</th>
                                <th>Remaining</th>
                                <th>Weekly Off</th>
                                <th>Deductions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyEmployeeSummaries as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></td>
                                    <td><?php echo htmlspecialchars($r['designation'] !== '' ? $r['designation'] : '—'); ?></td>
                                    <td><span class="badge bg-success"><?php echo (int) $r['present_days']; ?></span></td>
                                    <td><span class="badge bg-warning"><?php echo (int) $r['late_days']; ?></span></td>
                                    <td><span class="badge bg-danger"><?php echo (int) $r['absent_days']; ?></span></td>
                                    <td><span class="badge bg-primary"><?php echo (int) $r['approved_leaves']; ?></span></td>
                                    <td><span class="badge bg-info"><?php echo (int) $r['remaining_leaves']; ?></span></td>
                                    <td><span class="badge bg-purple"><?php echo (int) $r['weekly_offs']; ?></span></td>
                                    <td class="text-danger fw-semibold"><?php echo formatCurrency((float) $r['deduction_amount']); ?></td>
                                    <td>
                                        <div class="table-action-group">
                                            <button class="btn btn-sm btn-secondary" type="button" onclick="openWeeklyOffWorkedModal(<?php echo (int) $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
                                                <i class="fas fa-calendar-check"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $todayStats['present_today']; ?></div>
                <div class="stat-label">Present Today</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-sign-in-alt"></i>
                </div>
                <div class="stat-value"><?php echo $todayStats['checked_in']; ?></div>
                <div class="stat-label">Checked In</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <div class="stat-value"><?php echo $todayStats['checked_out']; ?></div>
                <div class="stat-label">Checked Out</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-value"><?php echo $todayStats['absent_today']; ?></div>
                <div class="stat-label">Absent Today</div>
            </div>
        </div>
    </div>
    
    <!-- Overall Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_present']; ?></div>
                <div class="stat-label">Present Sessions</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_late']; ?></div>
                <div class="stat-label">Late Arrivals</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_absent']; ?></div>
                <div class="stat-label">Absent</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value"><?php echo $stats['avg_hours']; ?>h</div>
                <div class="stat-label">Avg Hours</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Filters</h5>
            <a href="attendance.php" class="btn btn-sm btn-secondary"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label">Employee</label>
                        <select class="form-select js-searchable-select" name="employee">
                            <option value="">All</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int) $emp['id']; ?>" <?php echo (string) $filter_employee === (string) $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?><?php if ($emp['designation']) echo ' (' . htmlspecialchars($emp['designation']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All</option>
                            <option value="present" <?php echo $filter_status === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="absent" <?php echo $filter_status === 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="late" <?php echo $filter_status === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="active_session" <?php echo $filter_status === 'active_session' ? 'selected' : ''; ?>>Active Session</option>
                            <option value="checked_out" <?php echo $filter_status === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Month</label>
                        <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                        <a href="attendance.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Records -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Attendance Records</h5>
        </div>
        <div class="card-body">
            <?php if (empty($attendanceRecords)): ?>
                <p class="text-muted">No attendance records found for the selected criteria.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="attendanceTable" data-smart-table data-export-name="attendance_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Date</th>
                                <th>Session</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Hours</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendanceRecords as $record): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($record['name']); ?></strong>
                                            <?php if ($record['designation']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($record['designation']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo formatDate($record['date']); ?></td>
                                    <td><?php echo !empty($record['session_no']) ? (int) $record['session_no'] : '-'; ?></td>
                                    <td>
                                        <?php echo !empty($record['check_in']) ? substr((string) $record['check_in'], 0, 5) : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td>
                                        <?php echo $record['check_out'] ? substr($record['check_out'], 0, 5) : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($record['total_hours'])) {
                                            echo $record['total_hours'] . 'h';
                                        } elseif ($record['check_in'] && $record['check_out']) {
                                            $checkIn = strtotime($record['check_in']);
                                            $checkOut = strtotime($record['check_out']);
                                            $hours = round(($checkOut - $checkIn) / 3600, 2);
                                            echo $hours . 'h';
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $statusKey = strtolower(trim((string) ($record['attendance_status'] ?? '')));
                                            if (!empty($record['check_in']) && empty($record['check_out'])) {
                                                echo '<span class="badge bg-warning text-dark">Checked In</span>';
                                            } elseif (!empty($record['check_in']) && !empty($record['check_out'])) {
                                                echo '<span class="badge bg-success">Completed</span>';
                                            } else {
                                                echo $statusKey !== '' ? renderAttendanceStatusBadgeFromKey($statusKey) : renderAttendanceStatusBadge($record['check_in'] ?? null);
                                            }
                                        ?>
                                        <?php
                                            $orig = strtolower(trim((string) ($record['attendance_status_original'] ?? '')));
                                            if ($orig === 'absent' && ($statusKey === 'present' || $statusKey === 'late')): ?>
                                            <div class="text-muted small mt-1">Updated after check-in.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['latitude'] && $record['longitude']): ?>
                                            <a href="https://maps.google.com/?q=<?php echo $record['latitude']; ?>,<?php echo $record['longitude']; ?>" 
                                               target="_blank" class="text-primary">
                                                <i class="fas fa-map-marker-alt"></i> In
                                            </a>
                                        <?php elseif (!empty($record['check_out_latitude']) && !empty($record['check_out_longitude'])): ?>
                                            <a href="https://maps.google.com/?q=<?php echo $record['check_out_latitude']; ?>,<?php echo $record['check_out_longitude']; ?>" 
                                               target="_blank" class="text-primary">
                                                <i class="fas fa-map-marker-alt"></i> Out
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="editAttendance(<?php echo $record['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php
                                            $checkInInfo = resolveUploadPathInfo($record['image'] ?? '');
                                            $checkOutInfo = resolveUploadPathInfo($record['check_out_image'] ?? '');
                                        ?>
                                        <?php if (!empty($record['image'])): ?>
                                            <button class="btn btn-sm btn-info" onclick="viewImage(this)"
                                                data-image-url="<?php echo htmlspecialchars($checkInInfo['url']); ?>"
                                                data-image-debug="<?php echo htmlspecialchars($checkInInfo['debug']); ?>"
                                                data-image-exists="<?php echo $checkInInfo['exists'] ? '1' : '0'; ?>">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($record['check_out_image'])): ?>
                                            <button class="btn btn-sm btn-info" onclick="viewImage(this)"
                                                data-image-url="<?php echo htmlspecialchars($checkOutInfo['url']); ?>"
                                                data-image-debug="<?php echo htmlspecialchars($checkOutInfo['debug']); ?>"
                                                data-image-exists="<?php echo $checkOutInfo['exists'] ? '1' : '0'; ?>">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteAttendance(<?php echo $record['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Attendance Modal -->
<div class="modal fade" id="editAttendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="attendance_id" id="edit_attendance_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Check In Time</label>
                                <input type="time" class="form-control" name="check_in" id="edit_check_in">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Check Out Time</label>
                                <input type="time" class="form-control" name="check_out" id="edit_check_out">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Latitude</label>
                                <input type="text" class="form-control" name="latitude" id="edit_latitude" step="any">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Longitude</label>
                                <input type="text" class="form-control" name="longitude" id="edit_longitude" step="any">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Check In Notes</label>
                        <textarea class="form-control" name="check_in_notes" id="edit_check_in_notes" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Check Out Notes</label>
                        <textarea class="form-control" name="check_out_notes" id="edit_check_out_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Attendance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Attendance Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid" alt="Attendance Image">
                <div id="imageMissing" class="text-muted" style="display:none;">
                    Image not found on server.
                </div>
                <div id="imageDebug" class="text-muted small mt-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="attendance_id" id="delete_attendance_id">
</form>

<!-- Weekly Off Worked Modal -->
<div class="modal fade" id="weeklyOffWorkedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Working on Weekly Off</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="weekly_off_worked">
                <input type="hidden" name="employee_user_id" id="weekly_off_employee_user_id">
                <div class="modal-body">
                    <div class="mb-2 text-muted small" id="weekly_off_employee_name"></div>
                    <div class="mb-3">
                        <label class="form-label">Date (weekly off) *</label>
                        <input type="date" class="form-control" name="date" id="weekly_off_date" required>
                        <div class="form-text text-muted">This only works for the employee’s configured weekly off day (default Sunday).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle me-2"></i>Mark Worked</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function editAttendance(attendanceId) {
    window.location.href = 'attendance.php?edit=' + attendanceId;
}

function deleteAttendance(attendanceId) {
    customConfirm('Are you sure you want to delete this attendance record?', function() {
        document.getElementById('delete_attendance_id').value = attendanceId;
        document.getElementById('deleteForm').submit();
    });
}

function viewImage(elOrUrl) {
    const img = document.getElementById('modalImage');
    const debugEl = document.getElementById('imageDebug');
    const missingEl = document.getElementById('imageMissing');
    const isEl = typeof elOrUrl === 'object' && elOrUrl && elOrUrl.dataset;
    const url = isEl ? elOrUrl.dataset.imageUrl : elOrUrl;
    const debug = isEl ? elOrUrl.dataset.imageDebug : url;
    const exists = isEl ? elOrUrl.dataset.imageExists : '1';

    debugEl.textContent = debug || '';
    missingEl.style.display = (exists === '0') ? 'block' : 'none';
    img.style.display = (exists === '0') ? 'none' : 'block';

    img.onload = function() {
        missingEl.style.display = 'none';
        img.style.display = 'block';
    };
    img.onerror = function() {
        img.style.display = 'none';
        missingEl.style.display = 'block';
    };
    img.src = url || '';
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

function exportAttendance() {
    exportToCSV('attendanceTable', 'attendance_export.csv');
}

function openWeeklyOffWorkedModal(userId, name) {
    const idEl = document.getElementById('weekly_off_employee_user_id');
    const nameEl = document.getElementById('weekly_off_employee_name');
    const dateEl = document.getElementById('weekly_off_date');
    if (idEl) idEl.value = userId;
    if (nameEl) nameEl.textContent = name || '';
    if (dateEl) dateEl.value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('weeklyOffWorkedModal')).show();
}

// Load edit data if available
" . ($editAttendance ? "
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_attendance_id').value = '" . $editAttendance['id'] . "';
    document.getElementById('edit_check_in').value = '" . substr($editAttendance['check_in'] ?? '', 0, 5) . "';
    document.getElementById('edit_check_out').value = '" . substr($editAttendance['check_out'] ?? '', 0, 5) . "';
    document.getElementById('edit_latitude').value = '" . $editAttendance['latitude'] . "';
    document.getElementById('edit_longitude').value = '" . $editAttendance['longitude'] . "';
    document.getElementById('edit_check_in_notes').value = '" . addslashes($editAttendance['check_in_notes'] ?? '') . "';
    document.getElementById('edit_check_out_notes').value = '" . addslashes($editAttendance['check_out_notes'] ?? '') . "';
    
    // Show edit modal
    new bootstrap.Modal(document.getElementById('editAttendanceModal')).show();
});
" : "") . "

</script>
";
require_once '../includes/footer.php';
?>
