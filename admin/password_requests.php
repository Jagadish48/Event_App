<?php
$pageTitle = 'Password Reset Requests';
require_once '../includes/header.php';
requireAdmin();

// Ensure password_reset_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        new_password_hash VARCHAR(255) NULL,
        admin_note TEXT NULL,
        processed_by INT NULL,
        processed_at TIMESTAMP NULL,
        reset_token VARCHAR(100) NULL,
        token_expires_at DATETIME NULL,
        INDEX idx_prr_user (user_id),
        INDEX idx_prr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$message = '';
$messageType = 'success';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin_note = clean_input($_POST['admin_note'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    if ($request_id > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            // Get request info
            $stmt = $pdo->prepare("SELECT prr.*, u.name, u.email FROM password_reset_requests prr JOIN users u ON prr.user_id = u.id WHERE prr.id = ? LIMIT 1");
            $stmt->execute([$request_id]);
            $req = $stmt->fetch();

            if (!$req) {
                $message = 'Request not found.';
                $messageType = 'danger';
            } elseif ($req['status'] !== 'pending') {
                $message = 'This request has already been processed.';
                $messageType = 'warning';
            } else {
                if ($action === 'approve') {
                    if (empty($new_password) || strlen($new_password) < 6) {
                        $message = 'Please provide a new password (minimum 6 characters).';
                        $messageType = 'danger';
                    } else {
                        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        // Update user password
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                        $stmt->execute([$hashed, (int) $req['user_id']]);
                        // Update request status
                        $stmt = $pdo->prepare("UPDATE password_reset_requests SET status = 'approved', new_password_hash = ?, admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ? LIMIT 1");
                        $stmt->execute([$hashed, $admin_note, (int) $_SESSION['user_id'], $request_id]);
                        $message = "Password reset approved and updated for " . htmlspecialchars($req['name']) . ".";
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE password_reset_requests SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ? LIMIT 1");
                    $stmt->execute([$admin_note, (int) $_SESSION['user_id'], $request_id]);
                    $message = "Password reset request rejected for " . htmlspecialchars($req['name']) . ".";
                }
            }
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Fetch all requests
try {
    $stmt = $pdo->query("SELECT prr.*, u.name, u.email, u.role
        FROM password_reset_requests prr
        JOIN users u ON prr.user_id = u.id
        ORDER BY (prr.status = 'pending') DESC, prr.requested_at DESC
        LIMIT 100");
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
            <a class="nav-link" href="profile_requests.php"><i class="fas fa-user-edit"></i> Profile Requests</a>
            <a class="nav-link active" href="password_requests.php">
                <i class="fas fa-key"></i> Password Requests
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Password Reset Requests</h1>
            <div class="page-subtitle">Review and approve employee password reset requests</div>
        </div>
        <div class="page-actions">
            <?php if ($pendingCount > 0): ?>
                <span class="badge bg-warning text-dark fs-6"><?php echo $pendingCount; ?> Pending</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?> me-2"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($requests)): ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($requests as $req): ?>
                <?php
                    $st = $req['status'] ?? 'pending';
                    $statusLabel = ucfirst($st);
                    $statusBadge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold">
                                <?php echo htmlspecialchars($req['name'] ?? ''); ?>
                                <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars(ucfirst($req['role'] ?? 'employee')); ?></span>
                            </div>
                            <div class="text-muted small"><?php echo htmlspecialchars($req['email'] ?? ''); ?></div>
                            <div class="text-muted small">Requested: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($req['requested_at'] ?? 'now')))); ?></div>
                        </div>
                        <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($st === 'pending'): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="request_id" value="<?php echo (int) ($req['id'] ?? 0); ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Set New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" name="new_password" placeholder="Min 6 characters" minlength="6">
                                        <div class="form-text">Required to approve the request.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Admin Note (optional)</label>
                                        <input type="text" class="form-control" name="admin_note" placeholder="Note for employee">
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button type="submit" name="action" value="approve" class="btn btn-success">
                                        <i class="fas fa-check me-2"></i>Approve &amp; Set Password
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-danger"
                                        onclick="return confirm('Reject this password reset request?')">
                                        <i class="fas fa-times me-2"></i>Reject
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <?php if (!empty($req['admin_note'])): ?>
                                <div class="text-muted small">Admin note: <?php echo htmlspecialchars($req['admin_note']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($req['processed_at'])): ?>
                                <div class="text-muted small">Processed: <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) $req['processed_at']))); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-key"></i></div>
            <div class="empty-title">No password reset requests</div>
            <div class="empty-subtitle">When employees request a password reset, they'll appear here for your review.</div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
