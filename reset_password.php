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

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$showForm = true;

if ($token === '' || $userId === 0) {
    $error = 'Invalid or missing reset link.';
    $showForm = false;
} elseif (!validatePasswordResetToken($userId, $token)) {
    $error = 'This reset link is invalid or has expired.';
    $showForm = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {
    if (!verify_csrf_token()) {
        $error = 'Invalid request. Please try again.';
    } else {
    $newPassword = trim((string)($_POST['password'] ?? ''));
    $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (usePasswordResetToken($userId, $token, $newPassword)) {
        $success = 'Your password has been successfully reset!';
        $showForm = false;
        unset($_SESSION['reset_token'], $_SESSION['reset_user_id']);
    } else {
        $error = 'Failed to reset password. Please try again.';
    }
    } // end CSRF check
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
    <title>Reset Password - <?php echo htmlspecialchars($appName); ?></title>
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
<body class="auth-page">
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

                <div class="auth-admin-heading">Set New Password</div>
                <div class="auth-admin-subtitle">Choose a new password for your account</div>

                <div class="auth-logo-center">
                    <?php if ($loginLogoUrl !== ''): ?>
                        <img class="auth-logo-float js-brand-logo" src="<?php echo htmlspecialchars($loginLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                    <?php else: ?>
                        <div class="auth-logo-float auth-logo-fallback" aria-hidden="true">
                            <i class="fas fa-lock"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="auth-admin-form">
                <div class="auth-admin-topbar">
                    <a class="btn btn-secondary btn-sm" href="<?php echo SITE_URL; ?>index.php">
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
                    <div class="auth-admin-form-title h4 mb-1">New Password</div>
                    <div class="text-muted">Enter your new password below</div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger mt-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success mt-3" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                            <p class="mt-3">
                                <a href="<?php echo SITE_URL; ?>index.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($showForm): ?>
                        <form method="POST" action="reset_password.php?token=<?php echo urlencode($token); ?>&user_id=<?php echo $userId; ?>" class="mt-3">
                            <?php echo csrf_input(); ?>
                            <div class="mb-3">
                                <label for="password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter new password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary auth-submit">
                                <i class="fas fa-save me-2"></i>Reset Password
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/assets/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
    <script>
        document.querySelectorAll('.toggle-password').forEach(function(button) {
            button.addEventListener('click', function() {
                var targetId = this.getAttribute('data-target');
                var input = document.getElementById(targetId);
                var icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    </script>
</body>
</html>
