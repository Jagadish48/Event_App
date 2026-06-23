<?php
$pageTitle = 'Reports & Analytics';
require_once '../includes/header.php';
requireAdmin();
ensureExpenseCategorizationSchema();

$success = '';
$error = '';

// Get filter parameters
$filter_month = isset($_GET['month']) ? clean_input($_GET['month']) : getCurrentMonth();
$filter_year = isset($_GET['year']) ? clean_input($_GET['year']) : date('Y');
$filter_report_type = isset($_GET['report_type']) ? clean_input($_GET['report_type']) : 'attendance';
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';
$filter_client = isset($_GET['client']) ? clean_input($_GET['client']) : '';
$filter_event = isset($_GET['event']) ? clean_input($_GET['event']) : '';
$filter_status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$filter_category = isset($_GET['category']) ? clean_input($_GET['category']) : '';
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_budget_min = isset($_GET['budget_min']) ? clean_input($_GET['budget_min']) : '';
$filter_budget_max = isset($_GET['budget_max']) ? clean_input($_GET['budget_max']) : '';

$employeesForFilter = [];
$clientsForFilter = [];
$eventsForFilter = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation
                         FROM users u
                         LEFT JOIN employees e ON e.user_id = u.id
                         WHERE u.role = 'employee'
                         ORDER BY u.name");
    $employeesForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $employeesForFilter = [];
}

try {
    $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY name");
    $clientsForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $clientsForFilter = [];
}

try {
    $stmt = $pdo->query("SELECT id, name FROM events ORDER BY start_date DESC, name ASC");
    $eventsForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $eventsForFilter = [];
}

// Generate reports based on type
$reportData = [];

switch ($filter_report_type) {
    case 'attendance':
        // Attendance Report
        try {
            $joinOn = "u.id = a.user_id";
            $joinParams = [];
            if ($filter_from !== '' || $filter_to !== '') {
                if ($filter_from !== '') {
                    $joinOn .= " AND a.date >= ?";
                    $joinParams[] = $filter_from;
                }
                if ($filter_to !== '') {
                    $joinOn .= " AND a.date <= ?";
                    $joinParams[] = $filter_to;
                }
            } else {
                $joinOn .= " AND DATE_FORMAT(a.date, '%Y-%m') = ?";
                $joinParams[] = $filter_month;
            }

            $query = "SELECT u.id, u.name, e.designation, 
                                  COUNT(a.id) as total_days,
                                  SUM(CASE WHEN a.check_in IS NOT NULL THEN 1 ELSE 0 END) as present_days,
                                  SUM(CASE WHEN a.check_in IS NOT NULL AND a.check_in > '12:00:00' THEN 1 ELSE 0 END) as late_days,
                                  SUM(CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL THEN 
                                      TIMESTAMPDIFF(HOUR, a.check_in, a.check_out) ELSE 0 END) as total_hours
                                  FROM users u 
                                  LEFT JOIN employees e ON e.user_id = u.id 
                                  LEFT JOIN attendance a ON $joinOn
                                  WHERE u.role = 'employee'";
            $params = $joinParams;
            if ($filter_employee !== '') {
                $query .= " AND u.id = ?";
                $params[] = $filter_employee;
            }

            $query .= " 
                                  GROUP BY u.id, u.name, e.designation 
                                  ORDER BY present_days DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            
            // Calculate attendance statistics
            $totalEmployees = count($reportData);
            $totalPresentDays = array_sum(array_column($reportData, 'present_days'));
            $avgAttendance = $totalEmployees > 0 ? round($totalPresentDays / ($totalEmployees * getDaysInMonth($filter_month)) * 100, 1) : 0;
            
        } catch(PDOException $e) {
            $error = 'Error generating attendance report: ' . $e->getMessage();
        }
        break;
        
    case 'expense':
        // Expense Report
        try {
            $allowedExpenseStatuses = ['approved', 'pending', 'rejected'];
            $status = in_array($filter_status, $allowedExpenseStatuses, true) ? $filter_status : 'approved';
            $allowedCategories = ['client', 'personal'];

            $where = "WHERE 1=1";
            $params = [];

            if ($filter_from !== '' || $filter_to !== '') {
                if ($filter_from !== '') {
                    $where .= " AND DATE(e.created_at) >= ?";
                    $params[] = $filter_from;
                }
                if ($filter_to !== '') {
                    $where .= " AND DATE(e.created_at) <= ?";
                    $params[] = $filter_to;
                }
            } else {
                $where .= " AND DATE_FORMAT(e.created_at, '%Y-%m') = ?";
                $params[] = $filter_month;
            }

            $where .= " AND e.status = ?";
            $params[] = $status;

            if ($filter_employee !== '') {
                $where .= " AND e.user_id = ?";
                $params[] = $filter_employee;
            }
            if ($filter_client !== '') {
                $where .= " AND e.client_id = ?";
                $params[] = $filter_client;
            }
            if ($filter_event !== '') {
                $where .= " AND e.event_id = ?";
                $params[] = $filter_event;
            }
            if ($filter_category !== '' && in_array($filter_category, $allowedCategories, true)) {
                $where .= " AND COALESCE(e.expense_category, 'personal') = ?";
                $params[] = $filter_category;
            }

            $stmt = $pdo->prepare("SELECT u.name,
                                          COALESCE(e.expense_category, 'personal') as category,
                                          CASE
                                            WHEN COALESCE(e.expense_category, 'personal') = 'client' THEN 'Client Expense'
                                            ELSE COALESCE(e.personal_type, e.type)
                                          END as expense_type,
                                          SUM(e.amount) as total_amount,
                                          COUNT(e.id) as count
                                  FROM expenses e
                                  JOIN users u ON e.user_id = u.id
                                  $where
                                  GROUP BY u.id, u.name, category, expense_type
                                  ORDER BY total_amount DESC");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            
            // Get expense type summary
            $stmt = $pdo->prepare("SELECT
                                  COALESCE(expense_category, 'personal') as category,
                                  CASE
                                    WHEN COALESCE(expense_category, 'personal') = 'client' THEN 'Client Expense'
                                    ELSE COALESCE(personal_type, type)
                                  END as expense_type,
                                  SUM(amount) as total,
                                  COUNT(id) as count
                                  FROM expenses
                                  $where
                                  GROUP BY category, expense_type
                                  ORDER BY total DESC");
            $stmt->execute($params);
            $expenseSummary = $stmt->fetchAll();
            
        } catch(PDOException $e) {
            $error = 'Error generating expense report: ' . $e->getMessage();
        }
        break;
        
    case 'payroll':
        // Payroll Report
        try {
            $query = "SELECT u.id, u.name, e.designation, e.salary 
                      FROM users u 
                      LEFT JOIN employees e ON e.user_id = u.id 
                      WHERE u.role = 'employee' AND e.status = 'active'";
            $params = [];
            if ($filter_employee !== '') {
                $query .= " AND u.id = ?";
                $params[] = $filter_employee;
            }
            $query .= " ORDER BY u.name";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $employees = $stmt->fetchAll();
            
            foreach ($employees as $emp) {
                $salary = calculateSalary($emp['id'], $filter_month);
                $reportData[] = array_merge($emp, $salary);
            }
            
        } catch(PDOException $e) {
            $error = 'Error generating payroll report: ' . $e->getMessage();
        }
        break;
        
    case 'events':
        // Events Report
        try {
            $allowedEventStatuses = ['planning', 'active', 'completed', 'cancelled'];
            $query = "SELECT e.*, c.name as client_name,
                             (SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ')
                              FROM event_team et2
                              JOIN users u2 ON u2.id = et2.user_id
                              WHERE et2.event_id = e.id) as team_names,
                             (SELECT COALESCE(SUM(ex.amount), 0)
                              FROM event_expenses ee
                              JOIN expenses ex ON ex.id = ee.expense_id
                              WHERE ee.event_id = e.id
                                AND ex.status = 'approved'
                                AND COALESCE(ex.expense_category, 'personal') = 'client') as approved_expenses
                      FROM events e
                      LEFT JOIN clients c ON e.client_id = c.id
                      WHERE 1=1";
            $params = [];

            if ($filter_from !== '' || $filter_to !== '') {
                if ($filter_from !== '') {
                    $query .= " AND e.start_date >= ?";
                    $params[] = $filter_from;
                }
                if ($filter_to !== '') {
                    $query .= " AND e.start_date <= ?";
                    $params[] = $filter_to;
                }
            } else {
                $query .= " AND YEAR(e.start_date) = ?";
                $params[] = $filter_year;
            }

            if ($filter_status !== '' && in_array($filter_status, $allowedEventStatuses, true)) {
                $query .= " AND e.status = ?";
                $params[] = $filter_status;
            }

            if ($filter_client !== '') {
                $query .= " AND e.client_id = ?";
                $params[] = $filter_client;
            }

            if ($filter_event !== '') {
                $query .= " AND e.id = ?";
                $params[] = $filter_event;
            }

            if ($filter_employee !== '') {
                $query .= " AND EXISTS (SELECT 1 FROM event_team etx WHERE etx.event_id = e.id AND etx.user_id = ?)";
                $params[] = $filter_employee;
            }

            if ($filter_budget_min !== '' && is_numeric($filter_budget_min)) {
                $query .= " AND e.budget >= ?";
                $params[] = (float) $filter_budget_min;
            }
            if ($filter_budget_max !== '' && is_numeric($filter_budget_max)) {
                $query .= " AND e.budget <= ?";
                $params[] = (float) $filter_budget_max;
            }

            $query .= " ORDER BY e.start_date DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            
            // Calculate event statistics
            $totalEvents = count($reportData);
            $totalBudget = array_sum(array_column($reportData, 'budget'));
            $totalExpenses = array_sum(array_column($reportData, 'approved_expenses'));
            $totalProfit = $totalBudget - $totalExpenses;
            
        } catch(PDOException $e) {
            $error = 'Error generating events report: ' . $e->getMessage();
        }
        break;
        
    case 'performance':
        // Employee Performance Report
        try {
            ensurePerformanceSchema();
            $query = "SELECT u.id, u.name, e.designation
                      FROM users u
                      LEFT JOIN employees e ON e.user_id = u.id
                      WHERE u.role = 'employee'";
            $params = [];
            if ($filter_employee !== '') {
                $query .= " AND u.id = ?";
                $params[] = $filter_employee;
            }
            $query .= " ORDER BY u.name";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $employees = $stmt->fetchAll();

            $reportData = [];
            foreach ($employees as $emp) {
                $p = getEmployeeMonthlyPerformance((int) $emp['id'], $filter_month);
                $reportData[] = [
                    'id' => (int) $emp['id'],
                    'name' => $emp['name'],
                    'designation' => $emp['designation'],
                    'attendance_percent' => (float) ($p['attendance_percent'] ?? 0),
                    'tasks_completed' => (int) ($p['tasks_completed'] ?? 0),
                    'events_participated' => (int) ($p['events_participated'] ?? 0),
                    'late_days' => (int) ($p['late_days'] ?? 0),
                    'overdue_tasks' => (int) ($p['overdue_tasks'] ?? 0),
                    'total_score' => (int) ($p['total_score'] ?? 0),
                    'incentive_tier' => (string) ($p['incentive_tier'] ?? 'none'),
                    'incentive_amount' => (float) ($p['incentive_amount'] ?? 0)
                ];
            }

            usort($reportData, function($a, $b) {
                return ($b['total_score'] <=> $a['total_score']) ?: strcmp($a['name'], $b['name']);
            });
            
        } catch(PDOException $e) {
            $error = 'Error generating performance report: ' . $e->getMessage();
        }
        break;
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
            <h1 class="h3 page-title">Reports</h1>
            <div class="page-subtitle">Generate and export reports for events, finance, and performance</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" onclick="printReport()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button class="btn btn-secondary" onclick="exportReport()">
                <i class="fas fa-download me-2"></i>Export
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="events.php"><i class="fas fa-calendar-check me-2"></i>Events</a>
        <a class="btn btn-secondary" href="expenses.php"><i class="fas fa-money-bill-wave me-2"></i>Expenses</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Report Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select class="form-select" name="report_type">
                            <option value="attendance" <?php echo $filter_report_type == 'attendance' ? 'selected' : ''; ?>>Attendance Report</option>
                            <option value="expense" <?php echo $filter_report_type == 'expense' ? 'selected' : ''; ?>>Expense Report</option>
                            <option value="payroll" <?php echo $filter_report_type == 'payroll' ? 'selected' : ''; ?>>Payroll Report</option>
                            <option value="events" <?php echo $filter_report_type == 'events' ? 'selected' : ''; ?>>Events Report</option>
                            <option value="performance" <?php echo $filter_report_type == 'performance' ? 'selected' : ''; ?>>Employee Performance</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Month</label>
                        <input type="month" class="form-control" name="month" value="<?php echo $filter_month; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Year</label>
                        <select class="form-select" name="year">
                            <?php for ($year = date('Y'); $year >= date('Y') - 5; $year--): ?>
                                <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <div class="flex-fill">
                            <label class="form-label">Employee</label>
                            <select class="form-select js-searchable-select" name="employee">
                                <option value="">All</option>
                                <?php foreach ($employeesForFilter as $emp): ?>
                                    <option value="<?php echo (int) $emp['id']; ?>" <?php echo (string) $filter_employee === (string) $emp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['name']); ?><?php if (!empty($emp['designation'])) echo ' (' . htmlspecialchars($emp['designation']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-flex flex-column justify-content-end">
                            <a href="reports.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i></a>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select js-searchable-select" name="client">
                            <option value="">All</option>
                            <?php foreach ($clientsForFilter as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $filter_client === (string) $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Event</label>
                        <select class="form-select js-searchable-select" name="event">
                            <option value="">All</option>
                            <?php foreach ($eventsForFilter as $ev): ?>
                                <option value="<?php echo (int) $ev['id']; ?>" <?php echo (string) $filter_event === (string) $ev['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ev['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>

                    <?php if ($filter_report_type === 'expense'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="approved" <?php echo $filter_status === 'approved' || $filter_status === '' ? 'selected' : ''; ?>>Approved</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Expense Category</label>
                            <select class="form-select" name="category">
                                <option value="">All</option>
                                <option value="client" <?php echo $filter_category === 'client' ? 'selected' : ''; ?>>Client</option>
                                <option value="personal" <?php echo $filter_category === 'personal' ? 'selected' : ''; ?>>Personal</option>
                            </select>
                        </div>
                    <?php elseif ($filter_report_type === 'events'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All</option>
                                <option value="planning" <?php echo $filter_status === 'planning' ? 'selected' : ''; ?>>Planning</option>
                                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Budget Min</label>
                            <input type="number" class="form-control" name="budget_min" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_budget_min); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Budget Max</label>
                            <input type="number" class="form-control" name="budget_max" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_budget_max); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Generate Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <?php 
                switch ($filter_report_type) {
                    case 'attendance': echo 'Attendance Report - ' . date('F Y', strtotime($filter_month . '-01')); break;
                    case 'expense': echo 'Expense Report - ' . date('F Y', strtotime($filter_month . '-01')); break;
                    case 'payroll': echo 'Payroll Report - ' . date('F Y', strtotime($filter_month . '-01')); break;
                    case 'events': echo 'Events Report - ' . $filter_year; break;
                    case 'performance': echo 'Employee Performance - ' . date('F Y', strtotime($filter_month . '-01')); break;
                }
                ?>
            </h5>
        </div>
        <div class="card-body" id="reportContent">
            <?php if ($filter_report_type == 'attendance'): ?>
                <!-- Attendance Report -->
                <?php if (isset($avgAttendance)): ?>
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="stat-card primary">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-value"><?php echo $totalEmployees; ?></div>
                                <div class="stat-label">Total Employees</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card success">
                                <div class="stat-icon">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <div class="stat-value"><?php echo $avgAttendance; ?>%</div>
                                <div class="stat-label">Avg Attendance</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card info">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="stat-value"><?php echo $totalPresentDays; ?></div>
                                <div class="stat-label">Total Present Days</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover" data-smart-table data-export-name="attendance_report.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Designation</th>
                                <th>Total Days</th>
                                <th>Present Days</th>
                                <th>Absent Days</th>
                                <th>Status</th>
                                <th>Attendance %</th>
                                <th>Total Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $data): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($data['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($data['designation'] ?: 'N/A'); ?></td>
                                    <td><?php echo getDaysInMonth($filter_month); ?></td>
                                    <td><?php echo $data['present_days']; ?></td>
                                    <td><?php echo getDaysInMonth($filter_month) - $data['present_days']; ?></td>
                                    <td>
                                        <?php
                                            $statusKey = ((int) ($data['present_days'] ?? 0)) > 0
                                                ? (((int) ($data['late_days'] ?? 0)) > 0 ? 'late' : 'present')
                                                : 'absent';
                                            echo renderAttendanceStatusBadgeFromKey($statusKey);
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $percentage = round(($data['present_days'] / getDaysInMonth($filter_month)) * 100, 1);
                                        echo $percentage . '%';
                                        ?>
                                    </td>
                                    <td><?php echo round($data['total_hours'], 1); ?>h</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($filter_report_type == 'expense'): ?>
                <!-- Expense Report -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="expenseTypeChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-container">
                            <canvas id="expenseEmployeeChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover" data-smart-table data-export-name="expense_report.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Category</th>
                                <th>Expense Type</th>
                                <th>Count</th>
                                <th>Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $data): ?>
                                <?php
                                    $cat = strtolower(trim((string) ($data['category'] ?? 'personal')));
                                    $cat = $cat === 'client' ? 'client' : 'personal';
                                    $catBadge = $cat === 'client' ? 'bg-info' : 'bg-primary';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($data['name']); ?></td>
                                    <td><span class="badge <?php echo $catBadge; ?>"><?php echo htmlspecialchars(ucfirst($cat)); ?></span></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($data['expense_type']); ?></span></td>
                                    <td><?php echo $data['count']; ?></td>
                                    <td><strong><?php echo formatCurrency($data['total_amount']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($filter_report_type == 'payroll'): ?>
                <!-- Payroll Report -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card primary">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-value"><?php echo count($reportData); ?></div>
                            <div class="stat-label">Employees</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card info">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency(array_sum(array_column($reportData, 'base_salary'))); ?></div>
                            <div class="stat-label">Base Salary</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card success">
                            <div class="stat-icon">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency(array_sum(array_column($reportData, 'earned_salary'))); ?></div>
                            <div class="stat-label">Earned Salary</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card warning">
                            <div class="stat-icon">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency(array_sum(array_column($reportData, 'total_payable'))); ?></div>
                            <div class="stat-label">Total Payable</div>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover" data-smart-table data-export-name="expense_summary.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Designation</th>
                                <th>Base Salary</th>
                                <th>Present Days</th>
                                <th>Earned Salary</th>
                                <th>Expenses</th>
                                <th>Total Payable</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $data): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($data['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($data['designation'] ?: 'N/A'); ?></td>
                                    <td><?php echo formatCurrency($data['base_salary']); ?></td>
                                    <td><?php echo $data['present_days']; ?></td>
                                    <td><?php echo formatCurrency($data['earned_salary']); ?></td>
                                    <td><?php echo formatCurrency($data['approved_expenses']); ?></td>
                                    <td><strong><?php echo formatCurrency($data['total_payable']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-primary fw-bold">
                                <td colspan="3">TOTAL</td>
                                <td><?php echo array_sum(array_column($reportData, 'present_days')); ?></td>
                                <td><?php echo formatCurrency(array_sum(array_column($reportData, 'earned_salary'))); ?></td>
                                <td><?php echo formatCurrency(array_sum(array_column($reportData, 'approved_expenses'))); ?></td>
                                <td><?php echo formatCurrency(array_sum(array_column($reportData, 'total_payable'))); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
            <?php elseif ($filter_report_type == 'events'): ?>
                <!-- Events Report -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card primary">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-value"><?php echo $totalEvents; ?></div>
                            <div class="stat-label">Total Events</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card info">
                            <div class="stat-icon">
                                <i class="fas fa-rupee-sign"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency($totalBudget); ?></div>
                            <div class="stat-label">Total Budget</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card warning">
                            <div class="stat-icon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency($totalExpenses); ?></div>
                            <div class="stat-label">Total Expenses</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card <?php echo $totalProfit >= 0 ? 'success' : 'danger'; ?>">
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-value"><?php echo formatCurrency($totalProfit); ?></div>
                            <div class="stat-label">Profit/Loss</div>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover" data-smart-table data-export-name="events_report.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Client</th>
                                <th>Assigned Employees</th>
                                <th>Start Date</th>
                                <th>Budget</th>
                                <th>Approved Expenses</th>
                                <th>Remaining</th>
                                <th>Utilization</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $data): ?>
                                <?php
                                    $budget = (float) ($data['budget'] ?? 0);
                                    $approved = (float) ($data['approved_expenses'] ?? 0);
                                    $remaining = $budget - $approved;
                                    $util = $budget > 0 ? min(100, round(($approved / $budget) * 100, 1)) : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($data['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($data['client_name'] ?: 'No Client'); ?></td>
                                    <td><?php echo htmlspecialchars((string) (($data['team_names'] ?? '') ?: '-')); ?></td>
                                    <td><?php echo formatDate($data['start_date']); ?></td>
                                    <td><?php echo formatCurrency($data['budget']); ?></td>
                                    <td><?php echo formatCurrency($approved); ?></td>
                                    <td class="<?php echo $remaining >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <strong><?php echo formatCurrency($remaining); ?></strong>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-primary"><?php echo htmlspecialchars((string) $util); ?>%</span>
                                            <div class="progress flex-grow-1">
                                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (float) $util; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $data['status'] == 'completed' ? 'success' : 
                                                 ($data['status'] == 'active' ? 'primary' : 
                                                 ($data['status'] == 'cancelled' ? 'danger' : 'warning')); 
                                        ?>">
                                            <?php echo ucfirst($data['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($filter_report_type == 'performance'): ?>
                <!-- Employee Performance Report -->
                <div class="table-responsive">
                    <table class="table table-hover" data-smart-table data-export-name="performance_report.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Designation</th>
                                <th>Attendance %</th>
                                <th>Tasks Completed</th>
                                <th>Events</th>
                                <th>Late Days</th>
                                <th>Overdue Tasks</th>
                                <th>Score</th>
                                <th>Tier</th>
                                <th>Incentive</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $data): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($data['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($data['designation'] ?: 'N/A'); ?></td>
                                    <td><?php echo round((float) ($data['attendance_percent'] ?? 0), 1); ?>%</td>
                                    <td><?php echo (int) ($data['tasks_completed'] ?? 0); ?></td>
                                    <td><?php echo (int) ($data['events_participated'] ?? 0); ?></td>
                                    <td><?php echo (int) ($data['late_days'] ?? 0); ?></td>
                                    <td><?php echo (int) ($data['overdue_tasks'] ?? 0); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo ($data['total_score'] ?? 0) >= 75 ? 'success' : (($data['total_score'] ?? 0) >= 60 ? 'warning' : 'danger'); ?>">
                                            <?php echo (int) ($data['total_score'] ?? 0); ?>/100
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo ($data['incentive_tier'] ?? 'none') === 'high' ? 'success' : (($data['incentive_tier'] ?? 'none') === 'medium' ? 'primary' : (($data['incentive_tier'] ?? 'none') === 'basic' ? 'warning' : 'danger')); ?>">
                                            <?php echo ($data['incentive_tier'] ?? 'none') === 'none' ? 'None' : ucfirst((string) $data['incentive_tier']); ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold"><?php echo formatCurrency((float) ($data['incentive_amount'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
function printReport() {
    printElement('reportContent');
}

function exportReport() {
    const table = document.querySelector('#reportContent table');
    if (!table) return;
    if (!table.id) {
        table.id = 'reportTable';
    }
    const reportType = " . json_encode($filter_report_type) . ";
    const month = " . json_encode($filter_month) . ";
    const filename = reportType + '_' + month + '_export.csv';
    exportToCSV(table.id, filename);
}

" . ($filter_report_type == 'expense' && isset($expenseSummary) ? "
// Expense Type Chart
const expenseTypeCtx = document.getElementById('expenseTypeChart').getContext('2d');
const expenseTypeChart = new Chart(expenseTypeCtx, {
    type: 'doughnut',
    data: {
        labels: " . json_encode(array_map(function($r) {
            $cat = ucfirst((string) ($r['category'] ?? 'personal'));
            $t = (string) ($r['expense_type'] ?? '');
            return $cat . ' • ' . $t;
        }, $expenseSummary)) . ",
        datasets: [{
            data: " . json_encode(array_column($expenseSummary, 'total')) . ",
            backgroundColor: ['#3498db', '#2ecc71', '#f39c12', '#e74c3c', '#9b59b6', '#1abc9c']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            },
            title: {
                display: true,
                text: 'Expenses by Category'
            }
        }
    }
});

// Expense Employee Chart
const expenseEmployeeCtx = document.getElementById('expenseEmployeeChart').getContext('2d');
const employeeData = " . json_encode(array_slice($reportData, 0, 5)) . ";
const expenseEmployeeChart = new Chart(expenseEmployeeCtx, {
    type: 'bar',
    data: {
        labels: employeeData.map(d => d.name),
        datasets: [{
            label: 'Total Expenses',
            data: employeeData.map(d => d.total_amount),
            backgroundColor: '#3498db'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            title: {
                display: true,
                text: 'Top 5 Employees by Expenses'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'Rs ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});
" : "") . "
</script>
";
require_once '../includes/footer.php';
?>
