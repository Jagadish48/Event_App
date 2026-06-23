<?php
$pageTitle = 'Employee Management';
require_once '../includes/header.php';
requireAdmin();

ensureTaskWorkflowSchema();
ensureClientWorkflowSchema();
ensureEmployeeIdentitySchema();

$success = '';
$error = '';

// Get success/error from query params
if (isset($_GET['success'])) {
    $success = clean_input($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = clean_input($_GET['error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'add') {
            try {
                $aadhaarRaw = preg_replace('/\D+/', '', (string) ($_POST['aadhaar_number'] ?? ''));
                $panRaw = strtoupper(trim((string) ($_POST['pan_number'] ?? '')));

                if ($aadhaarRaw !== '' && !preg_match('/^\d{12}$/', $aadhaarRaw)) {
                    throw new RuntimeException('Aadhaar number must be exactly 12 digits.');
                }
                if ($panRaw !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $panRaw)) {
                    throw new RuntimeException('Invalid PAN number format.');
                }

                $aadhaarHash = $aadhaarRaw !== '' ? hashSensitiveValue($aadhaarRaw) : '';
                $panHash = $panRaw !== '' ? hashSensitiveValue($panRaw) : '';

                if ($aadhaarHash !== '') {
                    $stmt = $pdo->prepare("SELECT user_id FROM employees WHERE aadhaar_hash = ? LIMIT 1");
                    $stmt->execute([$aadhaarHash]);
                    if ($stmt->fetch()) {
                        throw new RuntimeException('This Aadhaar number is already used by another employee.');
                    }
                }

                if ($panHash !== '') {
                    $stmt = $pdo->prepare("SELECT user_id FROM employees WHERE pan_hash = ? LIMIT 1");
                    $stmt->execute([$panHash]);
                    if ($stmt->fetch()) {
                        throw new RuntimeException('This PAN number is already used by another employee.');
                    }
                }

                $aadhaarFile = null;
                if (isset($_FILES['aadhaar_file']) && ($_FILES['aadhaar_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $aadhaarFile = uploadIdentityFile($_FILES['aadhaar_file'], 'identity');
                }

                $panFile = null;
                if (isset($_FILES['pan_file']) && ($_FILES['pan_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $panFile = uploadIdentityFile($_FILES['pan_file'], 'identity');
                }

                $aadhaarEnc = $aadhaarRaw !== '' ? encryptSensitiveValue($aadhaarRaw) : null;
                $panEnc = $panRaw !== '' ? encryptSensitiveValue($panRaw) : null;
                $aadhaarLast4 = $aadhaarRaw !== '' ? substr($aadhaarRaw, -4) : null;
                $verificationStatus = 'pending';

                // Add new user
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'employee')");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    password_hash($_POST['password'], PASSWORD_DEFAULT)
                ]);
                $userId = $pdo->lastInsertId();
                
                // Add employee details
                $stmt = $pdo->prepare("INSERT INTO employees (user_id, designation, salary, phone, address, join_date, aadhaar_number, aadhaar_last4, aadhaar_hash, pan_number, pan_hash, aadhaar_file, pan_file, verification_status, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $userId,
                    clean_input($_POST['designation']),
                    clean_input($_POST['salary']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['address']),
                    clean_input($_POST['join_date']),
                    $aadhaarEnc,
                    $aadhaarLast4,
                    $aadhaarHash !== '' ? $aadhaarHash : null,
                    $panEnc,
                    $panHash !== '' ? $panHash : null,
                    $aadhaarFile,
                    $panFile,
                    $verificationStatus
                ]);
                
                header('Location: employees.php?success=' . urlencode('Employee added successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error adding employee: ' . $e->getMessage();
            } catch(Exception $e) {
                $error = $e->getMessage();
            }
        } elseif ($_POST['action'] == 'edit') {
            try {
                // Update user
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    clean_input($_POST['user_id'])
                ]);
                
                // Update employee details
                $stmt = $pdo->prepare("UPDATE employees SET designation = ?, salary = ?, phone = ?, address = ?, status = ? WHERE user_id = ?");
                $stmt->execute([
                    clean_input($_POST['designation']),
                    clean_input($_POST['salary']),
                    clean_input($_POST['phone']),
                    clean_input($_POST['address']),
                    clean_input($_POST['status']),
                    clean_input($_POST['user_id'])
                ]);
                
                // Update password if provided
                if (!empty($_POST['password'])) {
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([
                        password_hash($_POST['password'], PASSWORD_DEFAULT),
                        clean_input($_POST['user_id'])
                    ]);
                }
                
                header('Location: employees.php?success=' . urlencode('Employee updated successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error updating employee: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([clean_input($_POST['user_id'])]);
                header('Location: employees.php?success=' . urlencode('Employee deleted successfully!'));
                exit;
            } catch(PDOException $e) {
                $error = 'Error deleting employee: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'assign_task') {
            try {
                $employeeUserId = (int) clean_input($_POST['employee_user_id']);
                $title = clean_input($_POST['title']);
                $description = clean_input($_POST['description'] ?? '');
                $dueAt = clean_input($_POST['due_at'] ?? '');
                $clientId = clean_input($_POST['client_id'] ?? '');
                $clientId = $clientId !== '' ? (int) $clientId : null;

                if ($employeeUserId < 1 || $title === '') {
                    $error = 'Task title is required';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee' LIMIT 1");
                    $stmt->execute([$employeeUserId]);
                    if (!$stmt->fetch()) {
                        $error = 'Employee not found';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO employee_tasks (user_id, title, description, due_at, status, assigned_by, client_id) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
                        $stmt->execute([
                            $employeeUserId,
                            $title,
                            $description !== '' ? $description : null,
                            $dueAt !== '' ? $dueAt : null,
                            (int) $_SESSION['user_id'],
                            $clientId
                        ]);
                        header('Location: employees.php?success=' . urlencode('Task assigned successfully!'));
                        exit;
                    }
                }
            } catch(PDOException $e) {
                $error = 'Error assigning task: ' . $e->getMessage();
            }
        }
    }
}

// Get all employees
try {
    $stmt = $pdo->query("SELECT e.*, u.name, u.email, u.profile_image, u.created_at as user_created_at,
                        (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) as assigned_clients,
                        (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status IN ('pending','in_progress')) as active_tasks,
                        (SELECT COUNT(*) FROM employee_tasks t WHERE t.user_id = u.id AND t.status = 'completed') as completed_tasks
                        FROM employees e 
                        JOIN users u ON e.user_id = u.id 
                        ORDER BY e.created_at DESC");
    $employees = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = 'Error fetching employees: ' . $e->getMessage();
    $employees = [];
}

try {
    $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY created_at DESC");
    $allClients = $stmt->fetchAll();
} catch(PDOException $e) {
    $allClients = [];
}

// Get employee for editing
$editEmployee = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT e.*, u.name, u.email 
                              FROM employees e 
                              JOIN users u ON e.user_id = u.id 
                              WHERE e.user_id = ?");
        $stmt->execute([clean_input($_GET['edit'])]);
        $editEmployee = $stmt->fetch();
    } catch(PDOException $e) {
        $error = 'Error fetching employee: ' . $e->getMessage();
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
            <h1 class="h3 page-title">Employees</h1>
            <div class="page-subtitle">Manage employee profiles, salary, and roles</div>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <i class="fas fa-plus me-2"></i>Add Employee
            </button>
        </div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="attendance.php"><i class="fas fa-clock me-2"></i>Attendance</a>
        <a class="btn btn-secondary" href="payroll.php"><i class="fas fa-calculator me-2"></i>Payroll</a>
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

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Employees</h5>
        </div>
        <div class="card-body">
            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-users"></i></div>
                    <div class="empty-title">No employees yet</div>
                    <div class="empty-subtitle">Add your first employee to start managing attendance, payroll, and event teams.</div>
                    <div class="empty-actions">
                        <a class="btn btn-primary" href="employees.php?open=add"><i class="fas fa-user-plus me-2"></i>Add Employee</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-tools mb-3">
                    <div class="position-relative">
                        <i class="fas fa-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                        <input type="search" class="form-control ps-5" id="employeeSearchInput" placeholder="Search employees..." autocomplete="off">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover" id="employeesTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Designation</th>
                                <th>Clients</th>
                                <th>Active Tasks</th>
                                <th>Completed Tasks</th>
                                <th>Salary</th>
                                <th>Phone</th>
                                <th>Aadhaar</th>
                                <th>PAN</th>
                                <th>Status</th>
                                <th>Join Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                                <?php
                                    $profileInfo = resolveUploadPathInfo((string) ($employee['profile_image'] ?? ''));
                                    $profileImageUrl = ($profileInfo['exists'] ?? false) ? (string) ($profileInfo['url'] ?? '') : '';
                                    $empInitials = '';
                                    $nameParts = preg_split('/\s+/', trim((string) ($employee['name'] ?? '')));
                                    if ($nameParts) {
                                        $empInitials = strtoupper(substr($nameParts[0] ?? '', 0, 1) . substr($nameParts[count($nameParts) - 1] ?? '', 0, 1));
                                    }
                                    $searchText = strtolower(trim(
                                        (string) ($employee['name'] ?? '') . ' ' .
                                        (string) ($employee['email'] ?? '') . ' ' .
                                        (string) ($employee['designation'] ?? '') . ' ' .
                                        (string) ($employee['phone'] ?? '')
                                    ));
                                ?>
                                <tr data-employee-search="<?php echo htmlspecialchars($searchText); ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($profileImageUrl !== ''): ?>
                                                <img class="app-avatar-img" style="width:30px;height:30px;" src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="<?php echo htmlspecialchars((string) ($employee['name'] ?? '')); ?>">
                                            <?php else: ?>
                                                <span class="app-avatar" style="width:30px;height:30px;font-size:.75rem;"><?php echo htmlspecialchars($empInitials ?: 'U'); ?></span>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($employee['name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['email']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['designation']); ?></td>
                                    <td><span class="badge bg-info"><?php echo (int) ($employee['assigned_clients'] ?? 0); ?></span></td>
                                    <td><span class="badge bg-warning"><?php echo (int) ($employee['active_tasks'] ?? 0); ?></span></td>
                                    <td><span class="badge bg-success"><?php echo (int) ($employee['completed_tasks'] ?? 0); ?></span></td>
                                    <td><?php echo formatCurrency($employee['salary']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['phone']); ?></td>
                                    <td><?php echo htmlspecialchars(maskAadhaarLast4($employee['aadhaar_last4'] ?? '')); ?></td>
                                    <td><?php echo !empty($employee['pan_hash']) ? '**********' : '-'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $employee['status'] == 'active' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($employee['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($employee['join_date']); ?></td>
                                    <td>
                                        <div class="table-action-group">
                                            <button class="btn btn-sm btn-success" onclick="openAssignTaskModal(<?php echo (int) $employee['user_id']; ?>, '<?php echo htmlspecialchars($employee['name']); ?>')">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button class="btn btn-sm btn-primary" onclick="editEmployee(<?php echo $employee['user_id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteEmployee(<?php echo $employee['user_id']; ?>, '<?php echo htmlspecialchars($employee['name']); ?>')">
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

<!-- Assign Task Modal -->
<div class="modal fade" id="assignTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignTaskTitle">Assign Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="assign_task">
                <input type="hidden" name="employee_user_id" id="assign_task_employee_user_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Task Title *</label>
                        <input type="text" class="form-control" name="title" id="assign_task_title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="assign_task_description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Due Date & Time</label>
                                <input type="datetime-local" class="form-control" name="due_at" id="assign_task_due_at">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Link Client (optional)</label>
                                <select class="form-select" name="client_id" id="assign_task_client_id">
                                    <option value="">No client</option>
                                    <?php foreach ($allClients as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Task</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
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
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <!-- Fixed: Added maxlength="50" to allow full password input -->
                                <input type="password" class="form-control" name="password" maxlength="50" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Designation *</label>
                                <input type="text" class="form-control" name="designation" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Salary *</label>
                                <input type="number" class="form-control" name="salary" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Join Date *</label>
                                <input type="date" class="form-control" name="join_date" required>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="3"></textarea>
                    </div>

                    <div class="identity-section mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-bold">Identity Verification</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Aadhaar Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                    <input type="text" inputmode="numeric" class="form-control" name="aadhaar_number" id="aadhaar_number" maxlength="12" placeholder="Enter Aadhaar Number" autocomplete="off">
                                    <div class="invalid-feedback">Aadhaar number must be exactly 12 digits.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">PAN Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-address-card"></i></span>
                                    <input type="text" class="form-control" name="pan_number" id="pan_number" placeholder="Enter PAN Number" autocomplete="off">
                                    <div class="invalid-feedback">PAN format must be like ABCDE1234F.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Upload Aadhaar Card</label>
                                <input type="file" class="form-control" name="aadhaar_file" id="aadhaar_file" accept=".jpg,.jpeg,.png,.pdf">
                                <div class="form-text text-muted" id="aadhaar_file_name">Accepted: JPG, PNG, PDF • Max 2MB</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Upload PAN Card</label>
                                <input type="file" class="form-control" name="pan_file" id="pan_file" accept=".jpg,.jpeg,.png,.pdf">
                                <div class="form-text text-muted" id="pan_file_name">Accepted: JPG, PNG, PDF • Max 2MB</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
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
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">New Password (leave blank to keep current)</label>
                                <!-- Fixed: Added maxlength="50" to allow full password input -->
                                <input type="password" class="form-control" name="password" maxlength="50">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Designation *</label>
                                <input type="text" class="form-control" name="designation" id="edit_designation" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Salary *</label>
                                <input type="number" class="form-control" name="salary" step="0.01" id="edit_salary" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" class="form-control" name="phone" id="edit_phone">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form method="POST" action="" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<?php
$additional_js = "
<script>
function editEmployee(userId) {
    // Fetch employee data via AJAX or use existing data
    // For simplicity, we'll reload the page with edit parameter
    window.location.href = 'employees.php?edit=' + userId;
}

function deleteEmployee(userId, name) {
    customConfirm('Are you sure you want to delete employee \"' + name + '\"? This action cannot be undone.', function() {
        document.getElementById('delete_user_id').value = userId;
        document.getElementById('deleteForm').submit();
    });
}

function openAssignTaskModal(userId, name) {
    document.getElementById('assignTaskTitle').textContent = 'Assign Task • ' + name;
    document.getElementById('assign_task_employee_user_id').value = userId;
    document.getElementById('assign_task_title').value = '';
    document.getElementById('assign_task_description').value = '';
    document.getElementById('assign_task_due_at').value = '';
    document.getElementById('assign_task_client_id').value = '';
    new bootstrap.Modal(document.getElementById('assignTaskModal')).show();
}



document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('employeeSearchInput');
    const table = document.getElementById('employeesTable');
    if (!input || !table) return;

    const rows = Array.from(table.querySelectorAll('tbody tr'));
    input.addEventListener('input', function() {
        const q = (input.value || '').trim().toLowerCase();
        rows.forEach(function(row) {
            const hay = (row.dataset.employeeSearch || '').toLowerCase();
            row.style.display = q === '' || hay.includes(q) ? '' : 'none';
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const aadhaarEl = document.getElementById('aadhaar_number');
    const panEl = document.getElementById('pan_number');
    const aadhaarFileEl = document.getElementById('aadhaar_file');
    const panFileEl = document.getElementById('pan_file');
    const aadhaarName = document.getElementById('aadhaar_file_name');
    const panName = document.getElementById('pan_file_name');

    if (aadhaarEl) {
        aadhaarEl.addEventListener('input', function() {
            const digits = (aadhaarEl.value || '').replace(/\D+/g, '').slice(0, 12);
            aadhaarEl.value = digits;
            if (digits.length === 0) {
                aadhaarEl.classList.remove('is-invalid');
            } else {
                aadhaarEl.classList.toggle('is-invalid', digits.length !== 12);
            }
        });
    }

    if (panEl) {
        panEl.addEventListener('input', function() {
            panEl.value = String(panEl.value || '').toUpperCase();
            const v = (panEl.value || '').trim();
            if (v.length === 0) {
                panEl.classList.remove('is-invalid');
                return;
            }
            panEl.classList.toggle('is-invalid', !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(v));
        });
    }

    function bindFileName(inputEl, targetEl) {
        if (!inputEl || !targetEl) return;
        inputEl.addEventListener('change', function() {
            const f = inputEl.files && inputEl.files[0] ? inputEl.files[0] : null;
            targetEl.textContent = f ? ('Selected: ' + f.name) : targetEl.dataset.placeholder || targetEl.textContent;
        });
    }

    if (aadhaarName) aadhaarName.dataset.placeholder = aadhaarName.textContent;
    if (panName) panName.dataset.placeholder = panName.textContent;
    bindFileName(aadhaarFileEl, aadhaarName);
    bindFileName(panFileEl, panName);
});

// Load edit data if available
" . ($editEmployee ? "
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_user_id').value = '" . $editEmployee['user_id'] . "';
    document.getElementById('edit_name').value = '" . addslashes($editEmployee['name']) . "';
    document.getElementById('edit_email').value = '" . addslashes($editEmployee['email']) . "';
    document.getElementById('edit_designation').value = '" . addslashes($editEmployee['designation']) . "';
    document.getElementById('edit_salary').value = '" . $editEmployee['salary'] . "';
    document.getElementById('edit_phone').value = '" . addslashes($editEmployee['phone']) . "';
    document.getElementById('edit_status').value = '" . $editEmployee['status'] . "';
    document.getElementById('edit_address').value = '" . addslashes($editEmployee['address']) . "';
    
    // Show edit modal
    new bootstrap.Modal(document.getElementById('editEmployeeModal')).show();
});
" : "") . "
</script>
";
require_once '../includes/footer.php';
?>
