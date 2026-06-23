<?php
$pageTitle = 'Profile Requests';
require_once '../includes/header.php';
requireAdmin();

// Ensure profile_requests table exists with required columns
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

$message = '';
$messageType = 'success';

// Handle profile request approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $action = in_array($_POST['action'], ['approve', 'reject']) ? $_POST['action'] : '';
    $admin_comment = clean_input($_POST['admin_comment'] ?? '');

    if ($request_id > 0 && $action !== '') {
        try {
            if ($action === 'approve') {
                // Get request details
                $stmt = $pdo->prepare("SELECT pr.*, e.user_id FROM profile_requests pr
                    JOIN employees e ON pr.employee_id = e.id
                    WHERE pr.id = ? LIMIT 1");
                $stmt->execute([$request_id]);
                $request = $stmt->fetch();

                if ($request) {
                    $update_fields = [];
                    $update_values = [];

                    if (!empty($request['new_name'])) {
                        $update_fields[] = "name = ?";
                        $update_values[] = $request['new_name'];
                    }
                    if (!empty($request['new_phone'])) {
                        $update_fields[] = "phone = ?";
                        $update_values[] = $request['new_phone'];
                    }
                    if (!empty($request['new_address'])) {
                        $update_fields[] = "address = ?";
                        $update_values[] = $request['new_address'];
                    }

                    if (!empty($update_fields) && !empty($request['user_id'])) {
                        $update_values[] = (int) $request['user_id'];
                        $sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($update_values);
                    }
                }
            }

            // Update request status
            $stmt = $pdo->prepare("UPDATE profile_requests SET status = ?, admin_comment = ?, processed_by = ?, processed_at = NOW() WHERE id = ? LIMIT 1");
            $stmt->execute([$action, $admin_comment, (int) $_SESSION['user_id'], $request_id]);
            $message = "Request " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully.";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Get all profile requests with employee info
try {
    $stmt = $pdo->query("SELECT pr.*, u.name as employee_name, u.email as employee_email
        FROM profile_requests pr
        JOIN employees e ON pr.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        ORDER BY (pr.status = 'pending') DESC, pr.created_at DESC");
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    $requests = [];
}

$pendingCount = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'pending'));
?>

<div class="sidebar">
    <div class="p-3">
        <div class="mb-3">
            <div class="sidebar-title text-white">Admin</div>
            <div class="sidebar-subtitle">Manage events &amp; teams</div>
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
            <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>

            <div class="nav-section-title">Operations</div>
            <a class="nav-link" href="events.php"><i class="fas fa-calendar-check"></i> Events</a>
            <a class="nav-link" href="employees.php"><i class="fas fa-users"></i> Employees</a>
            <a class="nav-link" href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>

            <div class="nav-section-title">Sales</div>
            <a class="nav-link" href="leads.php"><i class="fas fa-handshake"></i> Leads</a>
            <a class="nav-link" href="clients.php"><i class="fas fa-building"></i> Clients</a>

            <div class="nav-section-title">Finance</div>
            <a class="nav-link" href="expenses.php"><i class="fas fa-money-bill-wave"></i> Expenses</a>
            <a class="nav-link" href="project_expense_reports.php"><i class="fas fa-file-excel"></i> Project Reports</a>
            <a class="nav-link" href="payroll.php"><i class="fas fa-calculator"></i> Payroll</a>

            <div class="nav-section-title">Analytics</div>
            <a class="nav-link" href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>

            <div class="nav-section-title">Communication</div>
            <a class="nav-link" href="whatsapp.php"><i class="fab fa-whatsapp"></i> WhatsApp</a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php"><i class="fas fa-gear"></i> Settings</a>
            <a class="nav-link active" href="profile_requests.php">
                <i class="fas fa-user-edit"></i> Profile Requests
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-link" href="password_requests.php">
                <i class="fas fa-key"></i> Password Requests
            </a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Profile Requests</h1>
            <div class="page-subtitle">Approve or reject employee profile update requests</div>
        </div>
        <div class="page-actions">
            <?php if ($pendingCount > 0): ?>
                <span class="badge bg-warning text-dark fs-6"><?php echo $pendingCount; ?> Pending</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($requests)): ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($requests as $request): ?>
                <?php
                    $rawStatus = strtolower(trim((string) ($request['status'] ?? 'pending')));
                    $statusLabel = $rawStatus === 'approve' ? 'Approved' : ($rawStatus === 'reject' ? 'Rejected' : 'Pending');
                    $statusBadge = $rawStatus === 'approve' ? 'bg-success' : ($rawStatus === 'reject' ? 'bg-danger' : 'bg-warning text-dark');
                ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars($request['employee_name'] ?? ''); ?>
                                <span class="text-muted small">(<?php echo htmlspecialchars($request['employee_email'] ?? ''); ?>)</span>
                            </div>
                            <div class="text-muted small">
                                Type: <?php echo htmlspecialchars(ucfirst((string) ($request['request_type'] ?? 'all'))); ?>
                                &bull; Submitted: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($request['created_at'] ?? 'now')))); ?>
                            </div>
                        </div>
                        <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <div class="fw-semibold mb-2">Requested Changes</div>

                                <?php if (!empty($request['new_name']) && ($request['new_name'] !== ($request['current_name'] ?? ''))): ?>
                                    <div class="panel-lite rounded p-3 mb-2">
                                        <div class="text-muted small">Name</div>
                                        <div><?php echo htmlspecialchars($request['current_name'] ?? 'N/A'); ?> &rarr; <strong><?php echo htmlspecialchars($request['new_name']); ?></strong></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($request['new_phone']) && ($request['new_phone'] !== ($request['current_phone'] ?? ''))): ?>
                                    <div class="panel-lite rounded p-3 mb-2">
                                        <div class="text-muted small">Phone</div>
                                        <div><?php echo htmlspecialchars(($request['current_phone'] ?? '') ?: 'Not set'); ?> &rarr; <strong><?php echo htmlspecialchars($request['new_phone']); ?></strong></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($request['new_address']) && ($request['new_address'] !== ($request['current_address'] ?? ''))): ?>
                                    <div class="panel-lite rounded p-3 mb-2">
                                        <div class="text-muted small">Address</div>
                                        <div><?php echo htmlspecialchars(($request['current_address'] ?? '') ?: 'Not set'); ?> &rarr; <strong><?php echo htmlspecialchars($request['new_address']); ?></strong></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($request['reason'])): ?>
                                    <div class="panel-lite rounded p-3">
                                        <div class="text-muted small">Reason from employee</div>
                                        <div><?php echo htmlspecialchars($request['reason']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-lg-5">
                                <?php if ($rawStatus === 'pending'): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                        <div class="mb-2">
                                            <label class="form-label">Admin Comment (optional)</label>
                                            <textarea class="form-control" name="admin_comment" rows="2" placeholder="Add a note for the employee"></textarea>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="action" value="approve" class="btn btn-success">
                                                <i class="fas fa-check me-2"></i>Approve
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                                <i class="fas fa-times me-2"></i>Reject
                                            </button>
                                        </div>
                                    </form>
                                <?php elseif (!empty($request['admin_comment'])): ?>
                                    <div class="panel-lite rounded p-3">
                                        <div class="text-muted small mb-1">Admin comment</div>
                                        <div><?php echo htmlspecialchars($request['admin_comment']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted">No admin comment.</div>
                                <?php endif; ?>

                                <?php if (!empty($request['processed_at'])): ?>
                                    <div class="text-muted small mt-2">
                                        Processed: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) $request['processed_at']))); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
            <div class="empty-title">No profile requests</div>
            <div class="empty-subtitle">When employees request profile updates, they'll appear here for review.</div>
            <div class="empty-actions">
                <a class="btn btn-secondary" href="employees.php"><i class="fas fa-users me-2"></i>Employees</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
