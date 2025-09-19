<?php
// includes/session.php - Session management
session_start();

function isLoggedIn() {
    $loggedIn = isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    error_log("DEBUG session.php: isLoggedIn check - result=" . ($loggedIn ? 'true' : 'false') .
              ", user_id=" . ($_SESSION['user_id'] ?? 'not set') .
              ", user_role=" . ($_SESSION['user_role'] ?? 'not set'));
    return $loggedIn;
}

function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'role' => $_SESSION['user_role'],
            'first_name' => $_SESSION['first_name'] ?? '',
            'last_name' => $_SESSION['last_name'] ?? '',
            'email' => $_SESSION['email'] ?? ''
        ];
    }
    return null;
}

function requireAuth() {
    if (!isLoggedIn()) {
        // Determine the correct path based on current script location
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        if (strpos($currentDir, '/views') !== false) {
            header('Location: user-login.php');
        } else {
            header('Location: views/user-login.php');
        }
        exit();
    }
}

function requireRole($allowedRoles) {
    if (!isLoggedIn() || !in_array($_SESSION['user_role'], (array)$allowedRoles)) {
        // Determine the correct path based on current script location
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        if (strpos($currentDir, '/views') !== false) {
            header('Location: user-login.php');
        } else {
            header('Location: views/user-login.php');
        }
        exit();
    }
}

function loginUser($userData) {
    $_SESSION['user_id'] = $userData['id'];
    $_SESSION['user_role'] = $userData['role'];
    $_SESSION['first_name'] = $userData['first_name'];
    $_SESSION['last_name'] = $userData['last_name'];
    $_SESSION['email'] = $userData['email'];

    // DEBUG: Log session creation
    error_log("DEBUG session.php: Session created for user=" . $userData['id'] . ", role=" . $userData['role']);
}

function logoutUser() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}
?>