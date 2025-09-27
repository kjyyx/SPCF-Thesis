<?php
require_once '../includes/session.php';
require_once '../includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

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

    <!-- Page Header -->
    <div class="page-header-section">
        <div class="container-fluid">
            <div class="page-header-content">
                <h1 class="page-title">
                    <i class="bi bi-file-earmark-arrow-up me-3"></i>
                    Track Document Uploads
                </h1>
                <p class="page-subtitle text-muted">
                    Monitor the status of your submitted documents and stay updated on their progress.
                </p>
            </div>
        </div>
    </div>


    <!-- Main Content -->
    <div class="main-content">
        <!-- Tracker Controls -->
        <div class="tracker-controls">
            <div class="container-fluid">
                <div class="controls-container">
                    <div class="search-section">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search documents...">
                        </div>
                    </div>
                    <div class="filter-section">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="statusFilter" id="filterAll" autocomplete="off"
                                checked>
                            <label class="btn btn-outline-secondary btn-sm" for="filterAll">All</label>
                            <input type="radio" class="btn-check" name="statusFilter" id="filterPending"
                                autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="filterPending">Pending</label>
                            <input type="radio" class="btn-check" name="statusFilter" id="filterProgress"
                                autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="filterProgress">In Progress</label>
                            <input type="radio" class="btn-check" name="statusFilter" id="filterReview"
                                autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="filterReview">Under Review</label>
                            <input type="radio" class="btn-check" name="statusFilter" id="filterCompleted"
                                autocomplete="off">
                            <label class="btn btn-outline-secondary btn-sm" for="filterCompleted">Completed</label>
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
                        <table class="table table-hover tracker-table" id="documentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Document Name</th>
                                    <th>Status</th>
                                    <th>Current Location</th>
                                    <th>Last Updated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsList">
                                <!-- Sample documents - in real app, this would come from database -->
                                <tr>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-2 text-primary"></i>
                                        Budget Proposal 2024
                                    </td>
                                    <td><span class="badge bg-warning">In Progress</span></td>
                                    <td><span class="badge bg-info">Finance Office</span></td>
                                    <td>2024-01-15</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewDetails('doc-001')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-2 text-primary"></i>
                                        Event Request Form
                                    </td>
                                    <td><span class="badge bg-success">Completed</span></td>
                                    <td><span class="badge bg-success">Approved</span></td>
                                    <td>2024-01-10</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewDetails('doc-002')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-2 text-primary"></i>
                                        Facility Reservation
                                    </td>
                                    <td><span class="badge bg-secondary">Pending</span></td>
                                    <td><span class="badge bg-warning">Student Council</span></td>
                                    <td>2024-01-12</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewDetails('doc-003')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-2 text-primary"></i>
                                        Activity Report
                                    </td>
                                    <td><span class="badge bg-info">Under Review</span></td>
                                    <td><span class="badge bg-primary">Dean's Office</span></td>
                                    <td>2024-01-08</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewDetails('doc-004')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Details Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalTitle">Document Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="documentModalBody">
                    <!-- Document details will be loaded here -->
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