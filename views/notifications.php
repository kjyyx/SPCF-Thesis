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
const ALLOWED_STUDENT_POSITIONS = ['SSC President', 'CSC President'];

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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header-section">
            <div class="container-fluid">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <i class="bi bi-bell me-3"></i>
                        Document Notifications
                    </h1>
                    <p class="page-subtitle">Track document approvals, rejections, and signing status updates</p>
                </div>
            </div>
        </div>

        <!-- Compact Notification Header -->
        <div class="calendar-header-compact">
            <div class="container-fluid">
                <div class="header-compact-content">
                    <div class="header-left">
                        <!-- Notification Info Compact -->
                        <div class="document-info-compact">
                            <div class="document-badge">
                                <i class="bi bi-bell-fill me-2" aria-hidden="true"></i>
                                <span class="fw-bold">Document Notifications</span>
                                <span class="badge bg-danger ms-2" id="pendingCount"
                                    aria-label="Pending documents count">3</span>
                            </div>
                        </div>

                        <!-- Search Bar -->
                        <div class="search-container">
                            <div class="input-group">
                                <span class="input-group-text" id="search-icon">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                </span>
                                <input type="text" class="form-control" id="documentSearch"
                                    placeholder="Search documents..." aria-label="Search documents"
                                    aria-describedby="search-icon">
                                <button class="btn btn-outline-secondary" type="button" id="clearSearch"
                                    aria-label="Clear search">
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Sorting Options -->
                        <div class="sorting-container">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-sort-down" aria-hidden="true"></i>
                                </span>
                                <select class="form-select" id="sortSelect" aria-label="Sort documents">
                                    <option value="date_desc">Date (Newest First)</option>
                                    <option value="date_asc">Date (Oldest First)</option>
                                    <option value="due_desc">Due Date (Soonest First)</option>
                                    <option value="due_asc">Due Date (Latest First)</option>
                                    <option value="name_asc">Name (A-Z)</option>
                                    <option value="name_desc">Name (Z-A)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="header-stats-compact">
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-danger" id="urgentCount"
                                    aria-label="Urgent documents">1</div>
                                <div class="stat-label-compact">Urgent</div>
                            </div>
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-warning" id="highCount"
                                    aria-label="High priority documents">1</div>
                                <div class="stat-label-compact">High Priority</div>
                            </div>
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-info" id="normalCount"
                                    aria-label="Normal priority documents">1</div>
                                <div class="stat-label-compact">Normal</div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="document-actions-buttons">
                        <!-- 'urgent' maps to 'submitted' status in JS binding below -->
                        <button class="btn btn-outline-primary btn-sm"
                            onclick="documentSystem.filterDocuments('all')">All</button>
                        <button class="btn btn-outline-warning btn-sm"
                            onclick="documentSystem.filterDocuments('submitted')">Pending</button>
                        <button class="btn btn-outline-info btn-sm"
                            onclick="documentSystem.filterDocuments('in_review')">In Review</button>
                        <button class="btn btn-outline-success btn-sm"
                            onclick="documentSystem.filterDocuments('approved')">Done</button>
                        <button class="btn btn-outline-danger btn-sm"
                            onclick="documentSystem.filterDocuments('rejected')">Rejected</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard View -->
        <div id="dashboardView" class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="container">
                        <div class="row g-3" id="documentsContainer">
                            <!-- Documents will be populated here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modern Document Detail View -->
    <div id="documentView" class="document-detail" style="display: none;">
        <!-- Enhanced Header with Better Controls -->
        <div class="document-header">
            <div class="container-fluid">
                <div class="header-content">
                    <div class="header-left">
                        <button class="back-btn" onclick="goBack()" title="Back to Dashboard">
                            <i class="bi bi-arrow-left"></i>
                            <span>Back to Documents</span>
                        </button>
                        <div class="document-info">
                            <h2 id="docTitle" class="document-title">Document Title</h2>
                            <div class="document-meta">
                                <span id="docStatus" class="status-badge">Status</span>
                                <span class="meta-separator">•</span>
                                <span id="pdfFileName" class="file-name">Document.pdf</span>
                            </div>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button class="action-btn primary" onclick="downloadPDF()" title="Download PDF">
                            <i class="bi bi-download"></i>
                            <span>Download</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modern Layout Container -->
        <div class="document-layout">
            <div class="container-fluid">
                <div class="layout-grid">
                    <!-- Main PDF Viewer -->
                    <div class="pdf-section">
                        <div class="pdf-viewer-modern">
                            <!-- Enhanced PDF Controls -->
                            <div class="pdf-toolbar">
                                <div class="toolbar-group">
                                    <div class="page-controls">
                                        <button class="tool-btn" onclick="documentSystem.prevPage()" id="prevPageBtn"
                                            title="Previous Page" aria-label="Previous Page">
                                            <i class="bi bi-chevron-left"></i>
                                        </button>
                                        <div class="page-info">
                                            <input type="number" id="pageInput" min="1"
                                                onchange="documentSystem.goToPage(this.value)" class="page-input"
                                                title="Current Page" />
                                            <span class="page-separator">/</span>
                                            <span id="pageTotal" class="page-total">1</span>
                                        </div>
                                        <button class="tool-btn" onclick="documentSystem.nextPage()" id="nextPageBtn"
                                            title="Next Page" aria-label="Next Page">
                                            <i class="bi bi-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="toolbar-group">
                                    <div class="zoom-controls">
                                        <button class="tool-btn" onclick="documentSystem.zoomOut()" title="Zoom Out">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <div class="zoom-info">
                                            <span id="zoomIndicator" class="zoom-level">100%</span>
                                        </div>
                                        <button class="tool-btn" onclick="documentSystem.zoomIn()" title="Zoom In">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                        <div class="toolbar-separator"></div>
                                        <button class="tool-btn" onclick="documentSystem.fitToWidth()"
                                            title="Fit to Width">
                                            <i class="bi bi-arrows-angle-expand"></i>
                                        </button>
                                        <button class="tool-btn" onclick="documentSystem.resetZoom()"
                                            title="Reset Zoom">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- PDF Canvas Container -->
                            <div class="pdf-canvas-container" id="pdfContent">
                                <div id="pdfLoading" class="pdf-loading-modern">
                                    <div class="loading-spinner">
                                        <div class="spinner"></div>
                                    </div>
                                    <p class="loading-text">Loading document...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Sidebar -->
                    <div class="actions-sidebar">
                        <!-- Document Actions Card -->
                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-lightning-charge"></i>
                                    Quick Actions
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="action-buttons">
                                    <button class="action-btn-full success" onclick="signDocument()"
                                        title="Sign this document">
                                        <div class="btn-icon">
                                            <i class="bi bi-pen-fill"></i>
                                        </div>
                                        <div class="btn-content">
                                            <span class="btn-title">Sign Document</span>
                                            <span class="btn-subtitle">Apply your signature</span>
                                        </div>
                                        <i class="bi bi-chevron-right btn-arrow"></i>
                                    </button>

                                    <button class="action-btn-full danger" onclick="showRejectModal()"
                                        title="Reject this document">
                                        <div class="btn-icon">
                                            <i class="bi bi-x-circle"></i>
                                        </div>
                                        <div class="btn-content">
                                            <span class="btn-title">Reject Document</span>
                                            <span class="btn-subtitle">Provide rejection reason</span>
                                        </div>
                                        <i class="bi bi-chevron-right btn-arrow"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Signature Status Card -->
                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-pen"></i>
                                    Signature Status
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="signature-status" id="signatureStatusContainer">
                                    <div class="signature-placeholder" id="signaturePlaceholder">
                                        <div class="placeholder-icon">
                                            <i class="bi bi-pen"></i>
                                        </div>
                                        <div class="placeholder-text">
                                            <span class="status-title">Ready to Sign</span>
                                            <span class="status-subtitle">Click above to add your signature</span>
                                        </div>
                                    </div>

                                    <div class="signed-status d-none" id="signedStatus">
                                        <div class="status-icon success">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </div>
                                        <div class="status-text">
                                            <span class="status-title">Document Signed</span>
                                            <span class="status-subtitle">Signature applied successfully</span>
                                        </div>
                                    </div>
                                </div>

                                <button class="signature-pad-toggle" onclick="toggleSignaturePad()"
                                    id="signaturePadToggle">
                                    <i class="bi bi-pencil-square me-2"></i>
                                    Open Signature Pad
                                </button>

                                <!-- Enhanced Signature Pad -->
                                <div id="signaturePadContainer" class="signature-pad-modern d-none">
                                    <div class="signature-header">
                                        <span class="signature-title">Add Your Signature</span>
                                        <button class="close-signature" onclick="toggleSignaturePad()">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                    <div class="signature-upload-section">
                                        <label for="signatureUpload" class="upload-label">
                                            <i class="bi bi-upload me-2"></i>
                                            Upload Signature Image
                                        </label>
                                        <input type="file" id="signatureUpload" accept="image/*"
                                            class="signature-upload-input">
                                        <div class="upload-hint">
                                            <i class="bi bi-info-circle"></i>
                                            Upload a PNG, JPG, or GIF image of your signature
                                        </div>
                                    </div>
                                    <div class="signature-divider">
                                        <span>or</span>
                                    </div>
                                    <div class="signature-draw-section">
                                        <div class="draw-header">Draw Your Signature</div>
                                        <div class="signature-canvas-wrapper">
                                            <canvas id="signatureCanvas" class="signature-canvas"></canvas>
                                        </div>
                                    </div>
                                    <div class="signature-actions">
                                        <button type="button" class="btn-signature clear" id="sigClearBtn">
                                            <i class="bi bi-eraser"></i>
                                            Clear
                                        </button>
                                        <button type="button" class="btn-signature save" id="sigSaveBtn">
                                            <i class="bi bi-check2"></i>
                                            Use Signature
                                        </button>
                                    </div>
                                    <div class="signature-hint">
                                        <i class="bi bi-info-circle"></i>
                                        Upload an image or draw your signature using your mouse or touch device
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes Card -->
                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-journal-text"></i>
                                    Notes & Comments
                                </h3>
                            </div>
                            <div class="card-content">
                                <div class="notes-container">
                                    <textarea class="notes-input" id="notesInput" rows="4"
                                        placeholder="Add your notes or comments about this document..."></textarea>
                                    <div class="notes-actions">
                                        <button class="btn-notes save" onclick="documentSystem.saveNotes()">
                                            <i class="bi bi-check2"></i>
                                            Save Notes
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Workflow Progress Card -->
                        <div class="sidebar-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-diagram-3"></i>
                                    Approval Hierarchy
                                </h3>
                                <p class="card-subtitle">Document routing through organizational levels</p>
                            </div>
                            <div class="card-content">
                                <div id="workflowSteps" class="workflow-timeline">
                                    <!-- Workflow steps will be populated here -->
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