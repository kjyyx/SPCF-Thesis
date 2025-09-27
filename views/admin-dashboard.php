<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    logoutUser();
    header('Location: user-login.php');
    exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('ADMIN_DASHBOARD_VIEWED', 'User Management', 'Viewed admin dashboard', $currentUser['id'], 'User', 'INFO');

// Debug: Log user data
error_log("DEBUG event-calendar.php: Current user data: " . json_encode($currentUser));
error_log("DEBUG event-calendar.php: Session data: " . json_encode($_SESSION));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/toast.css">

    <script>
        // Pass user data to JavaScript - FIXED property names
        window.currentUser = <?php
        $jsUser = [
            'id' => $currentUser['id'],
            'firstName' => $currentUser['first_name'],
            'lastName' => $currentUser['last_name'],
            'role' => $currentUser['role'],
            'email' => $currentUser['email']
        ];
        echo json_encode($jsUser);
        ?>;
    </script>

</head>

<body class="with-fixed-navbar">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <div class="navbar-brand">
                <i class="bi bi-shield-check me-2"></i>
                Sign-um | Admin Dashboard
            </div>

            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <!-- User Info -->
                <div class="user-info me-3">
                    <i class="bi bi-person-circle me-2"></i>
                    <span id="adminUserName">Loading...</span>
                </div>

                <!-- Notifications -->
                <div class="notification-bell me-3" onclick="showNotifications()">
                    <i class="bi bi-bell"></i>
                    <span class="notification-badge" id="notificationCount">3</span>
                </div>

                <!-- Settings Dropdown -->
                <div class="dropdown me-3">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
                        data-bs-toggle="dropdown">
                        <i class="bi bi-gear me-1"></i>Settings
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="openProfileSettings()"><i
                                    class="bi bi-person-gear me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="#" onclick="openChangePassword()"><i
                                    class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="#" onclick="openSystemSettings()"><i
                                    class="bi bi-sliders me-2"></i>System Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="logout()"><i
                                    class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <!-- Admin Header -->
        <div class="admin-header">
            <div class="container-fluid">
                <div class="header-content">
                    <div class="header-text">
                        <h1 class="admin-title">
                            <i class="bi bi-shield-check"></i>
                            Sign-um Admin Panel
                        </h1>
                        <p class="admin-subtitle">Manage document workflows, users, and system security</p>
                    </div>
                    <div class="header-stats">
                        <div class="stat-item">
                            <span class="stat-number" id="totalUsers">-</span>
                            <span class="stat-label">Total Users</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number" id="totalMaterials">-</span>
                            <span class="stat-label">Materials</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number" id="pendingApprovals">-</span>
                            <span class="stat-label">Pending</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Navigation Tabs -->
        <div class="admin-tabs-container">
            <div class="container-fluid">
                <ul class="nav nav-pills admin-tabs" id="adminTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="users-tab" data-bs-toggle="pill"
                            data-bs-target="#users-panel" type="button" role="tab">
                            <i class="bi bi-people-fill"></i>
                            <span>User Management</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="materials-tab" data-bs-toggle="pill"
                            data-bs-target="#materials-panel" type="button" role="tab">
                            <i class="bi bi-file-earmark-image"></i>
                            <span>Materials</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="audit-tab" data-bs-toggle="pill" data-bs-target="#audit-panel"
                            type="button" role="tab">
                            <i class="bi bi-clipboard-data"></i>
                            <span>Audit Logs</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="calendar-tab" type="button" onclick="goToCalendar()">
                            <i class="bi bi-calendar-event"></i>
                            <span>Calendar</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Admin Tab Content -->
        <div class="tab-content admin-content" id="adminTabContent">
            <!-- User Management Panel -->
            <div class="tab-pane fade show active" id="users-panel" role="tabpanel">
                <div class="container-fluid">
                    <!-- User Controls -->
                    <div class="content-header">
                        <div class="header-actions">
                            <h3><i class="bi bi-people-fill me-2"></i>User & Document Management</h3>
                            <div class="action-buttons">
                                <button class="btn btn-primary" onclick="openAddUserModal()">
                                    <i class="bi bi-person-plus"></i>Add User
                                </button>
                                <button class="btn btn-success" onclick="exportUsers()">
                                    <i class="bi bi-download"></i>Export
                                </button>
                                <button class="btn btn-secondary" onclick="refreshUserList()">
                                    <i class="bi bi-arrow-clockwise"></i>Refresh
                                </button>
                            </div>
                        </div>
                        <div class="search-controls">
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" id="userSearch"
                                        placeholder="Search users..." onkeyup="searchUsers()">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="userRoleFilter" onchange="filterUsers()">
                                        <option value="">All Roles</option>
                                        <option value="admin">Administrators</option>
                                        <option value="employee">Employees</option>
                                        <option value="student">Students</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="content-body">
                        <div class="table-container">
                            <table class="table data-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>User ID</th>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th>Department/Office</th>
                                        <th>Contact</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <!-- Users will be populated here -->
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <nav aria-label="Users pagination">
                            <ul class="pagination justify-content-center" id="usersPagination">
                                <!-- Pagination will be populated here -->
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Public Materials Panel -->
            <div class="tab-pane fade" id="materials-panel" role="tabpanel">
                <div class="container-fluid">
                    <!-- Materials Controls -->
                    <div class="content-header">
                        <div class="header-actions">
                            <h3><i class="bi bi-file-earmark-image me-2"></i>Document Library</h3>
                            <div class="action-buttons">
                                <button class="btn btn-danger" onclick="bulkDeleteMaterials()" disabled
                                    id="bulkDeleteBtn">
                                    <i class="bi bi-trash"></i>Delete Selected
                                </button>
                                <button class="btn btn-success" onclick="exportMaterials()">
                                    <i class="bi bi-download"></i>Export
                                </button>
                                <button class="btn btn-secondary" onclick="refreshMaterialsList()">
                                    <i class="bi bi-arrow-clockwise"></i>Refresh
                                </button>
                            </div>
                        </div>
                        <div class="search-controls">
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" id="materialSearch"
                                        placeholder="Search materials..." onkeyup="searchMaterials()">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="materialStatusFilter" onchange="filterMaterials()">
                                        <option value="">All Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Materials Table -->
                    <div class="content-body">
                        <div class="table-container">
                            <table class="table data-table" id="materialsTable">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllMaterials"
                                                onchange="toggleSelectAllMaterials()"></th>
                                        <th>Material ID</th>
                                        <th>File Name</th>
                                        <th>Submitted By</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Submission Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="materialsTableBody">
                                    <!-- Materials will be populated here -->
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <nav aria-label="Materials pagination">
                            <ul class="pagination justify-content-center" id="materialsPagination">
                                <!-- Pagination will be populated here -->
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Audit Log Panel -->
            <div class="tab-pane fade" id="audit-panel" role="tabpanel">
                <div class="container-fluid">
                    <!-- Audit Controls -->
                    <div class="content-header">
                        <div class="header-actions">
                            <h3><i class="bi bi-clipboard-data me-2"></i>Document Audit Trail</h3>
                            <div class="action-buttons">
                                <button class="btn btn-warning" onclick="clearAuditLog()">
                                    <i class="bi bi-trash"></i>Clear Logs
                                </button>
                                <button class="btn btn-success" onclick="exportAuditLog()">
                                    <i class="bi bi-download"></i>Export
                                </button>
                                <button class="btn btn-secondary" onclick="refreshAuditLog()">
                                    <i class="bi bi-arrow-clockwise"></i>Refresh
                                </button>
                            </div>
                        </div>
                        <div class="search-controls">
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" id="auditSearch"
                                        placeholder="Search audit logs..." onkeyup="searchAuditLog()">
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="auditCategoryFilter" onchange="filterAuditLog()">
                                        <option value="">All Categories</option>
                                        <option value="Authentication">Authentication</option>
                                        <option value="Event Management">Event Management</option>
                                        <option value="User Management">User Management</option>
                                        <option value="Public Materials">Public Materials</option>
                                        <option value="Security">Security</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" id="auditSeverityFilter" onchange="filterAuditLog()">
                                        <option value="">All Severity</option>
                                        <option value="INFO">Info</option>
                                        <option value="WARNING">Warning</option>
                                        <option value="ERROR">Error</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Log Table -->
                    <div class="content-body">
                        <div class="table-container">
                            <table class="table data-table" id="auditTable">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Category</th>
                                        <th>Severity</th>
                                        <th>IP Address</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="auditTableBody">
                                    <!-- Audit logs will be populated here -->
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <nav aria-label="Audit log pagination">
                            <ul class="pagination justify-content-center" id="auditPagination">
                                <!-- Pagination will be populated here -->
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="userForm" novalidate>
                    <div class="modal-body">
                        <div id="userFormMessages"></div>

                        <!-- Role Selection -->
                        <div class="mb-4">
                            <label class="form-label">Select User Role</label>
                            <div class="role-selection">
                                <button type="button" class="role-btn" data-role="admin"
                                    onclick="selectUserRole('admin')">
                                    <i class="bi bi-shield-lock"></i>
                                    <span>Administrator</span>
                                </button>
                                <button type="button" class="role-btn" data-role="employee"
                                    onclick="selectUserRole('employee')">
                                    <i class="bi bi-briefcase"></i>
                                    <span>Employee</span>
                                </button>
                                <button type="button" class="role-btn" data-role="student"
                                    onclick="selectUserRole('student')">
                                    <i class="bi bi-mortarboard"></i>
                                    <span>Student</span>
                                </button>
                            </div>
                        </div>

                        <!-- Security Notice -->
                        <div id="addUserNotice" class="alert alert-info mb-4">
                            <i class="bi bi-shield-lock me-2"></i>
                            <strong>Password Security:</strong> Set a temporary password for the new user. They will be
                            required to change it on first login. Default password will be used if left empty.
                        </div>
                        <div id="editUserNotice" class="alert alert-warning mb-4" style="display: none;">
                            <i class="bi bi-shield-lock me-2"></i>
                            <strong>Password Security:</strong> Passwords cannot be viewed or edited for security
                            reasons. Users must change their own passwords through the system settings.
                        </div>

                        <!-- Basic Information -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="userIdInput" class="form-label">User ID</label>
                                    <input type="text" class="form-control" id="userIdInput"
                                        placeholder="Enter unique user ID (e.g., ADM001, EMP001, STU001)" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userFirstName" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="userFirstName"
                                        placeholder="Enter first name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userLastName" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="userLastName"
                                        placeholder="Enter last name" required>
                                </div>
                            </div>
                        </div>

                        <!-- Role-specific fields -->
                        <div id="adminFields" class="role-fields" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="adminOffice" class="form-label">Office</label>
                                        <select class="form-select" id="adminOffice" required>
                                            <option value="">Select Office</option>
                                            <option value="Administration Office">Administration Office</option>
                                            <option value="Academic Affairs">Academic Affairs</option>
                                            <option value="Student Affairs">Student Affairs</option>
                                            <option value="Finance Office">Finance Office</option>
                                            <option value="HR Department">HR Department</option>
                                            <option value="IT Department">IT Department</option>
                                            <option value="Library">Library</option>
                                            <option value="Registrar">Registrar</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="adminPosition" class="form-label">Admin Position</label>
                                        <select class="form-select" id="adminPosition" required>
                                            <option value="">Select Position</option>
                                            <option value="System Administrator">System Administrator</option>
                                            <option value="Database Administrator">Database Administrator</option>
                                            <option value="Network Administrator">Network Administrator</option>
                                            <option value="IT Director">IT Director</option>
                                            <option value="Chief Information Officer">Chief Information Officer</option>
                                            <option value="Technical Lead">Technical Lead</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="employeeFields" class="role-fields" style="display: none;">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="employeeOffice" class="form-label">Office</label>
                                        <select class="form-select" id="employeeOffice" required>
                                            <option value="">Select Office</option>
                                            <option value="Administration Office">Administration Office</option>
                                            <option value="Academic Affairs">Academic Affairs</option>
                                            <option value="Student Affairs">Student Affairs</option>
                                            <option value="Finance Office">Finance Office</option>
                                            <option value="HR Department">HR Department</option>
                                            <option value="IT Department">IT Department</option>
                                            <option value="Library">Library</option>
                                            <option value="Registrar">Registrar</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="employeeDepartment" class="form-label">Department</label>
                                        <select class="form-select" id="employeeDepartment" required>
                                            <option value="">Select Department</option>
                                            <option value="College of Engineering">College of Engineering</option>
                                            <option value="College of Nursing">College of Nursing</option>
                                            <option value="College of Business">College of Business</option>
                                            <option value="College of Criminology">College of Criminology</option>
                                            <option value="College of Computing and Information Sciences">College of Computing and Information Sciences</option>
                                            <option value="College of Art and Social Sciences and Education">College of Art and Social Sciences and Education</option>
                                            <option value="Colleges of Hospitality and Tourism Management">Colleges of Hospitality and Tourism Management</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="employeePosition" class="form-label">Employee Position</label>
                                        <select class="form-select" id="employeePosition" required>
                                            <option value="">Select Position</option>
                                            <option value="President">President</option>
                                            <option value="Vice President">Vice President</option>
                                            <option value="Dean">Dean</option>
                                            <option value="Associate Dean">Associate Dean</option>
                                            <option value="Department Head">Department Head</option>
                                            <option value="Professor">Professor</option>
                                            <option value="Associate Professor">Associate Professor</option>
                                            <option value="Assistant Professor">Assistant Professor</option>
                                            <option value="Instructor">Instructor</option>
                                            <option value="Administrative Staff">Administrative Staff</option>
                                            <option value="System Administrator">System Administrator</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="studentFields" class="role-fields" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="studentDepartment" class="form-label">Department/College</label>
                                        <select class="form-select" id="studentDepartment" required>
                                            <option value="">Select Department</option>
                                            <option value="College of Engineering">College of Engineering</option>
                                            <option value="College of Nursing">College of Nursing</option>
                                            <option value="College of Business">College of Business</option>
                                            <option value="College of Criminology">College of Criminology</option>
                                            <option value="College of Computing and Information Sciences">College of
                                                Computing and Information Sciences</option>
                                            <option value="College of Art and Social Sciences and Education">College of
                                                Art and Social Sciences and Education</option>
                                            <option value="Colleges of Hospitality and Tourism Management">Colleges of
                                                Hospitality and Tourism Management</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="studentPosition" class="form-label">Student Body Position</label>
                                        <select class="form-select" id="studentPosition">
                                            <option value="">Select Position (Optional)</option>
                                            <option value="Student Council President">Student Council President</option>
                                            <option value="Student Council Vice President">Student Council Vice
                                                President</option>
                                            <option value="Student Council Secretary">Student Council Secretary</option>
                                            <option value="Student Council Treasurer">Student Council Treasurer</option>
                                            <option value="Class President">Class President</option>
                                            <option value="Class Vice President">Class Vice President</option>
                                            <option value="Class Secretary">Class Secretary</option>
                                            <option value="Class Treasurer">Class Treasurer</option>
                                            <option value="Club President">Club President</option>
                                            <option value="Club Officer">Club Officer</option>
                                            <option value="Regular Student">Regular Student</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userEmail" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="userEmail"
                                        placeholder="Enter email address (e.g., user@university.edu)" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userPhone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="userPhone"
                                        placeholder="Enter phone number (e.g., 09123456789 or +639123456789)" required>
                                </div>
                            </div>
                        </div>

                        <!-- Password Section (only for new users) -->
                        <div id="passwordSection" class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userPassword" class="form-label">Temporary Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="userPassword"
                                            placeholder="Enter temporary password (leave empty for default)">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                            onclick="togglePasswordVisibility('userPassword')">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Default password: <code>ChangeMe123!</code> (user must change
                                        on first login)</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="userConfirmPassword" class="form-label">Confirm Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="userConfirmPassword"
                                            placeholder="Confirm temporary password">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                            onclick="togglePasswordVisibility('userConfirmPassword')">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger bg-opacity-10 border-danger">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <i class="bi bi-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                        <h5>Are you sure you want to delete this user?</h5>
                        <p class="text-muted mb-0">This action cannot be undone.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">
                        <i class="bi bi-trash me-2"></i>Delete User
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Material Detail Modal -->
    <div class="modal fade" id="materialDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-file-earmark-image me-2"></i>Material Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-item">
                                <label class="detail-label">Material ID</label>
                                <div class="detail-value" id="materialDetailId">-</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-item">
                                <label class="detail-label">File Name</label>
                                <div class="detail-value" id="materialDetailFileName">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="detail-label">Description</label>
                        <div class="detail-value" id="materialDetailDescription">-</div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="detail-item">
                                <label class="detail-label">Status</label>
                                <div class="detail-value" id="materialDetailStatus">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="detail-item">
                                <label class="detail-label">File Size</label>
                                <div class="detail-value" id="materialDetailSize">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="detail-item">
                                <label class="detail-label">Downloads</label>
                                <div class="detail-value" id="materialDetailDownloads">-</div>
                            </div>
                        </div>
                    </div>
                    <div id="materialApprovalInfo" style="display: none;">
                        <hr>
                        <h6 class="text-success">Approval Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="detail-label">Approved By</label>
                                <div class="detail-value" id="materialApprovedBy">-</div>
                            </div>
                            <div class="col-md-6">
                                <label class="detail-label">Approval Date</label>
                                <div class="detail-value" id="materialApprovalDate">-</div>
                            </div>
                        </div>
                    </div>
                    <div id="materialRejectionInfo" style="display: none;">
                        <hr>
                        <h6 class="text-danger">Rejection Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="detail-label">Rejected By</label>
                                <div class="detail-value" id="materialRejectedBy">-</div>
                            </div>
                            <div class="col-md-6">
                                <label class="detail-label">Rejection Date</label>
                                <div class="detail-value" id="materialRejectionDate">-</div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="detail-label">Rejection Reason</label>
                            <div class="detail-value" id="materialRejectionReason">-</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="downloadMaterialBtn" onclick="downloadMaterial()">
                        <i class="bi bi-download me-2"></i>Download
                    </button>
                    <button type="button" class="btn btn-danger" id="deleteMaterialBtn"
                        onclick="deleteSingleMaterial()">
                        <i class="bi bi-trash me-2"></i>Delete Material
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Materials Modal -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger bg-opacity-10 border-danger">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Bulk Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <i class="bi bi-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                        <h5>Delete Selected Materials?</h5>
                        <p class="text-muted mb-0">This action cannot be undone.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()">
                        <i class="bi bi-trash me-2"></i>Delete Materials
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Audit Detail Modal -->
    <div class="modal fade" id="auditDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-data me-2"></i>Audit Log Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-item">
                                <label class="detail-label">Timestamp</label>
                                <div class="detail-value" id="auditDetailTimestamp">-</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-item">
                                <label class="detail-label">User</label>
                                <div class="detail-value" id="auditDetailUser">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="detail-label">Action Details</label>
                        <div class="detail-value" id="auditDetailAction">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="detail-label">System Information</label>
                        <div class="detail-value" id="auditDetailSystem">-</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2"></i>Change Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="changePasswordForm">
                    <div class="modal-body">
                        <div id="changePasswordMessages"></div>
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="currentPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('currentPassword')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="newPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('newPassword')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="confirmPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('confirmPassword')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/user-login.js"></script>
    <script src="../assets/js/admin-dashboard.js"></script>
</body>

</html>