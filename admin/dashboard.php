<?php
require_once __DIR__ . '/../config/database.php';
requireAdmin(); // Use the built-in auth function from database.php!

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    $action = (string) ($_GET['action'] ?? '');

    ensureExpenseCategorizationSchema();
    ensureClientWorkflowSchema();
    ensureTaskWorkflowSchema();
    ensureProjectExpenseReportsSchema();

    if ($action === 'employee_assign_task') {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit();
        }

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $dueAt = trim((string) ($_POST['due_at'] ?? ''));
        $clientIdRaw = trim((string) ($_POST['client_id'] ?? ''));
        $clientId = $clientIdRaw !== '' ? (int) $clientIdRaw : null;

        if ($employeeId < 1 || $title === '') {
            echo json_encode(['success' => false, 'message' => 'Task title is required.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee' LIMIT 1");
            $stmt->execute([$employeeId]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Employee not found.']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO employee_tasks (user_id, title, description, due_at, status, assigned_by, client_id)
                                   VALUES (?, ?, ?, ?, 'pending', ?, ?)");
            $stmt->execute([
                $employeeId,
                $title,
                $description !== '' ? $description : null,
                $dueAt !== '' ? $dueAt : null,
                (int) $_SESSION['user_id'],
                $clientId
            ]);

            echo json_encode(['success' => true, 'message' => 'Task assigned successfully.']);
            exit();
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to assign task.']);
            exit();
        }
    }

    if ($action === 'employee_snapshot') {
        header('Content-Type: application/json; charset=utf-8');

        $month = (string) ($_GET['month'] ?? getCurrentMonth());
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = getCurrentMonth();
        }

        try {
            $stmt = $pdo->query("SELECT u.id, u.name,
                                        (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) as assigned_clients,
                                        (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status IN ('pending','in_progress')) as active_tasks,
                                        (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status = 'completed') as completed_tasks
                                 FROM users u
                                 WHERE u.role = 'employee'
                                 ORDER BY u.name");
            $rows = $stmt->fetchAll();

            $todayMap = [];
            $stmt = $pdo->query("SELECT user_id,
                                        SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as sessions,
                                        SUM(CASE WHEN check_in IS NOT NULL AND check_in > '12:00:00' THEN 1 ELSE 0 END) as late_sessions
                                 FROM attendance
                                 WHERE date = CURDATE()
                                 GROUP BY user_id");
            foreach ($stmt->fetchAll() as $r) {
                $uid = (int) ($r['user_id'] ?? 0);
                if ($uid < 1) continue;
                $todayMap[$uid] = [
                    'sessions' => (int) ($r['sessions'] ?? 0),
                    'late_sessions' => (int) ($r['late_sessions'] ?? 0)
                ];
            }

            $out = [];
            foreach ($rows as $r) {
                $uid = (int) ($r['id'] ?? 0);
                if ($uid < 1) continue;
                $p = getEmployeeMonthlyPerformance($uid, $month);
                $score = (int) ($p['total_score'] ?? 0);
                $today = $todayMap[$uid] ?? ['sessions' => 0, 'late_sessions' => 0];
                $status = 'absent';
                if (($today['sessions'] ?? 0) > 0) $status = 'present';
                if (($today['late_sessions'] ?? 0) > 0 && ($today['sessions'] ?? 0) > 0) $status = 'late';
                $out[$uid] = [
                    'assigned_clients' => (int) ($r['assigned_clients'] ?? 0),
                    'active_tasks' => (int) ($r['active_tasks'] ?? 0),
                    'completed_tasks' => (int) ($r['completed_tasks'] ?? 0),
                    'attendance_status' => $status,
                    'performance_score' => $score
                ];
            }

            echo json_encode(['success' => true, 'employees' => $out]);
            exit();
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to load snapshot.']);
            exit();
        }
    }

    if ($action !== 'employee_profile') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit();
    }

    header('Content-Type: text/html; charset=utf-8');

    $employeeId = (int) ($_GET['employee_id'] ?? 0);
    $month = (string) ($_GET['month'] ?? getCurrentMonth());
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = getCurrentMonth();
    }

    if ($employeeId < 1) {
        echo "<div class='text-muted'>Invalid employee.</div>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT u.id, u.name, u.email,
                                      COALESCE(e.phone, u.phone) as phone,
                                      COALESCE(e.address, u.address) as address,
                                      e.designation, e.salary, e.join_date, e.status
                               FROM users u
                               LEFT JOIN employees e ON e.user_id = u.id
                               WHERE u.id = ? AND u.role = 'employee'
                               LIMIT 1");
        $stmt->execute([$employeeId]);
        $emp = $stmt->fetch();

        if (!$emp) {
            echo "<div class='text-muted'>Employee not found.</div>";
            exit();
        }

        $perf = getEmployeeMonthlyPerformance($employeeId, $month);
        $performanceScore = (int) ($perf['total_score'] ?? 0);
        $attendancePercent = (float) ($perf['attendance_percent'] ?? 0);
        $incentiveTier = (string) ($perf['incentive_tier'] ?? 'none');
        $incentiveAmount = (float) ($perf['incentive_amount'] ?? 0);
        $tasksCompleted = (int) ($perf['tasks_completed'] ?? 0);
        $tasksOverdue = (int) ($perf['overdue_tasks'] ?? 0);
        $lateDays = (int) ($perf['late_days'] ?? 0);
        $eventsParticipated = (int) ($perf['events_participated'] ?? 0);

        $stmt = $pdo->prepare("SELECT
                                  (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = ?) as assigned_clients,
                                  (SELECT COUNT(DISTINCT et.event_id) FROM event_team et WHERE et.user_id = ?) as assigned_events,
                                  (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = ? AND t.status IN ('pending','in_progress')) as active_tasks,
                                  (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = ? AND t.status = 'completed') as completed_tasks,
                                  (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = ? AND t.status = 'pending') as pending_tasks,
                                  (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = ? AND t.status = 'in_progress') as in_progress_tasks
                               ");
        $stmt->execute([$employeeId, $employeeId, $employeeId, $employeeId, $employeeId, $employeeId]);
        $counts = $stmt->fetch() ?: [];

        $totalTasks = (int) (($counts['active_tasks'] ?? 0)) + (int) (($counts['completed_tasks'] ?? 0));
        $taskCompletionRate = $totalTasks > 0 ? round(((int) ($counts['completed_tasks'] ?? 0) / $totalTasks) * 100, 1) : 0;

        $stmt = $pdo->prepare("SELECT a.*
                               FROM attendance a
                               WHERE a.user_id = ?
                               ORDER BY a.date DESC, a.check_in DESC, a.id DESC
                               LIMIT 10");
        $stmt->execute([$employeeId]);
        $attendanceRows = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT e.*, c.name as client_name,
                                      (SELECT COALESCE(SUM(ex.amount), 0)
                                       FROM expenses ex
                                       WHERE ex.event_id = e.id
                                         AND ex.status = 'approved'
                                         AND COALESCE(ex.expense_category, 'personal') = 'client') as approved_client_expenses
                               FROM event_team et
                               JOIN events e ON e.id = et.event_id
                               LEFT JOIN clients c ON c.id = e.client_id
                               WHERE et.user_id = ?
                               ORDER BY e.start_date DESC, e.id DESC
                               LIMIT 10");
        $stmt->execute([$employeeId]);
        $assignedEvents = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT c.*
                               FROM clients c
                               WHERE c.assigned_to = ?
                               ORDER BY COALESCE(c.workflow_updated_at, c.updated_at, c.created_at) DESC
                               LIMIT 10");
        $stmt->execute([$employeeId]);
        $assignedClients = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT t.*, c.name as client_name, e.name as event_name
                               FROM employee_tasks t
                               LEFT JOIN clients c ON c.id = t.client_id
                               LEFT JOIN events e ON e.id = t.event_id
                               WHERE t.user_id = ?
                               ORDER BY FIELD(t.status,'pending','in_progress','completed'), COALESCE(t.due_at, t.updated_at) ASC, t.id DESC
                               LIMIT 15");
        $stmt->execute([$employeeId]);
        $taskRows = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT ex.*, c.name as client_name, ev.name as event_name
                               FROM expenses ex
                               LEFT JOIN clients c ON c.id = ex.client_id
                               LEFT JOIN events ev ON ev.id = ex.event_id
                               WHERE ex.user_id = ?
                               ORDER BY ex.created_at DESC, ex.id DESC
                               LIMIT 10");
        $stmt->execute([$employeeId]);
        $expenseRows = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) as pending_reimbursements
                               FROM expenses ex
                               WHERE ex.user_id = ?
                                 AND ex.status = 'pending'
                                 AND COALESCE(ex.expense_category, 'personal') = 'client'
                                 AND COALESCE(ex.reimbursable, 0) = 1");
        $stmt->execute([$employeeId]);
        $pendingReimb = (int) (($stmt->fetch()['pending_reimbursements'] ?? 0));

        $stmt = $pdo->prepare("SELECT pr.*, c.name as client_name, ev.name as event_name
                               FROM project_expense_reports pr
                               LEFT JOIN clients c ON c.id = pr.client_id
                               LEFT JOIN events ev ON ev.id = pr.event_id
                               WHERE pr.user_id = ?
                               ORDER BY pr.submitted_at DESC, pr.id DESC
                               LIMIT 5");
        $stmt->execute([$employeeId]);
        $reportRows = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT cn.note, cn.created_at, c.name as client_name
                               FROM client_notes cn
                               JOIN clients c ON c.id = cn.client_id
                               WHERE cn.user_id = ?
                               ORDER BY cn.created_at DESC, cn.id DESC
                               LIMIT 10");
        $stmt->execute([$employeeId]);
        $clientNotes = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.budget), 0) as total_budget
                               FROM event_team et
                               JOIN events e ON e.id = et.event_id
                               WHERE et.user_id = ? AND e.status IN ('planning','active')");
        $stmt->execute([$employeeId]);
        $budgetHandledTotal = (float) (($stmt->fetch()['total_budget'] ?? 0));

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(ex.amount), 0) as spent
                               FROM expenses ex
                               JOIN events e ON e.id = ex.event_id
                               WHERE ex.status = 'approved'
                                 AND COALESCE(ex.expense_category, 'personal') = 'client'
                                 AND e.status IN ('planning','active')
                                 AND ex.event_id IN (SELECT et2.event_id FROM event_team et2 WHERE et2.user_id = ?)");
        $stmt->execute([$employeeId]);
        $budgetHandledSpent = (float) (($stmt->fetch()['spent'] ?? 0));

        $budgetHandledRemaining = max(0, $budgetHandledTotal - $budgetHandledSpent);
        $budgetHandledUtilization = $budgetHandledTotal > 0 ? min(100, round(($budgetHandledSpent / $budgetHandledTotal) * 100, 1)) : 0;

        $days = [];
        $presentMap = [];
        $startTs = strtotime('-13 days');
        for ($i = 0; $i < 14; $i++) {
            $d = date('Y-m-d', strtotime("+$i day", $startTs));
            $days[] = $d;
            $presentMap[$d] = 0;
        }
        $stmt = $pdo->prepare("SELECT date, COUNT(*) as sessions
                               FROM attendance
                               WHERE user_id = ?
                                 AND date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                                 AND check_in IS NOT NULL
                               GROUP BY date");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $r) {
            $k = (string) ($r['date'] ?? '');
            if (isset($presentMap[$k])) {
                $presentMap[$k] = (int) ($r['sessions'] ?? 0) > 0 ? 1 : 0;
            }
        }

        $chartData = [
            'task' => [
                'completed' => (int) ($counts['completed_tasks'] ?? 0),
                'active' => (int) ($counts['active_tasks'] ?? 0),
                'pending' => (int) ($counts['pending_tasks'] ?? 0),
                'in_progress' => (int) ($counts['in_progress_tasks'] ?? 0)
            ],
            'attendance' => [
                'labels' => array_map(function($d) { return date('d M', strtotime($d)); }, $days),
                'present' => array_map(function($d) use ($presentMap) { return (int) ($presentMap[$d] ?? 0); }, $days)
            ],
            'budget' => [
                'total' => (float) $budgetHandledTotal,
                'spent' => (float) $budgetHandledSpent,
                'remaining' => (float) $budgetHandledRemaining
            ]
        ];

        $name = (string) ($emp['name'] ?? '');
        $initials = '';
        foreach (preg_split('/\s+/', trim($name)) as $part) {
            if ($part === '') continue;
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) break;
        }
        if ($initials === '') $initials = 'EM';

        $status = (string) ($emp['status'] ?? 'active');
        $statusBadge = $status === 'inactive' ? 'bg-secondary' : 'bg-success';

        $tierBadge = $incentiveTier === 'high' ? 'bg-success' : ($incentiveTier === 'medium' ? 'bg-primary' : ($incentiveTier === 'basic' ? 'bg-warning' : 'bg-secondary'));

        echo "<div class='d-flex flex-wrap align-items-center gap-3 mb-3'>";
        echo "<div class='rounded-circle d-flex align-items-center justify-content-center fw-bold' style='width:64px;height:64px;background:rgba(41,182,246,0.20);border:1px solid rgba(229,231,235,0.10);color:#E5E7EB;'>" . htmlspecialchars($initials) . "</div>";
        echo "<div class='flex-grow-1'>";
        echo "<div class='d-flex flex-wrap align-items-center gap-2'>";
        echo "<div class='h5 mb-0'>" . htmlspecialchars($name) . "</div>";
        echo "<span class='badge " . $statusBadge . "'>" . htmlspecialchars(ucfirst($status)) . "</span>";
        echo "<span class='badge bg-primary'>EMP" . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT) . "</span>";
        echo "</div>";
        echo "<div class='text-muted small'>" . htmlspecialchars((string) ($emp['designation'] ?? '')) . " • " . htmlspecialchars((string) ($emp['email'] ?? '')) . "</div>";
        echo "</div>";
        echo "<div class='d-flex flex-wrap gap-2 ms-auto'>";
        echo "<a class='btn btn-secondary btn-sm' href='employees.php?edit=" . (int) $employeeId . "'><i class='fas fa-user-pen me-2'></i>Edit</a>";
        echo "<a class='btn btn-secondary btn-sm' href='clients.php?assigned_to=" . (int) $employeeId . "'><i class='fas fa-building me-2'></i>Clients</a>";
        echo "<a class='btn btn-secondary btn-sm' href='attendance.php?employee=" . (int) $employeeId . "'><i class='fas fa-clock me-2'></i>Attendance</a>";
        echo "<a class='btn btn-secondary btn-sm' href='expenses.php?employee=" . (int) $employeeId . "'><i class='fas fa-money-bill-wave me-2'></i>Expenses</a>";
        echo "<a class='btn btn-secondary btn-sm' href='payroll.php?employee=" . (int) $employeeId . "&month=" . htmlspecialchars(urlencode($month)) . "'><i class='fas fa-calculator me-2'></i>Payroll</a>";
        echo "<a class='btn btn-secondary btn-sm' href='reports.php?employee=" . (int) $employeeId . "&month=" . htmlspecialchars(urlencode($month)) . "'><i class='fas fa-chart-bar me-2'></i>Report</a>";
        echo "<button class='btn btn-primary btn-sm' type='button' onclick='window.printElement && printElement(\"employeeProfileContent\")'><i class='fas fa-print me-2'></i>Export PDF</button>";
        echo "<a class='btn btn-primary btn-sm' href='mailto:" . htmlspecialchars((string) ($emp['email'] ?? '')) . "'><i class='fas fa-envelope me-2'></i>Message</a>";
        echo "</div>";
        echo "</div>";

        echo "<div id='employeeProfileContent'>";
        echo "<div class='row g-3 mb-3'>";
        echo "<div class='col-md-3'><div class='stat-card primary h-100'><div class='stat-icon'><i class='fas fa-gauge-high'></i></div><div class='stat-value'>" . (int) $performanceScore . "/100</div><div class='stat-label'>Performance</div><small class='text-muted'>" . htmlspecialchars(date('F Y', strtotime($month . '-01'))) . "</small></div></div>";
        echo "<div class='col-md-3'><div class='stat-card info h-100'><div class='stat-icon'><i class='fas fa-percentage'></i></div><div class='stat-value'>" . htmlspecialchars((string) round($attendancePercent, 1)) . "%</div><div class='stat-label'>Attendance</div><small class='text-muted'>" . (int) $lateDays . " late day(s)</small></div></div>";
        echo "<div class='col-md-3'><div class='stat-card success h-100'><div class='stat-icon'><i class='fas fa-coins'></i></div><div class='stat-value'>" . htmlspecialchars(formatCurrency($incentiveAmount)) . "</div><div class='stat-label'>Incentives</div><small class='text-muted'><span class='badge " . $tierBadge . "'>" . htmlspecialchars($incentiveTier === 'none' ? 'None' : ucfirst($incentiveTier)) . "</span></small></div></div>";
        echo "<div class='col-md-3'><div class='stat-card warning h-100'><div class='stat-icon'><i class='fas fa-list-check'></i></div><div class='stat-value'>" . (int) ($counts['active_tasks'] ?? 0) . "</div><div class='stat-label'>Active Tasks</div><small class='text-muted'>" . htmlspecialchars((string) $taskCompletionRate) . "% completion</small></div></div>";
        echo "</div>";

        echo "<div class='accordion' id='employeeProfileAccordion'>";
        echo "<div class='accordion-item mb-2'><h2 class='accordion-header'><button class='accordion-button' type='button' data-bs-toggle='collapse' data-bs-target='#empBasic'>Basic Info</button></h2><div id='empBasic' class='accordion-collapse collapse show' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-4'><div class='text-muted small'>Phone</div><div class='fw-semibold'>" . htmlspecialchars((string) ($emp['phone'] ?? '-')) . "</div></div>";
        echo "<div class='col-md-4'><div class='text-muted small'>Joining Date</div><div class='fw-semibold'>" . htmlspecialchars(!empty($emp['join_date']) ? formatDate($emp['join_date']) : '-') . "</div></div>";
        echo "<div class='col-md-4'><div class='text-muted small'>Salary</div><div class='fw-semibold'>" . htmlspecialchars(formatCurrency((float) ($emp['salary'] ?? 0))) . "</div></div>";
        echo "<div class='col-12'><div class='text-muted small'>Address</div><div class='fw-semibold'>" . htmlspecialchars((string) (($emp['address'] ?? '') !== '' ? $emp['address'] : '-')) . "</div></div>";
        echo "</div>";
        echo "</div></div></div>";

        echo "<div class='accordion-item mb-2'><h2 class='accordion-header'><button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#empWork'>Work Details</button></h2><div id='empWork' class='accordion-collapse collapse' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        echo "<div class='row g-3 mb-3'>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Assigned Clients</div><div class='fw-bold'>" . (int) ($counts['assigned_clients'] ?? 0) . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Assigned Events</div><div class='fw-bold'>" . (int) ($counts['assigned_events'] ?? 0) . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Completed Tasks</div><div class='fw-bold'>" . (int) ($counts['completed_tasks'] ?? 0) . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Overdue Tasks</div><div class='fw-bold'>" . (int) $tasksOverdue . "</div></div></div>";
        echo "</div>";

        echo "<div class='row g-3'>";
        echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header d-flex justify-content-between align-items-center'><h6 class='mb-0'>Recent Tasks</h6><span class='text-muted small'>" . count($taskRows) . " shown</span></div><div class='card-body'><div class='mb-3'><canvas id='empTaskBreakdown' height='140'></canvas></div>";
        if (empty($taskRows)) {
            echo "<div class='text-muted'>No tasks found.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm mb-0' id='empTasksTable' data-smart-table data-page-size='10' data-export-name='employee_tasks.csv'><thead><tr><th>Task</th><th>Due</th><th>Status</th></tr></thead><tbody>";
            foreach ($taskRows as $t) {
                $st = (string) ($t['status'] ?? 'pending');
                $badge = $st === 'completed' ? 'bg-success' : ($st === 'in_progress' ? 'bg-primary' : 'bg-warning');
                $due = !empty($t['due_at']) ? date('d M Y', strtotime((string) $t['due_at'])) : '-';
                echo "<tr>";
                echo "<td><div class='fw-semibold'>" . htmlspecialchars((string) ($t['title'] ?? '')) . "</div>";
                $meta = [];
                if (!empty($t['client_name'])) $meta[] = (string) $t['client_name'];
                if (!empty($t['event_name'])) $meta[] = (string) $t['event_name'];
                if ($meta) echo "<div class='text-muted small'>" . htmlspecialchars(implode(' • ', $meta)) . "</div>";
                echo "</td>";
                echo "<td class='text-muted small'>" . htmlspecialchars($due) . "</td>";
                echo "<td><span class='badge " . $badge . "'>" . htmlspecialchars($st === 'in_progress' ? 'In Progress' : ucfirst($st)) . "</span></td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div></div>";

        echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h6 class='mb-0'>Assign Task</h6></div><div class='card-body'>";
        echo "<form id='employeeAssignTaskForm' data-employee-id='" . (int) $employeeId . "'>";
        echo "<div class='mb-2'><label class='form-label'>Title</label><input class='form-control' name='title' required></div>";
        echo "<div class='mb-2'><label class='form-label'>Description</label><textarea class='form-control' name='description' rows='2'></textarea></div>";
        echo "<div class='row g-2'><div class='col-md-6'><label class='form-label'>Due</label><input type='datetime-local' class='form-control' name='due_at'></div>";
        echo "<div class='col-md-6'><label class='form-label'>Client (optional)</label><select class='form-select js-searchable-select' name='client_id'><option value=''>No client</option>";
        foreach ($assignedClients as $c) {
            echo "<option value='" . (int) $c['id'] . "'>" . htmlspecialchars((string) ($c['name'] ?? '')) . "</option>";
        }
        echo "</select></div></div>";
        echo "<div class='mt-3 d-flex gap-2'><button type='submit' class='btn btn-primary'><i class='fas fa-plus me-2'></i>Assign</button>";
        echo "<a class='btn btn-secondary' href='employees.php?edit=" . (int) $employeeId . "'>Manage</a></div>";
        echo "</form>";
        echo "<div class='text-muted small mt-3'>Client feedback and leave records are not configured in this system schema.</div>";
        echo "</div></div></div>";
        echo "</div>";
        echo "</div></div></div>";

        echo "<div class='accordion-item mb-2'><h2 class='accordion-header'><button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#empAttendance'>Attendance</button></h2><div id='empAttendance' class='accordion-collapse collapse' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        echo "<div class='row g-3 mb-3'><div class='col-md-4'><canvas id='empAttendanceTrend' height='120'></canvas></div><div class='col-md-8'>";
        if (empty($attendanceRows)) {
            echo "<div class='text-muted'>No attendance records.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm mb-0' id='empAttendanceTable' data-smart-table data-page-size='10' data-export-name='employee_attendance.csv'><thead><tr><th>Status</th><th>Date</th><th>In</th><th>Out</th><th>GPS</th><th>Proof</th></tr></thead><tbody>";
            foreach ($attendanceRows as $a) {
                $gps = (!empty($a['latitude']) && !empty($a['longitude'])) ? 'Yes' : '-';
                $proof = !empty($a['image']) ? 'Yes' : '-';
                echo "<tr>";
                echo "<td>" . renderAttendanceStatusBadge($a['check_in'] ?? null) . "</td>";
                echo "<td>" . htmlspecialchars(formatDate((string) ($a['date'] ?? ''))) . "</td>";
                echo "<td>" . htmlspecialchars(!empty($a['check_in']) ? substr((string) $a['check_in'], 0, 5) : '-') . "</td>";
                echo "<td>" . htmlspecialchars(!empty($a['check_out']) ? substr((string) $a['check_out'], 0, 5) : '-') . "</td>";
                echo "<td class='text-muted small'>" . htmlspecialchars($gps) . "</td>";
                echo "<td class='text-muted small'>" . htmlspecialchars($proof) . "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div>";
        echo "</div></div></div>";

        echo "<div class='accordion-item mb-2'><h2 class='accordion-header'><button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#empFinance'>Financial</button></h2><div id='empFinance' class='accordion-collapse collapse' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        echo "<div class='row g-3 mb-3'>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Salary</div><div class='fw-bold'>" . htmlspecialchars(formatCurrency((float) ($emp['salary'] ?? 0))) . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Expense Claims</div><div class='fw-bold'>" . count($expenseRows) . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Pending Reimbursements</div><div class='fw-bold'>" . (int) $pendingReimb . "</div></div></div>";
        echo "<div class='col-md-3'><div class='p-3 border rounded-3 h-100'><div class='text-muted small'>Budget Handled</div><div class='fw-bold'>" . htmlspecialchars(formatCurrency($budgetHandledTotal)) . "</div></div></div>";
        echo "</div>";

        echo "<div class='mb-3'><div class='d-flex justify-content-between small text-muted mb-1'><span>Budget utilization</span><span>" . htmlspecialchars((string) $budgetHandledUtilization) . "%</span></div>";
        echo "<div class='progress' style='height:10px;'><div class='progress-bar bg-primary' role='progressbar' style='width:" . (float) $budgetHandledUtilization . "%'></div></div>";
        echo "<div class='d-flex justify-content-between small text-muted mt-2'><span>Used " . htmlspecialchars(formatCurrency($budgetHandledSpent)) . "</span><span>Remaining " . htmlspecialchars(formatCurrency($budgetHandledRemaining)) . "</span></div></div>";
        echo "<div class='mb-3'><canvas id='empBudgetChart' height='120'></canvas></div>";

        if (!empty($expenseRows)) {
            echo "<div class='table-responsive'><table class='table table-sm mb-0' id='empExpensesTable' data-smart-table data-page-size='10' data-export-name='employee_expenses.csv'><thead><tr><th>Date</th><th>Client</th><th>Event</th><th>Amount</th><th>Status</th></tr></thead><tbody>";
            foreach ($expenseRows as $ex) {
                $st = (string) ($ex['status'] ?? 'pending');
                $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning');
                echo "<tr>";
                echo "<td>" . htmlspecialchars(formatDate((string) ($ex['created_at'] ?? ''), 'd M Y')) . "</td>";
                echo "<td>" . htmlspecialchars((string) (($ex['client_name'] ?? '') ?: '-')) . "</td>";
                echo "<td>" . htmlspecialchars((string) (($ex['event_name'] ?? '') ?: '-')) . "</td>";
                echo "<td class='fw-semibold'>" . htmlspecialchars(formatCurrency((float) ($ex['amount'] ?? 0))) . "</td>";
                echo "<td><span class='badge " . $badge . "'>" . htmlspecialchars(ucfirst($st)) . "</span></td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "<div class='text-muted'>No expenses.</div>";
        }
        echo "</div></div></div>";

        echo "<div class='accordion-item mb-2'><h2 class='accordion-header'><button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#empClients'>Client Management</button></h2><div id='empClients' class='accordion-collapse collapse' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        if (empty($assignedClients)) {
            echo "<div class='text-muted'>No clients assigned.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm mb-0' id='empClientsTable' data-smart-table data-page-size='10' data-export-name='employee_clients.csv'><thead><tr><th>Client</th><th>Workflow</th><th>Updated</th></tr></thead><tbody>";
            foreach ($assignedClients as $c) {
                $wf = (string) ($c['workflow_status'] ?? 'New Lead');
                $updated = !empty($c['workflow_updated_at']) ? formatDate((string) $c['workflow_updated_at'], 'd M Y') : '-';
                echo "<tr>";
                echo "<td class='fw-semibold'>" . htmlspecialchars((string) ($c['name'] ?? '')) . "</td>";
                echo "<td><span class='badge bg-secondary'>" . htmlspecialchars($wf) . "</span></td>";
                echo "<td class='text-muted small'>" . htmlspecialchars($updated) . "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }

        if (!empty($clientNotes)) {
            echo "<div class='mt-3'><div class='fw-semibold mb-2'>Recent Communication Notes</div>";
            foreach ($clientNotes as $n) {
                $when = !empty($n['created_at']) ? date('d M Y, h:i A', strtotime((string) $n['created_at'])) : '';
                echo "<div class='p-3 rounded mb-2' style='background: rgba(255,255,255,0.03); border: 1px solid rgba(229,231,235,0.08);'>";
                echo "<div class='d-flex justify-content-between align-items-center'>";
                echo "<div class='fw-semibold'>" . htmlspecialchars((string) ($n['client_name'] ?? 'Client')) . "</div>";
                echo "<div class='text-muted small'>" . htmlspecialchars($when) . "</div>";
                echo "</div>";
                echo "<div class='text-muted small mt-1'>" . nl2br(htmlspecialchars((string) ($n['note'] ?? ''))) . "</div>";
                echo "</div>";
            }
            echo "</div>";
        }
        echo "</div></div></div>";

        echo "<div class='accordion-item'><h2 class='accordion-header'><button class='accordion-button collapsed' type='button' data-bs-toggle='collapse' data-bs-target='#empUploads'>Uploads & Reports</button></h2><div id='empUploads' class='accordion-collapse collapse' data-bs-parent='#employeeProfileAccordion'><div class='accordion-body'>";
        if (empty($reportRows)) {
            echo "<div class='text-muted'>No project expense reports uploaded.</div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-sm mb-0' id='empProjectReportsTable' data-smart-table data-page-size='10' data-export-name='employee_project_reports.csv'><thead><tr><th>Submitted</th><th>Client</th><th>Event</th><th>Total</th><th>Status</th><th>File</th></tr></thead><tbody>";
            foreach ($reportRows as $r) {
                $st = (string) ($r['status'] ?? 'pending');
                $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning');
                $file = (string) ($r['file_path'] ?? '');
                $fileUrl = $file !== '' ? (SITE_URL . 'uploads/' . ltrim($file, '/')) : '';
                echo "<tr>";
                echo "<td class='text-muted small'>" . htmlspecialchars(!empty($r['submitted_at']) ? formatDate((string) $r['submitted_at'], 'd M Y') : '-') . "</td>";
                echo "<td>" . htmlspecialchars((string) (($r['client_name'] ?? '') ?: '-')) . "</td>";
                echo "<td>" . htmlspecialchars((string) (($r['event_name'] ?? '') ?: '-')) . "</td>";
                echo "<td class='fw-semibold'>" . htmlspecialchars(formatCurrency((float) ($r['total_amount'] ?? 0))) . "</td>";
                echo "<td><span class='badge " . $badge . "'>" . htmlspecialchars(ucfirst($st)) . "</span></td>";
                echo "<td>";
                if ($fileUrl !== '') {
                    echo "<a class='btn btn-sm btn-secondary' target='_blank' href='" . htmlspecialchars($fileUrl) . "'><i class='fas fa-file-excel me-2'></i>Open</a>";
                } else {
                    echo "<span class='text-muted'>-</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }
        echo "</div></div></div>";
        echo "</div>";
        echo "</div>";

        echo "<script type='application/json' id='employeeProfileChartData'>" . json_encode($chartData) . "</script>";
        exit();
    } catch (PDOException $e) {
        echo "<div class='text-muted'>Failed to load profile.</div>";
        exit();
    }
}

$pageTitle = 'Admin Dashboard';
require_once '../includes/header.php';
requireAdmin();
ensureExpenseCategorizationSchema();
ensureClientWorkflowSchema();
ensureTaskWorkflowSchema();
ensureEventProfitFeedbackIncentiveSchema();

$emp_q = isset($_GET['emp_q']) ? trim((string) clean_input($_GET['emp_q'])) : '';
$emp_designation = isset($_GET['emp_designation']) ? clean_input($_GET['emp_designation']) : '';
$emp_client = isset($_GET['emp_client']) ? clean_input($_GET['emp_client']) : '';
$emp_event = isset($_GET['emp_event']) ? clean_input($_GET['emp_event']) : '';
$emp_tasks_min = isset($_GET['emp_tasks_min']) ? clean_input($_GET['emp_tasks_min']) : '';
$emp_attendance_status = isset($_GET['emp_attendance_status']) ? clean_input($_GET['emp_attendance_status']) : '';
$emp_perf_min = isset($_GET['emp_perf_min']) ? clean_input($_GET['emp_perf_min']) : '';
$emp_perf_max = isset($_GET['emp_perf_max']) ? clean_input($_GET['emp_perf_max']) : '';
$emp_salary_min = isset($_GET['emp_salary_min']) ? clean_input($_GET['emp_salary_min']) : '';
$emp_salary_max = isset($_GET['emp_salary_max']) ? clean_input($_GET['emp_salary_max']) : '';
$emp_join_from = isset($_GET['emp_join_from']) ? clean_input($_GET['emp_join_from']) : '';
$emp_join_to = isset($_GET['emp_join_to']) ? clean_input($_GET['emp_join_to']) : '';
$emp_sort = isset($_GET['emp_sort']) ? clean_input($_GET['emp_sort']) : 'name_asc';

$employeeFilterClients = [];
$employeeFilterEvents = [];
$employeeFilterDesignations = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY name");
    $employeeFilterClients = $stmt->fetchAll();
} catch (PDOException $e) {
    $employeeFilterClients = [];
}
try {
    $stmt = $pdo->query("SELECT id, name FROM events ORDER BY start_date DESC, name ASC");
    $employeeFilterEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    $employeeFilterEvents = [];
}
try {
    $stmt = $pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation <> '' ORDER BY designation");
    $employeeFilterDesignations = array_values(array_filter(array_map(function($r) {
        return (string) ($r['designation'] ?? '');
    }, $stmt->fetchAll()), function($v) { return $v !== ''; }));
} catch (PDOException $e) {
    $employeeFilterDesignations = [];
}

// Get dashboard statistics
try {
    // Total employees
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE status = 'active'");
    $totalEmployees = $stmt->fetch()['total'];
    
    // Today's attendance
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM attendance WHERE date = CURDATE() AND check_in IS NOT NULL");
    $stmt->execute();
    $todayAttendance = $stmt->fetch()['total'];
    
    // Total expenses this month
    $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status = 'approved'");
    $stmt->execute([getCurrentMonth()]);
    $totalExpenses = $stmt->fetch()['total'] ?: 0;

    // Pending expense approvals
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM expenses WHERE status = 'pending'");
    $pendingExpenseApprovals = (int) (($stmt->fetch()['total'] ?? 0));

    $currentMonth = getCurrentMonth();
    $companyProfit = getCompanyProfitSummaryForMonth($currentMonth);
    recomputeMonthlyScoresAndRankings($currentMonth);
    $bonusTopRankings = getTopEmployeeRankings($currentMonth, 5);

    // Budget analytics (active/planning events)
    $stmt = $pdo->query("SELECT COALESCE(SUM(budget), 0) as total_budget FROM events WHERE status IN ('planning','active')");
    $totalActiveBudget = (float) (($stmt->fetch()['total_budget'] ?? 0));

    $stmt = $pdo->query("SELECT COALESCE(SUM(ex.amount), 0) as approved_client_expenses
                         FROM expenses ex
                         JOIN events ev ON ev.id = ex.event_id
                         WHERE ev.status IN ('planning','active')
                           AND ex.status = 'approved'
                           AND COALESCE(ex.expense_category, 'personal') = 'client'");
    $approvedClientExpensesActive = (float) (($stmt->fetch()['approved_client_expenses'] ?? 0));

    $remainingBudgetActive = max(0, $totalActiveBudget - $approvedClientExpensesActive);
    $budgetUtilizationActive = $totalActiveBudget > 0 ? min(100, round(($approvedClientExpensesActive / $totalActiveBudget) * 100, 1)) : 0;
    
    // Active events
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM events WHERE status IN ('planning', 'active')");
    $activeEvents = (int) (($stmt->fetch()['total'] ?? 0));

    // WhatsApp Stats
    $waStats = function_exists('wa_getStats') ? wa_getStats('month') : ['total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'queued' => 0, 'pending_queue' => 0];

    $todayBooking = getClientBookingAvailability(date('Y-m-d'));
    
    // Recent attendance
    $stmt = $pdo->query("SELECT a.*, u.name FROM attendance a JOIN users u ON a.user_id = u.id ORDER BY a.date DESC, a.check_in DESC LIMIT 10");
    $recentAttendance = $stmt->fetchAll();
    
    // Recent expenses
    $stmt = $pdo->query("SELECT e.*, u.name FROM expenses e JOIN users u ON e.user_id = u.id ORDER BY e.created_at DESC LIMIT 10");
    $recentExpenses = $stmt->fetchAll();
    
    // Upcoming events
    $stmt = $pdo->query("SELECT e.*, c.name as client_name,
                                (SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ')
                                 FROM event_team et2
                                 JOIN users u2 ON u2.id = et2.user_id
                                 WHERE et2.event_id = e.id) as employee_names
                         FROM events e
                         LEFT JOIN clients c ON e.client_id = c.id
                         WHERE e.start_date >= CURDATE()
                         ORDER BY e.start_date ASC
                         LIMIT 5");
    $upcomingEvents = $stmt->fetchAll();

    $perfMonth = getCurrentMonth();
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation, e.salary
                         FROM users u
                         LEFT JOIN employees e ON e.user_id = u.id
                         WHERE u.role = 'employee'
                         ORDER BY u.name");
    $employeeRows = $stmt->fetchAll();

    $performances = [];
    foreach ($employeeRows as $emp) {
        $p = getEmployeeMonthlyPerformance((int) $emp['id'], $perfMonth);
        $performances[] = [
            'id' => (int) $emp['id'],
            'name' => (string) ($emp['name'] ?? ''),
            'designation' => (string) ($emp['designation'] ?? ''),
            'score' => (int) ($p['total_score'] ?? 0),
            'tier' => (string) ($p['incentive_tier'] ?? 'none'),
            'incentive' => (float) ($p['incentive_amount'] ?? 0),
            'attendance_percent' => (float) ($p['attendance_percent'] ?? 0),
            'tasks_completed' => (int) ($p['tasks_completed'] ?? 0),
            'overdue_tasks' => (int) ($p['overdue_tasks'] ?? 0),
            'late_days' => (int) ($p['late_days'] ?? 0)
        ];
    }

    usort($performances, function($a, $b) {
        return ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']);
    });

    $topPerformers = array_slice($performances, 0, 5);
    $lowPerformers = array_values(array_filter($performances, function($p) {
        return (int) $p['score'] < 60;
    }));
    $lowPerformers = array_slice($lowPerformers, 0, 5);

    $totalIncentives = 0;
    foreach ($performances as $p) {
        $totalIncentives += (float) ($p['incentive'] ?? 0);
    }

    $scoreBuckets = [
        '90-100' => 0,
        '75-89' => 0,
        '60-74' => 0,
        '0-59' => 0
    ];
    foreach ($performances as $p) {
        $s = (int) ($p['score'] ?? 0);
        if ($s >= 90) $scoreBuckets['90-100']++;
        elseif ($s >= 75) $scoreBuckets['75-89']++;
        elseif ($s >= 60) $scoreBuckets['60-74']++;
        else $scoreBuckets['0-59']++;
    }

    $todayAttendanceMap = [];
    try {
        $stmt = $pdo->query("SELECT user_id,
                                    MAX(check_in) as last_check_in,
                                    MAX(check_out) as last_check_out,
                                    SUM(CASE WHEN check_in IS NOT NULL THEN 1 ELSE 0 END) as sessions,
                                    SUM(CASE WHEN check_in IS NOT NULL AND check_in > '12:00:00' THEN 1 ELSE 0 END) as late_sessions,
                                    SUM(CASE WHEN check_in IS NOT NULL AND check_out IS NULL THEN 1 ELSE 0 END) as active_sessions
                             FROM attendance
                             WHERE date = CURDATE()
                             GROUP BY user_id");
        foreach ($stmt->fetchAll() as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid < 1) continue;
            $todayAttendanceMap[$uid] = [
                'sessions' => (int) ($r['sessions'] ?? 0),
                'late_sessions' => (int) ($r['late_sessions'] ?? 0),
                'active_sessions' => (int) ($r['active_sessions'] ?? 0),
                'last_check_in' => (string) ($r['last_check_in'] ?? ''),
                'last_check_out' => (string) ($r['last_check_out'] ?? '')
            ];
        }
    } catch (PDOException $e) {
        $todayAttendanceMap = [];
    }

    $employeeTableRows = [];
    $employeePerfMonth = $perfMonth;
    try {
        $query = "SELECT u.id, u.name, u.email,
                         e.designation, e.salary, e.join_date, e.status,
                         (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) as assigned_clients,
                         (SELECT COUNT(DISTINCT et.event_id) FROM event_team et WHERE et.user_id = u.id) as assigned_events,
                         (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status IN ('pending','in_progress')) as active_tasks,
                         (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status = 'completed') as completed_tasks
                  FROM users u
                  LEFT JOIN employees e ON e.user_id = u.id
                  WHERE u.role = 'employee'";
        $params = [];

        if ($emp_q !== '') {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
            $like = '%' . $emp_q . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($emp_designation !== '') {
            $query .= " AND e.designation = ?";
            $params[] = $emp_designation;
        }

        if ($emp_client !== '') {
            $query .= " AND EXISTS (SELECT 1 FROM clients c2 WHERE c2.assigned_to = u.id AND c2.id = ?)";
            $params[] = $emp_client;
        }

        if ($emp_event !== '') {
            $query .= " AND EXISTS (SELECT 1 FROM event_team et2 WHERE et2.user_id = u.id AND et2.event_id = ?)";
            $params[] = $emp_event;
        }

        if ($emp_tasks_min !== '' && is_numeric($emp_tasks_min)) {
            $query .= " AND (SELECT COUNT(*) FROM employee_tasks t2 WHERE t2.user_id = u.id AND t2.status IN ('pending','in_progress')) >= ?";
            $params[] = (int) $emp_tasks_min;
        }

        if ($emp_salary_min !== '' && is_numeric($emp_salary_min)) {
            $query .= " AND COALESCE(e.salary, 0) >= ?";
            $params[] = (float) $emp_salary_min;
        }
        if ($emp_salary_max !== '' && is_numeric($emp_salary_max)) {
            $query .= " AND COALESCE(e.salary, 0) <= ?";
            $params[] = (float) $emp_salary_max;
        }

        if ($emp_join_from !== '') {
            $query .= " AND e.join_date IS NOT NULL AND e.join_date >= ?";
            $params[] = $emp_join_from;
        }
        if ($emp_join_to !== '') {
            $query .= " AND e.join_date IS NOT NULL AND e.join_date <= ?";
            $params[] = $emp_join_to;
        }

        if ($emp_attendance_status !== '') {
            if ($emp_attendance_status === 'present') {
                $query .= " AND EXISTS (SELECT 1 FROM attendance a2 WHERE a2.user_id = u.id AND a2.date = CURDATE() AND a2.check_in IS NOT NULL)";
            } elseif ($emp_attendance_status === 'absent') {
                $query .= " AND NOT EXISTS (SELECT 1 FROM attendance a2 WHERE a2.user_id = u.id AND a2.date = CURDATE() AND a2.check_in IS NOT NULL)";
            } elseif ($emp_attendance_status === 'late') {
                $query .= " AND EXISTS (SELECT 1 FROM attendance a2 WHERE a2.user_id = u.id AND a2.date = CURDATE() AND a2.check_in IS NOT NULL AND a2.check_in > '12:00:00')";
            } elseif ($emp_attendance_status === 'active_session') {
                $query .= " AND EXISTS (SELECT 1 FROM attendance a2 WHERE a2.user_id = u.id AND a2.date = CURDATE() AND a2.check_in IS NOT NULL AND a2.check_out IS NULL)";
            }
        }

        $orderBy = "u.name ASC";
        if ($emp_sort === 'name_desc') $orderBy = "u.name DESC";
        elseif ($emp_sort === 'join_new') $orderBy = "e.join_date DESC, u.name ASC";
        elseif ($emp_sort === 'join_old') $orderBy = "e.join_date ASC, u.name ASC";
        elseif ($emp_sort === 'salary_desc') $orderBy = "COALESCE(e.salary,0) DESC, u.name ASC";
        elseif ($emp_sort === 'salary_asc') $orderBy = "COALESCE(e.salary,0) ASC, u.name ASC";
        elseif ($emp_sort === 'tasks_desc') $orderBy = "active_tasks DESC, u.name ASC";
        elseif ($emp_sort === 'clients_desc') $orderBy = "assigned_clients DESC, u.name ASC";
        elseif ($emp_sort === 'events_desc') $orderBy = "assigned_events DESC, u.name ASC";
        elseif ($emp_sort === 'performance_desc') $orderBy = "u.name ASC";

        $query .= " ORDER BY $orderBy";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $employeeTableRows = $stmt->fetchAll();

        $rowsWithPerf = [];
        foreach ($employeeTableRows as $r) {
            $uid = (int) ($r['id'] ?? 0);
            $p = getEmployeeMonthlyPerformance($uid, $employeePerfMonth);
            $r['performance_score'] = (int) ($p['total_score'] ?? 0);
            $r['attendance_percent'] = (float) ($p['attendance_percent'] ?? 0);
            $r['incentive_amount'] = (float) ($p['incentive_amount'] ?? 0);
            $r['incentive_tier'] = (string) ($p['incentive_tier'] ?? 'none');
            $rowsWithPerf[] = $r;
        }

        $employeeTableRows = array_values(array_filter($rowsWithPerf, function($r) use ($emp_perf_min, $emp_perf_max) {
            $s = (int) ($r['performance_score'] ?? 0);
            if ($emp_perf_min !== '' && is_numeric($emp_perf_min) && $s < (int) $emp_perf_min) return false;
            if ($emp_perf_max !== '' && is_numeric($emp_perf_max) && $s > (int) $emp_perf_max) return false;
            return true;
        }));

        if ($emp_sort === 'performance_desc') {
            usort($employeeTableRows, function($a, $b) {
                return ((int) ($b['performance_score'] ?? 0) <=> (int) ($a['performance_score'] ?? 0)) ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
        }
    } catch (PDOException $e) {
        $employeeTableRows = [];
    }
    
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
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
            <h1 class="h3 page-title">Dashboard</h1>
            <div class="page-subtitle">Monitor events, approvals, and team activity</div>
        </div>
        <div class="page-actions">
            <span class="badge bg-success">Admin</span>
            <span class="text-muted small"><?php echo date('d M Y, h:i A'); ?></span>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-primary" href="events.php?open=add"><i class="fas fa-plus me-2"></i>Create Event</a>
        <a class="btn btn-secondary" href="employees.php?open=add"><i class="fas fa-user-plus me-2"></i>Add Employee</a>
        <a class="btn btn-secondary" href="reports.php"><i class="fas fa-chart-bar me-2"></i>Reports</a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4 dashboard-stats row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4">
        <div class="col">
            <a href="events.php" class="stat-card-link">
                <div class="stat-card primary">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="stat-value"><?php echo $activeEvents; ?></div>
                            <div class="stat-label">Active Events</div>
                            <small class="text-muted">Planning + active</small>
                        </div>
                        <span class="stat-pill positive"><i class="fas fa-circle"></i> Live</span>
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
                <div class="stat-card info">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
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
            <a href="expenses.php" class="stat-card-link">
                <div class="stat-card success">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency($remainingBudgetActive ?? 0); ?></div>
                            <div class="stat-label">Remaining Budget</div>
                            <small class="text-muted">Active events</small>
                        </div>
                        <span class="stat-pill positive"><i class="fas fa-chart-pie"></i> <?php echo htmlspecialchars((string) ($budgetUtilizationActive ?? 0)); ?>%</span>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Used</span>
                            <span><?php echo formatCurrency($approvedClientExpensesActive ?? 0); ?> / <?php echo formatCurrency($totalActiveBudget ?? 0); ?></span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (float) ($budgetUtilizationActive ?? 0); ?>%"></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="expenses.php" class="stat-card-link">
                <div class="stat-card warning">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-circle-check"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency($totalExpenses); ?></div>
                            <div class="stat-label">Total Approved Expenses</div>
                            <small class="text-muted">Current month</small>
                        </div>
                        <span class="stat-pill positive"><i class="fas fa-receipt"></i> Approved</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="whatsapp.php" class="stat-card-link">
                <div class="stat-card" style="border-left: 4px solid #25D366; background: rgba(37,211,102,0.05);">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon" style="background: rgba(37,211,102,0.15); color: #25D366;">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($waStats['total']); ?></div>
                            <div class="stat-label">WhatsApp Sent</div>
                            <small class="text-muted">Current month</small>
                        </div>
                        <span class="stat-pill" style="background: rgba(37,211,102,0.1); color: #25D366;">
                            <i class="fas fa-paper-plane"></i> <?php echo number_format($waStats['sent'] + $waStats['delivered'] + $waStats['read']); ?> Delivered
                        </span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="expenses.php?status%5B%5D=pending" class="stat-card-link">
                <div class="stat-card info">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="stat-value"><?php echo (int) ($pendingExpenseApprovals ?? 0); ?></div>
                            <div class="stat-label">Pending Approvals</div>
                            <small class="text-muted">Expenses awaiting review</small>
                        </div>
                        <span class="stat-pill <?php echo ($pendingExpenseApprovals ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                            <i class="fas fa-<?php echo ($pendingExpenseApprovals ?? 0) > 0 ? 'triangle-exclamation' : 'check'; ?>"></i>
                            <?php echo ($pendingExpenseApprovals ?? 0) > 0 ? 'Action' : 'Clear'; ?>
                        </span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <?php
                $netCompany = (float) (($companyProfit['total_profit'] ?? 0) - ($companyProfit['total_loss'] ?? 0));
                $netIsProfit = $netCompany >= 0;
            ?>
            <a href="reports.php" class="stat-card-link">
                <div class="stat-card <?php echo $netIsProfit ? 'success' : 'warning'; ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency(abs($netCompany)); ?></div>
                            <div class="stat-label"><?php echo $netIsProfit ? 'Net Profit' : 'Net Loss'; ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars(date('F Y', strtotime(getCurrentMonth() . '-01'))); ?></small>
                        </div>
                        <span class="stat-pill <?php echo $netIsProfit ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-<?php echo $netIsProfit ? 'arrow-trend-up' : 'arrow-trend-down'; ?>"></i>
                            <?php echo $netIsProfit ? 'Profit' : 'Loss'; ?>
                        </span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="payroll.php" class="stat-card-link">
                <div class="stat-card primary">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-gift"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency((float) ($companyProfit['incentive_payouts'] ?? 0)); ?></div>
                            <div class="stat-label">Incentive Payouts</div>
                            <small class="text-muted">Earned + paid</small>
                        </div>
                        <span class="stat-pill positive"><i class="fas fa-coins"></i> Incentives</span>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="events.php" class="stat-card-link">
                <div class="stat-card danger">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="stat-icon">
                                <i class="fas fa-triangle-exclamation"></i>
                            </div>
                            <div class="stat-value"><?php echo (int) ($companyProfit['loss_events'] ?? 0); ?></div>
                            <div class="stat-label">Loss Events</div>
                            <small class="text-muted">Completed this month</small>
                        </div>
                        <span class="stat-pill negative"><i class="fas fa-xmark"></i> Loss</span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Top Performers</h5>
                    <span class="badge bg-primary"><?php echo htmlspecialchars(date('F Y', strtotime($perfMonth . '-01'))); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($topPerformers)): ?>
                        <p class="text-muted mb-0">No performance data yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Score</th>
                                        <th>Tier</th>
                                        <th>Incentive</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topPerformers as $p): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($p['designation'] ?: ''); ?></small>
                                            </td>
                                            <td><span class="badge bg-success"><?php echo (int) $p['score']; ?>/100</span></td>
                                            <td>
                                                <span class="badge bg-<?php echo $p['tier'] === 'high' ? 'success' : ($p['tier'] === 'medium' ? 'primary' : ($p['tier'] === 'basic' ? 'warning' : 'danger')); ?>">
                                                    <?php echo $p['tier'] === 'none' ? 'None' : ucfirst($p['tier']); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold"><?php echo formatCurrency($p['incentive']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Performance Alerts</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($lowPerformers)): ?>
                        <div class="d-flex align-items-center gap-2 text-success">
                            <i class="fas fa-check-circle"></i>
                            <div class="fw-semibold">No low performance alerts</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lowPerformers as $p): ?>
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom" style="border-color: rgba(229, 231, 235, 0.08) !important;">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></div>
                                    <div class="text-muted small">
                                        <?php echo (int) $p['overdue_tasks']; ?> overdue • <?php echo (int) $p['late_days']; ?> late day(s)
                                    </div>
                                </div>
                                <span class="badge bg-danger"><?php echo (int) $p['score']; ?>/100</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Total incentives</span>
                            <span class="fw-bold"><?php echo formatCurrency($totalIncentives); ?></span>
                        </div>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="performanceBuckets"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">Profit-Qualified Incentive Leaderboard</h5>
                    <span class="badge bg-primary"><?php echo htmlspecialchars(date('F Y', strtotime(getCurrentMonth() . '-01'))); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($bonusTopRankings)): ?>
                        <p class="text-muted mb-0">No incentive leaderboard data yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Employee</th>
                                        <th>Score</th>
                                        <th>Avg Rating</th>
                                        <th>Incentives</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bonusTopRankings as $r): ?>
                                        <tr>
                                            <td><span class="badge bg-primary">#<?php echo (int) ($r['rank_pos'] ?? 0); ?></span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($r['name'] ?? '')); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars((string) ($r['designation'] ?? '')); ?></div>
                                            </td>
                                            <td><span class="badge bg-success"><?php echo (int) ($r['score'] ?? 0); ?>/100</span></td>
                                            <td>
                                                <?php
                                                    $ar = (float) ($r['avg_rating'] ?? 0);
                                                    $arInt = (int) round($ar);
                                                ?>
                                                <div class="d-inline-flex align-items-center gap-1">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star <?php echo $i <= $arInt ? 'text-warning' : 'text-muted'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <div class="text-muted small"><?php echo htmlspecialchars(number_format($ar, 2)); ?>/5</div>
                                            </td>
                                            <td class="fw-semibold"><?php echo formatCurrency((float) ($r['incentives_earned'] ?? 0)); ?></td>
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

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Event Growth</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="growthChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Status Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables -->
    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Events</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($upcomingEvents)): ?>
                        <p class="text-muted">No events found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm" id="recentEventsTable" data-smart-table data-export-name="recent_events.csv" data-page-size="10">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Event</th>
                                        <th>Date</th>
                                        <th>Employee Name</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcomingEvents as $event): ?>
                                        <?php
                                            $status = (string) ($event['status'] ?? '');
                                            $badge = 'bg-primary';
                                            if ($status === 'planning') $badge = 'bg-warning';
                                            elseif ($status === 'active') $badge = 'bg-primary';
                                            elseif ($status === 'completed') $badge = 'bg-success';
                                            elseif ($status === 'cancelled') $badge = 'bg-danger';
                                            $employeeNames = trim((string) ($event['employee_names'] ?? ''));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($event['client_name'] ?: '-'); ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($event['name']); ?></td>
                                            <td><?php echo formatDate($event['start_date']); ?></td>
                                            <td><?php echo htmlspecialchars($employeeNames !== '' ? $employeeNames : '-'); ?></td>
                                            <td>
                                                <span class="badge <?php echo $badge; ?>">
                                                    <?php echo ucfirst($status ?: 'planning'); ?>
                                                </span>
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
        <div class="col-lg-5 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Employees</h5>
                    <a class="btn btn-sm btn-secondary" href="dashboard.php"><i class="fas fa-rotate-left me-2"></i>Reset</a>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="js-auto-submit mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-12">
                                <label class="form-label">Search</label>
                                <input type="search" class="form-control" name="emp_q" value="<?php echo htmlspecialchars($emp_q); ?>" placeholder="Employee name or email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Designation</label>
                                <select class="form-select js-searchable-select" name="emp_designation">
                                    <option value="">All</option>
                                    <?php foreach ($employeeFilterDesignations as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo (string) $emp_designation === (string) $d ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($d); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assigned Client</label>
                                <select class="form-select js-searchable-select" name="emp_client">
                                    <option value="">All</option>
                                    <?php foreach ($employeeFilterClients as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $emp_client === (string) $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($c['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Event Assigned</label>
                                <select class="form-select js-searchable-select" name="emp_event">
                                    <option value="">All</option>
                                    <?php foreach ($employeeFilterEvents as $ev): ?>
                                        <option value="<?php echo (int) $ev['id']; ?>" <?php echo (string) $emp_event === (string) $ev['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($ev['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Attendance Status</label>
                                <select class="form-select" name="emp_attendance_status">
                                    <option value="">All</option>
                                    <option value="present" <?php echo $emp_attendance_status === 'present' ? 'selected' : ''; ?>>Present Today</option>
                                    <option value="absent" <?php echo $emp_attendance_status === 'absent' ? 'selected' : ''; ?>>Absent Today</option>
                                    <option value="late" <?php echo $emp_attendance_status === 'late' ? 'selected' : ''; ?>>Late Today</option>
                                    <option value="active_session" <?php echo $emp_attendance_status === 'active_session' ? 'selected' : ''; ?>>Active Session</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Active Tasks (min)</label>
                                <input type="number" class="form-control" name="emp_tasks_min" min="0" step="1" value="<?php echo htmlspecialchars((string) $emp_tasks_min); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Performance Score</label>
                                <div class="d-flex gap-2">
                                    <input type="number" class="form-control" name="emp_perf_min" min="0" max="100" step="1" value="<?php echo htmlspecialchars((string) $emp_perf_min); ?>" placeholder="Min">
                                    <input type="number" class="form-control" name="emp_perf_max" min="0" max="100" step="1" value="<?php echo htmlspecialchars((string) $emp_perf_max); ?>" placeholder="Max">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Salary Range</label>
                                <div class="d-flex gap-2">
                                    <input type="number" class="form-control" name="emp_salary_min" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $emp_salary_min); ?>" placeholder="Min">
                                    <input type="number" class="form-control" name="emp_salary_max" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $emp_salary_max); ?>" placeholder="Max">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Joined</label>
                                <div class="d-flex gap-2">
                                    <input type="date" class="form-control" name="emp_join_from" value="<?php echo htmlspecialchars((string) $emp_join_from); ?>">
                                    <input type="date" class="form-control" name="emp_join_to" value="<?php echo htmlspecialchars((string) $emp_join_to); ?>">
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Sort</label>
                                <select class="form-select" name="emp_sort">
                                    <option value="name_asc" <?php echo $emp_sort === 'name_asc' ? 'selected' : ''; ?>>Name (A–Z)</option>
                                    <option value="name_desc" <?php echo $emp_sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z–A)</option>
                                    <option value="performance_desc" <?php echo $emp_sort === 'performance_desc' ? 'selected' : ''; ?>>Performance (High → Low)</option>
                                    <option value="tasks_desc" <?php echo $emp_sort === 'tasks_desc' ? 'selected' : ''; ?>>Active Tasks (High → Low)</option>
                                    <option value="clients_desc" <?php echo $emp_sort === 'clients_desc' ? 'selected' : ''; ?>>Assigned Clients (High → Low)</option>
                                    <option value="events_desc" <?php echo $emp_sort === 'events_desc' ? 'selected' : ''; ?>>Assigned Events (High → Low)</option>
                                    <option value="salary_desc" <?php echo $emp_sort === 'salary_desc' ? 'selected' : ''; ?>>Salary (High → Low)</option>
                                    <option value="salary_asc" <?php echo $emp_sort === 'salary_asc' ? 'selected' : ''; ?>>Salary (Low → High)</option>
                                    <option value="join_new" <?php echo $emp_sort === 'join_new' ? 'selected' : ''; ?>>Join Date (Newest)</option>
                                    <option value="join_old" <?php echo $emp_sort === 'join_old' ? 'selected' : ''; ?>>Join Date (Oldest)</option>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                                <a class="btn btn-secondary" href="dashboard.php">Clear</a>
                            </div>
                        </div>
                    </form>

                    <?php if (empty($employeeTableRows)): ?>
                        <p class="text-muted mb-0">No employees found for the selected filters.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="adminEmployeesTable" data-smart-table data-export-name="employees_dashboard.csv" data-page-size="10">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Designation</th>
                                        <th>Clients</th>
                                        <th>Tasks</th>
                                        <th>Attendance</th>
                                        <th>Score</th>
                                        <th>Salary</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employeeTableRows as $row): ?>
                                        <?php
                                            $uid = (int) ($row['id'] ?? 0);
                                            $today = $todayAttendanceMap[$uid] ?? ['sessions' => 0, 'late_sessions' => 0, 'active_sessions' => 0, 'last_check_in' => '', 'last_check_out' => ''];
                                            $attBadge = 'bg-danger';
                                            $attText = 'Absent';
                                            $attIcon = 'times-circle';
                                            if (($today['sessions'] ?? 0) > 0) {
                                                $attBadge = 'bg-success';
                                                $attText = 'Present';
                                                $attIcon = 'check-circle';
                                            }
                                            if (($today['late_sessions'] ?? 0) > 0 && ($today['sessions'] ?? 0) > 0) {
                                                $attBadge = 'bg-warning';
                                                $attText = 'Late';
                                                $attIcon = 'clock';
                                            }
                                            $score = (int) ($row['performance_score'] ?? 0);
                                            $scoreBadge = $score >= 90 ? 'bg-success' : ($score >= 75 ? 'bg-primary' : ($score >= 60 ? 'bg-warning' : 'bg-danger'));
                                        ?>
                                        <tr class="js-employee-row" role="button" tabindex="0" data-employee-id="<?php echo $uid; ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) (($row['designation'] ?? '') ?: 'N/A')); ?></td>
                                            <td><span class="badge bg-info" data-field="assigned_clients"><?php echo (int) ($row['assigned_clients'] ?? 0); ?></span></td>
                                            <td>
                                                <span class="badge bg-warning" data-field="active_tasks"><?php echo (int) ($row['active_tasks'] ?? 0); ?></span>
                                                <span class="text-muted small ms-1"><span data-field="completed_tasks"><?php echo (int) ($row['completed_tasks'] ?? 0); ?></span> done</span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $attBadge; ?>" data-field="attendance" data-attendance-status="<?php echo htmlspecialchars(strtolower($attText)); ?>">
                                                    <i class="fas fa-<?php echo htmlspecialchars($attIcon); ?> me-1"></i><?php echo htmlspecialchars($attText); ?>
                                                </span>
                                            </td>
                                            <td><span class="badge <?php echo $scoreBadge; ?>" data-field="performance_score"><?php echo $score; ?></span></td>
                                            <td class="fw-semibold"><?php echo formatCurrency((float) ($row['salary'] ?? 0)); ?></td>
                                            <td class="text-muted small"><?php echo !empty($row['join_date']) ? formatDate((string) $row['join_date']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted small mt-2">Click an employee to open the complete profile.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-12">
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
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentExpenses as $expense): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($expense['name']); ?></td>
                                            <td><?php echo htmlspecialchars($expense['type']); ?></td>
                                            <td><?php echo formatCurrency($expense['amount']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $expense['status'] == 'approved' ? 'success' : ($expense['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                                    <?php echo ucfirst($expense['status']); ?>
                                                </span>
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
    </div>
</div>

<div class="modal fade" id="employeeProfileModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Employee Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="employeeProfileModalBody">
                <div class="text-muted">Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
const growthCtx = document.getElementById('growthChart').getContext('2d');
const growthChart = new Chart(growthCtx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Events',
            data: [6, 9, 12, 10, 14, 18],
            borderColor: '#9333EA',
            backgroundColor: 'rgba(147, 51, 234, 0.12)',
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
            ,
            tooltip: {
                enabled: true
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#E5E7EB' },
                grid: { color: 'rgba(229, 231, 235, 0.10)' }
            },
            x: {
                ticks: { color: '#9CA3AF' },
                grid: { color: 'rgba(229, 231, 235, 0.06)' }
            }
        }
    }
});

const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusChart = new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Planning', 'Active', 'Completed'],
        datasets: [{
            data: [35, 45, 20],
            backgroundColor: ['#9333EA', '#29B6F6', '#06B6D4'],
            borderColor: 'rgba(11, 15, 26, 0.8)',
            borderWidth: 2,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#E5E7EB',
                    boxWidth: 10,
                    boxHeight: 10,
                    padding: 14
                }
            }
            ,
            tooltip: {
                enabled: true
            }
        }
    }
});

const bucketCtx = document.getElementById('performanceBuckets');
if (bucketCtx) {
    const bucketChart = new Chart(bucketCtx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: " . json_encode(array_keys($scoreBuckets)) . ",
            datasets: [{
                data: " . json_encode(array_values($scoreBuckets)) . ",
                backgroundColor: ['rgba(34, 197, 94, 0.35)', 'rgba(41, 182, 246, 0.35)', 'rgba(245, 158, 11, 0.35)', 'rgba(239, 68, 68, 0.35)'],
                borderColor: ['#22C55E', '#29B6F6', '#F59E0B', '#EF4444'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { color: '#E5E7EB', precision: 0 },
                    grid: { color: 'rgba(229, 231, 235, 0.10)' }
                },
                x: {
                    ticks: { color: '#9CA3AF' },
                    grid: { color: 'rgba(229, 231, 235, 0.06)' }
                }
            }
        }
    });
}

function openEmployeeProfile(employeeId) {
    const modalEl = document.getElementById('employeeProfileModal');
    const bodyEl = document.getElementById('employeeProfileModalBody');
    if (!modalEl || !bodyEl) return;

    bodyEl.innerHTML = '<div class=\"text-muted\">Loading...</div>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'employee_profile');
    url.searchParams.set('employee_id', String(employeeId));
    url.searchParams.set('month', '" . addslashes(getCurrentMonth()) . "');

    fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            bodyEl.innerHTML = html;
            if (typeof initializeSearchableSelects === 'function') initializeSearchableSelects();
            if (typeof initializeSmartTables === 'function') initializeSmartTables();
            if (typeof initializeActionButtonsTargets === 'function') initializeActionButtonsTargets();
            initializeEmployeeProfileCharts();
            initializeEmployeeAssignTaskForm();
        })
        .catch(function() {
            bodyEl.innerHTML = '<div class=\"text-muted\">Failed to load profile.</div>';
        });
}

function initializeEmployeeAssignTaskForm() {
    const form = document.getElementById('employeeAssignTaskForm');
    if (!form) return;
    if (form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const employeeId = Number(form.dataset.employeeId || 0);
        const fd = new FormData(form);
        fd.set('employee_id', String(employeeId));

        const url = new URL(window.location.href);
        url.searchParams.set('action', 'employee_assign_task');

        fetch(url.toString(), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.success) {
                    if (typeof showAlert === 'function') showAlert('success', res.message || 'Task assigned.');
                    openEmployeeProfile(employeeId);
                } else {
                    if (typeof showAlert === 'function') showAlert('danger', (res && res.message) ? res.message : 'Failed to assign task.');
                }
            })
            .catch(function() {
                if (typeof showAlert === 'function') showAlert('danger', 'Failed to assign task.');
            });
    });
}

function initializeEmployeeProfileCharts() {
    if (typeof Chart === 'undefined') return;
    const dataEl = document.getElementById('employeeProfileChartData');
    if (!dataEl) return;

    let data;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { data = {}; }

    const taskCanvas = document.getElementById('empTaskBreakdown');
    if (taskCanvas && data.task) {
        new Chart(taskCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress', 'Pending'],
                datasets: [{
                    data: [Number(data.task.completed || 0), Number(data.task.in_progress || 0), Number(data.task.pending || 0)],
                    backgroundColor: ['#22C55E', '#29B6F6', '#F59E0B'],
                    borderColor: 'rgba(11, 15, 26, 0.8)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#E5E7EB', boxWidth: 10, boxHeight: 10 } }
                }
            }
        });
    }

    const attCanvas = document.getElementById('empAttendanceTrend');
    if (attCanvas && data.attendance) {
        new Chart(attCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.attendance.labels || [],
                datasets: [{
                    label: 'Present',
                    data: data.attendance.present || [],
                    borderColor: '#06B6D4',
                    backgroundColor: 'rgba(6, 182, 212, 0.12)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: '#E5E7EB', precision: 0 }, grid: { color: 'rgba(229, 231, 235, 0.10)' } },
                    x: { ticks: { color: '#9CA3AF' }, grid: { color: 'rgba(229, 231, 235, 0.06)' } }
                }
            }
        });
    }

    const budgetCanvas = document.getElementById('empBudgetChart');
    if (budgetCanvas && data.budget) {
        new Chart(budgetCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Spent', 'Remaining'],
                datasets: [{
                    data: [Number(data.budget.spent || 0), Number(data.budget.remaining || 0)],
                    backgroundColor: ['#29B6F6', 'rgba(229, 231, 235, 0.18)'],
                    borderColor: 'rgba(11, 15, 26, 0.8)',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: '#E5E7EB', boxWidth: 10, boxHeight: 10 } } }
            }
        });
    }
}

document.addEventListener('click', function(e) {
    const row = e.target && e.target.closest ? e.target.closest('.js-employee-row') : null;
    if (!row) return;
    if (e.target && (e.target.closest('a') || e.target.closest('button') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea'))) return;
    const id = Number(row.dataset.employeeId || 0);
    if (id > 0) openEmployeeProfile(id);
});

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const row = e.target && e.target.closest ? e.target.closest('.js-employee-row') : null;
    if (!row) return;
    const id = Number(row.dataset.employeeId || 0);
    if (id > 0) openEmployeeProfile(id);
});

function refreshAdminEmployeesSnapshot() {
    const table = document.getElementById('adminEmployeesTable');
    if (!table) return;

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'employee_snapshot');
    url.searchParams.set('month', '" . addslashes(getCurrentMonth()) . "');

    fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res || !res.success || !res.employees) return;
            const rows = table.querySelectorAll('tbody tr[data-employee-id]');
            rows.forEach(function(row) {
                const id = String(row.dataset.employeeId || '');
                const d = res.employees[id] || res.employees[Number(id)];
                if (!d) return;

                const cEl = row.querySelector('[data-field=\"assigned_clients\"]');
                if (cEl) cEl.textContent = String(d.assigned_clients ?? cEl.textContent);

                const aEl = row.querySelector('[data-field=\"active_tasks\"]');
                if (aEl) aEl.textContent = String(d.active_tasks ?? aEl.textContent);

                const doneEl = row.querySelector('[data-field=\"completed_tasks\"]');
                if (doneEl) doneEl.textContent = String(d.completed_tasks ?? doneEl.textContent);

                const attEl = row.querySelector('[data-field=\"attendance\"]');
                if (attEl) {
                    const st = String(d.attendance_status || 'absent').toLowerCase();
                    let badge = 'bg-danger', icon = 'times-circle', label = 'Absent';
                    if (st === 'present') { badge = 'bg-success'; icon = 'check-circle'; label = 'Present'; }
                    if (st === 'late') { badge = 'bg-warning'; icon = 'clock'; label = 'Late'; }

                    attEl.className = 'badge ' + badge;
                    attEl.setAttribute('data-attendance-status', st);
                    attEl.innerHTML = '<i class=\"fas fa-' + icon + ' me-1\"></i>' + label;
                }

                const scoreEl = row.querySelector('[data-field=\"performance_score\"]');
                if (scoreEl) {
                    const score = Number(d.performance_score ?? scoreEl.textContent ?? 0);
                    let sBadge = 'bg-danger';
                    if (score >= 90) sBadge = 'bg-success';
                    else if (score >= 75) sBadge = 'bg-primary';
                    else if (score >= 60) sBadge = 'bg-warning';
                    scoreEl.className = 'badge ' + sBadge;
                    scoreEl.textContent = String(score);
                }
            });
        })
        .catch(function() {});
}

try {
    if (typeof startAutoRefresh === 'function') {
        startAutoRefresh(20000, refreshAdminEmployeesSnapshot);
    } else {
        setInterval(refreshAdminEmployeesSnapshot, 20000);
    }
} catch (e) {}
</script>
";
require_once '../includes/footer.php';
?>
