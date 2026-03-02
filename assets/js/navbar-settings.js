(function () {
    const BASE_URL = window.BASE_URL || '/';
    const byId = id => document.getElementById(id);
    const toast = (msg, type = 'info') => window.ToastManager?.show ? window.ToastManager.show({ message: msg, type, duration: 3000 }) : alert(msg);
    const getModal = id => byId(id) ? new bootstrap.Modal(byId(id)) : null;
    const closeModal = id => bootstrap.Modal.getInstance(byId(id))?.hide();
    const setMsg = (id, html) => { if (byId(id)) byId(id).innerHTML = html; };
    const apiPost = async (url, body) => await (await fetch(BASE_URL + url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();

    const PREFS = { emailNotifications: 'emailNotifications', browserNotifications: 'browserNotifications', defaultView: 'defaultView', timezone: 'timezone', autoRefresh: 'trackDoc_autoRefresh', showRejectedNotes: 'trackDoc_showRejectedNotes', itemsPerPagePref: 'trackDoc_itemsPerPage', compactView: 'trackDoc_compactView', showStats: 'trackDoc_showStats', autoPreview: 'uploadPub_autoPreview', confirmBeforeSubmit: 'uploadPub_confirmBeforeSubmit', showFileDescriptions: 'uploadPub_showFileDescriptions', maxFilesPerUpload: 'uploadPub_maxFilesPerUpload', showStorageWarnings: 'uploadPub_showStorageWarnings', autoCompress: 'uploadPub_autoCompress' };

    const validateField = (el, type) => {
        const valid = type === 'Email' ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim()) : /^(09|\+639)\d{9}$/.test(el.value.trim());
        el.classList.toggle('is-valid', valid && el.value.trim()); el.classList.toggle('is-invalid', !valid && el.value.trim());
        return valid;
    };
    const blockChars = (e, type) => { if ((type === 'Email' && !/[a-zA-Z0-9._%+-@]/.test(e.key)) || (type === 'Phone' && !/[0-9+]/.test(e.key))) if (e.key.length === 1) e.preventDefault(); };

    window.openProfileSettings = () => {
        const u = window.currentUser || {};
        ['FirstName', 'LastName', 'Email', 'Phone', 'Position'].forEach(f => {
            const el = byId('profile' + f);
            if (el) {
                el.value = u[f.charAt(0).toLowerCase() + f.slice(1)] || u[f.replace(/([A-Z])/g, "_$1").toLowerCase()] || el.value;
                if (['Email', 'Phone'].includes(f) && !el.dataset.bound) { el.addEventListener('input', () => validateField(el, f)); el.addEventListener('keypress', e => blockChars(e, f)); el.dataset.bound = 1; }
            }
        });
        if (byId('darkModeToggle')) byId('darkModeToggle').checked = localStorage.getItem('darkMode') === 'true';
        getModal('profileSettingsModal')?.show();
    };

    window.openChangePassword = () => {
        if (!byId('changePasswordModal')) document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="changePasswordModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-key me-2"></i>Change Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form id="changePasswordForm"><div class="modal-body"><div id="changePasswordMessages"></div>${['currentPassword', 'newPassword', 'confirmPassword'].map(id => `<div class="mb-3"><label class="form-label">${id.replace(/([A-Z])/g, ' $1').replace(/^./, s => s.toUpperCase())}</label><input type="password" class="form-control" id="${id}" required></div>`).join('')}</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form></div></div></div>`);
        getModal('changePasswordModal')?.show();
    };

    window.openPreferences = () => { Object.entries(PREFS).forEach(([id, k]) => { const el = byId(id); if (el && localStorage.getItem(k) !== null) el.type === 'checkbox' ? el.checked = (localStorage.getItem(k) === 'true') : el.value = localStorage.getItem(k); }); getModal('preferencesModal')?.show(); };
    window.showHelp = () => getModal('helpModal')?.show();

    window.savePreferences = () => {
        Object.entries(PREFS).forEach(([id, k]) => { const el = byId(id); if (el) localStorage.setItem(k, el.type === 'checkbox' ? el.checked : el.value); });
        if (byId('itemsPerPagePref') && byId('itemsPerPage')) byId('itemsPerPage').value = byId('itemsPerPagePref').value;
        setMsg('preferencesMessages', '<div class="alert alert-success">Saved!</div>'); toast('Preferences saved', 'success'); setTimeout(() => { setMsg('preferencesMessages', ''); closeModal('preferencesModal'); }, 1500);
        window.renderCurrentPage?.(); window.applyTheme?.();
    };

    window.saveProfileSettings = async (e) => {
        e?.preventDefault();
        const em = byId('profileEmail')?.value.trim(), ph = byId('profilePhone')?.value.trim(), fn = byId('profileFirstName')?.value.trim(), ln = byId('profileLastName')?.value.trim();
        if ((byId('profileEmail') && !validateField(byId('profileEmail'), 'Email')) || (byId('profilePhone') && !validateField(byId('profilePhone'), 'Phone'))) return setMsg('profileSettingsMessages', '<div class="alert alert-danger">Fix Validation Errors</div>');
        const p = { action: 'update_profile', email: em, phone: ph }; if (window.currentUser?.role === 'admin') { p.first_name = fn; p.last_name = ln; }
        try {
            const r = await apiPost('api/users.php', p);
            if (!r.success) return setMsg('profileSettingsMessages', `<div class="alert alert-danger">${r.message}</div>`);
            localStorage.setItem('darkMode', !!byId('darkModeToggle')?.checked); setMsg('profileSettingsMessages', '<div class="alert alert-success">Updated!</div>'); toast('Profile updated', 'success');
            if (window.currentUser) Object.assign(window.currentUser, { email: em, phone: ph, firstName: fn, lastName: ln });
            ['userDisplayName', 'adminUserName'].forEach(id => { if (byId(id) && window.currentUser?.role === 'admin') byId(id).textContent = `${fn} ${ln}`; });
            setTimeout(() => { setMsg('profileSettingsMessages', ''); closeModal('profileSettingsModal'); }, 900);
        } catch (err) { toast('Server error', 'error'); }
    };

    document.addEventListener('submit', async e => {
        if (e.target.id === 'changePasswordForm') {
            e.preventDefault(); const c = byId('currentPassword').value, n = byId('newPassword').value, cf = byId('confirmPassword').value;
            if (!c || !n || n !== cf) return setMsg('changePasswordMessages', '<div class="alert alert-danger">Passwords mismatch or empty</div>');
            try {
                const r = await apiPost('api/auth.php', { action: 'change_password', current_password: c, new_password: n });
                if (!r.success) return setMsg('changePasswordMessages', `<div class="alert alert-danger">${r.message}</div>`);
                setMsg('changePasswordMessages', '<div class="alert alert-success">Changed!</div>'); toast('Password changed', 'success');
                setTimeout(() => { setMsg('changePasswordMessages', ''); e.target.reset(); closeModal('changePasswordModal'); }, 900);
            } catch (err) { toast('Server error', 'error'); }
        }
    });

    window.NavbarSettings = { openProfileSettings, openChangePassword, openPreferences, showHelp, savePreferences, saveProfileSettings };
})();