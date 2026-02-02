<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user first to check role
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
if (!$currentUser) {
    logoutUser();
    header('Location: user-login.php');
    exit();
}

// Restrict Accounting employees to only SAF access
if ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) {
    header('Location: saf.php');
    exit();
}

// Define constants for better maintainability
const ALLOWED_ROLES = ['employee'];
const ALLOWED_STUDENT_POSITIONS = ['SSC President'];

// Allow employees and SSC President students only
$userHasAccess = in_array($currentUser['role'], ALLOWED_ROLES) ||
    ($currentUser['role'] === 'student' && in_array($currentUser['position'], ALLOWED_STUDENT_POSITIONS));

if (!$userHasAccess) {
    header('Location: user-login.php?error=access_denied');
    exit();
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Audit log helper function with improved error handling
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
    global $currentUser;

    if (!$currentUser) {
        error_log("Audit log failed: No current user");
        return false;
    }

    try {
        $db = new Database();
        $conn = $db->getConnection();

        // Validate inputs
        $action = trim($action);
        $category = trim($category);
        $details = trim($details);
        $severity = in_array(strtoupper($severity), ['INFO', 'WARNING', 'ERROR', 'CRITICAL']) ? strtoupper($severity) : 'INFO';

        if (empty($action) || empty($category) || empty($details)) {
            throw new Exception("Invalid audit log parameters");
        }

        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $result = $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            trim($currentUser['first_name'] . ' ' . $currentUser['last_name']),
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return $result;
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
        return false;
    }
}

// Log page view with error handling
addAuditLog('NOTIFICATIONS_VIEWED', 'Notifications', 'Viewed notifications page', $currentUser['id'], 'User', 'INFO');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Document Notifications</title>
    <meta name="description" content="Modern document notification and digital signature system">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- 
        CSS Loading Order (Important):
        1. global.css - OneUI foundation (navbar, buttons, forms, cards, modals, utilities)
        2. event-calendar.css - Calendar-specific components (optional, for shared header styles)
        3. notifications.css - Page-specific overrides and custom components
        4. toast.css - Toast notification styles
    -->
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/event-calendar.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/toast.css">
    <link rel="stylesheet" href="../assets/css/global-notifications.css"><!-- Global notifications styles -->

    <script>
        // Provide user data to JS (employee-only)
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
    <?php
    // Set page title for navbar
    $pageTitle = 'Document Notifications';
    include '../includes/navbar.php';
    include '../includes/notifications.php';
    ?>

    <!-- Main Content - OneUI Compact Design -->
    <div class="main-content notifications-page">
        <!-- Unified Header Bar -->
        <div class="notifications-header">
            <div class="container-fluid">
                <div class="header-row">
                    <!-- Left: Title & Stats -->
                    <div class="header-title-group">
                        <div class="title-wrapper">
                            <div class="title-icon">
                                <i class="bi bi-inbox-fill"></i>
                            </div>
                            <div class="title-content">
                                <h1>Documents</h1>
                                <p class="title-meta">
                                    <span id="totalDocsCount" class="meta-count">0</span> documents • 
                                    <span id="pendingCount" class="meta-pending">0</span> pending action
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Center: Search -->
                    <div class="header-search">
                        <div class="search-wrapper">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" id="documentSearch" class="search-input" 
                                   placeholder="Search documents..." aria-label="Search documents">
                            <button class="search-clear" id="clearSearch" aria-label="Clear search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Right: Sort & View Toggle -->
                    <div class="header-controls">
                        <div class="sort-dropdown">
                            <select id="sortSelect" class="sort-select" aria-label="Sort documents">
                                <option value="date_desc">Newest First</option>
                                <option value="date_asc">Oldest First</option>
                                <option value="due_desc">Due Soon</option>
                                <option value="due_asc">Due Later</option>
                                <option value="name_asc">A-Z</option>
                                <option value="name_desc">Z-A</option>
                            </select>
                            <i class="bi bi-chevron-down sort-arrow"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs-container">
            <div class="container-fluid">
                <div class="filter-tabs" role="tablist">
                    <button class="filter-tab active" data-filter="all" onclick="documentSystem.filterDocuments('all')" role="tab">
                        <span class="tab-label">All</span>
                        <span class="tab-count" id="allCount">0</span>
                    </button>
                    <button class="filter-tab" data-filter="submitted" onclick="documentSystem.filterDocuments('submitted')" role="tab">
                        <span class="tab-indicator pending"></span>
                        <span class="tab-label">Pending</span>
                        <span class="tab-count" id="submittedCount">0</span>
                    </button>
                    <button class="filter-tab" data-filter="in_review" onclick="documentSystem.filterDocuments('in_review')" role="tab">
                        <span class="tab-indicator review"></span>
                        <span class="tab-label">In Review</span>
                        <span class="tab-count" id="inReviewCount">0</span>
                    </button>
                    <button class="filter-tab" data-filter="approved" onclick="documentSystem.filterDocuments('approved')" role="tab">
                        <span class="tab-indicator approved"></span>
                        <span class="tab-label">Approved</span>
                        <span class="tab-count" id="approvedCount">0</span>
                    </button>
                    <button class="filter-tab" data-filter="rejected" onclick="documentSystem.filterDocuments('rejected')" role="tab">
                        <span class="tab-indicator rejected"></span>
                        <span class="tab-label">Rejected</span>
                        <span class="tab-count" id="rejectedCount">0</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Dashboard View -->
        <div id="dashboardView" class="documents-dashboard">
            <div class="container-fluid">
                <!-- Documents List Container -->
                <div class="documents-list" id="documentsContainer" role="list">
                    <!-- Documents will be populated here -->
                </div>

                <!-- Empty State -->
                <div class="empty-state" id="emptyState" style="display: none;">
                    <div class="empty-icon">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h3>No Documents Found</h3>
                    <p>There are no documents matching your current filter.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Detail View - OneUI Compact Design -->
    <div id="documentView" class="document-detail-view" style="display: none;">
        <!-- Compact Header -->
        <div class="detail-header">
            <div class="container-fluid">
                <div class="detail-header-content">
                    <div class="detail-header-left">
                        <button class="back-btn-compact" onclick="goBack()" title="Back to Documents">
                            <i class="bi bi-arrow-left"></i>
                        </button>
                        <div class="detail-title-group">
                            <h1 id="docTitle" class="detail-title">Document Title</h1>
                            <div class="detail-meta">
                                <span id="docStatus" class="detail-status-badge">Status</span>
                                <span class="detail-separator">•</span>
                                <span id="pdfFileName" class="detail-filename">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                    Document.pdf
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="detail-header-actions">
                        <button class="detail-action-btn" onclick="downloadPDF()" title="Download PDF">
                            <i class="bi bi-download"></i>
                            <span>Download</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="detail-content">
            <div class="container-fluid">
                <div class="detail-grid">
                    <!-- PDF Viewer Panel -->
                    <div class="pdf-panel">
                        <div class="pdf-viewer-card">
                            <!-- PDF Toolbar -->
                            <div class="pdf-toolbar-compact">
                                <div class="toolbar-left">
                                    <div class="page-nav">
                                        <button class="toolbar-btn" onclick="documentSystem.prevPage()" id="prevPageBtn" title="Previous">
                                            <i class="bi bi-chevron-left"></i>
                                        </button>
                                        <div class="page-indicator">
                                            <input type="number" id="pageInput" min="1" class="page-num-input"
                                                onchange="documentSystem.goToPage(this.value)" />
                                            <span class="page-divider">/</span>
                                            <span id="pageTotal" class="page-total-num">1</span>
                                        </div>
                                        <button class="toolbar-btn" onclick="documentSystem.nextPage()" id="nextPageBtn" title="Next">
                                            <i class="bi bi-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="toolbar-right">
                                    <div class="zoom-nav">
                                        <button class="toolbar-btn" onclick="documentSystem.zoomOut()" title="Zoom Out">
                                            <i class="bi bi-zoom-out"></i>
                                        </button>
                                        <span id="zoomIndicator" class="zoom-percent">100%</span>
                                        <button class="toolbar-btn" onclick="documentSystem.zoomIn()" title="Zoom In">
                                            <i class="bi bi-zoom-in"></i>
                                        </button>
                                        <span class="toolbar-divider"></span>
                                        <button class="toolbar-btn" onclick="documentSystem.fitToWidth()" title="Fit Width">
                                            <i class="bi bi-arrows-expand"></i>
                                        </button>
                                        <button class="toolbar-btn" onclick="documentSystem.resetZoom()" title="Reset">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- PDF Canvas -->
                            <div class="pdf-canvas-area" id="pdfContent">
                                <div id="pdfLoading" class="pdf-loader">
                                    <div class="loader-spinner"></div>
                                    <p class="loader-text">Loading document...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Panel -->
                    <div class="sidebar-panel">
                        <!-- Quick Actions -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <h3 class="panel-title">
                                    <i class="bi bi-lightning-charge-fill"></i>
                                    Actions
                                </h3>
                            </div>
                            <div class="panel-body">
                                <div class="action-stack">
                                    <button class="action-btn-full success" onclick="signDocument()">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>Sign & Approve</span>
                                    </button>
                                    <button class="action-btn-full danger" onclick="showRejectModal()">
                                        <i class="bi bi-x-circle-fill"></i>
                                        <span>Reject</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Signature Section -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <h3 class="panel-title">
                                    <i class="bi bi-pen-fill"></i>
                                    Signature
                                </h3>
                            </div>
                            <div class="panel-body">
                                <div class="signature-status-box" id="signatureStatusContainer">
                                    <div class="sig-status pending" id="signaturePlaceholder">
                                        <div class="sig-icon">
                                            <i class="bi bi-pen"></i>
                                        </div>
                                        <div class="sig-info">
                                            <span class="sig-label">Ready to Sign</span>
                                            <span class="sig-hint">Add your signature below</span>
                                        </div>
                                    </div>
                                    <div class="sig-status complete d-none" id="signedStatus">
                                        <div class="sig-icon success">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </div>
                                        <div class="sig-info">
                                            <span class="sig-label">Signed</span>
                                            <span class="sig-hint">Signature applied</span>
                                        </div>
                                    </div>
                                </div>

                                <button class="sig-toggle-btn" onclick="toggleSignaturePad()" id="signaturePadToggle">
                                    <i class="bi bi-pencil-square"></i>
                                    Open Signature Pad
                                </button>

                                <!-- Signature Pad (collapsed by default) -->
                                <div id="signaturePadContainer" class="sig-pad-container d-none">
                                    <div class="sig-pad-header">
                                        <span>Add Signature</span>
                                        <button class="sig-pad-close" onclick="toggleSignaturePad()">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="sig-upload-area">
                                        <label for="signatureUpload" class="sig-upload-label">
                                            <i class="bi bi-cloud-arrow-up"></i>
                                            <span>Upload Image</span>
                                        </label>
                                        <input type="file" id="signatureUpload" accept="image/*" class="sig-upload-input">
                                    </div>
                                    
                                    <div class="sig-divider"><span>or draw below</span></div>
                                    
                                    <div class="sig-canvas-container">
                                        <canvas id="signatureCanvas" class="sig-canvas"></canvas>
                                    </div>
                                    
                                    <div class="sig-pad-actions">
                                        <button type="button" class="sig-btn clear" id="sigClearBtn">
                                            <i class="bi bi-eraser"></i> Clear
                                        </button>
                                        <button type="button" class="sig-btn save" id="sigSaveBtn">
                                            <i class="bi bi-check2"></i> Apply
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes Section -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <h3 class="panel-title">
                                    <i class="bi bi-chat-left-text-fill"></i>
                                    Notes
                                </h3>
                            </div>
                            <div class="panel-body">
                                <textarea class="notes-textarea" id="notesInput" rows="3"
                                    placeholder="Add notes or comments..."></textarea>
                                <button class="notes-save-btn" onclick="documentSystem.saveNotes()">
                                    <i class="bi bi-check2"></i> Save
                                </button>
                            </div>
                        </div>

                        <!-- Workflow Progress -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <h3 class="panel-title">
                                    <i class="bi bi-diagram-3-fill"></i>
                                    Approval Flow
                                </h3>
                            </div>
                            <div class="panel-body">
                                <div id="workflowSteps" class="workflow-steps">
                                    <!-- Workflow steps populated by JS -->
                                </div>
                            </div>
                        </div>
                    </div>
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
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <div id="changePasswordMessages"></div>
                        <div class="mb-3">
                            <label for="currentPassword" class="form-label">Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="currentPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('currentPassword')">
                                    <i class="bi bi-eye" id="currentPasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="newPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('newPassword')">
                                    <i class="bi bi-eye" id="newPasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" class="form-control" id="confirmPassword" required>
                                <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('confirmPassword')">
                                    <i class="bi bi-eye" id="confirmPasswordIcon"></i>
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

    <!-- Profile Settings Modal -->
    <div class="modal fade" id="profileSettingsModal" tabindex="-1" aria-labelledby="profileSettingsLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileSettingsLabel">
                        <i class="bi bi-person-gear me-2"></i>Profile Settings
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="profileSettingsForm">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="modal-body">
                        <div id="profileSettingsMessages"></div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="profileFirstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="profileFirstName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="profileLastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="profileLastName" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="profileEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="profileEmail" required>
                        </div>
                        <div class="mb-3">
                            <label for="profilePhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="profilePhone">
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="darkModeToggle">
                                <label class="form-check-label" for="darkModeToggle">
                                    Enable Dark Mode
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Preferences Modal -->
    <div class="modal fade" id="preferencesModal" tabindex="-1" aria-labelledby="preferencesLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="preferencesLabel">
                        <i class="bi bi-sliders me-2"></i>Preferences
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="preferencesMessages"></div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="emailNotifications">
                            <label class="form-check-label" for="emailNotifications">
                                Email Notifications
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="browserNotifications">
                            <label class="form-check-label" for="browserNotifications">
                                Browser Notifications
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="defaultView" class="form-label">Default View</label>
                        <select class="form-select" id="defaultView">
                            <option value="month">Month View</option>
                            <option value="week">Week View</option>
                            <option value="agenda">Agenda View</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Help & Support Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpLabel">
                        <i class="bi bi-question-circle me-2"></i>Help & Support
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#gettingStarted">
                                    Getting Started
                                </button>
                            </h2>
                            <div id="gettingStarted" class="accordion-collapse collapse show"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Welcome to the Document Notifications system! Here you can:</p>
                                    <ul>
                                        <li>View documents requiring your approval or signature</li>
                                        <li>Track document status and workflow progress</li>
                                        <li>Sign documents electronically</li>
                                        <li>Communicate with other approvers</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#documentWorkflow">
                                    Document Workflow
                                </button>
                            </h2>
                            <div id="documentWorkflow" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Documents follow a sequential approval process:</p>
                                    <ol>
                                        <li><strong>Submitted:</strong> Document is uploaded and pending initial review
                                        </li>
                                        <li><strong>In Review:</strong> Document is being reviewed by assigned personnel
                                        </li>
                                        <li><strong>Approved:</strong> Document has been approved and signed</li>
                                        <li><strong>Rejected:</strong> Document was rejected (requires revision)</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#signingDocuments">
                                    Signing Documents
                                </button>
                            </h2>
                            <div id="signingDocuments" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>To sign a document:</p>
                                    <ol>
                                        <li>Open the document from your dashboard</li>
                                        <li>Click the signature placeholder area</li>
                                        <li>Draw your signature using the signature pad</li>
                                        <li>Click "Apply Signature" to place it on the document</li>
                                        <li>Click "Sign Document" to complete the approval</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#notifications">
                                    Notifications
                                </button>
                            </h2>
                            <div id="notifications" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>You will receive notifications for:</p>
                                    <ul>
                                        <li>New documents requiring your attention</li>
                                        <li>Document status changes</li>
                                        <li>Upcoming deadlines</li>
                                        <li>Messages from other approvers</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#troubleshooting">
                                    Troubleshooting
                                </button>
                            </h2>
                            <div id="troubleshooting" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p><strong>Common Issues:</strong></p>
                                    <ul>
                                        <li><strong>Document won't load:</strong> Check your internet connection and try
                                            refreshing</li>
                                        <li><strong>Signature not saving:</strong> Ensure you've drawn a complete
                                            signature</li>
                                        <li><strong>Notifications not showing:</strong> Check your browser notification
                                            settings</li>
                                        <li><strong>Access denied:</strong> Contact your administrator for permission
                                            issues</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notifications Modal - OneUI Enhanced -->
    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationsModalLabel">
                        <i class="bi bi-bell-fill"></i>
                        <span>Notifications</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="notificationsList">
                        <!-- Notifications will be populated here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="markAllAsRead()">
                        <i class="bi bi-check-all me-2"></i>Mark All Read
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Document Modal -->
    <div class="modal fade" id="rejectDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-x-circle me-2"></i>Reject Document
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="rejectDocumentForm" autocomplete="off">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejectReason" class="form-label">Reason for rejection</label>
                            <textarea class="form-control" id="rejectReason" rows="3" required
                                placeholder="Enter reason..."></textarea>
                        </div>
                        <div id="rejectError" class="text-danger small d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/global-notifications.js"></script>
    <script src="../assets/js/notifications.js"></script>

    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
        // Convert snake_case to camelCase for JavaScript
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['position'] = $currentUser['position'] ?? '';
        echo json_encode($jsUser);
        ?>;
    </script>

    <script>
        // Navbar functions
        function openProfileSettings() {
            // Populate form with current user data
            if (window.currentUser) {
                document.getElementById('profileFirstName').value = window.currentUser.firstName || '';
                document.getElementById('profileLastName').value = window.currentUser.lastName || '';
                document.getElementById('profileEmail').value = window.currentUser.email || '';
                document.getElementById('profilePhone').value = '';
                document.getElementById('darkModeToggle').checked = localStorage.getItem('darkMode') === 'true';
            }

            const modal = new bootstrap.Modal(document.getElementById('profileSettingsModal'));
            modal.show();
        }

        function openPreferences() {
            // Load preferences from localStorage
            const emailNotifications = localStorage.getItem('emailNotifications') !== 'false'; // default true
            const browserNotifications = localStorage.getItem('browserNotifications') !== 'false'; // default true
            const defaultView = localStorage.getItem('defaultView') || 'month';

            document.getElementById('emailNotifications').checked = emailNotifications;
            document.getElementById('browserNotifications').checked = browserNotifications;
            document.getElementById('defaultView').value = defaultView;

            const modal = new bootstrap.Modal(document.getElementById('preferencesModal'));
            modal.show();
        }

        function showHelp() {
            const modal = new bootstrap.Modal(document.getElementById('helpModal'));
            modal.show();
        }

        function savePreferences() {
            const emailNotifications = document.getElementById('emailNotifications').checked;
            const browserNotifications = document.getElementById('browserNotifications').checked;
            const defaultView = document.getElementById('defaultView').value;

            // Save to localStorage
            localStorage.setItem('emailNotifications', emailNotifications);
            localStorage.setItem('browserNotifications', browserNotifications);
            localStorage.setItem('defaultView', defaultView);

            // Show success message
            const messagesDiv = document.getElementById('preferencesMessages');
            if (messagesDiv) {
                messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Preferences saved successfully!</div>';
                setTimeout(() => messagesDiv.innerHTML = '', 3000);
            }

            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('preferencesModal')).hide();
        }

        function openChangePassword() {
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            modal.show();
        }

        // Document actions routed to the controller
        function filterDocuments(status) {
            if (window.documentSystem) {
                window.documentSystem.filterDocuments(status);
            }
        }

        function goBack() {
            document.getElementById('documentView').style.display = 'none';
            document.getElementById('dashboardView').style.display = 'block';
        }

        function signDocument() {
            if (window.documentSystem && window.documentSystem.currentDocument) {
                window.documentSystem.signDocument(window.documentSystem.currentDocument.id);
            } else if (window.ToastManager) {
                window.ToastManager.warning('Open a document first.', 'Notice');
            }
        }

        function applySignature() { toggleSignaturePad(); }

        function toggleSignaturePad() {
            const pad = document.getElementById('signaturePadContainer');
            if (!pad) return;
            pad.classList.toggle('d-none');
            if (!pad.classList.contains('d-none') && window.documentSystem) {
                window.documentSystem.initSignaturePad();
            }
        }

        function downloadPDF() {
            if (window.documentSystem && window.documentSystem.currentDocument) {
                // Create a temporary link to download the PDF
                const link = document.createElement('a');
                link.href = window.documentSystem.currentDocument.file_path;
                link.download = window.documentSystem.currentDocument.title + '.pdf';
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                if (window.ToastManager) {
                    window.ToastManager.success('PDF download started.', 'Download');
                }
            } else {
                if (window.ToastManager) {
                    window.ToastManager.warning('Please open a document first.', 'Notice');
                }
            }
        }

        // Reject modal functions
        function showRejectModal() {
            const modal = new bootstrap.Modal(document.getElementById('rejectDocumentModal'));
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectError').classList.add('d-none');
            modal.show();
        }

        // Handle reject form submit
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('rejectDocumentForm');
            if (form) {
                form.onsubmit = function (e) {
                    e.preventDefault();
                    const reason = document.getElementById('rejectReason').value.trim();
                    const errorDiv = document.getElementById('rejectError');
                    if (!reason) {
                        errorDiv.textContent = 'Please provide a reason for rejection.';
                        errorDiv.classList.remove('d-none');
                        return;
                    }
                    errorDiv.classList.add('d-none');
                    // Call the JS controller to reject
                    if (window.documentSystem && window.documentSystem.currentDocument) {
                        window.documentSystem.rejectDocument(window.documentSystem.currentDocument.id, reason);
                    }
                    // Hide modal after submit
                    bootstrap.Modal.getInstance(document.getElementById('rejectDocumentModal')).hide();
                };
            }
        });

        // Handle profile settings form
        document.addEventListener('DOMContentLoaded', function () {
            const profileForm = document.getElementById('profileSettingsForm');
            if (profileForm) {
                profileForm.addEventListener('submit', async function (e) {
                    e.preventDefault();

                    const firstName = document.getElementById('profileFirstName').value;
                    const lastName = document.getElementById('profileLastName').value;
                    const email = document.getElementById('profileEmail').value;
                    const phone = document.getElementById('profilePhone').value;
                    const darkMode = document.getElementById('darkModeToggle').checked;
                    const messagesDiv = document.getElementById('profileSettingsMessages');

                    if (!firstName || !lastName || !email) {
                        if (messagesDiv) messagesDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Please fill in all required fields.</div>';
                        return;
                    }

                    // Save dark mode preference
                    localStorage.setItem('darkMode', darkMode);

                    // Show success message
                    if (messagesDiv) messagesDiv.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Profile updated successfully!</div>';

                    // Apply theme
                    document.body.classList.toggle('dark-theme', darkMode);

                    // Close modal after a delay
                    setTimeout(() => {
                        bootstrap.Modal.getInstance(document.getElementById('profileSettingsModal')).hide();
                    }, 1500);
                });
            }
        });
    </script>
</body>

</html>