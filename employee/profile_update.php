<?php
$pageTitle = 'Profile Update Request';
require_once '../includes/header.php';
requireEmployee();

$employee_id = get_employee_id();
$success_message = '';
$error_message = '';

// Ensure profile_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        current_name VARCHAR(100) NULL,
        new_name VARCHAR(100) NULL,
        current_phone VARCHAR(20) NULL,
        new_phone VARCHAR(20) NULL,
        current_address TEXT NULL,
        new_address TEXT NULL,
        request_type VARCHAR(20) NOT NULL DEFAULT 'all',
        reason TEXT NULL,
        status ENUM('pending','approve','reject') NOT NULL DEFAULT 'pending',
        admin_comment TEXT NULL,
        processed_by INT NULL,
        processed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

if (!$employee_id) {
    $error_message = 'Employee profile not found. Please contact admin.';
} else {
    // Get current employee info
    try {
        $stmt = $pdo->prepare("SELECT u.name, u.email, u.phone, u.address, e.id as employee_id
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE e.id = ? LIMIT 1");
        $stmt->execute([$employee_id]);
        $current_info = $stmt->fetch();
    } catch (PDOException $e) {
        $current_info = null;
    }

    // Handle profile update request submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
        $new_name    = trim(clean_input($_POST['name'] ?? ''));
        $new_phone   = trim(clean_input($_POST['phone'] ?? ''));
        $new_address = trim(clean_input($_POST['address'] ?? ''));
        $reason      = trim(clean_input($_POST['reason'] ?? ''));

        $hasChange =
            ($new_name !== '' && $new_name !== ($current_info['name'] ?? '')) ||
            ($new_phone !== '' && $new_phone !== ($current_info['phone'] ?? '')) ||
            ($new_address !== '' && $new_address !== ($current_info['address'] ?? ''));

        if (!$hasChange) {
            $error_message = 'No changes detected. Please modify at least one field.';
        } elseif (empty($reason)) {
            $error_message = 'Please provide a reason for the update request.';
        } else {
            $updates = [];
            if ($new_name !== '' && $new_name !== ($current_info['name'] ?? '')) $updates[] = 'name';
            if ($new_phone !== '' && $new_phone !== ($current_info['phone'] ?? '')) $updates[] = 'phone';
            if ($new_address !== '' && $new_address !== ($current_info['address'] ?? '')) $updates[] = 'address';
            $request_type = count($updates) === 1 ? $updates[0] : 'all';

            try {
                // Check for existing pending request
                $stmt = $pdo->prepare("SELECT id FROM profile_requests WHERE employee_id = ? AND status = 'pending' AND request_type = ? LIMIT 1");
                $stmt->execute([$employee_id, $request_type]);
                if ($stmt->fetch()) {
                    $error_message = 'You already have a pending request for these fields. Please wait for admin approval.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO profile_requests
                        (employee_id, current_name, new_name, current_phone, new_phone, current_address, new_address, request_type, reason, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                    $stmt->execute([
                        $employee_id,
                        $current_info['name'] ?? '',
                        $new_name ?: ($current_info['name'] ?? ''),
                        $current_info['phone'] ?? '',
                        $new_phone ?: ($current_info['phone'] ?? ''),
                        $current_info['address'] ?? '',
                        $new_address ?: ($current_info['address'] ?? ''),
                        $request_type,
                        $reason
                    ]);
                    $success_message = 'Profile update request submitted successfully. Admin will review your request.';
                    // Refresh current info
                    $stmt = $pdo->prepare("SELECT u.name, u.email, u.phone, u.address, e.id as employee_id
                        FROM employees e JOIN users u ON e.user_id = u.id WHERE e.id = ? LIMIT 1");
                    $stmt->execute([$employee_id]);
                    $current_info = $stmt->fetch();
                }
            } catch (PDOException $e) {
                $error_message = 'Failed to submit request. Please try again. (' . $e->getMessage() . ')';
            }
        }
    }

    // Get existing requests
    try {
        $stmt = $pdo->prepare("SELECT * FROM profile_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$employee_id]);
        $requests = $stmt->fetchAll();
    } catch (PDOException $e) {
        $requests = [];
    }
}
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Employee</div>
            <div class="sidebar-subtitle">Your work &amp; schedule</div>
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
            <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="tasks.php"><i class="fas fa-list-check"></i> Tasks</a>

            <div class="nav-section-title">Work</div>
            <a class="nav-link" href="clients.php"><i class="fas fa-building"></i> Clients</a>
            <a class="nav-link" href="leads.php"><i class="fas fa-handshake"></i> Leads</a>
            <a class="nav-link" href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php"><i class="fas fa-money-bill-wave"></i> Expenses</a>
            <a class="nav-link" href="project_expense_report.php"><i class="fas fa-file-excel"></i> Project Expense Report</a>
            <a class="nav-link" href="payroll.php"><i class="fas fa-calculator"></i> Payroll</a>
            <a class="nav-link" href="salary.php"><i class="fas fa-wallet"></i> Salary</a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php"><i class="fas fa-gear"></i> Settings</a>
            <a class="nav-link active" href="profile_update.php"><i class="fas fa-user-edit"></i> Profile Requests</a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Profile Update Request</h1>
            <div class="page-subtitle">Request updates to your profile information (admin approval required)</div>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($current_info): ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Current Profile</h5></div>
                <div class="card-body">
                    <div class="panel-lite rounded p-3 mb-2">
                        <div class="text-muted small">Name</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($current_info['name'] ?? ''); ?></div>
                    </div>
                    <div class="panel-lite rounded p-3 mb-2">
                        <div class="text-muted small">Email</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($current_info['email'] ?? ''); ?></div>
                    </div>
                    <div class="panel-lite rounded p-3 mb-2">
                        <div class="text-muted small">Phone</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(($current_info['phone'] ?? '') ?: 'Not set'); ?></div>
                    </div>
                    <div class="panel-lite rounded p-3">
                        <div class="text-muted small">Address</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars(($current_info['address'] ?? '') ?: 'Not set'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><h5 class="mb-0">Request an Update</h5></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="submit_request">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">New Name</label>
                                <input type="text" class="form-control" id="name" name="name"
                                    value="<?php echo htmlspecialchars($current_info['name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">New Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                    value="<?php echo htmlspecialchars($current_info['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">New Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($current_info['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12">
                                <label for="reason" class="form-label">Reason *</label>
                                <textarea class="form-control" id="reason" name="reason" rows="2" required
                                    placeholder="Explain why you need to update your profile"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Previous Requests</h5></div>
                <div class="card-body">
                    <?php if (!empty($requests)): ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($requests as $request): ?>
                                <?php
                                    $rawStatus = strtolower(trim((string) ($request['status'] ?? 'pending')));
                                    $statusLabel = $rawStatus === 'approve' ? 'Approved' : ($rawStatus === 'reject' ? 'Rejected' : 'Pending');
                                    $statusBadge = $rawStatus === 'approve' ? 'bg-success' : ($rawStatus === 'reject' ? 'bg-danger' : 'bg-warning text-dark');
                                ?>
                                <div class="panel-lite rounded p-3">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-semibold">Type: <?php echo htmlspecialchars(ucfirst((string) ($request['request_type'] ?? 'all'))); ?></div>
                                            <div class="text-muted small">Submitted: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($request['created_at'] ?? 'now')))); ?></div>
                                        </div>
                                        <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
                                    </div>
                                    <?php if (!empty($request['reason'])): ?>
                                        <div class="mt-2 text-muted small">Reason</div>
                                        <div><?php echo htmlspecialchars((string) ($request['reason'] ?? '')); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($request['admin_comment'])): ?>
                                        <div class="mt-2">
                                            <div class="text-muted small">Admin comment</div>
                                            <div><?php echo htmlspecialchars($request['admin_comment']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div class="empty-title">No requests yet</div>
                            <div class="empty-subtitle">Your submitted profile update requests will appear here.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
