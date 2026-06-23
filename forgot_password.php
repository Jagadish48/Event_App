<?php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect(SITE_URL . 'admin/dashboard.php');
    } else {
        redirect(SITE_URL . 'employee/dashboard.php');
    }
    exit();
}

$role = trim(strtolower((string)($_GET['role'] ?? '')));
if ($role !== 'admin' && $role !== 'employee') {
    $role = '';
}

$error = '';
$success = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Please enter your email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, email, role, name FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Check if there's already a pending request
                $stmt = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
                $stmt->execute([(int) $user['id']]);
                $existingRequest = $stmt->fetch();

                if ($existingRequest) {
                    $success = 'You already have a pending password reset request. An administrator will review it shortly.';
                } else {
                    // Submit request for admin approval
                    $stmt = $pdo->prepare("INSERT INTO password_reset_requests (user_id, status) VALUES (?, 'pending')");
                    $stmt->execute([(int) $user['id']]);
                    $success = 'Your password reset request has been submitted. An administrator will review and set a new password for you. Please wait for confirmation.';
                }
            } else {
                // For security, don't reveal whether the email exists
                $success = 'If that email exists in our system, a reset request has been submitted for admin review.';
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again.';
        }
    }
}

$branding = getBrandingSettings();
$appName = trim((string)($branding['app_name'] ?? 'NETWORK EVENTS'));
if ($appName === '') {
    $appName = 'NETWORK EVENTS';
}
$loginLogoUrl = resolveAppAssetUrl($branding['logo_login'] ?? '');
$faviconUrl = resolveAppAssetUrl($branding['favicon'] ?? '');
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            try {
                var t = localStorage.getItem('ems_theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.dataset.theme = t;
                }
            } catch (e) {}
        })();
    </script>
    <title>Forgot Password - <?php echo htmlspecialchars($appName); ?></title>
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/bootstrap.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/all.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/style.css" rel="stylesheet">

    <!-- PWA Manifest and Meta Tags -->
    <link rel="manifest" href="<?php echo SITE_URL; ?>manifest.php">
    <meta name="theme-color" content="#0d6efd">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
</head>
<body class="auth-page <?php echo $role === 'admin' ? 'auth-role-admin' : ($role === 'employee' ? 'auth-role-employee' : ''); ?>">
    <div class="auth-admin-shell">
        <div class="auth-admin-stage">
            <div class="auth-admin-hero">
                <a class="app-brand" href="<?php echo SITE_URL; ?>">
                    <?php if ($loginLogoUrl !== ''): ?>
                        <img class="app-logo-img js-brand-logo" src="<?php echo htmlspecialchars($loginLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                    <?php else: ?>
                        <span class="app-logo-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                    <?php endif; ?>
                    <span class="app-brand-text">
                        <span class="brand-name"><?php echo htmlspecialchars($appName); ?></span>
                        <span class="brand-sub">Reset Password</span>
                    </span>
                </a>

                <div class="auth-admin-heading">Reset Your Password</div>
                <div class="auth-admin-subtitle">Enter your email to submit a password reset request to your administrator</div>

                <div class="auth-logo-center">
                    <?php if ($loginLogoUrl !== ''): ?>
                        <img class="auth-logo-float js-brand-logo" src="<?php echo htmlspecialchars($loginLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                    <?php else: ?>
                        <div class="auth-logo-float auth-logo-fallback" aria-hidden="true">
                            <i class="fas fa-key"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="auth-admin-form">
                <div class="auth-admin-topbar">
                    <a class="btn btn-secondary btn-sm" href="<?php echo SITE_URL; ?>index.php<?php echo $role !== '' ? '?role=' . urlencode($role) : ''; ?>">
                        <i class="fas fa-arrow-left me-2"></i>Back to Login
                    </a>
                    <div class="app-theme-toggle">
                        <i class="fas fa-moon" aria-hidden="true"></i>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="appThemeToggle" aria-label="Toggle theme">
                        </div>
                        <i class="fas fa-sun" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="auth-admin-form-card">
                    <div class="auth-admin-form-title h4 mb-1">Reset Password</div>
                    <div class="text-muted mb-3">Submit a request and your administrator will set a new password for you.</div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        </div>
                        <a href="<?php echo SITE_URL; ?>index.php<?php echo $role !== '' ? '?role=' . urlencode($role) : ''; ?>" class="btn btn-primary w-100">
                            <i class="fas fa-arrow-left me-2"></i>Back to Login
                        </a>
                    <?php else: ?>
                        <form method="POST" action="forgot_password.php<?php echo $role !== '' ? '?role=' . urlencode($role) : ''; ?>">
                            <input type="hidden" name="expected_role" value="<?php echo htmlspecialchars($role); ?>">

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary auth-submit">
                                <i class="fas fa-paper-plane me-2"></i>Submit Reset Request
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="text-muted small mt-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Your administrator will review your request and set a new password. This process may take some time.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/assets/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
</body>
</html>
