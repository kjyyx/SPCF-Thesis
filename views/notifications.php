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

// Allow employees and SSC President students only
if ($currentUser['role'] === 'employee' || ($currentUser['role'] === 'student' && $currentUser['position'] === 'SSC President')) {
    // Access granted
} else {
    header('Location: user-login.php?error=access_denied');
    exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    global $currentUser;
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['id'],
            $currentUser['role'],
            $currentUser['first_name'] . ' ' . $currentUser['last_name'],
            $action,
            $category,
            $details,
            $targetId,
            $targetType,
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null,
            null, // Set user_agent to null to avoid storing PII
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
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
    <!-- Global shared styles + page-specific overrides -->
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/event-calendar.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/toast.css">

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
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <div class="navbar-brand">
                <i class="bi bi-file-earmark-text me-2"></i>
                Sign-um | Document Notifications
            </div>

            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <!-- User Info -->
                <div class="user-info me-3">
                    <i class="bi bi-person-circle me-2"></i>
                    <span id="userDisplayName">Loading...</span>
                    <span class="badge ms-2" id="userRoleBadge">USER</span>
                </div>

                <!-- Notifications -->
                <div class="notification-bell me-3" onclick="showNotifications()">
                    <i class="bi bi-bell"></i>
                    <span class="notification-badge" id="notificationCount">0</span>
                </div>

                <!-- Settings Dropdown -->
                <div class="dropdown me-3">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
                        data-bs-toggle="dropdown">
                        <i class="bi bi-gear me-2"></i>Settings
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="openProfileSettings()"><i
                                    class="bi bi-person-gear me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="#" onclick="openChangePassword()"><i
                                    class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><a class="dropdown-item" href="event-calendar.php"><i
                                    class="bi bi-calendar-event me-2"></i>Calendar</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item text-danger" href="user-logout.php"><i
                                    class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

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
                                <i class="bi bi-bell-fill me-2"></i>
                                <span class="fw-bold">Document Notifications</span>
                                <span class="badge bg-danger ms-2" id="pendingCount">3</span>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="header-stats-compact">
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-danger" id="urgentCount">1</div>
                                <div class="stat-label-compact">Urgent</div>
                            </div>
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-warning" id="highCount">1</div>
                                <div class="stat-label-compact">High Priority</div>
                            </div>
                            <div class="stat-item-compact">
                                <div class="stat-number-compact text-info" id="normalCount">1</div>
                                <div class="stat-label-compact">Normal</div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="document-actions-buttons">
                        <!-- 'urgent' maps to 'submitted' status in JS binding below -->
                        <button class="action-button" onclick="filterDocuments('submitted')" title="Show Urgent">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span>Urgent</span>
                        </button>
                        <button class="action-button" onclick="filterDocuments('all')" title="Show All">
                            <i class="bi bi-list-ul"></i>
                            <span>All</span>
                        </button>
                        <button class="action-button" onclick="filterDocuments('approved')" title="Show Done">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Done</span>
                        </button>
                        <button class="action-button" onclick="filterDocuments('rejected')" title="Show Rejected">
                            <i class="bi bi-x-circle-fill"></i>
                            <span>Rejected</span>
                        </button>
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
                        <button class="action-btn secondary" onclick="printDocument()" title="Print Document">
                            <i class="bi bi-printer"></i>
                            <span>Print</span>
                        </button>
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
                                                   onchange="documentSystem.goToPage(this.value)" 
                                                   class="page-input" title="Current Page" />
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
                                        <button class="tool-btn" onclick="documentSystem.fitToWidth()" title="Fit to Width">
                                            <i class="bi bi-arrows-angle-expand"></i>
                                        </button>
                                        <button class="tool-btn" onclick="documentSystem.resetZoom()" title="Reset Zoom">
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
                                        <input type="file" id="signatureUpload" accept="image/*" class="signature-upload-input">
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

    <!-- Notifications Modal -->
    <div class="modal fade" id="notificationsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Notifications</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="notificationsList"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="markAllAsRead()">Mark All Read</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
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
                            <textarea class="form-control" id="rejectReason" rows="3" required placeholder="Enter reason..."></textarea>
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
        // Populate user info on load
        document.addEventListener('DOMContentLoaded', function () {
            if (window.currentUser) {
                const name = `${window.currentUser.firstName} ${window.currentUser.lastName}`;
                const badgeCls = window.currentUser.role === 'admin' ? 'bg-danger'
                    : (window.currentUser.role === 'employee' ? 'bg-primary' : 'bg-success');
                document.getElementById('userDisplayName').textContent = name;
                document.getElementById('userRoleBadge').textContent = window.currentUser.role.toUpperCase();
                document.getElementById('userRoleBadge').className = `badge ms-2 ${badgeCls}`;
            }
        });

        // Navbar + actions bindings to controller
        // REMOVE: Conflicting showNotifications function (handled by global-notifications.js)
        // function showNotifications() { ... }

        function openProfileSettings() {
            if (window.ToastManager) window.ToastManager.info('Profile settings are not available yet.', 'Info');
        }

        function openChangePassword() {
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            modal.show();
        }

        // REMOVE: Conflicting markAllAsRead function (handled by global-notifications.js)
        // function markAllAsRead() { ... }

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
            if (window.ToastManager) window.ToastManager.info('Downloading not wired yet.', 'Info');
        }

        function printDocument() {
            window.print();
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
    </script>
</body>

</html>