<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth();
requireRole(['employee']); // restrict to employees only

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
if (!$currentUser) {
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
                        <button class="action-button" onclick="markAllAsRead()" title="Mark All Read">
                            <i class="bi bi-check2-all"></i>
                            <span>Mark Read</span>
                        </button>
                        <!-- Create a mock document for testing -->
                        <button class="action-button" onclick="createMockDocument()" title="Create Mock Document">
                            <i class="bi bi-file-earmark-plus"></i>
                            <span>Create Mock</span>
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

    <!-- Document Detail View (not used by modal flow; kept for future use) -->
    <div id="documentView" class="document-detail" style="display: none;">
        <div class="document-controls">
            <div class="container-fluid">
                <div class="controls-actions">
                    <div class="action-controls">
                        <button class="btn btn-outline-secondary" onclick="goBack()" title="Back to Dashboard">
                            <i class="bi bi-arrow-left me-2"></i>Back
                        </button>
                        <div class="divider"></div>
                        <button class="btn btn-outline-primary" onclick="downloadPDF()">
                            <i class="bi bi-download me-2"></i>Download
                        </button>
                        <button class="btn btn-outline-secondary" onclick="printDocument()">
                            <i class="bi bi-printer me-2"></i>Print
                        </button>
                    </div>
                    <div class="preview-status">
                        <div class="status-indicator">
                            <span id="docStatus" class="status-badge">Status</span>
                            <div class="status-dot"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Container -->
        <div class="document-container">
            <div class="container-fluid">
                <div class="editor-wrapper">
                    <div class="editor-panel">
                        <div class="editor-content">
                            <div class="form-section">
                                <h5 class="mb-3">
                                    <i class="bi bi-file-text me-2"></i><span id="docTitle">Document Title</span>
                                </h5>
                                <div class="pdf-viewer-container">
                                    <div class="pdf-viewer-header">
                                        <div class="pdf-file-info">
                                            <div class="pdf-file-icon">
                                                <i class="bi bi-file-earmark-pdf-fill"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold" id="pdfFileName">Document.pdf</div>
                                                <small class="text-muted" id="pdfTitle">Document content preview</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pdf-content" id="pdfContent"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Compact Actions Panel -->
                    <div class="compact-actions-panel">
                        <div class="quick-actions-bar">
                            <button class="btn btn-success btn-sm" onclick="signDocument()" title="Sign Document">
                                <i class="bi bi-pen-fill me-1"></i>Sign
                            </button>
                            <button class="btn btn-outline-danger btn-sm" onclick="showRejectModal()" title="Reject Document">
                                <i class="bi bi-x-circle me-1"></i>Reject
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="toggleSignaturePad()"
                                id="applySignatureBtn" title="Draw/Apply Signature">
                                <i class="bi bi-person-check me-1"></i>Signature Pad
                            </button>
                        </div>

                        <div class="signature-status-compact">
                            <div class="signature-placeholder-compact" id="signaturePlaceholder">
                                <i class="bi bi-pen text-muted"></i>
                                <span class="text-muted fs-sm">Ready to sign</span>
                            </div>
                            <div class="signed-indicator-compact" id="appliedSignature" style="display: none;">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span class="text-success fs-sm fw-medium">Signature applied</span>
                            </div>
                        </div>

                        <!-- Inline signature pad (no modals) -->
                        <div id="signaturePadContainer" class="signature-pad d-none">
                            <canvas id="signatureCanvas"></canvas>
                            <div class="d-flex justify-content-end gap-2 mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="sigClearBtn">
                                    <i class="bi bi-eraser me-1"></i>Clear
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="sigSaveBtn">
                                    <i class="bi bi-check2 me-1"></i>Save
                                </button>
                            </div>
                            <div class="small text-muted mt-2">
                                Tip: Draw your signature. It will appear on the highlighted area.
                            </div>
                        </div>

                        <div class="notes-compact">
                            <textarea class="form-control form-control-sm" rows="2" placeholder="Add notes..."
                                id="notesInput"></textarea>
                        </div>

                        <div class="workflow-compact">
                            <div class="workflow-header-compact">
                                <i class="bi bi-diagram-3 text-muted me-1"></i>
                                <span class="text-muted fs-sm">Workflow</span>
                            </div>
                            <div id="workflowSteps" class="workflow-steps-compact"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/notifications.js"></script>

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
        function showNotifications() {
            const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
            const list = document.getElementById('notificationsList');
            if (list) {
                list.innerHTML = `
                    <div class="list-group">
                        <div class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Pending Documents</h6>
                                <small>Just now</small>
                            </div>
                            <p class="mb-1">You have documents awaiting your signature.</p>
                        </div>
                    </div>`;
            }
            modal.show();
        }

        function openProfileSettings() {
            if (window.ToastManager) window.ToastManager.info('Profile settings are not available yet.', 'Info');
        }

        function openChangePassword() {
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            modal.show();
        }

        function markAllAsRead() {
            const badge = document.getElementById('notificationCount');
            if (badge) {
                badge.textContent = '0';
                badge.style.display = 'none';
            }
            if (window.ToastManager) window.ToastManager.success('All notifications marked as read.', 'Done');
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