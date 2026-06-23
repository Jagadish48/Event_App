<?php
$pageTitle = 'Event Management';
require_once '../includes/header.php';
requireAdmin();
ensureEventProfitFeedbackIncentiveSchema();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            try {
                $stmt = $pdo->prepare("INSERT INTO events (name, description, client_id, budget, start_date, end_date, venue, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['description']),
                    clean_input($_POST['client_id']) ?: null,
                    clean_input($_POST['budget']),
                    clean_input($_POST['start_date']),
                    clean_input($_POST['end_date']),
                    clean_input($_POST['venue']),
                    $_SESSION['user_id']
                ]);
                $eventId = $pdo->lastInsertId();
                
                // Assign team members
                if (isset($_POST['team_members']) && is_array($_POST['team_members'])) {
                    foreach ($_POST['team_members'] as $memberId) {
                        $stmt = $pdo->prepare("INSERT INTO event_team (event_id, user_id, role) VALUES (?, ?, ?)");
                        $stmt->execute([$eventId, clean_input($memberId), clean_input($_POST['role_' . $memberId])]);

                        // WhatsApp: notify team member of assignment
                        if (wa_isTriggerEnabled('event_team_assigned')) {
                            try {
                                $stmtEmp = $pdo->prepare("SELECT u.name, emp.phone FROM users u LEFT JOIN employees emp ON emp.user_id = u.id WHERE u.id = ? LIMIT 1");
                                $stmtEmp->execute([(int)clean_input($memberId)]);
                                $empRow = $stmtEmp->fetch();
                                if ($empRow && !empty($empRow['phone'])) {
                                    sendWhatsAppMessage((string)$empRow['phone'], 'event_team_assigned', [
                                        (string)($empRow['name'] ?? 'Team Member'),
                                        clean_input($_POST['name']),
                                        clean_input($_POST['venue'] ?? 'TBD'),
                                        clean_input($_POST['start_date'] ?? ''),
                                        clean_input($_POST['role_' . $memberId] ?? 'Team Member'),
                                    ], ['related_type' => 'event', 'related_id' => (int)$eventId, 'user_id' => (int)clean_input($memberId)]);
                                }
                            } catch (Exception $notifEx) {}
                        }
                    }
                }
                
                header('Location: events.php?success=' . urlencode('Event added successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error adding event: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'edit') {
            try {
                // Detect status change
                $prevStatusRow = null;
                try {
                    $stmtPrev = $pdo->prepare("SELECT status, name, venue, start_date, client_id FROM events WHERE id = ? LIMIT 1");
                    $stmtPrev->execute([(int)clean_input($_POST['event_id'])]);
                    $prevStatusRow = $stmtPrev->fetch();
                } catch (PDOException $ex) {}

                $stmt = $pdo->prepare("UPDATE events SET name = ?, description = ?, client_id = ?, budget = ?, start_date = ?, end_date = ?, venue = ?, status = ? WHERE id = ?");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['description']),
                    clean_input($_POST['client_id']) ?: null,
                    clean_input($_POST['budget']),
                    clean_input($_POST['start_date']),
                    clean_input($_POST['end_date']),
                    clean_input($_POST['venue']),
                    clean_input($_POST['status']),
                    clean_input($_POST['event_id'])
                ]);
                
                // Update team members
                $stmt = $pdo->prepare("DELETE FROM event_team WHERE event_id = ?");
                $stmt->execute([clean_input($_POST['event_id'])]);
                
                if (isset($_POST['team_members']) && is_array($_POST['team_members'])) {
                    foreach ($_POST['team_members'] as $memberId) {
                        $stmt = $pdo->prepare("INSERT INTO event_team (event_id, user_id, role) VALUES (?, ?, ?)");
                        $stmt->execute([clean_input($_POST['event_id']), clean_input($memberId), clean_input($_POST['role_' . $memberId])]);
                    }
                }

                // WhatsApp: notify team + client on status change
                $newStatus = clean_input($_POST['status']);
                $prevStatus = (string)($prevStatusRow['status'] ?? '');
                if ($prevStatus !== $newStatus && wa_isTriggerEnabled('event_status_update')) {
                    $updatedEvId = (int)clean_input($_POST['event_id']);
                    $evName  = clean_input($_POST['name']);
                    $evVenue = clean_input($_POST['venue'] ?? 'TBD');
                    $evDate  = clean_input($_POST['start_date'] ?? '');
                    // Notify team members
                    try {
                        $stmtTeam = $pdo->prepare("SELECT u.name, emp.phone FROM event_team et JOIN users u ON u.id = et.user_id LEFT JOIN employees emp ON emp.user_id = u.id WHERE et.event_id = ?");
                        $stmtTeam->execute([$updatedEvId]);
                        foreach ($stmtTeam->fetchAll() as $tm) {
                            if (!empty($tm['phone'])) {
                                sendWhatsAppMessage((string)$tm['phone'], 'event_status_update', [
                                    $evName, ucfirst($newStatus), $evVenue, $evDate
                                ], ['related_type' => 'event', 'related_id' => $updatedEvId]);
                            }
                        }
                    } catch (Exception $notifEx) {}
                    // Notify client on completion
                    if ($newStatus === 'completed' && !empty($prevStatusRow['client_id'])) {
                        try {
                            $stmtCl = $pdo->prepare("SELECT name, phone FROM clients WHERE id = ? LIMIT 1");
                            $stmtCl->execute([(int)$prevStatusRow['client_id']]);
                            $clRow = $stmtCl->fetch();
                            if ($clRow && !empty($clRow['phone'])) {
                                sendWhatsAppMessage((string)$clRow['phone'], 'event_status_update', [
                                    $evName, 'Completed ✅', $evVenue, $evDate
                                ], ['related_type' => 'event', 'related_id' => $updatedEvId]);
                            }
                        } catch (Exception $notifEx) {}
                    }
                }

                header('Location: events.php?success=' . urlencode('Event updated successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error updating event: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                $stmt->execute([clean_input($_POST['event_id'])]);
                header('Location: events.php?success=' . urlencode('Event deleted successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error deleting event: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'add_other_cost') {
            try {
                $eventId = (int) clean_input($_POST['event_id'] ?? 0);
                $label = clean_input($_POST['label'] ?? '');
                $amount = (float) clean_input($_POST['amount'] ?? 0);
                $notes = clean_input($_POST['notes'] ?? '');
                if ($eventId < 1 || $label === '' || $amount <= 0) {
                    throw new RuntimeException('Please enter a valid cost label and amount.');
                }
                $stmt = $pdo->prepare("INSERT INTO event_other_costs (event_id, label, amount, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$eventId, $label, $amount, $notes !== '' ? $notes : null]);
                computeEventFinancials($eventId);
                header('Location: events.php?success=' . urlencode('Other cost added.'));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'save_employee_payment') {
            try {
                $eventId = (int) clean_input($_POST['event_id'] ?? 0);
                $userId = (int) clean_input($_POST['user_id'] ?? 0);
                $amount = (float) clean_input($_POST['amount'] ?? 0);
                $notes = clean_input($_POST['notes'] ?? '');
                if ($eventId < 1 || $userId < 1 || $amount < 0) {
                    throw new RuntimeException('Please enter a valid employee payment.');
                }
                $stmt = $pdo->prepare("INSERT INTO event_employee_payments (event_id, user_id, amount, notes)
                                       VALUES (?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE amount = VALUES(amount), notes = VALUES(notes)");
                $stmt->execute([$eventId, $userId, $amount, $notes !== '' ? $notes : null]);
                computeEventFinancials($eventId);
                header('Location: events.php?success=' . urlencode('Employee payment saved.'));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'send_feedback_request') {
            try {
                $eventId = (int) clean_input($_POST['event_id'] ?? 0);
                $channel = clean_input($_POST['channel'] ?? 'manual');
                $recipient = clean_input($_POST['recipient'] ?? '');
                if ($eventId < 1) throw new RuntimeException('Invalid event.');
                $info = createFeedbackRequest($eventId, $channel, $recipient);
                header('Location: events.php?success=' . urlencode('Feedback request created. Link: ' . $info['link']));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'record_feedback_admin') {
            try {
                $eventId = (int) clean_input($_POST['event_id'] ?? 0);
                $rating = (int) clean_input($_POST['rating'] ?? 0);
                $message = clean_input($_POST['message'] ?? '');
                if ($eventId < 1 || $rating < 1 || $rating > 5) {
                    throw new RuntimeException('Please select a valid rating.');
                }
                $stmt = $pdo->prepare("INSERT INTO event_feedback (event_id, rating, message, source) VALUES (?, ?, ?, 'admin')");
                $stmt->execute([$eventId, $rating, $message !== '' ? $message : null]);
                generateIncentivesForEvent($eventId);
                header('Location: events.php?success=' . urlencode('Feedback saved.'));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] === 'mark_event_incentives_paid') {
            try {
                $eventId = (int) clean_input($_POST['event_id'] ?? 0);
                if ($eventId < 1) throw new RuntimeException('Invalid event.');
                $stmt = $pdo->prepare("UPDATE event_incentives SET status = 'paid' WHERE event_id = ? AND status = 'earned'");
                $stmt->execute([$eventId]);
                header('Location: events.php?success=' . urlencode('Incentives marked as paid.'));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$filter_client = isset($_GET['client']) ? clean_input($_GET['client']) : '';
$filter_q = isset($_GET['q']) ? trim((string) clean_input($_GET['q'])) : '';
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';
$filter_from = isset($_GET['from']) ? clean_input($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? clean_input($_GET['to']) : '';
$filter_budget_min = isset($_GET['budget_min']) ? clean_input($_GET['budget_min']) : '';
$filter_budget_max = isset($_GET['budget_max']) ? clean_input($_GET['budget_max']) : '';

// Get events
try {
    $query = "SELECT e.*, c.name as client_name, c.phone as client_phone, u.name as created_by_name,
                (SELECT GROUP_CONCAT(u2.name ORDER BY u2.name SEPARATOR ', ')
                    FROM event_team et2
                    JOIN users u2 ON u2.id = et2.user_id
                    WHERE et2.event_id = e.id) as team_names,
                (SELECT COALESCE(SUM(ex.amount), 0)
                    FROM event_expenses ee
                    JOIN expenses ex ON ex.id = ee.expense_id
                    WHERE ee.event_id = e.id
                        AND ex.status = 'approved'
                        AND COALESCE(ex.expense_category, 'personal') = 'client') as approved_expenses,
                (SELECT COUNT(*)
                    FROM event_expenses ee
                    JOIN expenses ex ON ex.id = ee.expense_id
                    WHERE ee.event_id = e.id
                        AND ex.status = 'pending'
                        AND COALESCE(ex.expense_category, 'personal') = 'client') as pending_approvals
              FROM events e 
              LEFT JOIN clients c ON e.client_id = c.id 
              JOIN users u ON e.created_by = u.id 
              WHERE 1=1";
    
    $params = [];
    
    if ($filter_status) {
        $query .= " AND e.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_client) {
        $query .= " AND e.client_id = ?";
        $params[] = $filter_client;
    }

    if ($filter_q !== '') {
        $query .= " AND (e.name LIKE ? OR e.venue LIKE ? OR c.name LIKE ?)";
        $like = '%' . $filter_q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($filter_employee !== '') {
        $query .= " AND EXISTS (SELECT 1 FROM event_team etx WHERE etx.event_id = e.id AND etx.user_id = ?)";
        $params[] = $filter_employee;
    }

    if ($filter_from !== '') {
        $query .= " AND e.start_date >= ?";
        $params[] = $filter_from;
    }

    if ($filter_to !== '') {
        $query .= " AND e.start_date <= ?";
        $params[] = $filter_to;
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
    $events = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching events: ' . $e->getMessage();
    $events = [];
}

$eventIncentiveTotals = [];
try {
    $eventIds = array_values(array_filter(array_map(function($ev) { return (int) ($ev['id'] ?? 0); }, $events), function($v) { return $v > 0; }));
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $pdo->prepare("SELECT event_id, COALESCE(SUM(incentive_amount), 0) as total
                               FROM event_incentives
                               WHERE event_id IN ($placeholders) AND status IN ('earned','paid')
                               GROUP BY event_id");
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll() as $row) {
            $eventIncentiveTotals[(int) $row['event_id']] = (float) ($row['total'] ?? 0);
        }
    }
} catch (PDOException $e) {}

// Get clients for dropdown
try {
    $stmt = $pdo->query("SELECT id, name, company FROM clients ORDER BY name");
    $clients = $stmt->fetchAll();
} catch(PDOException $e) {
    $clients = [];
}

// Get employees for team assignment
try {
    $stmt = $pdo->query("SELECT u.id, u.name, e.designation 
                        FROM users u 
                        LEFT JOIN employees e ON e.user_id = u.id 
                        WHERE u.role = 'employee' AND e.status = 'active' 
                        ORDER BY u.name");
    $employees = $stmt->fetchAll();
} catch(PDOException $e) {
    $employees = [];
}

// Get event for editing
$editEvent = null;
$editTeam = [];
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([clean_input($_GET['edit'])]);
        $editEvent = $stmt->fetch();
        
        if ($editEvent) {
            $stmt = $pdo->prepare("SELECT et.*, u.name, e.designation 
                                  FROM event_team et 
                                  JOIN users u ON et.user_id = u.id 
                                  LEFT JOIN employees e ON e.user_id = u.id 
                                  WHERE et.event_id = ?");
            $stmt->execute([$editEvent['id']]);
            $editTeam = $stmt->fetchAll();
        }
    } catch(PDOException $e) {
        $error = 'Error fetching event: ' . $e->getMessage();
    }
}

// Calculate statistics
$stats = [
    'total_events' => count($events),
    'planning_events' => 0,
    'active_events' => 0,
    'completed_events' => 0,
    'total_budget' => 0,
    'total_approved_expenses' => 0,
    'pending_expense_approvals' => 0,
    'remaining_budget' => 0
];

foreach ($events as $event) {
    switch ($event['status']) {
        case 'planning':
            $stats['planning_events']++;
            break;
        case 'active':
            $stats['active_events']++;
            break;
        case 'completed':
            $stats['completed_events']++;
            break;
    }
    $budget = (float) ($event['budget'] ?? 0);
    $approved = (float) ($event['approved_expenses'] ?? 0);
    $pending = (int) ($event['pending_approvals'] ?? 0);
    $stats['total_budget'] += $budget;
    $stats['total_approved_expenses'] += $approved;
    $stats['pending_expense_approvals'] += $pending;
    $stats['remaining_budget'] += max(0, $budget - $approved);
}

$stats['budget_utilization'] = $stats['total_budget'] > 0 ? round(($stats['total_approved_expenses'] / $stats['total_budget']) * 100) : 0;
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
            <h1 class="h3 page-title">Events</h1>
            <div class="page-subtitle">Create and manage events from planning to completion</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEventModal">
                <i class="fas fa-plus me-2"></i>Create Event
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="employees.php"><i class="fas fa-users me-2"></i>Team</a>
        <a class="btn btn-secondary" href="clients.php"><i class="fas fa-building me-2"></i>Clients</a>
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

    <!-- Statistics Cards -->
    <div class="events-stats-grid mb-4">
        <div class="stat-card primary">
            <div class="stat-icon">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="stat-value"><?php echo $stats['total_events']; ?></div>
            <div class="stat-label">Total Events</div>
        </div>
        <div class="stat-card primary">
            <div class="stat-icon">
                <i class="fas fa-rupee-sign"></i>
            </div>
            <div class="stat-value"><?php echo formatCurrency($stats['total_budget']); ?></div>
            <div class="stat-label">Total Budget</div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="stat-value"><?php echo formatCurrency($stats['total_approved_expenses']); ?></div>
            <div class="stat-label">Approved Expenses</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-value"><?php echo (int) $stats['pending_expense_approvals']; ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="stat-value"><?php echo (int) $stats['budget_utilization']; ?>%</div>
            <div class="stat-label">Utilization</div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stat-value"><?php echo formatCurrency($stats['remaining_budget']); ?></div>
            <div class="stat-label">Remaining</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-value"><?php echo $stats['planning_events']; ?></div>
            <div class="stat-label">Planning</div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="stat-value"><?php echo $stats['active_events']; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-value"><?php echo $stats['completed_events']; ?></div>
            <div class="stat-label">Completed</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
            <a href="events.php" class="btn btn-sm btn-secondary"><i class="fas fa-rotate-left me-2"></i>Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="js-auto-submit">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($filter_q); ?>" placeholder="Event, client, venue...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All</option>
                            <option value="planning" <?php echo $filter_status == 'planning' ? 'selected' : ''; ?>>Planning</option>
                            <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select class="form-select js-searchable-select" name="client">
                            <option value="">All</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo $filter_client == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['name']); ?>
                                    <?php if ($client['company']) echo '(' . htmlspecialchars($client['company']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employee</label>
                        <select class="form-select js-searchable-select" name="employee">
                            <option value="">All</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int) $emp['id']; ?>" <?php echo $filter_employee == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Min Budget</label>
                        <input type="number" class="form-control" name="budget_min" value="<?php echo htmlspecialchars($filter_budget_min); ?>" placeholder="0">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max Budget</label>
                        <input type="number" class="form-control" name="budget_max" value="<?php echo htmlspecialchars($filter_budget_max); ?>" placeholder="Any">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-2"></i>Apply</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Events Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-table me-2"></i>All Events</h5>
            <span class="text-muted small"><?php echo count($events); ?> item(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($events)): ?>
                <p class="text-muted">No events found for the selected criteria.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="eventsTable" data-smart-table data-export-name="events_export.csv" data-page-size="25">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Client</th>
                                <th>Dates</th>
                                <th>Budget</th>
                                <th>Used</th>
                                <th>Remaining</th>
                                <th>Profit / Loss</th>
                                <th>Rating</th>
                                <th>Incentive</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th>Team</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $budget = (float) ($event['budget'] ?? 0);
                                    $used = (float) ($event['approved_expenses'] ?? 0);
                                    $remaining = max(0, $budget - $used);
                                    $fin = computeEventFinancials((int) $event['id']);
                                    $netProfit = (float) ($fin['net_profit'] ?? 0);
                                    $profitBadge = $netProfit >= 0 ? 'success' : 'danger';
                                    $profitLabel = $netProfit >= 0 ? 'Profit' : 'Loss';
                                    $feedback = getLatestEventFeedback((int) $event['id']);
                                    $rating = (int) ($feedback['rating'] ?? 0);
                                    $incentiveTotal = (float) ($eventIncentiveTotals[(int) $event['id']] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($event['name']); ?></strong>
                                            <?php if ($event['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($event['description'], 0, 50)) . '...'; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($event['client_name']): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($event['client_name']); ?></strong>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No Client</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <i class="fas fa-calendar me-1"></i><?php echo formatDate($event['start_date']); ?>
                                            <br><i class="fas fa-calendar-check me-1"></i><?php echo formatDate($event['end_date']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($event['budget']); ?></strong>
                                    </td>
                                    <td><?php echo formatCurrency($used); ?></td>
                                    <td><?php echo formatCurrency($remaining); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $profitBadge; ?>"><?php echo $profitLabel; ?></span>
                                        <div class="<?php echo $netProfit >= 0 ? 'text-success' : 'text-danger'; ?> fw-semibold mt-1">
                                            <?php echo formatCurrency(abs($netProfit)); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($rating >= 1 && $rating <= 5): ?>
                                            <div class="d-inline-flex align-items-center gap-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star <?php echo $i <= $rating ? 'text-warning' : 'text-muted'; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="text-muted small"><?php echo $rating; ?>/5</div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($incentiveTotal > 0): ?>
                                            <span class="badge bg-info">Earned</span>
                                            <div class="fw-semibold mt-1"><?php echo formatCurrency($incentiveTotal); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($event['venue'] ?: 'TBD'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $event['status'] == 'completed' ? 'success' : 
                                                 ($event['status'] == 'active' ? 'primary' : 
                                                 ($event['status'] == 'cancelled' ? 'danger' : 'warning')); 
                                        ?>">
                                            <?php echo ucfirst($event['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($event['team_names'])): ?>
                                            <span class="text-muted small"><?php echo htmlspecialchars((string) $event['team_names']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-secondary"
                                                type="button"
                                                onclick="openEventFinanceModal(
                                                    <?php echo (int) $event['id']; ?>,
                                                    '<?php echo htmlspecialchars(addslashes((string) $event['name']), ENT_QUOTES, 'UTF-8'); ?>',
                                                    '<?php echo htmlspecialchars(addslashes((string) ($event['client_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>',
                                                    '<?php echo htmlspecialchars(addslashes((string) ($event['client_phone'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>',
                                                    <?php echo htmlspecialchars(json_encode($fin), ENT_QUOTES, 'UTF-8'); ?>,
                                                    <?php echo htmlspecialchars(json_encode($feedback ?: new stdClass()), ENT_QUOTES, 'UTF-8'); ?>,
                                                    <?php echo htmlspecialchars(json_encode(['incentive_total' => $incentiveTotal]), ENT_QUOTES, 'UTF-8'); ?>
                                                )">
                                                <i class="fas fa-chart-line"></i>
                                            </button>
                                            <button class="btn btn-sm btn-primary" onclick="editEvent(<?php echo $event['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-info" onclick="viewTeam(<?php echo $event['id']; ?>)">
                                                <i class="fas fa-users"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteEvent(<?php echo $event['id']; ?>, '<?php echo htmlspecialchars($event['name']); ?>')">
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

<form method="POST" action="" id="markIncentivesPaidForm" style="display:none;">
    <input type="hidden" name="action" value="mark_event_incentives_paid">
    <input type="hidden" name="event_id" id="mark_paid_event_id">
</form>

<!-- Profit / Feedback Modal -->
<div class="modal fade" id="eventFinanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-chart-line me-2"></i><span id="financeModalTitle">Event Performance</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Profit / Loss</h6>
                                <span id="financeStatusBadge" class="badge bg-secondary">—</span>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="text-muted small">Budget</div>
                                    <div class="fw-semibold" id="financeBudget">—</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="text-muted small">Expenses</div>
                                    <div class="fw-semibold" id="financeExpenses">—</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="text-muted small">Employee Payments</div>
                                    <div class="fw-semibold" id="financePayments">—</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="text-muted small">Other Costs</div>
                                    <div class="fw-semibold" id="financeOtherCosts">—</div>
                                </div>
                                <div class="sidebar-divider my-3"></div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="fw-bold">Net</div>
                                    <div class="fw-bold" id="financeNet">—</div>
                                </div>
                                <div class="text-muted small mt-2">Profit/Loss is based on Budget − Expenses − Employee Payments − Other Costs.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Client Feedback</h6>
                                <span class="badge bg-warning" id="financeRatingBadge">No rating</span>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-2 mb-2" id="financeStars"></div>
                                <div class="text-muted small mb-3" id="financeFeedbackMessage">—</div>
                                <form method="POST" action="" class="row g-2">
                                    <input type="hidden" name="action" value="send_feedback_request">
                                    <input type="hidden" name="event_id" id="feedbackReqEventId">
                                    <div class="col-md-5">
                                        <label class="form-label">Channel</label>
                                        <select class="form-select" name="channel" id="feedbackChannel">
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="sms">SMS</option>
                                            <option value="manual">Manual Link</option>
                                        </select>
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label">Recipient (phone)</label>
                                        <input type="text" class="form-control" name="recipient" id="feedbackRecipient" placeholder="Client phone">
                                    </div>
                                    <div class="col-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send Request</button>
                                    </div>
                                </form>
                                <div class="sidebar-divider my-3"></div>
                                <form method="POST" action="" class="row g-2">
                                    <input type="hidden" name="action" value="record_feedback_admin">
                                    <input type="hidden" name="event_id" id="feedbackAdminEventId">
                                    <div class="col-md-4">
                                        <label class="form-label">Rating</label>
                                        <select class="form-select" name="rating" required>
                                            <option value="">Select</option>
                                            <option value="5">5★ Excellent</option>
                                            <option value="4">4★ Very Good</option>
                                            <option value="3">3★ Good</option>
                                            <option value="2">2★ Average</option>
                                            <option value="1">1★ Poor</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Message</label>
                                        <input type="text" class="form-control" name="message" placeholder="Optional feedback message">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-secondary">Save Feedback</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Costs & Payments</h6>
                                <span class="text-muted small">Updates profit/loss immediately</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <form method="POST" action="" class="row g-2">
                                            <input type="hidden" name="action" value="add_other_cost">
                                            <input type="hidden" name="event_id" id="costEventId">
                                            <div class="col-12">
                                                <label class="form-label">Other Cost</label>
                                                <input type="text" class="form-control" name="label" placeholder="Other cost label" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Amount</label>
                                                <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Notes</label>
                                                <input type="text" class="form-control" name="notes" placeholder="Optional">
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-warning w-100"><i class="fas fa-plus me-2"></i>Add Other Cost</button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-md-6">
                                        <form method="POST" action="" class="row g-2">
                                            <input type="hidden" name="action" value="save_employee_payment">
                                            <input type="hidden" name="event_id" id="payEventId">
                                            <div class="col-12">
                                                <label class="form-label">Employee</label>
                                                <select class="form-select" name="user_id" required>
                                                    <option value="">Select employee</option>
                                                    <?php foreach ($employees as $emp): ?>
                                                        <option value="<?php echo (int) $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?><?php if (!empty($emp['designation'])) echo ' • ' . htmlspecialchars($emp['designation']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Amount</label>
                                                <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Notes</label>
                                                <input type="text" class="form-control" name="notes" placeholder="Optional">
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i>Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div class="text-muted small">Incentives depend on profit and rating (3★+). If loss: no incentive.</div>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-success" id="markPaidBtn"><i class="fas fa-check-circle me-2"></i>Mark Event Incentives Paid</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Event Modal -->
<div class="modal fade" id="addEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Event Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client</label>
                                <select class="form-select" name="client_id">
                                    <option value="">Select Client (Optional)</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>">
                                            <?php echo htmlspecialchars($client['name']); ?>
                                            <?php if ($client['company']) echo '(' . htmlspecialchars($client['company']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Start Date *</label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">End Date *</label>
                                <input type="date" class="form-control" name="end_date" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Budget *</label>
                                <input type="number" class="form-control" name="budget" step="0.01" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Venue</label>
                                <input type="text" class="form-control" name="venue">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="planning">Planning</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Team Members</label>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($employees as $employee): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="team_members[]" value="<?php echo $employee['id']; ?>" id="member_<?php echo $employee['id']; ?>">
                                    <label class="form-check-label" for="member_<?php echo $employee['id']; ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                        <?php if ($employee['designation']) echo '(' . htmlspecialchars($employee['designation']) . ')'; ?>
                                    </label>
                                    <input type="text" class="form-control form-control-sm mt-1" name="role_<?php echo $employee['id']; ?>" placeholder="Role (optional)">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="event_id" id="edit_event_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Event Name *</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Client</label>
                                <select class="form-select" name="client_id" id="edit_client_id">
                                    <option value="">Select Client (Optional)</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>">
                                            <?php echo htmlspecialchars($client['name']); ?>
                                            <?php if ($client['company']) echo '(' . htmlspecialchars($client['company']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Start Date *</label>
                                <input type="date" class="form-control" name="start_date" id="edit_start_date" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">End Date *</label>
                                <input type="date" class="form-control" name="end_date" id="edit_end_date" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Budget *</label>
                                <input type="number" class="form-control" name="budget" step="0.01" id="edit_budget" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Venue</label>
                                <input type="text" class="form-control" name="venue" id="edit_venue">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="planning">Planning</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Team Members</label>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($employees as $employee): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="team_members[]" value="<?php echo $employee['id']; ?>" id="edit_member_<?php echo $employee['id']; ?>">
                                    <label class="form-check-label" for="edit_member_<?php echo $employee['id']; ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                        <?php if ($employee['designation']) echo '(' . htmlspecialchars($employee['designation']) . ')'; ?>
                                    </label>
                                    <input type="text" class="form-control form-control-sm mt-1" name="role_<?php echo $employee['id']; ?>" placeholder="Role (optional)">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Team Modal -->
<div class="modal fade" id="teamModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Event Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="teamContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="event_id" id="delete_event_id">
</form>

<?php
$additional_js = "
<script>
function formatCurrencyClient(amount) {
    try {
        const n = Number(amount || 0);
        return 'Rs. ' + n.toFixed(2).replace(/\\B(?=(\\d{3})+(?!\\d))/g, ',');
    } catch (e) {
        return 'Rs. 0.00';
    }
}

function renderStars(rating) {
    const r = parseInt(rating || '0', 10) || 0;
    const wrap = document.getElementById('financeStars');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (r < 1 || r > 5) return;
    for (let i = 1; i <= 5; i++) {
        const star = document.createElement('i');
        star.className = 'fas fa-star ' + (i <= r ? 'text-warning' : 'text-muted');
        wrap.appendChild(star);
    }
}

function openEventFinanceModal(eventId, eventName, clientName, clientPhone, financials, feedback, extras) {
    const title = document.getElementById('financeModalTitle');
    if (title) title.textContent = (eventName || 'Event') + ' • Performance';

    const statusBadge = document.getElementById('financeStatusBadge');
    const net = Number((financials && financials.net_profit) || 0);
    const isProfit = net >= 0;
    if (statusBadge) {
        statusBadge.className = 'badge bg-' + (isProfit ? 'success' : 'danger');
        statusBadge.textContent = isProfit ? 'Profit' : 'Loss';
    }

    const budgetEl = document.getElementById('financeBudget');
    const expEl = document.getElementById('financeExpenses');
    const payEl = document.getElementById('financePayments');
    const otherEl = document.getElementById('financeOtherCosts');
    const netEl = document.getElementById('financeNet');
    if (budgetEl) budgetEl.textContent = formatCurrencyClient((financials && financials.budget) || 0);
    if (expEl) expEl.textContent = formatCurrencyClient((financials && financials.total_expenses) || 0);
    if (payEl) payEl.textContent = formatCurrencyClient((financials && financials.employee_payments) || 0);
    if (otherEl) otherEl.textContent = formatCurrencyClient((financials && financials.other_costs) || 0);
    if (netEl) {
        netEl.textContent = formatCurrencyClient(Math.abs(net));
        netEl.className = 'fw-bold ' + (isProfit ? 'text-success' : 'text-danger');
    }

    const rating = parseInt((feedback && feedback.rating) || '0', 10) || 0;
    const ratingBadge = document.getElementById('financeRatingBadge');
    if (ratingBadge) {
        ratingBadge.className = 'badge ' + (rating >= 4 ? 'bg-success' : (rating === 3 ? 'bg-primary' : (rating >= 1 ? 'bg-warning' : 'bg-warning')));
        ratingBadge.textContent = (rating >= 1 && rating <= 5) ? (rating + '/5') : 'No rating';
    }
    renderStars(rating);
    const msgEl = document.getElementById('financeFeedbackMessage');
    if (msgEl) msgEl.textContent = (feedback && feedback.message) ? String(feedback.message) : '—';

    const reqEventId = document.getElementById('feedbackReqEventId');
    const adminEventId = document.getElementById('feedbackAdminEventId');
    const costEventId = document.getElementById('costEventId');
    const payEventId = document.getElementById('payEventId');
    if (reqEventId) reqEventId.value = eventId;
    if (adminEventId) adminEventId.value = eventId;
    if (costEventId) costEventId.value = eventId;
    if (payEventId) payEventId.value = eventId;

    const recipientEl = document.getElementById('feedbackRecipient');
    if (recipientEl) recipientEl.value = clientPhone || '';

    const markPaidBtn = document.getElementById('markPaidBtn');
    if (markPaidBtn) {
        const total = Number((extras && extras.incentive_total) || 0);
        markPaidBtn.disabled = !(total > 0);
        markPaidBtn.onclick = function() {
            customConfirm('Mark all earned incentives for this event as paid?', function() {
                document.getElementById('mark_paid_event_id').value = eventId;
                document.getElementById('markIncentivesPaidForm').submit();
            });
        };
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('eventFinanceModal')).show();
}

function editEvent(eventId) {
    window.location.href = 'events.php?edit=' + eventId;
}

function deleteEvent(eventId, name) {
    customConfirm('Are you sure you want to delete event \"' + name + '\"? This action cannot be undone.', function() {
        document.getElementById('delete_event_id').value = eventId;
        document.getElementById('deleteForm').submit();
    });
}

function viewTeam(eventId) {
    // Load event team via AJAX
    fetch('event_team.php?event_id=' + eventId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('teamContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('teamModal')).show();
        })
        .catch(error => {
            console.error('Error loading team:', error);
            alert('Error loading event team');
        });
}



// Load edit data if available
" . ($editEvent ? "
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_event_id').value = '" . $editEvent['id'] . "';
    document.getElementById('edit_name').value = '" . addslashes($editEvent['name']) . "';
    document.getElementById('edit_client_id').value = '" . $editEvent['client_id'] . "';
    document.getElementById('edit_start_date').value = '" . $editEvent['start_date'] . "';
    document.getElementById('edit_end_date').value = '" . $editEvent['end_date'] . "';
    document.getElementById('edit_budget').value = '" . $editEvent['budget'] . "';
    document.getElementById('edit_venue').value = '" . addslashes($editEvent['venue']) . "';
    document.getElementById('edit_status').value = '" . $editEvent['status'] . "';
    document.getElementById('edit_description').value = '" . addslashes($editEvent['description']) . "';
    
    // Check team members
    const teamMembers = [" . implode(',', array_column($editTeam, 'user_id')) . "];
    const teamRoles = " . json_encode(array_column($editTeam, 'role'), JSON_FORCE_OBJECT) . ";
    
    teamMembers.forEach(memberId => {
        const checkbox = document.getElementById('edit_member_' + memberId);
        if (checkbox) {
            checkbox.checked = true;
            const roleInput = document.querySelector('input[name=\"role_' + memberId + '\"]');
            if (roleInput && teamRoles[memberId]) {
                roleInput.value = teamRoles[memberId];
            }
        }
    });
    
    // Show edit modal
    new bootstrap.Modal(document.getElementById('editEventModal')).show();
});
" : "") . "

// Initialize search functionality
searchTable('eventsTable', 'searchInput');
</script>
";
require_once '../includes/footer.php';
?>
