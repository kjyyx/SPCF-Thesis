<?php
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/auth.php';
requireAuth(); // Requires login

// Get current user
$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);

if (!$currentUser) {
    logoutUser();
    header('Location: ' . BASE_URL . 'login');
    exit();
}

error_log("Current position: " . $currentUser['position']);

// Restrict Accounting employees to only SAF access
if ($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) {
    header('Location: ' . BASE_URL . 'saf');
    exit();
}

// Audit log helper function
function addAuditLog($action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO')
{
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
            $severity ?? 'INFO',
            $_SERVER['REMOTE_ADDR'] ?? null,
            null, // Set user_agent to null to avoid storing PII
        ]);
    } catch (Exception $e) {
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

// Log page view
addAuditLog('EVENT_CALENDAR_VIEWED', 'Event Management', 'Viewed event calendar', $currentUser['id'], 'User', 'INFO');

// Set page title for navbar
$pageTitle = 'University Calendar';
$currentPage = 'calendar';

// Check for pending signatures
require_once ROOT_PATH . 'includes/database.php';
$db = new Database();
$pdo = $db->getConnection();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM document_steps WHERE (assigned_to_employee_id = ? OR assigned_to_student_id = ?) AND status = 'pending'");
$stmt->execute([$currentUser['id'], $currentUser['id']]);
$pendingCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/images/Sign-UM logo ico.png">
    <title>Sign-um - Event Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/master-css.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/event-calendar.css">
    <script>
        // Pass user data to JavaScript
        window.currentUser = <?php
        // Convert snake_case to camelCase for JavaScript
        $jsUser = $currentUser;
        $jsUser['firstName'] = $currentUser['first_name'];
        $jsUser['lastName'] = $currentUser['last_name'];
        $jsUser['must_change_password'] = isset($currentUser['must_change_password']) ? (int) $currentUser['must_change_password'] : ((int) ($_SESSION['must_change_password'] ?? 0));
        $jsUser['position'] = $currentUser['position'] ?? '';
        echo json_encode($jsUser);
        ?>;
        window.isAdmin = <?php echo ($currentUser['role'] === 'admin') ? 'true' : 'false'; ?>;
        window.BASE_URL = "<?php echo BASE_URL; ?>";
    </script>
</head>

<body class="has-navbar">
    <?php include ROOT_PATH . 'includes/navbar.php'; ?>
    <?php include ROOT_PATH . 'includes/notifications.php'; ?>

    <div class="container pt-4 pb-5">

        <?php if (isset($currentUser['must_change_password']) && (int) $currentUser['must_change_password'] === 1): ?>
            <div class="alert alert-warning mb-4" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <div class="alert-content">
                    <div class="alert-title">Security Notice</div>
                    Your account is using a temporary password. For your security, please change your password now.
                </div>
                <button type="button" class="btn btn-warning btn-sm" onclick="openChangePassword()">
                    <i class="bi bi-key"></i> Change Password
                </button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-calendar-event text-primary me-2"></i> Document Calendar & Events
                </h1>
                <p class="page-subtitle">Track document deadlines, signing events, and important dates</p>
            </div>

            <div class="page-actions">
                <?php if ($currentUser['role'] === 'student'): ?>
                    <span class="badge badge-success me-2"><i class="bi bi-mortarboard me-1"></i> Student</span>
                    <button class="btn btn-ghost btn-sm" onclick="openCreateDocumentModal()" title="Create Document"><i
                            class="bi bi-file-plus"></i> Create</button>
                    <button class="btn btn-ghost btn-sm" onclick="openUploadPubmatModal()" title="Submit Pubmat"><i
                            class="bi bi-file-earmark-text"></i> Pubmat</button>
                    <button class="btn btn-ghost btn-sm" onclick="openTrackDocumentsModal()" title="Track Documents"><i
                            class="bi bi-search"></i> Track</button>
                    <button class="btn btn-ghost btn-sm" onclick="window.location.href='<?php echo BASE_URL; ?>saf'"
                        title="Student Allocated Funds"><i class="bi bi-cash-coin"></i> SAF</button>
                    <button class="btn btn-ghost btn-sm" onclick="openPendingApprovals()"
                        title="View Documents In Review"><i class="bi bi-clipboard-check"></i> In Review</button>

                    <?php if ($currentUser['position'] === 'Supreme Student Council President'): ?>
                        <button class="btn btn-primary btn-sm ms-2" onclick="openPendingApprovals()" id="approvalsBtn"><i
                                class="bi bi-clipboard-check"></i> Approvals</button>
                    <?php endif; ?>

                <?php elseif ($currentUser['role'] === 'employee'): ?>
                    <span class="badge badge-primary me-2"><i class="bi bi-person-badge me-1"></i> Employee</span>
                    <button class="btn btn-danger btn-sm" onclick="openPendingApprovals()"><i
                            class="bi bi-clipboard-check"></i> Pending Approvals</button>

                    <?php if (stripos($currentUser['position'] ?? '', 'OSA') !== false): ?>
                        <button class="btn btn-ghost btn-sm"
                            onclick="window.location.href='<?php echo BASE_URL; ?>saf'"><i class="bi bi-cash-coin"></i>
                            SAF</button>
                    <?php endif; ?>

                    <?php if (in_array($currentUser['position'] ?? '', ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'])): ?>
                        <button class="btn btn-ghost btn-sm" onclick="openPubmatApprovals()"><i
                                class="bi bi-file-earmark-text"></i> Pubmats</button>
                    <?php endif; ?>
                    <?php if (($currentUser['position'] ?? '') === 'Physical Plant and Facilities Office (PPFO)'): ?>
                        <button class="btn btn-info btn-sm" onclick="openPubmatDisplay()"><i class="bi bi-image"></i> Pubmat Display</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card h-full">
                    <div class="card-body d-flex flex-column gap-3">

                        <div class="d-flex justify-between items-center flex-wrap gap-3">
                            <div class="d-flex items-center gap-2">
                                <button class="btn btn-icon sm" id="prevMonth"><i
                                        class="bi bi-chevron-left"></i></button>
                                <h3 id="currentMonth" class="m-0 text-lg text-center" style="min-width: 160px;">
                                    Loading...</h3>
                                <button class="btn btn-icon sm" id="nextMonth"><i
                                        class="bi bi-chevron-right"></i></button>
                            </div>

                            <div class="nav-tabs-glass">
                                <button type="button" class="nav-tab active view-btn" data-view="month"><i
                                        class="bi bi-calendar-month"></i> Month</button>
                                <button type="button" class="nav-tab view-btn" data-view="agenda"><i
                                        class="bi bi-calendar-event"></i> Agenda</button>
                            </div>
                        </div>

                        <div class="divider m-0"></div>

                        <div class="d-flex flex-wrap gap-3 items-center">
                            <div class="search-input-wrapper flex-1" style="min-width: 200px;">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="form-control sm" id="eventSearch"
                                    placeholder="Search events...">
                            </div>
                            <div style="min-width: 180px;">
                                <select class="form-select sm" id="departmentFilter">
                                    <option value="">All Departments</option>
                                </select>
                            </div>
                            <div style="min-width: 140px;">
                                <select class="form-select sm" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="approved">Approved</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="d-flex flex-column gap-3 h-full">
                    <div class="d-flex gap-3 flex-1">
                        <div class="stat-card info flex-1 p-3 flex-col justify-center text-center">
                            <div class="stat-value text-xl" id="upcomingEvents">0</div>
                            <div class="stat-label">Upcoming</div>
                        </div>
                        <div class="stat-card primary flex-1 p-3 flex-col justify-center text-center">
                            <div class="stat-value text-xl" id="todayEvents">0</div>
                            <div class="stat-label">Today</div>
                        </div>
                        <div class="stat-card flex-1 p-3 flex-col justify-center text-center">
                            <div class="stat-value text-xl" id="totalEvents">0</div>
                            <div class="stat-label">Total</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-end">
                        <button type="button" class="btn btn-ghost flex-1" id="exportEventsBtn">
                            <i class="bi bi-download"></i> Export
                        </button>
                        <?php if ($currentUser['role'] !== 'student'): ?>
                            <button type="button" class="btn btn-primary flex-2" id="addEventBtn">
                                <i class="bi bi-plus-lg"></i> Add Event
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-lg p-0 border-0 shadow-md">
            <div id="calendarLoading" class="text-center py-5 d-none">
                <div class="spinner spinner-lg mx-auto mb-3"></div>
                <p class="text-muted">Loading events...</p>
            </div>

            <div id="monthView" class="calendar-view active">
                <div class="su-calendar-grid">
                    <div class="su-calendar-header">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>
                    <div id="calendarDays" class="su-calendar-body">
                    </div>
                </div>
            </div>

            <div id="agendaView" class="calendar-view d-none p-4">
                <div id="agendaContainer" class="su-agenda-container">
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalLabel">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="eventForm" class="d-flex flex-col gap-3">
                        <div class="form-group mb-0">
                            <label for="eventTitle" class="form-label">Event Title <span
                                    class="required">*</span></label>
                            <input type="text" class="form-control" id="eventTitle" required>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6 form-group mb-0">
                                <label for="eventDate" class="form-label">Date <span class="required">*</span></label>
                                <input type="date" class="form-control" id="eventDate" required>
                            </div>
                            <div class="col-md-6 form-group mb-0">
                                <label for="eventTime" class="form-label">Time</label>
                                <input type="time" class="form-control" id="eventTime">
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="eventVenue" class="form-label">Venue</label>
                            <input type="text" class="form-control" id="eventVenue">
                        </div>
                        <div class="form-group mb-0">
                            <label for="eventDepartment" class="form-label">Department/College <span
                                    class="required">*</span></label>
                            <select class="form-select" id="eventDepartment" required>
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
                    <button type="button" class="btn btn-ghost me-auto" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteBtn" style="display: none;"><i
                            class="bi bi-trash"></i></button>
                    <button type="button" class="btn btn-warning" id="disapproveBtn"
                        style="display: none;">Disapprove</button>
                    <button type="button" class="btn btn-success" id="approveBtn"
                        style="display: none;">Approve</button>
                    <button type="button" class="btn btn-primary" id="saveEventBtn">Save Event</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewEventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-col gap-4">
                        <div>
                            <label class="form-label text-muted mb-1">Event Title</label>
                            <div class="fw-semibold text-base" id="viewEventTitle">-</div>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label text-muted mb-1">Date</label>
                                <div class="fw-medium" id="viewEventDate">-</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted mb-1">Time</label>
                                <div class="fw-medium" id="viewEventTime">-</div>
                            </div>
                        </div>
                        <div>
                            <label class="form-label text-muted mb-1">Venue</label>
                            <div class="fw-medium" id="viewEventVenue">-</div>
                        </div>
                        <div>
                            <label class="form-label text-muted mb-1">Department/College</label>
                            <div class="fw-medium" id="viewEventDepartment">-</div>
                        </div>
                        <div>
                            <label class="form-label text-muted mb-1">Status</label>
                            <div id="viewEventStatus">-</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="changePasswordForm">
                    <div class="modal-body d-flex flex-col gap-3">
                        <div id="changePasswordMessages"></div>
                        <div class="form-group mb-0">
                            <label for="currentPassword" class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="currentPassword" required>
                                <button class="btn btn-ghost border" type="button"
                                    onclick="togglePasswordVisibility('currentPassword')"><i class="bi bi-eye"
                                        id="currentPasswordIcon"></i></button>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="newPassword" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="newPassword" required>
                                <button class="btn btn-ghost border" type="button"
                                    onclick="togglePasswordVisibility('newPassword')"><i class="bi bi-eye"
                                        id="newPasswordIcon"></i></button>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="confirmPassword" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirmPassword" required>
                                <button class="btn btn-ghost border" type="button"
                                    onclick="togglePasswordVisibility('confirmPassword')"><i class="bi bi-eye"
                                        id="confirmPasswordIcon"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileSettingsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Profile Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="profileSettingsForm">
                    <div class="modal-body d-flex flex-col gap-3">
                        <div id="profileSettingsMessages"></div>
                        <div class="row g-3">
                            <div class="col-md-6 form-group mb-0">
                                <label for="profileFirstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="profileFirstName" required <?php if ($currentUser['role'] !== 'admin') echo 'readonly'; ?>>
                            </div>
                            <div class="col-md-6 form-group mb-0">
                                <label for="profileLastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="profileLastName" required <?php if ($currentUser['role'] !== 'admin') echo 'readonly'; ?>>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="profileEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="profileEmail" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" required>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="profilePhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="profilePhone" pattern="^(09|\+639)\d{9}$">
                            <div class="invalid-feedback">Please enter a valid Philippine phone number (e.g., 09123456789 or +639123456789).</div>
                        </div>
                        <?php if ($currentUser['role'] === 'student'): ?>
                            <div class="form-group mb-0">
                                <label for="profilePosition" class="form-label">Position/Role</label>
                                <input type="text" class="form-control" id="profilePosition" readonly>
                            </div>
                        <?php endif; ?>

                        <div class="divider"></div>

                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="darkModeToggle">
                            <label class="form-check-label fw-medium ms-2" for="darkModeToggle">Enable Dark Mode</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="preferencesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Preferences</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-col gap-4">
                    <div id="preferencesMessages"></div>

                    <div>
                        <label class="form-label text-muted mb-2">Notification Settings</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                            <label class="form-check-label fw-medium ms-1" for="emailNotifications">Email notifications
                                for events</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="browserNotifications" checked>
                            <label class="form-check-label fw-medium ms-1" for="browserNotifications">Browser
                                notifications</label>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label for="defaultView" class="form-label">Default Calendar View</label>
                        <select class="form-select" id="defaultView">
                            <option value="month">Month</option>
                            <option value="week">Week</option>
                            <option value="list">List</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label for="timezone" class="form-label">Timezone</label>
                        <select class="form-select" id="timezone">
                            <option value="Asia/Manila">Asia/Manila (GMT+8)</option>
                            <option value="UTC">UTC</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePreferences()">Save Preferences</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Help & Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item border-0 mb-3 bg-transparent">
                            <h2 class="accordion-header">
                                <button class="accordion-button rounded-xl bg-surface-sunken" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#gettingStarted">
                                    Getting Started
                                </button>
                            </h2>
                            <div id="gettingStarted" class="accordion-collapse collapse show"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body px-4 py-3">
                                    <p>Welcome to Sign-um Document Portal! Here's how to get started:</p>
                                    <ul class="mb-0 text-muted">
                                        <li><strong>Students:</strong> View events, create documents, track progress
                                        </li>
                                        <li><strong>Employees:</strong> Manage events, approve documents</li>
                                        <li><strong>Admins:</strong> Full system management</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item border-0 mb-3 bg-transparent">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed rounded-xl bg-surface-sunken" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#calendarFeatures">
                                    Calendar Features
                                </button>
                            </h2>
                            <div id="calendarFeatures" class="accordion-collapse collapse"
                                data-bs-parent="#helpAccordion">
                                <div class="accordion-body px-4 py-3 text-muted">
                                    <h6 class="text-dark fw-bold mb-2">Keyboard Shortcuts</h6>
                                    <ul class="mb-0">
                                        <li><kbd>Ctrl</kbd> + <kbd>←</kbd> / <kbd>→</kbd>: Previous/Next month</li>
                                        <li><kbd>Ctrl</kbd> + <kbd>Home</kbd>: Go to today</li>
                                        <li><kbd>Ctrl</kbd> + <kbd>M</kbd>: Month view</li>
                                        <li><kbd>Ctrl</kbd> + <kbd>W</kbd>: Week view</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/event-calendar.js"></script>
    <script>
        function openPendingApprovals() { window.location.href = window.BASE_URL + 'notifications'; }
        function openPubmatApprovals() { window.location.href = window.BASE_URL + 'pubmat-approvals'; }
        function openCreateDocumentModal() { window.location.href = window.BASE_URL + 'create-document'; }
        function openUploadPubmatModal() { window.location.href = window.BASE_URL + 'upload-publication'; }
        function openTrackDocumentsModal() { window.location.href = window.BASE_URL + 'track-document'; }
    </script>
</body>

</html>