<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/constants.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth();

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    logoutUser();
    header('Location: ' . BASE_URL . 'login');
    exit();
}

function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, ip_address, user_agent, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$currentUser['id'], $currentUser['role'], $currentUser['first_name'] . ' ' . $currentUser['last_name'], $action, $category, $details, $targetId, $targetType, $_SERVER['REMOTE_ADDR'] ?? null, null, $severity ?? 'INFO']);
    } catch (Exception $e) {
    }
}

addAuditLog('ADMIN_DASHBOARD_VIEWED', 'System', 'Viewed admin dashboard', $currentUser['id'], 'User', 'INFO');
$pageTitle = 'Admin Dashboard';
$currentPage = 'dashboard';

$depts = $DEPARTMENTS;
$docTypes = $DOC_TYPES;
$offices = $OFFICES;

echo "<script>
    const APP_CONSTANTS = {
        DEPARTMENTS: " . json_encode($DEPARTMENTS) . ",
        OFFICES: " . json_encode($OFFICES) . ",
        POSITIONS: " . json_encode($POSITIONS) . ",
        DOC_TYPES: " . json_encode($DOC_TYPES) . "
    };
</script>";

$renderOpts = function ($arr) { foreach ($arr as $opt) echo "<option value=\"$opt\">$opt</option>"; };
$renderKeyOpts = function ($arr) { foreach ($arr as $key => $val) echo "<option value=\"$key\">$val</option>"; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/Sign-UM logo ico.png">
    <title>Sign-um - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-css.css">
    <style>
        .admin-content { padding: 0 1.5rem 2rem; }
        .container-fluid { max-width: 1400px; margin: 0 auto; }
        .stat-item { backdrop-filter: blur(10px); }
        .data-table td { vertical-align: middle; }
        .stacked-cell-title { font-weight: 600; color: var(--color-text-primary); font-size: 0.95rem; }
        .stacked-cell-sub { font-size: 0.75rem; color: var(--color-text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .detail-block { background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(0, 0, 0, 0.05); border-radius: var(--radius-xl); padding: 1.25rem; height: 100%; }
        .detail-block-primary { background: rgba(18, 89, 195, 0.04); border: 1px solid rgba(18, 89, 195, 0.1); border-radius: var(--radius-xl); padding: 1.25rem; height: 100%; }
        .detail-label { font-size: 0.75rem; font-weight: 700; color: var(--color-text-tertiary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .detail-value { font-size: 0.95rem; font-weight: 500; color: var(--color-text-primary); }
    </style>
    <script>
        window.currentUser = <?= json_encode(['id' => $currentUser['id'], 'firstName' => $currentUser['first_name'], 'lastName' => $currentUser['last_name'], 'role' => $currentUser['role'], 'email' => $currentUser['email']]) ?>;
        window.BASE_URL = "<?= BASE_URL ?>";
    </script>
</head>
<body class="has-navbar">
    <?php include ROOT_PATH . 'includes/navbar.php'; include ROOT_PATH . 'includes/notifications.php'; ?>

    <div class="container-fluid pt-4 pb-5 px-4 max-w-7xl mx-auto">
        <div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="page-title"><i class="bi bi-shield-lock text-primary me-2"></i> System Administration</h1>
                <p class="page-subtitle">Manage system users, workflows, and data retention.</p>
            </div>
            <div class="page-actions d-flex gap-2">
                <button class="btn btn-outline-secondary shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#systemSettingsModal"><i class="bi bi-gear me-2"></i> Settings</button>
                <button class="btn btn-primary shadow-sm rounded-pill px-4" onclick="openAddUserModal()"><i class="bi bi-person-plus me-2"></i> Add New User</button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-3 col-xl-2">
                <div class="card card-flat shadow-sm border-0 position-sticky" style="top: 100px;">
                    <div class="card-body p-3">
                        <div class="nav flex-column nav-pills custom-nav-pills gap-2" id="adminTabs" role="tablist">
                            <button class="nav-link active text-start rounded-pill" data-bs-toggle="pill" data-bs-target="#dashboard-panel" type="button"><i class="bi bi-grid-1x2 me-2"></i> Overview</button>
                            <button class="nav-link text-start rounded-pill" data-bs-toggle="pill" data-bs-target="#users-panel" type="button"><i class="bi bi-people me-2"></i> User Directory</button>
                            <button class="nav-link text-start rounded-pill" data-bs-toggle="pill" data-bs-target="#documents-panel" type="button"><i class="bi bi-file-earmark-text me-2"></i> Documents</button>
                            <button class="nav-link text-start rounded-pill" data-bs-toggle="pill" data-bs-target="#materials-panel" type="button"><i class="bi bi-images me-2"></i> Materials</button>
                            <button class="nav-link text-start rounded-pill" data-bs-toggle="pill" data-bs-target="#audit-panel" type="button"><i class="bi bi-journal-text me-2"></i> Audit Logs</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9 col-xl-10">
                <div class="tab-content" id="adminTabsContent">
                    <div class="tab-pane fade show active" id="dashboard-panel" role="tabpanel">
                        <div class="row g-4 mb-4">
                            <?php
                            $stats = [['dashboardTotalUsers', 'Total Users', 'bi-people', 'primary'], ['dashboardTotalDocs', 'Documents', 'bi-file-earmark-check', 'success'], ['dashboardTotalMaterials', 'Materials', 'bi-images', 'info'], ['dashboardTotalLogs', 'Sys Actions', 'bi-activity', 'warning']];
                            foreach ($stats as $s) echo "<div class='col-md-3'><div class='card shadow-sm border-0 h-100'><div class='card-body d-flex align-items-center'><div class='bg-{$s[3]} bg-opacity-10 text-{$s[3]} rounded-circle p-3 me-3'><i class='bi {$s[2]} fs-4'></i></div><div><h6 class='text-muted mb-1 text-xs text-uppercase fw-bold'>{$s[1]}</h6><h3 class='mb-0 fw-bold' id='{$s[0]}'>...</h3></div></div></div></div>";
                            ?>
                        </div>
                        <div class="row g-4">
                            <div class="col-lg-8"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-transparent py-3 border-bottom"><h6 class="mb-0 fw-bold">System Activity Overview</h6></div><div class="card-body p-4"><canvas id="auditActivityChart" height="250"></canvas></div></div></div>
                            <div class="col-lg-4"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-transparent py-3 border-bottom"><h6 class="mb-0 fw-bold">User Distribution</h6></div><div class="card-body p-4 d-flex justify-content-center align-items-center"><canvas id="userRoleChart" height="250"></canvas></div></div></div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="users-panel" role="tabpanel">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0 fw-bold">User Directory</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="exportUsers()"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Export</button>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="userRoleFilter" onchange="filterUsers()" style="width:130px;"><option value="">All Roles</option><option value="student">Students</option><option value="employee">Employees</option><option value="admin">Admins</option></select>
                                    <div class="input-group input-group-sm" style="width:250px;"><span class="input-group-text bg-transparent rounded-start-pill"><i class="bi bi-search text-muted"></i></span><input type="text" class="form-control border-start-0 rounded-end-pill ps-0" id="userSearch" placeholder="Search users..." onkeyup="searchUsers()"></div>
                                </div>
                            </div>
                            <div class="bulk-operations bg-primary bg-opacity-10 p-2 border-bottom justify-content-between align-items-center px-4" id="bulkOperationsBar" style="display:none !important;">
                                <div><span id="selectedCount" class="fw-bold text-primary">0</span> <span class="text-primary text-sm">users selected</span></div>
                                <div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary bg-white" onclick="clearSelection()">Cancel</button><button class="btn btn-warning" onclick="bulkResetPasswords()"><i class="bi bi-key"></i> Reset Passwords</button><button class="btn btn-danger" onclick="bulkDeleteUsers()"><i class="bi bi-trash"></i> Delete</button></div>
                            </div>
                            <div class="card-body p-0"><div class="table-responsive"><table class="table data-table align-middle mb-0" id="usersTable">
                                <thead><tr><th class="ps-4" style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllUsers" onchange="toggleSelectAll()"></th><th>User ID</th><th>Identity</th><th>Role & Pos</th><th>Department</th><th>Status / Security</th><th class="text-end pe-4">Manage</th></tr></thead>
                                <tbody id="usersTableBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr></tbody>
                            </table></div></div>
                            <div class="card-footer bg-transparent border-top py-3"><ul class="pagination pagination-sm justify-content-end mb-0" id="usersPagination"></ul></div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="documents-panel" role="tabpanel">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0 fw-bold">Document Library</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="bulkDeleteDocuments()" disabled id="bulkDeleteDocsBtn"><i class="bi bi-trash"></i></button>
                                    <button class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="exportDocuments()"><i class="bi bi-download me-1"></i> Export</button>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="documentDeptFilter" onchange="filterDocuments()" style="width: 160px; max-width: 100%;"><option value="">All Departments</option><?php $renderOpts($depts); ?></select>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="documentTypeFilter" onchange="filterDocuments()" style="width: 140px;"><option value="">All Doc Types</option><?php $renderKeyOpts($docTypes); ?></select>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="documentStatusFilter" onchange="filterDocuments()" style="width:110px;"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select>
                                    <div class="input-group input-group-sm" style="width:200px;"><span class="input-group-text bg-transparent rounded-start-pill"><i class="bi bi-search text-muted"></i></span><input type="text" class="form-control border-start-0 rounded-end-pill ps-0" id="documentSearch" placeholder="Search docs..." onkeyup="searchDocuments()"></div>
                                </div>
                            </div>
                            <div class="card-body p-0"><div class="table-responsive"><table class="table data-table align-middle mb-0" id="documentsTable">
                                <thead><tr><th class="ps-4" style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllDocuments" onchange="toggleSelectAllDocuments()"></th><th>Document Reference</th><th>Submission Details</th><th>Status</th><th>Date</th><th class="text-end pe-4">Actions</th></tr></thead>
                                <tbody id="documentsTableBody"><tr><td colspan="6" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr></tbody>
                            </table></div></div>
                            <div class="card-footer bg-transparent border-top py-3"><ul class="pagination pagination-sm justify-content-end mb-0" id="documentsPagination"></ul></div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="materials-panel" role="tabpanel">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="mb-0 fw-bold">Publication Materials</h5>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="bulkDeleteMaterials()" disabled id="bulkDeleteBtn"><i class="bi bi-trash"></i></button>
                                    <button class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="exportMaterials()"><i class="bi bi-download me-1"></i> Export</button>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="materialDeptFilter" onchange="filterMaterials()" style="width: 160px; max-width: 100%;"><option value="">All Departments</option><?php $renderOpts($depts); ?></select>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="materialStatusFilter" onchange="filterMaterials()" style="width:110px;"><option value="">All Status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select>
                                    <div class="input-group input-group-sm" style="width:200px;"><span class="input-group-text bg-transparent rounded-start-pill"><i class="bi bi-search text-muted"></i></span><input type="text" class="form-control border-start-0 rounded-end-pill ps-0" id="materialSearch" placeholder="Search mats..." onkeyup="searchMaterials()"></div>
                                </div>
                            </div>
                            <div class="card-body p-0"><div class="table-responsive"><table class="table data-table align-middle mb-0" id="materialsTable">
                                <thead><tr><th class="ps-4" style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllMaterials" onchange="toggleSelectAllMaterials()"></th><th>Material Reference</th><th>Submission Details</th><th>Status</th><th>Date</th><th class="text-end pe-4">Actions</th></tr></thead>
                                <tbody id="materialsTableBody"><tr><td colspan="6" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr></tbody>
                            </table></div></div>
                            <div class="card-footer bg-transparent border-top py-3"><ul class="pagination pagination-sm justify-content-end mb-0" id="materialsPagination"></ul></div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="audit-panel" role="tabpanel">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-transparent py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold">System Audit Trail</h5>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="clearAuditLog()"><i class="bi bi-trash"></i> Clear</button>
                                    <button class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="exportAuditLog()"><i class="bi bi-download"></i></button>
                                    <select class="form-select form-select-sm rounded-pill px-3" id="auditCategoryFilter" onchange="filterAuditLog()" style="width:130px;"><option value="">All Categories</option><option value="Authentication">Authentication</option><option value="User Management">Users</option><option value="System">System</option></select>
                                    <div class="input-group input-group-sm" style="width:200px;"><span class="input-group-text bg-transparent rounded-start-pill"><i class="bi bi-search text-muted"></i></span><input type="text" class="form-control border-start-0 rounded-end-pill ps-0" id="auditSearch" placeholder="Search logs..." onkeyup="searchAuditLog()"></div>
                                </div>
                            </div>
                            <div class="card-body p-0"><div class="table-responsive"><table class="table data-table align-middle mb-0" id="auditTable">
                                <thead><tr><th class="ps-4">Timestamp</th><th>Identity</th><th>Action Taken</th><th>Category</th><th>Severity</th><th class="text-end pe-4">Details</th></tr></thead>
                                <tbody id="auditTableBody"></tbody>
                            </table></div></div>
                            <div class="card-footer bg-transparent border-top py-3"><ul class="pagination pagination-sm justify-content-end mb-0" id="auditPagination"></ul></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold" id="userModalLabel">Add New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="userForm" novalidate><div class="modal-body p-4"><div id="userFormMessages"></div>
            <div class="mb-4"><label class="form-label">User Role <span class="text-danger">*</span></label><div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary rounded-pill role-btn flex-fill" data-role="admin" onclick="selectUserRole('admin')"><i class="bi bi-shield-lock me-2"></i>Admin</button><button type="button" class="btn btn-outline-primary rounded-pill role-btn flex-fill" data-role="employee" onclick="selectUserRole('employee')"><i class="bi bi-briefcase me-2"></i>Employee</button><button type="button" class="btn btn-outline-primary rounded-pill role-btn flex-fill" data-role="student" onclick="selectUserRole('student')"><i class="bi bi-mortarboard me-2"></i>Student</button></div></div>
            <div id="addUserNotice" class="alert alert-info rounded-3 mb-4"><i class="bi bi-info-circle me-2"></i>Set a temporary password. If left empty, the default is <strong>ChangeMe123!</strong></div>
            <div id="editUserNotice" class="alert alert-warning rounded-3 mb-4" style="display:none;"><i class="bi bi-shield-lock me-2"></i>Passwords cannot be viewed or edited here. Use the Reset Password bulk action if needed.</div>
            
            <div class="row g-3 mb-3"><div class="col-md-12"><label class="form-label">User ID / Reference Number <span class="text-danger">*</span></label><input type="text" class="form-control" id="userIdInput" placeholder="e.g. 02-1234" required></div></div>
            <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="userFirstName" placeholder="e.g. Juan" required></div><div class="col-md-6"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="userLastName" placeholder="e.g. Dela Cruz" required></div></div>
            
            <div id="adminFields" class="role-fields mb-3" style="display:none;"><div class="row g-3"><div class="col-md-6"><label class="form-label">Office <span class="text-danger">*</span></label><select class="form-select" id="adminOffice" required><option value="">-- Select Office --</option></select></div><div class="col-md-6"><label class="form-label">Position <span class="text-danger">*</span></label><select class="form-select" id="adminPosition" required><option value="">-- Select Position --</option></select></div></div></div>
            <div id="employeeFields" class="role-fields mb-3" style="display:none;"><div class="row g-3"><div class="col-md-6"><label class="form-label">Position <span class="text-danger">*</span></label><select class="form-select" id="employeePosition" required><option value="">-- Select Position --</option></select></div><div class="col-md-6"><label class="form-label">Department <span class="text-danger">*</span></label><select class="form-select" id="employeeDepartment" required><option value="">-- Select Department --</option></select></div></div></div>
            <div id="studentFields" class="role-fields mb-3" style="display:none;"><div class="row g-3"><div class="col-md-6"><label class="form-label">Department/College <span class="text-danger">*</span></label><select class="form-select" id="studentDepartment" required><option value="">-- Select Department --</option></select></div><div class="col-md-6"><label class="form-label">Position (Optional)</label><select class="form-select" id="studentPosition"><option value="">Regular Student (No Position)</option></select></div></div></div>
            
            <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Email Address <span class="text-danger">*</span></label><input type="email" class="form-control" id="userEmail" placeholder="e.g. juan@spcf.edu.ph" required></div><div class="col-md-6"><label class="form-label">Phone Number <span class="text-danger">*</span></label><input type="tel" class="form-control" id="userPhone" placeholder="e.g. 09123456789" required></div></div>
            <div id="passwordSection" class="row g-3"><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="userPassword" placeholder="Leave blank for default"></div><div class="col-md-6"><label class="form-label">Confirm Password</label><input type="password" class="form-control" id="userConfirmPassword" placeholder="Leave blank for default"></div></div>

            <div id="editSecuritySection" class="mt-4 pt-4 border-top" style="display:none;">
                <h6 class="detail-label text-dark mb-3"><i class="bi bi-shield-check me-2 text-primary"></i>Account Security & Status</h6>
                
                <div class="d-flex align-items-center justify-content-between mb-3 p-3 rounded-3 border bg-secondary bg-opacity-10">
                    <div>
                        <div class="fw-bold text-dark text-sm mb-1">Two-Factor Authentication (2FA)</div>
                        <div class="text-xs text-muted" id="status2FAText">Loading...</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 shadow-sm" id="btnReset2FA">
                        <i class="bi bi-shield-x me-1"></i> Reset 2FA
                    </button>
                </div>

                <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border bg-secondary bg-opacity-10">
                    <div>
                        <div class="fw-bold text-dark text-sm mb-1">Account Access Status</div>
                        <div class="text-xs text-muted" id="statusAccountText">Loading...</div>
                    </div>
                    <button type="button" class="btn btn-sm rounded-pill px-3 shadow-sm" id="btnToggleStatus">
                        Loading...
                    </button>
                </div>
            </div>

        </div><div class="modal-footer border-top-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4">Save User</button></div></form>
    </div></div></div>

    <div class="modal fade" id="documentDetailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-text text-primary me-2"></i>Document Record Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div><h4 id="docDetailTitle" class="fw-bold mb-1 text-dark">Document Title</h4><span id="docDetailType" class="badge bg-secondary me-2">Type</span><span id="docDetailStatus" class="badge">Status</span></div>
                <div class="text-end"><span class="text-muted text-xs d-block mb-1">Reference ID</span><strong id="docDetailId" class="text-dark bg-light px-2 py-1 rounded border">DOC-000</strong></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><div class="detail-block-primary"><h6 class="detail-label text-primary">Submission Metadata</h6><div class="mb-2"><span class="text-muted d-block text-xs">Submitted By</span><span id="docDetailSubmitter" class="detail-value"></span></div><div class="mb-2"><span class="text-muted d-block text-xs">Originating Department</span><span id="docDetailDept" class="detail-value"></span></div><div><span class="text-muted d-block text-xs">Timestamp</span><span id="docDetailDate" class="detail-value"></span></div></div></div>
                <div class="col-md-6"><div class="detail-block"><h6 class="detail-label">File Information</h6><div class="mb-2"><span class="text-muted d-block text-xs">Attached File</span><div class="d-flex align-items-center"><i class="bi bi-file-pdf text-danger me-2 fs-5"></i><span id="docDetailFileName" class="detail-value text-truncate d-inline-block" style="max-width:200px;"></span></div></div><div><span class="text-muted d-block text-xs">Notes / Description</span><p id="docDetailDesc" class="detail-value mb-0 text-sm mt-1">-</p></div></div></div>
            </div>
            <div id="docReviewSection" class="detail-block border-warning bg-warning bg-opacity-10" style="display:none;"><h6 class="detail-label text-warning text-dark" id="docReviewTitle">Review Workflow Information</h6><div class="row"><div class="col-md-6"><span class="text-muted d-block text-xs">Reviewed By</span><span id="docReviewer" class="detail-value"></span></div><div class="col-md-6"><span class="text-muted d-block text-xs">Review Timestamp</span><span id="docReviewDate" class="detail-value"></span></div></div><div id="docReviewRemarksContainer" class="mt-3 pt-3 border-top border-warning border-opacity-25" style="display:none;"><span class="text-muted d-block text-xs">Review Remarks / Reason</span><p id="docReviewRemarks" class="detail-value fw-bold text-dark mb-0"></p></div></div>
        </div>
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary rounded-pill shadow-sm px-4" onclick="downloadDocument()"><i class="bi bi-download me-2"></i>Download</button><button type="button" class="btn btn-outline-danger rounded-pill px-4 ms-2" onclick="deleteSingleDocument()"><i class="bi bi-trash"></i></button></div>
    </div></div></div>

    <div class="modal fade" id="materialDetailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-image text-info me-2"></i>Publication Material Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div><h4 id="materialDetailTitle" class="fw-bold mb-2 text-dark">Material Title</h4><span id="materialDetailStatus" class="badge">Status</span></div>
                <div class="text-end"><span class="text-muted text-xs d-block mb-1">Reference ID</span><strong id="materialDetailId" class="text-dark bg-light px-2 py-1 rounded border">MAT-000</strong></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><div class="detail-block-primary"><h6 class="detail-label text-primary">Submission Metadata</h6><div class="mb-2"><span class="text-muted d-block text-xs">Submitted By</span><span id="materialDetailSubmitter" class="detail-value"></span></div><div class="mb-2"><span class="text-muted d-block text-xs">Originating Department</span><span id="materialDetailDept" class="detail-value"></span></div><div><span class="text-muted d-block text-xs">Timestamp</span><span id="materialDetailDate" class="detail-value"></span></div></div></div>
                <div class="col-md-6"><div class="detail-block"><h6 class="detail-label">File Information</h6><div class="mb-2"><span class="text-muted d-block text-xs">Attached Image</span><div class="d-flex align-items-center"><i class="bi bi-image text-info me-2 fs-5"></i><span id="materialDetailFileName" class="detail-value text-truncate d-inline-block" style="max-width:200px;"></span></div></div><div><span class="text-muted d-block text-xs">Caption / Description</span><p id="materialDetailDesc" class="detail-value mb-0 text-sm mt-1">-</p></div></div></div>
            </div>
            <div id="materialReviewSection" class="detail-block border-warning bg-warning bg-opacity-10" style="display:none;"><h6 class="detail-label text-warning text-dark" id="materialReviewTitle">Review Workflow Information</h6><div class="row"><div class="col-md-6"><span class="text-muted d-block text-xs">Reviewed By</span><span id="materialReviewer" class="detail-value"></span></div><div class="col-md-6"><span class="text-muted d-block text-xs">Review Timestamp</span><span id="materialReviewDate" class="detail-value"></span></div></div><div id="materialReviewRemarksContainer" class="mt-3 pt-3 border-top border-warning border-opacity-25" style="display:none;"><span class="text-muted d-block text-xs">Review Remarks / Reason</span><p id="materialReviewRemarks" class="detail-value fw-bold text-dark mb-0"></p></div></div>
        </div>
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary rounded-pill shadow-sm px-4" onclick="downloadMaterial()"><i class="bi bi-download me-2"></i>Download</button><button type="button" class="btn btn-outline-danger rounded-pill px-4 ms-2" onclick="deleteSingleMaterial()"><i class="bi bi-trash"></i></button></div>
    </div></div></div>

    <div class="modal fade" id="auditDetailModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>Audit Log Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4"><div class="detail-block mb-3"><span class="detail-label">Timestamp</span><div id="auditDetailTimestamp" class="detail-value mb-3"></div><span class="detail-label">User</span><div id="auditDetailUser" class="detail-value mb-3"></div><span class="detail-label">Action</span><div id="auditDetailAction" class="detail-value mb-3"></div><span class="detail-label">System Information</span><div id="auditDetailSystem" class="detail-value"></div></div></div>
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Close</button></div>
    </div></div></div>

    <div class="modal fade" id="deleteConfirmModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-0 pb-0"><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body text-center pb-4">
            <i class="bi bi-exclamation-circle text-danger mb-3" id="confirmModalIcon" style="font-size: 3rem;"></i>
            <h5 class="modal-title fw-bold text-dark mb-2" id="confirmModalTitle">Confirm Action</h5>
            <p class="text-muted text-sm mb-4" id="deleteConfirmMessage">Are you sure? This action cannot be undone.</p>
            <div class="d-flex justify-content-center gap-2">
                <button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm" id="confirmDeleteBtn">Confirm</button>
            </div>
        </div>
    </div></div></div>

    <div class="modal fade" id="systemSettingsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-sliders me-2 text-primary"></i>System Settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4"><form id="generalSettingsForm"><div class="mb-4"><label class="form-label">Academic Year <span class="text-danger">*</span></label><input type="text" class="form-control" id="sysAcadYear" value="2025-2026" placeholder="e.g. 2025-2026"></div><div class="mb-4"><label class="form-label">Global 2FA Requirement</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" id="enable2FA"><label class="form-check-label ms-2">Force 2FA for all new accounts</label></div></div></form></div>
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary rounded-pill shadow-sm px-4" onclick="document.getElementById('generalSettingsForm').dispatchEvent(new Event('submit'))">Save Settings</button></div>
    </div></div></div>

    <div class="modal fade" id="profileSettingsModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header border-bottom-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-person-gear me-2 text-primary"></i>Profile Settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4"><form id="profileSettingsForm"><div id="profileSettingsMessages"></div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="profileFirstName" placeholder="e.g. Juan" required></div><div class="col-md-6"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="profileLastName" placeholder="e.g. Dela Cruz" required></div></div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" id="profileEmail" placeholder="e.g. email@spcf.edu.ph" required></div><div class="col-md-6"><label class="form-label">Phone <span class="text-danger">*</span></label><input type="tel" class="form-control" id="profilePhone" placeholder="e.g. 09123456789" required></div></div></form></div>
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4"><button type="button" class="btn btn-ghost rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary rounded-pill shadow-sm px-4" onclick="document.getElementById('profileSettingsForm').dispatchEvent(new Event('submit'))">Update Profile</button></div>
    </div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/toast.js"></script>
    <script src="<?= BASE_URL ?>assets/js/navbar-settings.js"></script>
    <script src="<?= BASE_URL ?>assets/js/global-notifications.js"></script>
    <script src="<?= BASE_URL ?>assets/js/admin-dashboard.js"></script>
</body>
</html>