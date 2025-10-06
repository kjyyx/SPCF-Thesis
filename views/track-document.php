<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$currentUser = getCurrentUser();

if (!$currentUser) {
    logoutUser();
    header('Location: user-login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Document Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/track-document.css">
    <link rel="stylesheet" href="../assets/css/toast.css">

    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
        // Convert snake_case to camelCase for JavaScript
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
        echo json_encode($jsUser);
        ?>;
        window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;

         // Load notifications
    async function loadNotifications() {
        try {
            const response = await fetch('../api/notifications.php');
            const data = await response.json();
            if (data.success) {
                const badge = document.getElementById('notificationCount');
                if (badge) {
                    badge.textContent = data.unread_count;
                    badge.style.display = data.unread_count > 0 ? 'flex' : 'none';
                }
                window.notifications = data.notifications;
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }

    setInterval(loadNotifications, 300000);
    loadNotifications();
    
    </script>
</head>

<body class="with-fixed-navbar">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <div class="navbar-brand">
                <i class="bi bi-folder2-open me-2"></i>
                Sign-um | Document Tracker
            </div>

            <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <!-- User Info -->
                <div class="user-info me-3">
                    <i class="bi bi-person-circle me-2"></i>
                    <span
                        id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                    <span class="badge ms-2 <?php
                    echo ($currentUser['role'] === 'admin') ? 'bg-danger' :
                        (($currentUser['role'] === 'employee') ? 'bg-primary' : 'bg-success');
                    ?>" id="userRoleBadge">
                        <?php echo ucfirst($currentUser['role']); ?>
                    </span>
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
                        <i class="bi bi-gear me-1"></i>Menu
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="event-calendar.php"><i
                                    class="bi bi-calendar-event me-2"></i>Calendar</a></li>
                        <li><a class="dropdown-item" href="create-document.php"><i
                                    class="bi bi-file-text me-2"></i>Create Document</a></li>
                        <li><a class="dropdown-item" href="upload-publication.php"><i
                                    class="bi bi-file-earmark-check me-2"></i>Upload Publication</a></li>
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

    <!-- Admin-Style Compact Header -->
    <div class="calendar-header-compact">
        <div class="container-fluid">
            <div class="header-compact-content">
                <div class="header-left">
                    <div class="header-text">
                        <h1 class="admin-title">Document Tracker</h1>
                        <p class="admin-subtitle">Monitor your document signing progress and approval status</p>
                    </div>
                </div>
                
                <!-- Header Stats -->
                <div class="header-stats-compact">
                    <div class="stat-item-compact">
                        <span class="stat-number-compact" id="totalDocuments">0</span>
                        <span class="stat-label-compact">Total</span>
                    </div>
                    <div class="stat-item-compact">
                        <span class="stat-number-compact" id="pendingDocuments">0</span>
                        <span class="stat-label-compact">Pending</span>
                    </div>
                    <div class="stat-item-compact">
                        <span class="stat-number-compact" id="approvedDocuments">0</span>
                        <span class="stat-label-compact">Approved</span>
                    </div>
                    <div class="stat-item-compact">
                        <span class="stat-number-compact" id="inProgressDocuments">0</span>
                        <span class="stat-label-compact">In Progress</span>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Main Content -->
    <div class="main-content">
        <!-- Admin-Style Content Header -->
        <div class="admin-content">
            <div class="container-fluid">
                <div class="content-header">
                    <div class="header-actions">
                        <h3><i class="bi bi-folder2-open me-2"></i>Document Management</h3>
                        <div class="action-buttons">
                            <button class="btn btn-success btn-sm" onclick="window.location.href='create-document.php'">
                                <i class="bi bi-file-plus me-1"></i>New Document
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="refreshDocuments()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                            </button>
                        </div>
                    </div>
                    
                    <!-- Search and Filter Controls -->
                    <div class="search-controls">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control" id="searchInput" placeholder="Search documents, status, location...">
                                    <button class="btn btn-outline-secondary" type="button" id="clearSearch" title="Clear search">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="btn-group" role="group" aria-label="Status filter">
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterAll" checked>
                                    <label class="btn btn-outline-primary btn-sm" for="filterAll">All</label>
                                    
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterPending">
                                    <label class="btn btn-outline-primary btn-sm" for="filterPending">Pending</label>
                                    
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterInProgress">
                                    <label class="btn btn-outline-primary btn-sm" for="filterInProgress">In Progress</label>
                                    
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterCompleted">
                                    <label class="btn btn-outline-primary btn-sm" for="filterCompleted">Completed</label>
                                    
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterApproved">
                                    <label class="btn btn-outline-primary btn-sm" for="filterApproved">Approved</label>
                                    
                                    <input type="radio" class="btn-check" name="statusFilter" id="filterRejected">
                                    <label class="btn btn-outline-primary btn-sm" for="filterRejected">Rejected</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()" title="Clear all filters and search">
                                    <i class="bi bi-x-circle me-1"></i>Clear All
                                </button>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted fw-semibold" id="resultsCount">
                                        <i class="bi bi-info-circle me-1"></i>Loading documents...
                                    </small>
                                    <small class="text-muted" id="lastUpdated">
                                        <i class="bi bi-clock-history me-1"></i>Last updated: <span id="lastUpdatedTime">--</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admin-Style Content Body -->
                <div class="content-body">
                    <div class="table-container">
                        <table class="table table-hover data-table" id="documentsTable">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="title">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><i class="bi bi-file-earmark-text me-2"></i>Document</span>
                                            <i class="bi bi-chevron-expand"></i>
                                        </div>
                                    </th>
                                    <th class="sortable" data-sort="document_type">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><i class="bi bi-tag me-2"></i>Type</span>
                                            <i class="bi bi-chevron-expand"></i>
                                        </div>
                                    </th>
                                    <th class="sortable" data-sort="current_status">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><i class="bi bi-flag me-2"></i>Status</span>
                                            <i class="bi bi-chevron-expand"></i>
                                        </div>
                                    </th>
                                    <th class="sortable" data-sort="current_location">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><i class="bi bi-geo-alt me-2"></i>Location</span>
                                            <i class="bi bi-chevron-expand"></i>
                                        </div>
                                    </th>
                                    <th class="sortable" data-sort="updated_at">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span><i class="bi bi-clock me-2"></i>Updated</span>
                                            <i class="bi bi-chevron-expand"></i>
                                        </div>
                                    </th>
                                    <th><i class="bi bi-chat-left-text me-2"></i>Notes</th>
                                    <th><i class="bi bi-three-dots me-2"></i>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsList">
                                <!-- Documents will be populated here -->
                            </tbody>
                        </table>
                        
                        <!-- Loading State -->
                        <div id="loadingState" class="text-center py-5">
                            <div class="loading-animation mb-3">
                                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                            <h5 class="text-primary mb-2">Loading Documents</h5>
                            <p class="text-muted">Please wait while we fetch your documents...</p>
                            <div class="loading-dots">
                                <span></span><span></span><span></span>
                            </div>
                        </div>
                        
                        <!-- Empty State -->
                        <div id="emptyState" class="text-center py-5" style="display: none;">
                            <div class="empty-state-icon mb-4">
                                <i class="bi bi-folder2-open text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                            </div>
                            <h4 class="text-dark mb-3">No Documents Found</h4>
                            <p class="text-muted mb-4 lead">You haven't submitted any documents yet or no documents match your current search criteria.</p>
                            <div class="d-flex gap-2 justify-content-center flex-wrap">
                                <button class="btn btn-primary btn-lg" onclick="window.location.href='create-document.php'">
                                    <i class="bi bi-file-plus me-2"></i>Create Document
                                </button>
                                <button class="btn btn-outline-secondary btn-lg" onclick="clearFilters()">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Admin-Style Pagination -->
                    <div class="d-flex justify-content-between align-items-center mt-3" id="paginationContainer" style="display: none;">
                        <div>
                            <span class="text-muted" id="paginationInfo">Showing 1 to 10 of 0 documents</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm" id="itemsPerPage" style="width: auto;">
                                <option value="5">5 per page</option>
                                <option value="10" selected>10 per page</option>
                                <option value="25">25 per page</option>
                                <option value="50">50 per page</option>
                            </select>
                            <nav aria-label="Documents pagination">
                                <ul class="pagination pagination-sm mb-0" id="pagination">
                                    <!-- Pagination buttons will be generated here -->
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin-Style Document Details Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title" id="documentModalTitle">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>Document Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="documentModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <h6 class="mt-3 text-primary">Loading Details</h6>
                        <p class="text-muted">Please wait while we fetch the document information...</p>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                    <button type="button" class="btn btn-primary" id="printDocumentBtn" style="display: none;">
                        <i class="bi bi-printer me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/track-document.js"></script>
    <script>
        // Function to navigate to pending approvals (notifications.php)
        function openPendingApprovals() {
            window.location.href = 'notifications.php';
        }

        // Function to navigate to create document page
        function openCreateDocumentModal() {
            window.location.href = 'create-document.php';
        }

        // Function to navigate to upload pubmat page
        function openUploadPubmatModal() {
            window.location.href = 'upload-publication.php';
        }

        // Function to navigate to track documents page
        function openTrackDocumentsModal() {
            window.location.href = 'track-document.php';
        }

        // Function to show notifications
        function showNotifications() {
            const modal = new bootstrap.Modal(document.getElementById('notificationsModal'));
            const list = document.getElementById('notificationsList');
            if (list && window.notifications) {
                list.innerHTML = window.notifications.map(n => {
                    let icon = 'bi-bell'; // Default icon
                    if (n.type === 'pending_document' || n.type === 'new_document' || n.type === 'document_status') icon = 'bi-file-earmark-text';
                    else if (n.type === 'upcoming_event' || n.type === 'event_reminder') icon = 'bi-calendar-event';
                    else if (n.type === 'pending_material' || n.type === 'material_status') icon = 'bi-image';
                    else if (n.type === 'new_user') icon = 'bi-person-plus';
                    else if (n.type === 'security_alert') icon = 'bi-shield-exclamation';
                    else if (n.type === 'account') icon = 'bi-key';
                    else if (n.type === 'system') icon = 'bi-gear';

                    return `
                        <div class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><i class="bi ${icon} me-2"></i>${n.title}</h6>
                                <small>${new Date(n.timestamp).toLocaleDateString()}</small>
                            </div>
                            <p class="mb-1">${n.message}</p>
                        </div>
                    `;
                }).join('');
            } else {
                if (list) list.innerHTML = '<div class="list-group-item">No notifications available.</div>';
            }
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
    </script>

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
</body>

</html>