<?php
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    if (isAdmin()) {
        redirect(SITE_URL . 'admin/dashboard.php');
    } else {
        redirect(SITE_URL . 'employee/dashboard.php');
    }
}

// Show login form for GET requests, process login for POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Redirect to index.php for login form
    redirect(SITE_URL . 'index.php');
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$expectedRoleRaw = strtolower(trim((string) ($_POST['expected_role'] ?? '')));
$expectedRole = ($expectedRoleRaw === 'admin' || $expectedRoleRaw === 'employee') ? $expectedRoleRaw : '';
$returnUrl = 'index.php' . ($expectedRole !== '' ? ('?role=' . urlencode($expectedRole)) : '');

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = 'Please fill in all fields';
    header('Location: ' . $returnUrl);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['login_error'] = 'Invalid email or password';
        header('Location: ' . $returnUrl);
        exit();
    }

    $storedPassword = (string) ($user['password'] ?? '');
    $storedIsHashed = (bool) (password_get_info($storedPassword)['algo'] ?? 0);
    $passwordOk = $storedIsHashed ? password_verify($password, $storedPassword) : ($password === $storedPassword);

    if (!$passwordOk) {
        $_SESSION['login_error'] = 'Invalid email or password';
        header('Location: ' . $returnUrl);
        exit();
    }

    $rawRole = (string) ($user['role'] ?? '');
    $role = normalizeUserRole($rawRole !== '' ? $rawRole : 'employee');
    $rawRoleNormalized = strtolower(trim($rawRole));
    if ($rawRoleNormalized !== '' && $rawRoleNormalized !== 'admin' && $rawRoleNormalized !== 'employee') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, (int) $user['id']]);
        } catch (PDOException $e) {
        }
    }

    if ($expectedRole !== '' && $role !== $expectedRole) {
        $_SESSION['login_error'] = $expectedRole === 'admin'
            ? 'This account is not authorized for Admin Login. Please use Employee Login.'
            : 'This account is not authorized for Employee Login. Please use Admin Login.';
        header('Location: index.php?role=' . urlencode($expectedRole));
        exit();
    }

    // Clear any existing session variables to prevent mix-up, but preserve login_error if any
    $tempLoginError = $_SESSION['login_error'] ?? null;
    session_unset();
    // Restore login_error if it was present
    if ($tempLoginError !== null) {
        $_SESSION['login_error'] = $tempLoginError;
    }
    
    // Set new session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $role;
    $_SESSION['login_time'] = time();

    if (($expectedRole !== '' ? $expectedRole : $_SESSION['role']) === 'admin') {
        redirect(SITE_URL . 'admin/dashboard.php');
    } else {
        redirect(SITE_URL . 'employee/dashboard.php');
    }
} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Database error. Please try again.';
    header('Location: ' . $returnUrl);
    exit();
}
?>
