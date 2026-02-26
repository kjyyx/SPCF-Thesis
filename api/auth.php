<?php
/**
 * Authentication API
 * ==================
 *
 * Handles user authentication operations including:
 * - User login with role-based access (POST)
 * - Password changes for authenticated users (POST action=change_password or PUT)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'vendor/autoload.php';
require_once ROOT_PATH . 'includes/utilities.php';

// IMPORTANT: Include our newly separated mailer
require_once ROOT_PATH . 'includes/mailer.php';

use PragmaRX\Google2FA\Google2FA;

// Utility: map session role to table
function _auth_table_by_role($role)
{
    switch ($role) {
        case 'admin':
            return 'administrators';
        case 'employee':
            return 'employees';
        case 'student':
            return 'students';
        default:
            return null;
    }
}

// Helper function to check if 2FA is globally enabled
function is2FAEnabledGlobally()
{
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_2fa'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['setting_value'] === '1';
    } catch (Exception $e) {
        error_log("Error checking 2FA setting: " . $e->getMessage());
        return false;
    }
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
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
            $severity ?? 'INFO',
            $_SERVER['REMOTE_ADDR'] ?? null,
            null,
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
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';

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
                $stmt = $db->prepare("SELECT email, phone, first_name, last_name FROM $table WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user)
                    break;
            }

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }

            // Generate 6-digit code
            $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store code securely in session with expiration (5 minutes)
            $_SESSION['forgot_password_code'] = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['forgot_password_user'] = $userId;
            $_SESSION['forgot_password_expires'] = time() + 300;

            // ==========================================
            // CLEAN CALL TO MAILER INSTEAD OF HUGE BLOCK
            // ==========================================
            $userName = $user['first_name'] . ' ' . $user['last_name'];
            $emailSent = sendPasswordResetEmail($user['email'], $userName, $code);

            if ($emailSent) {
                error_log("Password recovery email sent to {$user['email']} for user $userId");

                // Mask email and phone for response 
                $emailParts = explode('@', $user['email']);
                $username = $emailParts[0] ?? '';
                $maskedUsername = strlen($username) > 1 ? substr($username, 0, 1) . str_repeat('*', max(0, strlen($username) - 1)) : $username;
                $maskedEmail = $maskedUsername . '@' . ($emailParts[1] ?? '');

                $phone = $user['phone'] ?? '';
                $phoneLen = strlen($phone);
                if ($phoneLen > 7) {
                    $maskedPhone = substr($phone, 0, 4) . str_repeat('*', max(0, $phoneLen - 7)) . substr($phone, -3);
                } else {
                    $maskedPhone = $phone;
                }

                echo json_encode([
                    'success' => true,
                    'maskedEmail' => $maskedEmail,
                    'maskedPhone' => $maskedPhone
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to send recovery email. Please try again.']);
            }
            exit;
        }

        if ($action === 'reset_password') {
            $userId = $data['userId'] ?? '';
            $newPassword = $data['newPassword'] ?? '';

            if (!$userId || !$newPassword) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID and new password required']);
                exit;
            }

            $pattern = '/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/';
            if (!preg_match($pattern, $newPassword)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Password does not meet complexity requirements.']);
                exit;
            }

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
                unset($_SESSION['forgot_password_code']);
                unset($_SESSION['forgot_password_user']);
                echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found or update failed.']);
            }
            exit;
        }

        if ($action === 'verify_2fa') {
            $userId = $data['user_id'] ?? '';
            $code = $data['code'] ?? '';
            $db = (new Database())->getConnection();

            if (!$userId || !$code) {
                echo json_encode(['success' => false, 'message' => 'Missing user_id or code']);
                exit();
            }

            $user = null;
            $tables = ['administrators', 'employees', 'students'];
            foreach ($tables as $table) {
                $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
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
                $stmt = $db->prepare("DELETE FROM login_attempts WHERE user_id = ?");
                $stmt->execute([$userId]);

                loginUser($user);
                addAuditLog('LOGIN_2FA', 'Authentication', "User {$user['first_name']} {$user['last_name']} completed 2FA login", $user['id'], 'User', 'INFO');
                echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid 2FA code']);
            }
            exit();
        }

        if ($action === 'setup_2fa') {
            $userId = $data['user_id'] ?? '';
            $code = $data['code'] ?? '';
            $db = (new Database())->getConnection();

            if (!$userId || !$code) {
                echo json_encode(['success' => false, 'message' => 'Missing user_id or code']);
                exit();
            }

            $user = null;
            $tables = ['students', 'employees', 'administrators'];
            foreach ($tables as $table) {
                $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
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
                $stmt = $db->prepare("UPDATE $table SET 2fa_enabled = 1 WHERE id = ?");
                $stmt->execute([$userId]);
                loginUser($user);
                addAuditLog('2FA_SETUP', 'Authentication', "User {$user['first_name']} {$user['last_name']} set up 2FA", $user['id'], 'User', 'INFO');
                echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid 2FA code']);
            }
            exit();
        }

        if ($action === 'verify_forgot_password') {
            $code = $data['code'] ?? '';
            if (!$code) {
                echo json_encode(['success' => false, 'message' => 'Code required']);
                exit();
            }

            if (!isset($_SESSION['forgot_password_code']) || !isset($_SESSION['forgot_password_expires']) || !isset($_SESSION['forgot_password_user'])) {
                echo json_encode(['success' => false, 'message' => 'No active password reset session']);
                exit();
            }

            if (time() > $_SESSION['forgot_password_expires']) {
                unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
                echo json_encode(['success' => false, 'message' => 'Code expired']);
                exit();
            }

            if (!password_verify($code, $_SESSION['forgot_password_code'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid code']);
                exit();
            }

            unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
            echo json_encode(['success' => true, 'message' => 'Code verified for password reset']);
            exit();
        }

        // --- Core Login Logic ---
        $userId = $data['userId'] ?? '';
        $password = $data['password'] ?? '';
        $loginType = $data['loginType'] ?? '';

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

        if ($user) {
            $global2FAEnabled = is2FAEnabledGlobally();

            if (!empty($user['2fa_secret'])) {
                if ($user['2fa_enabled'] == 1 && $global2FAEnabled) {
                    echo json_encode(['success' => true, 'requires_2fa' => true, 'user_id' => $user['id']]);
                } elseif ($user['2fa_enabled'] == 1 && !$global2FAEnabled) {
                    loginUser($user);
                    addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA bypassed)", $user['id'], 'User', 'INFO');
                    echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                } else {
                    if ($global2FAEnabled) {
                        echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $user['2fa_secret']]);
                    } else {
                        loginUser($user);
                        addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                        echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                    }
                }
            } else {
                if ($global2FAEnabled) {
                    $google2fa = new Google2FA();
                    $secret = $google2fa->generateSecretKey();
                    $table = _auth_table_by_role($user['role']);
                    $stmt = $db->prepare("UPDATE $table SET 2fa_secret = ? WHERE id = ?");
                    $stmt->execute([$secret, $user['id']]);
                    echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $secret]);
                } else {
                    loginUser($user);
                    addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                    echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                }
            }
            exit();
        } else {
            $attempts = ($attemptData ? $attemptData['attempts'] : 0) + 1;
            $lockedUntil = null;
            if ($attempts >= 5) {
                $lockedUntil = $now->add(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
                $attempts = 0;
            }
            $stmt = $db->prepare("INSERT INTO login_attempts (user_id, attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?");
            $stmt->execute([$userId, $attempts, $lockedUntil, $attempts, $lockedUntil]);

            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
    } catch (Exception $e) {
        error_log("ERROR api/auth.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>