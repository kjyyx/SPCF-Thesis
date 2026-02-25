<?php
// Use absolute paths - include config first to define ROOT_PATH
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';

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
            $severity ?? 'INFO',
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
        header('Location: ' . BASE_URL . 'dashboard');
    } else {
        header('Location: ' . BASE_URL . 'calendar');
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

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
    <title>Sign-um - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/user-login.css">
    <script src="<?php echo BASE_URL; ?>assets/js/user-login.js"></script>
    <!-- Add this after your other CSS links -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <!-- OR use this more reliable library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
</head>

<body>
    <main class="login-wrapper" role="main">
        <div class="login-layout">
            <aside class="login-info-panel" aria-label="About Sign-um">

                <!-- Top badge + headline -->
                <div>
                    <div class="info-badge mb-3">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        SPCF &mdash; Sign-um Portal
                    </div>
                    <h1 class="info-title mb-3">Digital Document Workflows</h1>
                    <p class="info-description">
                        Sign-um digitizes every step of SPCF's document lifecycle&mdash;from creation and routing
                        to multi-level approval and real-time tracking&mdash;all in one portal.
                    </p>
                </div>

                <!-- Feature cards 2×2 grid -->
                <div class="info-features-grid">
                    <div class="info-feature-card">
                        <div class="info-feature-icon"><i class="bi bi-file-earmark-check-fill"></i></div>
                        <p class="info-feature-title">Smart Routing</p>
                        <p class="info-feature-desc">Documents flow automatically through the correct approval chain.</p>
                    </div>
                    <div class="info-feature-card">
                        <div class="info-feature-icon"><i class="bi bi-pen-fill"></i></div>
                        <p class="info-feature-title">E-Signatures</p>
                        <p class="info-feature-desc">Authorised signatories sign digitally with full audit trail.</p>
                    </div>
                    <div class="info-feature-card">
                        <div class="info-feature-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
                        <p class="info-feature-title">Live Tracking</p>
                        <p class="info-feature-desc">See exactly where your document sits in the workflow.</p>
                    </div>
                    <div class="info-feature-card">
                        <div class="info-feature-icon"><i class="bi bi-shield-lock-fill"></i></div>
                        <p class="info-feature-title">2FA Security</p>
                        <p class="info-feature-desc">TOTP two-factor authentication keeps accounts protected.</p>
                    </div>
                </div>

                <!-- Footer note -->
                <div class="info-panel-footer">
                    <i class="bi bi-buildings-fill fs-5"></i>
                    <span>Systems Plus College Foundation &mdash; Document Portal</span>
                </div>

            </aside>

            <div class="login-container" role="dialog" aria-labelledby="login-title">
                <!-- Login Header -->
                <div class="login-header">
                    <div class="login-icon" aria-hidden="true">
                        <i class="bi bi-file-earmark-lock2-fill"></i>
                    </div>
                    <h2 id="login-title">Sign-um Portal</h2>
                    <p>Sign in to access your document workspace</p>
                </div>

                <!-- Login Body -->
                <div class="login-body">
                    <form id="loginForm" aria-label="Login Form">
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
                            <i class="bi bi-person-fill" aria-hidden="true"></i>User ID
                        </label>
                        <input type="text" class="form-control" id="userId" name="userId" 
                            placeholder="Enter your userID" autocomplete="username"
                            required value="<?= isset($_POST['userId']) ? htmlspecialchars($_POST['userId']) : '' ?>"
                            aria-describedby="userIdHelp">
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="password" name="password"
                                placeholder="Enter your password" autocomplete="current-password" required
                                aria-describedby="passwordHelp">
                            <button type="button" class="password-toggle" id="togglePassword" 
                                aria-label="Toggle password visibility">
                                <i class="bi bi-eye" id="passwordIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <!-- 2FA elements moved to modals below -->

                    <!-- Error/Success Message -->
                    <div id="loginError" class="alert alert-danger d-none" role="alert" aria-live="assertive">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span id="loginErrorMessage"></span>
                    </div>
                    <div id="loginSuccess" class="alert alert-success d-none" role="alert" aria-live="polite">
                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        <span id="loginSuccessMessage"></span>
                    </div>

                    <!-- Login Button -->
                    <button type="submit" class="login-btn" id="loginButton" aria-label="Sign In">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Sign In
                    </button>

                    <!-- Forgot Password -->
                    <div class="forgot-password">
                        <button type="button" onclick="openForgotPassword()" aria-label="Reset Password">
                            <i class="bi bi-question-circle-fill" aria-hidden="true"></i>
                            Forgot Password?
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- 2FA Verification Modal -->
    <div class="modal fade" id="2faVerificationModal" tabindex="-1" data-bs-backdrop="static" 
        data-bs-keyboard="false" aria-labelledby="2faVerificationLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white">
                    <h5 class="modal-title" id="2faVerificationLabel">
                        <i class="bi bi-shield-lock-fill me-2" aria-hidden="true"></i>Two-Factor Authentication
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-5">
                    <div class="mb-4">
                        <div class="step-icon bg-primary mx-auto" aria-hidden="true">
                            <i class="bi bi-shield-check" style="font-size: 2.5rem; color: white;"></i>
                        </div>
                    </div>
                    <h5 class="mb-2 fw-bold" style="color: #1f2937;">Enter Authentication Code</h5>
                    <p class="text-muted mb-4" style="font-size: 0.95rem;">Open your authenticator app and enter the 6-digit code</p>
                    
                    <div class="mb-4 px-md-5">
                        <input type="text" class="form-control form-control-lg text-center" id="2faCode" 
                               placeholder="000000" maxlength="6" pattern="[0-9]{6}" 
                               inputmode="numeric" autocomplete="one-time-code" aria-label="Authentication Code">
                    </div>

                    <div id="2faVerifyError" class="alert alert-danger d-none mx-md-4" role="alert" aria-live="assertive">
                        <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
                        <span id="2faVerifyErrorMessage"></span>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-lg btn-primary px-5" id="verify2faBtn" aria-label="Verify Authentication Code">
                        <i class="bi bi-check-circle me-2" aria-hidden="true"></i>Verify Code
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
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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

    <!-- Add this before closing body tag -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
    <script>
    // Override QRCode with the more reliable library
    window.QRCode = {
        toCanvas: function(canvas, text, options, callback) {
            if (typeof QRCode !== 'undefined' && QRCode.toCanvas) {
                return QRCode.toCanvas(canvas, text, options, callback);
            }
        }
    };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>