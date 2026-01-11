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
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/utilities.php';
use PragmaRX\Google2FA\Google2FA;

// Utility: map session role to table
function _auth_table_by_role($role) {
    switch ($role) {
        case 'admin': return 'administrators';
        case 'employee': return 'employees';
        case 'student': return 'students';
        default: return null;
    }
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_role'] ?? 'system',
            $_SESSION['first_name'] ?? 'Unknown User',
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            null, // Set user_agent to null to avoid storing PII
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
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
    try {
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

    // Add new endpoint for 2FA verification
    if ($action === 'verify_2fa') {
        $userId = $data['user_id'] ?? '';
        $code = $data['code'] ?? '';
        $db = (new Database())->getConnection();
        
        if (!$userId || !$code) {
            echo json_encode(['success' => false, 'message' => 'Missing user_id or code']);
            exit();
        }
        
        // Fetch user and secret
        $user = null;
        $tables = ['administrators', 'employees', 'students'];
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                // Add role based on table
                if ($table === 'students') {
                    $user['role'] = 'student';
                } elseif ($table === 'employees') {
                    $user['role'] = 'employee';
                } elseif ($table === 'administrators') {
                    $user['role'] = 'admin';
                }
                break;
            }
        }
        
        if (!$user || empty($user['2fa_secret'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid user or 2FA not enabled']);
            exit();
        }
        
        $google2fa = new Google2FA();
        if ($google2fa->verifyKey($user['2fa_secret'], $code)) {
            // Reset attempts on success
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            loginUser($user);
            addAuditLog('LOGIN_2FA', 'Authentication', "User {$user['first_name']} {$user['last_name']} completed 2FA login", $user['id'], 'User', 'INFO');
            error_log("DEBUG api/auth.php: 2FA verification successful for user $userId, redirecting to " . ($user['role'] === 'admin' ? 'admin-dashboard.php' : 'event-calendar.php'));
            echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? 'admin-dashboard.php' : 'event-calendar.php')]);
        } else {
            error_log("DEBUG api/auth.php: 2FA verification failed for user $userId - invalid code");
            echo json_encode(['success' => false, 'message' => 'Invalid 2FA code']);
        }
        exit();
    }

    // Add 2FA setup endpoint
    if ($action === 'setup_2fa') {
        $userId = $data['user_id'] ?? '';
        $code = $data['code'] ?? '';
        $db = (new Database())->getConnection();
        
        if (!$userId || !$code) {
            echo json_encode(['success' => false, 'message' => 'Missing user_id or code']);
            exit();
        }
        
        // Fetch user
        $user = null;
        $tables = ['students', 'employees', 'administrators'];
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                // Add role based on table
                if ($table === 'students') {
                    $user['role'] = 'student';
                } elseif ($table === 'employees') {
                    $user['role'] = 'employee';
                } elseif ($table === 'administrators') {
                    $user['role'] = 'admin';
                }
                break;
            }
        }
        
        if (!$user || empty($user['2fa_secret'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid user or 2FA not configured']);
            exit();
        }
        
        $google2fa = new Google2FA();
        if ($google2fa->verifyKey($user['2fa_secret'], $code)) {
            // Mark as enabled
            $stmt = $db->prepare("UPDATE $table SET 2fa_enabled = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            loginUser($user);
            addAuditLog('2FA_SETUP', 'Authentication', "User {$user['first_name']} {$user['last_name']} set up 2FA", $user['id'], 'User', 'INFO');
            error_log("DEBUG api/auth.php: 2FA setup successful for user $userId, redirecting to " . ($user['role'] === 'admin' ? 'admin-dashboard.php' : 'event-calendar.php'));
            echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? 'admin-dashboard.php' : 'event-calendar.php')]);
        } else {
            error_log("DEBUG api/auth.php: 2FA setup failed for user $userId - invalid code");
            echo json_encode(['success' => false, 'message' => 'Invalid 2FA code']);
        }
        exit();
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
            error_log("DEBUG api/auth.php: User authenticated successfully: {$user['id']} ({$user['role']}), 2fa_secret=" . ($user['2fa_secret'] ?? 'NULL') . ", 2fa_enabled=" . ($user['2fa_enabled'] ?? 'NULL'));
            
            // Check if user has 2FA secret
            if (!empty($user['2fa_secret'])) {
                if ($user['2fa_enabled'] == 1) {
                    // 2FA is set up, require code
                    error_log("DEBUG api/auth.php: User has 2FA enabled, requiring verification");
                    echo json_encode(['success' => true, 'requires_2fa' => true, 'user_id' => $user['id']]);
                } else {
                    // 2FA secret exists but not set up - prompt for setup
                    error_log("DEBUG api/auth.php: User has 2FA secret but not enabled, prompting setup");
                    echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $user['2fa_secret']]);
                }
            } else {
                // No 2FA - proceed (or enforce for students)
                if ($user['role'] === 'student') {
                    // Generate secret for students
                    error_log("DEBUG api/auth.php: Student without 2FA secret, generating one");
                    $google2fa = new Google2FA();
                    $secret = $google2fa->generateSecretKey();
                    $stmt = $db->prepare("UPDATE students SET 2fa_secret = ? WHERE id = ?");
                    $stmt->execute([$secret, $user['id']]);
                    error_log("DEBUG api/auth.php: Generated secret for student {$user['id']}: $secret");
                    echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $secret]);
                } else {
                    error_log("DEBUG api/auth.php: Non-student user without 2FA, proceeding with normal login");
                    loginUser($user);
                    addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in", $user['id'], 'User', 'INFO');
                    echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? 'admin-dashboard.php' : 'event-calendar.php')]);
                }
            }
            exit();
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
    } catch (Exception $e) {
        error_log("ERROR api/auth.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);  // For debugging; remove in production
    }
} else {
    error_log("DEBUG api/auth.php: Invalid method: $method");
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>