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

// Set page title for navbar
$pageTitle = 'Track Documents';
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

        // REMOVE: Duplicate loadNotifications function and interval (handled by global-notifications.js)
    </script>

    <!-- ADD: Include global notifications module -->
    <script src="../assets/js/global-notifications.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>

<body class="with-fixed-navbar">
    <?php include '../includes/navbar.php'; ?>

    <!-- Admin-Style Compact Header -->
    <div class="calendar-header-compact">
        <div class="container-fluid">
            <div class="header-compact-content">
                <div class="header-left">
                    <div class="header-text">
                        <h1 class="admin-title">
                            <i class="bi bi-file-earmark-text me-2" style="color: #3b82f6;"></i>
                            Document Tracker
                        </h1>
                        <p class="admin-subtitle">Monitor your document signing progress and approval status in real-time</p>
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
                        <h3>
                            <i class="bi bi-folder2-open"></i>
                            Document Management
                        </h3>
                        <div class="action-buttons">
                            <button class="btn btn-success" onclick="window.location.href='create-document.php'">
                                <i class="bi bi-file-plus me-2"></i>New Document
                            </button>
                            <button class="btn btn-primary" onclick="refreshDocuments()">
                                <i class="bi bi-arrow-clockwise me-2"></i>Refresh
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
                            <h5 class="text-primary mb-2 fw-bold">Loading Documents</h5>
                            <p class="text-muted">Please wait while we fetch your documents...</p>
                        </div>
                        
                        <!-- Empty State -->
                        <div id="emptyState" class="text-center py-5" style="display: none;">
                            <div class="empty-state-icon mb-4">
                                <i class="bi bi-inbox" style="font-size: 5rem; color: #cbd5e1;"></i>
                            </div>
                            <h4 class="text-dark mb-3 fw-bold">No Documents Found</h4>
                            <p class="text-muted mb-4">You haven't submitted any documents yet or no documents match your current search criteria.</p>
                            <div class="d-flex gap-3 justify-content-center flex-wrap">
                                <button class="btn btn-primary px-4" onclick="window.location.href='create-document.php'">
                                    <i class="bi bi-file-plus me-2"></i>Create Document
                                </button>
                                <button class="btn btn-outline-secondary px-4" onclick="clearFilters()">
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
    </script>

    <!-- Profile Settings Modal -->
    <div class="modal fade" id="profileSettingsModal" tabindex="-1" aria-labelledby="profileSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileSettingsModalLabel"><i class="bi bi-person-gear me-2"></i>Profile Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="profileSettingsForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="profileFirstName" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="profileFirstName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="profileLastName" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="profileLastName" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="profileEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="profileEmail" required>
                        </div>
                        <div class="mb-3">
                            <label for="profilePhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="profilePhone">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveProfileSettings()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Preferences Modal -->
    <div class="modal fade" id="preferencesModal" tabindex="-1" aria-labelledby="preferencesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="preferencesModalLabel"><i class="bi bi-sliders me-2"></i>Preferences</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="preferencesForm">
                        <h6 class="mb-3">Document Tracking Preferences</h6>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                                <label class="form-check-label" for="autoRefresh">
                                    Auto-refresh document list every 30 seconds
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                <label class="form-check-label" for="emailNotifications">
                                    Email notifications for document status changes
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showRejectedNotes" checked>
                                <label class="form-check-label" for="showRejectedNotes">
                                    Show rejection notes in document details
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="itemsPerPagePref" class="form-label">Documents per page</label>
                            <select class="form-select" id="itemsPerPagePref">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                        <h6 class="mb-3 mt-4">Display Preferences</h6>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="compactView" checked>
                                <label class="form-check-label" for="compactView">
                                    Use compact view for document list
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="showStats" checked>
                                <label class="form-check-label" for="showStats">
                                    Show document statistics in header
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpModalLabel"><i class="bi bi-question-circle me-2"></i>Help & Support - Document Tracker</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#gettingStarted">
                                    Getting Started with Document Tracking
                                </button>
                            </h2>
                            <div id="gettingStarted" class="accordion-collapse collapse show" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>The Document Tracker allows you to monitor the progress of documents you've submitted for approval and signing.</p>
                                    <ul>
                                        <li><strong>Document Status:</strong> Track whether your documents are pending, in progress, approved, or rejected</li>
                                        <li><strong>Real-time Updates:</strong> The system automatically refreshes to show the latest status changes</li>
                                        <li><strong>Detailed View:</strong> Click "View Details" to see the complete approval timeline and notes</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#understandingStatus">
                                    Understanding Document Status
                                </button>
                            </h2>
                            <div id="understandingStatus" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <h6>Status Types:</h6>
                                    <ul>
                                        <li><span class="badge bg-warning">Pending</span> - Document submitted, waiting for initial review</li>
                                        <li><span class="badge bg-info">In Progress</span> - Document is being reviewed by approvers</li>
                                        <li><span class="badge bg-success">Approved</span> - Document has been fully approved</li>
                                        <li><span class="badge bg-danger">Rejected</span> - Document was rejected (check notes for reason)</li>
                                    </ul>
                                    <p><strong>Note:</strong> Documents move through multiple approval levels. Check the timeline in document details for progress.</p>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#usingFilters">
                                    Using Search and Filters
                                </button>
                            </h2>
                            <div id="usingFilters" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Use the search and filter options to find specific documents:</p>
                                    <ul>
                                        <li><strong>Search:</strong> Enter keywords to search document names, types, or locations</li>
                                        <li><strong>Status Filter:</strong> Filter by document status (All, Pending, In Progress, Completed)</li>
                                        <li><strong>Clear Filters:</strong> Use the "Clear All" button to reset search and filters</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#documentDetails">
                                    Viewing Document Details
                                </button>
                            </h2>
                            <div id="documentDetails" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Click "View Details" on any document to see:</p>
                                    <ul>
                                        <li>Complete approval timeline with dates and approver names</li>
                                        <li>Rejection notes (if applicable)</li>
                                        <li>Document type and submission information</li>
                                        <li>Download link for the signed document (when approved)</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#troubleshooting">
                                    Troubleshooting
                                </button>
                            </h2>
                            <div id="troubleshooting" class="accordion-collapse collapse" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <h6>Common Issues:</h6>
                                    <ul>
                                        <li><strong>Document not showing:</strong> Try refreshing the page or clearing filters</li>
                                        <li><strong>Status not updating:</strong> The system updates automatically, but you can manually refresh</li>
                                        <li><strong>Cannot download:</strong> Documents can only be downloaded after full approval</li>
                                    </ul>
                                    <p>If you continue to experience issues, contact your system administrator.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="create-document.php" class="btn btn-primary">Create New Document</a>
                </div>
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
</body>

</html>