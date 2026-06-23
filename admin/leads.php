<?php
$pageTitle = 'Leads';
require_once '../includes/header.php';
requireAdmin();

ensureClientWorkflowSchema();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            try {
                $stmt = $pdo->prepare("INSERT INTO leads (name, email, phone, company, description, source, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['company']),
                    clean_input($_POST['description']),
                    clean_input($_POST['source']),
                    clean_input($_POST['assigned_to']) ?: null
                ]);
                $leadId = $pdo->lastInsertId();
                $success = 'Lead added successfully!';

                // WhatsApp notification
                if (wa_isTriggerEnabled('lead_welcome')) {
                    $lPhone = trim(clean_input($_POST['phone']));
                    if ($lPhone !== '') {
                        sendWhatsAppMessage($lPhone, 'lead_welcome', [
                            clean_input($_POST['name']),
                            getAppSetting('company_name', 'Our Company')
                        ], ['related_type' => 'lead', 'related_id' => $leadId, 'user_id' => $_SESSION['user_id']]);
                    }
                }
            } catch(PDOException $e) {
                $error = 'Error adding lead: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'edit') {
            try {
                $stmt = $pdo->prepare("UPDATE leads SET name = ?, email = ?, phone = ?, company = ?, description = ?, status = ?, source = ?, assigned_to = ? WHERE id = ?");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['company']),
                    clean_input($_POST['description']),
                    clean_input($_POST['status']),
                    clean_input($_POST['source']),
                    clean_input($_POST['assigned_to']) ?: null,
                    clean_input($_POST['lead_id'])
                ]);
                $success = 'Lead updated successfully!';
            } catch(PDOException $e) {
                $error = 'Error updating lead: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
                $stmt->execute([clean_input($_POST['lead_id'])]);
                $success = 'Lead deleted successfully!';
            } catch(PDOException $e) {
                $error = 'Error deleting lead: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'convert') {
            try {
                // Get lead details
                $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
                $stmt->execute([clean_input($_POST['lead_id'])]);
                $lead = $stmt->fetch();
                
                if ($lead) {
                    $bookingDate = trim((string) clean_input($_POST['booking_date'] ?? ''));
                    if (!isValidISODate($bookingDate)) {
                        throw new RuntimeException('Please select a valid booking date.');
                    }
                    $availability = getClientBookingAvailability($bookingDate);
                    if (($availability['status'] ?? '') === 'Packed') {
                        throw new RuntimeException('Selected date is fully packed.');
                    }

                    // Create client from lead
                    $workflowStatus = 'New Lead';
                    $assignedTo = !empty($lead['assigned_to']) ? (int) $lead['assigned_to'] : null;
                    $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, company, address, linked_lead_id, assigned_to, workflow_status, booking_date, workflow_updated_at)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $lead['name'],
                        $lead['email'],
                        $lead['phone'],
                        $lead['company'],
                        clean_input($_POST['client_address']),
                        $lead['id'],
                        $assignedTo,
                        $workflowStatus,
                        $bookingDate
                    ]);
                    
                    // Update lead status to converted
                    $stmt = $pdo->prepare("UPDATE leads SET status = 'converted' WHERE id = ?");
                    $stmt->execute([$lead['id']]);
                    
                    $success = 'Lead converted to client successfully!';
                }
            } catch(PDOException $e) {
                $error = 'Error converting lead: ' . $e->getMessage();
            } catch(RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filter_status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$filter_employee = isset($_GET['employee']) ? clean_input($_GET['employee']) : '';

// Get leads
try {
    $query = "SELECT l.*, u.name as assigned_name, e.designation 
              FROM leads l 
              LEFT JOIN users u ON l.assigned_to = u.id 
              LEFT JOIN employees e ON e.user_id = u.id 
              WHERE 1=1";
    
    $params = [];
    
    if ($filter_status) {
        $query .= " AND l.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_employee) {
        $query .= " AND l.assigned_to = ?";
        $params[] = $filter_employee;
    }
    
    $query .= " ORDER BY l.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
    
} catch(PDOException $e) {
    error_log('Lead Fetch Error (admin/leads.php): ' . $e->getMessage());
    $error = 'Unable to load leads. Please try again.';
    $leads = [];
}

// Get employees for assignment dropdown
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

// Get lead for editing
$editLead = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([clean_input($_GET['edit'])]);
        $editLead = $stmt->fetch();
    } catch(PDOException $e) {
        error_log('Lead Fetch Error (admin/leads.php edit): ' . $e->getMessage());
        $error = 'Unable to load lead details. Please try again.';
    }
}

// Calculate statistics
$stats = [
    'total_leads' => count($leads),
    'new_leads' => 0,
    'contacted_leads' => 0,
    'converted_leads' => 0,
    'lost_leads' => 0
];

foreach ($leads as $lead) {
    switch ($lead['status']) {
        case 'new':
            $stats['new_leads']++;
            break;
        case 'contacted':
            $stats['contacted_leads']++;
            break;
        case 'converted':
            $stats['converted_leads']++;
            break;
        case 'lost':
            $stats['lost_leads']++;
            break;
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
            <h1 class="h3 page-title">Leads</h1>
            <div class="page-subtitle">Track lead progress from new to conversion</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                <i class="fas fa-plus me-2"></i>Add Lead
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
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
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_leads']; ?></div>
                <div class="stat-label">Total Leads</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-value"><?php echo $stats['new_leads']; ?></div>
                <div class="stat-label">New</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="stat-value"><?php echo $stats['contacted_leads']; ?></div>
                <div class="stat-label">Contacted</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['converted_leads']; ?></div>
                <div class="stat-label">Converted</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['lost_leads']; ?></div>
                <div class="stat-label">Lost</div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card info">
                <div class="stat-icon">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php 
                    $conversion_rate = $stats['total_leads'] > 0 ? round(($stats['converted_leads'] / $stats['total_leads']) * 100, 1) : 0;
                    echo $conversion_rate . '%';
                ?></div>
                <div class="stat-label">Conversion Rate</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <div class="row">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="new" <?php echo $filter_status == 'new' ? 'selected' : ''; ?>>New</option>
                            <option value="contacted" <?php echo $filter_status == 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                            <option value="converted" <?php echo $filter_status == 'converted' ? 'selected' : ''; ?>>Converted</option>
                            <option value="lost" <?php echo $filter_status == 'lost' ? 'selected' : ''; ?>>Lost</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Assigned To</label>
                        <select class="form-select" name="employee">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo $filter_employee == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                    <?php if ($emp['designation']) echo '(' . htmlspecialchars($emp['designation']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="leads.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Leads Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Leads</h5>
        </div>
        <div class="card-body">
            <?php if (empty($leads)): ?>
                <p class="text-muted">No leads found for the selected criteria.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="leadsTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Company</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($lead['name']); ?></strong>
                                            <?php if ($lead['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($lead['description'], 0, 50)) . '...'; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <?php if ($lead['email']): ?>
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($lead['email']); ?>
                                                <br>
                                            <?php endif; ?>
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($lead['phone']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($lead['company'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($lead['source'] ?: 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $lead['status'] == 'new' ? 'primary' : 
                                                 ($lead['status'] == 'contacted' ? 'warning' : 
                                                 ($lead['status'] == 'converted' ? 'success' : 'danger')); 
                                        ?>">
                                            <?php echo ucfirst($lead['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($lead['assigned_name']): ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($lead['assigned_name']); ?></strong>
                                                <?php if ($lead['designation']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($lead['designation']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatDate($lead['created_at']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-primary" onclick="editLead(<?php echo $lead['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($lead['status'] != 'converted'): ?>
                                                <button class="btn btn-sm btn-success" onclick="convertLead(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['name']); ?>')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="deleteLead(<?php echo $lead['id']; ?>, '<?php echo htmlspecialchars($lead['name']); ?>')">
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

<!-- Add Lead Modal -->
<div class="modal fade" id="addLeadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Lead</h5>
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
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Source</label>
                                <select class="form-select" name="source">
                                    <option value="">Select Source</option>
                                    <option value="Website">Website</option>
                                    <option value="Referral">Referral</option>
                                    <option value="Cold Call">Cold Call</option>
                                    <option value="Email">Email</option>
                                    <option value="Social Media">Social Media</option>
                                    <option value="Advertisement">Advertisement</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Assigned To</label>
                                <select class="form-select" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>">
                                            <?php echo htmlspecialchars($emp['name']); ?>
                                            <?php if ($emp['designation']) echo '(' . htmlspecialchars($emp['designation']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Lead Modal -->
<div class="modal fade" id="editLeadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="lead_id" id="edit_lead_id">
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
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Source</label>
                                <select class="form-select" name="source" id="edit_source">
                                    <option value="">Select Source</option>
                                    <option value="Website">Website</option>
                                    <option value="Referral">Referral</option>
                                    <option value="Cold Call">Cold Call</option>
                                    <option value="Email">Email</option>
                                    <option value="Social Media">Social Media</option>
                                    <option value="Advertisement">Advertisement</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="new">New</option>
                                    <option value="contacted">Contacted</option>
                                    <option value="converted">Converted</option>
                                    <option value="lost">Lost</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Assigned To</label>
                                <select class="form-select" name="assigned_to" id="edit_assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>">
                                            <?php echo htmlspecialchars($emp['name']); ?>
                                            <?php if ($emp['designation']) echo '(' . htmlspecialchars($emp['designation']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Convert Lead Modal -->
<div class="modal fade" id="convertLeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Convert Lead to Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="convert">
                <input type="hidden" name="lead_id" id="convert_lead_id">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        This will convert the lead to a client and update the lead status to "converted".
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Booking Date *</label>
                        <input type="date" class="form-control" name="booking_date" id="convert_booking_date" required>
                        <div class="form-text">Max <?php echo (int) getClientBookingDailyLimit(); ?> clients per date.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Client Address</label>
                        <textarea class="form-control" name="client_address" rows="3" placeholder="Enter client address (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Convert to Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="lead_id" id="delete_lead_id">
</form>

<?php
$additional_js = "
<script>
function editLead(leadId) {
    window.location.href = 'leads.php?edit=' + leadId;
}

function deleteLead(leadId, name) {
    customConfirm('Are you sure you want to delete lead \"' + name + '\"? This action cannot be undone.', function() {
        document.getElementById('delete_lead_id').value = leadId;
        document.getElementById('deleteForm').submit();
    });
}

function convertLead(leadId, name) {
    document.getElementById('convert_lead_id').value = leadId;
    new bootstrap.Modal(document.getElementById('convertLeadModal')).show();
}

// Load edit data if available
" . ($editLead ? "
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_lead_id').value = '" . $editLead['id'] . "';
    document.getElementById('edit_name').value = '" . addslashes($editLead['name']) . "';
    document.getElementById('edit_phone').value = '" . addslashes($editLead['phone']) . "';
    document.getElementById('edit_email').value = '" . addslashes($editLead['email']) . "';
    document.getElementById('edit_company').value = '" . addslashes($editLead['company']) . "';
    document.getElementById('edit_source').value = '" . addslashes($editLead['source']) . "';
    document.getElementById('edit_status').value = '" . $editLead['status'] . "';
    document.getElementById('edit_assigned_to').value = '" . $editLead['assigned_to'] . "';
    document.getElementById('edit_description').value = '" . addslashes($editLead['description']) . "';
    
    // Show edit modal
    new bootstrap.Modal(document.getElementById('editLeadModal')).show();
});
" : "") . "

// Initialize search functionality
searchTable('leadsTable', 'searchInput');
</script>
";
require_once '../includes/footer.php';
?>
