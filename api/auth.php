<?php
/**
 * Authentication API
 * ==================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'vendor/autoload.php';
require_once ROOT_PATH . 'includes/utilities.php';
use PragmaRX\Google2FA\Google2FA;

// ------------------------------------------------------------------
// Helper Functions
// ------------------------------------------------------------------
function findUserAcrossTables($pdo, $userId)
{
    $tables = ['administrators' => 'admin', 'employees' => 'employee', 'students' => 'student'];
    foreach ($tables as $table => $role) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$userId]);
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user['role'] = $role;
            $user['table'] = $table;
            return $user;
        }
    }
    return null;
}

function isPasswordComplex($password)
{
    return preg_match('/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/', $password);
}

function _auth_table_by_role($role)
{
    return match ($role) {
        'admin' => 'administrators',
        'employee' => 'employees',
        'student' => 'students',
        default => null,
    };
}

function is2FAEnabledGlobally($pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_2fa'");
        $stmt->execute();
        return ($stmt->fetchColumn() === '1');
    } catch (Exception $e) {
        error_log("Error checking 2FA setting: " . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------------
// Request Handling
// ------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

// Initialize DB once for all operations
$pdo = (new Database())->getConnection();

// --- PUT / Password Change Override ---
if ($method === 'PUT' || ($method === 'POST' && $action === 'change_password')) {
    if (!isLoggedIn())
        sendJsonResponse(false, 'Not authenticated', 401);

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';

    if (!isPasswordComplex($newPassword)) {
        sendJsonResponse(false, 'Password does not meet complexity requirements.', 400);
    }

    $userId = $_SESSION['user_id'] ?? '';
    $table = _auth_table_by_role($_SESSION['user_role'] ?? '');

    if (!$userId || !$table)
        sendJsonResponse(false, 'Invalid session state', 400);

    try {
        $stmt = $pdo->prepare("SELECT password FROM $table WHERE id=:id");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($currentPassword, $row['password'])) {
            sendJsonResponse(false, 'Current password is incorrect.', 400);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE $table SET password=:pwd, must_change_password=0 WHERE id=:id");
        if ($upd->execute([':pwd' => $hash, ':id' => $userId])) {
            $_SESSION['must_change_password'] = 0;
            sendJsonResponse(true, 'Password updated successfully.');
        }
        sendJsonResponse(false, 'Failed to update password.', 500);
    } catch (Throwable $e) {
        error_log('Auth change_password error: ' . $e->getMessage());
        sendJsonResponse(false, 'Server error', 500);
    }
}

// --- POST Actions ---
if ($method === 'POST') {
    try {
        switch ($action) {
            case 'forgot_password':
                $userId = $data['userId'] ?? '';
                if (!$userId)
                    sendJsonResponse(false, 'User ID required', 400);

                $user = findUserAcrossTables($pdo, $userId);
                if (!$user)
                    sendJsonResponse(false, 'User not found', 404);

                $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['forgot_password_code'] = password_hash($code, PASSWORD_DEFAULT);
                $_SESSION['forgot_password_user'] = $userId;
                $_SESSION['forgot_password_expires'] = time() + 300;

                // --- NEW MAILER IMPLEMENTATION ---
                require_once ROOT_PATH . 'includes/Mailer.php';
                $mailer = new Mailer();

                $sent = $mailer->send(
                    $user['email'],
                    $user['first_name'] . ' ' . $user['last_name'],
                    'Password Recovery - Sign-um System',
                    'password_recovery',
                    [
                        'user' => $user,
                        'code' => $code
                    ]
                );

                if ($sent) {
                    // Mask info for client
                    $emailParts = explode('@', $user['email']);
                    $username = $emailParts[0] ?? '';
                    $maskedEmail = (strlen($username) > 1 ? substr($username, 0, 1) . str_repeat('*', max(0, strlen($username) - 1)) : $username) . '@' . ($emailParts[1] ?? '');

                    $phone = $user['phone'] ?? '';
                    $maskedPhone = strlen($phone) > 7 ? substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 7) . substr($phone, -3) : $phone;

                    sendJsonResponse(true, ['maskedEmail' => $maskedEmail, 'maskedPhone' => $maskedPhone]);
                } else {
                    sendJsonResponse(false, 'Failed to send recovery email. Please try again.', 500);
                }
                break;

            case 'verify_forgot_password':
                $code = $data['code'] ?? '';
                if (!$code)
                    sendJsonResponse(false, 'Code required', 400);
                if (!isset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_expires'], $_SESSION['forgot_password_user'])) {
                    sendJsonResponse(false, 'No active password reset session', 400);
                }
                if (time() > $_SESSION['forgot_password_expires']) {
                    unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
                    sendJsonResponse(false, 'Code expired', 400);
                }
                if (!password_verify($code, $_SESSION['forgot_password_code'])) {
                    sendJsonResponse(false, 'Invalid code', 400);
                }

                unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
                sendJsonResponse(true, 'Code verified for password reset');
                break;

            case 'reset_password':
                $userId = $data['userId'] ?? '';
                $newPassword = $data['newPassword'] ?? '';

                if (!$userId || !$newPassword)
                    sendJsonResponse(false, 'User ID and new password required', 400);
                if (!isPasswordComplex($newPassword))
                    sendJsonResponse(false, 'Password does not meet complexity requirements.', 400);

                $user = findUserAcrossTables($pdo, $userId);
                if (!$user)
                    sendJsonResponse(false, 'User not found or update failed.', 404);

                $stmt = $pdo->prepare("UPDATE {$user['table']} SET password = ?, must_change_password = 0 WHERE id = ?");
                $stmt->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

                unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user']);
                sendJsonResponse(true, 'Password updated successfully.');
                break;

            case 'verify_2fa':
            case 'setup_2fa':
                $userId = $data['user_id'] ?? '';
                $code = $data['code'] ?? '';

                if (!$userId || !$code)
                    sendJsonResponse(false, 'Missing user_id or code', 400);

                $user = findUserAcrossTables($pdo, $userId);
                if (!$user || empty($user['2fa_secret']))
                    sendJsonResponse(false, 'Invalid user or 2FA not configured', 400);

                $google2fa = new Google2FA();
                if ($google2fa->verifyKey($user['2fa_secret'], $code)) {
                    if ($action === 'verify_2fa') {
                        $pdo->prepare("DELETE FROM login_attempts WHERE user_id = ?")->execute([$userId]);
                        $logMsg = "User {$user['first_name']} {$user['last_name']} completed 2FA login";
                    } else {
                        $pdo->prepare("UPDATE {$user['table']} SET 2fa_enabled = 1 WHERE id = ?")->execute([$userId]);
                        $logMsg = "User {$user['first_name']} {$user['last_name']} set up 2FA";
                    }

                    loginUser($user);
                    addAuditLog($pdo, strtoupper($action), 'Authentication', $logMsg, $user['id'], 'User', 'INFO');

                    sendJsonResponse(true, ['redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                }
                sendJsonResponse(false, 'Invalid 2FA code', 400);
                break;

            default: // Standard Login
                $userId = $data['userId'] ?? '';
                $password = $data['password'] ?? '';
                $loginType = $data['loginType'] ?? '';

                // Brute force check
                $stmt = $pdo->prepare("SELECT attempts, locked_until FROM login_attempts WHERE user_id = ?");
                $stmt->execute([$userId]);
                $attemptData = $stmt->fetch(PDO::FETCH_ASSOC);

                $now = new DateTime();
                if ($attemptData && $attemptData['locked_until'] && new DateTime($attemptData['locked_until']) > $now) {
                    $remaining = $now->diff(new DateTime($attemptData['locked_until']));
                    sendJsonResponse(false, ['message' => "Too many failed attempts. Try again in {$remaining->i}m {$remaining->s}s.", 'cooldown' => true], 429);
                }

                $auth = new Auth();
                $user = $auth->login($userId, $password, $loginType);

                if ($user) {
                    $global2FAEnabled = is2FAEnabledGlobally($pdo);
                    $redirectUrl = ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar');

                    if (!empty($user['2fa_secret'])) {
                        if ($user['2fa_enabled'] == 1 && $global2FAEnabled) {
                            sendJsonResponse(true, ['requires_2fa' => true, 'user_id' => $user['id']]);
                        } elseif ($user['2fa_enabled'] == 1 && !$global2FAEnabled) {
                            loginUser($user);
                            addAuditLog($pdo, 'LOGIN', 'Authentication', "User {$user['first_name']} logged in (2FA bypassed)", $user['id'], 'User', 'INFO');
                            sendJsonResponse(true, ['redirect' => $redirectUrl]);
                        } else {
                            if ($global2FAEnabled) {
                                sendJsonResponse(true, ['requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $user['2fa_secret']]);
                            } else {
                                loginUser($user);
                                addAuditLog($pdo, 'LOGIN', 'Authentication', "User {$user['first_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                                sendJsonResponse(true, ['redirect' => $redirectUrl]);
                            }
                        }
                    } else {
                        if ($global2FAEnabled) {
                            $secret = (new Google2FA())->generateSecretKey();
                            $table = _auth_table_by_role($user['role']);
                            $pdo->prepare("UPDATE $table SET 2fa_secret = ? WHERE id = ?")->execute([$secret, $user['id']]);
                            sendJsonResponse(true, ['requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $secret]);
                        } else {
                            loginUser($user);
                            addAuditLog($pdo, 'LOGIN', 'Authentication', "User {$user['first_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                            sendJsonResponse(true, ['redirect' => $redirectUrl]);
                        }
                    }
                } else {
                    $attempts = ($attemptData ? $attemptData['attempts'] : 0) + 1;
                    $lockedUntil = null;
                    if ($attempts >= 5) {
                        $lockedUntil = $now->add(new DateInterval('PT1M'))->format('Y-m-d H:i:s');
                        $attempts = 0;
                    }
                    $stmt = $pdo->prepare("INSERT INTO login_attempts (user_id, attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?");
                    $stmt->execute([$userId, $attempts, $lockedUntil, $attempts, $lockedUntil]);
                    sendJsonResponse(false, 'Invalid credentials', 401);
                }
                break;
        }
    } catch (Exception $e) {
        error_log("ERROR api/auth.php: " . $e->getMessage());
        sendJsonResponse(false, 'Server error: ' . $e->getMessage(), 500);
    }
} else {
    sendJsonResponse(false, 'Invalid request method', 405);
}

// ------------------------------------------------------------------
// Template Generators
// ------------------------------------------------------------------

function getPasswordRecoveryEmailTemplate($user, $code)
{
    // 1. Capture the specific content
    ob_start();
    include ROOT_PATH . 'assets/templates/emails/password_recovery.php';
    $content = ob_get_clean();

    // 2. Inject it into the master layout
    ob_start();
    include ROOT_PATH . 'assets/templates/emails/layout.php';
    return ob_get_clean();
}