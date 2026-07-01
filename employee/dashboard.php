<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Employee Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireEmployee(); // Use built-in auth function!

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensureTaskWorkflowSchema();
ensureClientWorkflowSchema();
ensureAttendancePolicySchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && (($_POST['action'] ?? '') === 'update_task_status')) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id']) || !isEmployee()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $allowed = ['pending', 'in_progress', 'completed'];
    if ($taskId <= 0 || !in_array($status, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid task data']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE employee_tasks SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$status, $taskId, (int) $_SESSION['user_id']]);

        if ($stmt->rowCount() < 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT DATE_FORMAT(updated_at, '%d %b %Y, %h:%i %p') as updated_at_fmt FROM employee_tasks WHERE id = ? LIMIT 1");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Task status updated successfully',
            'updated_at' => $row['updated_at_fmt'] ?? ''
        ]);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
        exit();
    }
}

// Get employee statistics
try {
    // Today's attendance
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $todayAttendance = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL AND check_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $activeSession = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) as sessions_today FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL");
    $stmt->execute([$_SESSION['user_id']]);
    $sessionsToday = (int) (($stmt->fetch()['sessions_today'] ?? 0));

    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURDATE() AND check_in IS NOT NULL ORDER BY check_in ASC, id ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $todaySessions = $stmt->fetchAll();

    $todayStatusRaw = '';
    $todayNotesRaw = '';
    if (is_array($todayAttendance)) {
        $todayStatusRaw = strtolower(trim((string) ($todayAttendance['attendance_status'] ?? '')));
        $todayNotesRaw = strtolower(trim((string) ($todayAttendance['check_in_notes'] ?? '')));
    }
    $isAbsentMarkedToday = is_array($todayAttendance) && empty($todayAttendance['check_in']) && ($todayStatusRaw === 'absent' || $todayNotesRaw === 'marked absent');

    $firstCheckInTime = '';
    if (!empty($todaySessions) && !empty($todaySessions[0]['check_in'])) {
        $firstCheckInTime = (string) $todaySessions[0]['check_in'];
    }

    $todayStatusKey = 'unmarked';
    if (!empty($activeSession)) {
        $todayStatusKey = 'checked_in';
    } elseif ($sessionsToday > 0) {
        $todayStatusKey = ($firstCheckInTime !== '' && $firstCheckInTime > '12:00:00') ? 'late' : 'present';
    } elseif ($isAbsentMarkedToday) {
        $todayStatusKey = 'absent';
    }

    $todayStatusLabel = $todayStatusKey === 'checked_in' ? 'Checked In' : ($todayStatusKey === 'present' ? 'Present' : ($todayStatusKey === 'late' ? 'Late' : ($todayStatusKey === 'absent' ? 'Absent' : 'Not marked')));
    $todayStatusBadgeClass = $todayStatusKey === 'checked_in' ? 'bg-info' : ($todayStatusKey === 'present' ? 'bg-success' : ($todayStatusKey === 'late' ? 'bg-warning' : ($todayStatusKey === 'absent' ? 'bg-danger' : 'bg-secondary')));
    
    // Current month attendance
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT date) as present_days FROM attendance 
                          WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                          AND check_in IS NOT NULL");
    $stmt->execute([$_SESSION['user_id'], getCurrentMonth()]);
    $monthAttendance = $stmt->fetch();
    
    // Current month expenses
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(amount) as total_amount, 
                          SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_amount 
                          FROM expenses 
                          WHERE user_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->execute([$_SESSION['user_id'], getCurrentMonth()]);
    $monthExpenses = $stmt->fetch();
    
    // Assigned events
    $stmt = $pdo->prepare("SELECT e.*, c.name as client_name 
                          FROM event_team et 
                          JOIN events e ON et.event_id = e.id 
                          LEFT JOIN clients c ON e.client_id = c.id 
                          WHERE et.user_id = ? AND e.status IN ('planning', 'active') 
                          ORDER BY e.start_date ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $assignedEvents = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) as assigned_clients FROM clients WHERE assigned_to = ?");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $assignedClientsCount = (int) (($stmt->fetch()['assigned_clients'] ?? 0));

    $todayBooking = getClientBookingAvailability(date('Y-m-d'));

    $stmt = $pdo->prepare("SELECT COUNT(*) as active_tasks FROM employee_tasks WHERE user_id = ? AND status IN ('pending','in_progress')");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $activeTasksCount = (int) (($stmt->fetch()['active_tasks'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(*) as today_tasks
                           FROM employee_tasks
                           WHERE user_id = ?
                             AND status IN ('pending','in_progress')
                             AND due_at IS NOT NULL
                             AND DATE(due_at) = CURDATE()");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $todayTasksCount = (int) (($stmt->fetch()['today_tasks'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.id) as events_managed
                           FROM event_team et
                           JOIN events e ON e.id = et.event_id
                           WHERE et.user_id = ?");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $eventsManagedCount = (int) (($stmt->fetch()['events_managed'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.id) as completed_events
                           FROM event_team et
                           JOIN events e ON e.id = et.event_id
                           WHERE et.user_id = ? AND e.status = 'completed'");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $completedEventsCount = (int) (($stmt->fetch()['completed_events'] ?? 0));

    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_expenses,
                                  COALESCE(SUM(amount), 0) as pending_amount
                           FROM expenses
                           WHERE user_id = ?
                             AND status = 'pending'
                             AND DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->execute([(int) $_SESSION['user_id'], getCurrentMonth()]);
    $pendingExpenseRow = $stmt->fetch();
    $pendingExpensesCount = (int) (($pendingExpenseRow['pending_expenses'] ?? 0));
    $pendingExpensesAmount = (float) (($pendingExpenseRow['pending_amount'] ?? 0));

    ensureExpenseCategorizationSchema();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.budget), 0) as total_budget
                           FROM event_team et
                           JOIN events e ON e.id = et.event_id
                           WHERE et.user_id = ? AND e.status IN ('planning','active')");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $budgetHandledTotal = (float) (($stmt->fetch()['total_budget'] ?? 0));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(ex.amount), 0) as spent
                           FROM expenses ex
                           JOIN events e ON e.id = ex.event_id
                           WHERE ex.status = 'approved'
                             AND COALESCE(ex.expense_category, 'personal') = 'client'
                             AND e.status IN ('planning','active')
                             AND ex.event_id IN (SELECT et2.event_id FROM event_team et2 WHERE et2.user_id = ?)");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $budgetHandledSpent = (float) (($stmt->fetch()['spent'] ?? 0));

    $budgetHandledRemaining = max(0, $budgetHandledTotal - $budgetHandledSpent);
    $budgetHandledUtilization = $budgetHandledTotal > 0 ? min(100, round(($budgetHandledSpent / $budgetHandledTotal) * 100, 1)) : 0;
    
    // Recent expenses
    $stmt = $pdo->prepare("SELECT * FROM expenses 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $recentExpenses = $stmt->fetchAll();
    
    // Recent attendance (latest sessions)
    $stmt = $pdo->prepare("SELECT * FROM attendance 
                          WHERE user_id = ? 
                          ORDER BY date DESC, check_in DESC, id DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $recentAttendance = $stmt->fetchAll();
    
    // Get employee details
    $stmt = $pdo->prepare("SELECT e.*, u.name, u.email 
                          FROM employees e 
                          JOIN users u ON e.user_id = u.id 
                          WHERE e.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch();
    
    // Calculate salary
    $salary = calculateSalary($_SESSION['user_id']);

    $tasks = [];
    $stmt = $pdo->prepare("SELECT id, title, due_at, status, updated_at FROM employee_tasks WHERE user_id = ? ORDER BY FIELD(status,'pending','in_progress','completed'), COALESCE(due_at, updated_at) ASC, id ASC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $tasks = $stmt->fetchAll();

    if (empty($tasks)) {
        $seed = [
            ['Prepare event checklist', date('Y-m-d 18:00:00'), 'pending'],
            ['Confirm vendor booking', date('Y-m-d 18:00:00', strtotime('+1 day')), 'in_progress'],
            ['Submit expense receipt', null, 'completed']
        ];
        $stmt = $pdo->prepare("INSERT INTO employee_tasks (user_id, title, due_at, status) VALUES (?, ?, ?, ?)");
        foreach ($seed as $row) {
            $stmt->execute([(int) $_SESSION['user_id'], $row[0], $row[1], $row[2]]);
        }
        $stmt = $pdo->prepare("SELECT id, title, due_at, status, updated_at FROM employee_tasks WHERE user_id = ? ORDER BY FIELD(status,'pending','in_progress','completed'), COALESCE(due_at, updated_at) ASC, id ASC LIMIT 10");
        $stmt->execute([$_SESSION['user_id']]);
        $tasks = $stmt->fetchAll();
    }

    $performanceMonth = getCurrentMonth();
    $performance = getEmployeeMonthlyPerformance((int) $_SESSION['user_id'], $performanceMonth);
    $performanceScore = (int) ($performance['total_score'] ?? 0);
    $attendancePercent = (float) ($performance['attendance_percent'] ?? 0);
    $incentiveTier = (string) ($performance['incentive_tier'] ?? 'none');
    $incentiveAmount = (float) ($performance['incentive_amount'] ?? 0);
    $tasksCompleted = (int) ($performance['tasks_completed'] ?? 0);
    $tasksOverdue = (int) ($performance['overdue_tasks'] ?? 0);
    $eventsParticipated = (int) ($performance['events_participated'] ?? 0);
    $lateDays = (int) ($performance['late_days'] ?? 0);

    ensureEventProfitFeedbackIncentiveSchema();
    recomputeMonthlyScoresAndRankings($performanceMonth);
    $bonusScore = computeEmployeeMonthlyScore((int) $_SESSION['user_id'], $performanceMonth);
    $bonusRanking = getEmployeeRankingForMonth((int) $_SESSION['user_id'], $performanceMonth);
    $bonusRankPos = (int) ($bonusRanking['rank_pos'] ?? 0);
    $bonusAvgRating = (float) ($bonusScore['avg_rating'] ?? 0);
    $bonusIncentivesEarned = (float) ($bonusScore['incentives_earned'] ?? 0);
    $bonusEventsHandled = (int) ($bonusScore['events_handled'] ?? 0);
    $bonusPerfScore = (int) ($bonusScore['score'] ?? 0);
    $bonusGrade = (string) ($bonusScore['grade'] ?? 'Needs Improvement');

    $totalTasksCount = count($tasks);
    $completedCountUi = 0;
    $inProgressCountUi = 0;
    $pendingCountUi = 0;
    foreach ($tasks as $t) {
        $s = strtolower(trim((string) ($t['status'] ?? 'pending')));
        if ($s === 'completed') $completedCountUi++;
        elseif ($s === 'in_progress') $inProgressCountUi++;
        else $pendingCountUi++;
    }
    
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
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

<div class="main-content" data-csrf-token="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Dashboard</h1>
            <div class="page-subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['name']); ?></div>
        </div>
        <div class="page-actions">
            <span class="badge bg-success">Employee</span>
            <span class="text-muted small"><?php echo date('d M Y, h:i A'); ?></span>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-primary" href="attendance.php">
            <i class="fas fa-clock me-2"></i>Mark Attendance
        </a>
        <a class="btn btn-secondary" href="tasks.php">
            <i class="fas fa-list-check me-2"></i>My Tasks
        </a>
        <a class="btn btn-secondary" href="clients.php">
            <i class="fas fa-building me-2"></i>Clients
        </a>
        <a class="btn btn-secondary" href="expenses.php?open=add">
            <i class="fas fa-plus me-2"></i>Add Expense
        </a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="attendance-card attendance-card--compact h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h5 class="mb-0">Attendance</h5>
                    <span class="badge bg-primary">Today</span>
                </div>
                <div class="text-muted attendance-desc mb-2">Check in / out with selfie + location</div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-muted small">Sessions today</div>
                    <span class="badge bg-info"><?php echo (int) ($sessionsToday ?? 0); ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center attendance-status-row mb-2">
                    <div class="text-muted small">Status</div>
                    <span id="attendanceStatusBadge" class="badge <?php echo htmlspecialchars($todayStatusBadgeClass); ?>"><?php echo htmlspecialchars($todayStatusLabel); ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3 justify-content-between">
                    <span class="badge bg-success">Present: <?php echo (int) ($salary['present_days'] ?? 0); ?></span>
                    <span class="badge bg-danger">Absent: <?php echo (int) ($salary['absent_days'] ?? 0); ?></span>
                    <span class="badge bg-primary">Leaves: <?php echo (int) ($salary['approved_leaves'] ?? 0); ?></span>
                    <span class="badge bg-info">Remaining: <?php echo (int) ($salary['remaining_leaves'] ?? 0); ?></span>
                    <span class="badge bg-purple">Weekly Off: <?php echo (int) ($salary['weekly_offs'] ?? 0); ?></span>
                    <span class="badge bg-warning">Deductions: <?php echo formatCurrency((float) ($salary['deduction_amount'] ?? 0)); ?></span>
                </div>
                <?php if (!empty($activeSession)): ?>
        <div class="text-center">
            <p class="text-success attendance-active mb-2">
                <i class="fas fa-check-circle me-2"></i>
                Checked in at <?php echo substr($activeSession['check_in'], 0, 5); ?>
            </p>
            <button class="btn btn-danger w-100 attendance-action-btn" onclick="markAttendance('check_out')">
                <i class="fas fa-sign-out-alt me-2"></i>Check Out
            </button>
        </div>
<?php elseif (!empty($todayAttendance) && !empty($todayAttendance['check_in']) && !empty($todayAttendance['check_out'])): ?>
        <div class="text-center">
            <p class="text-success attendance-active mb-2">
                <i class="fas fa-check-circle me-2"></i>
                Attendance completed for today
            </p>
            <p class="text-muted small mb-2">
                In: <?php echo substr($todayAttendance['check_in'], 0, 5); ?> • Out: <?php echo substr($todayAttendance['check_out'], 0, 5); ?> • Hours: <?php echo $todayAttendance['total_hours'] ?? '0.00'; ?>
            </p>
            <button class="btn btn-secondary w-100 attendance-action-btn" disabled>
                <i class="fas fa-check-circle me-2"></i>Completed
            </button>
        </div>
<?php else: ?>
                    <div class="text-center">
                        <p class="<?php echo $isAbsentMarkedToday ? 'text-danger' : 'text-muted'; ?> attendance-state mb-2">
                            <?php if ($isAbsentMarkedToday): ?>
                                <i class="fas fa-xmark me-2"></i>Marked absent for today (you can still check in later)
                            <?php else: ?>
                                Not marked yet
                            <?php endif; ?>
                        </p>
                        <div class="d-grid gap-2 attendance-primary-actions">
                            <button class="btn btn-success w-100 attendance-action-btn" id="attendanceCheckInBtn" onclick="markAttendance('check_in')">
                                <i class="fas fa-sign-in-alt me-2"></i>Check In
                            </button>
                            <?php if (!$isAbsentMarkedToday): ?>
                                <button class="btn btn-absent w-100 attendance-action-btn" id="attendanceAbsentBtn" type="button" onclick="openAbsentModal()">
                                    <i class="fas fa-xmark me-2"></i>Mark Absent
                                </button>
                                <div class="absent-info-card text-start">
                                    <div class="absent-info-title">Mark yourself as absent</div>
                                    <div class="absent-info-text">Use this option if you are not working today or unable to check in.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($todaySessions)): ?>
                    <div class="mt-3">
                        <div class="text-muted small mb-2">Session timeline</div>
                        <div class="d-flex flex-column gap-2 attendance-timeline">
                            <?php foreach ($todaySessions as $s): ?>
                                <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(229,231,235,0.08);">
                                    <div>
                                        <div class="fw-semibold">Session <?php echo (int) ($s['session_no'] ?? 0) ?: '-'; ?></div>
                                        <div class="small text-muted">
                                            In: <?php echo $s['check_in'] ? substr($s['check_in'], 0, 5) : '-'; ?>
                                            • Out: <?php echo $s['check_out'] ? substr($s['check_out'], 0, 5) : '-'; ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo $s['check_out'] ? 'success' : 'warning'; ?>">
                                        <?php echo $s['check_out'] ? 'Completed' : 'Active'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="mt-3 d-grid gap-2 attendance-secondary-actions">
                    <a href="attendance.php" class="btn btn-primary attendance-action-btn">
                        <i class="fas fa-clock me-2"></i>Attendance History
                    </a>
                    <a href="expenses.php" class="btn btn-warning attendance-action-btn">
                        <i class="fas fa-receipt me-2"></i>Add Expense
                    </a>
                    <a href="payroll.php" class="btn btn-info attendance-action-btn">
                        <i class="fas fa-calculator me-2"></i>Salary Details
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="row g-3 mb-3 dashboard-stats row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4">
                <div class="col">
                    <a href="clients.php" class="stat-card-link">
                        <div class="stat-card primary h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-building"></i></div>
                                    <div class="stat-value"><?php echo (int) ($assignedClientsCount ?? 0); ?></div>
                                    <div class="stat-label">Clients</div>
                                    <small class="text-muted">Assigned</small>
                                </div>
                                <span class="stat-pill positive"><i class="fas fa-arrow-up"></i> Live</span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <?php
                        $tbStatus = (string) ($todayBooking['status'] ?? 'Available');
                        $tbRemaining = (int) ($todayBooking['remaining'] ?? 0);
                        $tbLimit = (int) ($todayBooking['limit'] ?? getClientBookingDailyLimit());
                        $tbCount = (int) ($todayBooking['count'] ?? 0);
                        $tbPill = $tbStatus === 'Packed' ? 'negative' : ($tbStatus === 'Limited Slots' ? 'negative' : 'positive');
                    ?>
                    <a href="clients.php" class="stat-card-link">
                        <div class="stat-card info h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                                    <div class="stat-value"><?php echo $tbRemaining; ?></div>
                                    <div class="stat-label">Client Slots Left</div>
                                    <small class="text-muted">Today • <?php echo $tbCount; ?>/<?php echo $tbLimit; ?> booked</small>
                                </div>
                                <span class="stat-pill <?php echo $tbPill; ?>">
                                    <i class="fas fa-<?php echo $tbStatus === 'Packed' ? 'ban' : ($tbStatus === 'Limited Slots' ? 'triangle-exclamation' : 'check'); ?>"></i>
                                    <?php echo htmlspecialchars($tbStatus); ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="tasks.php" class="stat-card-link">
                        <div class="stat-card warning h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-list-check"></i></div>
                                    <div class="stat-value"><?php echo (int) ($todayTasksCount ?? 0); ?></div>
                                    <div class="stat-label">Today's Tasks</div>
                                    <small class="text-muted">Due today</small>
                                </div>
                                <span class="stat-pill <?php echo ($todayTasksCount ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                                    <i class="fas fa-<?php echo ($todayTasksCount ?? 0) > 0 ? 'clock' : 'check'; ?>"></i>
                                    <?php echo ($todayTasksCount ?? 0) > 0 ? 'Due' : 'Clear'; ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="dashboard.php" class="stat-card-link">
                        <div class="stat-card info h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                                    <div class="stat-value"><?php echo (int) (is_array($assignedEvents) ? count($assignedEvents) : 0); ?></div>
                                    <div class="stat-label">Upcoming Events</div>
                                    <small class="text-muted">Planning + active</small>
                                </div>
                                <span class="stat-pill positive"><i class="fas fa-arrow-up"></i> Active</span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="expenses.php" class="stat-card-link">
                        <div class="stat-card success h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                                    <div class="stat-value"><?php echo (int) ($pendingExpensesCount ?? 0); ?></div>
                                    <div class="stat-label">Expenses</div>
                                    <small class="text-muted"><?php echo formatCurrency((float) ($monthExpenses['approved_amount'] ?? 0)); ?> approved</small>
                                </div>
                                <span class="stat-pill <?php echo ($pendingExpensesCount ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                                    <i class="fas fa-<?php echo ($pendingExpensesCount ?? 0) > 0 ? 'hourglass-half' : 'check'; ?>"></i>
                                    <?php echo ($pendingExpensesCount ?? 0) > 0 ? 'Pending' : 'Clear'; ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Budget Handled</h5>
                    <span class="badge bg-primary"><?php echo htmlspecialchars((string) ($budgetHandledUtilization ?? 0)); ?>% utilized</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small">Total Budget</div>
                                <div class="fw-bold"><?php echo formatCurrency($budgetHandledTotal ?? 0); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small">Approved Client Expenses</div>
                                <div class="fw-bold"><?php echo formatCurrency($budgetHandledSpent ?? 0); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small">Remaining</div>
                                <div class="fw-bold"><?php echo formatCurrency($budgetHandledRemaining ?? 0); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (float) ($budgetHandledUtilization ?? 0); ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-2">
                            <span><?php echo formatCurrency($budgetHandledSpent ?? 0); ?> used</span>
                            <span><?php echo formatCurrency($budgetHandledTotal ?? 0); ?> total</span>
                        </div>
                    </div>
                    <?php if (!empty($pendingExpensesCount)): ?>
                        <div class="text-muted small mt-2">
                            Pending expenses: <?php echo (int) $pendingExpensesCount; ?> • <?php echo formatCurrency($pendingExpensesAmount ?? 0); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-3 dashboard-stats">
                <div class="col">
                    <a href="salary.php" class="stat-card-link">
                        <div class="stat-card warning h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-gauge-high"></i></div>
                                    <div class="stat-value"><?php echo $performanceScore; ?>/100</div>
                                    <div class="stat-label">Performance</div>
                                    <small class="text-muted"><?php echo htmlspecialchars(date('F Y', strtotime($performanceMonth . '-01'))); ?></small>
                                </div>
                                <span class="stat-pill <?php echo $performanceScore >= 75 ? 'positive' : 'negative'; ?>">
                                    <i class="fas fa-<?php echo $performanceScore >= 75 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    <?php echo $performanceScore >= 75 ? 'Good' : 'Needs focus'; ?>
                                </span>
                            </div>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Progress</span>
                                    <span><?php echo $performanceScore; ?>%</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $performanceScore >= 90 ? 'success' : ($performanceScore >= 75 ? 'primary' : ($performanceScore >= 60 ? 'warning' : 'danger')); ?>" role="progressbar" style="width: <?php echo max(0, min(100, $performanceScore)); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="salary.php" class="stat-card-link">
                        <div class="stat-card success h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-coins"></i></div>
                                    <div class="stat-value"><?php echo formatCurrency($incentiveAmount); ?></div>
                                    <div class="stat-label">Monthly Incentive</div>
                                    <small class="text-muted"><?php echo ucfirst($incentiveTier); ?> tier</small>
                                </div>
                                <span class="stat-pill <?php echo $incentiveTier === 'high' ? 'positive' : ($incentiveTier === 'medium' ? 'positive' : ($incentiveTier === 'basic' ? 'positive' : 'negative')); ?>">
                                    <i class="fas fa-<?php echo $incentiveTier === 'high' ? 'trophy' : ($incentiveTier === 'medium' ? 'award' : ($incentiveTier === 'basic' ? 'medal' : 'ban')); ?>"></i>
                                    <?php echo $incentiveTier === 'none' ? 'No incentive' : ucfirst($incentiveTier); ?>
                                </span>
                            </div>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <?php if ($attendancePercent >= 95): ?>
                                    <span class="badge bg-success">Attendance Star</span>
                                <?php endif; ?>
                                <?php if ($tasksCompleted >= 3): ?>
                                    <span class="badge bg-primary">Task Finisher</span>
                                <?php endif; ?>
                                <?php if ($eventsParticipated >= 1): ?>
                                    <span class="badge bg-info">Team Player</span>
                                <?php endif; ?>
                                <?php if ($lateDays === 0 && $attendancePercent >= 90): ?>
                                    <span class="badge bg-success">On Time</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="attendance.php" class="stat-card-link">
                        <div class="stat-card info h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-chart-pie"></i></div>
                                    <div class="stat-value"><?php echo round($attendancePercent, 1); ?>%</div>
                                    <div class="stat-label">Attendance</div>
                                    <small class="text-muted"><?php echo $lateDays; ?> late day(s)</small>
                                </div>
                                <span class="stat-pill <?php echo $tasksOverdue > 0 ? 'negative' : 'positive'; ?>">
                                    <i class="fas fa-<?php echo $tasksOverdue > 0 ? 'triangle-exclamation' : 'check'; ?>"></i>
                                    <?php echo $tasksOverdue > 0 ? ($tasksOverdue . ' overdue') : 'On track'; ?>
                                </span>
                            </div>
                            <div class="mt-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Task completion</span>
                                    <span><?php echo $totalTasksCount > 0 ? round(($completedCountUi / $totalTasksCount) * 100) : 0; ?>%</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $totalTasksCount > 0 ? max(0, min(100, round(($completedCountUi / $totalTasksCount) * 100))) : 0; ?>%"></div>
                                </div>
                                <div class="mt-2 small text-muted">
                                    <span class="me-2"><?php echo $pendingCountUi; ?> pending</span>
                                    <span class="me-2"><?php echo $inProgressCountUi; ?> in progress</span>
                                    <span><?php echo $completedCountUi; ?> completed</span>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row g-3 mb-3 dashboard-stats">
                <div class="col">
                    <a href="salary.php" class="stat-card-link">
                        <div class="stat-card primary h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-gift"></i></div>
                                    <div class="stat-value"><?php echo formatCurrency($bonusIncentivesEarned ?? 0); ?></div>
                                    <div class="stat-label">Incentives Earned</div>
                                    <small class="text-muted">Profit-qualified</small>
                                </div>
                                <span class="stat-pill positive"><i class="fas fa-coins"></i> Bonus</span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="dashboard.php" class="stat-card-link">
                        <div class="stat-card info h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                                    <div class="stat-value"><?php echo htmlspecialchars(number_format((float) ($bonusAvgRating ?? 0), 2)); ?>/5</div>
                                    <div class="stat-label">Avg Client Rating</div>
                                    <small class="text-muted"><?php echo (int) ($bonusEventsHandled ?? 0); ?> completed event(s)</small>
                                </div>
                                <span class="stat-pill <?php echo ($bonusAvgRating ?? 0) >= 4 ? 'positive' : (($bonusAvgRating ?? 0) >= 3 ? 'positive' : 'negative'); ?>">
                                    <i class="fas fa-<?php echo ($bonusAvgRating ?? 0) >= 4 ? 'thumbs-up' : (($bonusAvgRating ?? 0) >= 3 ? 'check' : 'triangle-exclamation'); ?>"></i>
                                    <?php echo ($bonusAvgRating ?? 0) >= 4 ? 'Great' : (($bonusAvgRating ?? 0) >= 3 ? 'Good' : 'Low'); ?>
                                </span>
                            </div>
                            <div class="mt-3 d-flex align-items-center gap-1">
                                <?php $arInt = (int) round((float) ($bonusAvgRating ?? 0)); ?>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= $arInt ? 'text-warning' : 'text-muted'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="salary.php" class="stat-card-link">
                        <div class="stat-card warning h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-gauge-high"></i></div>
                                    <div class="stat-value"><?php echo (int) ($bonusPerfScore ?? 0); ?>/100</div>
                                    <div class="stat-label">Bonus Score</div>
                                    <small class="text-muted"><?php echo htmlspecialchars($bonusGrade ?? ''); ?></small>
                                </div>
                                <span class="stat-pill <?php echo (int) ($bonusPerfScore ?? 0) >= 75 ? 'positive' : 'negative'; ?>">
                                    <i class="fas fa-<?php echo (int) ($bonusPerfScore ?? 0) >= 75 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    <?php echo (int) ($bonusPerfScore ?? 0) >= 75 ? 'Strong' : 'Improve'; ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col">
                    <a href="salary.php" class="stat-card-link">
                        <div class="stat-card success h-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                                    <div class="stat-value"><?php echo $bonusRankPos > 0 ? ('#' . (int) $bonusRankPos) : '—'; ?></div>
                                    <div class="stat-label">Monthly Rank</div>
                                    <small class="text-muted"><?php echo htmlspecialchars(date('F Y', strtotime($performanceMonth . '-01'))); ?></small>
                                </div>
                                <span class="stat-pill <?php echo $bonusRankPos > 0 && $bonusRankPos <= 3 ? 'positive' : 'neutral'; ?>">
                                    <i class="fas fa-<?php echo $bonusRankPos > 0 && $bonusRankPos <= 3 ? 'medal' : 'users'; ?>"></i>
                                    <?php echo $bonusRankPos > 0 && $bonusRankPos <= 3 ? 'Top' : 'Rank'; ?>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Task List</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($tasks)): ?>
                                <p class="text-muted mb-0">No tasks assigned yet.</p>
                            <?php else: ?>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    $taskStatus = strtolower(trim((string) ($task['status'] ?? 'pending')));
                                    if ($taskStatus !== 'pending' && $taskStatus !== 'in_progress' && $taskStatus !== 'completed') {
                                        $taskStatus = 'pending';
                                    }
                                    $badgeClass = $taskStatus === 'completed' ? 'bg-success' : ($taskStatus === 'in_progress' ? 'bg-primary' : 'bg-warning');
                                    $badgeText = $taskStatus === 'completed' ? 'Completed' : ($taskStatus === 'in_progress' ? 'In Progress' : 'Pending');

                                    $dueText = '';
                                    if (!empty($task['due_at'])) {
                                        $dueText = 'Due ' . date('d M Y', strtotime((string) $task['due_at']));
                                    }
                                    $updatedText = '';
                                    if (!empty($task['updated_at'])) {
                                        $updatedText = 'Updated ' . date('d M Y, h:i A', strtotime((string) $task['updated_at']));
                                    }
                                    ?>
                                    <div class="task-row js-task-row d-flex justify-content-between align-items-start gap-3 border-bottom" style="border-color: rgba(229, 231, 235, 0.08) !important;">
                                        <div class="task-details flex-grow-1">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($task['title'] ?? ''); ?></div>
                                            <div class="task-meta text-muted small">
                                                <?php if ($dueText !== ''): ?>
                                                    <span><?php echo htmlspecialchars($dueText); ?></span>
                                                <?php endif; ?>
                                                <?php if ($updatedText !== ''): ?>
                                                    <span class="js-task-updated"><?php echo htmlspecialchars($updatedText); ?></span>
                                                <?php else: ?>
                                                    <span class="js-task-updated"></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="task-actions d-flex align-items-center justify-content-end flex-wrap gap-2">
                                            <span class="badge js-task-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                            <select class="form-select form-select-sm task-status-select js-task-status" data-task-id="<?php echo (int) $task['id']; ?>" data-current-status="<?php echo htmlspecialchars($taskStatus); ?>" aria-label="Task status">
                                                <option value="pending" <?php echo $taskStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="in_progress" <?php echo $taskStatus === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $taskStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Schedule</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($assignedEvents)): ?>
                                <p class="text-muted mb-0">No upcoming schedule yet.</p>
                            <?php else: ?>
                                <?php foreach ($assignedEvents as $event): ?>
                                    <div class="d-flex justify-content-between align-items-start py-2 border-bottom" style="border-color: rgba(229, 231, 235, 0.08) !important;">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($event['name']); ?></div>
                                            <div class="text-muted small">
                                                <i class="fas fa-calendar me-1"></i><?php echo formatDate($event['start_date']); ?>
                                                <?php if ($event['client_name']): ?>
                                                    <span class="ms-2"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($event['client_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="badge bg-<?php echo $event['status'] == 'active' ? 'primary' : 'warning'; ?>">
                                            <?php echo ucfirst($event['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Attendance -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Attendance</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentAttendance)): ?>
                        <p class="text-muted">No attendance records found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Check In</th>
                                        <th>Check Out</th>
                                        <th>Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentAttendance as $attendance): ?>
                                        <tr>
                                            <td><?php echo formatDate($attendance['date']); ?></td>
                                            <td><?php echo $attendance['check_in'] ? substr($attendance['check_in'], 0, 5) : '-'; ?></td>
                                            <td><?php echo $attendance['check_out'] ? substr($attendance['check_out'], 0, 5) : '-'; ?></td>
                                            <td>
                                                <?php 
                                                if ($attendance['check_in'] && $attendance['check_out']) {
                                                    $checkIn = strtotime($attendance['check_in']);
                                                    $checkOut = strtotime($attendance['check_out']);
                                                    $hours = round(($checkOut - $checkIn) / 3600, 2);
                                                    echo $hours . 'h';
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
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
        
        <!-- Recent Expenses -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Expenses</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentExpenses)): ?>
                        <p class="text-muted">No expenses found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentExpenses as $expense): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($expense['type']); ?></td>
                                            <td><?php echo formatCurrency($expense['amount']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $expense['status'] == 'approved' ? 'success' : 
                                                         ($expense['status'] == 'rejected' ? 'danger' : 'warning'); 
                                                ?>">
                                                    <?php echo ucfirst($expense['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatDate($expense['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Mark Absent Modal -->
<div class="modal fade absent-modal" id="absentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-1">
                <div>
                    <div class="h5 mb-0 fw-bold">Confirm absence</div>
                    <div class="text-muted small">Submit your absence details for today</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="absent-meta-grid mb-3">
                    <div class="absent-meta-item">
                        <div class="absent-meta-label">Date</div>
                        <div class="absent-meta-value"><?php echo date('d M Y'); ?></div>
                    </div>
                    <div class="absent-meta-item">
                        <div class="absent-meta-label">Day</div>
                        <div class="absent-meta-value"><?php echo date('l'); ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="absentReason">Reason</label>
                    <select class="form-select" id="absentReason" required>
                        <option value="" selected disabled>Select reason</option>
                        <option value="sick leave">Sick Leave</option>
                        <option value="personal work">Personal Work</option>
                        <option value="emergency">Emergency</option>
                        <option value="family function">Family Function</option>
                        <option value="not available">Not Available</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="invalid-feedback">Please select a reason.</div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold" for="absentDescription">Description</label>
                    <textarea class="form-control" id="absentDescription" rows="3" maxlength="280" placeholder="Enter reason for absence..."></textarea>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="text-danger small d-none" id="absentDescriptionError">Please enter a short description.</div>
                        <div class="text-muted small ms-auto"><span id="absentDescCount">0</span>/280</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <div class="d-grid gap-2 d-sm-flex w-100 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-absent" id="confirmAbsentBtn">
                        <i class="fas fa-xmark me-2"></i>Confirm Absent
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Attendance Camera Modal -->
<div class="modal fade" id="attendanceCameraModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-camera me-2"></i>Capture Attendance Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center d-flex flex-column align-items-center justify-content-center" style="min-height: 400px;">
                <video id="attendanceCameraVideo" autoplay playsinline muted style="display: none; max-width: 100%; max-height: 60vh; border-radius: 8px; object-fit: contain;"></video>
                <canvas id="attendanceCameraCanvas" style="display: none;"></canvas>
                <img id="attendanceCameraPreview" src="" alt="Captured Photo Preview" style="display: none; max-width: 100%; max-height: 60vh; border-radius: 8px; object-fit: contain;">
                <div id="attendanceCameraPlaceholder" style="display: flex; flex-direction: column; align-items: center;">
                    <i class="fas fa-spinner fa-4x fa-spin text-muted mb-3"></i>
                    <p class="text-muted fs-5">Requesting permissions and starting camera...</p>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" id="attendanceSwitchFrontBtn" style="display: none;">
                        <i class="fas fa-user-circle me-2"></i>Front Camera
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="attendanceSwitchBackBtn" style="display: none;">
                        <i class="fas fa-camera me-2"></i>Back Camera
                    </button>
                    <button type="button" class="btn btn-success" id="attendanceCaptureBtn" style="display: none;">
                        <i class="fas fa-camera-retro me-2"></i>Capture Photo
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="attendanceRetakeBtn" style="display: none;">
                        <i class="fas fa-rotate-left me-2"></i>Retake Photo
                    </button>
                    <button type="button" class="btn btn-primary" id="attendanceSubmitBtn" style="display: none;">
                        <i class="fas fa-check-circle me-2"></i>Submit Attendance
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$employeeName = htmlspecialchars($_SESSION['name'], ENT_QUOTES);
$employeeId = htmlspecialchars($_SESSION['user_id'], ENT_QUOTES);
$additional_js = <<<JS
<script src="../assets/js/attendance.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('[employee/dashboard.php] DOMContentLoaded');
    console.log('[employee/dashboard.php] Initializing attendance system');
    // Initialize attendance system with employee info
    initAttendanceSystem({
        name: '{$employeeName}',
        id: '{$employeeId}'
    });
    console.log('[employee/dashboard.php] Attendance system initialized');
});

// Replace old markAttendance function
function markAttendance(type) {
    openAttendanceModal(type);
}

function openAbsentModal() {
    const el = document.getElementById('absentModal');
    if (!el) return;
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function updateAbsentCounter() {
    const ta = document.getElementById('absentDescription');
    const counter = document.getElementById('absentDescCount');
    if (!ta || !counter) return;
    counter.textContent = String(ta.value.length);
}

function validateAbsentForm() {
    const reasonEl = document.getElementById('absentReason');
    const descEl = document.getElementById('absentDescription');
    const descErr = document.getElementById('absentDescriptionError');
    if (!reasonEl || !descEl) return false;

    const reason = (reasonEl.value || '').trim();
    const description = (descEl.value || '').trim();

    reasonEl.classList.remove('is-invalid');
    if (descErr) descErr.classList.add('d-none');

    if (!reason) {
        reasonEl.classList.add('is-invalid');
        return false;
    }

    if (reason === 'other' && description.length < 3) {
        if (descErr) descErr.classList.remove('d-none');
        descEl.focus();
        return false;
    }

    return true;
}

async function submitAbsent() {
    if (!validateAbsentForm()) return;

    const confirmBtn = document.getElementById('confirmAbsentBtn');
    if (confirmBtn) confirmBtn.disabled = true;

    try {
        showLoading();

        const reasonEl = document.getElementById('absentReason');
        const descEl = document.getElementById('absentDescription');
        const formData = new FormData();
        formData.append('type', 'absent');
        formData.append('reason', (reasonEl && reasonEl.value) ? reasonEl.value : '');
        formData.append('description', (descEl && descEl.value) ? descEl.value : '');

        const response = await fetch('attendance_process.php', { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            showAlert('success', 'Absent marked successfully. You can still check in later to start work.');

            const badge = document.getElementById('attendanceStatusBadge');
            if (badge) {
                badge.className = 'badge bg-danger';
                badge.textContent = 'Absent';
            }

            const el = document.getElementById('absentModal');
            const inst = el ? bootstrap.Modal.getInstance(el) : null;
            if (inst) inst.hide();

            setTimeout(() => window.location.reload(), 1200);
        } else {
            showAlert('danger', result.message || 'Unable to mark absent');
        }
    } catch (error) {
        showAlert('danger', 'Error: ' + error.message);
    } finally {
        hideLoading();
        if (confirmBtn) confirmBtn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('absentModal');
    const descEl = document.getElementById('absentDescription');
    const reasonEl = document.getElementById('absentReason');
    const confirmBtn = document.getElementById('confirmAbsentBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', submitAbsent);
    if (descEl) descEl.addEventListener('input', updateAbsentCounter);
    if (reasonEl) reasonEl.addEventListener('change', function() {
        reasonEl.classList.remove('is-invalid');
        const descErr = document.getElementById('absentDescriptionError');
        if (descErr) descErr.classList.add('d-none');
    });
    updateAbsentCounter();

    if (el) {
        el.addEventListener('show.bs.modal', function() {
            document.body.classList.add('absent-modal-open');
        });
        el.addEventListener('hidden.bs.modal', function() {
            document.body.classList.remove('absent-modal-open');
            if (reasonEl) {
                reasonEl.value = '';
                reasonEl.classList.remove('is-invalid');
            }
            if (descEl) {
                descEl.value = '';
            }
            const descErr = document.getElementById('absentDescriptionError');
            if (descErr) descErr.classList.add('d-none');
            updateAbsentCounter();
            if (confirmBtn) confirmBtn.disabled = false;
        });
    }
});
</script>
JS;
require_once '../includes/footer.php';
?>
