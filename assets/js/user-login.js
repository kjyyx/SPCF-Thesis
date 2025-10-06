// Login page JavaScript for University Event Management System

console.log('user-login.js loaded');

let selectedLoginType = null;

// Login type selection function
function selectLoginType(type) {
    selectedLoginType = type;

    // Reset all buttons
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));

    const loginButton = document.getElementById('loginButton');
    const loginTypeInput = document.getElementById('loginTypeInput');
    const selectedBtn = document.getElementById(type + 'Btn');

    // Add active state to selected button
    selectedBtn.classList.add('active');
    
    // Set the hidden input value for form submission
    loginTypeInput.value = type;

    // Update login button based on selection
    if (type === 'employee') {
        loginButton.innerHTML = '<i class="bi bi-briefcase"></i>Sign In as Employee';
        loginButton.className = 'login-btn employee';
        loginButton.disabled = false;
    } else if (type === 'student') {
        loginButton.innerHTML = '<i class="bi bi-mortarboard"></i>Sign In as Student';
        loginButton.className = 'login-btn student';
        loginButton.disabled = false;
    } else if (type === 'admin') {
        loginButton.innerHTML = '<i class="bi bi-shield-lock"></i>Sign In as Administrator';
        loginButton.className = 'login-btn admin';
        loginButton.disabled = false;
    }

    // Hide any login errors when user changes selection
    hideLoginError();

    // Add smooth transition effect
    loginButton.style.transform = 'scale(0.95)';
    setTimeout(() => {
        loginButton.style.transform = 'scale(1)';
    }, 100);
}

// Password visibility toggle (global so inline onclick can call it)
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + 'Icon');
    if (!field) return;
    if (field.type === 'password') {
        field.type = 'text';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
    } else {
        field.type = 'password';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
    }
}

// Login error handling functions
function showLoginError(message) {
    const errorDiv = document.getElementById('loginError');
    const errorMessage = document.getElementById('loginErrorMessage');
    
    if (errorDiv && errorMessage) {
        errorMessage.textContent = message;
        errorDiv.classList.remove('d-none', 'alert-success');
        errorDiv.classList.add('alert-danger');

        // Smooth slide-in animation
        errorDiv.style.opacity = '0';
        errorDiv.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            errorDiv.style.opacity = '1';
            errorDiv.style.transform = 'translateY(0)';
        }, 10);

        // Auto-hide after 5 seconds
        setTimeout(() => {
            hideLoginError();
        }, 5000);
    }
}

function hideLoginError() {
    const errorDiv = document.getElementById('loginError');
    if (errorDiv) {
        errorDiv.style.opacity = '0';
        errorDiv.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            errorDiv.classList.add('d-none');
        }, 200);
    }
}

// Forgot password error handling functions
function showForgotPasswordError(message) {
    const errorDiv = document.getElementById('forgotPasswordError');
    const errorMessage = document.getElementById('forgotPasswordErrorMessage');
    
    if (errorDiv && errorMessage) {
        errorMessage.textContent = message;
        errorDiv.classList.remove('d-none');
    }
}

function hideForgotPasswordError() {
    const errorDiv = document.getElementById('forgotPasswordError');
    if (errorDiv) {
        errorDiv.classList.add('d-none');
    }
}

// Form validation before submission (attach after DOM ready)
function attachLoginSubmitHandler() {
    const form = document.getElementById('loginForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (!selectedLoginType) {
            e.preventDefault();
            showLoginError('Please select whether you are logging in as an Employee, Student, or Administrator.');
            return;
        }
        const userId = document.getElementById('userId').value;
        const password = document.getElementById('password').value;
        if (!userId || !password) {
            e.preventDefault();
            showLoginError('Please enter both User ID and Password.');
            return;
        }
        const loginButton = document.getElementById('loginButton');
        const loginContainer = document.querySelector('.login-container');
        loginContainer.classList.add('loading');
        loginButton.innerHTML = '<i class="bi bi-hourglass-split"></i>Signing In...';
        loginButton.disabled = true;
    });
}

// Forgot Password Modal Functions
function openForgotPassword() {
    console.log('openForgotPassword called');
    const modal = new bootstrap.Modal(document.getElementById('forgotPasswordModal'));
    modal.show();
}

function closeForgotPasswordModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal'));
    modal.hide();
    resetForgotPasswordModal();
}

function resetForgotPasswordModal() {
    // Reset to step 1
    document.getElementById('forgotPasswordStep1').style.display = 'block';
    document.getElementById('forgotPasswordStep2').style.display = 'none';
    document.getElementById('forgotPasswordStep3').style.display = 'none';
    document.getElementById('forgotPasswordStep4').style.display = 'none';

    // Clear forms
    document.getElementById('forgotPasswordForm').reset();
    document.getElementById('mfaVerificationForm').reset();
    document.getElementById('resetPasswordForm').reset();

    // Hide error messages
    document.getElementById('forgotPasswordError').classList.add('d-none');
    document.getElementById('mfaError').classList.add('d-none');
    document.getElementById('resetPasswordError').classList.add('d-none');
}

// Handle forgot password form submission
// Moved to DOMContentLoaded below

// MFA Timer
let mfaTimer;
let mfaTimeLeft = 300; // 5 minutes

function startMfaTimer() {
    mfaTimeLeft = 300;
    const timerDisplay = document.getElementById('codeTimer');

    mfaTimer = setInterval(() => {
        const minutes = Math.floor(mfaTimeLeft / 60);
        const seconds = mfaTimeLeft % 60;
        timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;

        if (mfaTimeLeft <= 0) {
            clearInterval(mfaTimer);
            timerDisplay.textContent = 'Expired';
            document.getElementById('mfaCode').disabled = true;
        }

        mfaTimeLeft--;
    }, 1000);
}

// Handle MFA verification
// Moved to DOMContentLoaded below

// Handle password reset
// Moved to DOMContentLoaded below

// Navigation functions
function backToStep1() {
    document.getElementById('forgotPasswordStep2').style.display = 'none';
    document.getElementById('forgotPasswordStep1').style.display = 'block';
    clearInterval(mfaTimer);
}

function backToStep2() {
    document.getElementById('forgotPasswordStep3').style.display = 'none';
    document.getElementById('forgotPasswordStep2').style.display = 'block';
    startMfaTimer();
}

function resendMfaCode() {
    // Reset timer
    startMfaTimer();
    document.getElementById('mfaCode').disabled = false;
    document.getElementById('mfaError').classList.add('d-none');

    // Show brief success message
    const btn = document.getElementById('resendCodeBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check me-1"></i>Code Sent!';
    btn.disabled = true;

    setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 3000);
}

// (Duplicate togglePasswordVisibility removed; single global function defined above)

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    // Attach submit handler and wire up main password eye button
    attachLoginSubmitHandler();
    const togglePasswordBtn = document.getElementById('togglePassword');
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function () { togglePasswordVisibility('password'); });
    }

    // Check if there's a PHP error message to display
    const errorDiv = document.getElementById('loginError');
    if (errorDiv && !errorDiv.classList.contains('d-none')) {
        // Make error message auto-hide after 5 seconds
        setTimeout(() => {
            hideLoginError();
        }, 5000);
    }

    // If a login type was previously selected, restore it
    const loginTypeInput = document.getElementById('loginTypeInput');
    if (loginTypeInput && loginTypeInput.value) {
        selectLoginType(loginTypeInput.value);
    }

    // Add event listeners for forgot password modal toggles
    const newPasswordToggle = document.getElementById('newPasswordResetToggle');
    const confirmPasswordToggle = document.getElementById('confirmPasswordResetToggle');

    if (newPasswordToggle) newPasswordToggle.addEventListener('click', function () { togglePasswordVisibility('newPasswordReset'); });
    if (confirmPasswordToggle) confirmPasswordToggle.addEventListener('click', function () { togglePasswordVisibility('confirmPasswordReset'); });

    // Attach forgot password form listener
    const forgotForm = document.getElementById('forgotPasswordForm');
    if (forgotForm) {
        forgotForm.addEventListener('submit', async function (e) {
            console.log('forgotPasswordForm submit event fired');
            e.preventDefault();
            console.log('preventDefault called');

            const userId = document.getElementById('forgotUserId').value.trim();
            hideForgotPasswordError();
            console.log('userId:', userId);

            if (!userId) {
                showForgotPasswordError('Please enter your User ID.');
                return;
            }

            try {
                console.log('Sending fetch request');
                const response = await fetch('../api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'forgot_password', userId })
                });
                console.log('Response received:', response);
                const data = await response.json();
                console.log('Data:', data);

                if (data.success) {
                    // Show masked contact info
                    document.getElementById('maskedEmail').textContent = data.maskedEmail;
                    document.getElementById('maskedPhone').textContent = data.maskedPhone;

                    // Show demo code alert
                    const demoAlert = document.createElement('div');
                    demoAlert.className = 'alert alert-info alert-dismissible fade show';
                    demoAlert.innerHTML = `
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Demo Mode:</strong> Your verification code is <strong>${data.demoCode}</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.getElementById('forgotPasswordStep2').insertBefore(demoAlert, document.getElementById('forgotPasswordStep2').querySelector('form'));

                    // Proceed to MFA step
                    document.getElementById('forgotPasswordStep1').style.display = 'none';
                    document.getElementById('forgotPasswordStep2').style.display = 'block';
                    startMfaTimer();
                } else {
                    showForgotPasswordError(data.message || 'User not found.');
                }
            } catch (error) {
                console.error('Error:', error);
                showForgotPasswordError('Server error. Please try again.');
            }
        });
        console.log('forgotPasswordForm event listener attached');
    } else {
        console.log('forgotPasswordForm not found in DOMContentLoaded');
    }

    // Attach MFA verification form listener
    const mfaForm = document.getElementById('mfaVerificationForm');
    if (mfaForm) {
        mfaForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const code = document.getElementById('mfaCode').value;

            // Demo: accept any 6-digit code
            if (code.length === 6 && /^\d+$/.test(code)) {
                document.getElementById('forgotPasswordStep2').style.display = 'none';
                document.getElementById('forgotPasswordStep3').style.display = 'block';
                clearInterval(mfaTimer);
            } else {
                document.getElementById('mfaErrorMessage').textContent = 'Invalid verification code. Please enter a 6-digit code.';
                document.getElementById('mfaError').classList.remove('d-none');
            }
        });
        console.log('mfaVerificationForm event listener attached');
    }

    // Attach password reset form listener
    const resetForm = document.getElementById('resetPasswordForm');
    if (resetForm) {
        resetForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const newPassword = document.getElementById('newPasswordReset').value;
            const confirmPassword = document.getElementById('confirmPasswordReset').value;

            if (newPassword !== confirmPassword) {
                document.getElementById('resetPasswordErrorMessage').textContent = 'Passwords do not match.';
                document.getElementById('resetPasswordError').classList.remove('d-none');
                return;
            }

            if (newPassword.length < 8) {
                document.getElementById('resetPasswordErrorMessage').textContent = 'Password must be at least 8 characters long and meet complexity requirements.';
                document.getElementById('resetPasswordError').classList.remove('d-none');
                return;
            }

            // Get userId from the forgot form
            const userId = document.getElementById('forgotUserId').value.trim();

            try {
                const response = await fetch('../api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset_password', userId, newPassword })
                });
                const data = await response.json();

                if (data.success) {
                    // Show success
                    document.getElementById('forgotPasswordStep3').style.display = 'none';
                    document.getElementById('forgotPasswordStep4').style.display = 'block';
                } else {
                    document.getElementById('resetPasswordErrorMessage').textContent = data.message || 'Failed to update password.';
                    document.getElementById('resetPasswordError').classList.remove('d-none');
                }
            } catch (error) {
                document.getElementById('resetPasswordErrorMessage').textContent = 'Server error. Please try again.';
                document.getElementById('resetPasswordError').classList.remove('d-none');
            }
        });
        console.log('resetPasswordForm event listener attached');
    }
});