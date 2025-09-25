<?php
/**
 * Authentication API
 * ==================
 *
 * Handles user authentication operations including:
 * - User login with role-based access (POST)
 * - Password changes for authenticated users (POST action=change_password or PUT)
 *
 * This API manages user sessions and password security.
 * Uses bcrypt hashing for secure password storage.
 * Enforces password complexity requirements.
 */

header('Content-Type: application/json');
// Fix the include paths - use absolute paths from root
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/database.php';

// Utility: map session role to table
function _auth_table_by_role($role) {
    switch ($role) {
        case 'admin': return 'administrators';
        case 'employee': return 'employees';
        case 'student': return 'students';
        default: return null;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

// Handle password change (POST action=change_password or PUT)
if (($method === 'POST' && ($data['action'] ?? '') === 'change_password') || $method === 'PUT') {
    /**
     * Password Change Endpoint
     * ========================
     * Allows authenticated users to change their password.
     * Requires current password verification for security.
     * Enforces password complexity rules.
     */

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';

    // Enforce password policy: at least 8 chars, uppercase, lowercase, number, special char
    $pattern = '/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/';
    if (!preg_match($pattern, $newPassword)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password does not meet complexity requirements.']);
        exit;
    }

    $userId = $_SESSION['user_id'] ?? '';
    $role = $_SESSION['user_role'] ?? '';
    $table = _auth_table_by_role($role);
    if (!$userId || !$table) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid session state']);
        exit;
    }

    try {
        $db = (new Database())->getConnection();
        // Fetch current password hash
        $stmt = $db->prepare("SELECT password FROM $table WHERE id=:id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($currentPassword, $row['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $db->prepare("UPDATE $table SET password=:pwd, must_change_password=0 WHERE id=:id");
        $ok = $upd->execute([':pwd' => $hash, ':id' => $userId]);
        if ($ok) {
            // reflect in session if present
            $_SESSION['must_change_password'] = 0;
            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update password.']);
        }
    } catch (Throwable $e) {
        error_log('Auth change_password error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit;
}

// Default: handle login via POST { userId, password, loginType }
if ($method === 'POST') {
    /**
     * User Login Endpoint
     * ===================
     * Authenticates users based on role (admin/employee/student).
     * Creates session upon successful authentication.
     * Returns user data for frontend use.
     */

    $userId = $data['userId'] ?? '';
    $password = $data['password'] ?? '';
    $loginType = $data['loginType'] ?? '';

    // DEBUG: Log API request
    error_log("DEBUG api/auth.php: API login request - userId=$userId, loginType=$loginType, password_length=" . strlen($password));

    $auth = new Auth();
    $user = $auth->login($userId, $password, $loginType);

    // DEBUG: Log API result
    error_log("DEBUG api/auth.php: Auth->login returned: " . ($user ? 'SUCCESS' : 'FAILED'));

    if ($user) {
        loginUser($user);
        error_log("DEBUG api/auth.php: User logged in via API - id=" . $user['id'] . ", role=" . $user['role']);
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        error_log("DEBUG api/auth.php: API login failed");
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} else {
    error_log("DEBUG api/auth.php: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>