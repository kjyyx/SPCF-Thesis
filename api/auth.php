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
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';
require_once ROOT_PATH . 'vendor/autoload.php';
require_once ROOT_PATH . 'includes/utilities.php';
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

// Helper function to check if 2FA is globally enabled
function is2FAEnabledGlobally() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_2fa'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['setting_value'] === '1';
    } catch (Exception $e) {
        error_log("Error checking 2FA setting: " . $e->getMessage());
        return false; // Default to disabled on error
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
            // Forgot password: Check user in all tables and send real email
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
                if ($user) break;
            }

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }

            // Generate 6-digit code
            $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store code securely in session with expiration (5 minutes)
            $_SESSION['forgot_password_code'] = password_hash($code, PASSWORD_DEFAULT); // Hash for security
            $_SESSION['forgot_password_user'] = $userId;
            $_SESSION['forgot_password_expires'] = time() + 300; // 5 minutes

            // Send email using Gmail SMTP
            require_once ROOT_PATH . 'vendor/autoload.php'; // Ensure PHPMailer is loaded
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'signumsystem2025@gmail.com';
                $mail->Password = 'kilm dprk lwou xhad';
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port = 465;

                // Recipients
                $mail->setFrom('signumsystem2025@gmail.com', 'Sign-um System');
                $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']); // Assuming first_name/last_name are available; adjust if needed

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Password Recovery - Sign-um System';
                
                // Professional HTML email template
                $currentYear = date('Y');
                
                // Embed the header image
                $mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Email_background.jpg', 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');
                
                $mail->Body = "
                <!DOCTYPE html>
                <html lang='en'>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <title>Password Recovery</title>
                </head>
                <body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f7fa;'>
                    <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f4f7fa;'>
                        <tr>
                            <td align='center' style='padding: 40px 0;'>
                                <!-- Main Container -->
                                <table role='presentation' style='width: 600px; max-width: 90%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                                    <!-- Header with Background Image -->
                                    <tr>
                                        <td style='padding: 0; text-align: center; border-radius: 8px 8px 0 0; overflow: hidden;'>
                                            <img src='cid:header_image' alt='Sign-um System' style='width: 100%; max-width: 700px; height: auto; display: block; margin: 0 auto;' />
                                        </td>
                                    </tr>
                                    
                                    <!-- Content -->
                                    <tr>
                                        <td style='padding: 40px 30px;'>
                                            <h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;'>Password Recovery Request</h2>
                                            
                                            <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                                Hello <strong>{$user['first_name']} {$user['last_name']}</strong>,
                                            </p>
                                            
                                            <p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
                                                We received a request to reset your password. Use the verification code below to proceed with resetting your password:
                                            </p>
                                            
                                            <!-- Verification Code Box -->
                                            <table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
                                                <tr>
                                                    <td align='center' style='background-color: #f8f9fa; border: 2px dashed #2a5298; border-radius: 6px; padding: 25px;'>
                                                        <p style='margin: 0 0 10px 0; color: #666666; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;'>Your Verification Code</p>
                                                        <p style='margin: 0; color: #1e3c72; font-size: 36px; font-weight: bold; letter-spacing: 8px; font-family: \"Courier New\", monospace;'>$code</p>
                                                    </td>
                                                </tr>
                                            </table>
                                            
                                            <!-- Important Notice -->
                                            <table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin: 20px 0;'>
                                                <tr>
                                                    <td style='padding: 15px;'>
                                                        <p style='margin: 0; color: #856404; font-size: 14px; line-height: 1.5;'>
                                                            <strong>⚠️ Important:</strong> This code will expire in <strong>5 minutes</strong>. If you did not request this password reset, please ignore this email and your password will remain unchanged.
                                                        </p>
                                                    </td>
                                                </tr>
                                            </table>
                                            
                                            <p style='color: #555555; line-height: 1.6; margin: 20px 0 0 0; font-size: 15px;'>
                                                For security reasons, never share this code with anyone. Our support team will never ask for your verification code.
                                            </p>
                                        </td>
                                    </tr>
                                    
                                    <!-- Footer -->
                                    <tr>
                                        <td style='background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;'>
                                            <p style='margin: 0 0 10px 0; color: #6c757d; font-size: 13px; line-height: 1.5;'>
                                                If you have any questions or need assistance, please contact our support team.
                                            </p>
                                            <p style='margin: 0; color: #6c757d; font-size: 12px;'>
                                                &copy; $currentYear Sign-um System. All rights reserved.<br>
                                                Systems Plus College Foundation | Angeles City
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <!-- Footer Text -->
                                <table role='presentation' style='width: 600px; max-width: 90%; margin-top: 20px;'>
                                    <tr>
                                        <td align='center'>
                                            <p style='color: #999999; font-size: 12px; line-height: 1.5; margin: 0;'>
                                                This is an automated message, please do not reply to this email.
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </body>
                </html>
                ";
                
                $mail->AltBody = "Password Recovery - Sign-um System\n\nHello {$user['first_name']} {$user['last_name']},\n\nWe received a request to reset your password. Your verification code is: $code\n\nThis code expires in 5 minutes. If you did not request this password reset, please ignore this email.\n\nFor security reasons, never share this code with anyone.\n\n© $currentYear Sign-um System. All rights reserved.\nSt. Paul College Foundation, Dumaguete City";

                $mail->send();
                error_log("Password recovery email sent to {$user['email']} for user $userId");

                // Mask email and phone for response (no demo code sent to client)
                $emailParts = explode('@', $user['email']);
                $maskedEmail = substr($emailParts[0], 0, 1) . str_repeat('*', strlen($emailParts[0]) - 1) . '@' . $emailParts[1];
                $phone = $user['phone'];
                $maskedPhone = substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 7) . substr($phone, -3);

                echo json_encode([
                    'success' => true,
                    'maskedEmail' => $maskedEmail,
                    'maskedPhone' => $maskedPhone
                ]);
            } catch (Exception $e) {
                error_log("Email sending failed: {$mail->ErrorInfo}");
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to send recovery email. Please try again.']);
            }
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
            echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
        } else {
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
            echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid 2FA code']);
        }
        exit();
    }

    // Add new endpoint for forgot password verification
    if ($action === 'verify_forgot_password') {
        $code = $data['code'] ?? '';
        if (!$code) {
            echo json_encode(['success' => false, 'message' => 'Code required']);
            exit();
        }

        // Check session data
        if (!isset($_SESSION['forgot_password_code']) || !isset($_SESSION['forgot_password_expires']) || !isset($_SESSION['forgot_password_user'])) {
            echo json_encode(['success' => false, 'message' => 'No active password reset session']);
            exit();
        }

        // Check expiration
        if (time() > $_SESSION['forgot_password_expires']) {
            unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
            echo json_encode(['success' => false, 'message' => 'Code expired']);
            exit();
        }

        // Verify code
        if (!password_verify($code, $_SESSION['forgot_password_code'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid code']);
            exit();
        }

        // Success - clear session and allow password reset
        unset($_SESSION['forgot_password_code'], $_SESSION['forgot_password_user'], $_SESSION['forgot_password_expires']);
        echo json_encode(['success' => true, 'message' => 'Code verified for password reset']);
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

    if ($user) {
        $global2FAEnabled = is2FAEnabledGlobally();
            
            // Check if user has 2FA secret
            if (!empty($user['2fa_secret'])) {
                if ($user['2fa_enabled'] == 1 && $global2FAEnabled) {
                    // 2FA is set up and globally enabled, require code
                    echo json_encode(['success' => true, 'requires_2fa' => true, 'user_id' => $user['id']]);
                } elseif ($user['2fa_enabled'] == 1 && !$global2FAEnabled) {
                    // 2FA is set up but globally disabled, skip verification
                    loginUser($user);
                    addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA bypassed for development)", $user['id'], 'User', 'INFO');
                    echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                } else {
                    // 2FA secret exists but not set up - prompt for setup if globally enabled
                    if ($global2FAEnabled) {
                        echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $user['2fa_secret']]);
                    } else {
                        // Proceed without 2FA
                        loginUser($user);
                        addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                        echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
                    }
                }
            } else {
                // No 2FA secret - generate and prompt setup if globally enabled
                if ($global2FAEnabled) {
                    $google2fa = new Google2FA();
                    $secret = $google2fa->generateSecretKey();
                    $table = _auth_table_by_role($user['role']);
                    $stmt = $db->prepare("UPDATE $table SET 2fa_secret = ? WHERE id = ?");
                    $stmt->execute([$secret, $user['id']]);
                    echo json_encode(['success' => true, 'requires_2fa_setup' => true, 'user_id' => $user['id'], 'secret' => $secret]);
                } else {
                    // Proceed without 2FA
                    loginUser($user);
                    addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in (2FA disabled)", $user['id'], 'User', 'INFO');
                    echo json_encode(['success' => true, 'redirect' => ($user['role'] === 'admin' ? BASE_URL . '?page=dashboard' : BASE_URL . '?page=calendar')]);
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