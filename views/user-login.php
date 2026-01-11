<?php
// Use absolute paths
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/database.php';

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            'system', // Not logged in yet
            'Unknown User', // Since user not logged in yet
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

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['user_role'];
    if ($role === 'admin') {
        header('Location: admin-dashboard.php');
    } else {
        header('Location: event-calendar.php');
    }
    exit();
}

$error = '';
$userId = '';
$errorMessage = '';
$successMessage = '';
$requires2fa = false;
$requires2faSetup = false;
$twoFactorSecret = '';
$twoFactorUserId = '';

// Process login form if submitted - DISABLED: Using JavaScript API instead
// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $userId = $_POST['userId'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($userId) && !empty($password)) {
        // Automatic user role detection based on user ID prefix
        $loginType = '';
        if (substr($userId, 0, 3) === 'ADM') {
            $loginType = 'admin';
        } elseif (substr($userId, 0, 3) === 'EMP') {
            $loginType = 'employee';
        } elseif (substr($userId, 0, 3) === 'STU') {
            $loginType = 'student';
        } else {
            $error = 'Invalid User ID format. User ID must start with ADM, EMP, or STU.';
        }

        if ($loginType) {
            // Check for brute force cooldown
            $db = new Database();
            $conn = $db->getConnection();
            $stmt = $conn->prepare("SELECT attempts, locked_until FROM login_attempts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $attemptData = $stmt->fetch(PDO::FETCH_ASSOC);

            $now = new DateTime();
            if ($attemptData && $attemptData['locked_until'] && new DateTime($attemptData['locked_until']) > $now) {
                $remaining = $now->diff(new DateTime($attemptData['locked_until']));
                $minutes = $remaining->i;
                $seconds = $remaining->s;
                $error = "Too many failed attempts. Try again in {$minutes}m {$seconds}s.";
            } else {
                $auth = new Auth();
                $user = $auth->login($userId, $password, $loginType);

                if ($user) {
                    // Check 2FA status
                    if (!empty($user['2fa_secret'])) {
                        if ($user['2fa_enabled'] == 1) {
                            // 2FA is set up, require code
                            $requires2fa = true;
                            $twoFactorUserId = $user['id'];
                        } else {
                            // 2FA secret exists but not set up - prompt for setup
                            $requires2faSetup = true;
                            $twoFactorUserId = $user['id'];
                            $twoFactorSecret = $user['2fa_secret'];
                        }
                    } else {
                        // No 2FA - proceed (or enforce for students)
                        if ($user['role'] === 'student') {
                            // Generate secret for students
                            require_once __DIR__ . '/../vendor/autoload.php';
                            $google2fa = new PragmaRX\Google2FA\Google2FA();
                            $secret = $google2fa->generateSecretKey();
                            $stmt = $conn->prepare("UPDATE students SET 2fa_secret = ? WHERE id = ?");
                            $stmt->execute([$secret, $user['id']]);
                            $requires2faSetup = true;
                            $twoFactorUserId = $user['id'];
                            $twoFactorSecret = $secret;
                        } else {
                            // Normal login for non-students
                            loginUser($user);
                            addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in", $user['id'], 'User', 'INFO');

                            // Reset attempts on success
                            $stmt = $conn->prepare("DELETE FROM login_attempts WHERE user_id = ?");
                            $stmt->execute([$userId]);

                            // Redirect based on role
                            if ($user['role'] === 'admin') {
                                header('Location: admin-dashboard.php');
                            } else {
                                header('Location: event-calendar.php');
                            }
                            exit();
                        }
                    }

                    // Reset attempts on success (for 2FA cases)
                    if ($requires2fa || $requires2faSetup) {
                        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE user_id = ?");
                        $stmt->execute([$userId]);
                    }
                } else {
                    // Login failed
                    $error = 'Invalid credentials. Please try again.';

                    // ADD AUDIT LOG FOR FAILED LOGIN
                    addAuditLog('LOGIN_FAILED', 'Security', "Failed login attempt for user: {$userId}", null, 'User', 'WARNING');

                    // Increment attempts on failure
                    $attempts = ($attemptData ? $attemptData['attempts'] : 0) + 1;
                    $lockedUntil = null;
                    if ($attempts >= 5) {
                        $lockedUntil = $now->add(new DateInterval('PT1M'))->format('Y-m-d H:i:s'); // 1 minute lock
                        $attempts = 0; // Reset after lock
                    }
                    $stmt = $conn->prepare("INSERT INTO login_attempts (user_id, attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?");
                    $stmt->execute([$userId, $attempts, $lockedUntil, $attempts, $lockedUntil]);
                }
            }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
// }
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/user-login.css">
    <script src="../assets/js/user-login.js"></script>
</head>

<body>
    <div class="login-container">
        <!-- Login Header -->
        <div class="login-header">
            <div class="login-icon">
                <i class="bi bi-calendar-event"></i>
            </div>
            <h2>Sign-um Document Portal</h2>
            <p>Event Management System</p>
        </div>

        <!-- Login Body -->
        <div class="login-body">
            <form id="loginForm">
                <input type="hidden" id="requires2fa" value="<?php echo $requires2fa ? 'true' : 'false'; ?>">
                <input type="hidden" id="requires2faSetup" value="<?php echo $requires2faSetup ? 'true' : 'false'; ?>">
                <input type="hidden" id="twoFactorSecret" value="<?php echo htmlspecialchars($twoFactorSecret); ?>">
                <input type="hidden" id="twoFactorUserId" value="<?php echo htmlspecialchars($twoFactorUserId); ?>">
                <!-- Show error if exists -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- User ID -->
                <div class="form-group">
                    <label for="userId" class="form-label">
                        <i class="bi bi-person"></i>User ID
                    </label>
                    <input type="text" class="form-control" id="userId" name="userId" placeholder="Enter your ID"
                        required value="<?= isset($_POST['userId']) ? htmlspecialchars($_POST['userId']) : '' ?>">
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock"></i>Password
                    </label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="bi bi-eye" id="passwordIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- 2FA elements moved to modals below -->

                <!-- Error/Success Message -->
                <div id="loginError" class="alert alert-danger d-none" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span id="loginErrorMessage"></span>
                </div>
                <div id="loginSuccess" class="alert alert-success d-none" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <span id="loginSuccessMessage"></span>
                </div>

                <!-- Login Button -->
                <button type="submit" class="login-btn" id="loginButton">
                    <i class="bi bi-box-arrow-in-right"></i>Sign In
                </button>

                <!-- Forgot Password -->
                <div class="forgot-password">
                    <button type="button" onclick="openForgotPassword()">
                        <i class="bi bi-question-circle"></i>
                        Forgot Password?
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2FA Verification Modal -->
    <div class="modal fade" id="2faVerificationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-shield-lock-fill me-2"></i>Two-Factor Authentication
                    </h5>
                </div>
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <div class="step-icon bg-primary mx-auto">
                            <i class="bi bi-shield-check" style="font-size: 2.5rem; color: white;"></i>
                        </div>
                    </div>
                    <h5 class="mb-2 fw-bold" style="color: #1f2937;">Enter Authentication Code</h5>
                    <p class="text-muted mb-4" style="font-size: 0.95rem;">Open your authenticator app and enter the 6-digit code</p>
                    
                    <div class="mb-4 px-md-5">
                        <input type="text" class="form-control form-control-lg text-center" id="2faCode" 
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" 
                               inputmode="numeric" autocomplete="one-time-code">
                    </div>

                    <div id="2faVerifyError" class="alert alert-danger d-none mx-md-4" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <span id="2faVerifyErrorMessage"></span>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-lg btn-primary px-5" id="verify2faBtn">
                        <i class="bi bi-check-circle me-2"></i>Verify Code
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2FA Setup Modal -->
    <div class="modal fade" id="2faSetupModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-shield-plus me-2"></i>Set Up Two-Factor Authentication
                    </h5>
                </div>
                <div class="modal-body py-4">
                    <div class="row g-0">
                        <div class="col-md-6 text-center border-end">
                            <div class="px-3">
                                <h6 class="mb-3"><i class="bi bi-qr-code me-2"></i>Step 1: Scan QR Code</h6>
                                <p class="text-muted small mb-3">Use your authenticator app to scan this code</p>
                                <div id="qrCodeContainer" class="mx-auto"></div>
                                <div class="mt-3">
                                    <p class="text-muted small mb-2" style="font-size: 0.8rem;"><i class="bi bi-phone me-1"></i>Recommended Apps:</p>
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">Google Authenticator</span>
                                        <span class="badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">Microsoft Authenticator</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="px-3">
                                <h6 class="mb-3"><i class="bi bi-123 me-2"></i>Step 2: Verify Code</h6>
                                <p class="text-muted small mb-3">After scanning, enter the 6-digit code from your app</p>
                                <div class="mb-3">
                                    <input type="text" class="form-control form-control-lg text-center" id="2faSetupCode" 
                                           placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                           inputmode="numeric" autocomplete="one-time-code">
                                </div>

                                <div id="2faSetupError" class="alert alert-danger d-none" role="alert">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <span id="2faSetupErrorMessage"></span>
                                </div>

                                <div class="alert alert-info border-0">
                                    <div class="d-flex align-items-start">
                                        <i class="bi bi-info-circle me-2 mt-1"></i>
                                        <small style="line-height: 1.6;">This adds an extra layer of security to your account. You'll need your authenticator app each time you log in.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4 pt-3">
                    <button type="button" class="btn btn-lg btn-success px-5" id="complete2faSetupBtn">
                        <i class="bi bi-check-circle me-2"></i>Complete Setup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2"></i>Password Recovery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Step 1: User ID Input -->
                    <div id="forgotPasswordStep1">
                        <div class="step-content">
                            <div class="step-icon bg-warning bg-opacity-10 text-warning">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <h6 class="step-title">Forgot Your Password?</h6>
                            <p class="step-description">Enter your User ID to begin the password recovery process</p>
                        </div>

                        <form id="forgotPasswordForm">
                            <div class="form-group">
                                <label for="forgotUserId" class="form-label">User ID</label>
                                <input type="text" class="form-control" id="forgotUserId"
                                    placeholder="Enter your User ID" required>
                            </div>

                            <div id="forgotPasswordError" class="alert alert-danger d-none" role="alert">
                                <i class="bi bi-exclamation-triangle"></i>
                                <span id="forgotPasswordErrorMessage"></span>
                            </div>

                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-search me-2"></i>Find Account
                            </button>
                        </form>
                    </div>

                    <!-- Step 2: MFA Code Verification -->
                    <div id="forgotPasswordStep2" style="display: none;">
                        <div class="step-content">
                            <div class="step-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <h6 class="step-title">Multi-Factor Authentication</h6>
                            <p class="step-description">We've sent a 6-digit verification code to your registered
                                contact</p>
                        </div>

                        <div class="contact-info">
                            <div class="contact-item">
                                <i class="bi bi-envelope text-primary"></i>
                                <strong>Email:</strong> <span id="maskedEmail"></span>
                            </div>
                            <div class="contact-item">
                                <i class="bi bi-phone text-success"></i>
                                <strong>SMS:</strong> <span id="maskedPhone"></span>
                            </div>
                        </div>

                        <form id="mfaVerificationForm">
                            <div class="form-group">
                                <label for="mfaCode" class="form-label">Enter 6-Digit Verification Code</label>
                            <input type="text" class="form-control mfa-code-input" id="mfaCode" placeholder="000000"
                                maxlength="6" pattern="[0-9]{6}" required>
                            <div class="timer-text">Code expires in <span id="codeTimer">5:00</span></div>
                        </div>

                        <div id="mfaError" class="alert alert-danger d-none" role="alert">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span id="mfaErrorMessage"></span>
                        </div>

                        <div class="text-center mb-3">
                            <button type="button" class="btn-link" id="resendCodeBtn" onclick="resendMfaCode()">
                                <i class="bi bi-arrow-clockwise"></i>Resend Code
                            </button>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary" onclick="backToStep1()">
                                <i class="bi bi-arrow-left"></i>Back
                            </button>
                            <button type="submit" class="btn btn-info flex-fill">
                                <i class="bi bi-check-circle"></i>Verify Code
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: Reset Password -->
                <div id="forgotPasswordStep3" style="display: none;">
                    <div class="step-content">
                        <div class="step-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h6 class="step-title">Reset Your Password</h6>
                        <p class="step-description">Create a new secure password for your account</p>
                    </div>

                    <form id="resetPasswordForm">
                        <div class="form-group">
                            <label for="newPasswordReset" class="form-label">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="newPasswordReset" required
                                    minlength="6">
                                <button type="button" class="password-toggle" id="newPasswordResetToggle">
                                    <i class="bi bi-eye" id="newPasswordResetIcon"></i>
                                </button>
                            </div>
                            <div class="timer-text">Password must be at least 6 characters long</div>
                        </div>

                        <div class="form-group">
                            <label for="confirmPasswordReset" class="form-label">Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="confirmPasswordReset" required>
                                <button type="button" class="password-toggle" id="confirmPasswordResetToggle">
                                    <i class="bi bi-eye" id="confirmPasswordResetIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div id="resetPasswordError" class="alert alert-danger d-none" role="alert">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span id="resetPasswordErrorMessage"></span>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary" onclick="backToStep2()">
                                <i class="bi bi-arrow-left"></i>Back
                            </button>
                            <button type="submit" class="btn btn-success flex-fill">
                                <i class="bi bi-check-lg"></i>Reset Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 4: Success -->
                <div id="forgotPasswordStep4" style="display: none;">
                    <div class="step-content">
                        <div class="step-icon bg-success bg-opacity-10 text-success"
                            style="width: 100px; height: 100px; font-size: 3rem;">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <h5 class="step-title text-success">Password Reset Successful!</h5>
                        <p class="step-description">Your password has been successfully updated. You can now log in
                            with your new password.</p>
                        <button type="button" class="btn btn-success" onclick="closeForgotPasswordModal()">
                            <i class="bi bi-box-arrow-in-right"></i>Return to Login
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>