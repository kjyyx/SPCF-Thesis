// Login page JavaScript for SPCF Digital Workflow Management System

// Sample user data for demonstration
const users = {
    'ADM001': { 
        id: 'ADM001', // <-- Add this line
        firstName: 'John', 
        lastName: 'Cooper', 
        role: 'admin', 
        password: 'admin123',
        email: 'admin@spcf.edu.ph',
        phone: '+63-xxx-xxx-xxxx'
    },
    'EMP001': { 
        id: 'EMP001', // <-- Add this line
        firstName: 'Jane', 
        lastName: 'Austin', 
        role: 'employee', 
        password: 'admin123',
        email: 'jane.employee@spcf.edu.ph',
        phone: '+63-xxx-xxx-xxxx'
    },
    'STU001': { 
        id: 'STU001', // <-- Add this line
        firstName: 'Alice', 
        lastName: 'Hetherington', 
        role: 'student', 
        password: 'student123',
        email: 'alice.student@spcf.edu.ph',
        phone: '+63-xxx-xxx-xxxx'
    }
};

let selectedLoginType = null;

// Login type selection function
function selectLoginType(type) {
    selectedLoginType = type;
    
    // Reset all buttons
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    
    const loginButton = document.getElementById('loginButton');
    const selectedBtn = document.getElementById(type + 'Btn');
    
    // Add active state to selected button
    selectedBtn.classList.add('active');
    
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

// Password visibility toggle
function togglePasswordVisibility() {
    const passwordField = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.classList.remove('bi-eye');
        toggleIcon.classList.add('bi-eye-slash');
    } else {
        passwordField.type = 'password';
        toggleIcon.classList.remove('bi-eye-slash');
        toggleIcon.classList.add('bi-eye');
    }
}

// Login error handling functions
function showLoginError(message) {
    const errorDiv = document.getElementById('loginError');
    const errorMessage = document.getElementById('loginErrorMessage');
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

function hideLoginError() {
    const errorDiv = document.getElementById('loginError');
    errorDiv.style.opacity = '0';
    errorDiv.style.transform = 'translateY(-10px)';
    setTimeout(() => {
        errorDiv.classList.add('d-none');
    }, 200);
}

function showSuccessMessage(message) {
    const errorDiv = document.getElementById('loginError');
    const errorMessage = document.getElementById('loginErrorMessage');
    errorMessage.textContent = message;
    errorDiv.classList.remove('alert-danger', 'd-none');
    errorDiv.classList.add('alert-success');
    
    // Change icon to success
    const icon = errorDiv.querySelector('i');
    icon.classList.remove('bi-exclamation-triangle');
    icon.classList.add('bi-check-circle');
    
    // Smooth animation
    errorDiv.style.opacity = '0';
    errorDiv.style.transform = 'translateY(-10px)';
    setTimeout(() => {
        errorDiv.style.opacity = '1';
        errorDiv.style.transform = 'translateY(0)';
    }, 10);
}

// Login functionality
function handleLoginSubmit(e) {
    e.preventDefault();

    // Hide any previous error messages
    hideLoginError();

    if (!selectedLoginType) {
        showLoginError('Please select whether you are logging in as an Employee, Student, or Administrator.');
        return;
    }

    const userId = document.getElementById('userId').value;
    const password = document.getElementById('password').value;
    const loginButton = document.getElementById('loginButton');
    const loginContainer = document.querySelector('.login-container');

    // Add loading state
    loginContainer.classList.add('loading');
    loginButton.innerHTML = '<i class="bi bi-hourglass-split"></i>Signing In...';
    loginButton.disabled = true;

    // Simulate network delay for better UX
    setTimeout(() => {
        // Check if user exists and password is correct
        if (users[userId] && users[userId].password === password) {
            // Verify the selected login type matches the user's actual role
            if (users[userId].role === selectedLoginType) {
                const currentUser = users[userId];
                localStorage.setItem('currentUser', JSON.stringify(currentUser));

                // Show success message
                showSuccessMessage('Login successful! Redirecting to dashboard...');
                loginButton.innerHTML = '<i class="bi bi-check-circle"></i>Success!';

                // Redirect based on user role after short delay
                setTimeout(() => {
                    loginContainer.style.transform = 'scale(0.9)';
                    loginContainer.style.opacity = '0';
                    setTimeout(() => {
                        // Redirect admin users to admin dashboard, others to calendar
                        if (currentUser.role === 'admin') {
                            window.location.href = 'admin-dashboard.php';
                        } else {
                            window.location.href = 'event-calendar.php';
                        }
                    }, 300);
                }, 1000);

            } else {
                const actualTypeMap = {
                    'employee': 'Employee',
                    'student': 'Student',
                    'admin': 'Administrator'
                };
                const actualType = actualTypeMap[users[userId].role];
                const selectedTypeDisplay = actualTypeMap[selectedLoginType];
                showLoginError(`Account mismatch: This ID (${userId}) belongs to a ${actualType} account, but you selected ${selectedTypeDisplay} login. Please select the correct login type.`);
                resetLoginButton();
            }
        } else if (!users[userId]) {
            showLoginError('User ID not found. Please check your credentials and try again.');
            resetLoginButton();
        } else {
            showLoginError('Incorrect password. Please try again.');
            resetLoginButton();
        }
    }, 800); // Simulate network delay
}

document.getElementById('loginForm').addEventListener('submit', handleLoginSubmit);

function resetLoginButton() {
    const loginButton = document.getElementById('loginButton');
    const loginContainer = document.querySelector('.login-container');
    
    loginContainer.classList.remove('loading');
    loginButton.disabled = false;
    
    // Restore button text based on selected type
    if (selectedLoginType === 'employee') {
        loginButton.innerHTML = '<i class="bi bi-briefcase"></i>Sign In as Employee';
    } else if (selectedLoginType === 'student') {
        loginButton.innerHTML = '<i class="bi bi-mortarboard"></i>Sign In as Student';
    } else if (selectedLoginType === 'admin') {
        loginButton.innerHTML = '<i class="bi bi-shield-lock"></i>Sign In as Administrator';
    }
}

// Forgot Password Modal Functions
function openForgotPassword() {
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
function handleForgotPasswordSubmit(e) {
    e.preventDefault();
    
    const userId = document.getElementById('forgotUserId').value;
    
    // Hide previous error
    document.getElementById('forgotPasswordError').classList.add('d-none');
    
    // Check if user exists
    if (users[userId]) {
        // Show success and move to step 2
        document.getElementById('forgotPasswordStep1').style.display = 'none';
        document.getElementById('forgotPasswordStep2').style.display = 'block';
        
        // Show masked contact info (demo)
        document.getElementById('maskedEmail').textContent = 'j***@spcf.edu.ph';
        document.getElementById('maskedPhone').textContent = '+63-***-***-1234';
        
        // Start countdown timer
        startMfaTimer();
    } else {
        document.getElementById('forgotPasswordErrorMessage').textContent = 'User ID not found. Please check your ID and try again.';
        document.getElementById('forgotPasswordError').classList.remove('d-none');
    }
}

document.getElementById('forgotPasswordForm').addEventListener('submit', handleForgotPasswordSubmit);

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
function handleMfaVerificationSubmit(e) {
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
}

document.getElementById('mfaVerificationForm').addEventListener('submit', handleMfaVerificationSubmit);

// Handle password reset
function handleResetPasswordSubmit(e) {
    e.preventDefault();
    
    const newPassword = document.getElementById('newPasswordReset').value;
    const confirmPassword = document.getElementById('confirmPasswordReset').value;
    
    if (newPassword !== confirmPassword) {
        document.getElementById('resetPasswordErrorMessage').textContent = 'Passwords do not match.';
        document.getElementById('resetPasswordError').classList.remove('d-none');
        return;
    }
    
    if (newPassword.length < 6) {
        document.getElementById('resetPasswordErrorMessage').textContent = 'Password must be at least 6 characters long.';
        document.getElementById('resetPasswordError').classList.remove('d-none');
        return;
    }
    
    // Show success
    document.getElementById('forgotPasswordStep3').style.display = 'none';
    document.getElementById('forgotPasswordStep4').style.display = 'block';
}

document.getElementById('resetPasswordForm').addEventListener('submit', handleResetPasswordSubmit);

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

// Password visibility toggle for reset forms
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + 'Icon');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Clear any existing login data
    localStorage.removeItem('currentUser');
    
    // Reset form
    document.getElementById('loginForm').reset();
    hideLoginError();

    // Add event listeners
    document.getElementById('togglePassword').addEventListener('click', togglePasswordVisibility);
    document.getElementById('loginForm').addEventListener('submit', handleLoginSubmit);
    document.getElementById('forgotPasswordForm').addEventListener('submit', handleForgotPasswordSubmit);
    document.getElementById('mfaVerificationForm').addEventListener('submit', handleMfaVerificationSubmit);
    document.getElementById('resetPasswordForm').addEventListener('submit', handleResetPasswordSubmit);
});
