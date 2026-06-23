<?php
$pageTitle = 'My Profile';
require_once '../includes/header.php';
requireEmployee();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'update_profile') {
            try {
                // Update user details
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([
                    clean_input($_POST['name']),
                    clean_input($_POST['email']),
                    $_SESSION['user_id']
                ]);
                
                // Update employee details
                $stmt = $pdo->prepare("UPDATE employees SET phone = ?, address = ? WHERE user_id = ?");
                $stmt->execute([
                    clean_input($_POST['phone']),
                    clean_input($_POST['address']),
                    $_SESSION['user_id']
                ]);
                
                // Update session name
                $_SESSION['name'] = clean_input($_POST['name']);
                
                $success = 'Profile updated successfully!';
            } catch(PDOException $e) {
                $error = 'Error updating profile: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'change_password') {
            try {
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                if (!password_verify($currentPassword, $user['password'])) {
                    $error = 'Current password is incorrect';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'New passwords do not match';
                } elseif (strlen($newPassword) < 6) {
                    $error = 'Password must be at least 6 characters long';
                } else {
                    // Update password
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([
                        password_hash($newPassword, PASSWORD_DEFAULT),
                        $_SESSION['user_id']
                    ]);
                    
                    $success = 'Password changed successfully!';
                }
            } catch(PDOException $e) {
                $error = 'Error changing password: ' . $e->getMessage();
            }
        } elseif ($_POST['action'] == 'upload_profile_photo') {
            $csrf = (string) ($_POST['csrf_token'] ?? '');
            if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
                $error = 'Invalid security token. Please refresh and try again.';
            } elseif (!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])) {
                $error = 'Please select a photo to upload.';
            } else {
                try {
                    ensureProfileImageSchema();
                    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([(int) $_SESSION['user_id']]);
                    $row = $stmt->fetch();
                    $oldPath = (string) ($row['profile_image'] ?? '');

                    $newPath = uploadProfileImage($_FILES['profile_photo'], (int) $_SESSION['user_id']);
                    $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ? LIMIT 1");
                    $stmt->execute([$newPath, (int) $_SESSION['user_id']]);
                    try {
                        $stmt = $pdo->prepare("UPDATE employees SET profile_image = ? WHERE user_id = ? LIMIT 1");
                        $stmt->execute([$newPath, (int) $_SESSION['user_id']]);
                    } catch (PDOException $e) {
                    }

                    if ($oldPath !== '') {
                        deleteUploadedFileByStoredPath($oldPath);
                    }

                    $success = 'Profile photo updated successfully!';
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif ($_POST['action'] == 'remove_profile_photo') {
            $csrf = (string) ($_POST['csrf_token'] ?? '');
            if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
                $error = 'Invalid security token. Please refresh and try again.';
            } else {
                try {
                    ensureProfileImageSchema();
                    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([(int) $_SESSION['user_id']]);
                    $row = $stmt->fetch();
                    $oldPath = (string) ($row['profile_image'] ?? '');

                    $stmt = $pdo->prepare("UPDATE users SET profile_image = NULL WHERE id = ? LIMIT 1");
                    $stmt->execute([(int) $_SESSION['user_id']]);
                    try {
                        $stmt = $pdo->prepare("UPDATE employees SET profile_image = NULL WHERE user_id = ? LIMIT 1");
                        $stmt->execute([(int) $_SESSION['user_id']]);
                    } catch (PDOException $e) {
                    }

                    if ($oldPath !== '') {
                        deleteUploadedFileByStoredPath($oldPath);
                    }

                    $success = 'Profile photo removed successfully!';
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

// Get employee details
try {
    $stmt = $pdo->prepare("SELECT u.*, e.designation, e.salary, e.phone, e.address, e.join_date 
                          FROM users u 
                          LEFT JOIN employees e ON e.user_id = u.id 
                          WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $employee = $stmt->fetch();
    
    // Get assigned events
    $stmt = $pdo->prepare("SELECT e.*, c.name as client_name 
                          FROM event_team et 
                          JOIN events e ON et.event_id = e.id 
                          LEFT JOIN clients c ON e.client_id = c.id 
                          WHERE et.user_id = ? 
                          ORDER BY e.start_date DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $assignedEvents = $stmt->fetchAll();
    
    // Get recent attendance
    $stmt = $pdo->prepare("SELECT * FROM attendance 
                          WHERE user_id = ? 
                          ORDER BY date DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $recentAttendance = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = 'Error fetching profile: ' . $e->getMessage();
}

$profileInfo = resolveUploadPathInfo((string) ($employee['profile_image'] ?? ''));
$profileImageUrl = ($profileInfo['exists'] ?? false) ? (string) ($profileInfo['url'] ?? '') : '';
$initials = '';
$parts = preg_split('/\s+/', trim((string) ($employee['name'] ?? '')));
if ($parts) {
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
}
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

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="h3 page-title">Profile</h1>
            <div class="page-subtitle">Manage your personal and job information</div>
        </div>
        <div class="page-actions"></div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="payroll.php"><i class="fas fa-calculator me-2"></i>Payroll</a>
        <a class="btn btn-secondary" href="attendance.php"><i class="fas fa-clock me-2"></i>Attendance</a>
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

    <div class="row">
        <!-- Profile Information -->
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Profile Photo</h5>
                </div>
                <div class="card-body">
                    <div class="profile-photo-row">
                        <div class="profile-photo">
                            <?php if ($profileImageUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="<?php echo htmlspecialchars((string) ($employee['name'] ?? '')); ?>">
                            <?php else: ?>
                                <div class="profile-photo-initials"><?php echo htmlspecialchars($initials ?: 'U'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="profile-photo-actions">
                                <form method="POST" action="" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-center">
                                    <input type="hidden" name="action" value="upload_profile_photo">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                                    <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" required>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-upload me-2"></i>Upload Photo
                                    </button>
                                </form>
                                <?php if ($profileImageUrl !== ''): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="remove_profile_photo">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-trash me-2"></i>Remove Photo
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <div class="profile-photo-help mt-2">
                                Supported: JPG, JPEG, PNG, WEBP • Max 2MB
                            </div>
                            <div id="profile_photo_preview" class="mt-2"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Personal Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($employee['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($employee['email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Profile
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Job Information -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Job Information</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>Designation:</strong></td>
                            <td><?php echo htmlspecialchars($employee['designation'] ?: 'Not Assigned'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Salary:</strong></td>
                            <td><?php echo formatCurrency($employee['salary'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Join Date:</strong></td>
                            <td><?php echo formatDate($employee['join_date'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Employee ID:</strong></td>
                            <td>#<?php echo str_pad($employee['id'], 5, '0', STR_PAD_LEFT); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0">Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label">Current Password *</label>
                            <!-- Fixed: Added maxlength="50" to allow full password input -->
                            <input type="password" class="form-control" name="current_password" maxlength="50" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password *</label>
                            <!-- Fixed: Added maxlength="50" and kept minlength="6" for security -->
                            <input type="password" class="form-control" name="new_password" maxlength="50" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password *</label>
                            <!-- Fixed: Added maxlength="50" and kept minlength="6" for security -->
                            <input type="password" class="form-control" name="confirm_password" maxlength="50" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-lock me-2"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Attendance</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentAttendance)): ?>
                        <p class="text-muted">No attendance records found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Check In</th>
                                        <th>Check Out</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentAttendance as $attendance): ?>
                                        <tr>
                                            <td><?php echo formatDate($attendance['date']); ?></td>
                                            <td><?php echo $attendance['check_in'] ? substr($attendance['check_in'], 0, 5) : '-'; ?></td>
                                            <td><?php echo $attendance['check_out'] ? substr($attendance['check_out'], 0, 5) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Assigned Events</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($assignedEvents)): ?>
                        <p class="text-muted">No events assigned.</p>
                    <?php else: ?>
                        <?php foreach ($assignedEvents as $event): ?>
                            <div class="border rounded p-3 mb-2">
                                <h6><?php echo htmlspecialchars($event['name']); ?></h6>
                                <p class="mb-1">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i><?php echo formatDate($event['start_date']); ?>
                                        <?php if ($event['client_name']): ?>
                                            <br><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($event['client_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </p>
                                <span class="badge bg-<?php echo $event['status'] == 'active' ? 'primary' : 'warning'; ?>">
                                    <?php echo ucfirst($event['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
// Initialize form validation
document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        form.classList.add('was-validated');
    });
});
</script>
";
require_once '../includes/footer.php';
?>
