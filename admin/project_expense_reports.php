<?php
$pageTitle = 'Project Expense Reports';
require_once '../includes/header.php';
requireAdmin();
ensureProjectExpenseReportsSchema();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $adminComment = trim((string) ($_POST['admin_comment'] ?? ''));

    if ($reportId > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE project_expense_reports
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_comment = ?
                WHERE id = ?");
            $stmt->execute([$newStatus, (int) $_SESSION['user_id'], ($adminComment !== '' ? $adminComment : null), $reportId]);
            $success = $newStatus === 'approved' ? 'Report approved successfully.' : 'Report rejected successfully.';
        } catch (PDOException $e) {
            $error = 'Failed to update report status.';
        }
    }
}

try {
    $stmt = $pdo->query("SELECT pr.*, u.name as employee_name, c.name as client_name, ev.name as event_name
        FROM project_expense_reports pr
        JOIN (
            SELECT user_id, event_id, MAX(id) as id
            FROM project_expense_reports
            GROUP BY user_id, event_id
        ) latest ON latest.id = pr.id
        JOIN users u ON u.id = pr.user_id
        LEFT JOIN clients c ON c.id = pr.client_id
        LEFT JOIN events ev ON ev.id = pr.event_id
        ORDER BY FIELD(pr.status,'pending','approved','rejected'), pr.submitted_at DESC, pr.id DESC");
    $reports = $stmt->fetchAll();
} catch (PDOException $e) {
    $reports = [];
}

$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'approved_total' => 0
];

foreach ($reports as $r) {
    $st = (string) ($r['status'] ?? 'pending');
    if ($st === 'approved') {
        $stats['approved']++;
        $stats['approved_total'] += (float) ($r['total_amount'] ?? 0);
    } elseif ($st === 'rejected') {
        $stats['rejected']++;
    } else {
        $stats['pending']++;
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
            <a class="nav-link" href="whatsapp.php">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>

            <div class="nav-section-title">Account</div>
            <a class="nav-link" href="profile.php"><i class="fas fa-gear"></i> Settings</a>
        </nav>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Project Expense Reports</h1>
            <div class="page-subtitle">Review and approve uploaded final expense sheets</div>
        </div>
        <div class="page-actions"></div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card warning h-100">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card success h-100">
                <div class="stat-icon"><i class="fas fa-circle-check"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['approved'] ?? 0); ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card danger h-100">
                <div class="stat-icon"><i class="fas fa-circle-xmark"></i></div>
                <div class="stat-value"><?php echo (int) ($stats['rejected'] ?? 0); ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card info h-100">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value"><?php echo formatCurrency((float) ($stats['approved_total'] ?? 0)); ?></div>
                <div class="stat-label">Approved Spending</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-file-excel me-2"></i>All Reports</h5>
            <span class="text-muted small"><?php echo count($reports); ?> report(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <div class="empty-title">No reports yet</div>
                    <div class="empty-subtitle">Employee submissions will appear here for review.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Event</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>File</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $r): ?>
                                <?php
                                    $st = (string) ($r['status'] ?? 'pending');
                                    $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning');
                                    $fileUrl = SITE_URL . 'uploads/' . ltrim((string) ($r['file_path'] ?? ''), '/');
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($r['employee_name'] ?? 'Employee'); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(($r['event_name'] ?? '') ?: 'Event'); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars(($r['client_name'] ?? '') ?: ''); ?></div>
                                    </td>
                                    <td><strong><?php echo formatCurrency((float) ($r['total_amount'] ?? 0)); ?></strong></td>
                                    <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime((string) ($r['submitted_at'] ?? 'now')))); ?></td>
                                    <td>
                                        <?php if (!empty($r['file_path'])): ?>
                                            <a class="btn btn-sm btn-info" href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($st === 'pending'): ?>
                                            <form method="POST" class="d-flex gap-2 flex-wrap align-items-center">
                                                <input type="hidden" name="report_id" value="<?php echo (int) ($r['id'] ?? 0); ?>">
                                                <input type="text" class="form-control form-control-sm flex-grow-1" name="admin_comment" placeholder="Comment (optional)">
                                                <button type="submit" class="btn btn-sm btn-success" name="action" value="approve"><i class="fas fa-check me-1"></i>Approve</button>
                                                <button type="submit" class="btn btn-sm btn-danger" name="action" value="reject"><i class="fas fa-times me-1"></i>Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-muted small">
                                                <?php if (!empty($r['admin_comment'])): ?>
                                                    <?php echo htmlspecialchars($r['admin_comment']); ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
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

<?php require_once '../includes/footer.php'; ?>
