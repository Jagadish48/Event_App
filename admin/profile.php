<?php
$pageTitle = 'My Profile';
require_once '../includes/header.php';
requireAdmin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([
                clean_input($_POST['name']),
                clean_input($_POST['email']),
                $_SESSION['user_id']
            ]);

            $_SESSION['name'] = clean_input($_POST['name']);
            $_SESSION['email'] = clean_input($_POST['email']);

            $success = 'Profile updated successfully!';
        } catch(PDOException $e) {
            $error = 'Error updating profile: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'change_password') {
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userRow = $stmt->fetch();

            $storedPassword = $userRow['password'] ?? '';
            $storedIsHashed = (bool) (password_get_info($storedPassword)['algo'] ?? 0);

            $currentOk = $storedIsHashed ? password_verify($currentPassword, $storedPassword) : ($currentPassword === $storedPassword);

            if (!$currentOk) {
                $error = 'Current password is incorrect';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters long';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $_SESSION['user_id']]);
                $success = 'Password changed successfully!';
            }
        } catch(PDOException $e) {
            $error = 'Error changing password: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'upload_profile_photo') {
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

                if ($oldPath !== '') {
                    deleteUploadedFileByStoredPath($oldPath);
                }

                $success = 'Profile photo updated successfully!';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'remove_profile_photo') {
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

                if ($oldPath !== '') {
                    deleteUploadedFileByStoredPath($oldPath);
                }

                $success = 'Profile photo removed successfully!';
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'update_logo_settings') {
        ensureAppSettingsSchema();

        $maxBytes = 2 * 1024 * 1024;
        $allowedExt = ['png', 'jpg', 'jpeg', 'svg'];
        $uploadRel = 'assets/uploads/logo/';
        $uploadAbs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'logo' . DIRECTORY_SEPARATOR;

        if (!is_dir($uploadAbs)) {
            @mkdir($uploadAbs, 0775, true);
        }

        $inputMap = [
            'main_logo' => 'logo_main',
            'dark_logo' => 'logo_dark',
            'light_logo' => 'logo_light',
            'login_logo' => 'logo_login',
            'favicon' => 'favicon'
        ];

        $didUpdate = false;
        foreach ($inputMap as $input => $key) {
            if (!isset($_FILES[$input]) || !is_array($_FILES[$input])) continue;
            $f = $_FILES[$input];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $error = 'Upload failed. Please try again.';
                break;
            }
            if ((int) ($f['size'] ?? 0) > $maxBytes) {
                $error = 'Max file size is 2MB.';
                break;
            }

            $name = (string) ($f['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $error = 'Only PNG, JPG, and SVG files are allowed.';
                break;
            }

            $tmp = (string) ($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                $error = 'Invalid upload.';
                break;
            }

            if ($ext === 'svg') {
                $head = @file_get_contents($tmp, false, null, 0, 2048);
                if ($head === false || stripos($head, '<svg') === false) {
                    $error = 'Invalid SVG file.';
                    break;
                }
            } else {
                $imgInfo = @getimagesize($tmp);
                if (!$imgInfo) {
                    $error = 'Invalid image file.';
                    break;
                }
            }

            $safeKey = preg_replace('/[^a-z0-9_]/i', '', (string) $key) ?: 'logo';
            try {
                $rand = bin2hex(random_bytes(4));
            } catch (Exception $e) {
                $rand = (string) mt_rand(100000, 999999);
            }
            $filename = $safeKey . '-' . date('YmdHis') . '-' . $rand . '.' . $ext;
            $destAbs = $uploadAbs . $filename;
            $destRel = $uploadRel . $filename;

            if (!@move_uploaded_file($tmp, $destAbs)) {
                $error = 'Failed to save uploaded file.';
                break;
            }

            setAppSetting($key, $destRel);
            $didUpdate = true;
        }

        if ($error === '') {
            $success = $didUpdate ? 'Logo settings updated successfully!' : 'No logo changes to save.';
        }
    } elseif ($_POST['action'] === 'reset_logo_settings') {
        ensureAppSettingsSchema();
        foreach (['logo_main', 'logo_dark', 'logo_light', 'logo_login', 'favicon'] as $k) {
            deleteAppSetting($k);
        }
        $success = 'Logo settings reset to default.';
    }
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, role, profile_image, created_at, updated_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
} catch(PDOException $e) {
    $error = 'Error fetching profile: ' . $e->getMessage();
    $admin = null;
}

$profileInfo = resolveUploadPathInfo((string) ($admin['profile_image'] ?? ''));
$profileImageUrl = ($profileInfo['exists'] ?? false) ? (string) ($profileInfo['url'] ?? '') : '';
$initials = '';
$parts = preg_split('/\s+/', trim((string) ($admin['name'] ?? '')));
if ($parts) {
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
}

$branding = getBrandingSettings();
$logoMainUrl = resolveAppAssetUrl($branding['logo_main'] ?? '');
$logoDarkUrl = resolveAppAssetUrl($branding['logo_dark'] ?? '');
$logoLightUrl = resolveAppAssetUrl($branding['logo_light'] ?? '');
$loginLogoUrl = resolveAppAssetUrl($branding['logo_login'] ?? '');
$faviconUrl = resolveAppAssetUrl($branding['favicon'] ?? '');
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
            <h1 class="h3 page-title">Settings</h1>
            <div class="page-subtitle">Manage your admin account details</div>
        </div>
        <div class="page-actions"></div>
    </div>

    <div class="quick-actions mb-4">
        <a class="btn btn-secondary" href="reports.php"><i class="fas fa-chart-bar me-2"></i>Reports</a>
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

    <?php if ($admin): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">Profile Photo</h5>
                    </div>
                    <div class="card-body">
                        <div class="profile-photo-row">
                            <div class="profile-photo">
                                <?php if ($profileImageUrl !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="<?php echo htmlspecialchars((string) ($admin['name'] ?? '')); ?>">
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
                                <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($admin['name'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Account Details</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td><strong>Role:</strong></td>
                                <td><?php echo htmlspecialchars($admin['role'] ?? 'admin'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>User ID:</strong></td>
                                <td>#<?php echo str_pad((int) ($admin['id'] ?? 0), 5, '0', STR_PAD_LEFT); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created:</strong></td>
                                <td><?php echo htmlspecialchars($admin['created_at'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Updated:</strong></td>
                                <td><?php echo htmlspecialchars($admin['updated_at'] ?? ''); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">Change Password</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">
                            <div class="mb-3">
                                <label class="form-label">Current Password *</label>
                                <input type="password" class="form-control" name="current_password" maxlength="50" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password *</label>
                                <input type="password" class="form-control" name="new_password" maxlength="50" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password *</label>
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

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h5 class="mb-0"><i class="fas fa-image me-2"></i>Logo Settings</h5>
                <span class="badge bg-info">Branding</span>
            </div>
            <div class="card-body">
                <div class="text-muted mb-3">Upload brand assets and apply them across Admin, Employee, and Login pages.</div>

                <form method="POST" action="" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="action" value="update_logo_settings">

                    <div class="col-lg-6">
                        <label class="form-label">Main website logo</label>
                        <input class="form-control" type="file" name="main_logo" accept=".png,.jpg,.jpeg,.svg" data-preview-target="#previewMain">
                        <div class="panel-lite rounded p-3 mt-2 d-flex align-items-center gap-3">
                            <img id="previewMain" class="app-logo-img" src="<?php echo htmlspecialchars($logoMainUrl); ?>" alt="Main logo preview" style="<?php echo $logoMainUrl !== '' ? '' : 'display:none;'; ?>">
                            <div class="text-muted small"><?php echo $logoMainUrl !== '' ? 'Current logo' : 'No custom logo uploaded'; ?></div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label">Dark theme logo</label>
                        <input class="form-control" type="file" name="dark_logo" accept=".png,.jpg,.jpeg,.svg" data-preview-target="#previewDark">
                        <div class="panel-lite rounded p-3 mt-2 d-flex align-items-center gap-3">
                            <img id="previewDark" class="app-logo-img" src="<?php echo htmlspecialchars($logoDarkUrl); ?>" alt="Dark logo preview" style="<?php echo $logoDarkUrl !== '' ? '' : 'display:none;'; ?>">
                            <div class="text-muted small"><?php echo $logoDarkUrl !== '' ? 'Current dark logo' : 'Falls back to main logo'; ?></div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label">Light theme logo</label>
                        <input class="form-control" type="file" name="light_logo" accept=".png,.jpg,.jpeg,.svg" data-preview-target="#previewLight">
                        <div class="panel-lite rounded p-3 mt-2 d-flex align-items-center gap-3">
                            <img id="previewLight" class="app-logo-img" src="<?php echo htmlspecialchars($logoLightUrl); ?>" alt="Light logo preview" style="<?php echo $logoLightUrl !== '' ? '' : 'display:none;'; ?>">
                            <div class="text-muted small"><?php echo $logoLightUrl !== '' ? 'Current light logo' : 'Falls back to main logo'; ?></div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label">Login page logo</label>
                        <input class="form-control" type="file" name="login_logo" accept=".png,.jpg,.jpeg,.svg" data-preview-target="#previewLogin">
                        <div class="panel-lite rounded p-3 mt-2 d-flex align-items-center gap-3">
                            <img id="previewLogin" class="app-logo-img" src="<?php echo htmlspecialchars($loginLogoUrl); ?>" alt="Login logo preview" style="<?php echo $loginLogoUrl !== '' ? '' : 'display:none;'; ?>">
                            <div class="text-muted small"><?php echo $loginLogoUrl !== '' ? 'Current login logo' : 'Falls back to main logo'; ?></div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label">Favicon</label>
                        <input class="form-control" type="file" name="favicon" accept=".png,.jpg,.jpeg,.svg" data-preview-target="#previewFavicon">
                        <div class="panel-lite rounded p-3 mt-2 d-flex align-items-center gap-3">
                            <img id="previewFavicon" class="app-logo-img" src="<?php echo htmlspecialchars($faviconUrl); ?>" alt="Favicon preview" style="<?php echo $faviconUrl !== '' ? '' : 'display:none;'; ?>">
                            <div class="text-muted small"><?php echo $faviconUrl !== '' ? 'Current favicon' : 'Default favicon will be used'; ?></div>
                        </div>
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                        <button type="submit" class="btn btn-primary btn-action">
                            <i class="fas fa-save"></i>Save Changes
                        </button>
                    </div>
                </form>

                <form method="POST" action="" class="mt-2">
                    <input type="hidden" name="action" value="reset_logo_settings">
                    <button type="submit" class="btn btn-outline-secondary btn-action">
                        <i class="fas fa-rotate-left"></i>Reset Default
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[type="file"][data-preview-target]').forEach(function(input) {
            input.addEventListener('change', function() {
                var targetSel = input.getAttribute('data-preview-target');
                if (!targetSel) return;
                var img = document.querySelector(targetSel);
                if (!img) return;
                var file = input.files && input.files[0];
                if (!file) return;
                var url = URL.createObjectURL(file);
                img.src = url;
                img.style.display = '';
            });
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
