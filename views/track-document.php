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

    <!-- Modern Page Header -->
    <div class="modern-page-header">
        <div class="container-fluid">
            <div class="header-glass-card">
                <div class="header-content">
                    <div class="header-info-section">
                        <div class="page-title-group">
                            <div class="title-icon-wrapper">
                                <i class="bi bi-folder2-open"></i>
                            </div>
                            <div class="title-text">
                                <h1 class="page-title">Document Tracker</h1>
                                <p class="page-subtitle">
                                    Monitor submissions and track approval progress in real-time
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="header-stats-section">
                        <div class="stats-grid">
                            <div class="stat-card primary">
                                <div class="stat-icon">
                                    <i class="bi bi-files"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number" id="totalDocuments">0</div>
                                    <div class="stat-label">Total</div>
                                </div>
                            </div>
                            <div class="stat-card warning">
                                <div class="stat-icon">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number" id="pendingDocuments">0</div>
                                    <div class="stat-label">Pending</div>
                                </div>
                            </div>
                            <div class="stat-card success">
                                <div class="stat-icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number" id="approvedDocuments">0</div>
                                    <div class="stat-label">Approved</div>
                                </div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-icon">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-number" id="inProgressDocuments">0</div>
                                    <div class="stat-label">In Progress</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Main Content -->
    <div class="main-content">
        <!-- Modern Tracker Controls -->
        <div class="modern-controls-section">
            <div class="container-fluid">
                <div class="controls-glass-card">
                    <div class="controls-layout">
                        <div class="search-area">
                            <div class="search-input-wrapper">
                                <div class="search-icon">
                                    <i class="bi bi-search"></i>
                                </div>
                                <input type="text" class="modern-search-input" id="searchInput" placeholder="Search documents, status, or location...">
                                <button class="search-clear-btn" type="button" id="clearSearch">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                        <div class="filter-area">
                            <div class="filter-chips-container">
                                <label class="filter-chips-label">Filter by Status:</label>
                                <div class="filter-chips">
                                    <input type="radio" class="chip-input" name="statusFilter" id="filterAll" autocomplete="off" checked>
                                    <label class="filter-chip active" for="filterAll">
                                        <i class="bi bi-grid"></i>
                                        <span>All</span>
                                    </label>
                                    <input type="radio" class="chip-input" name="statusFilter" id="filterPending" autocomplete="off">
                                    <label class="filter-chip" for="filterPending">
                                        <i class="bi bi-clock"></i>
                                        <span>Pending</span>
                                    </label>
                                    <input type="radio" class="chip-input" name="statusFilter" id="filterProgress" autocomplete="off">
                                    <label class="filter-chip" for="filterProgress">
                                        <i class="bi bi-arrow-clockwise"></i>
                                        <span>In Progress</span>
                                    </label>
                                    <input type="radio" class="chip-input" name="statusFilter" id="filterReview" autocomplete="off">
                                    <label class="filter-chip" for="filterReview">
                                        <i class="bi bi-eye"></i>
                                        <span>Under Review</span>
                                    </label>
                                    <input type="radio" class="chip-input" name="statusFilter" id="filterCompleted" autocomplete="off">
                                    <label class="filter-chip" for="filterCompleted">
                                        <i class="bi bi-check-circle"></i>
                                        <span>Completed</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="actions-area">
                            <button class="modern-action-btn primary" onclick="window.location.href='create-document.php'">
                                <i class="bi bi-plus-circle"></i>
                                <span>New Document</span>
                            </button>
                            <button class="modern-action-btn secondary" onclick="refreshDocuments()">
                                <i class="bi bi-arrow-clockwise"></i>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>
                    <div class="controls-footer">
                        <div class="results-summary">
                            <i class="bi bi-info-circle"></i>
                            <span id="resultsCount">Loading documents...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tracker Container -->
        <div class="tracker-container">
            <div class="container-fluid">
                <div class="tracker-grid">
                    <div class="tracker-header">
                        <h3><i class="bi bi-folder2-open me-2"></i>Document Signing Tracker</h3>
                    </div>
                    <div class="tracker-table-container">
                        <div class="table-wrapper">
                            <table class="table table-hover tracker-table" id="documentsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th class="sortable" data-sort="document_name">
                                            <div class="th-content">
                                                <i class="bi bi-file-earmark-text me-2"></i>
                                                Document Name
                                                <i class="bi bi-chevron-expand sort-icon"></i>
                                            </div>
                                        </th>
                                        <th class="sortable" data-sort="status">
                                            <div class="th-content">
                                                Status
                                                <i class="bi bi-chevron-expand sort-icon"></i>
                                            </div>
                                        </th>
                                        <th class="sortable" data-sort="current_location">
                                            <div class="th-content">
                                                Current Location
                                                <i class="bi bi-chevron-expand sort-icon"></i>
                                            </div>
                                        </th>
                                        <th class="sortable" data-sort="updated_at">
                                            <div class="th-content">
                                                Last Updated
                                                <i class="bi bi-chevron-expand sort-icon"></i>
                                            </div>
                                        </th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="documentsList">
                                    <!-- Documents will be loaded here dynamically -->
                                </tbody>
                            </table>
                            
                            <!-- Loading State -->
                            <div id="loadingState" class="loading-state">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-3">Loading your documents...</p>
                            </div>
                            
                            <!-- Empty State -->
                            <div id="emptyState" class="empty-state" style="display: none;">
                                <i class="bi bi-folder2-open empty-icon"></i>
                                <h4>No Documents Found</h4>
                                <p>You haven't submitted any documents yet.</p>
                                <button class="btn btn-primary" onclick="window.location.href='create-document.php'">
                                    <i class="bi bi-plus-circle me-2"></i>Create Your First Document
                                </button>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <div class="pagination-container" id="paginationContainer" style="display: none;">
                            <div class="pagination-info">
                                <span id="paginationInfo">Showing 1-10 of 25 documents</span>
                            </div>
                            <nav aria-label="Documents pagination">
                                <ul class="pagination" id="pagination">
                                    <!-- Pagination items will be generated here -->
                                </ul>
                            </nav>
                            <div class="pagination-controls">
                                <select class="form-select form-select-sm" id="itemsPerPage">
                                    <option value="10">10 per page</option>
                                    <option value="25">25 per page</option>
                                    <option value="50">50 per page</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Document Details Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content modern-modal">
                <div class="modern-modal-header">
                    <div class="modal-header-content">
                        <div class="modal-icon">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <div class="modal-title-section">
                            <h4 class="modal-title" id="documentModalTitle">Document Details</h4>
                            <p class="modal-subtitle">Complete document information and history</p>
                        </div>
                    </div>
                    <button type="button" class="modern-close-btn" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="modern-modal-body" id="documentModalBody">
                    <div class="modal-loading">
                        <div class="loading-spinner">
                            <div class="spinner-ring"></div>
                        </div>
                        <p>Loading document details...</p>
                    </div>
                </div>
                <div class="modern-modal-footer">
                    <div class="modal-actions">
                        <button type="button" class="modern-action-btn secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i>
                            <span>Close</span>
                        </button>
                    </div>
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
            // Placeholder for notifications modal
            console.log('Show notifications');
        }
    </script>
</body>

</html>