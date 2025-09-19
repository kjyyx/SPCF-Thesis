<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Notification System</title>
    <meta name="description" content="Modern document notification and digital signature system">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/notifications.css">
</head>

<body>
    <!-- Navigation Bar - Matching Calendar.html -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <div class="navbar-brand">
                <i class="bi bi-file-earmark-text me-2"></i>
                Document Notifications
            </div>

            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <!-- User Info -->
                <div class="user-info me-3">
                    <i class="bi bi-person-circle me-2"></i>
                    <span id="userDisplayName">John Smith</span>
                    <span class="badge ms-2" id="userRoleBadge">ADMIN</span>
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
                        <i class="bi bi-gear me-2"></i>Settings
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="openProfileSettings()"><i
                                    class="bi bi-person-gear me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="calendar.html"><i
                                    class="bi bi-calendar-event me-2"></i>Calendar</a></li>
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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Compact Notification Header -->
        <div class="notification-header-compact">
            <div class="container-fluid">
                <div class="header-compact-content">
                    <div class="header-left">
                        <!-- Notification Info Compact -->
                        <div class="notification-info-compact">
                            <div class="notification-badge-large">
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
                    <div class="notification-actions-buttons">
                        <button class="action-button" onclick="filterDocuments('urgent')" title="Show Urgent">
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

    <!-- Document Detail View -->
    <div id="documentView" class="document-detail" style="display: none;">
        <!-- Document Controls - Compact -->
        <div class="document-controls">
            <div class="container-fluid">
                <div class="controls-actions">
                    <div class="action-controls">
                        <button class="btn btn-outline-secondary" onclick="goBack()" title="Back to Dashboard">
                            <i class="bi bi-arrow-left me-2"></i>Back
                        </button>

                        <div class="divider"></div>

                        <!-- <button class="btn btn-success" onclick="signDocument()">
                            <i class="bi bi-pen me-2"></i>Sign
                        </button> -->
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
                    <!-- Document Panel -->
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
                                        <div class="pdf-controls">
                                            <button class="pdf-control-btn" onclick="zoomOut()" title="Zoom Out">
                                                <i class="bi bi-zoom-out"></i>
                                            </button>
                                            <span class="zoom-indicator" id="zoomLevel">100%</span>
                                            <button class="pdf-control-btn" onclick="zoomIn()" title="Zoom In">
                                                <i class="bi bi-zoom-in"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="pdf-content" id="pdfContent">
                                        <!-- PDF content will be loaded here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Compact Actions Panel -->
                    <div class="compact-actions-panel">
                        <!-- Quick Actions Bar -->
                        <div class="quick-actions-bar">
                            <button class="btn btn-success btn-sm" onclick="signDocument()" title="Sign Document">
                                <i class="bi bi-pen-fill me-1"></i>Sign
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="applySignature()"
                                id="applySignatureBtn" title="Apply Signature">
                                <i class="bi bi-person-check me-1"></i>Apply
                            </button>
                        </div>

                        <!-- Compact Signature Status -->
                        <div class="signature-status-compact">
                            <div class="signature-placeholder-compact" id="signaturePlaceholder">
                                <i class="bi bi-pen text-muted"></i>
                                <span class="text-muted fs-sm">Ready to sign</span>
                            </div>
                            <div class="signed-indicator-compact" id="appliedSignature" style="display: none;">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span class="text-success fs-sm fw-medium">Signed by John Smith</span>
                            </div>
                        </div>

                        <!-- Compact Notes -->
                        <div class="notes-compact">
                            <textarea class="form-control form-control-sm" rows="2" placeholder="Add notes..."
                                id="notesInput"></textarea>
                        </div>

                        <!-- Compact Workflow -->
                        <div class="workflow-compact">
                            <div class="workflow-header-compact">
                                <i class="bi bi-diagram-3 text-muted me-1"></i>
                                <span class="text-muted fs-sm">Workflow</span>
                            </div>
                            <div id="workflowSteps" class="workflow-steps-compact">
                                <!-- Workflow steps will be populated here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/notifications.js"></script>

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
                            <input type="password" class="form-control" id="currentPassword" required>
                        </div>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="newPassword" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirmPassword" required>
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
                    <h5 class="modal-title">
                        <i class="bi bi-bell me-2"></i>Notifications
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="notificationsList">
                        <!-- Notifications will be populated here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="markAllAsRead()">Mark All Read</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Navigation functions matching calendar.html
        function showNotifications() {
            const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
            modal.show();
        }

        function openProfileSettings() {
            console.log('Profile settings opened');
        }

        function openChangePassword() {
            const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            modal.show();
        }

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'user-login.php';
            }
        }

        function markAllAsRead() {
            document.getElementById('notificationCount').style.display = 'none';
            console.log('All notifications marked as read');
        }
    </script>
</body>

</html>