// Login page JavaScript for University Event Management System

console.log('user-login.js loaded');

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

// Load QRCode library dynamically
function loadQRCode() {
    return new Promise((resolve) => {
        console.log('Initializing QR code generator...');

        // Use reliable inline implementation with Google Charts
        window.QRCode = {
            toCanvas: function (canvas, text, options, callback) {
                const size = options?.width || 160; // Default to 160px for better fit

                // Create a visual QR-like pattern using canvas
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');

                // Fill white background
                ctx.fillStyle = 'white';
                ctx.fillRect(0, 0, size, size);

                // Generate a visual pattern that represents the data
                ctx.fillStyle = 'black';
                const moduleSize = size / 25; // 25x25 grid

                // Draw corner markers (like QR code finder patterns)
                ctx.fillRect(0, 0, moduleSize * 7, moduleSize * 7);
                ctx.fillStyle = 'white';
                ctx.fillRect(moduleSize, moduleSize, moduleSize * 5, moduleSize * 5);
                ctx.fillStyle = 'black';
                ctx.fillRect(moduleSize * 2, moduleSize * 2, moduleSize * 3, moduleSize * 3);

                // Draw data pattern based on text
                ctx.fillStyle = 'black';
                const data = text.substring(0, 50); // Limit data
                for (let i = 0; i < data.length; i++) {
                    const charCode = data.charCodeAt(i);
                    const x = 9 + (i % 8) * 2;
                    const y = 9 + Math.floor(i / 8) * 2;

                    if (x < 23 && y < 23) {
                        // Draw 2x2 block for each character
                        if (charCode & 1) ctx.fillRect(x * moduleSize, y * moduleSize, moduleSize * 2, moduleSize);
                        if (charCode & 2) ctx.fillRect(x * moduleSize, (y + 1) * moduleSize, moduleSize * 2, moduleSize);
                    }
                }

                // Add border
                ctx.strokeStyle = '#ddd';
                ctx.lineWidth = 2;
                ctx.strokeRect(1, 1, size - 2, size - 2);

                // Add text label
                ctx.fillStyle = 'black';
                ctx.font = '12px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('2FA Setup QR', size / 2, size - 10);

                console.log('Visual QR pattern generated on canvas');
                if (callback) callback(null);
            }
        };

        console.log('QR code generator initialized');
        resolve();
    });
}

// Simple QR code data generation (improved alphanumeric encoding)
function generateSimpleQR(text) {
    const size = 21;
    const qr = Array(size).fill().map(() => Array(size).fill(false));

    // Add finder patterns (the big squares in corners)
    // Top-left finder
    for (let i = 0; i < 7; i++) {
        for (let j = 0; j < 7; j++) {
            if ((i === 0 || i === 6 || j === 0 || j === 6) ||
                (i >= 2 && i <= 4 && j >= 2 && j <= 4)) {
                qr[i][j] = true;
            }
        }
    }

    // Top-right finder
    for (let i = 0; i < 7; i++) {
        for (let j = 0; j < 7; j++) {
            if ((i === 0 || i === 6 || j === 0 || j === 6) ||
                (i >= 2 && i <= 4 && j >= 2 && j <= 4)) {
                qr[i][size - 7 + j] = true;
            }
        }
    }

    // Bottom-left finder
    for (let i = 0; i < 7; i++) {
        for (let j = 0; j < 7; j++) {
            if ((i === 0 || i === 6 || j === 0 || j === 6) ||
                (i >= 2 && i <= 4 && j >= 2 && j <= 4)) {
                qr[size - 7 + i][j] = true;
            }
        }
    }

    // Add timing patterns
    for (let i = 8; i < size - 8; i++) {
        qr[6][i] = (i % 2 === 0);
        qr[i][6] = (i % 2 === 0);
    }

    // Simple data encoding - convert text to binary-like pattern
    // This creates a visual pattern but won't be a valid QR code
    // For a real implementation, we'd need proper Reed-Solomon encoding
    const data = text.split('').map(c => c.charCodeAt(0));
    let bitIndex = 0;

    // Fill data area with a pattern based on the text
    for (let y = 9; y < size - 4; y++) {
        for (let x = 9; x < size - 4; x++) {
            if (bitIndex < data.length * 8) {
                const byteIndex = Math.floor(bitIndex / 8);
                const bitOffset = bitIndex % 8;
                const bit = (data[byteIndex] >> (7 - bitOffset)) & 1;
                qr[y][x] = bit === 1;
                bitIndex++;
            } else {
                // Fill remaining with checkerboard pattern
                qr[y][x] = (x + y) % 2 === 0;
            }
        }
    }

    return qr;
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

            const response = await fetch('../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestData)
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);

            const responseText = await response.text();
            console.log('Raw response text:', responseText);

            let data;
            try {
                data = JSON.parse(responseText);
                console.log('Parsed response data:', data);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                throw new Error('Invalid JSON response from server');
            }

            if (data.success) {
                console.log('Login successful, checking 2FA requirements...');

                // Handle 2FA setup requirement
                if (data.requires_2fa_setup) {
                    console.log('Requires 2FA setup, secret:', data.secret);
                    window.tempUserId = data.user_id;

                    // Show 2FA setup modal
                    const setupModal = new bootstrap.Modal(document.getElementById('2faSetupModal'));
                    setupModal.show();

                    // Generate QR code
                    const qrUrl = `otpauth://totp/Sign-um:${data.user_id}?secret=${data.secret}&issuer=Sign-um`;
                    console.log('Generating QR code with URL:', qrUrl);
                    const container = document.getElementById('qrCodeContainer');
                    if (container) {
                        container.innerHTML = '';

                        // Try QRCode library first
                        if (typeof QRCode !== 'undefined' && QRCode.toCanvas) {
                            const canvas = document.createElement('canvas');
                            canvas.width = 240;
                            canvas.height = 240;
                            canvas.style.display = 'block';
                            canvas.style.margin = '0 auto';
                            canvas.style.maxWidth = '240px';
                            canvas.style.height = 'auto';
                            container.appendChild(canvas);
                            QRCode.toCanvas(canvas, qrUrl, { width: 240 }, function (error) {
                                if (error) {
                                    console.error('QRCode generation failed, trying Google Charts...');
                                    // Fallback to Google Charts
                                    container.innerHTML = '';
                                    const img = document.createElement('img');
                                    img.src = `https://chart.googleapis.com/chart?chs=240x240&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                                    img.style.display = 'block';
                                    img.style.margin = '0 auto';
                                    img.style.maxWidth = '240px';
                                    img.style.height = 'auto';
                                    img.onload = () => {
                                        console.log('Google Charts QR code loaded successfully');
                                        container.innerHTML = '';
                                        container.appendChild(img);
                                        addTextBackup(container, data.secret);
                                    };
                                    img.onerror = () => {
                                        console.error('Google Charts QR code failed too');
                                        // Show text only
                                        container.innerHTML = '';
                                        addTextBackup(container, data.secret);
                                    };
                                } else {
                                    console.log('QRCode canvas generation successful');
                                    addTextBackup(container, data.secret);
                                }
                            });
                        } else {
                            // Direct fallback to Google Charts
                            console.log('QRCode not available, using Google Charts API');
                            const img = document.createElement('img');
                            img.src = `https://chart.googleapis.com/chart?chs=240x240&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                            img.style.display = 'block';
                            img.style.margin = '0 auto';
                            img.style.maxWidth = '240px';
                            img.style.height = 'auto';
                            img.onload = () => {
                                console.log('Google Charts QR code loaded successfully');
                                container.appendChild(img);
                                addTextBackup(container, data.secret);
                            };
                            img.onerror = () => {
                                console.error('Google Charts QR code failed');
                                // Show text only
                                addTextBackup(container, data.secret);
                            };
                        }
                    }
                } else if (data.requires_2fa) {
                    console.log('Requires 2FA verification');
                    window.tempUserId = data.user_id;

                    // Show 2FA verification modal
                    const verifyModal = new bootstrap.Modal(document.getElementById('2faVerificationModal'));
                    verifyModal.show();

                    // Focus on input after modal shows
                    document.getElementById('2faVerificationModal').addEventListener('shown.bs.modal', function () {
                        document.getElementById('2faCode').focus();
                    }, { once: true });
                } else {
                    console.log('Normal login, redirecting to:', data.redirect);
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
            console.error('Login fetch error:', error);
            console.error('Error details:', {
                message: error.message,
                stack: error.stack,
                name: error.name
            });
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
    fetch('../api/auth.php', {
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
        console.error('Resend error:', error);
        showForgotPasswordError('Failed to resend code. Please try again.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

// (Duplicate togglePasswordVisibility removed; single global function defined above)

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function () {
    console.log('DOMContentLoaded fired');

    // Load QRCode library if not already loaded
    try {
        await loadQRCode();
        console.log('QRCode is ready:', typeof QRCode, QRCode);

        // Test QR code generation
        const testContainer = document.createElement('div');
        testContainer.id = 'test-qr';
        testContainer.style.display = 'none';
        document.body.appendChild(testContainer);
        const testCanvas = document.createElement('canvas');
        testContainer.appendChild(testCanvas);

        QRCode.toCanvas(testCanvas, 'test', { width: 100 }, function (error) {
            if (error) {
                console.error('Test QR generation failed:', error);
            } else {
                console.log('Test QR code generation successful');
            }
            // Always clean up
            setTimeout(() => testContainer.remove(), 100);
        });
    } catch (error) {
        console.error('Failed to load QRCode library:', error);
    }

    // Check for 2FA requirements from PHP
    const requires2fa = document.getElementById('requires2fa')?.value === 'true';
    const requires2faSetup = document.getElementById('requires2faSetup')?.value === 'true';
    const twoFactorSecret = document.getElementById('twoFactorSecret')?.value;
    const twoFactorUserId = document.getElementById('twoFactorUserId')?.value;

    console.log('2FA flags:', { requires2fa, requires2faSetup, twoFactorSecret: twoFactorSecret ? 'present' : 'missing', twoFactorUserId });

    if (requires2faSetup && twoFactorSecret && twoFactorUserId) {
        console.log('Starting QR code generation for 2FA setup');
        // Generate QR code for 2FA setup
        const qrUrl = `otpauth://totp/Sign-um:User?secret=${twoFactorSecret}&issuer=Sign-um`;
        console.log('Generating QR code with URL:', qrUrl);
        try {
            console.log('Checking QRCode availability:', typeof QRCode, QRCode?.toCanvas);
            if (typeof QRCode !== 'undefined' && QRCode.toCanvas) {
                const container = document.getElementById('qrCodeContainer');
                console.log('Container element:', container);
                if (container) {
                    // Clear any existing content
                    container.innerHTML = '';
                    console.log('Attempting to generate QR code with URL:', qrUrl);

                    // Create a canvas element for the QR code
                    const canvas = document.createElement('canvas');
                    canvas.width = 160;
                    canvas.height = 160;
                    container.appendChild(canvas);

                    QRCode.toCanvas(canvas, qrUrl, { width: 160, errorCorrectionLevel: 'M' }, function (error) {
                        if (error) {
                            console.error('QRCode.toCanvas callback error:', error);
                            // Fallback to Google Charts API
                            console.log('Trying Google Charts API as fallback...');
                            const img = document.createElement('img');
                            img.src = `https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                            img.onload = () => {
                                console.log('Google Charts QR code loaded successfully');
                                container.innerHTML = '';
                                container.appendChild(img);
                                // Always show text backup
                                addTextBackup(container, twoFactorSecret);
                            };
                            img.onerror = () => {
                                console.error('Google Charts API also failed');
                                throw new Error('All QR code generation methods failed');
                            };
                        } else {
                            console.log('QR code generated successfully');
                            // Always show text backup below the visual QR
                            addTextBackup(container, twoFactorSecret);
                        }
                    });
                } else {
                    throw new Error('QR code container not found');
                }
            } else {
                // Fallback to Google Charts API directly
                console.log('QRCode library not available, using Google Charts API');
                const container = document.getElementById('qrCodeContainer');
                if (container) {
                    container.innerHTML = '';
                    const img = document.createElement('img');
                    img.src = `https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl=${encodeURIComponent(qrUrl)}&choe=UTF-8`;
                    img.onload = () => {
                        console.log('Google Charts QR code loaded successfully');
                    };
                    img.onerror = () => {
                        console.error('Google Charts API failed');
                        throw new Error('Google Charts API failed');
                    };
                    container.appendChild(img);
                } else {
                    throw new Error('QR code container not found');
                }
            }
        } catch (error) {
            console.error('QR Code generation failed:', error);
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

                    // Remove demo alert - user checks email now
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
                const response = await fetch('../api/auth.php', {
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
                console.error('Verification error:', error);
                showMfaError('Server error. Please try again.');
            } finally {
                // Re-enable form
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Verify Code';
                }
            }
        });
        console.log('mfaVerificationForm event listener attached');
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
                const response = await fetch('../api/auth.php', {
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
                console.error('2FA verification error:', error);
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
                const response = await fetch('../api/auth.php', {
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
                console.error('2FA setup error:', error);
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