<?php
// includes/session.php - Session management

// 1. SECURE SESSION SETTINGS (Must be set before session_start)
// Force sessions to use cookies only (no session IDs in URLs)
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);

// Configure cookie parameters dynamically for local vs production
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
session_set_cookie_params([
    'lifetime' => 86400, // 24 hours
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => $isSecure, // True on Hostinger (HTTPS), False on Localhost (HTTP)
    'httponly' => true,    // PREVENTS JavaScript from stealing the session cookie (Critical for XSS)
    'samesite' => 'Lax'    // Helps protect against Cross-Site Request Forgery (CSRF)
]);

session_start();

function isLoggedIn()
{
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function getCurrentUser()
{
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'role' => $_SESSION['user_role'],
            'first_name' => $_SESSION['first_name'] ?? '',
            'last_name' => $_SESSION['last_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'department' => $_SESSION['department'] ?? '',
            'position' => $_SESSION['position'] ?? ''
        ];
    }
    return null;
}

function redirectTo404()
{
    header('Location: ' . BASE_URL . '404');
    exit();
}

function requireAuth()
{
    if (!isLoggedIn()) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, '/api/') !== false) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit();
        }
        header('Location: ' . BASE_URL . 'login');
        exit();
    }
}

function requireRole($allowedRoles)
{
    if (!isLoggedIn() || !in_array($_SESSION['user_role'], (array) $allowedRoles)) {
        header('Location: ' . BASE_URL . 'login');
        exit();
    }
}

function loginUser($userData)
{
    // CRITICAL FIX: Regenerate session ID to prevent Session Fixation attacks
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userData['id'];
    $_SESSION['user_role'] = $userData['role'];
    $_SESSION['first_name'] = $userData['first_name'];
    $_SESSION['last_name'] = $userData['last_name'];
    $_SESSION['email'] = $userData['email'];
    $_SESSION['department'] = $userData['department'] ?? '';
    $_SESSION['position'] = $userData['position'] ?? '';
    if (isset($userData['must_change_password'])) {
        $_SESSION['must_change_password'] = (int) $userData['must_change_password'];
    }
}

function logoutUser()
{
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}
?>