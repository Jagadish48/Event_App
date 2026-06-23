<?php
$pageTitle = 'Attendance History';
require_once '../includes/header.php';
requireEmployee();

$success = '';
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_status = isset($_GET['status']) ? clean_input($_GET['status']) : '';

ensureLeaveRequestsSchema();
ensureEmployeePolicySchema();
ensureAttendancePolicySchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'request_leave')) {
    try {
        $fromDate = clean_input($_POST['from_date'] ?? '');
        $toDate = clean_input($_POST['to_date'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($fromDate === '' || $toDate === '' || $reason === '') {
            throw new RuntimeException('Please fill in all leave request fields.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            throw new RuntimeException('Invalid date format.');
        }
        if (strtotime($fromDate) === false || strtotime($toDate) === false) {
            throw new RuntimeException('Invalid date range.');
        }
        if (strtotime($fromDate) > strtotime($toDate)) {
            throw new RuntimeException('From date must be before To date.');
        }

        $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, from_date, to_date, reason, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([(int) $_SESSION['user_id'], $fromDate, $toDate, $reason]);
        $success = 'Leave request submitted successfully and is pending approval.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$policySummary = getMonthlyPolicySummary((int) $_SESSION['user_id'], $filter_month);
$salarySummary = calculateSalary((int) $_SESSION['user_id'], $filter_month);

try {
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE user_id = ? AND (DATE_FORMAT(from_date, '%Y-%m') = ? OR DATE_FORMAT(to_date, '%Y-%m') = ?) ORDER BY created_at DESC, id DESC");
    $stmt->execute([(int) $_SESSION['user_id'], substr((string) $filter_month, 0, 7), substr((string) $filter_month, 0, 7)]);
    $leaveRequests = $stmt->fetchAll();
} catch (PDOException $e) {
    $leaveRequests = [];
}

// Get attendance records
try {
    $query = "SELECT a.*
              FROM attendance a
              JOIN (
                  SELECT user_id, date, MAX(id) as id
                  FROM attendance
                  WHERE user_id = ?
                  GROUP BY user_id, date
              ) latest ON latest.id = a.id
              WHERE a.user_id = ?";
    $params = [$_SESSION['user_id'], $_SESSION['user_id']];

    if ($filter_from !== '' || $filter_to !== '') {
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

    if ($filter_status !== '') {
        if ($filter_status === 'present') {
            $query .= " AND a.check_in IS NOT NULL";
        } elseif ($filter_status === 'absent') {
            $query .= " AND a.check_in IS NULL";
        } elseif ($filter_status === 'late') {
            $query .= " AND a.check_in IS NOT NULL AND a.check_in > '12:00:00'";
        } elseif ($filter_status === 'in_progress') {
            $query .= " AND a.check_in IS NOT NULL AND a.check_out IS NULL";
        } elseif ($filter_status === 'complete') {
            $query .= " AND a.check_in IS NOT NULL AND a.check_out IS NOT NULL";
        }
    }

    $query .= " ORDER BY a.date DESC, a.check_in DESC, a.id DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $attendanceRecords = $stmt->fetchAll();
    
    // Calculate statistics
    $datesAll = [];
    $datesPresent = [];
    $totalHours = 0;
    $hoursSessions = 0;
    $totalSessions = 0;
    
    foreach ($attendanceRecords as $record) {
        if (!empty($record['date'])) {
            $datesAll[(string) $record['date']] = true;
        }
        if ($record['check_in']) {
            $datesPresent[(string) $record['date']] = true;
            $totalSessions++;
            
            if ($record['check_in'] && $record['check_out']) {
                $checkIn = strtotime($record['check_in']);
                $checkOut = strtotime($record['check_out']);
                $hours = ($checkOut - $checkIn) / 3600;
                $totalHours += $hours;
                $hoursSessions++;
            }
        }
    }
    
    $totalDays = count($datesAll);
    $presentDays = count($datesPresent);
    $avgHours = $hoursSessions > 0 ? round($totalHours / $hoursSessions, 2) : 0;
    
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $attendanceRecords = [];
    $totalSessions = 0;
    $totalDays = 0;
    $presentDays = 0;
    $avgHours = 0;
}
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Employee</div>
            <div class="sidebar-subtitle">Your work & schedule</div>
        </div>

        <div class="sidebar-quick">
            <a class="btn btn-secondary btn-sm" href="attendance.php">
                <i class="fas fa-clock me-2"></i>Attendance
            </a>
            <a class="btn btn-primary btn-sm" href="expenses.php?open=add">
                <i class="fas fa-plus me-2"></i>Expense
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav flex-column">
            <div class="nav-section-title">Overview</div>
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-link" href="tasks.php">
                <i class="fas fa-list-check"></i> Tasks
            </a>

            <div class="nav-section-title">Work</div>
            <a class="nav-link" href="clients.php">
                <i class="fas fa-building"></i> Clients
            </a>
            <a class="nav-link" href="leads.php">
                <i class="fas fa-handshake"></i> Leads
            </a>
            <a class="nav-link" href="attendance.php">
                <i class="fas fa-clock"></i> Attendance
            </a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php">
                <i class="fas fa-money-bill-wave"></i> Expenses
            </a>
            <a class="nav-link" href="project_expense_report.php">
                <i class="fas fa-file-excel"></i> Project Expense Report
            </a>
            <a class="nav-link" href="payroll.php">
                <i class="fas fa-calculator"></i> Payroll
            </a>
            <a class="nav-link" href="salary.php">
                <i class="fas fa-wallet"></i> Salary
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
            <div class="page-subtitle">Review your attendance sessions and locations</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#leaveRequestModal">
                <i class="fas fa-plane-departure me-2"></i>Request Leave
            </button>
            <a class="btn btn-primary" href="dashboard.php">
                <i class="fas fa-clock me-2"></i>Mark Attendance
            </a>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-value"><?php echo (int) ($policySummary['present_days'] ?? 0); ?></div>
                <div class="stat-label">Present</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-user-xmark"></i></div>
                <div class="stat-value"><?php echo (int) ($policySummary['absent_days'] ?? 0); ?></div>
                <div class="stat-label">Absent</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-plane-departure"></i></div>
                <div class="stat-value"><?php echo (int) ($policySummary['approved_leaves'] ?? 0); ?></div>
                <div class="stat-label">Approved Leave</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-ticket"></i></div>
                <div class="stat-value"><?php echo (int) ($policySummary['remaining_leaves'] ?? 0); ?></div>
                <div class="stat-label">Remaining Leave</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-mug-hot"></i></div>
                <div class="stat-value"><?php echo (int) ($policySummary['weekly_offs'] ?? 0); ?></div>
                <div class="stat-label">Weekly Off</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-2">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-scissors"></i></div>
                <div class="stat-value"><?php echo formatCurrency((float) ($salarySummary['deduction_amount'] ?? 0)); ?></div>
                <div class="stat-label">Deductions</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Leave Requests</h5>
        </div>
        <div class="card-body">
            <?php if (empty($leaveRequests)): ?>
                <p class="text-muted mb-0">No leave requests found for this month.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm" data-smart-table data-export-name="my_leave_requests.csv" data-page-size="10">
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>To</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaveRequests as $lr): ?>
                                <?php
                                    $st = strtolower((string) ($lr['status'] ?? 'pending'));
                                    $sb = $st === 'approved' ? 'success' : ($st === 'rejected' ? 'danger' : 'warning');
                                ?>
                                <tr>
                                    <td><?php echo formatDate($lr['from_date']); ?></td>
                                    <td><?php echo formatDate($lr['to_date']); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($lr['reason'] ?? '')); ?></td>
                                    <td><span class="badge bg-<?php echo $sb; ?>"><?php echo ucfirst($st); ?></span></td>
                                    <td><?php echo !empty($lr['created_at']) ? htmlspecialchars(date('d M Y', strtotime((string) $lr['created_at']))) : '-'; ?></td>
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
            <h5 class="mb-0">Filters</h5>
            <a href="attendance.php" class="btn btn-sm btn-secondary"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All</option>
                            <option value="present" <?php echo $filter_status === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="absent" <?php echo $filter_status === 'absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="late" <?php echo $filter_status === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="complete" <?php echo $filter_status === 'complete' ? 'selected' : ''; ?>>Complete</option>
                        </select>
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
            <h5 class="mb-0">Your Attendance Records</h5>
        </div>
        <div class="card-body">
            <?php if (empty($attendanceRecords)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-clock"></i></div>
                    <div class="empty-title">No attendance records yet</div>
                    <div class="empty-subtitle">Start by checking in from the dashboard. Your sessions will appear here automatically.</div>
                    <div class="empty-actions">
                        <a class="btn btn-primary" href="dashboard.php"><i class="fas fa-clock me-2"></i>Mark Attendance</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="attendanceTable" data-smart-table data-export-name="my_attendance_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Session</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Total Hours</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Image</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendanceRecords as $record): ?>
                                <?php
                                    $checkInInfo = resolveUploadPathInfo($record['image'] ?? '');
                                    $checkOutInfo = resolveUploadPathInfo($record['check_out_image'] ?? '');
                                ?>
                                <tr>
                                    <td><?php echo formatDate($record['date']); ?></td>
                                    <td><?php echo !empty($record['session_no']) ? (int) $record['session_no'] : '-'; ?></td>
                                    <td>
                                        <?php echo !empty($record['check_in']) ? substr((string) $record['check_in'], 0, 5) : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td><?php echo $record['check_out'] ? substr($record['check_out'], 0, 5) : '<span class="text-muted">-</span>'; ?></td>
                                    <td>
                                        <?php 
                                        if ($record['check_in'] && $record['check_out']) {
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
                                            echo $statusKey !== '' ? renderAttendanceStatusBadgeFromKey($statusKey) : renderAttendanceStatusBadge($record['check_in'] ?? null);
                                        ?>
                                        <?php
                                            $orig = strtolower(trim((string) ($record['attendance_status_original'] ?? '')));
                                            if ($orig === 'absent' && ($statusKey === 'present' || $statusKey === 'late')): ?>
                                            <div class="text-muted small mt-1">Attendance updated after check-in.</div>
                                        <?php endif; ?>
                                        <?php if (!empty($record['check_in'])): ?>
                                            <div class="text-muted small mt-1">
                                                <?php echo !empty($record['check_out']) ? 'Complete' : 'In progress'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['latitude'] && $record['longitude']): ?>
                                            <a href="https://maps.google.com/?q=<?php echo $record['latitude']; ?>,<?php echo $record['longitude']; ?>" 
                                               target="_blank" class="text-primary">
                                                <i class="fas fa-map-marker-alt"></i> View
                                            </a>
                                        <?php elseif (!empty($record['check_out_latitude']) && !empty($record['check_out_longitude'])): ?>
                                            <a href="https://maps.google.com/?q=<?php echo $record['check_out_latitude']; ?>,<?php echo $record['check_out_longitude']; ?>" 
                                               target="_blank" class="text-primary">
                                                <i class="fas fa-map-marker-alt"></i> View
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['image'])): ?>
                                            <button class="btn btn-sm btn-info" onclick="viewImage(this)"
                                                data-image-url="<?php echo htmlspecialchars($checkInInfo['url']); ?>"
                                                data-image-debug="<?php echo htmlspecialchars($checkInInfo['debug']); ?>"
                                                data-image-exists="<?php echo $checkInInfo['exists'] ? '1' : '0'; ?>">
                                                <i class="fas fa-image"></i> View
                                            </button>
                                            <?php if (!empty($record['check_out_image'])): ?>
                                                <button class="btn btn-sm btn-info ms-1" onclick="viewImage(this)"
                                                    data-image-url="<?php echo htmlspecialchars($checkOutInfo['url']); ?>"
                                                    data-image-debug="<?php echo htmlspecialchars($checkOutInfo['debug']); ?>"
                                                    data-image-exists="<?php echo $checkOutInfo['exists'] ? '1' : '0'; ?>">
                                                    <i class="fas fa-image"></i> Out
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No image</span>
                                        <?php endif; ?>
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

<!-- Leave Request Modal -->
<div class="modal fade" id="leaveRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Leave</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="request_leave">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">From Date *</label>
                            <input type="date" class="form-control" name="from_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Date *</label>
                            <input type="date" class="form-control" name="to_date" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reason *</label>
                            <textarea class="form-control" name="reason" rows="3" placeholder="Enter leave reason..." required></textarea>
                            <div class="form-text text-muted">Monthly quota: <?php echo (int) ($policySummary['leave_quota'] ?? 4); ?> • Remaining: <?php echo (int) ($policySummary['remaining_leaves'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Request</button>
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

<?php
$additional_js = "
<script>
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
</script>
";
require_once '../includes/footer.php';
?>
