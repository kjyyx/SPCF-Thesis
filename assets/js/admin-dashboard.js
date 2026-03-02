/**
 * Admin Dashboard JavaScript - Refactored & Stabilized (Premium View Edition)
 * Handles User CRUD, Documents, Materials Management, Audit logging.
 */

// === Global Variables & Configuration ===
var BASE_URL = window.BASE_URL || (window.location.origin + '/SPCF-Thesis/');
let currentUser = null;
let publicMaterials = [];
let publicDocuments = [];
let auditLogs = [];
let users = {};

// UI State
let editingUserId = null, selectedUserRole = null, editingUserOriginalRole = null;
let selectedMaterials = [], currentMaterialId = null;
let selectedDocuments = [], currentDocumentId = null;

// Filter & Pagination State
let currentUserRoleFilter = null, currentUserSearch = null, currentUserStatus = null, currentUserStartDate = null, currentUserEndDate = null;
let currentMaterialsStatus = null, currentMaterialsSearch = null, currentMaterialsDept = null;
let currentDocsStatus = null, currentDocsSearch = null, currentDocsDept = null, currentDocsType = null;
let currentAuditCategory = null, currentAuditSearch = null, currentAuditSeverity = null, currentAuditStartDate = null, currentAuditEndDate = null;

let currentUsersPage = 1, usersPageSize = 50, totalUsersPages = 1;
let currentMaterialsPage = 1, materialsPageSize = 50, totalMaterialsPages = 1;
let currentDocsPage = 1, docsPageSize = 50, totalDocsPages = 1;
let currentAuditPage = 1, auditPageSize = 50, totalAuditPages = 1;

// API Endpoints
const USERS_API = BASE_URL + 'api/users.php';
const MATERIALS_API = BASE_URL + 'api/materials.php';
const DOCUMENTS_API = BASE_URL + 'api/documents.php';
const ADMIN_DATA_API = BASE_URL + 'api/admin_data.php';
const AUDIT_API = BASE_URL + 'api/audit.php';

const byId = id => document.getElementById(id);

// === Smart Dean ID Generator ===
function updateDynamicUserID() {
    // Only auto-generate if we are ADDING a new user, not editing
    if (editingUserId) return;

    const role = selectedUserRole;
    const idInput = byId('userIdInput');
    if (!idInput) return;

    let prefix = '';
    let suffix = '01'; // Default suffix for employees
    let deptCode = '';

    // Department Code Mapper
    const getDeptCode = (fullDeptName) => {
        if (!fullDeptName) return '';
        if (fullDeptName.includes('Engineering')) return 'COE';
        if (fullDeptName.includes('Nursing')) return 'CON';
        if (fullDeptName.includes('Business')) return 'COB';
        if (fullDeptName.includes('Criminology')) return 'COC';
        if (fullDeptName.includes('Computing')) return 'CCIS';
        if (fullDeptName.includes('Arts, Social Sciences')) return 'CASSE';
        if (fullDeptName.includes('Hospitality')) return 'CHTM';
        if (fullDeptName.includes('Miranda')) return 'MIRANDA';
        return '';
    };

    if (role === 'admin') {
        prefix = 'ADM';
        suffix = '001';
    }
    else if (role === 'student') {
        const position = byId('studentPosition').value;
        const dept = byId('studentDepartment').value;

        if (position === 'Supreme Student Council President') {
            prefix = 'SSC';
            deptCode = '';
        } else {
            prefix = 'CSC';
            deptCode = getDeptCode(dept);
        }
        suffix = '001'; // Students use 001
    }
    else if (role === 'employee') {
        const position = byId('employeePosition').value;
        const dept = byId('employeeDepartment').value;

        if (position === 'College Dean') { prefix = 'DEAN'; deptCode = getDeptCode(dept); }
        else if (position === 'College Student Council Adviser') { prefix = 'ADV'; deptCode = getDeptCode(dept); }
        else if (position.includes('Accounting Personnel')) prefix = 'AP';
        else if (position.includes('OIC-OSA')) prefix = 'OSA';
        else if (position.includes('CPAO')) prefix = 'CPAO';
        else if (position.includes('VPAA')) prefix = 'VPAA';
        else if (position.includes('EVP')) prefix = 'EVP';
        else if (position.includes('PPFO')) prefix = 'PPFO';
        else if (position.includes('Information Office')) prefix = 'INFO';
        else if (position.includes('Information Technology')) prefix = 'ITS';
        else if (position.includes('Technical Support')) prefix = 'TECH';
        else if (position.includes('Security Head')) prefix = 'SEC';
        else prefix = 'EMP'; // Fallback
    }

    // Combine them and put it in the input box!
    idInput.value = `${prefix}${deptCode}${suffix}`;
}

// --- Safe Modal Handling ---
const safeShowModal = (id) => {
    const el = byId(id);
    if (el && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(el);
        modal.show();
    }
};

const safeHideModal = (id) => {
    const el = byId(id);
    if (el && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getInstance(el);
        if (modal) modal.hide();
    }
};

window.showToast = function (message, type = 'info') {
    if (window.ToastManager) window.ToastManager.show({ type, message, duration: 4000 });
    else alert(message);
};

function showFormAlert(containerId, message, type = 'success') {
    const container = byId(containerId);
    if (container) {
        const icon = type === 'success' ? 'check-circle' : 'exclamation-triangle';
        container.innerHTML = `<div class="alert alert-${type}"><i class="bi bi-${icon} me-2"></i>${message}</div>`;
    }
}

async function apiFetch(url, options = {}) {
    const merged = { headers: { 'Content-Type': 'application/json' }, ...options };
    try {
        const resp = await fetch(url, merged);
        return await resp.json().catch(() => ({}));
    } catch (e) { throw e; }
}

function generatePagination(containerId, current, total, onClickStrFn) {
    const el = byId(containerId); if (!el) return;
    if (total <= 1) { el.innerHTML = ''; return; }
    let html = `<li class="page-item ${current === 1 ? 'disabled' : ''}"><a class="page-link rounded-start-pill" href="#" onclick="${onClickStrFn(current - 1)}; return false;">Prev</a></li>`;
    for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
        html += `<li class="page-item ${i === current ? 'active' : ''}"><a class="page-link" href="#" onclick="${onClickStrFn(i)}; return false;">${i}</a></li>`;
    }
    html += `<li class="page-item ${current === total ? 'disabled' : ''}"><a class="page-link rounded-end-pill" href="#" onclick="${onClickStrFn(current + 1)}; return false;">Next</a></li>`;
    el.innerHTML = html;
}

function newSearchParams(obj) {
    const params = new URLSearchParams();
    for (let key in obj) { if (obj[key] !== null && obj[key] !== undefined && obj[key] !== '') params.append(key, obj[key]); }
    return params;
}

// === Validation Functions (Fixed hoisting so they can be called globally) ===
function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
function normalizePhone(input) {
    return input ? input.replace(/[\s\-().]/g, '') : '';
}
function isValidPHPhone(phone) {
    return /^(09|\+639)\d{9}$/.test(normalizePhone(phone));
}

function validateUserEmail(input) {
    const valid = isValidEmail(input.value.trim());
    input.classList.toggle('is-valid', valid && input.value.trim());
    input.classList.toggle('is-invalid', !valid && input.value.trim());
}

function validateUserPhone(input) {
    const valid = isValidPHPhone(input.value.trim());
    input.classList.toggle('is-valid', valid && input.value.trim());
    input.classList.toggle('is-invalid', !valid && input.value.trim());
}

function preventInvalidUserEmailKeypress(e) { if (!/[a-zA-Z0-9._%+-@]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight'].includes(e.key)) e.preventDefault(); }
function preventInvalidUserPhoneKeypress(e) { if (!/[0-9+]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight'].includes(e.key)) e.preventDefault(); }


// === Dynamic Confirm Modal Helper ===
function promptConfirmAction(title, message, btnText, btnClass, iconClass, callback) {
    const modalEl = byId('deleteConfirmModal');
    if (!modalEl) return;

    const titleEl = byId('confirmModalTitle');
    const iconEl = byId('confirmModalIcon');
    const msgEl = byId('deleteConfirmMessage');
    const btnEl = byId('confirmDeleteBtn');

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (iconEl) iconEl.className = `bi ${iconClass} mb-3`;

    if (btnEl) {
        btnEl.className = `btn ${btnClass} rounded-pill px-4 shadow-sm`;
        btnEl.innerHTML = btnText;
        btnEl.onclick = callback;
    }

    safeShowModal('deleteConfirmModal');
}

// === Audit Log Management ===
window.addAuditLog = async function (action, category, details, targetId = null, targetType = null, severity = 'INFO') {
    try { await apiFetch(AUDIT_API, { method: 'POST', body: JSON.stringify({ action, category, details, target_id: targetId, target_type: targetType, severity }) }); } catch (e) { }
};

function createAuditLogRow(e) {
    const row = document.createElement('tr');
    const sc = { 'INFO': 'bg-info bg-opacity-10 text-info', 'WARNING': 'bg-warning bg-opacity-10 text-warning', 'ERROR': 'bg-danger bg-opacity-10 text-danger', 'CRITICAL': 'bg-dark text-white' };
    row.innerHTML = `
        <td class="ps-4 text-xs text-muted">${new Date(e.timestamp).toLocaleString()}</td>
        <td><div class="fw-bold text-dark text-sm">${e.user_name || 'System'}</div><div class="text-xs text-muted">${e.user_id || '-'}</div></td>
        <td><div class="text-sm fw-medium">${e.action}</div></td>
        <td><span class="badge bg-secondary rounded-pill">${e.category}</span></td>
        <td><span class="badge ${sc[e.severity] || 'bg-secondary'} rounded-pill">${e.severity}</span></td>
        <td class="text-end pe-4"><button class="btn btn-ghost btn-sm text-primary rounded-circle" onclick="viewAuditDetails('${e.id}')"><i class="bi bi-eye"></i></button></td>`;
    return row;
}

window.viewAuditDetails = function (id) {
    const e = auditLogs.find(x => x.id == id); if (!e) return;
    const elTime = byId('auditDetailTimestamp'); if (elTime) elTime.textContent = new Date(e.timestamp).toLocaleString();
    const elUser = byId('auditDetailUser'); if (elUser) elUser.textContent = `${e.user_name || 'System'} (${e.user_id || 'N/A'}) - ${e.user_role || 'N/A'}`;
    const elAction = byId('auditDetailAction'); if (elAction) elAction.textContent = `${e.action} - ${e.category}`;
    const elSystem = byId('auditDetailSystem'); if (elSystem) elSystem.textContent = `IP: ${e.ip_address || 'N/A'} | Severity: ${e.severity}`;
    safeShowModal('auditDetailModal');
};

window.loadAuditLogs = async function (page = 1, category = null, search = null) {
    currentAuditCategory = category; currentAuditSearch = search; currentAuditPage = page;
    try {
        const p = newSearchParams({ page, limit: auditPageSize, category, search });
        const d = await apiFetch(`${AUDIT_API}?${p}`);
        if (d.success) {
            const tbody = byId('auditTableBody');
            if (tbody) {
                tbody.innerHTML = '';
                auditLogs = d.logs || []; totalAuditPages = d.totalPages || 1;
                auditLogs.forEach(entry => tbody.appendChild(createAuditLogRow(entry)));
                generatePagination('auditPagination', currentAuditPage, totalAuditPages, p => `loadAuditLogs(${p}, currentAuditCategory, currentAuditSearch)`);
            }
        }
    } catch (e) { }
};

window.filterAuditLog = function () { loadAuditLogs(1, byId('auditCategoryFilter')?.value, byId('auditSearch')?.value.trim()); };
window.searchAuditLog = function () { window.filterAuditLog(); };
window.clearAuditLog = function () {
    promptConfirmAction(
        'Clear Logs',
        'Delete all audit logs? This cannot be undone.',
        '<i class="bi bi-trash me-2"></i>Clear Logs',
        'btn-danger',
        'bi-exclamation-circle text-danger',
        async () => {
            const r = await apiFetch(AUDIT_API, { method: 'DELETE' });
            if (r.success) { window.loadAuditLogs(1); showToast('Logs Cleared', 'success'); safeHideModal('deleteConfirmModal'); }
        }
    );
};

// === User Management ===
window.loadUsersFromAPI = async function (role = null, page = 1, search = null) {
    currentUserRoleFilter = role; currentUserSearch = search; currentUsersPage = page;
    try {
        const p = newSearchParams({ page, limit: usersPageSize, role, search });
        const d = await apiFetch(`${USERS_API}?${p}`);
        if (d.success) {
            users = d.users || {}; totalUsersPages = d.totalPages || 1;
            const tbody = byId('usersTableBody');
            if (tbody) {
                tbody.innerHTML = '';
                Object.values(users).forEach(u => tbody.appendChild(createUserRow(u)));
                generatePagination('usersPagination', currentUsersPage, totalUsersPages, p => `loadUsersFromAPI(currentUserRoleFilter, ${p}, currentUserSearch)`);
            }
        }
    } catch (e) { }
};

function createUserRow(u) {
    const row = document.createElement('tr');
    const rc = { 'admin': 'bg-danger text-white', 'employee': 'bg-primary text-white', 'student': 'bg-success text-white' };
    const dept = u.role === 'student' ? u.department : (u.department || u.office || '-');
    const pos = u.position?.startsWith('Dean of') ? 'College Dean' : (u.position || 'General');
    const isActive = u.status === 'active';

    if (!isActive) row.classList.add('table-secondary', 'text-muted');

    row.innerHTML = `
        <td class="ps-4"><input type="checkbox" class="form-check-input user-checkbox" value="${u.id}" onchange="updateBulkSelection()"></td>
        <td><div class="fw-bold ${isActive ? 'text-dark' : 'text-muted'}">${u.id}</div></td>
        <td><div class="fw-bold ${isActive ? 'text-dark' : 'text-muted'}">${u.firstName} ${u.lastName}</div><div class="text-xs text-muted">${u.email}</div></td>
        <td><span class="badge ${isActive ? rc[u.role] : 'bg-secondary'} rounded-pill mb-1">${u.role.toUpperCase()}</span><div class="text-xs text-muted">${pos}</div></td>
        <td><div class="text-sm fw-medium text-truncate" style="max-width:200px;" title="${dept}">${dept}</div></td>
        <td>
            <div class="mb-1"><span class="badge ${isActive ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'} border border-opacity-25 rounded-pill px-2 status-badge">${isActive ? 'Active' : 'Inactive'}</span></div>
            ${isActive ? `<div><span class="badge ${u.twoFactorEnabled ? 'bg-info-subtle text-info border-info' : 'bg-light text-muted border-secondary'} border border-opacity-25 rounded-pill px-2"><i class="bi ${u.twoFactorEnabled ? 'bi-shield-check' : 'bi-shield-dash'}"></i> 2FA</span></div>` : ''}
        </td>
        <td class="text-end pe-4">
            <button class="btn btn-ghost btn-sm text-primary rounded-circle" onclick="editUser('${u.id}')" title="Edit & Manage"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-ghost btn-sm text-danger rounded-circle" onclick="deleteUser('${u.id}')" title="Delete User"><i class="bi bi-trash"></i></button>
        </td>`;
    return row;
}

window.filterUsers = function () { window.loadUsersFromAPI(byId('userRoleFilter')?.value, 1, byId('userSearch')?.value.trim()); };
window.searchUsers = function () { window.filterUsers(); };
window.refreshUserList = function () {
    ['userRoleFilter', 'userSearch', 'userStatusFilter', 'userDateFrom', 'userDateTo'].forEach(id => { if (byId(id)) byId(id).value = '' });
    window.loadUsersFromAPI(null, 1).then(() => window.updateDashboardOverview());
};

window.updateBulkSelection = function () {
    const l = document.querySelectorAll('.user-checkbox:checked').length;
    if (byId('bulkOperationsBar')) byId('bulkOperationsBar').style.setProperty('display', l > 0 ? 'flex' : 'none', 'important');
    if (byId('selectedCount')) byId('selectedCount').textContent = l;
};
window.toggleSelectAll = function () { document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = byId('selectAllUsers')?.checked); window.updateBulkSelection(); };
window.clearSelection = function () { document.querySelectorAll('.user-checkbox, #selectAllUsers').forEach(cb => cb.checked = false); window.updateBulkSelection(); };

window.deleteUser = function (id) {
    if (currentUser && currentUser.id === id) return showToast('Cannot delete yourself.', 'warning');
    promptConfirmAction(
        'Confirm Delete',
        `Are you sure you want to permanently delete user ${id}?`,
        '<i class="bi bi-trash me-2"></i>Delete',
        'btn-danger',
        'bi-exclamation-circle text-danger',
        async () => {
            const r = await apiFetch(`${USERS_API}?id=${encodeURIComponent(id)}&role=${encodeURIComponent(users[id].role)}`, { method: 'DELETE' });
            if (r.success) {
                addAuditLog('USER_DELETED', 'User Management', `Deleted user ${id}`);
                window.loadUsersFromAPI(currentUserRoleFilter, currentUsersPage, currentUserSearch);
                window.updateDashboardOverview();
                safeHideModal('deleteConfirmModal');
                showToast('User deleted', 'success');
            }
        }
    );
};

window.toggleUserStatus = function (id, newStatus) {
    safeHideModal('userModal'); // Close user modal so confirm shows cleanly
    const user = users[id];
    if (!user) return;

    if (currentUser && currentUser.id === id && newStatus === 'inactive') {
        return showToast('You cannot deactivate your own account.', 'warning');
    }

    const isActivating = newStatus === 'active';
    promptConfirmAction(
        isActivating ? 'Reactivate User' : 'Deactivate User',
        isActivating ? `Reactivate user ${user.firstName}? They will be able to log in again.` : `Deactivate user ${user.firstName}? They will not be able to log in.`,
        isActivating ? '<i class="bi bi-person-check me-2"></i>Reactivate' : '<i class="bi bi-person-slash me-2"></i>Deactivate',
        isActivating ? 'btn-success' : 'btn-danger',
        isActivating ? 'bi-person-check text-success' : 'bi-person-slash text-danger',
        async () => {
            const r = await apiFetch(USERS_API, { method: 'POST', body: JSON.stringify({ action: 'toggle_status', id: id, role: user.role, status: newStatus }) });
            if (r.success) {
                addAuditLog(`USER_${newStatus.toUpperCase()}D`, 'User Management', `${isActivating ? 'Reactivated' : 'Deactivated'} user ${id}`);
                window.loadUsersFromAPI(currentUserRoleFilter, currentUsersPage, currentUserSearch);
                showToast(`User ${isActivating ? 'reactivated' : 'deactivated'} successfully`, 'success');
                safeHideModal('deleteConfirmModal');
            } else showToast(r.message || 'Action failed', 'danger');
        }
    );
};

window.resetUser2FA = function (userId) {
    safeHideModal('userModal'); // Close user modal so confirm shows cleanly
    const user = users[userId];
    if (!user) return;

    promptConfirmAction(
        'Reset 2FA',
        `Reset 2FA for ${user.firstName}? They will need to set it up again on next login.`,
        '<i class="bi bi-shield-x me-2"></i>Reset 2FA',
        'btn-warning',
        'bi-shield-exclamation text-warning',
        async () => {
            const r = await apiFetch(`${USERS_API}`, { method: 'POST', body: JSON.stringify({ action: 'reset_2fa', id: userId, role: user.role }) });
            if (r.success) {
                addAuditLog('2FA_RESET', 'User Management', `Reset 2FA for user ${userId}`);
                window.loadUsersFromAPI(currentUserRoleFilter, currentUsersPage, currentUserSearch);
                showToast('2FA reset successfully', 'success');
                safeHideModal('deleteConfirmModal');
            } else showToast(r.message || 'Failed to reset 2FA', 'danger');
        }
    );
};

window.openAddUserModal = function () {
    editingUserId = null; selectedUserRole = 'student'; editingUserOriginalRole = null;
    if (byId('userForm')) byId('userForm').reset();
    if (byId('userModalLabel')) byId('userModalLabel').textContent = 'Add New User';
    if (byId('userIdInput')) { byId('userIdInput').value = ''; byId('userIdInput').readOnly = false; }

    if (byId('passwordSection')) byId('passwordSection').style.display = 'flex';
    if (byId('addUserNotice')) byId('addUserNotice').style.display = 'block';
    if (byId('editUserNotice')) byId('editUserNotice').style.display = 'none';
    if (byId('editSecuritySection')) byId('editSecuritySection').style.display = 'none'; // Hide security section

    document.querySelectorAll('.role-btn').forEach(btn => { btn.classList.remove('btn-primary', 'active'); btn.classList.add('btn-outline-primary'); });
    const studentBtn = document.querySelector(`[data-role="student"]`);
    if (studentBtn) { studentBtn.classList.remove('btn-outline-primary'); studentBtn.classList.add('btn-primary', 'active'); }

    window.selectUserRole('student');
    safeShowModal('userModal');
};

window.editUser = function (userId) {
    const user = users[userId]; if (!user) return;
    editingUserId = userId; selectedUserRole = user.role; editingUserOriginalRole = user.role;

    if (byId('userIdInput')) { byId('userIdInput').value = user.id; byId('userIdInput').readOnly = true; }
    if (byId('userFirstName')) byId('userFirstName').value = user.firstName || '';
    if (byId('userLastName')) byId('userLastName').value = user.lastName || '';
    if (byId('userEmail')) byId('userEmail').value = user.email || '';
    if (byId('userPhone')) byId('userPhone').value = user.phone || '';

    document.querySelectorAll('.role-btn').forEach(btn => { btn.classList.remove('btn-primary', 'active'); btn.classList.add('btn-outline-primary'); });
    const roleBtn = document.querySelector(`[data-role="${user.role}"]`);
    if (roleBtn) { roleBtn.classList.remove('btn-outline-primary'); roleBtn.classList.add('btn-primary', 'active'); }

    showRoleFields(user.role);

    setTimeout(() => {
        if (user.role === 'admin') {
            if (byId('adminOffice')) byId('adminOffice').value = user.office || '';
            if (byId('adminPosition')) byId('adminPosition').value = user.position || '';
        } else if (user.role === 'employee') {
            if (byId('employeePosition')) byId('employeePosition').value = user.position || '';
            if (byId('employeeDepartment')) byId('employeeDepartment').value = user.department || '';
        } else if (user.role === 'student') {
            if (byId('studentDepartment')) byId('studentDepartment').value = user.department || '';
            if (byId('studentPosition')) byId('studentPosition').value = user.position || '';
        }
    }, 50);

    if (byId('passwordSection')) byId('passwordSection').style.display = 'none';
    if (byId('addUserNotice')) byId('addUserNotice').style.display = 'none';
    if (byId('editUserNotice')) byId('editUserNotice').style.display = 'block';
    if (byId('userModalLabel')) byId('userModalLabel').textContent = 'Edit User';

    // Setup Edit Security Section
    const secSec = byId('editSecuritySection');
    if (secSec) {
        secSec.style.display = 'block';

        // 2FA Logic
        const btn2FA = byId('btnReset2FA');
        const txt2FA = byId('status2FAText');
        if (user.twoFactorEnabled) {
            if (btn2FA) { btn2FA.style.display = 'inline-flex'; btn2FA.onclick = () => window.resetUser2FA(user.id); }
            if (txt2FA) txt2FA.textContent = 'User currently has 2FA configured. Resetting forces re-registration.';
        } else {
            if (btn2FA) btn2FA.style.display = 'none';
            if (txt2FA) txt2FA.textContent = '2FA is not currently configured for this user.';
        }

        // Status Logic
        const btnStatus = byId('btnToggleStatus');
        const txtStatus = byId('statusAccountText');
        if (user.status === 'active') {
            if (txtStatus) txtStatus.textContent = 'Account is active and can log in.';
            if (btnStatus) { btnStatus.className = 'btn btn-sm btn-outline-danger rounded-pill px-3 shadow-sm'; btnStatus.innerHTML = '<i class="bi bi-person-slash me-1"></i> Deactivate User'; btnStatus.onclick = () => window.toggleUserStatus(user.id, 'inactive'); }
        } else {
            if (txtStatus) txtStatus.textContent = 'Account is deactivated (cannot log in).';
            if (btnStatus) { btnStatus.className = 'btn btn-sm btn-outline-success rounded-pill px-3 shadow-sm'; btnStatus.innerHTML = '<i class="bi bi-person-check me-1"></i> Reactivate User'; btnStatus.onclick = () => window.toggleUserStatus(user.id, 'active'); }
        }
    }

    safeShowModal('userModal');
};

window.selectUserRole = function (role) {
    selectedUserRole = role;
    document.querySelectorAll('.role-btn').forEach(btn => { btn.classList.remove('btn-primary', 'active'); btn.classList.add('btn-outline-primary'); });
    const activeBtn = document.querySelector(`[data-role="${role}"]`);
    if (activeBtn) { activeBtn.classList.remove('btn-outline-primary'); activeBtn.classList.add('btn-primary', 'active'); }
    showRoleFields(role);
};

function showRoleFields(role) {
    document.querySelectorAll('.role-fields').forEach(el => el.style.display = 'none');
    if (role === 'admin' && byId('adminFields')) byId('adminFields').style.display = 'block';
    else if (role === 'employee' && byId('employeeFields')) byId('employeeFields').style.display = 'block';
    else if (role === 'student' && byId('studentFields')) byId('studentFields').style.display = 'block';
}

function initUserFormDropdowns() {
    if (typeof APP_CONSTANTS === 'undefined') return;

    const populate = (id, items) => {
        const el = byId(id);
        if (!el) return;
        const placeholder = el.firstElementChild;
        el.innerHTML = '';
        if (placeholder) el.appendChild(placeholder);

        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item;
            el.appendChild(opt);
        });

        // --- NEW: Trigger the Smart ID generator when a dropdown changes ---
        el.addEventListener('change', updateDynamicUserID);
    };

    populate('adminOffice', APP_CONSTANTS.OFFICES);
    populate('adminPosition', APP_CONSTANTS.POSITIONS.admin);
    populate('employeeDepartment', APP_CONSTANTS.DEPARTMENTS);
    populate('employeePosition', APP_CONSTANTS.POSITIONS.employee);
    populate('studentDepartment', APP_CONSTANTS.DEPARTMENTS);
    populate('studentPosition', APP_CONSTANTS.POSITIONS.student);
}

// === Documents Management ===
window.loadDocuments = async function (page = 1, status = null, search = null, dept = null, type = null) {
    currentDocsStatus = status; currentDocsSearch = search; currentDocsDept = dept; currentDocsType = type; currentDocsPage = page;
    try {
        const p = newSearchParams({ type: 'documents', page, limit: docsPageSize, status, search, department: dept, doc_type: type });
        const d = await apiFetch(`${ADMIN_DATA_API}?${p}`);
        if (d.success) {
            publicDocuments = d.documents || []; totalDocsPages = d.totalPages || 1;
            const tbody = byId('documentsTableBody');
            if (tbody) {
                tbody.innerHTML = '';
                if (publicDocuments.length === 0) tbody.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No documents found.</td></tr>`;
                else publicDocuments.forEach(doc => tbody.appendChild(createDocumentRow(doc)));
                generatePagination('documentsPagination', currentDocsPage, totalDocsPages, p => `loadDocuments(${p}, currentDocsStatus, currentDocsSearch, currentDocsDept, currentDocsType)`);
            }
        }
    } catch (e) { }
};

function createDocumentRow(doc) {
    const row = document.createElement('tr');
    const sc = { 'pending': 'bg-warning text-dark', 'approved': 'bg-success text-white', 'rejected': 'bg-danger text-white' };
    row.innerHTML = `
        <td class="ps-4"><input type="checkbox" class="form-check-input document-checkbox" value="${doc.id}" onchange="updateSelectedDocuments()"></td>
        <td>
            <div class="stacked-cell-sub">ID: DOC-${doc.id}</div>
            <div class="stacked-cell-title text-truncate" style="max-width: 200px;" title="${doc.title}">${doc.title}</div>
            <span class="badge bg-secondary mt-1 px-2" style="font-size: 0.65rem;">${doc.doc_type || 'General'}</span>
        </td>
        <td>
            <div class="stacked-cell-title">${doc.submitted_by || 'Unknown'}</div>
            <div class="stacked-cell-sub text-truncate" style="max-width: 180px;" title="${doc.department}">${doc.department || 'N/A'}</div>
        </td>
        <td><span class="badge ${sc[doc.status]} rounded-pill px-3">${doc.status.toUpperCase()}</span></td>
        <td class="text-sm text-muted">${new Date(doc.uploaded_at).toLocaleDateString()}</td>
        <td class="text-end pe-4">
            <button class="btn btn-ghost btn-sm text-primary rounded-circle" onclick="viewDocumentDetails('${doc.id}')"><i class="bi bi-eye"></i></button>
            <button class="btn btn-ghost btn-sm text-danger rounded-circle" onclick="deleteSingleDocument('${doc.id}')"><i class="bi bi-trash"></i></button>
        </td>`;
    return row;
}

window.viewDocumentDetails = function (id) {
    const d = publicDocuments.find(x => x.id == id); if (!d) return;
    currentDocumentId = id;

    if (byId('docDetailId')) byId('docDetailId').textContent = `DOC-${d.id}`;
    if (byId('docDetailTitle')) byId('docDetailTitle').textContent = d.title;
    if (byId('docDetailType')) byId('docDetailType').textContent = d.doc_type || 'General Document';
    if (byId('docDetailStatus')) {
        byId('docDetailStatus').className = `badge bg-${d.status === 'approved' ? 'success' : d.status === 'rejected' ? 'danger' : 'warning'} px-3 rounded-pill`;
        byId('docDetailStatus').textContent = d.status.toUpperCase();
    }
    if (byId('docDetailSubmitter')) byId('docDetailSubmitter').textContent = d.submitted_by || 'Unknown';
    if (byId('docDetailDept')) byId('docDetailDept').textContent = d.department || 'N/A';
    if (byId('docDetailDate')) byId('docDetailDate').textContent = new Date(d.uploaded_at).toLocaleString();
    if (byId('docDetailFileName')) byId('docDetailFileName').textContent = d.file_path ? d.file_path.split('/').pop() : 'No File Attached';
    if (byId('docDetailDesc')) byId('docDetailDesc').textContent = d.description || 'No description provided for this document.';

    const revSec = byId('docReviewSection');
    if (revSec) {
        if (d.status === 'approved' || d.status === 'rejected') {
            revSec.style.display = 'block';
            revSec.className = `detail-block border-${d.status === 'approved' ? 'success' : 'danger'} bg-${d.status === 'approved' ? 'success' : 'danger'} bg-opacity-10`;
            if (byId('docReviewTitle')) byId('docReviewTitle').className = `detail-label text-${d.status === 'approved' ? 'success' : 'danger'} text-dark`;
            if (byId('docReviewer')) byId('docReviewer').textContent = d.approved_by_name || d.rejected_by || 'System Admin';
            if (byId('docReviewDate')) byId('docReviewDate').textContent = (d.approved_at || d.rejected_at) ? new Date(d.approved_at || d.rejected_at).toLocaleString() : 'N/A';

            const remarksContainer = byId('docReviewRemarksContainer');
            if (remarksContainer) {
                if (d.status === 'rejected' && d.rejection_reason) {
                    remarksContainer.style.display = 'block';
                    if (byId('docReviewRemarks')) byId('docReviewRemarks').textContent = d.rejection_reason;
                } else {
                    remarksContainer.style.display = 'none';
                }
            }
        } else {
            revSec.style.display = 'none';
        }
    }
    safeShowModal('documentDetailModal');
};

window.downloadDocument = function () {
    const d = publicDocuments.find(x => x.id == currentDocumentId);
    if (d && d.file_path) window.open(BASE_URL + d.file_path, '_blank');
    else showToast('File path not available.', 'warning');
};

window.deleteSingleDocument = function (id) {
    const targetId = id || currentDocumentId; if (!targetId) return;
    promptConfirmAction(
        'Confirm Delete',
        'Delete this document permanently?',
        '<i class="bi bi-trash me-2"></i>Delete',
        'btn-danger',
        'bi-exclamation-circle text-danger',
        async () => {
            const r = await apiFetch(`${DOCUMENTS_API}?id=${encodeURIComponent(targetId)}`, { method: 'DELETE' });
            if (r.success) {
                addAuditLog('DOCUMENT_DELETED', 'Documents', `Deleted DOC-${targetId}`);
                safeHideModal('documentDetailModal');
                safeHideModal('deleteConfirmModal');
                window.loadDocuments(currentDocsPage);
                window.updateDashboardOverview();
                showToast('Document deleted', 'success');
            }
        }
    );
};

window.filterDocuments = function () { window.loadDocuments(1, byId('documentStatusFilter')?.value, byId('documentSearch')?.value.trim(), byId('documentDeptFilter')?.value, byId('documentTypeFilter')?.value); };
window.searchDocuments = function () { window.filterDocuments(); };
window.refreshDocumentsList = function () { ['documentStatusFilter', 'documentSearch', 'documentDateFrom', 'documentDateTo', 'documentDeptFilter', 'documentTypeFilter'].forEach(id => { if (byId(id)) byId(id).value = '' }); window.loadDocuments(1); selectedDocuments = []; };

window.updateSelectedDocuments = function () {
    const l = document.querySelectorAll('.document-checkbox:checked').length;
    if (byId('bulkDeleteDocsBtn')) byId('bulkDeleteDocsBtn').disabled = l === 0;
};
window.toggleSelectAllDocuments = function () { document.querySelectorAll('.document-checkbox').forEach(cb => cb.checked = byId('selectAllDocuments').checked); window.updateSelectedDocuments(); };

// === Materials Management ===
window.loadMaterials = async function (page = 1, status = null, search = null, dept = null) {
    currentMaterialsStatus = status; currentMaterialsSearch = search; currentMaterialsDept = dept; currentMaterialsPage = page;
    try {
        const p = newSearchParams({ type: 'materials', page, limit: materialsPageSize, status, search, department: dept });
        const d = await apiFetch(`${ADMIN_DATA_API}?${p}`);
        if (d.success) {
            publicMaterials = d.materials || []; totalMaterialsPages = d.totalPages || 1;
            const tbody = byId('materialsTableBody');
            if (tbody) {
                tbody.innerHTML = '';
                if (publicMaterials.length === 0) tbody.innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No materials found.</td></tr>`;
                else publicMaterials.forEach(m => tbody.appendChild(createMaterialRow(m)));
                generatePagination('materialsPagination', currentMaterialsPage, totalMaterialsPages, p => `loadMaterials(${p}, currentMaterialsStatus, currentMaterialsSearch, currentMaterialsDept)`);
            }
        }
    } catch (e) { }
};

function createMaterialRow(m) {
    const row = document.createElement('tr');
    const sc = { 'pending': 'bg-warning text-dark', 'approved': 'bg-success text-white', 'rejected': 'bg-danger text-white' };
    row.innerHTML = `
        <td class="ps-4"><input type="checkbox" class="form-check-input material-checkbox" value="${m.id}" onchange="updateSelectedMaterials()"></td>
        <td>
            <div class="stacked-cell-sub">ID: MAT-${m.id}</div>
            <div class="stacked-cell-title text-truncate" style="max-width: 200px;" title="${m.title}">${m.title}</div>
        </td>
        <td>
            <div class="stacked-cell-title">${m.submitted_by || 'Unknown'}</div>
            <div class="stacked-cell-sub text-truncate" style="max-width: 180px;" title="${m.department}">${m.department || 'N/A'}</div>
        </td>
        <td><span class="badge ${sc[m.status]} rounded-pill px-3">${m.status.toUpperCase()}</span></td>
        <td class="text-sm text-muted">${new Date(m.uploaded_at).toLocaleDateString()}</td>
        <td class="text-end pe-4">
            <button class="btn btn-ghost btn-sm text-info rounded-circle" onclick="viewMaterialDetails('${m.id}')"><i class="bi bi-eye"></i></button>
            <button class="btn btn-ghost btn-sm text-danger rounded-circle" onclick="deleteSingleMaterial('${m.id}')"><i class="bi bi-trash"></i></button>
        </td>`;
    return row;
}

window.viewMaterialDetails = function (id) {
    const m = publicMaterials.find(x => x.id == id); if (!m) return;
    currentMaterialId = id;

    if (byId('materialDetailId')) byId('materialDetailId').textContent = `MAT-${m.id}`;
    if (byId('materialDetailTitle')) byId('materialDetailTitle').textContent = m.title;
    if (byId('materialDetailStatus')) {
        byId('materialDetailStatus').className = `badge bg-${m.status === 'approved' ? 'success' : m.status === 'rejected' ? 'danger' : 'warning'} px-3 rounded-pill`;
        byId('materialDetailStatus').textContent = m.status.toUpperCase();
    }
    if (byId('materialDetailSubmitter')) byId('materialDetailSubmitter').textContent = m.submitted_by || 'Unknown';
    if (byId('materialDetailDept')) byId('materialDetailDept').textContent = m.department || 'N/A';
    if (byId('materialDetailDate')) byId('materialDetailDate').textContent = new Date(m.uploaded_at).toLocaleString();
    if (byId('materialDetailFileName')) byId('materialDetailFileName').textContent = m.file_path ? m.file_path.split('/').pop() : 'No Image Attached';
    if (byId('materialDetailDesc')) byId('materialDetailDesc').textContent = m.description || 'No caption provided.';

    const revSec = byId('materialReviewSection');
    if (revSec) {
        if (m.status === 'approved' || m.status === 'rejected') {
            revSec.style.display = 'block';
            revSec.className = `detail-block border-${m.status === 'approved' ? 'success' : 'danger'} bg-${m.status === 'approved' ? 'success' : 'danger'} bg-opacity-10`;
            if (byId('materialReviewTitle')) byId('materialReviewTitle').className = `detail-label text-${m.status === 'approved' ? 'success' : 'danger'} text-dark`;
            if (byId('materialReviewer')) byId('materialReviewer').textContent = m.approved_by_name || m.rejected_by || 'System Admin';
            if (byId('materialReviewDate')) byId('materialReviewDate').textContent = (m.approved_at || m.rejected_at) ? new Date(m.approved_at || m.rejected_at).toLocaleString() : 'N/A';

            const remarksContainer = byId('materialReviewRemarksContainer');
            if (remarksContainer) {
                if (m.status === 'rejected' && m.rejection_reason) {
                    remarksContainer.style.display = 'block';
                    if (byId('materialReviewRemarks')) byId('materialReviewRemarks').textContent = m.rejection_reason;
                } else {
                    remarksContainer.style.display = 'none';
                }
            }
        } else {
            revSec.style.display = 'none';
        }
    }
    safeShowModal('materialDetailModal');
};

window.downloadMaterial = function () {
    const m = publicMaterials.find(x => x.id == currentMaterialId);
    if (m && m.file_path) window.open(BASE_URL + m.file_path, '_blank');
    else showToast('Image path not available.', 'warning');
};

window.deleteSingleMaterial = function (id) {
    const targetId = id || currentMaterialId; if (!targetId) return;
    promptConfirmAction(
        'Confirm Delete',
        'Delete this material permanently?',
        '<i class="bi bi-trash me-2"></i>Delete',
        'btn-danger',
        'bi-exclamation-circle text-danger',
        async () => {
            const r = await apiFetch(`${MATERIALS_API}?id=${encodeURIComponent(targetId)}`, { method: 'DELETE' });
            if (r.success) {
                addAuditLog('MATERIAL_DELETED', 'Materials', `Deleted MAT-${targetId}`);
                safeHideModal('materialDetailModal');
                safeHideModal('deleteConfirmModal');
                window.loadMaterials(currentMaterialsPage);
                window.updateDashboardOverview();
                showToast('Material deleted', 'success');
            }
        }
    );
};

window.filterMaterials = function () { window.loadMaterials(1, byId('materialStatusFilter')?.value, byId('materialSearch')?.value.trim(), byId('materialDeptFilter')?.value); };
window.searchMaterials = function () { window.filterMaterials(); };
window.refreshMaterialsList = function () { ['materialStatusFilter', 'materialSearch', 'materialDateFrom', 'materialDateTo', 'materialDeptFilter'].forEach(id => { if (byId(id)) byId(id).value = '' }); window.loadMaterials(1); selectedMaterials = []; };

window.updateSelectedMaterials = function () {
    const l = document.querySelectorAll('.material-checkbox:checked').length;
    if (byId('bulkDeleteBtn')) byId('bulkDeleteBtn').disabled = l === 0;
};
window.toggleSelectAllMaterials = function () { document.querySelectorAll('.material-checkbox').forEach(cb => cb.checked = byId('selectAllMaterials')?.checked); window.updateSelectedMaterials(); };


// === Dashboard Overview & Charts ===
let myChartUserRole = null, myChartAuditActivity = null;

function renderDoughnutChart(canvasId, inst, labels, data, colors) {
    const ctx = byId(canvasId); if (!ctx) return inst;
    if (inst && typeof inst.destroy === 'function') { inst.destroy(); }
    if (typeof Chart === 'undefined') return null;

    return new Chart(ctx, {
        type: 'doughnut', data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#ffffff' }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom' } } }
    });
}

window.refreshDashboard = function () { window.loadUsersFromAPI(); window.loadDocuments(); window.loadMaterials(); window.loadAuditLogs(); };

window.updateDashboardOverview = function () {
    const uArr = Object.values(users);

    if (typeof Chart !== 'undefined') {
        myChartUserRole = renderDoughnutChart('userRoleChart', myChartUserRole, ['Admin', 'Employee', 'Student'], [
            uArr.filter(u => u.role === 'admin').length,
            uArr.filter(u => u.role === 'employee').length,
            uArr.filter(u => u.role === 'student').length
        ], ['#ef4444', '#3b82f6', '#10b981']);

        window.updateAuditActivityChart();
    }

    if (byId('dashboardTotalUsers')) byId('dashboardTotalUsers').textContent = uArr.length;
    if (byId('dashboardTotalDocs')) byId('dashboardTotalDocs').textContent = publicDocuments.length;
    if (byId('dashboardTotalMaterials')) byId('dashboardTotalMaterials').textContent = publicMaterials.length;
    if (byId('dashboardTotalLogs')) byId('dashboardTotalLogs').textContent = auditLogs.length;
};

window.updateAuditActivityChart = function () {
    const ctx = byId('auditActivityChart'); if (!ctx) return;
    const days = [], counts = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date(); d.setDate(d.getDate() - i);
        days.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        counts.push(auditLogs.filter(l => l.timestamp?.startsWith(d.toISOString().split('T')[0])).length);
    }

    if (myChartAuditActivity && typeof myChartAuditActivity.destroy === 'function') myChartAuditActivity.destroy();

    if (typeof Chart !== 'undefined') {
        myChartAuditActivity = new Chart(ctx, { type: 'line', data: { labels: days, datasets: [{ label: 'Activity', data: counts, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', borderWidth: 3, tension: 0.4, fill: true }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4] } }, x: { grid: { display: false } } } } });
    }
};

// === Settings Forms & Utilities ===
window.togglePasswordVisibility = function (id) {
    const input = byId(id); if (!input) return;
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    const icon = input.nextElementSibling?.querySelector('i');
    if (icon) icon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
};

function convertToCSV(data) {
    if (!data.length) return '';
    const headers = Object.keys(data[0]);
    return [headers.join(','), ...data.map(row => headers.map(h => `"${(row[h] || '').toString().replace(/"/g, '""')}"`).join(','))].join('\n');
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = filename;
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
}

window.exportDashboardReport = function () { downloadCSV(convertToCSV([{ generated: new Date().toISOString(), totalUsers: Object.keys(users).length, totalDocs: publicDocuments.length, totalMaterials: publicMaterials.length }]), 'report.csv'); };
window.exportUsers = function () { downloadCSV(convertToCSV(Object.values(users).map(u => ({ 'User ID': u.id, 'Name': `${u.firstName} ${u.lastName}`, 'Role': u.role, 'Email': u.email }))), 'users.csv'); };
window.exportDocuments = function () { downloadCSV(convertToCSV(publicDocuments.map(d => ({ 'Doc ID': d.id, 'Title': d.title, 'Type': d.doc_type, 'Status': d.status, 'Date': d.uploaded_at }))), 'documents.csv'); };
window.exportMaterials = function () { downloadCSV(convertToCSV(publicMaterials.map(m => ({ 'Mat ID': m.id, 'Title': m.title, 'Status': m.status, 'Date': m.uploaded_at }))), 'materials.csv'); };
window.exportAuditLog = function () { downloadCSV(convertToCSV(auditLogs), 'audit.csv'); };

window.goToCalendar = function () { window.location.href = BASE_URL + 'calendar'; };
window.logout = function () { window.addAuditLog('LOGOUT', 'Auth', 'Logged out'); window.location.href = BASE_URL + 'logout'; };
window.openSystemSettings = function () { safeShowModal('systemSettingsModal'); };

// User Form Listener
document.addEventListener('DOMContentLoaded', function () {
    const userForm = byId('userForm');
    if (userForm) {
        userForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (byId('userFormMessages')) byId('userFormMessages').innerHTML = '';

            const payload = {
                id: byId('userIdInput').value.trim(), first_name: byId('userFirstName').value.trim(), last_name: byId('userLastName').value.trim(),
                email: byId('userEmail').value.trim(), phone: normalizePhone(byId('userPhone').value.trim()), role: selectedUserRole
            };

            if (!payload.id || !payload.first_name || !payload.last_name || !payload.email || !payload.phone) return showFormAlert('userFormMessages', 'Fill all required fields.', 'danger');
            if (!isValidEmail(payload.email)) return showFormAlert('userFormMessages', 'Invalid email format.', 'danger');
            if (!isValidPHPhone(payload.phone)) return showFormAlert('userFormMessages', 'Invalid PH phone number.', 'danger');

            if (!editingUserId) {
                const pass = byId('userPassword')?.value, conf = byId('userConfirmPassword')?.value;
                if (pass && pass.length < 8) return showFormAlert('userFormMessages', 'Password min 8 chars.', 'danger');
                if (pass !== conf) return showFormAlert('userFormMessages', 'Passwords mismatch.', 'danger');
                if (pass) payload.default_password = pass;
            }

            if (selectedUserRole === 'admin') { payload.office = byId('adminOffice').value; payload.position = byId('adminPosition').value; }
            else if (selectedUserRole === 'employee') { payload.department = byId('employeeDepartment').value; payload.position = byId('employeePosition').value; }
            else if (selectedUserRole === 'student') { payload.department = byId('studentDepartment').value; payload.position = byId('studentPosition').value || 'Regular Student'; }

            try {
                const url = editingUserId ? `${USERS_API}?id=${encodeURIComponent(payload.id)}&role=${encodeURIComponent(editingUserOriginalRole)}` : USERS_API;
                const resp = await apiFetch(url, { method: editingUserId ? 'PUT' : 'POST', body: JSON.stringify(payload) });

                if (resp.success) {
                    showToast(editingUserId ? 'Updated successfully!' : 'Created successfully!', 'success');
                    window.addAuditLog(editingUserId ? 'USER_UPDATED' : 'USER_CREATED', 'User Management', `${payload.id}`);
                    await window.loadUsersFromAPI(); window.updateDashboardOverview();
                    safeHideModal('userModal');
                } else showFormAlert('userFormMessages', resp.message || 'Operation failed', 'danger');
            } catch (err) { showFormAlert('userFormMessages', 'Server error', 'danger'); }
        });
    }
});

// System Settings Form Handler
document.addEventListener('DOMContentLoaded', function () {
    const settingsForm = byId('generalSettingsForm');
    if (settingsForm) {
        settingsForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const data = {
                action: 'update_settings',
                academic_year: byId('sysAcadYear')?.value,
                force_2fa: byId('enable2FA')?.checked ? 1 : 0
            };
            const res = await apiFetch(ADMIN_DATA_API, { method: 'POST', body: JSON.stringify(data) });
            if (res.success) { showToast('Settings saved successfully', 'success'); safeHideModal('systemSettingsModal'); }
            else showToast(res.message || 'Failed to save settings', 'warning');
        });
    }
});

// === Core Initialization Trigger ===
document.addEventListener('DOMContentLoaded', async function () {
    if (!window.currentUser || window.currentUser.role !== 'admin') return window.location.href = BASE_URL + 'login';
    currentUser = window.currentUser;
    if (byId('adminUserName')) byId('adminUserName').textContent = `${currentUser.firstName} ${currentUser.lastName}`;

    const savedTab = localStorage.getItem('admin_currentTab');
    if (savedTab) {
        const targetBtn = document.querySelector(`[data-bs-target="${savedTab}"]`);
        if (targetBtn) targetBtn.click();
    }

    initUserFormDropdowns();

    await window.loadUsersFromAPI();
    await window.loadDocuments();
    await window.loadMaterials();
    await window.loadAuditLogs();

    window.updateDashboardOverview();

    document.body.style.opacity = '0';
    setTimeout(() => { document.body.style.transition = 'opacity 0.6s ease'; document.body.style.opacity = '1'; }, 100);
    document.querySelectorAll('[data-bs-toggle="pill"]').forEach(t => t.addEventListener('shown.bs.tab', e => localStorage.setItem('admin_currentTab', e.target.getAttribute('data-bs-target'))));
});