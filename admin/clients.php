<?php
$pageTitle = 'Clients';
require_once '../includes/header.php';
requireAdmin();

ensureClientWorkflowSchema();

$success = '';
$error = '';
$info = '';

// Get success/error from query params
if (isset($_GET['success'])) {
    $success = clean_input($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = clean_input($_GET['error']);
}

$workflowStatuses = [
    'New Lead',
    'Contacted',
    'Meeting Scheduled',
    'Proposal Sent',
    'Confirmed',
    'Event Ongoing',
    'Completed'
];

$filter_q = isset($_GET['q']) ? trim((string) clean_input($_GET['q'])) : '';
$filter_assigned = isset($_GET['assigned_to']) ? clean_input($_GET['assigned_to']) : '';
$filter_workflow = isset($_GET['workflow_status']) ? clean_input($_GET['workflow_status']) : '';
$filter_event = isset($_GET['event']) ? clean_input($_GET['event']) : '';
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_budget_min = isset($_GET['budget_min']) ? clean_input($_GET['budget_min']) : '';
$filter_budget_max = isset($_GET['budget_max']) ? clean_input($_GET['budget_max']) : '';

if (isset($_GET['booking_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $date = trim((string) clean_input($_GET['date'] ?? ''));
    if (!isValidISODate($date)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid date']);
        exit;
    }
    $availability = getClientBookingAvailability($date);
    echo json_encode(['success' => true, 'availability' => $availability]);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $assignedTo = clean_input($_POST['assigned_to'] ?? '');
                $assignedTo = $assignedTo !== '' ? (int) $assignedTo : null;
                $workflowStatus = clean_input($_POST['workflow_status'] ?? 'New Lead');
                if (!in_array($workflowStatus, $workflowStatuses, true)) {
                    $workflowStatus = 'New Lead';
                }

                $bookingDate = trim((string) clean_input($_POST['booking_date'] ?? ''));
                if (!isValidISODate($bookingDate)) {
                    throw new RuntimeException('Please select a valid booking date.');
                }
                $availability = getClientBookingAvailability($bookingDate);
                if (($availability['status'] ?? '') === 'Packed') {
                    throw new RuntimeException('Selected date is fully packed.');
                }

                $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, company, address, assigned_to, workflow_status, booking_date, workflow_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['company']),
                    clean_input($_POST['address']),
                    $assignedTo,
                    $workflowStatus,
                    $bookingDate
                ]);
                header('Location: clients.php?success=' . urlencode('Client added successfully!'));
                exit;
            } elseif ($_POST['action'] == 'edit') {
                $assignedTo = clean_input($_POST['assigned_to'] ?? '');
                $assignedTo = $assignedTo !== '' ? (int) $assignedTo : null;
                $workflowStatus = clean_input($_POST['workflow_status'] ?? 'New Lead');
                if (!in_array($workflowStatus, $workflowStatuses, true)) {
                    $workflowStatus = 'New Lead';
                }

                $clientId = (int) clean_input($_POST['client_id']);
                $bookingDate = trim((string) clean_input($_POST['booking_date'] ?? ''));
                if (!isValidISODate($bookingDate)) {
                    throw new RuntimeException('Please select a valid booking date.');
                }
                $availability = getClientBookingAvailability($bookingDate, $clientId);
                if (($availability['status'] ?? '') === 'Packed') {
                    throw new RuntimeException('Selected date is fully packed.');
                }

                $stmt = $pdo->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, company = ?, address = ?, assigned_to = ?, workflow_status = ?, booking_date = ?, workflow_updated_at = NOW() WHERE id = ?");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['company']),
                    clean_input($_POST['address']),
                    $assignedTo,
                    $workflowStatus,
                    $bookingDate,
                    $clientId
                ]);
                header('Location: clients.php?success=' . urlencode('Client updated successfully!'));
                exit;
            } elseif ($_POST['action'] == 'delete') {
                $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                $stmt->execute([clean_input($_POST['client_id'])]);
                header('Location: clients.php?success=' . urlencode('Client deleted successfully!'));
                exit;
            }
        } catch(PDOException $e) {
            header('Location: clients.php?error=' . urlencode('Error: ' . $e->getMessage()));
            exit;
        } catch(RuntimeException $e) {
            header('Location: clients.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Get clients
try {
    $query = "SELECT c.*, l.name as lead_name, u.name as assigned_name,
                (SELECT COUNT(*) FROM events e WHERE e.client_id = c.id AND e.status IN ('planning','active')) as active_events,
                (SELECT COALESCE(SUM(e.budget), 0) FROM events e WHERE e.client_id = c.id) as total_budget,
                (SELECT COALESCE(SUM(ex.amount), 0) FROM expenses ex
                    WHERE ex.client_id = c.id AND ex.status = 'approved' AND COALESCE(ex.expense_category, 'personal') = 'client') as total_expenses,
                (SELECT COUNT(*) FROM employee_tasks t WHERE t.client_id = c.id AND t.status <> 'completed') as pending_tasks,
                (SELECT COUNT(*) FROM expenses ex
                    WHERE ex.client_id = c.id AND ex.status = 'pending' AND COALESCE(ex.expense_category, 'personal') = 'client' AND COALESCE(ex.reimbursable, 0) = 1) as pending_reimbursements
            FROM clients c 
            LEFT JOIN leads l ON c.linked_lead_id = l.id 
            LEFT JOIN users u ON u.id = c.assigned_to
            WHERE 1=1";
    $params = [];

    if ($filter_q !== '') {
        $query .= " AND (c.name LIKE ? OR c.company LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $like = '%' . $filter_q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($filter_assigned !== '') {
        $query .= " AND c.assigned_to = ?";
        $params[] = $filter_assigned;
    }

    if ($filter_workflow !== '' && in_array($filter_workflow, $workflowStatuses, true)) {
        $query .= " AND c.workflow_status = ?";
        $params[] = $filter_workflow;
    }

    if ($filter_event !== '') {
        $query .= " AND EXISTS (SELECT 1 FROM events e3 WHERE e3.id = ? AND e3.client_id = c.id)";
        $params[] = $filter_event;
    }

    if ($filter_from !== '') {
        $query .= " AND DATE(c.created_at) >= ?";
        $params[] = $filter_from;
    }

    if ($filter_to !== '') {
        $query .= " AND DATE(c.created_at) <= ?";
        $params[] = $filter_to;
    }

    if ($filter_budget_min !== '' && is_numeric($filter_budget_min)) {
        $query .= " AND (SELECT COALESCE(SUM(e4.budget), 0) FROM events e4 WHERE e4.client_id = c.id) >= ?";
        $params[] = (float) $filter_budget_min;
    }

    if ($filter_budget_max !== '' && is_numeric($filter_budget_max)) {
        $query .= " AND (SELECT COALESCE(SUM(e5.budget), 0) FROM events e5 WHERE e5.client_id = c.id) <= ?";
        $params[] = (float) $filter_budget_max;
    }

    $query .= " ORDER BY c.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = 'Error fetching clients: ' . $e->getMessage();
    $clients = [];
}

$bookingCounts = [];
try {
    $stmt = $pdo->query("SELECT booking_date, COUNT(*) as c FROM clients WHERE booking_date IS NOT NULL GROUP BY booking_date");
    foreach ($stmt->fetchAll() as $r) {
        $d = (string) ($r['booking_date'] ?? '');
        if ($d !== '') $bookingCounts[$d] = (int) ($r['c'] ?? 0);
    }
} catch (PDOException $e) {
    $bookingCounts = [];
}

// Get employees for assignment dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'employee' ORDER BY name");
    $employees = $stmt->fetchAll();
} catch(PDOException $e) {
    $employees = [];
}

// Get events for filter dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM events ORDER BY start_date DESC, name ASC");
    $eventsForFilter = $stmt->fetchAll();
} catch(PDOException $e) {
    $eventsForFilter = [];
}

// Get client for editing
$editClient = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([clean_input($_GET['edit'])]);
        $editClient = $stmt->fetch();
    } catch(PDOException $e) {
        $error = 'Error fetching client: ' . $e->getMessage();
    }
}

// Calculate statistics
$stats = [
    'total_clients' => count($clients),
    'converted_from_leads' => 0,
    'direct_clients' => 0
];

foreach ($clients as $client) {
    if ($client['linked_lead_id']) {
        $stats['converted_from_leads']++;
    } else {
        $stats['direct_clients']++;
    }
}

$statusCounts = [];
foreach ($workflowStatuses as $s) {
    $statusCounts[$s] = 0;
}
foreach ($clients as $client) {
    $st = (string) ($client['workflow_status'] ?? 'New Lead');
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
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
            <h1 class="h3 page-title">Clients</h1>
            <div class="page-subtitle">Manage clients and assignments for events</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                <i class="fas fa-plus me-2"></i>Add Client
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="leads.php"><i class="fas fa-handshake me-2"></i>Leads</a>
        <a class="btn btn-secondary" href="events.php"><i class="fas fa-calendar-check me-2"></i>Events</a>
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

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_clients']; ?></div>
                <div class="stat-label">Total Clients</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['converted_from_leads']; ?></div>
                <div class="stat-label">Converted from Leads</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-value"><?php echo $stats['direct_clients']; ?></div>
                <div class="stat-label">Direct Clients</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Workflow Analytics</h5>
            <small class="text-muted"><?php echo date('d M Y'); ?></small>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($workflowStatuses as $s): ?>
                    <?php
                        $count = (int) ($statusCounts[$s] ?? 0);
                        $pct = ($stats['total_clients'] ?? 0) > 0 ? round(($count / $stats['total_clients']) * 100) : 0;
                        $barClass = $s === 'Completed' ? 'bg-success' : ($s === 'Proposal Sent' || $s === 'Event Ongoing' ? 'bg-warning' : 'bg-primary');
                    ?>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span><?php echo htmlspecialchars($s); ?></span>
                            <span><?php echo $count; ?> • <?php echo $pct; ?>%</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar <?php echo $barClass; ?>" role="progressbar" style="width: <?php echo (int) $pct; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Filters</h5>
            <a class="btn btn-sm btn-secondary" href="clients.php"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3">
                        <label class="form-label">Search</label>
                        <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Client, company, email, phone">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Assigned Employee</label>
                        <select class="form-select js-searchable-select" name="assigned_to">
                            <option value="">All</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int) $emp['id']; ?>" <?php echo (string) $filter_assigned === (string) $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Workflow Status</label>
                        <select class="form-select" name="workflow_status">
                            <option value="">All</option>
                            <?php foreach ($workflowStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo (string) $filter_workflow === (string) $st ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($st); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
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

                    <div class="col-lg-3">
                        <label class="form-label">Created From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Created To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Budget Min</label>
                        <input type="number" class="form-control" name="budget_min" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_budget_min); ?>" placeholder="0">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Budget Max</label>
                        <input type="number" class="form-control" name="budget_max" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $filter_budget_max); ?>" placeholder="No limit">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                        <a class="btn btn-secondary" href="clients.php">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Clients Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Clients</h5>
        </div>
        <div class="card-body">
            <?php if (empty($clients)): ?>
                <p class="text-muted">No clients found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="clientsTable" data-smart-table data-export-name="clients_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Assigned Employee</th>
                                <th>Active Events</th>
                                <th>Budget</th>
                                <th>Total Expenses</th>
                                <th>Remaining</th>
                                <th>Pending Tasks</th>
                                <th>Reimbursements</th>
                                <th>Workflow</th>
                                <th>Booking Date</th>
                                <th>Booking</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($client['company'] ?: 'N/A'); ?>
                                                <?php if (!empty($client['email'])): ?>
                                                    • <?php echo htmlspecialchars($client['email']); ?>
                                                <?php endif; ?>
                                                <?php if (!empty($client['phone'])): ?>
                                                    • <?php echo htmlspecialchars($client['phone']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small mt-1">
                                                <?php if ($client['linked_lead_id']): ?>
                                                    <span class="badge bg-success me-1"><i class="fas fa-exchange-alt me-1"></i>Lead Conversion</span>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($client['lead_name'] ?: 'Lead'); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">Direct</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            $assignedName = (string) ($client['assigned_name'] ?? '');
                                        ?>
                                        <?php if (!empty($client['assigned_to'])): ?>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($assignedName !== '' ? $assignedName : 'Employee'); ?></div>
                                            <div class="text-muted small">Assigned</div>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo (int) ($client['active_events'] ?? 0); ?></div>
                                        <div class="text-muted small">
                                            <button type="button" class="btn btn-link btn-sm p-0" onclick="viewEvents(<?php echo (int) $client['id']; ?>)">
                                                View timeline
                                            </button>
                                        </div>
                                    </td>
                                    <?php
                                        $budget = (float) ($client['total_budget'] ?? 0);
                                        $expenses = (float) ($client['total_expenses'] ?? 0);
                                        $remaining = max(0, $budget - $expenses);
                                        $util = $budget > 0 ? min(100, round(($expenses / $budget) * 100)) : 0;
                                    ?>
                                    <td>
                                        <div class="fw-semibold"><?php echo formatCurrency($budget); ?></div>
                                        <div class="text-muted small">Utilization <?php echo (int) $util; ?>%</div>
                                        <div class="progress mt-1" style="height: 8px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int) $util; ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="fw-semibold"><?php echo formatCurrency($expenses); ?></td>
                                    <td class="fw-semibold"><?php echo formatCurrency($remaining); ?></td>
                                    <td>
                                        <?php
                                            $pendingTasks = (int) ($client['pending_tasks'] ?? 0);
                                            $taskBadge = $pendingTasks > 0 ? 'bg-warning' : 'bg-success';
                                        ?>
                                        <span class="badge <?php echo $taskBadge; ?>"><?php echo $pendingTasks; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                            $pendingReimb = (int) ($client['pending_reimbursements'] ?? 0);
                                            $reimbBadge = $pendingReimb > 0 ? 'bg-warning' : 'bg-success';
                                            $reimbText = $pendingReimb > 0 ? ('Pending ' . $pendingReimb) : 'Clear';
                                        ?>
                                        <span class="badge <?php echo $reimbBadge; ?>"><?php echo htmlspecialchars($reimbText); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                            $st = (string) ($client['workflow_status'] ?? 'New Lead');
                                            $badge = 'bg-info';
                                            if ($st === 'Proposal Sent' || $st === 'Event Ongoing') $badge = 'bg-warning';
                                            if ($st === 'Confirmed' || $st === 'Completed') $badge = 'bg-success';
                                            if ($st === 'Contacted' || $st === 'Meeting Scheduled') $badge = 'bg-primary';
                                        ?>
                                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($st); ?></span>
                                        <?php if (!empty($client['workflow_updated_at'])): ?>
                                            <div class="text-muted small">Updated <?php echo htmlspecialchars(date('d M Y', strtotime($client['workflow_updated_at']))); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php
                                            $bd = (string) ($client['booking_date'] ?? '');
                                            echo $bd !== '' ? htmlspecialchars(date('d M Y', strtotime($bd))) : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $bd = (string) ($client['booking_date'] ?? '');
                                            $limit = (int) getClientBookingDailyLimit();
                                            $count = ($bd !== '' && isset($bookingCounts[$bd])) ? (int) $bookingCounts[$bd] : 0;
                                            $remainingSlots = max(0, $limit - $count);
                                            $bookStatus = $count >= $limit ? 'Packed' : ($remainingSlots <= 1 ? 'Limited Slots' : 'Available');
                                            $bookBadge = $bookStatus === 'Packed' ? 'bg-danger' : ($bookStatus === 'Limited Slots' ? 'bg-warning' : 'bg-success');
                                        ?>
                                        <span class="badge <?php echo $bookBadge; ?>"><?php echo htmlspecialchars($bookStatus); ?></span>
                                        <div class="text-muted small"><?php echo $bookStatus === 'Packed' ? '0 slots left' : ((int) $remainingSlots . ' slots left'); ?></div>
                                    </td>
                                    <td><?php echo formatDate($client['created_at']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-primary" onclick="editClient(<?php echo $client['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-info" onclick="viewEvents(<?php echo $client['id']; ?>)">
                                                <i class="fas fa-calendar"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteClient(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['name']); ?>')">
                                                <i class="fas fa-trash"></i>
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
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" class="form-control" name="phone" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Company</label>
                                <input type="text" class="form-control" name="company">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Booking Date *</label>
                                <input type="date" class="form-control" name="booking_date" id="add_booking_date" required>
                                <div class="form-text" id="add_booking_hint">Max <?php echo (int) getClientBookingDailyLimit(); ?> clients per date.</div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Assign to Employee</label>
                                <select class="form-select" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int) $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Workflow Status</label>
                                <select class="form-select" name="workflow_status">
                                    <?php foreach ($workflowStatuses as $st): ?>
                                        <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="client_id" id="edit_client_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" class="form-control" name="phone" id="edit_phone" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="edit_email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Company</label>
                                <input type="text" class="form-control" name="company" id="edit_company">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Booking Date *</label>
                                <input type="date" class="form-control" name="booking_date" id="edit_booking_date" required>
                                <div class="form-text" id="edit_booking_hint">Max <?php echo (int) getClientBookingDailyLimit(); ?> clients per date.</div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Assign to Employee</label>
                                <select class="form-select" name="assigned_to" id="edit_assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int) $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Workflow Status</label>
                                <select class="form-select" name="workflow_status" id="edit_workflow_status">
                                    <?php foreach ($workflowStatuses as $st): ?>
                                        <option value="<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars($st); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Client Events Modal -->
<div class="modal fade" id="eventsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Client Events</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="eventsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="client_id" id="delete_client_id">
</form>

<?php
$additional_js = "
<script>
function editClient(clientId) {
    window.location.href = 'clients.php?edit=' + clientId;
}

function deleteClient(clientId, name) {
    customConfirm('Are you sure you want to delete client \"' + name + '\"? This action cannot be undone.', function() {
        document.getElementById('delete_client_id').value = clientId;
        document.getElementById('deleteForm').submit();
    });
}

function viewEvents(clientId) {
    // Load client events via AJAX
    fetch('client_events.php?client_id=' + clientId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('eventsContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('eventsModal')).show();
        })
        .catch(error => {
            console.error('Error loading events:', error);
            alert('Error loading client events');
        });
}

async function fetchBookingAvailability(dateStr) {
    if (!dateStr) return null;
    const url = 'clients.php?booking_status=1&date=' + encodeURIComponent(dateStr);
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json().catch(function() { return null; });
    if (!data || !data.success) return null;
    return data.availability || null;
}

function applyBookingHint(inputEl, hintEl, submitBtn, availability) {
    if (!hintEl || !availability) return;
    hintEl.classList.remove('text-danger', 'text-warning', 'text-success');

    const status = availability.status || 'Available';
    const remaining = typeof availability.remaining === 'number' ? availability.remaining : 0;
    const limit = typeof availability.limit === 'number' ? availability.limit : 4;
    const count = typeof availability.count === 'number' ? availability.count : 0;

    if (status === 'Packed' || count >= limit || remaining <= 0) {
        hintEl.classList.add('text-danger');
        hintEl.textContent = 'Packed • Selected date is fully packed.';
        if (submitBtn) submitBtn.disabled = true;
    } else if (status === 'Limited Slots' || remaining <= 1) {
        hintEl.classList.add('text-warning');
        hintEl.textContent = remaining + ' Slot Left • ' + count + '/' + limit + ' booked';
        if (submitBtn) submitBtn.disabled = false;
    } else {
        hintEl.classList.add('text-success');
        hintEl.textContent = remaining + ' Slots Left • ' + count + '/' + limit + ' booked';
        if (submitBtn) submitBtn.disabled = false;
    }
}

function bindBookingDateInput(inputId, hintId) {
    const inputEl = document.getElementById(inputId);
    const hintEl = document.getElementById(hintId);
    if (!inputEl || !hintEl) return;
    const form = inputEl.closest('form');
    const submitBtn = form ? form.querySelector('button[type=\"submit\"]') : null;

    async function refresh() {
        const v = inputEl.value;
        if (!v) {
            hintEl.classList.remove('text-danger', 'text-warning', 'text-success');
            hintEl.textContent = 'Max 4 clients per date.';
            if (submitBtn) submitBtn.disabled = false;
            return;
        }
        const availability = await fetchBookingAvailability(v);
        applyBookingHint(inputEl, hintEl, submitBtn, availability);
        if (availability && availability.status === 'Packed') {
            inputEl.value = '';
        }
    }

    inputEl.addEventListener('change', refresh);
    inputEl.addEventListener('blur', refresh);
    refresh();
}



document.addEventListener('DOMContentLoaded', function() {
    bindBookingDateInput('add_booking_date', 'add_booking_hint');
    bindBookingDateInput('edit_booking_date', 'edit_booking_hint');
});

// Load edit data if available
" . ($editClient ? "
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_client_id').value = '" . $editClient['id'] . "';
    document.getElementById('edit_name').value = '" . addslashes($editClient['name']) . "';
    document.getElementById('edit_phone').value = '" . addslashes($editClient['phone']) . "';
    document.getElementById('edit_email').value = '" . addslashes($editClient['email']) . "';
    document.getElementById('edit_company').value = '" . addslashes($editClient['company']) . "';
    document.getElementById('edit_address').value = '" . addslashes($editClient['address']) . "';
    document.getElementById('edit_assigned_to').value = '" . addslashes((string) ($editClient['assigned_to'] ?? '')) . "';
    document.getElementById('edit_workflow_status').value = '" . addslashes((string) ($editClient['workflow_status'] ?? 'New Lead')) . "';
    document.getElementById('edit_booking_date').value = '" . addslashes((string) ($editClient['booking_date'] ?? '')) . "';
    
    // Show edit modal
    new bootstrap.Modal(document.getElementById('editClientModal')).show();
});
" : "") . "
</script>
";
require_once '../includes/footer.php';
?>
