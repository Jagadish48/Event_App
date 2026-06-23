<?php
// Include database configuration
require_once __DIR__ . '/config/database.php';

// Check for API routes mapped by .htaccess
$route = $_GET['route'] ?? '';
if (strpos($route, 'api/') === 0) {
    // Force JSON response for API endpoints
    header('Content-Type: application/json');
    
    // Extract the specific endpoint (everything after 'api/')
    $endpoint = substr($route, 4);
    
    // Handle different endpoints
    switch ($endpoint) {
        case 'ping':
            echo json_encode([
                'status' => 'success', 
                'message' => 'API is running smoothly!', 
                'timestamp' => time()
            ]);
            break;
            
        // You can add more API endpoints here
        // case 'users':
        //     require 'api/users.php';
        //     break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'status' => 'error', 
                'message' => 'API endpoint not found: ' . htmlspecialchars($endpoint)
            ]);
            break;
    }
    
    // Stop further execution so HTML is not rendered for API calls
    exit();
}

// If user is already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect(SITE_URL . 'admin/dashboard.php');
    } else {
        redirect(SITE_URL . 'employee/dashboard.php');
    }
    exit();
}

$error = '';
$sessionLoginError = $_SESSION['login_error'] ?? '';
if ($sessionLoginError !== '') {
    $error = $sessionLoginError;
    unset($_SESSION['login_error']);
}

$loginRole = '';
$loginRoleRaw = strtolower(trim((string) ($_GET['role'] ?? '')));
if ($loginRoleRaw === 'admin' || $loginRoleRaw === 'employee') {
    $loginRole = $loginRoleRaw;
}

$branding = getBrandingSettings();
$appName = trim((string) ($branding['app_name'] ?? 'NETWORK EVENTS'));
if ($appName === '') {
    $appName = 'NETWORK EVENTS';
}
$logoMainUrl = resolveAppAssetUrl($branding['logo_main'] ?? '');
$logoDarkUrl = resolveAppAssetUrl($branding['logo_dark'] ?? '');
$logoLightUrl = resolveAppAssetUrl($branding['logo_light'] ?? '');
$loginLogoUrl = resolveAppAssetUrl($branding['logo_login'] ?? '');
$faviconUrl = resolveAppAssetUrl($branding['favicon'] ?? '');
$brandLogoUrl = $loginLogoUrl !== '' ? $loginLogoUrl : ($logoMainUrl !== '' ? $logoMainUrl : ($logoDarkUrl !== '' ? $logoDarkUrl : $logoLightUrl));
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
    <title><?php echo $loginRole === 'admin' ? 'Admin Login' : ($loginRole === 'employee' ? 'Employee Login' : 'Login'); ?> - <?php echo htmlspecialchars($appName); ?></title>
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/bootstrap.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/all.min.css?v=<?php echo filemtime(__DIR__ . '/assets/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    
    <!-- PWA Manifest and Meta Tags -->
    <link rel="manifest" href="<?php echo SITE_URL; ?>manifest.php">
    <meta name="theme-color" content="#0d6efd">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <script>
        window.SITE_URL = '<?php echo addslashes(SITE_URL); ?>';
    </script>
</head>
<body class="auth-page<?php echo $loginRole === '' ? ' auth-landing' : ($loginRole === 'admin' ? ' auth-role-admin' : ($loginRole === 'employee' ? ' auth-role-employee' : '')); ?>"
      data-app-name="<?php echo htmlspecialchars($appName); ?>"
      data-logo-main="<?php echo htmlspecialchars($logoMainUrl); ?>"
      data-logo-dark="<?php echo htmlspecialchars($logoDarkUrl); ?>"
      data-logo-light="<?php echo htmlspecialchars($logoLightUrl); ?>"
      data-logo-login="<?php echo htmlspecialchars($loginLogoUrl); ?>"
      data-favicon="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php if ($loginRole === 'admin'): ?>
        <div class="auth-admin-shell">
            <div class="auth-admin-stage">
                <div class="auth-admin-hero">
                    <a class="app-brand" href="<?php echo SITE_URL; ?>">
                        <?php if ($brandLogoUrl !== ''): ?>
                            <img class="app-logo-img js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                        <?php else: ?>
                            <span class="app-logo-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                        <?php endif; ?>
                        <span class="app-brand-text">
                            <span class="brand-name"><?php echo htmlspecialchars($appName); ?></span>
                            <span class="brand-sub">Administrator</span>
                        </span>
                    </a>

                    <div class="auth-admin-heading">Administrator Secure Access</div>
                    <div class="auth-admin-subtitle">Secure dashboard access for management and approvals</div>

                    <div class="auth-logo-center">
                        <?php if ($brandLogoUrl !== ''): ?>
                            <img class="auth-logo-float js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                        <?php else: ?>
                            <div class="auth-logo-float auth-logo-fallback" aria-hidden="true">
                                <i class="fas fa-network-wired"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="auth-admin-form">
                    <div class="auth-admin-topbar">
                        <a class="btn btn-secondary btn-sm" href="index.php?role=employee"><i class="fas fa-user-check me-2"></i>Employee Login</a>
                        <div class="app-theme-toggle">
                            <i class="fas fa-moon" aria-hidden="true"></i>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="appThemeToggle" aria-label="Toggle theme">
                            </div>
                            <i class="fas fa-sun" aria-hidden="true"></i>
                        </div>
                    </div>

                    <div class="auth-admin-form-card">
                        <div class="auth-admin-form-title h4 mb-1">Admin Sign In</div>
                        <div class="text-muted">Use your admin credentials to access the corporate dashboard.</div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger mt-3" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="login.php" class="mt-3">
                            <input type="hidden" name="expected_role" value="admin">

                            <div class="mb-3">
                                <label for="email" class="form-label">Admin email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter admin email" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mb-4 text-end">
                                <a href="forgot_password.php?role=admin" class="text-decoration-none small">Forgot Password?</a>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 auth-submit">
                                <i class="fas fa-shield-halved me-2"></i>Sign In to Admin Dashboard
                            </button>

                        </form>
                    </div>

                    <div class="text-muted small">
                        <i class="fas fa-lock me-1"></i>Admin access is restricted to authorized accounts.
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($loginRole === 'employee'): ?>
        <div class="auth-employee-shell">
            <div class="auth-employee-stage">
                <div class="auth-employee-panel">
                    <a class="app-brand" href="<?php echo SITE_URL; ?>">
                        <?php if ($brandLogoUrl !== ''): ?>
                            <img class="app-logo-img js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                        <?php else: ?>
                            <span class="app-logo-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                        <?php endif; ?>
                        <span class="app-brand-text">
                            <span class="brand-name"><?php echo htmlspecialchars($appName); ?></span>
                            <span class="brand-sub">Workspace</span>
                        </span>
                    </a>

                    <div class="auth-employee-heading">Employee Portal Access</div>
                    <div class="auth-employee-subtitle">Access your tasks, attendance, and event assignments</div>

                    <div class="auth-logo-center">
                        <?php if ($brandLogoUrl !== ''): ?>
                            <img class="auth-logo-float js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                        <?php else: ?>
                            <div class="auth-logo-float auth-logo-fallback" aria-hidden="true">
                                <i class="fas fa-network-wired"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="auth-employee-card">
                    <div class="auth-employee-topbar">
                        <a class="btn btn-secondary btn-sm" href="index.php?role=admin"><i class="fas fa-user-shield me-2"></i>Admin Login</a>
                        <div class="app-theme-toggle">
                            <i class="fas fa-moon" aria-hidden="true"></i>
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="appThemeToggle" aria-label="Toggle theme">
                            </div>
                            <i class="fas fa-sun" aria-hidden="true"></i>
                        </div>
                    </div>

                    <div class="auth-employee-form-title h4 mb-1">Employee Sign In</div>
                    <div class="text-muted">Sign in to manage your tasks and daily activity.</div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger mt-3" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" class="mt-3">
                        <input type="hidden" name="expected_role" value="employee">

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" placeholder="Enter email" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4 text-end">
                            <a href="forgot_password.php?role=employee" class="text-decoration-none small">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 auth-submit">
                            <i class="fas fa-right-to-bracket me-2"></i>Enter Workspace
                        </button>
                        </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="auth-shell">
            <div class="auth-card">
                <div class="auth-left">
                    <div class="auth-left-panel">
                        <a class="app-brand" href="<?php echo SITE_URL; ?>">
                            <?php if ($brandLogoUrl !== ''): ?>
                                <img class="app-logo-img js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                            <?php else: ?>
                                <span class="app-logo-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                            <?php endif; ?>
                            <span class="app-brand-text">
                                <span class="brand-name"><?php echo htmlspecialchars($appName); ?></span>
                                <span class="brand-sub">Secure Access</span>
                            </span>
                        </a>

                        <div class="auth-welcome">
                            <div class="auth-welcome-head">
                                <?php if ($brandLogoUrl !== ''): ?>
                                    <span class="auth-welcome-icon">
                                        <img class="auth-welcome-logo js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                                    </span>
                                <?php endif; ?>
                                <div class="auth-welcome-title">WELCOME BACK</div>
                            </div>
                            <div class="auth-welcome-subtitle">Login to continue managing events, employees, and workflows.</div>
                        </div>

                        <div class="auth-highlights">
                            <div class="auth-highlight">
                                <span class="auth-highlight-icon" aria-hidden="true"><i class="fas fa-user-shield"></i></span>
                                <div>
                                    <div class="auth-highlight-title">Role-based access</div>
                                    <div class="auth-highlight-sub">Admin and Employee areas stay separated and protected.</div>
                                </div>
                            </div>
                            <div class="auth-highlight">
                                <span class="auth-highlight-icon" aria-hidden="true"><i class="fas fa-calendar-check"></i></span>
                                <div>
                                    <div class="auth-highlight-title">Daily operations</div>
                                    <div class="auth-highlight-sub">Attendance, payroll, expenses, and tasks in one place.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="auth-right">
                    <div class="auth-role-card">
                        <div class="auth-top">
                            <div>
                                <div class="auth-role-title">Choose your workspace</div>
                                <div class="auth-role-sub">Continue with your role to sign in.</div>
                            </div>
                            <div class="app-theme-toggle">
                                <i class="fas fa-moon" aria-hidden="true"></i>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="appThemeToggle" aria-label="Toggle theme">
                                </div>
                                <i class="fas fa-sun" aria-hidden="true"></i>
                            </div>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger mt-3" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error); ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2 mt-3">
                            <a class="btn btn-primary auth-role-btn" href="index.php?role=admin"><i class="fas fa-user-shield me-2"></i>Continue to Admin Login</a>
                            <a class="btn btn-success auth-role-btn" href="index.php?role=employee"><i class="fas fa-user-check me-2"></i>Continue to Employee Login</a>
                        </div>
                        
                        <div class="mt-4 pt-3 border-top border-secondary border-opacity-25" id="app-download-section">
                            <div class="text-center text-muted small mb-3 text-uppercase fw-bold"><i class="fas fa-mobile-screen me-2"></i>Install Mobile App</div>
                            <div class="d-grid gap-2" id="app-download-buttons">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="handleAppDownload('android')" id="btn-download-android">
                                    <i class="fab fa-android me-2"></i><span class="btn-text">Download for Android</span>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="handleAppDownload('ios')" id="btn-download-ios">
                                    <i class="fab fa-apple me-2"></i><span class="btn-text">Download for iOS</span>
                                </button>
                            </div>
                        </div>

                        <div class="text-muted small mt-3">
                            <i class="fas fa-circle-info me-1"></i>Tip: Use the toggle to switch between light and dark mode.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="<?php echo SITE_URL; ?>assets/js/bootstrap.bundle.min.js?v=<?php echo filemtime(__DIR__ . '/assets/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/script.js"></script>
    <script>
        // Password Toggle Functionality
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

        // If already installed as a PWA, mark buttons accordingly
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            document.querySelectorAll('[data-pwa-install], .js-pwa-install').forEach(function(btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>App Installed';
            });
            
            const androidBtn = document.getElementById('btn-download-android');
            const iosBtn = document.getElementById('btn-download-ios');
            if (androidBtn) {
                androidBtn.innerHTML = '<i class="fas fa-external-link-alt me-2"></i>Open App';
                androidBtn.onclick = function() { alert('App is already installed. Please open it from your home screen.'); };
            }
            if (iosBtn) {
                iosBtn.style.display = 'none';
            }
        } else {
            window.addEventListener('beforeinstallprompt', function(e) {
                window._pwaPromptAvailable = true;
            });
        }

        function handleAppDownload(platform) {
            const isPWAInstalled = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            if (isPWAInstalled) {
                alert('App is already installed. Please use it from your home screen.');
                return;
            }

            if (platform === 'android') {
                if (typeof window.triggerPWAInstall === 'function' && window._pwaPromptAvailable) {
                    window.triggerPWAInstall();
                    return;
                }
                const apkUrl = window.SITE_URL + 'downloads/app.apk';
                if (/android/i.test(navigator.userAgent)) {
                     alert('To install:\\n1. Download the APK.\\n2. Open the downloaded file.\\n3. Allow installation from unknown sources if prompted.');
                     window.location.href = apkUrl;
                } else {
                     alert('Please visit this page on an Android device to download the app.');
                }
            } else if (platform === 'ios') {
                alert('To install on iPhone/iPad:\\n1. Tap the Share button (square with arrow pointing up) at the bottom of Safari.\\n2. Scroll down and tap "Add to Home Screen".');
            }
        }
    </script>
</body>
</html>
