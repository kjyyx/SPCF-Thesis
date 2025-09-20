// Admin Dashboard JavaScript
// High-level: Handles admin functionality — user management, materials, audit logs.
// Notes for future developers:
// - Keep public function names referenced by HTML unchanged (e.g., editUser, deleteUser, etc.).
// - API endpoints are centralized via apiFetch(); update base paths here if folder structure changes.
// - Avoid storing secrets or sensitive data in client-side code. Passwords are never read back from the API.

// === Global Variables ===
let currentUser = null; // set by PHP on page
let auditLog = JSON.parse(localStorage.getItem('auditLog')) || []; // local-only audit log
let publicMaterials = JSON.parse(localStorage.getItem('publicMaterials')) || []; // replaced by API load
let editingUserId = null; // tracks current edit target
let selectedUserRole = null; // tracks role selection in modal
let selectedMaterials = []; // bulk selection for materials
let currentMaterialId = null; // detail modal focus
let editingUserOriginalRole = null; // used to prevent table hopping on edit

// User database - will be populated from backend API
let users = {};
const USERS_API = '../api/users.php';

// Toast notification helper
function showToast(message, type = 'info', title = null) {
    if (window.ToastManager) {
        window.ToastManager.show({
            type: type,
            title: title,
            message: message,
            duration: 4000
        });
    } else {
        // Fallback
        alert(message);
    }
}

// ==========================================================
// Initialization / Boot
// ==========================================================
// Removed legacy sample users seeding (users load from API)

// Initialize data
/**
 * Seed demo public materials in localStorage on first run.
 * Real data is fetched via API later; this keeps UI from looking empty.
 */
function initializeSampleData() {
    // Keep only Public Materials sample seeding (we'll replace with real API later)
    if (publicMaterials.length === 0) {
        const sampleMaterials = [
            {
                id: 'MAT001',
                fileName: 'engineering_symposium_poster.png',
                fileType: 'image/png',
                fileSize: '2.4 MB',
                submittedBy: 'STU001',
                submitterName: 'Juan Dela Cruz',
                department: 'College of Engineering',
                submissionDate: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000).toISOString(),
                status: 'approved',
                description: 'Poster for the upcoming Engineering Symposium event',
                approvedBy: 'EMP001',
                approvalDate: new Date(Date.now() - 1 * 24 * 60 * 60 * 1000).toISOString(),
                downloadCount: 15,
                lastDownloaded: new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString()
            },
            {
                id: 'MAT002',
                fileName: 'nursing_competition_flyer.jpeg',
                fileType: 'image/jpeg',
                fileSize: '1.8 MB',
                submittedBy: 'STU002',
                submitterName: 'Maria Santos',
                department: 'College of Nursing',
                submissionDate: new Date(Date.now() - 5 * 24 * 60 * 60 * 1000).toISOString(),
                status: 'pending',
                description: 'Promotional flyer for nursing skills competition',
                downloadCount: 0,
                lastDownloaded: null
            },
            {
                id: 'MAT003',
                fileName: 'business_plan_template.png',
                fileType: 'image/png',
                fileSize: '3.1 MB',
                submittedBy: 'STU003',
                submitterName: 'Carlos Rodriguez',
                department: 'College of Business',
                submissionDate: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString(),
                status: 'rejected',
                description: 'Template design for business plan presentations',
                rejectedBy: 'EMP001',
                rejectionDate: new Date(Date.now() - 6 * 24 * 60 * 60 * 1000).toISOString(),
                rejectionReason: 'Does not meet university branding guidelines',
                downloadCount: 3,
                lastDownloaded: new Date(Date.now() - 8 * 24 * 60 * 60 * 1000).toISOString()
            }
        ];

        publicMaterials = sampleMaterials;
        localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));
    }
}

// ==========================================================
// Audit Log Helpers (client-side only)
// ==========================================================
/**
 * Add an entry to the local audit log for user-initiated actions.
 * This is not a server-side audit trail — adjust once a backend exists.
 */
function addAuditLog(action, category, details, targetId = null, targetType = null, severity = 'INFO') {
    const entry = {
        id: 'AUDIT' + Date.now(),
        timestamp: new Date().toISOString(),
        userId: currentUser ? currentUser.id : 'SYSTEM',
        userName: currentUser ? `${currentUser.firstName} ${currentUser.lastName}` : 'System',
        action: action,
        category: category,
        details: details,
        targetId: targetId,
        targetType: targetType,
        ipAddress: '192.168.1.' + Math.floor(Math.random() * 255), // Simulated IP
        userAgent: navigator.userAgent,
        severity: severity
    };

    auditLog.unshift(entry); // Add to beginning of array

    // Keep only last 1000 entries to prevent excessive storage
    if (auditLog.length > 1000) {
        auditLog = auditLog.slice(0, 1000);
    }

    localStorage.setItem('auditLog', JSON.stringify(auditLog));
}

// ==========================================================
// Main Initialization
// ==========================================================
// Initialize the admin dashboard when the page loads
document.addEventListener('DOMContentLoaded', async function () {
    console.log('Admin Dashboard loaded');

    // Use the user data passed from PHP
    if (window.currentUser) {
        currentUser = window.currentUser;
        console.log('Admin user:', currentUser);

        // Check if user is actually an admin
        if (currentUser.role !== 'admin') {
            console.log('User is not an admin, redirecting...');
            window.location.href = 'event-calendar.php';
            return;
        }

        // Update UI
        const adminUserName = document.getElementById('adminUserName');
        if (adminUserName) {
            adminUserName.textContent = `${currentUser.firstName} ${currentUser.lastName}`;
        }

        // Initialize dashboard
        initializeSampleData();
        await loadUsersFromAPI();
        await loadMaterials();
        loadAuditLogs();
        updateDashboardStats();

    } else {
        console.log('No user data, redirecting to login...');
        window.location.href = 'user-login.php';
    }
});

// ==========================================================
// Navigation
// ==========================================================
// Navigation functions moved below (single source of truth)

// ==========================================================
// Users: State, UI, and Actions
// ==========================================================
/** Update header statistics based on current state. */
function updateUserStatistics() {
    const totalUsers = Object.keys(users).length;
    const pendingMaterials = publicMaterials.filter(m => m.status === 'pending').length;

    const totalUsersEl = document.getElementById('totalUsers');
    const totalMaterialsEl = document.getElementById('totalMaterials');
    const pendingApprovalsEl = document.getElementById('pendingApprovals');

    if (totalUsersEl) totalUsersEl.textContent = totalUsers;
    if (totalMaterialsEl) totalMaterialsEl.textContent = publicMaterials.length;
    if (pendingApprovalsEl) pendingApprovalsEl.textContent = pendingMaterials;
}

/** Render the users table based on current users map. */
function loadUsersTable() {
    const tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    tbody.innerHTML = '';

    Object.values(users).forEach(user => {
        const row = createUserRow(user);
        tbody.appendChild(row);
    });
}

/** Build a <tr> element for a given user. */
function createUserRow(user) {
    const row = document.createElement('tr');

    const roleClass = {
        'admin': 'bg-danger',
        'employee': 'bg-primary',
        'student': 'bg-success'
    };

    const departmentOrOffice = user.role === 'student' ? user.department : user.office;
    const contact = `${user.email}<br><small>${user.phone || '-'}</small>`;

    // Show edit/delete buttons for all roles now
    const actionButtons = `
        <div class="btn-group" role="group">
            <button class="btn btn-sm btn-outline-primary" onclick="editUser('${user.id}')" title="Edit User">
                <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser('${user.id}')" title="Delete User">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;

    row.innerHTML = `
        <td><strong>${user.id}</strong></td>
        <td>${user.firstName} ${user.lastName}</td>
        <td><span class="badge ${roleClass[user.role]}">${user.role.toUpperCase()}</span></td>
        <td>${departmentOrOffice || '-'}</td>
        <td>${contact}</td>
        <td>${actionButtons}</td>
    `;

    return row;
}

/** Open the modal to create a new user. */
function openAddUserModal() {
    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    resetUserForm();
    const modalLabel = document.getElementById('userModalLabel');
    if (modalLabel) modalLabel.textContent = 'Add New User';
    editingUserId = null;
    editingUserOriginalRole = null;
    // Enable role selection for create
    document.querySelectorAll('.role-btn').forEach(btn => btn.disabled = false);
    const idInput = document.getElementById('userIdInput');
    if (idInput) idInput.disabled = false;
    // Show password section for new users
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');
    if (passwordSection) passwordSection.style.display = 'block';
    if (addUserNotice) addUserNotice.style.display = 'block';
    if (editUserNotice) editUserNotice.style.display = 'none';
    modal.show();
}

/** Reset and sanitize the user form inputs. */
function resetUserForm() {
    const userForm = document.getElementById('userForm');
    if (userForm) userForm.reset();
    selectedUserRole = null;
    editingUserOriginalRole = null;

    // Reset password fields specifically (since form.reset() might not clear them properly)
    const userPassword = document.getElementById('userPassword');
    const userConfirmPassword = document.getElementById('userConfirmPassword');
    if (userPassword) userPassword.value = '';
    if (userConfirmPassword) userConfirmPassword.value = '';

    // Reset password visibility to hidden
    if (userPassword) userPassword.type = 'password';
    if (userConfirmPassword) userConfirmPassword.type = 'password';
    const passwordButtons = document.querySelectorAll('#userPassword ~ .btn, #userConfirmPassword ~ .btn');
    passwordButtons.forEach(btn => {
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'bi bi-eye';
    });

    // Reset role buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Hide role-specific fields and clear constraints
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });
    setRoleFieldConstraints(null);

    // Hide messages
    hideUserFormMessages();

    // Show password section by default
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');
    if (passwordSection) passwordSection.style.display = 'block';
    if (addUserNotice) addUserNotice.style.display = 'block';
    if (editUserNotice) editUserNotice.style.display = 'none';
}

/** Handle role button selection in the modal. */
function selectUserRole(role) {
    // Allow admin, employee and student roles
    if (role !== 'admin' && role !== 'employee' && role !== 'student') {
        showUserFormError('Invalid role selection. Please select Admin, Employee, or Student role.');
        return;
    }

    selectedUserRole = role;

    // Reset all buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Highlight selected role
    const selectedBtn = document.querySelector(`[data-role="${role}"]`);
    if (selectedBtn) {
        selectedBtn.classList.add('active');
    }

    // Show/hide role-specific fields and apply constraints
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });

    if (role === 'admin') {
        const adminFields = document.getElementById('adminFields');
        if (adminFields) adminFields.style.display = 'block';
    } else if (role === 'employee') {
        const employeeFields = document.getElementById('employeeFields');
        if (employeeFields) employeeFields.style.display = 'block';
    } else if (role === 'student') {
        const studentFields = document.getElementById('studentFields');
        if (studentFields) studentFields.style.display = 'block';
    }

    setRoleFieldConstraints(role);

    // Clear any previous error messages
    hideUserFormMessages();
}

/** Open the modal pre-filled for editing a user. */
function editUser(userId) {
    const user = users[userId];
    if (!user) return;

    editingUserId = userId;
    editingUserOriginalRole = user.role;

    // Populate form
    const userIdInput = document.getElementById('userIdInput');
    const userFirstName = document.getElementById('userFirstName');
    const userLastName = document.getElementById('userLastName');
    const userEmail = document.getElementById('userEmail');
    const userPhone = document.getElementById('userPhone');
    const userPassword = document.getElementById('userPassword');
    const userConfirmPassword = document.getElementById('userConfirmPassword');

    if (userIdInput) {
        userIdInput.value = user.id;
        userIdInput.disabled = true; // prevent PK change during edit
    }
    if (userFirstName) userFirstName.value = user.firstName;
    if (userLastName) userLastName.value = user.lastName;
    if (userEmail) userEmail.value = user.email;
    if (userPhone) userPhone.value = user.phone;

    // Clear password fields for security (don't show existing passwords)
    if (userPassword) userPassword.value = '';
    if (userConfirmPassword) userConfirmPassword.value = '';

    // Select role
    selectUserRole(user.role);
    // Disable changing role during edit to avoid moving across tables
    document.querySelectorAll('.role-btn').forEach(btn => btn.disabled = true);

    // Populate role-specific fields
    if (user.role === 'admin') {
        const adminOffice = document.getElementById('adminOffice');
        const adminPosition = document.getElementById('adminPosition');
        if (adminOffice) adminOffice.value = user.office || '';
        if (adminPosition) adminPosition.value = user.position || '';
    } else if (user.role === 'employee') {
        const employeeOffice = document.getElementById('employeeOffice');
        const employeePosition = document.getElementById('employeePosition');
        if (employeeOffice) employeeOffice.value = user.office || '';
        if (employeePosition) employeePosition.value = user.position || '';
    } else if (user.role === 'student') {
        const studentDepartment = document.getElementById('studentDepartment');
        const studentPosition = document.getElementById('studentPosition');
        if (studentDepartment) studentDepartment.value = user.department || '';
        if (studentPosition) studentPosition.value = user.position || '';
    }

    // Update modal
    const userModalLabel = document.getElementById('userModalLabel');
    const passwordSection = document.getElementById('passwordSection');
    const addUserNotice = document.getElementById('addUserNotice');
    const editUserNotice = document.getElementById('editUserNotice');

    if (userModalLabel) userModalLabel.textContent = 'Edit User';
    // Hide password section for editing (security)
    if (passwordSection) passwordSection.style.display = 'none';
    if (addUserNotice) addUserNotice.style.display = 'none';
    if (editUserNotice) editUserNotice.style.display = 'block';

    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
}

// Ensure hidden role fields are not marked required/disabled incorrectly
/** Apply input required/disabled flags depending on the selected role. */
function setRoleFieldConstraints(role) {
    const adminOffice = document.getElementById('adminOffice');
    const adminPosition = document.getElementById('adminPosition');
    const employeeOffice = document.getElementById('employeeOffice');
    const employeePosition = document.getElementById('employeePosition');
    const studentDepartment = document.getElementById('studentDepartment');
    const studentPosition = document.getElementById('studentPosition');

    // Reset all to not required and disabled when role is null
    [adminOffice, adminPosition, employeeOffice, employeePosition, studentDepartment, studentPosition]
        .forEach(el => { if (el) { el.required = false; el.disabled = true; } });

    if (role === 'admin') {
        if (adminOffice) { adminOffice.disabled = false; adminOffice.required = true; }
        if (adminPosition) { adminPosition.disabled = false; adminPosition.required = true; }
    } else if (role === 'employee') {
        if (employeeOffice) { employeeOffice.disabled = false; employeeOffice.required = true; }
        if (employeePosition) { employeePosition.disabled = false; employeePosition.required = true; }
    } else if (role === 'student') {
        if (studentDepartment) { studentDepartment.disabled = false; studentDepartment.required = true; }
        if (studentPosition) { studentPosition.disabled = false; /* optional */ }
    }
}

/** Ask for confirmation and stage user deletion. */
function deleteUser(userId) {
    const user = users[userId];
    if (!user) return;

    // Prevent deleting current admin (self-deletion protection)
    if (currentUser && currentUser.id === userId) {
        showToast('You cannot delete your own account while logged in.', 'warning');
        return;
    }

    // Show confirmation modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    window.userToDelete = userId; // Store for confirmation
    modal.show();
}

/** Perform user deletion via API after confirm modal. */
function confirmDeleteUser() {
    const userId = window.userToDelete;
    if (!userId || !users[userId]) return;
    const u = users[userId];
    // Call API to delete
    fetch(`${USERS_API}?id=${encodeURIComponent(userId)}&role=${encodeURIComponent(u.role)}`, {
        method: 'DELETE'
    }).then(r => r.json()).then(resp => {
        if (resp.success) {
            addAuditLog('USER_DELETED', 'User Management', `Deleted user account: ${u.firstName} ${u.lastName} (${u.id})`, u.id, 'User', 'WARNING');
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
            if (modal) modal.hide();
            loadUsersFromAPI().then(() => {
                updateUserStatistics();
                editingUserId = null;
                showToast('User deleted successfully.', 'success');
            });
        } else {
            showToast(resp.message || 'Delete failed', 'error');
        }
    }).catch(err => {
        console.error('Delete error', err);
        showToast('Server error while deleting user', 'error');
    });
}

// ----------------------------------------------------------
// Form messaging helpers
// ----------------------------------------------------------
function showUserFormError(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${message}</div>`;
}

// Validation helpers
/** Basic email validation. */
function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/** Remove common punctuation/spaces for phone input. */
function normalizePhone(input) {
    return input.replace(/[\s\-().]/g, '');
}

/** Accept PH mobile formats: 09XXXXXXXXX or +639XXXXXXXXX */
function isValidPHPhone(phone) {
    const p = normalizePhone(phone);
    // Accept Philippines mobile format: 09XXXXXXXXX or +639XXXXXXXXX
    return /^(09|\+639)\d{9}$/.test(p);
}

function showUserFormSuccess(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${message}</div>`;
}

function hideUserFormMessages() {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = '';
}

/** Filter users table by role (dropdown). */
function filterUsers() {
    const filter = document.getElementById('userRoleFilter').value;
    const tbody = document.getElementById('usersTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const roleCell = row.querySelector('td:nth-child(3)');
        if (filter === '' || roleCell.textContent.toLowerCase().includes(filter)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/** Client-side full table text search for users. */
function searchUsers() {
    const searchTerm = document.getElementById('userSearch').value.toLowerCase();
    const tbody = document.getElementById('usersTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/** Re-fetch users and clear filters. */
function refreshUserList() {
    loadUsersFromAPI().then(() => updateUserStatistics());
    document.getElementById('userRoleFilter').value = '';
    document.getElementById('userSearch').value = '';
}

/** Export users to CSV (client-side only). */
function exportUsers() {
    const userData = Object.values(users).map(user => ({
        'User ID': user.id,
        'First Name': user.firstName,
        'Last Name': user.lastName,
        'Role': user.role,
        'Department/Office': user.role === 'student' ? user.department : user.office,
        'Position': user.position,
        'Email': user.email,
        'Phone': user.phone
    }));

    const csv = convertToCSV(userData);
    downloadCSV(csv, 'university_users.csv');

    // Add audit log
    addAuditLog('USERS_EXPORTED', 'User Management', `Exported ${userData.length} users to CSV`, null, 'System', 'INFO');
}

// Handle user form submission
// ----------------------------------------------------------
// User form submit (create/update)
// ----------------------------------------------------------
document.getElementById('userForm').addEventListener('submit', function (e) {
    e.preventDefault();

    hideUserFormMessages();

    // Allow admin, employee and student roles
    if (!selectedUserRole || (selectedUserRole !== 'admin' && selectedUserRole !== 'employee' && selectedUserRole !== 'student')) {
        showUserFormError('Please select Admin, Employee, or Student role.');
        return;
    }

    const userId = document.getElementById('userIdInput').value.trim();
    const firstName = document.getElementById('userFirstName').value.trim();
    const lastName = document.getElementById('userLastName').value.trim();
    const email = document.getElementById('userEmail').value.trim();
    const phone = document.getElementById('userPhone').value.trim();
    const password = document.getElementById('userPassword').value;
    const confirmPassword = document.getElementById('userConfirmPassword').value;

    // Determine if this is an edit operation
    const isEdit = !!editingUserId;

    // Validation
    if (!userId || !firstName || !lastName || !email || !phone) {
        showUserFormError('Please fill in all required fields.');
        return;
    }

    // Email and phone validation
    if (!isValidEmail(email)) {
        showUserFormError('Please enter a valid email address (e.g., user@example.com).');
        return;
    }
    if (!isValidPHPhone(phone)) {
        showUserFormError('Please enter a valid Philippines mobile number (e.g., 09123456789 or +639123456789).');
        return;
    }

    // Password validation (only for new users)
    if (!isEdit) {
        if (password && password.length < 8) {
            showUserFormError('Password must be at least 8 characters long.');
            return;
        }
        if (password && confirmPassword && password !== confirmPassword) {
            showUserFormError('Passwords do not match.');
            return;
        }
        // If password is provided but confirmation is empty
        if (password && !confirmPassword) {
            showUserFormError('Please confirm the password.');
            return;
        }
        // If confirmation is provided but password is empty
        if (!password && confirmPassword) {
            showUserFormError('Please enter a password first.');
            return;
        }
    }

    // Role-specific validation
    let office, position, department, studentPosition;

    if (selectedUserRole === 'admin') {
        office = document.getElementById('adminOffice').value;
        position = document.getElementById('adminPosition').value;

        if (!office || !position) {
            showUserFormError('Please select both Office and Admin Position for administrators.');
            return;
        }
    } else if (selectedUserRole === 'employee') {
        office = document.getElementById('employeeOffice').value;
        position = document.getElementById('employeePosition').value;

        if (!office || !position) {
            showUserFormError('Please select both Office and Employee Position for employees.');
            return;
        }
    } else if (selectedUserRole === 'student') {
        department = document.getElementById('studentDepartment').value;
        studentPosition = document.getElementById('studentPosition').value;

        if (!department) {
            showUserFormError('Please select a Department/College for students.');
            return;
        }
    }

    // Prepare payload
    const payload = {
        id: userId,
        first_name: firstName,
        last_name: lastName,
        role: selectedUserRole,
        email: email,
        phone: normalizePhone(phone) // store normalized phone
    };

    // Add password if provided (only for new users)
    if (!isEdit && password) {
        payload.default_password = password;
    }

    if (selectedUserRole === 'student') {
        payload.department = department;
        payload.position = studentPosition || 'Regular Student';
    } else {
        payload.office = office;
        payload.position = position;
    }

    const roleForUpdate = isEdit ? editingUserOriginalRole : selectedUserRole;
    const request = isEdit
        ? apiFetch(`${USERS_API}?id=${encodeURIComponent(userId)}&role=${encodeURIComponent(roleForUpdate)}`, {
            method: 'PUT',
            body: JSON.stringify(payload)
        })
        : apiFetch(USERS_API, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

    request.then(async resp => {
        if (resp.success) {
            if (isEdit) {
                showUserFormSuccess('User updated successfully!');
                addAuditLog('USER_UPDATED', 'User Management', `Updated user: ${firstName} ${lastName} (${userId})`, userId, 'User', 'INFO');
            } else {
                const passwordMsg = password ? 'The provided password was set.' : 'Default password "ChangeMe123!" was set.';
                showUserFormSuccess(`User created successfully! ${passwordMsg} User must change password on first login.`);
                addAuditLog('USER_CREATED', 'User Management', `Created ${selectedUserRole}: ${firstName} ${lastName} (${userId})`, userId, 'User', 'INFO');
            }
            await loadUsersFromAPI();
            updateUserStatistics();
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('userModal'));
                if (modal) modal.hide();
            }, 1000);
        } else {
            showUserFormError(resp.message || 'Operation failed');
        }
    }).catch(err => handleApiError('User save error', err, showUserFormError));
});

// ==========================================================
// Materials: State, UI, and Actions
// ==========================================================
function loadMaterialsTable() {
    const tbody = document.getElementById('materialsTableBody');
    tbody.innerHTML = '';

    publicMaterials.forEach(material => {
        const row = createMaterialRow(material);
        tbody.appendChild(row);
    });

    // Update bulk actions
    updateSelectedMaterials();
}

/** Build a <tr> for a material row. */
function createMaterialRow(material) {
    const row = document.createElement('tr');

    const statusClass = {
        'pending': 'bg-warning text-dark',
        'approved': 'bg-success text-white',
        'rejected': 'bg-danger text-white'
    };

    const submissionDate = new Date(material.uploaded_at).toLocaleDateString();

    row.innerHTML = `
        <td>
            <input type="checkbox" class="material-checkbox" value="${material.id}" onchange="updateSelectedMaterials()">
        </td>
        <td><strong>${material.id}</strong></td>
        <td>${material.title}</td>
        <td>${material.student_id}</td>
        <td>${material.doc_type}</td>
        <td><span class="badge ${statusClass[material.status]}">${material.status.toUpperCase()}</span></td>
        <td>${submissionDate}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-outline-info" onclick="viewMaterialDetails('${material.id}')" title="View Details">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteMaterial('${material.id}')" title="Delete Material">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    `;

    return row;
}

/** Open the material detail modal for a given ID. */
function viewMaterialDetails(materialId) {
    const material = publicMaterials.find(m => m.id == materialId);
    if (!material) return;

    currentMaterialId = materialId;

    // Populate modal with material details
    document.getElementById('materialDetailId').textContent = material.id;
    document.getElementById('materialDetailFileName').textContent = material.title;
    document.getElementById('materialDetailStatus').innerHTML = `<span class="badge ${getStatusClass(material.status)}">${material.status.toUpperCase()}</span>`;
    document.getElementById('materialDetailSize').textContent = 'N/A'; // No size in documents table
    document.getElementById('materialDetailDownloads').textContent = 'N/A'; // No download count
    document.getElementById('materialDetailDescription').textContent = material.description || 'No description provided';

    // Hide approval/rejection info for now
    const approvalInfo = document.getElementById('materialApprovalInfo');
    const rejectionInfo = document.getElementById('materialRejectionInfo');
    approvalInfo.style.display = 'none';
    rejectionInfo.style.display = 'none';

    const modal = new bootstrap.Modal(document.getElementById('materialDetailModal'));
    modal.show();
}

function getStatusClass(status) {
    const statusClasses = {
        'pending': 'bg-warning text-dark',
        'approved': 'bg-success text-white',
        'rejected': 'bg-danger text-white'
    };
    return statusClasses[status] || 'bg-secondary text-white';
}

/** Ask confirmation and stage material deletion. */
function deleteMaterial(materialId) {
    currentMaterialId = materialId;
    const material = publicMaterials.find(m => m.id === materialId);
    if (!material) return;

    if (confirm(`Are you sure you want to delete "${material.fileName}"?\n\nThis action cannot be undone.`)) {
        deleteSingleMaterial();
    }
}

function deleteSingleMaterial() {
    if (!currentMaterialId) return;

    apiFetch(`${MATERIALS_API}?id=${encodeURIComponent(currentMaterialId)}`, {
        method: 'DELETE'
    }).then(resp => {
        if (resp.success) {
            addAuditLog('MATERIAL_DELETED', 'Public Materials', `Deleted material ID: ${currentMaterialId}`, currentMaterialId, 'Material', 'INFO');
            const modal = bootstrap.Modal.getInstance(document.getElementById('materialDetailModal'));
            if (modal) modal.hide();
            loadMaterials();
            updateUserStatistics();
            currentMaterialId = null;
        } else {
            showToast(resp.message || 'Delete failed', 'error');
        }
    }).catch(err => handleApiError('Delete error', err, (m) => showToast(m || 'Server error while deleting material', 'error')));
}

/** Maintain bulk selection state and toggle button states. */
function updateSelectedMaterials() {
    const checkboxes = document.querySelectorAll('.material-checkbox:checked');
    selectedMaterials = Array.from(checkboxes).map(cb => cb.value);

    // Update bulk delete button
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    bulkDeleteBtn.disabled = selectedMaterials.length === 0;
    bulkDeleteBtn.textContent = selectedMaterials.length > 0
        ? `Delete Selected (${selectedMaterials.length})`
        : 'Delete Selected';

    // Update select all checkbox
    const selectAllCheckbox = document.getElementById('selectAllMaterials');
    const allCheckboxes = document.querySelectorAll('.material-checkbox');
    selectAllCheckbox.checked = selectedMaterials.length === allCheckboxes.length && allCheckboxes.length > 0;
    selectAllCheckbox.indeterminate = selectedMaterials.length > 0 && selectedMaterials.length < allCheckboxes.length;
}

function toggleSelectAllMaterials() {
    const selectAllCheckbox = document.getElementById('selectAllMaterials');
    const checkboxes = document.querySelectorAll('.material-checkbox');

    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateSelectedMaterials();
}

function bulkDeleteMaterials() {
    if (selectedMaterials.length === 0) {
        showToast('Please select materials to delete.', 'warning');
        return;
    }

    if (confirm(`Are you sure you want to delete ${selectedMaterials.length} selected materials?\n\nThis action cannot be undone.`)) {
        confirmBulkDelete();
    }
}

function confirmBulkDelete() {
    if (selectedMaterials.length === 0) return;

    const deletedMaterials = [];
    selectedMaterials.forEach(materialId => {
        const material = publicMaterials.find(m => m.id === materialId);
        if (material) {
            deletedMaterials.push(material.fileName);
        }
    });

    // Remove selected materials
    publicMaterials = publicMaterials.filter(m => !selectedMaterials.includes(m.id));
    localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));

    // Add audit log
    addAuditLog('MATERIALS_BULK_DELETED', 'Public Materials', `Bulk deleted ${selectedMaterials.length} materials: ${deletedMaterials.join(', ')}`, null, 'PublicMaterial', 'INFO');

    // Refresh and clear selection
    loadMaterialsTable();
    updateUserStatistics();
    selectedMaterials = [];
    document.getElementById('selectAllMaterials').checked = false;

    showToast(`Successfully deleted ${deletedMaterials.length} materials.`, 'success');
}

/** Client-side filter materials by status. */
function filterMaterials() {
    const statusFilter = document.getElementById('materialStatusFilter').value;
    const tbody = document.getElementById('materialsTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const statusCell = row.querySelector('td:nth-child(6)');
        const statusMatch = statusFilter === '' || statusCell.textContent.toLowerCase().includes(statusFilter);

        if (statusMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/** Client-side search across materials table. */
function searchMaterials() {
    const searchTerm = document.getElementById('materialSearch').value.toLowerCase();
    const tbody = document.getElementById('materialsTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/** Reset filters and re-render materials. */
function refreshMaterialsList() {
    loadMaterialsTable();
    updateUserStatistics();
    document.getElementById('materialStatusFilter').value = '';
    document.getElementById('materialSearch').value = '';
    selectedMaterials = [];
}

/** Export materials to CSV. */
function exportMaterials() {
    const materialData = publicMaterials.map(material => ({
        'Material ID': material.id,
        'Title': material.title,
        'Student ID': material.student_id,
        'Type': material.doc_type,
        'Status': material.status,
        'Description': material.description || '',
        'Uploaded At': new Date(material.uploaded_at).toLocaleString()
    }));

    const csv = convertToCSV(materialData);
    downloadCSV(csv, 'public_materials.csv');

    // Add audit log
    addAuditLog('MATERIALS_EXPORTED', 'Public Materials', `Exported ${materialData.length} materials to CSV`, null, 'System', 'INFO');
}

// ==========================================================
// Audit Log: UI helpers
// ==========================================================
// Note: Audit details modal is triggered by the eye icon in the audit table
function loadAuditLogTable() {
    const tbody = document.getElementById('auditTableBody');
    tbody.innerHTML = '';

    // Show most recent entries first
    const sortedAuditLog = [...auditLog].sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));

    sortedAuditLog.forEach(entry => {
        const row = createAuditLogRow(entry);
        tbody.appendChild(row);
    });
}

/** Build a <tr> for an audit log entry. */
function createAuditLogRow(entry) {
    const row = document.createElement('tr');

    const severityClass = {
        'INFO': 'bg-info text-white',
        'WARNING': 'bg-warning text-dark',
        'ERROR': 'bg-danger text-white',
        'CRITICAL': 'bg-dark text-white'
    };

    const timestamp = new Date(entry.timestamp).toLocaleString();

    row.innerHTML = `
        <td>${timestamp}</td>
        <td>${entry.userName}</td>
        <td>${entry.action}</td>
        <td>${entry.category}</td>
        <td><span class="badge ${severityClass[entry.severity]}">${entry.severity}</span></td>
        <td>${entry.ipAddress}</td>
        <td>
            <button class="btn btn-sm btn-outline-info" onclick="viewAuditDetails('${entry.id}')" title="View Details">
                <i class="bi bi-eye"></i>
            </button>
        </td>
    `;

    return row;
}

/** Open details modal for an audit log entry. */
function viewAuditDetails(auditId) {
    const entry = auditLog.find(e => e.id === auditId);
    if (!entry) return;

    // Populate modal with audit details
    document.getElementById('auditDetailTimestamp').textContent = new Date(entry.timestamp).toLocaleString();
    document.getElementById('auditDetailUser').textContent = `${entry.userName} (${entry.userId})`;
    document.getElementById('auditDetailAction').textContent = `${entry.action} - ${entry.category}`;
    document.getElementById('auditDetailSystem').textContent = `IP: ${entry.ipAddress} | Severity: ${entry.severity}`;

    const modal = new bootstrap.Modal(document.getElementById('auditDetailModal'));
    modal.show();
}

/** Client-side filter audit table by category and severity. */
function filterAuditLog() {
    const categoryFilter = document.getElementById('auditCategoryFilter').value;
    const severityFilter = document.getElementById('auditSeverityFilter').value;
    const tbody = document.getElementById('auditTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const categoryCell = row.querySelector('td:nth-child(4)');
        const severityCell = row.querySelector('td:nth-child(5)');

        const categoryMatch = categoryFilter === '' || categoryCell.textContent.includes(categoryFilter);
        const severityMatch = severityFilter === '' || severityCell.textContent.includes(severityFilter);

        if (categoryMatch && severityMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/** Client-side search across audit table. */
function searchAuditLog() {
    const searchTerm = document.getElementById('auditSearch').value.toLowerCase();
    const tbody = document.getElementById('auditTableBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function refreshAuditLog() {
    loadAuditLogTable();
    document.getElementById('auditCategoryFilter').value = '';
    document.getElementById('auditSeverityFilter').value = '';
    document.getElementById('auditSearch').value = '';
}

/** Export audit log to CSV (local only). */
function exportAuditLog() {
    const auditData = auditLog.map(entry => ({
        'Audit ID': entry.id,
        'Timestamp': new Date(entry.timestamp).toLocaleString(),
        'User ID': entry.userId,
        'User Name': entry.userName,
        'Action': entry.action,
        'Category': entry.category,
        'Details': entry.details,
        'Target ID': entry.targetId || '',
        'Target Type': entry.targetType || '',
        'IP Address': entry.ipAddress,
        'Severity': entry.severity
    }));

    const csv = convertToCSV(auditData);
    downloadCSV(csv, 'audit_log.csv');

    // Add audit log
    addAuditLog('AUDIT_EXPORTED', 'System', `Exported ${auditData.length} audit entries to CSV`, null, 'System', 'INFO');
}

function clearAuditLog() {
    if (confirm('Are you sure you want to clear all audit log entries?\n\nThis action cannot be undone and will remove all audit history.')) {
        const entryCount = auditLog.length;
        auditLog = [];
        localStorage.setItem('auditLog', JSON.stringify(auditLog));

        // Add a new audit entry for the clear action (this will be the only entry)
        addAuditLog('AUDIT_LOG_CLEARED', 'System', `Cleared audit log containing ${entryCount} entries`, null, 'System', 'WARNING');

        loadAuditLogTable();
    }
}

// ==========================================================
// Settings and Profile (stubs)
// ==========================================================
function openProfileSettings() {
    alert('Profile Settings\n\nThis would open a modal to edit administrator profile information, including name, email, and contact details.');
}

function openNotificationSettings() {
    alert('Notification Settings\n\nThis would open settings to configure email notifications, system alerts, and audit log notifications.');
}

function openSystemSettings() {
    alert('System Settings\n\nThis would open advanced system configuration options including backup settings, user policies, and security configurations.');
}

function openChangePassword() {
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}

// Handle change password form
document.getElementById('changePasswordForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const currentPassword = document.getElementById('currentPassword')?.value || '';
    const newPassword = document.getElementById('newPassword')?.value || '';
    const confirmPassword = document.getElementById('confirmPassword')?.value || '';
    const messagesDiv = document.getElementById('changePasswordMessages');

    const show = (html) => { if (messagesDiv) messagesDiv.innerHTML = html; };
    const ok = (msg) => `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${msg}</div>`;
    const err = (msg) => `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${msg}</div>`;

    if (!currentPassword || !newPassword || !confirmPassword) { show(err('All fields are required.')); return; }
    if (newPassword !== confirmPassword) { show(err('New passwords do not match.')); return; }
    const policy = /^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/;
    if (!policy.test(newPassword)) { show(err('Password must be 8+ chars with upper, lower, number, special.')); return; }

    try {
        const resp = await fetch('../api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_password', current_password: currentPassword, new_password: newPassword })
        }).then(r => r.json());

        if (resp.success) {
            addAuditLog('PASSWORD_CHANGED', 'Security', 'Password changed', currentUser?.id || null, 'User', 'INFO');
            show(ok('Password changed successfully!'));
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
                if (modal) modal.hide();
                if (messagesDiv) messagesDiv.innerHTML = '';
                document.getElementById('changePasswordForm')?.reset();
            }, 1500);
        } else {
            show(err(resp.message || 'Failed to change password.'));
        }
    } catch (e) {
        show(err('Server error changing password.'));
    }
});

// Notification function
function showNotifications() {
    const pendingMaterials = publicMaterials.filter(m => m.status === 'pending').length;
    const recentAudits = auditLog.filter(a => new Date(a.timestamp) > new Date(Date.now() - 24 * 60 * 60 * 1000)).length;

    alert(`📢 Admin Notifications\n\n` +
        `🔔 Recent Activity:\n` +
        `• ${pendingMaterials} pending material approvals\n` +
        `• ${recentAudits} audit entries in last 24h\n` +
        `• ${Object.keys(users).length} total system users\n` +
        `• System running normally\n\n` +
        `This would show a detailed notification panel with real-time alerts.`);
}

// ==========================================================
// Utility helpers (DOM, CSV, Fetch)
// ==========================================================
/** Shortcut for querySelector. */
const qs = (sel, root = document) => root.querySelector(sel);
/** Shortcut for querySelectorAll. */
const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function convertToCSV(data) {
    if (data.length === 0) return '';

    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(header => `"${(row[header] || '').toString().replace(/"/g, '""')}"`).join(','))
    ].join('\n');

    return csvContent;
}

function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');

    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}
/**
 * API fetch wrapper to unify headers, error handling, and JSON parsing.
 * Returns parsed JSON or throws on network/parse errors.
 */
async function apiFetch(url, options = {}) {
    const defaultHeaders = { 'Content-Type': 'application/json' };
    const merged = { headers: defaultHeaders, ...options };
    try {
        const resp = await fetch(url, merged);
        const data = await resp.json().catch(() => ({}));
        return data;
    } catch (e) {
        throw e;
    }
}

/** Generic API error handler to reduce console + alert boilerplate. */
function handleApiError(prefix, err, notifyFn = (msg) => showToast(msg, 'error')) {
    console.error(prefix, err);
    notifyFn('Server error, please try again');
}

// ==========================================================
// API and Loading Functions
// ==========================================================
// Replace older duplicated init/load functions with API-backed ones
const MATERIALS_API = '../api/materials.php';

async function loadUsersFromAPI(role = null) {
    const url = role ? `${USERS_API}?role=${encodeURIComponent(role)}` : USERS_API;
    try {
        const data = await apiFetch(url);
        if (data.success) {
            users = data.users || {};
            loadUsersTable();
            return true;
        } else {
            console.error('Failed to load users', data.message);
            showToast('Failed to load users: ' + (data.message || 'Unknown error'), 'error');
            return false;
        }
    } catch (e) {
        handleApiError('Error loading users', e, (m) => showToast(m || 'Server error while loading users', 'error'));
        return false;
    }
}

// Load materials into the table
async function loadMaterials() {
    try {
        const data = await apiFetch(MATERIALS_API);
        if (data.success) {
            publicMaterials = data.materials || [];
            loadMaterialsTable();
        } else {
            console.error('Failed to load materials', data.message);
            showToast('Failed to load materials: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (e) {
        handleApiError('Error loading materials', e, (m) => showToast(m || 'Server error while loading materials', 'error'));
    }
}

// Load audit logs into the table
function loadAuditLogs() {
    const auditTableBody = document.getElementById('auditTableBody');
    if (!auditTableBody) return;

    auditTableBody.innerHTML = '';

    auditLog.slice(0, 50).forEach(entry => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${new Date(entry.timestamp).toLocaleString()}</td>
            <td>${entry.userName}</td>
            <td>${entry.action}</td>
            <td>${entry.category}</td>
            <td><span class="badge bg-${getSeverityColor(entry.severity)}">${entry.severity}</span></td>
            <td>${entry.details}</td>
        `;
        auditTableBody.appendChild(row);
    });
}

// Update dashboard statistics
function updateDashboardStats() {
    const totalUsers = Object.keys(users).length;
    const totalMaterials = publicMaterials.length;
    const pendingApprovals = publicMaterials.filter(m => m.status === 'pending').length;

    document.getElementById('totalUsers').textContent = totalUsers;
    document.getElementById('totalMaterials').textContent = totalMaterials;
    document.getElementById('pendingApprovals').textContent = pendingApprovals;
}

// ==========================================================
// Helper Functions (colors, navigation)
// ==========================================================
// Helper functions for colors
function getRoleColor(role) {
    const colors = { admin: 'danger', employee: 'primary', student: 'success' };
    return colors[role] || 'secondary';
}

function getStatusColor(status) {
    const colors = { approved: 'success', pending: 'warning', rejected: 'danger' };
    return colors[status] || 'secondary';
}

function getSeverityColor(severity) {
    const colors = { INFO: 'info', WARNING: 'warning', ERROR: 'danger' };
    return colors[severity] || 'secondary';
}

// === Basic Utility Functions ===
// Basic utility functions (defined earlier in the file where applicable)

// Remove duplicate stubbed user functions (now handled above)

// Navigation functions (required by UI)
function goToCalendar() {
    window.location.href = 'event-calendar.php';
}

function logout() {
    try {
        if (currentUser) {
            addAuditLog('LOGOUT', 'Authentication', `Admin ${currentUser.firstName} ${currentUser.lastName} logged out`);
        }
    } catch (e) {
        // ignore audit errors
    }
    window.location.href = 'user-logout.php';
}

// End of file
