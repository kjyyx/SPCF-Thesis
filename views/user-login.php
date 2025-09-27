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
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            'Unknown User', // Since user not logged in yet
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$userId = '';
$errorMessage = '';
$successMessage = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $userId = $_POST['userId'] ?? '';
    $password = $_POST['password'] ?? '';
    $loginType = $_POST['loginType'] ?? '';

    if (!empty($userId) && !empty($password) && !empty($loginType)) {
        $auth = new Auth();
        $user = $auth->login($userId, $password, $loginType);

        if ($user) {
            // Login successful
            loginUser($user);

            // ADD AUDIT LOG FOR SUCCESSFUL LOGIN
            addAuditLog('LOGIN', 'Authentication', "User {$user['first_name']} {$user['last_name']} logged in", $user['id'], 'User', 'INFO');

            // Redirect based on role
            if ($user['role'] === 'admin') {
                header('Location: admin-dashboard.php');
            } else {
                header('Location: event-calendar.php');
            }
            exit();
        } else {
            // Login failed
            $error = 'Invalid credentials. Please try again.';

            // ADD AUDIT LOG FOR FAILED LOGIN
            addAuditLog('LOGIN_FAILED', 'Security', "Failed login attempt for user: {$userId}", null, 'User', 'WARNING');
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
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
            <form id="loginForm" method="POST" action="">
                <input type="hidden" name="loginType" id="loginTypeInput" value="">
                <input type="hidden" name="login" value="1">

                <!-- Show error if exists -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Login Type Selection -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-person-badge"></i>Login As
                    </label>
                    <div class="login-type-buttons">
                        <button type="button" class="type-btn employee" id="employeeBtn"
                            onclick="selectLoginType('employee')">
                            <i class="bi bi-briefcase"></i>Employee
                        </button>
                        <button type="button" class="type-btn student" id="studentBtn"
                            onclick="selectLoginType('student')">
                            <i class="bi bi-mortarboard"></i>Student
                        </button>
                        <button type="button" class="type-btn admin admin-btn" id="adminBtn"
                            onclick="selectLoginType('admin')">
                            <i class="bi bi-shield-lock"></i>Administrator
                        </button>
                    </div>
                </div>

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

                <!-- Error/Success Message -->
                <?php if ($errorMessage): ?>
                    <div id="loginError" class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span id="loginErrorMessage"><?= $errorMessage ?></span>
                    </div>
                <?php elseif ($successMessage): ?>
                    <div id="loginSuccess" class="alert alert-success" role="alert">
                        <i class="bi bi-check-circle"></i>
                        <span id="loginSuccessMessage"><?= $successMessage ?></span>
                    </div>
                <?php else: ?>
                    <div id="loginError" class="alert alert-danger d-none" role="alert">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span id="loginErrorMessage"></span>
                    </div>
                <?php endif; ?>

                <!-- Login Button -->
                <button type="submit" class="login-btn" id="loginButton" disabled>
                    <i class="bi bi-box-arrow-in-right"></i>
                    Select Login Type First
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