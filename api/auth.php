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
    $action = $data['action'] ?? '';

    if ($action === 'forgot_password') {
        // Demo forgot password: Check user in all tables
        $userId = $data['userId'] ?? '';
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID required']);
            exit;
        }

        $db = (new Database())->getConnection();
        $user = null;
        $tables = ['students', 'employees', 'administrators'];

        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT email, phone FROM $table WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) break;
        }

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        // Generate 6-digit code
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store in session for demo verification
        $_SESSION['forgot_password_code'] = $code;
        $_SESSION['forgot_password_user'] = $userId;

        // Mask email and phone for response
        $emailParts = explode('@', $user['email']);
        $maskedEmail = substr($emailParts[0], 0, 1) . str_repeat('*', strlen($emailParts[0]) - 1) . '@' . $emailParts[1];
        $phone = $user['phone'];
        $maskedPhone = substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 7) . substr($phone, -3);

        // Log "sent" code for demo
        error_log("DEMO: Password reset code $code 'sent' to {$user['email']} for user $userId");

        echo json_encode([
            'success' => true,
            'maskedEmail' => $maskedEmail,
            'maskedPhone' => $maskedPhone,
            'demoCode' => $code // Include for demo display
        ]);
        exit;
    }

    if ($action === 'reset_password') {
        // Demo reset password: Update password for the user
        $userId = $data['userId'] ?? '';
        $newPassword = $data['newPassword'] ?? '';

        if (!$userId || !$newPassword) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID and new password required']);
            exit;
        }

        // Enforce password policy
        $pattern = '/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/';
        if (!preg_match($pattern, $newPassword)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Password does not meet complexity requirements.']);
            exit;
        }

        // Check if user exists and update
        $tables = ['students', 'employees', 'administrators'];
        $updated = false;
        $db = (new Database())->getConnection();

        foreach ($tables as $table) {
            $stmt = $db->prepare("UPDATE $table SET password = ?, must_change_password = 0 WHERE id = ?");
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $result = $stmt->execute([$hash, $userId]);
            if ($result && $stmt->rowCount() > 0) {
                $updated = true;
                break;
            }
        }

        if ($updated) {
            // Clear session
            unset($_SESSION['forgot_password_code']);
            unset($_SESSION['forgot_password_user']);
            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found or update failed.']);
        }
        exit;
    }

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

    // Check for brute force cooldown
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT attempts, locked_until FROM login_attempts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $attemptData = $stmt->fetch(PDO::FETCH_ASSOC);

    $now = new DateTime();
    if ($attemptData && $attemptData['locked_until'] && new DateTime($attemptData['locked_until']) > $now) {
        $remaining = $now->diff(new DateTime($attemptData['locked_until']));
        $minutes = $remaining->i;
        $seconds = $remaining->s;
        echo json_encode(['success' => false, 'message' => "Too many failed attempts. Try again in {$minutes}m {$seconds}s.", 'cooldown' => true]);
        exit;
    }

    $auth = new Auth();
    $user = $auth->login($userId, $password, $loginType);

    // DEBUG: Log API result
    error_log("DEBUG api/auth.php: Auth->login returned: " . ($user ? 'SUCCESS' : 'FAILED'));

    if ($user) {
        // Reset attempts on success
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE user_id = ?");
        $stmt->execute([$userId]);

        loginUser($user);
        error_log("DEBUG api/auth.php: User logged in via API - id=" . $user['id'] . ", role=" . $user['role']);
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        // Increment attempts on failure
        $attempts = ($attemptData ? $attemptData['attempts'] : 0) + 1;
        $lockedUntil = null;
        if ($attempts >= 5) {
            $lockedUntil = $now->add(new DateInterval('PT1M'))->format('Y-m-d H:i:s'); // 1 minute lock
            $attempts = 0; // Reset after lock
        }
        $stmt = $db->prepare("INSERT INTO login_attempts (user_id, attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?");
        $stmt->execute([$userId, $attempts, $lockedUntil, $attempts, $lockedUntil]);

        error_log("DEBUG api/auth.php: API login failed");
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
} else {
    error_log("DEBUG api/auth.php: Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>