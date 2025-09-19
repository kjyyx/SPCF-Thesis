// Admin Dashboard JavaScript - Event Management System

// Global variables
let currentUser = null;
let auditLog = JSON.parse(localStorage.getItem('auditLog')) || [];
let publicMaterials = JSON.parse(localStorage.getItem('publicMaterials')) || [];
let editingUserId = null;
let selectedUserRole = null;
let selectedMaterials = [];
let currentMaterialId = null;

// User database - will be populated from PHP/database
let users = {};

// Initialize with sample users for demo purposes
function initializeSampleUsers() {
    users = {
        'ADM001': {
            id: 'ADM001',
            firstName: 'System',
            lastName: 'Administrator',
            role: 'admin',
            office: 'IT Department',
            position: 'System Administrator',
            email: 'admin@university.edu',
            phone: '+63 917 000 0000'
        },
        'EMP001': {
            id: 'EMP001',
            firstName: 'Maria',
            lastName: 'Santos',
            role: 'employee',
            office: 'Administration Office',
            position: 'Dean',
            email: 'maria.santos@university.edu',
            phone: '+63 917 123 4567'
        },
        'STU001': {
            id: 'STU001',
            firstName: 'Juan',
            lastName: 'Dela Cruz',
            role: 'student',
            department: 'College of Engineering',
            position: 'Student',
            email: 'juan.delacruz@student.university.edu',
            phone: '+63 918 765 4321'
        }
    };
}

// Initialize data
function initializeSampleData() {
    // Initialize users
    initializeSampleUsers();
    
    // Initialize public materials with sample data if empty
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

    // Initialize audit log with sample data if empty
    if (auditLog.length === 0) {
        const sampleAuditEntries = [
            {
                id: 'AUDIT001',
                timestamp: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
                userId: 'ADM001',
                userName: 'System Administrator',
                action: 'LOGIN',
                category: 'Authentication',
                details: 'Administrator logged into the system',
                ipAddress: '192.168.1.100',
                userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                severity: 'INFO'
            },
            {
                id: 'AUDIT002',
                timestamp: new Date(Date.now() - 45 * 60 * 1000).toISOString(),
                userId: 'EMP001',
                userName: 'Maria Santos',
                action: 'EVENT_CREATED',
                category: 'Event Management',
                details: 'Created new event: Engineering Symposium',
                targetId: '1',
                targetType: 'Event',
                ipAddress: '192.168.1.101',
                userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                severity: 'INFO'
            },
            {
                id: 'AUDIT003',
                timestamp: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
                userId: 'STU001',
                userName: 'Juan Dela Cruz',
                action: 'MATERIAL_SUBMITTED',
                category: 'Public Materials',
                details: 'Submitted public material: engineering_symposium_poster.png',
                targetId: 'MAT001',
                targetType: 'PublicMaterial',
                ipAddress: '192.168.1.102',
                userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_7_1 like Mac OS X)',
                severity: 'INFO'
            },
            {
                id: 'AUDIT004',
                timestamp: new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString(),
                userId: 'ADM001',
                userName: 'System Administrator',
                action: 'USER_CREATED',
                category: 'User Management',
                details: 'Created new user account: STU004',
                targetId: 'STU004',
                targetType: 'User',
                ipAddress: '192.168.1.100',
                userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                severity: 'INFO'
            },
            {
                id: 'AUDIT005',
                timestamp: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
                userId: 'SYSTEM',
                userName: 'System',
                action: 'LOGIN_FAILED',
                category: 'Security',
                details: 'Failed login attempt for user: STU999',
                targetId: 'STU999',
                targetType: 'User',
                ipAddress: '203.124.45.67',
                userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
                severity: 'WARNING'
            }
        ];

        auditLog = sampleAuditEntries;
        localStorage.setItem('auditLog', JSON.stringify(auditLog));
    }
}

// Function to add audit log entry
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

// Initialize the admin dashboard when the page loads
document.addEventListener('DOMContentLoaded', function () {
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
        loadUsers();
        loadMaterials();
        loadAuditLogs();
        updateDashboardStats();
        
    } else {
        console.log('No user data, redirecting to login...');
        window.location.href = 'user-login.php';
    }
});

// Navigation functions
function goToCalendar() {
    window.location.href = 'event-calendar.php';
}

function logout() {
    window.location.href = 'user-logout.php';
}

// User Management Functions
function updateUserStatistics() {
    const totalUsers = Object.keys(users).length;
    const pendingMaterials = publicMaterials.filter(m => m.status === 'pending').length;

    document.getElementById('totalUsers').textContent = totalUsers;
    document.getElementById('totalMaterials').textContent = publicMaterials.length;
    document.getElementById('pendingApprovals').textContent = pendingMaterials;
}

function loadUsersTable() {
    const tbody = document.getElementById('usersTableBody');
    tbody.innerHTML = '';

    Object.values(users).forEach(user => {
        const row = createUserRow(user);
        tbody.appendChild(row);
    });
}

function createUserRow(user) {
    const row = document.createElement('tr');

    const roleClass = {
        'admin': 'bg-danger',
        'employee': 'bg-primary',
        'student': 'bg-success'
    };

    const departmentOrOffice = user.role === 'student' ? user.department : user.office;
    const contact = `${user.email}<br><small>${user.phone}</small>`;

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

function openAddUserModal() {
    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    resetUserForm();
    document.getElementById('userModalLabel').textContent = 'Add New User';
    editingUserId = null;
    modal.show();
}

function resetUserForm() {
    document.getElementById('userForm').reset();
    selectedUserRole = null;

    // Reset role buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    // Hide role-specific fields
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });

    // Hide messages
    hideUserFormMessages();
}

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

    // Show/hide role-specific fields
    document.querySelectorAll('.role-fields').forEach(field => {
        field.style.display = 'none';
    });

    if (role === 'admin') {
        document.getElementById('adminFields').style.display = 'block';
    } else if (role === 'employee') {
        document.getElementById('employeeFields').style.display = 'block';
    } else if (role === 'student') {
        document.getElementById('studentFields').style.display = 'block';
    }

    // Clear any previous error messages
    hideUserFormMessages();
}

function editUser(userId) {
    const user = users[userId];
    if (!user) return;

    editingUserId = userId;

    // Populate form
    document.getElementById('userIdInput').value = user.id;
    document.getElementById('userFirstName').value = user.firstName;
    document.getElementById('userLastName').value = user.lastName;
    document.getElementById('userEmail').value = user.email;
    document.getElementById('userPhone').value = user.phone;

    // Select role
    selectUserRole(user.role);

    // Populate role-specific fields
    if (user.role === 'admin') {
        document.getElementById('adminOffice').value = user.office || '';
        document.getElementById('adminPosition').value = user.employeePosition || '';
    } else if (user.role === 'employee') {
        document.getElementById('employeeOffice').value = user.office || '';
        document.getElementById('employeePosition').value = user.employeePosition || '';
    } else if (user.role === 'student') {
        document.getElementById('studentDepartment').value = user.department || '';
        document.getElementById('studentPosition').value = user.studentPosition || '';
    }

    // Update modal
    document.getElementById('userModalLabel').textContent = 'Edit User';

    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
}

function deleteUser(userId) {
    const user = users[userId];
    if (!user) return;

    // Prevent deleting current admin (self-deletion protection)
    if (currentUser && currentUser.id === userId) {
        alert('You cannot delete your own account while logged in.');
        return;
    }

    // Show confirmation modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    window.userToDelete = userId; // Store for confirmation
    modal.show();
}

function confirmDeleteUser() {
    const userId = window.userToDelete;
    if (!userId || !users[userId]) return;

    const deletedUser = users[userId];
    delete users[userId];

    // Add audit log
    addAuditLog('USER_DELETED', 'User Management', `Deleted user account: ${deletedUser.firstName} ${deletedUser.lastName} (${deletedUser.id})`, deletedUser.id, 'User', 'WARNING');

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
    if (modal) modal.hide();

    // Refresh table
    loadUsersTable();
    updateUserStatistics();

    editingUserId = null;
    alert('User deleted successfully.');
}

function showUserFormError(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${message}</div>`;
}

function showUserFormSuccess(message) {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>${message}</div>`;
}

function hideUserFormMessages() {
    const messagesDiv = document.getElementById('userFormMessages');
    messagesDiv.innerHTML = '';
}

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

function refreshUserList() {
    loadUsersTable();
    updateUserStatistics();
    document.getElementById('userRoleFilter').value = '';
    document.getElementById('userSearch').value = '';
}

function exportUsers() {
    const userData = Object.values(users).map(user => ({
        'User ID': user.id,
        'First Name': user.firstName,
        'Last Name': user.lastName,
        'Role': user.role,
        'Department/Office': user.role === 'student' ? user.department : user.office,
        'Position': user.role === 'student' ? user.studentPosition : user.employeePosition,
        'Email': user.email,
        'Phone': user.phone
    }));

    const csv = convertToCSV(userData);
    downloadCSV(csv, 'university_users.csv');

    // Add audit log
    addAuditLog('USERS_EXPORTED', 'User Management', `Exported ${userData.length} users to CSV`, null, 'System', 'INFO');
}

// Handle user form submission
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

    // Validation
    if (!userId || !firstName || !lastName || !email || !phone) {
        showUserFormError('Please fill in all required fields.');
        return;
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

    // Check if user ID already exists (for new users)
    if (!editingUserId && users[userId]) {
        showUserFormError('User ID already exists. Please choose a different ID.');
        return;
    }

    // Create user object
    const userData = {
        id: userId,
        firstName: firstName,
        lastName: lastName,
        role: selectedUserRole,
        email: email,
        phone: phone
    };

    // Assign default password based on role (secure default passwords)
    if (!editingUserId) {
        // New user - assign default password based on role
        if (selectedUserRole === 'admin') {
            userData.password = 'admin123'; // Default admin password
        } else if (selectedUserRole === 'employee') {
            userData.password = 'employee123'; // Default employee password
        } else if (selectedUserRole === 'student') {
            userData.password = 'student123'; // Default student password
        }
    } else {
        // For existing users, preserve their current password
        userData.password = users[editingUserId].password;
    }

    // Add role-specific fields
    if (selectedUserRole === 'admin') {
        userData.office = office;
        userData.employeePosition = position;
    } else if (selectedUserRole === 'employee') {
        userData.office = office;
        userData.employeePosition = position;
    } else if (selectedUserRole === 'student') {
        userData.department = department;
        userData.studentPosition = studentPosition || 'Regular Student';
    }

    // Save user
    if (editingUserId) {
        // Update existing user
        users[editingUserId] = userData;
        showUserFormSuccess('User updated successfully!');

        // Add audit log
        addAuditLog('USER_UPDATED', 'User Management', `Updated user account: ${userData.firstName} ${userData.lastName} (${userData.id})`, userData.id, 'User', 'INFO');
    } else {
        // Add new user
        users[userId] = userData;
        showUserFormSuccess(`User created successfully! Default password: ${userData.password} (User must change on first login)`);

        // Add audit log
        addAuditLog('USER_CREATED', 'User Management', `Created new ${userData.role} account: ${userData.firstName} ${userData.lastName} (${userData.id})`, userData.id, 'User', 'INFO');
    }

    // Refresh table
    loadUsersTable();
    updateUserStatistics();

    // Close modal after 2 seconds
    setTimeout(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('userModal'));
        modal.hide();
    }, 2000);
});

// Materials Management Functions
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

function createMaterialRow(material) {
    const row = document.createElement('tr');

    const statusClass = {
        'pending': 'bg-warning text-dark',
        'approved': 'bg-success text-white',
        'rejected': 'bg-danger text-white'
    };

    const submissionDate = new Date(material.submissionDate).toLocaleDateString();

    row.innerHTML = `
        <td>
            <input type="checkbox" class="material-checkbox" value="${material.id}" onchange="updateSelectedMaterials()">
        </td>
        <td><strong>${material.id}</strong></td>
        <td>${material.fileName}</td>
        <td>${material.submitterName}</td>
        <td>${material.department}</td>
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

function viewMaterialDetails(materialId) {
    const material = publicMaterials.find(m => m.id === materialId);
    if (!material) return;

    currentMaterialId = materialId;

    // Populate modal with material details
    document.getElementById('materialDetailId').textContent = material.id;
    document.getElementById('materialDetailFileName').textContent = material.fileName;
    document.getElementById('materialDetailStatus').innerHTML = `<span class="badge ${getStatusClass(material.status)}">${material.status.toUpperCase()}</span>`;
    document.getElementById('materialDetailSize').textContent = material.fileSize;
    document.getElementById('materialDetailDownloads').textContent = material.downloadCount;
    document.getElementById('materialDetailDescription').textContent = material.description || 'No description provided';

    // Show/hide approval/rejection info
    const approvalInfo = document.getElementById('materialApprovalInfo');
    const rejectionInfo = document.getElementById('materialRejectionInfo');

    if (material.status === 'approved' && material.approvedBy) {
        document.getElementById('materialApprovedBy').textContent = material.approvedBy;
        document.getElementById('materialApprovalDate').textContent = new Date(material.approvalDate).toLocaleString();
        approvalInfo.style.display = 'block';
        rejectionInfo.style.display = 'none';
    } else if (material.status === 'rejected' && material.rejectedBy) {
        document.getElementById('materialRejectedBy').textContent = material.rejectedBy;
        document.getElementById('materialRejectionDate').textContent = new Date(material.rejectionDate).toLocaleString();
        document.getElementById('materialRejectionReason').textContent = material.rejectionReason;
        rejectionInfo.style.display = 'block';
        approvalInfo.style.display = 'none';
    } else {
        approvalInfo.style.display = 'none';
        rejectionInfo.style.display = 'none';
    }

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

    const material = publicMaterials.find(m => m.id === currentMaterialId);
    if (!material) return;

    // Remove from array
    publicMaterials = publicMaterials.filter(m => m.id !== currentMaterialId);
    localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));

    // Add audit log
    addAuditLog('MATERIAL_DELETED', 'Public Materials', `Deleted public material: ${material.fileName}`, currentMaterialId, 'PublicMaterial', 'INFO');

    // Close modal and refresh table
    const modal = bootstrap.Modal.getInstance(document.getElementById('materialDetailModal'));
    if (modal) modal.hide();

    loadMaterialsTable();
    updateUserStatistics();
    currentMaterialId = null;
}

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
        alert('Please select materials to delete.');
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

    alert(`Successfully deleted ${deletedMaterials.length} materials.`);
}

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

function refreshMaterialsList() {
    loadMaterialsTable();
    updateUserStatistics();
    document.getElementById('materialStatusFilter').value = '';
    document.getElementById('materialSearch').value = '';
    selectedMaterials = [];
}

function exportMaterials() {
    const materialData = publicMaterials.map(material => ({
        'Material ID': material.id,
        'File Name': material.fileName,
        'File Type': material.fileType,
        'File Size': material.fileSize,
        'Submitted By': material.submitterName,
        'User ID': material.submittedBy,
        'Department': material.department,
        'Status': material.status,
        'Submission Date': new Date(material.submissionDate).toLocaleString(),
        'Description': material.description || '',
        'Download Count': material.downloadCount,
        'Last Downloaded': material.lastDownloaded ? new Date(material.lastDownloaded).toLocaleString() : 'Never'
    }));

    const csv = convertToCSV(materialData);
    downloadCSV(csv, 'public_materials.csv');

    // Add audit log
    addAuditLog('MATERIALS_EXPORTED', 'Public Materials', `Exported ${materialData.length} materials to CSV`, null, 'System', 'INFO');
}

// Audit Log Management Functions
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

// Settings and Profile Functions
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
document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const currentPassword = document.getElementById('currentPassword').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    
    const messagesDiv = document.getElementById('changePasswordMessages');
    
    // Validation
    if (currentPassword !== currentUser.password) {
        messagesDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Current password is incorrect.</div>';
        return;
    }
    
    if (newPassword !== confirmPassword) {
        messagesDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>New passwords do not match.</div>';
        return;
    }
    
    if (newPassword.length < 6) {
        messagesDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Password must be at least 6 characters long.</div>';
        return;
    }
    
    // Update password
    users[currentUser.id].password = newPassword;
    currentUser.password = newPassword;
    localStorage.setItem('currentUser', JSON.stringify(currentUser));
    
    // Add audit log
    addAuditLog('PASSWORD_CHANGED', 'Security', 'Administrator changed password', currentUser.id, 'User', 'INFO');
    
    // Show success message
    messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Password changed successfully!</div>';
    
    // Close modal after delay
    setTimeout(() => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'));
        modal.hide();
        messagesDiv.innerHTML = '';
        document.getElementById('changePasswordForm').reset();
    }, 2000);
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

// Password visibility toggle function
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.parentNode.querySelector('.password-toggle i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        button.className = 'bi bi-eye';
    }
}

// Utility Functions
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

// Initialize the admin dashboard when the page loads
document.addEventListener('DOMContentLoaded', function () {
    console.log('Admin Dashboard DOM loaded');
    console.log('window.currentUser:', window.currentUser);
    
    // Use the user data passed from PHP
    if (window.currentUser) {
        currentUser = window.currentUser;
        console.log('Admin user data found:', currentUser);
        
        // Update user display name
        const adminUserName = document.getElementById('adminUserName');
        if (adminUserName) {
            adminUserName.textContent = `${currentUser.firstName} ${currentUser.lastName}`;
        }
        
        // Initialize dashboard
        initializeDashboard();
        
    } else {
        console.log('No admin user data found, redirecting to login...');
        // If no user data, redirect to login
        window.location.href = 'user-login.php';
    }
});

function initializeDashboard() {
    console.log('Initializing admin dashboard...');
    
    // Initialize sample data
    initializeSampleData();
    
    // Load initial data
    loadUsers();
    loadMaterials();
    loadAuditLogs();
    updateDashboardStats();
    
    console.log('Admin dashboard initialized successfully');
}

// Load users into the table
function loadUsers() {
    const userTableBody = document.getElementById('usersTableBody');
    if (!userTableBody) return;
    
    userTableBody.innerHTML = '';
    
    Object.values(users).forEach(user => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${user.id}</td>
            <td>${user.firstName} ${user.lastName}</td>
            <td><span class="badge bg-${getRoleColor(user.role)}">${user.role}</span></td>
            <td>${user.role === 'student' ? (user.department || 'N/A') : (user.office || 'N/A')}</td>
            <td>${user.email}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="viewUser('${user.id}')">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-warning me-1" onclick="editUser('${user.id}')">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteUser('${user.id}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        userTableBody.appendChild(row);
    });
}

// Load materials into the table
function loadMaterials() {
    const materialsTableBody = document.getElementById('materialsTableBody');
    if (!materialsTableBody) return;
    
    materialsTableBody.innerHTML = '';
    
    publicMaterials.forEach(material => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${material.id}</td>
            <td>${material.fileName}</td>
            <td>${material.submitterName}</td>
            <td>${material.department}</td>
            <td><span class="badge bg-${getStatusColor(material.status)}">${material.status}</span></td>
            <td>${new Date(material.submissionDate).toLocaleDateString()}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="viewMaterial('${material.id}')">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn btn-sm btn-outline-success me-1" onclick="approveMaterial('${material.id}')">
                    <i class="bi bi-check-circle"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="rejectMaterial('${material.id}')">
                    <i class="bi bi-x-circle"></i>
                </button>
            </td>
        `;
        materialsTableBody.appendChild(row);
    });
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

// Basic utility functions
function logout() {
    if (currentUser) {
        addAuditLog('LOGOUT', 'Authentication', `Admin ${currentUser.firstName} ${currentUser.lastName} logged out`);
    }
    window.location.href = 'user-logout.php';
}

function showNotifications() {
    // Simple notification display
    alert('Notifications feature coming soon!');
}

function openProfileSettings() {
    alert('Profile settings feature coming soon!');
}

function openChangePassword() {
    alert('Change password feature coming soon!');
}

function openSystemSettings() {
    alert('System settings feature coming soon!');
}

function goToCalendar() {
    window.location.href = 'event-calendar.php';
}

// User management functions
function openAddUserModal() {
    alert('Add user modal coming soon!');
}

function exportUsers() {
    const csv = arrayToCSV(Object.values(users));
    downloadCSV(csv, 'users.csv');
}

function refreshUserList() {
    loadUsers();
    addAuditLog('USER_LIST_REFRESH', 'User Management', 'Refreshed user list');
}

function viewUser(userId) {
    const user = users[userId];
    if (user) {
        alert(`User Details:\n\nID: ${user.id}\nName: ${user.firstName} ${user.lastName}\nRole: ${user.role}\nEmail: ${user.email}`);
    }
}

function editUser(userId) {
    alert('Edit user feature coming soon!');
}

function deleteUser(userId) {
    if (confirm('Are you sure you want to delete this user?')) {
        delete users[userId];
        localStorage.setItem('users', JSON.stringify(users));
        loadUsers();
        addAuditLog('USER_DELETED', 'User Management', `Deleted user ${userId}`);
    }
}

// Materials management functions
function viewMaterial(materialId) {
    const material = publicMaterials.find(m => m.id === materialId);
    if (material) {
        alert(`Material Details:\n\nID: ${material.id}\nFile: ${material.fileName}\nSubmitted by: ${material.submitterName}\nStatus: ${material.status}`);
    }
}

function approveMaterial(materialId) {
    const material = publicMaterials.find(m => m.id === materialId);
    if (material) {
        material.status = 'approved';
        material.approvedBy = currentUser.id;
        material.approvalDate = new Date().toISOString();
        localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));
        loadMaterials();
        addAuditLog('MATERIAL_APPROVED', 'Materials Management', `Approved material ${material.fileName}`);
    }
}

function rejectMaterial(materialId) {
    const reason = prompt('Enter rejection reason:');
    if (reason) {
        const material = publicMaterials.find(m => m.id === materialId);
        if (material) {
            material.status = 'rejected';
            material.rejectedBy = currentUser.id;
            material.rejectionDate = new Date().toISOString();
            material.rejectionReason = reason;
            localStorage.setItem('publicMaterials', JSON.stringify(publicMaterials));
            loadMaterials();
            addAuditLog('MATERIAL_REJECTED', 'Materials Management', `Rejected material ${material.fileName}`);
        }
    }
}

function bulkDeleteMaterials() {
    alert('Bulk delete feature coming soon!');
}

function exportMaterials() {
    const csv = arrayToCSV(publicMaterials);
    downloadCSV(csv, 'materials.csv');
}

function refreshMaterialsList() {
    loadMaterials();
    addAuditLog('MATERIALS_LIST_REFRESH', 'Materials Management', 'Refreshed materials list');
}

// Audit log functions
function clearAuditLog() {
    if (confirm('Are you sure you want to clear all audit logs?')) {
        auditLog = [];
        localStorage.setItem('auditLog', JSON.stringify(auditLog));
        loadAuditLogs();
        addAuditLog('AUDIT_LOG_CLEARED', 'Audit Management', 'Cleared all audit logs');
    }
}

function exportAuditLog() {
    const csv = arrayToCSV(auditLog);
    downloadCSV(csv, 'audit-log.csv');
}

function refreshAuditLog() {
    loadAuditLogs();
    addAuditLog('AUDIT_LOG_REFRESH', 'Audit Management', 'Refreshed audit log');
}

// User role selection
function selectUserRole(role) {
    selectedUserRole = role;
    // Update UI to show selected role
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-role="${role}"]`).classList.add('active');
}

// Modal functions
function confirmDeleteUser() {
    alert('Delete user confirmation coming soon!');
}

function deleteSingleMaterial() {
    alert('Delete material feature coming soon!');
}

function confirmBulkDelete() {
    alert('Bulk delete confirmation coming soon!');
}

// Filter functions
function filterUsers() {
    const filterValue = document.getElementById('userRoleFilter').value;
    const rows = document.querySelectorAll('#usersTableBody tr');
    
    rows.forEach(row => {
        const roleCell = row.cells[2]; // Role column
        const role = roleCell.textContent.toLowerCase();
        
        if (filterValue === '' || role.includes(filterValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterMaterials() {
    const filterValue = document.getElementById('materialStatusFilter').value;
    const rows = document.querySelectorAll('#materialsTableBody tr');
    
    rows.forEach(row => {
        const statusCell = row.cells[4]; // Status column
        const status = statusCell.textContent.toLowerCase();
        
        if (filterValue === '' || status.includes(filterValue)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
