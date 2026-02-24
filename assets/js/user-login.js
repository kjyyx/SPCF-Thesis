// Login page JavaScript for University Event Management System

var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');

function detectLoginType(userId) {
    const prefix = userId.substring(0, 3).toUpperCase(); // Convert to uppercase for case-insensitive check
    if (prefix === 'ADM') {
        return 'admin';
    } else if (prefix === 'EMP') {
        return 'employee';
    } else if (prefix === 'STU') {
        return 'student';
    } else {
        return null; // Invalid prefix
    }
}

// Enhanced QR Code Generation with Multiple Fallbacks
function generateQRCode(containerId, secret, userId = 'User') {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }
    
    // Clear container
    container.innerHTML = '';
    
    // Create OTP URL
    const otpUrl = `otpauth://totp/Sign-um:${userId}?secret=${secret}&issuer=Sign-um&algorithm=SHA1&digits=6&period=30`;
    
    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.style.cssText = `
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 10px;
    `;
    
    // Show loading state
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'qr-loading';
    loadingDiv.style.cssText = `
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 250px;
        width: 100%;
    `;
    loadingDiv.innerHTML = `
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-3 text-muted">Generating QR Code...</p>
    `;
    container.appendChild(loadingDiv);
    
    // Try multiple QR code providers in sequence
    tryQRCodeProvider(0);
    
    function tryQRCodeProvider(attempt) {
        const providers = [
            {
                name: 'Google Charts',
                url: `https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=${encodeURIComponent(otpUrl)}&choe=UTF-8&chld=H|0`
            },
            {
                name: 'QR Server',
                url: `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(otpUrl)}`
            },
            {
                name: 'GoQR',
                url: `https://api.qr-code-generator.com/v1/create?size=250x250&data=${encodeURIComponent(otpUrl)}`
            }
        ];
        
        if (attempt >= providers.length) {
            // All providers failed, show manual entry
            showManualEntry();
            return;
        }
        
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        img.onload = function() {
            // Clear loading
            container.innerHTML = '';
            
            // Set image styles
            img.style.cssText = `
                display: block;
                width: 250px;
                height: 250px;
                max-width: 100%;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
                border: 4px solid white;
                margin: 0 auto;
                object-fit: contain;
                background: white;
                padding: 8px;
            `;
            
            wrapper.appendChild(img);
            
            // Add secret backup
            addSecretBackup(wrapper, secret, userId);
            
            container.appendChild(wrapper);
        };
        
        img.onerror = function() {
            tryQRCodeProvider(attempt + 1);
        };
        
        // Add cache buster to avoid caching issues
        img.src = providers[attempt].url + '&_=' + new Date().getTime();
    }
    
    function addSecretBackup(wrapper, secret, userId) {
        const backupDiv = document.createElement('div');
        backupDiv.className = 'qr-secret-backup';
        backupDiv.style.cssText = `
            margin-top: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            width: 100%;
            max-width: 300px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        `;
        
        backupDiv.innerHTML = `
            <div style="margin-bottom: 12px; color: #475569; font-size: 0.9rem; font-weight: 500;">
                <i class="bi bi-key-fill" style="color: #667eea; margin-right: 5px;"></i>
                Can't scan the QR code?
            </div>
            <div style="
                background: white;
                border-radius: 12px;
                padding: 15px;
                margin-bottom: 12px;
                border: 1px dashed #94a3b8;
            ">
                <div style="color: #64748b; font-size: 0.75rem; margin-bottom: 5px;">Secret Key:</div>
                <code style="
                    display: block;
                    color: #1e293b;
                    font-size: 16px;
                    font-weight: 700;
                    letter-spacing: 2px;
                    word-break: break-all;
                    font-family: 'SF Mono', 'Monaco', monospace;
                ">${secret}</code>
            </div>
            <div style="color: #64748b; font-size: 0.8rem;">
                <i class="bi bi-info-circle me-1"></i>
                Account: <strong>${userId}</strong><br>
                Issuer: <strong>Sign-um</strong>
            </div>
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 0.75rem; color: #94a3b8;">
                Open your authenticator app and tap "Enter setup key"
            </div>
        `;
        
        wrapper.appendChild(backupDiv);
    }
    
    function showManualEntry() {
        container.innerHTML = '';
        
        const manualDiv = document.createElement('div');
        manualDiv.className = 'manual-entry';
        manualDiv.style.cssText = `
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #fbbf24;
            border-radius: 20px;
            padding: 30px 20px;
            text-align: center;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
            box-shadow: 0 10px 25px rgba(251, 191, 36, 0.2);
        `;
        
        manualDiv.innerHTML = `
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 48px; color: #f59e0b;"></i>
            <h6 style="margin: 20px 0 10px; color: #92400e; font-weight: 700;">QR Code Unavailable</h6>
            <p style="color: #92400e; font-size: 14px; margin-bottom: 20px;">
                Please set up two-factor authentication manually using the key below:
            </p>
            <div style="
                background: white;
                border-radius: 12px;
                padding: 15px;
                margin-bottom: 15px;
                border: 2px solid #fbbf24;
            ">
                <div style="color: #666; font-size: 12px; margin-bottom: 5px;">Your secret key:</div>
                <code style="
                    display: block;
                    background: #f8fafc;
                    padding: 15px;
                    border-radius: 8px;
                    color: #1e293b;
                    font-size: 16px;
                    font-weight: bold;
                    word-break: break-all;
                    border: 1px solid #e2e8f0;
                ">${secret}</code>
            </div>
            <div style="background: white; border-radius: 8px; padding: 10px; margin-bottom: 15px;">
                <p style="margin: 0; color: #4b5563; font-size: 13px;">
                    <strong>Setup instructions:</strong><br>
                    1. Open Google Authenticator<br>
                    2. Tap "+" and "Enter setup key"<br>
                    3. Enter account: ${userId}<br>
                    4. Enter key above<br>
                    5. Tap "Add"
                </p>
            </div>
            <div style="color: #92400e; font-size: 12px;">
                <i class="bi bi-shield-check me-1"></i>
                After setup, enter the 6-digit code below
            </div>
        `;
        
        container.appendChild(manualDiv);
    }
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

// Cooldown timer variables
let cooldownTimer;
let cooldownEndTime;

// Show cooldown message with live countdown
function showCooldownMessage(message) {
    const errorDiv = document.getElementById('loginError');
    const errorMessage = document.getElementById('loginErrorMessage');

    if (errorDiv && errorMessage) {
        errorMessage.textContent = message;
        errorDiv.classList.remove('d-none', 'alert-success', 'alert-danger');
        errorDiv.classList.add('alert-warning');

        // Smooth slide-in animation with bounce
        errorDiv.style.opacity = '0';
        errorDiv.style.transform = 'translateY(-20px) scale(0.95)';
        setTimeout(() => {
            errorDiv.style.transition = 'all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
            errorDiv.style.opacity = '1';
            errorDiv.style.transform = 'translateY(0) scale(1)';
        }, 10);

        // Start countdown timer
        startCooldownTimer();
    }
}

// Start live countdown for cooldown
function startCooldownTimer() {
    // Parse initial message to get total seconds (assuming format "Try again in Xm Ys")
    const message = document.getElementById('loginErrorMessage').textContent;
    const match = message.match(/Try again in (\d+)m (\d+)s/);
    if (match) {
        const minutes = parseInt(match[1]);
        const seconds = parseInt(match[2]);
        const totalSeconds = minutes * 60 + seconds;
        cooldownEndTime = Date.now() + totalSeconds * 1000;

        // Clear any existing timer
        if (cooldownTimer) clearInterval(cooldownTimer);

        cooldownTimer = setInterval(updateCooldownDisplay, 1000);
        updateCooldownDisplay(); // Initial update
    }
}

// Update cooldown display every second
function updateCooldownDisplay() {
    const now = Date.now();
    const remainingMs = cooldownEndTime - now;

    if (remainingMs <= 0) {
        // Cooldown expired
        clearInterval(cooldownTimer);
        hideLoginError();
        enableFormAfterCooldown();
        return;
    }

    const remainingSeconds = Math.ceil(remainingMs / 1000);
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds % 60;

    const errorMessage = document.getElementById('loginErrorMessage');
    if (errorMessage) {
        errorMessage.textContent = `Too many failed attempts. Try again in ${minutes}m ${seconds}s.`;
    }
}

// Enable form after cooldown
function enableFormAfterCooldown() {
    const form = document.getElementById('loginForm');
    const inputs = form.querySelectorAll('input');
    const button = document.getElementById('loginButton');
    inputs.forEach(input => input.disabled = false);
    button.disabled = false;
    button.innerHTML = '<i class="bi bi-box-arrow-in-right"></i>Sign In';
}

function hideLoginError() {
    const errorDiv = document.getElementById('loginError');
    if (errorDiv) {
        errorDiv.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        errorDiv.style.opacity = '0';
        errorDiv.style.transform = 'translateY(-20px) scale(0.95)';
        setTimeout(() => {
            errorDiv.classList.add('d-none');
        }, 300);
    }
}

// Show login error message
function showLoginError(message) {
    const errorDiv = document.getElementById('loginError');
    const errorMessage = document.getElementById('loginErrorMessage');

    if (errorDiv && errorMessage) {
        errorMessage.textContent = message;
        errorDiv.classList.remove('d-none', 'alert-warning');
        errorDiv.classList.add('alert-danger');

        // Smooth slide-in animation with bounce
        errorDiv.style.opacity = '0';
        errorDiv.style.transform = 'translateY(-20px) scale(0.95)';
        setTimeout(() => {
            errorDiv.style.transition = 'all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
            errorDiv.style.opacity = '1';
            errorDiv.style.transform = 'translateY(0) scale(1)';
        }, 10);
    }
}

function showLoginSuccess(message) {
    const successDiv = document.getElementById('loginSuccess');
    const successMessage = document.getElementById('loginSuccessMessage');

    if (successDiv && successMessage) {
        successMessage.textContent = message;
        successDiv.classList.remove('d-none');

        // Smooth slide-in animation with bounce
        successDiv.style.opacity = '0';
        successDiv.style.transform = 'translateY(-20px) scale(0.95)';
        setTimeout(() => {
            successDiv.style.transition = 'all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)';
            successDiv.style.opacity = '1';
            successDiv.style.transform = 'translateY(0) scale(1)';
        }, 10);
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
    form.addEventListener('submit', async function (e) {
        e.preventDefault(); // Always prevent default form submission - handle via API

        const loginButton = document.getElementById('loginButton');
        const loginContainer = document.querySelector('.login-container');
        
        // Add smooth loading transition
        loginContainer.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        loginContainer.classList.add('loading');
        loginButton.disabled = true;
        loginButton.style.transform = 'scale(0.98)';

        try {
            // Normal login only - 2FA handled by modal buttons
            const userId = document.getElementById('userId').value;
            const password = document.getElementById('password').value;
            if (!userId || !password) {
                showLoginError('Please enter both User ID and Password.');
                return;
            }

            const loginType = detectLoginType(userId);
            if (!loginType) {
                showLoginError('Invalid User ID format. User ID must start with ADM, EMP, or STU.');
                return;
            }

            const requestData = { userId, password, loginType: detectLoginType(userId) };
            loginButton.innerHTML = '<i class="bi bi-hourglass-split"></i>Signing In...';

            const response = await fetch(BASE_URL + 'api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestData)
            });

            const responseText = await response.text();

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error('Invalid JSON response from server');
            }

            if (data.success) {

                // Handle 2FA setup requirement
                if (data.requires_2fa_setup) {
                    // Use the simplified QR generator
                    generateQRCode('qrCodeContainer', data.secret, data.user_id);
                    
                    window.tempUserId = data.user_id;
                    
                    // Update button text
                    const loginBtn = document.getElementById('loginButton');
                    if (loginBtn) {
                        loginBtn.innerHTML = '<i class="bi bi-shield-plus me-2"></i>Complete 2FA Setup';
                    }
                    
                    // Show the setup modal
                    const setupModal = new bootstrap.Modal(document.getElementById('2faSetupModal'));
                    setupModal.show();
                } else if (data.requires_2fa) {
                    window.tempUserId = data.user_id;

                    // Show 2FA verification modal
                    const verifyModal = new bootstrap.Modal(document.getElementById('2faVerificationModal'));
                    verifyModal.show();

                    // Focus on input after modal shows
                    document.getElementById('2faVerificationModal').addEventListener('shown.bs.modal', function () {
                        document.getElementById('2faCode').focus();
                    }, { once: true });
                } else {
                    // Redirect as usual
                    window.location.href = data.redirect;
                }
            } else {
                // Handle cooldown
                if (data.cooldown) {
                    showCooldownMessage(data.message);
                    disableFormForCooldown(); // Disable form during cooldown
                } else {
                    showLoginError(data.message || 'Invalid credentials. Please try again.');
                }
            }
        } catch (error) {
            showLoginError('Server error: ' + error.message + '. Check console for details.');
        } finally {
            loginContainer.classList.remove('loading');
            loginButton.innerHTML = '<i class="bi bi-box-arrow-in-right"></i>Sign In';
            loginButton.disabled = false;
            loginButton.style.transform = '';
        }
        }
    );
}

// Helper function to add text backup below QR code
function addTextBackup(container, secret) {
    const backupDiv = document.createElement('div');
    backupDiv.className = 'mt-2 text-center';
    backupDiv.style.maxWidth = '100%';
    backupDiv.innerHTML = `
        <small class="text-muted" style="font-size: 0.75rem;">If QR code doesn't scan:</small><br>
        <code class="d-inline-block px-2 py-1 bg-light border" style="font-size: 0.7rem; word-break: break-all; max-width: 100%;">${secret}</code><br>
        <small class="text-muted" style="font-size: 0.7rem;">Issuer: Sign-um</small>
    `;
    container.appendChild(backupDiv);
}

// Disable form during cooldown
function disableFormForCooldown() {
    const form = document.getElementById('loginForm');
    const inputs = form.querySelectorAll('input');
    const button = document.getElementById('loginButton');
    inputs.forEach(input => input.disabled = true);
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-clock"></i>Cooldown Active';

    // Note: Form will be re-enabled by the countdown timer when cooldown expires
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
    // Get the userId from the forgot password form
    const userId = document.getElementById('forgotUserId').value.trim();

    if (!userId) {
        showForgotPasswordError('User ID not found. Please try again.');
        return;
    }

    // Reset timer
    startMfaTimer();
    document.getElementById('mfaCode').disabled = false;
    document.getElementById('mfaError').classList.add('d-none');

    // Show loading state
    const btn = document.getElementById('resendCodeBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
    btn.disabled = true;

    // Actually resend the code
    fetch(BASE_URL + 'api/auth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'forgot_password', userId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Code Sent!';
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }, 3000);
        } else {
            // Show error
            showForgotPasswordError(data.message || 'Failed to resend code.');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        showForgotPasswordError('Failed to resend code. Please try again.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

// (Duplicate togglePasswordVisibility removed; single global function defined above)

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function () {

    // Load QRCode library if not already loaded
    try {
        await loadQRCode();

        // Test QR code generation
        const testContainer = document.createElement('div');
        testContainer.id = 'test-qr';
        testContainer.style.display = 'none';
        document.body.appendChild(testContainer);
        const testCanvas = document.createElement('canvas');
        testContainer.appendChild(testCanvas);

        QRCode.toCanvas(testCanvas, 'test', { width: 100 }, function (error) {
            if (error) {
                // Test QR generation failed
            } else {
                // Test QR code generation successful
            }
            // Always clean up
            setTimeout(() => testContainer.remove(), 100);
        });
    } catch (error) {
        // Failed to load QRCode library
    }

    // Check for 2FA requirements from PHP
    const requires2fa = document.getElementById('requires2fa')?.value === 'true';
    const requires2faSetup = document.getElementById('requires2faSetup')?.value === 'true';
    const twoFactorSecret = document.getElementById('twoFactorSecret')?.value;
    const twoFactorUserId = document.getElementById('twoFactorUserId')?.value;

    if (requires2faSetup && twoFactorSecret && twoFactorUserId) {
        // Generate QR code for 2FA setup
        const qrUrl = `otpauth://totp/Sign-um:User?secret=${twoFactorSecret}&issuer=Sign-um`;
        try {
            if (typeof QRCode !== 'undefined' && QRCode.toCanvas) {
                const container = document.getElementById('qrCodeContainer');
                if (container) {
                    // Clear any existing content
                    container.innerHTML = '';

                    // Create a canvas element for the QR code
                    const canvas = document.createElement('canvas');
                    canvas.width = 160;
                    canvas.height = 160;
                    container.appendChild(canvas);

                    QRCode.toCanvas(canvas, qrUrl, { width: 160, errorCorrectionLevel: 'M' }, function (error) {
                        if (error) {
                            // Fallback to Google Charts API
                            const img = document.createElement('img');
                            img.src = `https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                            img.onload = () => {
                                container.innerHTML = '';
                                container.appendChild(img);
                                // Always show text backup
                                addTextBackup(container, twoFactorSecret);
                            };
                            img.onerror = () => {
                                throw new Error('All QR code generation methods failed');
                            };
                        } else {
                            // Always show text backup below the visual QR
                            addTextBackup(container, twoFactorSecret);
                        }
                    });
                } else {
                    throw new Error('QR code container not found');
                }
            } else {
                // Fallback to Google Charts API directly
                const container = document.getElementById('qrCodeContainer');
                if (container) {
                    container.innerHTML = '';
                    const img = document.createElement('img');
                    img.src = `https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                    img.onload = () => {
                        // Google Charts QR code loaded successfully
                    };
                    img.onerror = () => {
                        throw new Error('Google Charts API failed');
                    };
                    container.appendChild(img);
                } else {
                    throw new Error('QR code container not found');
                }
            }
        } catch (error) {
            // Fallback: Show secret key as text
            const container = document.getElementById('qrCodeContainer');
            if (container) {
                container.innerHTML = `
                    <p class="text-warning">QR Code failed to generate. Manually add this secret to your authenticator app:</p>
                    <code class="d-block p-2 bg-light border">${twoFactorSecret}</code>
                    <small>Issuer: Sign-um, Account: User</small>
                `;
            }
        }
        window.tempUserId = twoFactorUserId;
        document.getElementById('loginButton').textContent = 'Complete 2FA Setup';
    } else if (requires2fa && twoFactorUserId) {
        window.tempUserId = twoFactorUserId;
        document.getElementById('loginButton').textContent = 'Verify 2FA';
    }

    // Attach submit handler and wire up main password eye button
    attachLoginSubmitHandler();
    const togglePasswordBtn = document.getElementById('togglePassword');
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function () { togglePasswordVisibility('password'); });
    }

    // Check if there's a PHP error message to display
    const errorDiv = document.getElementById('loginError');
    if (errorDiv && !errorDiv.classList.contains('d-none')) {
        // Check if it's a cooldown message
        const errorMessage = document.getElementById('loginErrorMessage');
        if (errorMessage && errorMessage.textContent.includes('Try again in')) {
            errorDiv.classList.remove('alert-danger');
            errorDiv.classList.add('alert-warning');
            startCooldownTimer();
            disableFormForCooldown();
        }
    }
    // Make error message auto-hide after 5 seconds
    setTimeout(() => {
        hideLoginError();
    }, 5000);

    // Add event listeners for forgot password modal toggles
    const newPasswordToggle = document.getElementById('newPasswordResetToggle');
    const confirmPasswordToggle = document.getElementById('confirmPasswordResetToggle');

    if (newPasswordToggle) newPasswordToggle.addEventListener('click', function () { togglePasswordVisibility('newPasswordReset'); });
    if (confirmPasswordToggle) confirmPasswordToggle.addEventListener('click', function () { togglePasswordVisibility('confirmPasswordReset'); });

    // Attach forgot password form listener
    const forgotForm = document.getElementById('forgotPasswordForm');
    if (forgotForm) {
        forgotForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const userId = document.getElementById('forgotUserId').value.trim();
            hideForgotPasswordError();

            if (!userId) {
                showForgotPasswordError('Please enter your User ID.');
                return;
            }

            try {
                const response = await fetch(BASE_URL + 'api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'forgot_password', userId })
                });
                const data = await response.json();

                if (data.success) {
                    // Show masked contact info
                    document.getElementById('maskedEmail').textContent = data.maskedEmail;
                    document.getElementById('maskedPhone').textContent = data.maskedPhone;

                    // Remove demo alert - user checks email now
                    // Proceed to MFA step
                    document.getElementById('forgotPasswordStep1').style.display = 'none';
                    document.getElementById('forgotPasswordStep2').style.display = 'block';
                    startMfaTimer();
                } else {
                    showForgotPasswordError(data.message || 'User not found.');
                }
            } catch (error) {
                showForgotPasswordError('Server error. Please try again.');
            }
        });
    } else {
        // forgotPasswordForm not found
    }

    // Attach MFA verification form listener
    const mfaForm = document.getElementById('mfaVerificationForm');
    if (mfaForm) {
        mfaForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const code = document.getElementById('mfaCode').value;
            hideMfaError();

            if (!code || code.length !== 6 || !/^\d+$/.test(code)) {
                showMfaError('Please enter a valid 6-digit code.');
                return;
            }

            // Disable form during verification
            const submitBtn = mfaForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            }

            try {
                const response = await fetch(BASE_URL + 'api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'verify_forgot_password',
                        code: code
                    })
                });

                const data = await response.json();
                if (data.success) {
                    // Code verified successfully
                    document.getElementById('forgotPasswordStep2').style.display = 'none';
                    document.getElementById('forgotPasswordStep3').style.display = 'block';
                    clearInterval(mfaTimer);
                } else {
                    showMfaError(data.message || 'Invalid verification code.');
                }
            } catch (error) {
                showMfaError('Server error. Please try again.');
            } finally {
                // Re-enable form
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Verify Code';
                }
            }
        });
    }

    // Attach 2FA verification modal button
    const verify2faBtn = document.getElementById('verify2faBtn');
    if (verify2faBtn) {
        verify2faBtn.addEventListener('click', async function () {
            const code = document.getElementById('2faCode').value;
            if (!code || code.length !== 6) {
                const errorDiv = document.getElementById('2faVerifyError');
                const errorMsg = document.getElementById('2faVerifyErrorMessage');
                errorMsg.textContent = 'Please enter a valid 6-digit code';
                errorDiv.classList.remove('d-none');
                return;
            }

            // Disable button
            verify2faBtn.disabled = true;
            verify2faBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';

            try {
                const response = await fetch(BASE_URL + 'api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'verify_2fa',
                        user_id: window.tempUserId,
                        code: code
                    })
                });

                const data = await response.json();
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    const errorDiv = document.getElementById('2faVerifyError');
                    const errorMsg = document.getElementById('2faVerifyErrorMessage');
                    errorMsg.textContent = data.message || 'Invalid code. Please try again.';
                    errorDiv.classList.remove('d-none');
                }
            } catch (error) {
                const errorDiv = document.getElementById('2faVerifyError');
                const errorMsg = document.getElementById('2faVerifyErrorMessage');
                errorMsg.textContent = 'Server error. Please try again.';
                errorDiv.classList.remove('d-none');
            } finally {
                verify2faBtn.disabled = false;
                verify2faBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Verify Code';
            }
        });
    }

    // Attach 2FA setup modal button
    const complete2faBtn = document.getElementById('complete2faSetupBtn');
    if (complete2faBtn) {
        complete2faBtn.addEventListener('click', async function () {
            const code = document.getElementById('2faSetupCode').value;
            if (!code || code.length !== 6) {
                const errorDiv = document.getElementById('2faSetupError');
                const errorMsg = document.getElementById('2faSetupErrorMessage');
                errorMsg.textContent = 'Please enter a valid 6-digit code';
                errorDiv.classList.remove('d-none');
                return;
            }

            // Disable button
            complete2faBtn.disabled = true;
            complete2faBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Completing Setup...';

            try {
                const response = await fetch(BASE_URL + 'api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'setup_2fa',
                        user_id: window.tempUserId,
                        code: code
                    })
                });

                const data = await response.json();
                if (data.success) {
                    const setupModal = bootstrap.Modal.getInstance(document.getElementById('2faSetupModal'));
                    if (setupModal) setupModal.hide();
                    showLoginSuccess('2FA setup completed! Redirecting...');
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1500);
                } else {
                    const errorDiv = document.getElementById('2faSetupError');
                    const errorMsg = document.getElementById('2faSetupErrorMessage');
                    errorMsg.textContent = data.message || 'Invalid code. Please try again.';
                    errorDiv.classList.remove('d-none');
                }
            } catch (error) {
                const errorDiv = document.getElementById('2faSetupError');
                const errorMsg = document.getElementById('2faSetupErrorMessage');
                errorMsg.textContent = 'Server error. Please try again.';
                errorDiv.classList.remove('d-none');
            } finally {
                complete2faBtn.disabled = false;
                complete2faBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Complete Setup';
            }
        });
    }

    // Allow Enter key to submit in 2FA modals
    document.getElementById('2faCode')?.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('verify2faBtn')?.click();
        }
    });

    document.getElementById('2faSetupCode')?.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('complete2faSetupBtn')?.click();
        }
    });

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
                const response = await fetch(BASE_URL + 'api/auth.php', {
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
    }

    // Add event listeners for modal close events to reset form state
    const verifyModalEl = document.getElementById('2faVerificationModal');
    const setupModalEl = document.getElementById('2faSetupModal');

    if (verifyModalEl) {
        verifyModalEl.addEventListener('hidden.bs.modal', function () {
            resetLoginForm();
        });
    }

    if (setupModalEl) {
        setupModalEl.addEventListener('hidden.bs.modal', function () {
            resetLoginForm();
        });
    }
});

// Helper function to reset login form to initial state
function resetLoginForm() {
    // Close any open 2FA modals
    const verifyModal = bootstrap.Modal.getInstance(document.getElementById('2faVerificationModal'));
    if (verifyModal) verifyModal.hide();

    const setupModal = bootstrap.Modal.getInstance(document.getElementById('2faSetupModal'));
    if (setupModal) setupModal.hide();

    // Clear form fields
    document.getElementById('userId').value = '';
    document.getElementById('password').value = '';
    document.getElementById('2faCode').value = '';
    document.getElementById('2faSetupCode').value = '';

    // Reset button text
    document.getElementById('loginButton').textContent = 'Sign In';

    // Clear any temp data
    window.tempUserId = null;

    // Clear QR code container
    const qrContainer = document.getElementById('qrCodeContainer');
    if (qrContainer) {
        qrContainer.innerHTML = '';
    }

    // Clear any error messages
    const errorElement = document.getElementById('loginError');
    if (errorElement) {
        errorElement.style.display = 'none';
    }

    // Clear success messages
    const successElement = document.getElementById('loginSuccess');
    if (successElement) {
        successElement.style.display = 'none';
    }
}

// 2FA Inline Functions (removed - now handled by PHP and simple QR generation)

// Helper functions for MFA error handling
function hideMfaError() {
    const errorDiv = document.getElementById('mfaError');
    if (errorDiv) {
        errorDiv.classList.add('d-none');
    }
}

function showMfaError(message) {
    const errorDiv = document.getElementById('mfaError');
    const errorMsg = document.getElementById('mfaErrorMessage');
    if (errorDiv && errorMsg) {
        errorMsg.textContent = message;
        errorDiv.classList.remove('d-none');
    }
}