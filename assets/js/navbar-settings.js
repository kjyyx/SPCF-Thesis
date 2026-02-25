(function () {
    const BASE_URL = window.BASE_URL || '/';

    function toast(message, type = 'info') {
        if (window.ToastManager?.show) {
            window.ToastManager.show({ message, type, duration: 3000 });
            return;
        }
        if (typeof window.showToast === 'function') {
            try { window.showToast(message, type); return; } catch (_) { }
        }
        console[type === 'error' ? 'error' : 'log'](message);
    }

    function getModal(id) {
        const el = document.getElementById(id);
        return el ? new bootstrap.Modal(el) : null;
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        const instance = bootstrap.Modal.getInstance(el);
        if (instance) instance.hide();
    }

    function setMessages(id, html) {
        const el = document.getElementById(id);
        if (el) el.innerHTML = html;
    }

    function ensureChangePasswordModal() {
        if (document.getElementById('changePasswordModal')) return;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-key me-2"></i>Change Password</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form id="changePasswordForm">
                            <div class="modal-body">
                                <div id="changePasswordMessages"></div>
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="currentPassword" required>
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="newPassword" required>
                                </div>
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirmPassword" required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(wrapper.firstElementChild);

        const form = document.getElementById('changePasswordForm');
        if (form && !form.dataset.navbarSettingsBound) {
            form.addEventListener('submit', saveChangePassword);
            form.dataset.navbarSettingsBound = '1';
        }
    }

    function populateProfileForm() {
        const user = window.currentUser || {};
        const firstName = user.firstName || user.first_name || '';
        const lastName = user.lastName || user.last_name || '';

        const first = document.getElementById('profileFirstName');
        const last = document.getElementById('profileLastName');
        const email = document.getElementById('profileEmail');
        const phone = document.getElementById('profilePhone');
        const position = document.getElementById('profilePosition');
        const darkMode = document.getElementById('darkModeToggle');

        if (first) first.value = firstName;
        if (last) last.value = lastName;
        if (email) email.value = user.email || '';
        if (phone) phone.value = user.phone || '';
        if (position && !position.value) position.value = user.position || '';
        if (darkMode) darkMode.checked = localStorage.getItem('darkMode') === 'true';
    }

    function openProfileSettings() {
        populateProfileForm();
        const modal = getModal('profileSettingsModal');
        if (!modal) return toast('Profile settings modal is not available on this page.', 'warning');
        modal.show();
        // Attach validation after modal is shown
        setTimeout(attachProfileValidation, 100);
    }

    function openChangePassword() {
        ensureChangePasswordModal();
        const existingForm = document.getElementById('changePasswordForm');
        if (existingForm && !existingForm.dataset.navbarSettingsBound) {
            existingForm.addEventListener('submit', saveChangePassword);
            existingForm.dataset.navbarSettingsBound = '1';
        }
        const modal = getModal('changePasswordModal');
        if (!modal) return toast('Change password is not available right now.', 'warning');
        modal.show();
    }

    function openPreferences() {
        const fieldMap = getPreferenceFieldMap();
        const keys = Object.keys(fieldMap);
        let loaded = 0;

        keys.forEach((id) => {
            const element = document.getElementById(id);
            if (!element) return;
            loaded++;
            const storageKey = fieldMap[id];
            const value = localStorage.getItem(storageKey);
            if (value === null) return;
            if (element.type === 'checkbox') {
                element.checked = value === 'true';
            } else {
                element.value = value;
            }
        });

        const modal = getModal('preferencesModal');
        if (!modal) return toast('Preferences modal is not available on this page.', 'warning');
        if (loaded === 0) toast('No configurable preferences found on this page.', 'warning');
        modal.show();
    }

    function showHelp() {
        const modal = getModal('helpModal');
        if (!modal) return toast('Help modal is not available on this page.', 'warning');
        modal.show();
    }

    function getPreferenceFieldMap() {
        return {
            emailNotifications: 'emailNotifications',
            browserNotifications: 'browserNotifications',
            defaultView: 'defaultView',
            timezone: 'timezone',
            autoRefresh: 'trackDoc_autoRefresh',
            showRejectedNotes: 'trackDoc_showRejectedNotes',
            itemsPerPagePref: 'trackDoc_itemsPerPage',
            compactView: 'trackDoc_compactView',
            showStats: 'trackDoc_showStats',
            autoPreview: 'uploadPub_autoPreview',
            confirmBeforeSubmit: 'uploadPub_confirmBeforeSubmit',
            showFileDescriptions: 'uploadPub_showFileDescriptions',
            maxFilesPerUpload: 'uploadPub_maxFilesPerUpload',
            showStorageWarnings: 'uploadPub_showStorageWarnings',
            autoCompress: 'uploadPub_autoCompress'
        };
    }

    function savePreferences() {
        const fieldMap = getPreferenceFieldMap();
        let saved = 0;

        Object.entries(fieldMap).forEach(([id, storageKey]) => {
            const element = document.getElementById(id);
            if (!element) return;
            saved++;
            const value = element.type === 'checkbox' ? String(element.checked) : String(element.value ?? '');
            localStorage.setItem(storageKey, value);
        });

        if (saved === 0) return toast('No preferences to save on this page.', 'warning');

        const itemsPerPagePref = document.getElementById('itemsPerPagePref');
        const itemsPerPage = document.getElementById('itemsPerPage');
        if (itemsPerPagePref && itemsPerPage) itemsPerPage.value = itemsPerPagePref.value;

        setMessages('preferencesMessages', '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Preferences saved successfully!</div>');
        toast('Preferences saved successfully', 'success');
        setTimeout(() => setMessages('preferencesMessages', ''), 2500);
        closeModal('preferencesModal');

        if (typeof window.renderCurrentPage === 'function') {
            try { window.renderCurrentPage(); } catch (_) { }
        }
        if (typeof window.applyTheme === 'function') {
            try { window.applyTheme(); } catch (_) { }
        }
    }

    // Real-time validation functions
    function validateEmail(input) {
        const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        const isValid = emailRegex.test(input.value.trim());
        input.classList.toggle('is-valid', isValid && input.value.trim());
        input.classList.toggle('is-invalid', !isValid && input.value.trim());
        return isValid;
    }

    function validatePhone(input) {
        const phoneRegex = /^(09|\+639)\d{9}$/;
        const isValid = phoneRegex.test(input.value.trim());
        input.classList.toggle('is-valid', isValid && input.value.trim());
        input.classList.toggle('is-invalid', !isValid && input.value.trim());
        return isValid;
    }

    function preventInvalidEmailKeypress(event) {
        const allowedChars = /[a-zA-Z0-9._%+-@]/;
        if (!allowedChars.test(event.key) && !['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
        }
    }

    function preventInvalidPhoneKeypress(event) {
        const allowedChars = /[0-9+]/;
        if (!allowedChars.test(event.key) && !['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
        }
    }

    // Attach validation to profile modal when opened
    function attachProfileValidation() {
        const emailInput = document.getElementById('profileEmail');
        const phoneInput = document.getElementById('profilePhone');

        if (emailInput) {
            emailInput.addEventListener('input', () => validateEmail(emailInput));
            emailInput.addEventListener('keypress', preventInvalidEmailKeypress);
        }

        if (phoneInput) {
            phoneInput.addEventListener('input', () => validatePhone(phoneInput));
            phoneInput.addEventListener('keypress', preventInvalidPhoneKeypress);
        }
    }

    // Modify saveProfileSettings to validate before submit
    async function saveProfileSettings(event) {
        if (event?.preventDefault) event.preventDefault();

        const emailInput = document.getElementById('profileEmail');
        const phoneInput = document.getElementById('profilePhone');

        const emailValid = emailInput ? validateEmail(emailInput) : true;
        const phoneValid = phoneInput ? validatePhone(phoneInput) : true;

        if (!emailValid || !phoneValid) {
            setMessages('profileSettingsMessages', '<div class="alert alert-danger">Please correct the validation errors before saving.</div>');
            return;
        }

        const firstName = (document.getElementById('profileFirstName')?.value || '').trim();
        const lastName = (document.getElementById('profileLastName')?.value || '').trim();
        const email = (emailInput?.value || '').trim();
        const phone = (phoneInput?.value || '').trim();
        const darkMode = !!document.getElementById('darkModeToggle')?.checked;

        if (!email) {
            setMessages('profileSettingsMessages', '<div class="alert alert-danger">Email is required.</div>');
            return;
        }

        // Only validate names for admins
        if (window.currentUser?.role === 'admin' && (!firstName || !lastName)) {
            setMessages('profileSettingsMessages', '<div class="alert alert-danger">First name and last name are required.</div>');
            return;
        }

        try {
            const payload = {
                action: 'update_profile',
                email,
                phone
            };

            // Only include name updates for admins
            if (window.currentUser?.role === 'admin') {
                payload.first_name = firstName;
                payload.last_name = lastName;
            }

            const response = await fetch(BASE_URL + 'api/users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (!result.success) {
                const message = result.message || 'Failed to update profile.';
                setMessages('profileSettingsMessages', `<div class="alert alert-danger">${message}</div>`);
                return toast(message, 'error');
            }

            localStorage.setItem('darkMode', String(darkMode));
            setMessages('profileSettingsMessages', '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Profile updated successfully!</div>');
            toast('Profile updated successfully', 'success');

            if (window.currentUser) {
                window.currentUser.email = email;
                window.currentUser.phone = phone;
                // Only update names for admins
                if (window.currentUser.role === 'admin') {
                    window.currentUser.firstName = firstName;
                    window.currentUser.lastName = lastName;
                    window.currentUser.first_name = firstName;
                    window.currentUser.last_name = lastName;
                }
            }

            const nameEl = document.getElementById('userDisplayName') || document.getElementById('adminUserName');
            if (nameEl && window.currentUser?.role === 'admin') {
                nameEl.textContent = `${firstName} ${lastName}`;
            }

            setTimeout(() => {
                setMessages('profileSettingsMessages', '');
                closeModal('profileSettingsModal');
            }, 900);
        } catch (error) {
            setMessages('profileSettingsMessages', '<div class="alert alert-danger">Server error updating profile.</div>');
            toast('Server error updating profile', 'error');
        }
    }

    async function saveChangePassword(event) {
        if (event?.preventDefault) event.preventDefault();

        const currentPassword = document.getElementById('currentPassword')?.value || '';
        const newPassword = document.getElementById('newPassword')?.value || '';
        const confirmPassword = document.getElementById('confirmPassword')?.value || '';

        if (!currentPassword || !newPassword || !confirmPassword) {
            setMessages('changePasswordMessages', '<div class="alert alert-danger">All password fields are required.</div>');
            return;
        }

        if (newPassword !== confirmPassword) {
            setMessages('changePasswordMessages', '<div class="alert alert-danger">New passwords do not match.</div>');
            return;
        }

        try {
            const response = await fetch(BASE_URL + 'api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'change_password',
                    current_password: currentPassword,
                    new_password: newPassword
                })
            });
            const result = await response.json();

            if (!result.success) {
                const message = result.message || 'Failed to change password.';
                setMessages('changePasswordMessages', `<div class="alert alert-danger">${message}</div>`);
                return toast(message, 'error');
            }

            setMessages('changePasswordMessages', '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Password changed successfully!</div>');
            toast('Password changed successfully', 'success');

            setTimeout(() => {
                setMessages('changePasswordMessages', '');
                document.getElementById('changePasswordForm')?.reset();
                closeModal('changePasswordModal');
            }, 900);
        } catch (error) {
            setMessages('changePasswordMessages', '<div class="alert alert-danger">Server error changing password.</div>');
            toast('Server error changing password', 'error');
        }
    }

    window.NavbarSettings = {
        openProfileSettings,
        openChangePassword,
        openPreferences,
        showHelp,
        savePreferences,
        saveProfileSettings,
        saveChangePassword
    };

    window.openProfileSettings = openProfileSettings;
    window.openChangePassword = openChangePassword;
    window.openPreferences = openPreferences;
    window.showHelp = showHelp;
    window.savePreferences = savePreferences;
    window.saveProfileSettings = saveProfileSettings;
})();
