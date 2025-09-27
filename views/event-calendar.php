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
addAuditLog('EVENT_CALENDAR_VIEWED', 'Event Management', 'Viewed event calendar', $currentUser['id'], 'User', 'INFO');

// Debug: Log user data
error_log("DEBUG event-calendar.php: Current user data: " . json_encode($currentUser));
error_log("DEBUG event-calendar.php: Session data: " . json_encode($_SESSION));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../assets/images/sign-um-favicon.jpg">
    <title>Sign-um - Event Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css"><!-- Global shared UI styles -->
    <link rel="stylesheet" href="../assets/css/event-calendar.css"><!-- Calendar-specific styles -->
    <link rel="stylesheet" href="../assets/css/toast.css">

    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
        // Convert snake_case to camelCase for JavaScript
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int)$currentUser['must_change_password'] : ((int)($_SESSION['must_change_password'] ?? 0));
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
                <i class="bi bi-calendar-event me-2"></i>
                Sign-um | University Calendar
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
                        <?php echo strtoupper($currentUser['role']); ?>
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
                        <i class="bi bi-gear me-2"></i>Settings
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="openProfileSettings()"><i
                                    class="bi bi-person-gear me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="#" onclick="openChangePassword()"><i
                                    class="bi bi-key me-2"></i>Change Password</a></li>
                        <?php if ($currentUser['role'] === 'admin'): ?>
                            <li><a class="dropdown-item" href="admin-dashboard.php"><i
                                        class="bi bi-shield-check me-2"></i>Admin Dashboard</a></li>
                        <?php endif; ?>
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
        <?php if (isset($currentUser['must_change_password']) && (int)$currentUser['must_change_password'] === 1): ?>
            <div class="container-fluid">
                <div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Your account is using a temporary password. For your security, please change your password now.
                    <button type="button" class="btn btn-sm btn-warning ms-2" onclick="openChangePassword()">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        <?php endif; ?>
        <!-- Combined Compact Header -->
        <div class="calendar-header-compact">
            <div class="container-fluid">
                <div class="header-compact-content">
                    <!-- Left Side - Student Info and Employee Info -->
                    <div class="header-left">
                        <!-- Student Info Redesigned -->
                        <?php if ($currentUser['role'] === 'student'): ?>
                            <div class="student-info-compact" id="studentInfoCompact">
                                <div class="student-badge">
                                    <i class="bi bi-mortarboard me-2"></i>
                                    <span>Student Dashboard</span>
                                </div>
                                <div class="student-actions-buttons">
                                    <button class="action-compact-btn" onclick="openCreateDocumentModal()"
                                        title="Create Document">
                                        <i class="bi bi-file-plus"></i>
                                        <span>Create</span>
                                    </button>
                                    <button class="action-compact-btn" onclick="openUploadPubmatModal()"
                                        title="Submit Pubmat">
                                        <i class="bi bi-image"></i>
                                        <span>Pubmat</span>
                                    </button>
                                    <button class="action-compact-btn" onclick="openTrackDocumentsModal()"
                                        title="Track Documents">
                                        <i class="bi bi-search"></i>
                                        <span>Track</span>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Employee Info with Pending Approvals -->
                        <?php if ($currentUser['role'] === 'employee'): ?>
                            <div class="employee-info-compact" id="employeeInfoCompact">
                                <div class="employee-badge">
                                    <i class="bi bi-person-badge me-2"></i>
                                    <span>Employee Dashboard</span>
                                </div>
                                <div class="employee-actions-buttons">
                                    <button class="action-compact-btn" onclick="openPendingApprovals()"
                                        title="View Pending Approvals">
                                        <i class="bi bi-clipboard-check"></i>
                                        <span>Approvals</span>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Side - Compact Stats -->
                    <div class="header-stats-compact">
                        <div class="stat-item-compact">
                            <span class="stat-number-compact" id="totalEvents">0</span>
                            <span class="stat-label-compact">Total</span>
                        </div>
                        <div class="stat-item-compact">
                            <span class="stat-number-compact" id="upcomingEvents">0</span>
                            <span class="stat-label-compact">Upcoming</span>
                        </div>
                        <div class="stat-item-compact">
                            <span class="stat-number-compact" id="todayEvents">0</span>
                            <span class="stat-label-compact">Today</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Page Header -->
        <div class="page-header-section">
            <div class="container-fluid">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <i class="bi bi-calendar-event me-3"></i>
                        Document Calendar & Events
                    </h1>
                    <p class="page-subtitle">Track document deadlines, signing events, and important dates</p>
                </div>
            </div>
        </div>

        <!-- Calendar Controls -->
        <div class="calendar-controls">
            <div class="container-fluid">
                <div class="controls-container">
                    <div class="navigation-controls">
                        <button class="btn btn-outline-primary" id="prevMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="current-month">
                            <h3 id="currentMonth">Loading...</h3>
                        </div>
                        <button class="btn btn-outline-primary" id="nextMonth">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <button class="btn btn-outline-primary" id="todayBtn">
                            <i class="bi bi-calendar-check me-1"></i>Today
                        </button>
                    </div>

                    <div class="view-controls">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-secondary active view-btn" data-view="month">
                                <i class="bi bi-calendar-month me-1"></i>Month
                            </button>
                            <button type="button" class="btn btn-outline-secondary view-btn" data-view="week">
                                <i class="bi bi-calendar-week me-1"></i>Week
                            </button>
                            <button type="button" class="btn btn-outline-secondary view-btn" data-view="list">
                                <i class="bi bi-list me-1"></i>List
                            </button>
                        </div>
                        <?php if ($currentUser['role'] !== 'student'): ?>
                            <button type="button" class="btn btn-primary ms-3" id="addEventBtn">
                                <i class="bi bi-plus-circle me-1"></i>Add Event
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Grid -->
        <div class="calendar-container">
            <div class="container-fluid">
                <!-- Month View -->
                <div id="monthView" class="calendar-view active">
                    <div class="calendar-grid">
                        <!-- Calendar Header -->
                        <div class="calendar-header-row">
                            <div class="calendar-day-header">Sun</div>
                            <div class="calendar-day-header">Mon</div>
                            <div class="calendar-day-header">Tue</div>
                            <div class="calendar-day-header">Wed</div>
                            <div class="calendar-day-header">Thu</div>
                            <div class="calendar-day-header">Fri</div>
                            <div class="calendar-day-header">Sat</div>
                        </div>
                        <!-- Calendar Days -->
                        <div id="calendarDays" class="calendar-days">
                            <!-- Days will be populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Week View -->
                <div id="weekView" class="calendar-view">
                    <div class="week-grid">
                        <div class="week-header">
                            <div class="time-column">Time</div>
                            <div class="week-days" id="weekDaysHeader">
                                <!-- Week days will be populated -->
                            </div>
                        </div>
                        <div class="week-body" id="weekBody">
                            <!-- Week time slots will be populated -->
                        </div>
                    </div>
                </div>

                <!-- List View -->
                <div id="listView" class="calendar-view">
                    <div class="events-list" id="eventsList">
                        <!-- Events list will be populated -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalLabel">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="eventForm">
                        <div class="mb-3">
                            <label for="eventTitle" class="form-label">Event Title</label>
                            <input type="text" class="form-control" id="eventTitle" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="eventDate" class="form-label">Date</label>
                                <input type="date" class="form-control" id="eventDate" required>
                            </div>
                            <div class="col-md-6">
                                <label for="eventTime" class="form-label">Time</label>
                                <input type="time" class="form-control" id="eventTime">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="eventDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="eventDescription" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="eventDepartment" class="form-label">Department/College</label>
                            <select class="form-control" id="eventDepartment" required>
                                <option value="">Select Department</option>
                                <option value="College of Arts, Sciences and Education">College of Arts, Sciences and
                                    Education</option>
                                <option value="College of Business and Accountancy">College of Business and Accountancy
                                </option>
                                <option value="College of Computer Science and Information Systems">College of Computer
                                    Science and Information Systems</option>
                                <option value="College of Engineering and Technology">College of Engineering and
                                    Technology</option>
                                <option value="College of Health Sciences and Nursing">College of Health Sciences and
                                    Nursing</option>
                                <option value="College of Law and Criminology">College of Law and Criminology</option>
                                <option value="College of Tourism, Hospitality Management and Transportation">College of
                                    Tourism, Hospitality Management and Transportation</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteBtn" style="display: none;">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                    <button type="button" class="btn btn-primary" id="saveEventBtn">
                        <i class="bi bi-check-circle me-1"></i>Save Event
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Event Modal (Students) -->
    <div class="modal fade" id="viewEventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="event-details">
                        <div class="detail-group">
                            <label class="detail-label">Event Title</label>
                            <div class="detail-value" id="viewEventTitle">-</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-group">
                                    <label class="detail-label">Date</label>
                                    <div class="detail-value" id="viewEventDate">-</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-group">
                                    <label class="detail-label">Time</label>
                                    <div class="detail-value" id="viewEventTime">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="detail-group">
                            <label class="detail-label">Description</label>
                            <div class="detail-value" id="viewEventDescription">-</div>
                        </div>
                        <div class="detail-group">
                            <label class="detail-label">Department/College</label>
                            <div class="detail-value" id="viewEventDepartment">-</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/event-calendar.js"></script>
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
</body>

</html>