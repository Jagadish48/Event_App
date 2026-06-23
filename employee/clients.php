<?php
$pageTitle = 'Clients';
require_once __DIR__ . '/../config/database.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    if (!isLoggedIn() || !isEmployee()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }

        http_response_code(401);
        echo "<div class='text-muted'>Unauthorized.</div>";
        exit();
    }
} else {
    requireEmployee();
}

ensureClientWorkflowSchema();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

$workflowStatuses = [
    'New Lead',
    'Contacted',
    'Meeting Scheduled',
    'Proposal Sent',
    'Confirmed',
    'Event Ongoing',
    'Completed'
];

$statusMeta = [
    'New Lead' => ['badge' => 'bg-info', 'progress' => 10],
    'Contacted' => ['badge' => 'bg-primary', 'progress' => 25],
    'Meeting Scheduled' => ['badge' => 'bg-primary', 'progress' => 40],
    'Proposal Sent' => ['badge' => 'bg-warning', 'progress' => 55],
    'Confirmed' => ['badge' => 'bg-success', 'progress' => 70],
    'Event Ongoing' => ['badge' => 'bg-warning', 'progress' => 85],
    'Completed' => ['badge' => 'bg-success', 'progress' => 100]
];

if (!$isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif ($action === 'delete' || $action === 'delete_client' || $action === 'remove') {
        $error = 'You do not have permission to delete clients.';
    } elseif ($action === 'add') {
        $name = trim((string) clean_input($_POST['name'] ?? ''));
        $phone = trim((string) clean_input($_POST['phone'] ?? ''));
        $email = trim((string) clean_input($_POST['email'] ?? ''));
        $company = trim((string) clean_input($_POST['company'] ?? ''));
        $address = trim((string) clean_input($_POST['address'] ?? ''));
        $bookingDate = trim((string) clean_input($_POST['booking_date'] ?? ''));
        $workflowStatus = (string) clean_input($_POST['workflow_status'] ?? 'New Lead');
        if (!in_array($workflowStatus, $workflowStatuses, true)) {
            $workflowStatus = 'New Lead';
        }

        if ($name === '' || $phone === '') {
            $error = 'Client name and phone are required.';
        } elseif (!isValidISODate($bookingDate)) {
            $error = 'Please select a valid booking date.';
        } else {
            try {
                $availability = getClientBookingAvailability($bookingDate);
                if (($availability['status'] ?? '') === 'Packed') {
                    $error = 'Selected date is fully packed.';
                } else {
                $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, company, address, assigned_to, workflow_status, booking_date, workflow_updated_at)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone,
                    $company !== '' ? $company : null,
                    $address !== '' ? $address : null,
                    (int) $_SESSION['user_id'],
                    $workflowStatus,
                    $bookingDate
                ]);
                $success = 'Client added successfully.';
                }
            } catch (PDOException $e) {
                $error = 'Failed to add client.';
            }
        }
    } elseif ($action === 'edit') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $name = trim((string) clean_input($_POST['name'] ?? ''));
        $phone = trim((string) clean_input($_POST['phone'] ?? ''));
        $email = trim((string) clean_input($_POST['email'] ?? ''));
        $company = trim((string) clean_input($_POST['company'] ?? ''));
        $address = trim((string) clean_input($_POST['address'] ?? ''));
        $bookingDate = trim((string) clean_input($_POST['booking_date'] ?? ''));
        $workflowStatus = (string) clean_input($_POST['workflow_status'] ?? 'New Lead');
        if (!in_array($workflowStatus, $workflowStatuses, true)) {
            $workflowStatus = 'New Lead';
        }

        if ($clientId < 1) {
            $error = 'Invalid client.';
        } elseif ($name === '' || $phone === '') {
            $error = 'Client name and phone are required.';
        } elseif (!isValidISODate($bookingDate)) {
            $error = 'Please select a valid booking date.';
        } else {
            try {
                $availability = getClientBookingAvailability($bookingDate, $clientId);
                if (($availability['status'] ?? '') === 'Packed') {
                    $error = 'Selected date is fully packed.';
                } else {
                $stmt = $pdo->prepare("UPDATE clients
                                       SET name = ?, email = ?, phone = ?, company = ?, address = ?, workflow_status = ?, booking_date = ?, workflow_updated_at = NOW()
                                       WHERE id = ? AND assigned_to = ?
                                       LIMIT 1");
                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone,
                    $company !== '' ? $company : null,
                    $address !== '' ? $address : null,
                    $workflowStatus,
                    $bookingDate,
                    $clientId,
                    (int) $_SESSION['user_id']
                ]);
                if ($stmt->rowCount() < 1) {
                    $error = 'Client not found or not assigned to you.';
                } else {
                    $success = 'Client updated successfully.';
                }
                }
            } catch (PDOException $e) {
                $error = 'Failed to update client.';
            }
        }
    }
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }

    if ($action === 'delete' || $action === 'delete_client' || $action === 'remove') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Delete is not allowed for employee accounts.']);
        exit();
    }

    if ($action === 'update_client_status') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $status = clean_input($_POST['status'] ?? '');
        if ($clientId < 1 || !in_array($status, $workflowStatuses, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid input']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE clients SET workflow_status = ?, workflow_updated_at = NOW() WHERE id = ? AND assigned_to = ?");
            $stmt->execute([$status, $clientId, (int) $_SESSION['user_id']]);
            if ($stmt->rowCount() < 1) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Client not found']);
                exit();
            }

            $stmt = $pdo->prepare("SELECT DATE_FORMAT(workflow_updated_at, '%d %b %Y, %h:%i %p') as updated_fmt FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $row = $stmt->fetch();

            $meta = $statusMeta[$status] ?? ['badge' => 'bg-info', 'progress' => 0];

            echo json_encode([
                'success' => true,
                'message' => 'Client status updated successfully',
                'updated_at' => $row['updated_fmt'] ?? '',
                'badge' => $meta['badge'],
                'progress' => (int) $meta['progress']
            ]);
            exit();
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
            exit();
        }
    }

    if ($action === 'add_client_note') {
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($clientId < 1 || $note === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Note is required']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND assigned_to = ? LIMIT 1");
            $stmt->execute([$clientId, (int) $_SESSION['user_id']]);
            $client = $stmt->fetch();
            if (!$client) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Client not found']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO client_notes (client_id, user_id, note) VALUES (?, ?, ?)");
            $stmt->execute([$clientId, (int) $_SESSION['user_id'], $note]);
            $noteId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p') as created_fmt FROM client_notes WHERE id = ? LIMIT 1");
            $stmt->execute([$noteId]);
            $row = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => 'Note added successfully',
                'note' => [
                    'id' => $noteId,
                    'text' => $note,
                    'created_at' => $row['created_fmt'] ?? ''
                ]
            ]);
            exit();
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
            exit();
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'booking_status')) {
    header('Content-Type: application/json; charset=utf-8');
    $date = trim((string) clean_input($_GET['date'] ?? ''));
    $excludeId = (int) ($_GET['exclude_id'] ?? 0);
    if (!isValidISODate($date)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid date']);
        exit();
    }
    $availability = getClientBookingAvailability($date, $excludeId);
    echo json_encode(['success' => true, 'availability' => $availability]);
    exit();
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'client_events')) {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId < 1) {
        echo "<div class='text-muted'>Invalid client.</div>";
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND assigned_to = ? LIMIT 1");
        $stmt->execute([$clientId, (int) $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            echo "<div class='text-muted'>Client not found.</div>";
            exit();
        }

        $stmt = $pdo->prepare("SELECT id, name, start_date, end_date, venue, status FROM events WHERE client_id = ? ORDER BY start_date DESC");
        $stmt->execute([$clientId]);
        $events = $stmt->fetchAll();

        if (!$events) {
            echo "<div class='text-muted'>No events found for this client.</div>";
            exit();
        }

        echo "<div class='table-responsive'><table class='table table-hover mb-0'><thead><tr><th>Event</th><th>Dates</th><th>Status</th></tr></thead><tbody>";
        foreach ($events as $ev) {
            $badge = 'bg-info';
            if (($ev['status'] ?? '') === 'active') $badge = 'bg-warning';
            if (($ev['status'] ?? '') === 'completed') $badge = 'bg-success';
            if (($ev['status'] ?? '') === 'cancelled') $badge = 'bg-danger';

            $dates = htmlspecialchars(formatDate($ev['start_date'])) . ' - ' . htmlspecialchars(formatDate($ev['end_date']));
            echo "<tr>";
            echo "<td><div class='fw-semibold'>" . htmlspecialchars($ev['name']) . "</div><div class='text-muted small'>" . htmlspecialchars($ev['venue'] ?: 'N/A') . "</div></td>";
            echo "<td>" . $dates . "</td>";
            echo "<td><span class='badge " . $badge . "'>" . htmlspecialchars(ucfirst((string) $ev['status'])) . "</span></td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
        exit();
    } catch (PDOException $e) {
        echo "<div class='text-muted'>Failed to load events.</div>";
        exit();
    }
}

try {
    $stmt = $pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM events e WHERE e.client_id = c.id) as events_total,
        (SELECT COUNT(*) FROM events e WHERE e.client_id = c.id AND e.status IN ('planning','active')) as events_active,
        (SELECT COUNT(*) FROM events e WHERE e.client_id = c.id AND e.status = 'completed') as events_completed
        FROM clients c
        WHERE c.assigned_to = ?
        ORDER BY COALESCE(c.workflow_updated_at, c.updated_at, c.created_at) DESC");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $clients = $stmt->fetchAll();

    $stats = [
        'total' => count($clients),
        'active' => 0,
        'completed' => 0,
        'events_managed' => 0,
        'events_completed' => 0
    ];

    $statusCounts = [];
    foreach ($workflowStatuses as $s) {
        $statusCounts[$s] = 0;
    }

    foreach ($clients as $c) {
        $st = (string) ($c['workflow_status'] ?? 'New Lead');
        if (isset($statusCounts[$st])) {
            $statusCounts[$st]++;
        }
        if ($st !== 'Completed') {
            $stats['active']++;
        } else {
            $stats['completed']++;
        }
        $stats['events_managed'] += (int) ($c['events_total'] ?? 0);
        $stats['events_completed'] += (int) ($c['events_completed'] ?? 0);
    }

} catch (PDOException $e) {
    $error = 'Error fetching clients';
    $clients = [];
    $stats = ['total' => 0, 'active' => 0, 'completed' => 0, 'events_managed' => 0, 'events_completed' => 0];
    $statusCounts = [];
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

require_once '../includes/header.php';
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
            <h1 class="h3 page-title">Clients</h1>
            <div class="page-subtitle">Track assigned clients and move them through the workflow</div>
        </div>
        <div class="page-actions">
            <span class="badge bg-info"><?php echo (int) ($stats['total'] ?? 0); ?> assigned</span>
            <span class="badge bg-success"><?php echo (int) ($stats['completed'] ?? 0); ?> completed</span>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addClientModal">
                <i class="fas fa-plus me-2"></i>Add Client
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="leads.php">
            <i class="fas fa-handshake me-2"></i>Leads
        </a>
        <a class="btn btn-secondary" href="attendance.php">
            <i class="fas fa-clock me-2"></i>Attendance
        </a>
        <a class="btn btn-primary" href="expenses.php?open=add">
            <i class="fas fa-plus me-2"></i>Add Expense
        </a>
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

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card primary h-100">
                <div class="stat-icon"><i class="fas fa-building"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['total'] ?? 0); ?></div>
                <div class="stat-label">Assigned Clients</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning h-100">
                <div class="stat-icon"><i class="fas fa-signal"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['active'] ?? 0); ?></div>
                <div class="stat-label">Active Clients</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['events_managed'] ?? 0); ?></div>
                <div class="stat-label">Events Managed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card success h-100">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['events_completed'] ?? 0); ?></div>
                <div class="stat-label">Completed Events</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Status Overview</h5>
            <small class="text-muted">This is your client pipeline</small>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($workflowStatuses as $s): ?>
                    <?php $meta = $statusMeta[$s] ?? ['badge' => 'bg-info', 'progress' => 0]; ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="d-flex justify-content-between align-items-center p-3 rounded panel-lite">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($s); ?></div>
                                <div class="text-muted small"><?php echo (int) ($statusCounts[$s] ?? 0); ?> client(s)</div>
                            </div>
                            <span class="badge <?php echo $meta['badge']; ?>"><?php echo (int) ($meta['progress']); ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Assigned Clients</h5>
        </div>
        <div class="card-body">
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-building"></i></div>
                    <div class="empty-title">No clients assigned yet</div>
                    <div class="empty-subtitle">When a client is assigned to you, they’ll appear here with their workflow status and event history.</div>
                    <div class="empty-actions">
                        <a class="btn btn-secondary" href="leads.php"><i class="fas fa-handshake me-2"></i>View Leads</a>
                        <a class="btn btn-secondary" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Back to Dashboard</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="clientsTable">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Booking Date</th>
                                <th>Booking</th>
                                <th>Progress</th>
                                <th>Events</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                    $st = (string) ($client['workflow_status'] ?? 'New Lead');
                                    if (!isset($statusMeta[$st])) $st = 'New Lead';
                                    $meta = $statusMeta[$st];
                                    $updatedAt = $client['workflow_updated_at'] ?: ($client['updated_at'] ?: $client['created_at']);
                                ?>
                                <tr class="js-client-row" data-client-id="<?php echo (int) $client['id']; ?>">
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($client['name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($client['company'] ?: 'N/A'); ?></div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?php if (!empty($client['email'])): ?>
                                                <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($client['email']); ?></div>
                                            <?php endif; ?>
                                            <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($client['phone']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge js-client-badge <?php echo $meta['badge']; ?>"><?php echo htmlspecialchars($st); ?></span>
                                            <select class="form-select form-select-sm js-client-status" data-client-id="<?php echo (int) $client['id']; ?>" data-current-status="<?php echo htmlspecialchars($st); ?>">
                                                <?php foreach ($workflowStatuses as $opt): ?>
                                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $st ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($opt); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
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
                                    <td>
                                        <div class="small text-muted mb-1"><span class="js-client-progress-text"><?php echo (int) $meta['progress']; ?>%</span></div>
                                        <div class="progress">
                                            <div class="progress-bar bg-<?php echo $meta['progress'] >= 70 ? 'success' : ($meta['progress'] >= 40 ? 'primary' : 'warning'); ?> js-client-progress" role="progressbar" style="width: <?php echo (int) $meta['progress']; ?>%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small text-muted"><?php echo (int) ($client['events_total'] ?? 0); ?> total</div>
                                        <button class="btn btn-sm btn-info mt-1" onclick="viewClientEvents(<?php echo (int) $client['id']; ?>)">
                                            <i class="fas fa-calendar"></i>
                                        </button>
                                    </td>
                                    <td class="small text-muted js-client-updated">
                                        <?php echo $updatedAt ? ('Updated ' . htmlspecialchars(date('d M Y, h:i A', strtotime($updatedAt)))) : '-'; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-primary" onclick="openClientProfile(this)"
                                                data-client-id="<?php echo (int) $client['id']; ?>"
                                                data-client-name="<?php echo htmlspecialchars($client['name']); ?>"
                                                data-client-company="<?php echo htmlspecialchars($client['company'] ?: ''); ?>"
                                                data-client-email="<?php echo htmlspecialchars($client['email'] ?: ''); ?>"
                                                data-client-phone="<?php echo htmlspecialchars($client['phone'] ?: ''); ?>"
                                                data-client-address="<?php echo htmlspecialchars($client['address'] ?: ''); ?>"
                                                data-client-booking-date="<?php echo htmlspecialchars((string) ($client['booking_date'] ?? '')); ?>"
                                                data-client-status="<?php echo htmlspecialchars($st); ?>"
                                                data-client-progress="<?php echo (int) $meta['progress']; ?>">
                                                <i class="fas fa-user"></i>
                                            </button>
                                            <button class="btn btn-sm btn-secondary" type="button" onclick="openEditClient(this)"
                                                data-client-id="<?php echo (int) $client['id']; ?>"
                                                data-client-name="<?php echo htmlspecialchars($client['name']); ?>"
                                                data-client-company="<?php echo htmlspecialchars($client['company'] ?: ''); ?>"
                                                data-client-email="<?php echo htmlspecialchars($client['email'] ?: ''); ?>"
                                                data-client-phone="<?php echo htmlspecialchars($client['phone'] ?: ''); ?>"
                                                data-client-address="<?php echo htmlspecialchars($client['address'] ?: ''); ?>"
                                                data-client-booking-date="<?php echo htmlspecialchars((string) ($client['booking_date'] ?? '')); ?>"
                                                data-client-status="<?php echo htmlspecialchars($st); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-success" onclick="openNoteModal(<?php echo (int) $client['id']; ?>, '<?php echo htmlspecialchars($client['name']); ?>')">
                                                <i class="fas fa-comment-dots"></i>
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

<div class="modal fade" id="clientProfileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clientProfileTitle">Client Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><h6 class="mb-0">Contact</h6></div>
                            <div class="card-body">
                                <div class="fw-semibold" id="cpName"></div>
                                <div class="text-muted small mb-2" id="cpCompany"></div>
                                <div class="small" id="cpEmail"></div>
                                <div class="small" id="cpPhone"></div>
                                <div class="small text-muted mt-2" id="cpAddress"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header"><h6 class="mb-0">Workflow</h6></div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-info" id="cpStatus"></span>
                                    <span class="text-muted small" id="cpProgressText"></span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-primary" id="cpProgressBar" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="mt-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                    <div class="text-muted small" id="cpBookingDate"></div>
                                    <span class="badge" id="cpBookingStatus"></span>
                                </div>
                                <div class="mt-3">
                                    <button class="btn btn-info w-100" id="cpViewEventsBtn" type="button">
                                        <i class="fas fa-calendar me-2"></i>View Events
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">Event Details</h6></div>
                    <div class="card-body" id="clientEventsContent">
                        <div class="text-muted">Select “View Events” to load client events.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="noteModalTitle">Client Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="noteClientId" value="">
                <div class="mb-3">
                    <label class="form-label">Add Note</label>
                    <textarea class="form-control" id="noteText" rows="3" placeholder="Add a quick update about communication, meeting, requirements..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" onclick="submitClientNote()">Save Note</button>
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
                <div class="mt-3" id="noteList">
                    <div class="text-muted">Notes will appear here after you add them.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <input type="text" class="form-control" name="company">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Workflow Status</label>
                            <select class="form-select" name="workflow_status">
                                <?php foreach ($workflowStatuses as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === 'New Lead' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Booking Date *</label>
                            <input type="date" class="form-control" name="booking_date" id="add_booking_date" required>
                            <div class="form-text" id="add_booking_hint">Max <?php echo (int) getClientBookingDailyLimit(); ?> clients per date.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editClientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="client_id" id="edit_client_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone *</label>
                            <input type="tel" class="form-control" name="phone" id="edit_phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <input type="text" class="form-control" name="company" id="edit_company">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Workflow Status</label>
                            <select class="form-select" name="workflow_status" id="edit_workflow_status">
                                <?php foreach ($workflowStatuses as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Booking Date *</label>
                            <input type="date" class="form-control" name="booking_date" id="edit_booking_date" required>
                            <div class="form-text" id="edit_booking_hint">Max <?php echo (int) getClientBookingDailyLimit(); ?> clients per date.</div>
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

<?php
$additional_js = "
<script>
function statusBadgeClass(label) {
    switch (label) {
        case 'New Lead': return 'bg-info';
        case 'Contacted': return 'bg-primary';
        case 'Meeting Scheduled': return 'bg-primary';
        case 'Proposal Sent': return 'bg-warning';
        case 'Confirmed': return 'bg-success';
        case 'Event Ongoing': return 'bg-warning';
        case 'Completed': return 'bg-success';
        default: return 'bg-info';
    }
}

function statusProgress(label) {
    switch (label) {
        case 'New Lead': return 10;
        case 'Contacted': return 25;
        case 'Meeting Scheduled': return 40;
        case 'Proposal Sent': return 55;
        case 'Confirmed': return 70;
        case 'Event Ongoing': return 85;
        case 'Completed': return 100;
        default: return 0;
    }
}

async function parseJsonOrLog(response, context) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('[Client Notes] Non-JSON response for ' + context + ':', text);
        throw new Error('Server returned an invalid response. Check console for details.');
    }
}

async function fetchBookingAvailability(dateStr, excludeId) {
    if (!dateStr) return null;
    const url = window.location.pathname
        + '?action=booking_status&date=' + encodeURIComponent(dateStr)
        + (excludeId ? ('&exclude_id=' + encodeURIComponent(String(excludeId))) : '');
    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await parseJsonOrLog(response, 'booking_status');
    if (!data || !data.success) return null;
    return data.availability || null;
}

function bookingBadgeClass(status) {
    if (status === 'Packed') return 'bg-danger';
    if (status === 'Limited Slots') return 'bg-warning';
    return 'bg-success';
}

function applyBookingHint(hintEl, submitBtn, availability) {
    if (!hintEl) return;
    hintEl.classList.remove('text-danger', 'text-warning', 'text-success');

    if (!availability) {
        hintEl.textContent = 'Max 4 clients per date.';
        if (submitBtn) submitBtn.disabled = false;
        return;
    }

    const status = availability.status || 'Available';
    const remaining = typeof availability.remaining === 'number' ? availability.remaining : 0;
    const limit = typeof availability.limit === 'number' ? availability.limit : 4;
    const count = typeof availability.count === 'number' ? availability.count : 0;

    if (status === 'Packed' || remaining <= 0 || count >= limit) {
        hintEl.classList.add('text-danger');
        hintEl.textContent = 'Packed • Selected date is fully packed.';
        if (submitBtn) submitBtn.disabled = true;
        return;
    }

    if (status === 'Limited Slots' || remaining <= 1) {
        hintEl.classList.add('text-warning');
        hintEl.textContent = remaining + ' Slot Left • ' + count + '/' + limit + ' booked';
        if (submitBtn) submitBtn.disabled = false;
        return;
    }

    hintEl.classList.add('text-success');
    hintEl.textContent = remaining + ' Slots Left • ' + count + '/' + limit + ' booked';
    if (submitBtn) submitBtn.disabled = false;
}

function bindBookingDateInput(inputId, hintId, excludeIdFn) {
    const inputEl = document.getElementById(inputId);
    const hintEl = document.getElementById(hintId);
    if (!inputEl || !hintEl) return;
    const form = inputEl.closest('form');
    const submitBtn = form ? form.querySelector('button[type=\"submit\"]') : null;

    async function refresh() {
        const v = inputEl.value;
        if (!v) {
            applyBookingHint(hintEl, submitBtn, null);
            return;
        }
        const excludeId = excludeIdFn ? excludeIdFn() : 0;
        const availability = await fetchBookingAvailability(v, excludeId);
        applyBookingHint(hintEl, submitBtn, availability);
        if (availability && availability.status === 'Packed') {
            inputEl.value = '';
        }
    }

    inputEl.addEventListener('change', refresh);
    inputEl.addEventListener('blur', refresh);
    refresh();
}

async function updateClientStatus(selectEl) {
    const row = selectEl.closest('.js-client-row');
    const clientId = selectEl.dataset.clientId;
    const newStatus = selectEl.value;
    const previousStatus = selectEl.dataset.currentStatus || '';
    const badge = row ? row.querySelector('.js-client-badge') : null;
    const updatedEl = row ? row.querySelector('.js-client-updated') : null;
    const progressBar = row ? row.querySelector('.js-client-progress') : null;
    const progressText = row ? row.querySelector('.js-client-progress-text') : null;

    const csrfToken = document.querySelector('.main-content')?.dataset?.csrfToken || '';
    if (!csrfToken) {
        showAlert('danger', 'Security token missing. Please refresh the page.');
        selectEl.value = previousStatus || selectEl.value;
        return;
    }

    selectEl.disabled = true;
    selectEl.classList.add('is-loading');
    if (badge) {
        badge.classList.remove('bg-info', 'bg-primary', 'bg-warning', 'bg-success', 'bg-danger');
        badge.classList.add(statusBadgeClass(newStatus));
        badge.textContent = newStatus;
    }
    if (progressBar && progressText) {
        const p = statusProgress(newStatus);
        progressText.textContent = p + '%';
        progressBar.style.width = p + '%';
    }

    try {
        const formData = new FormData();
        formData.append('action', 'update_client_status');
        formData.append('client_id', clientId);
        formData.append('status', newStatus);
        formData.append('csrf_token', csrfToken);

        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });

        const result = await parseJsonOrLog(response, 'update_client_status');
        if (!result || !result.success) {
            throw new Error(result && result.message ? result.message : 'Failed to update client status');
        }
        if (updatedEl && result.updated_at) {
            updatedEl.textContent = 'Updated ' + result.updated_at;
        }
        selectEl.dataset.currentStatus = newStatus;
        showAlert('success', result.message || 'Client status updated successfully');
    } catch (err) {
        if (badge) {
            badge.classList.remove('bg-info', 'bg-primary', 'bg-warning', 'bg-success', 'bg-danger');
            badge.classList.add(statusBadgeClass(previousStatus));
            badge.textContent = previousStatus || badge.textContent;
        }
        if (progressBar && progressText) {
            const p = statusProgress(previousStatus);
            progressText.textContent = p + '%';
            progressBar.style.width = p + '%';
        }
        selectEl.value = previousStatus || selectEl.value;
        showAlert('danger', err && err.message ? err.message : 'Failed to update client status');
    } finally {
        selectEl.disabled = false;
        selectEl.classList.remove('is-loading');
    }
}

document.querySelectorAll('.js-client-status').forEach(function(sel) {
    sel.addEventListener('change', function() { updateClientStatus(sel); });
});

document.addEventListener('DOMContentLoaded', function() {
    bindBookingDateInput('add_booking_date', 'add_booking_hint', function() { return 0; });
    bindBookingDateInput('edit_booking_date', 'edit_booking_hint', function() {
        const idEl = document.getElementById('edit_client_id');
        return idEl ? (parseInt(idEl.value || '0', 10) || 0) : 0;
    });
});

function openClientProfile(btn) {
    const clientId = btn.dataset.clientId;
    document.getElementById('clientProfileTitle').textContent = btn.dataset.clientName || 'Client Profile';
    document.getElementById('cpName').textContent = btn.dataset.clientName || '';
    document.getElementById('cpCompany').textContent = btn.dataset.clientCompany || '';
    document.getElementById('cpEmail').textContent = btn.dataset.clientEmail ? ('Email: ' + btn.dataset.clientEmail) : '';
    document.getElementById('cpPhone').textContent = btn.dataset.clientPhone ? ('Phone: ' + btn.dataset.clientPhone) : '';
    document.getElementById('cpAddress').textContent = btn.dataset.clientAddress ? ('Address: ' + btn.dataset.clientAddress) : '';
    document.getElementById('cpStatus').textContent = btn.dataset.clientStatus || '';
    document.getElementById('cpStatus').className = 'badge ' + statusBadgeClass(btn.dataset.clientStatus || '');

    const p = parseInt(btn.dataset.clientProgress || '0', 10) || 0;
    document.getElementById('cpProgressText').textContent = p + '%';
    document.getElementById('cpProgressBar').style.width = p + '%';
    const bookingDate = btn.dataset.clientBookingDate || '';
    const bookingDateEl = document.getElementById('cpBookingDate');
    const bookingStatusEl = document.getElementById('cpBookingStatus');
    if (bookingDateEl) {
        bookingDateEl.textContent = bookingDate ? ('Booking: ' + bookingDate) : 'Booking: -';
    }
    if (bookingStatusEl) {
        bookingStatusEl.className = 'badge bg-secondary';
        bookingStatusEl.textContent = 'Checking...';
    }
    document.getElementById('clientEventsContent').innerHTML = '<div class=\"text-muted\">Select “View Events” to load client events.</div>';
    const viewBtn = document.getElementById('cpViewEventsBtn');
    viewBtn.onclick = function() { viewClientEvents(clientId, true); };

    if (bookingDate) {
        fetchBookingAvailability(bookingDate, 0)
            .then(function(av) {
                if (!av || !bookingStatusEl) return;
                bookingStatusEl.className = 'badge ' + bookingBadgeClass(av.status || 'Available');
                bookingStatusEl.textContent = (av.status || 'Available') + ' • ' + (av.remaining || 0) + ' left';
            })
            .catch(function() {
                if (!bookingStatusEl) return;
                bookingStatusEl.className = 'badge bg-secondary';
                bookingStatusEl.textContent = 'Unavailable';
            });
    } else if (bookingStatusEl) {
        bookingStatusEl.className = 'badge bg-secondary';
        bookingStatusEl.textContent = 'Not set';
    }

    new bootstrap.Modal(document.getElementById('clientProfileModal')).show();
}

function openEditClient(btn) {
    document.getElementById('edit_client_id').value = btn.dataset.clientId || '';
    document.getElementById('edit_name').value = btn.dataset.clientName || '';
    document.getElementById('edit_phone').value = btn.dataset.clientPhone || '';
    document.getElementById('edit_email').value = btn.dataset.clientEmail || '';
    document.getElementById('edit_company').value = btn.dataset.clientCompany || '';
    document.getElementById('edit_address').value = btn.dataset.clientAddress || '';
    document.getElementById('edit_workflow_status').value = btn.dataset.clientStatus || 'New Lead';
    document.getElementById('edit_booking_date').value = btn.dataset.clientBookingDate || '';
    new bootstrap.Modal(document.getElementById('editClientModal')).show();
}

function viewClientEvents(clientId, injectIntoProfile) {
    const url = window.location.pathname + '?action=client_events&client_id=' + encodeURIComponent(clientId);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            if (injectIntoProfile) {
                document.getElementById('clientEventsContent').innerHTML = html;
            } else {
                document.getElementById('clientEventsContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('clientProfileModal')).show();
            }
            if (typeof initializeActionButtonsTargets === 'function') initializeActionButtonsTargets();
        })
        .catch(function() {
            const target = document.getElementById('clientEventsContent');
            if (target) target.innerHTML = '<div class=\"text-muted\">Failed to load events.</div>';
        });
}

function openNoteModal(clientId, clientName) {
    document.getElementById('noteClientId').value = clientId;
    document.getElementById('noteModalTitle').textContent = 'Client Notes • ' + clientName;
    document.getElementById('noteText').value = '';
    document.getElementById('noteList').innerHTML = '<div class=\"text-muted\">Add a note to start tracking communication.</div>';
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

async function submitClientNote() {
    const clientId = document.getElementById('noteClientId').value;
    const note = document.getElementById('noteText').value.trim();
    if (!note) {
        showAlert('danger', 'Please enter a note.');
        return;
    }

    const csrfToken = document.querySelector('.main-content')?.dataset?.csrfToken || '';
    if (!csrfToken) {
        showAlert('danger', 'Security token missing. Please refresh the page.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_client_note');
        formData.append('client_id', clientId);
        formData.append('note', note);
        formData.append('csrf_token', csrfToken);

        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const result = await parseJsonOrLog(response, 'add_client_note');
        if (!result || !result.success) {
            throw new Error(result && result.message ? result.message : 'Failed to add note');
        }

        const list = document.getElementById('noteList');
        const item = document.createElement('div');
        item.className = 'p-3 rounded mb-2';
        item.style.background = 'rgba(255,255,255,0.03)';
        item.style.border = '1px solid rgba(229,231,235,0.08)';
        item.innerHTML = '<div class=\"fw-semibold\">' + (result.note.created_at || '') + '</div><div class=\"text-muted small mt-1\"></div>';
        item.querySelector('.text-muted').textContent = result.note.text || '';
        if (list && list.querySelector('.text-muted')) {
            list.innerHTML = '';
        }
        if (list) list.prepend(item);
        document.getElementById('noteText').value = '';
        showAlert('success', result.message || 'Note added successfully');
    } catch (err) {
        showAlert('danger', err && err.message ? err.message : 'Failed to add note');
    }
}
</script>
";
require_once '../includes/footer.php';
?>
