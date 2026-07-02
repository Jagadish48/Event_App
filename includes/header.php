<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();
ensureProfileImageSchema();
$branding = getBrandingSettings();
$appName = trim((string) ($branding['app_name'] ?? 'NETWORK EVENTS'));
$appName = $appName !== '' ? $appName : 'NETWORK EVENTS';
$logoMainUrl = resolveAppAssetUrl($branding['logo_main'] ?? '');
$logoDarkUrl = resolveAppAssetUrl($branding['logo_dark'] ?? '');
$logoLightUrl = resolveAppAssetUrl($branding['logo_light'] ?? '');
$loginLogoUrl = resolveAppAssetUrl($branding['logo_login'] ?? '');
$faviconUrl = resolveAppAssetUrl($branding['favicon'] ?? '');
$brandLogoUrl = $logoMainUrl !== '' ? $logoMainUrl : ($logoDarkUrl !== '' ? $logoDarkUrl : $logoLightUrl);
$currentUserName = $_SESSION['name'] ?? 'User';
$currentUserProfileImage = '';
try {
    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $row = $stmt->fetch();
    $currentUserProfileImage = (string) ($row['profile_image'] ?? '');
} catch (PDOException $e) {
    $currentUserProfileImage = '';
}
$profileInfo = resolveUploadPathInfo($currentUserProfileImage);
$profileImageUrl = ($profileInfo['exists'] ?? false) ? (string) ($profileInfo['url'] ?? '') : '';
$initials = '';
$parts = preg_split('/\s+/', trim((string) $currentUserName));
if ($parts) {
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo htmlspecialchars($appName); ?></title>
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <?php else: ?>
        <link rel="icon" href="<?php echo SITE_URL; ?>assets/icons/icon-192.png">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/bootstrap.min.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/all.min.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="manifest" href="<?php echo SITE_URL; ?>manifest.php">
    <meta name="theme-color" content="#0F172A">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($appName); ?>">
    <link rel="apple-touch-icon" href="<?php echo SITE_URL; ?>assets/icons/icon-192.png">
    <script>
        window.SITE_URL = '<?php echo addslashes(SITE_URL); ?>';
    </script>
</head>
<body data-app-name="<?php echo htmlspecialchars($appName); ?>"
      data-panel-label="<?php echo isAdmin() ? 'Admin Panel' : 'Employee Panel'; ?>"
      data-logo-main="<?php echo htmlspecialchars($logoMainUrl); ?>"
      data-logo-dark="<?php echo htmlspecialchars($logoDarkUrl); ?>"
      data-logo-light="<?php echo htmlspecialchars($logoLightUrl); ?>"
      data-logo-login="<?php echo htmlspecialchars($loginLogoUrl); ?>"
      data-favicon="<?php echo htmlspecialchars($faviconUrl); ?>">
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top app-topbar">
        <div class="container-fluid">
            <button class="btn btn-icon d-lg-none me-2" type="button" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand app-brand" href="<?php echo SITE_URL; ?><?php echo isAdmin() ? 'admin' : 'employee'; ?>/dashboard.php">
                <?php if ($brandLogoUrl !== ''): ?>
                    <img class="app-logo-img js-brand-logo" src="<?php echo htmlspecialchars($brandLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?> logo">
                <?php else: ?>
                    <span class="app-logo-mark" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
                <?php endif; ?>
                <span class="app-brand-text">
                    <span class="brand-name"><?php echo htmlspecialchars($appName); ?></span>
                    <span class="brand-sub"><?php echo isAdmin() ? 'Admin Panel' : 'Employee Panel'; ?></span>
                </span>
            </a>

            <ul class="navbar-nav ms-auto">
                <li class="nav-item d-flex align-items-center me-2">
                    <div class="app-theme-toggle">
                        <i class="fas fa-moon" aria-hidden="true"></i>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="appThemeToggle" aria-label="Toggle theme">
                        </div>
                        <i class="fas fa-sun" aria-hidden="true"></i>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <?php if ($profileImageUrl !== ''): ?>
                            <img class="app-avatar-img me-2" src="<?php echo htmlspecialchars($profileImageUrl); ?>" alt="<?php echo htmlspecialchars($currentUserName); ?>">
                        <?php else: ?>
                            <span class="app-avatar me-2"><?php echo htmlspecialchars($initials ?: 'U'); ?></span>
                        <?php endif; ?>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($currentUserName); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo SITE_URL; ?><?php echo isAdmin() ? 'admin' : 'employee'; ?>/profile.php">
                            <i class="fas fa-user me-2"></i>Profile
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>

    <div class="sidebar-backdrop" onclick="toggleSidebar(true)"></div>
    <div id="alertContainer" class="app-toast"></div>
