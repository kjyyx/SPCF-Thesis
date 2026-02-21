<?php
// navbar.php - Reusable navbar component for Sign-um application
// Requires $currentUser to be set in the including script
?>
<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container-fluid">
        <a href="<?php echo BASE_URL; ?>" class="navbar-brand">
            <i class="bi bi-building me-2"></i>
            Sign-um | <?php echo $pageTitle ?? 'Dashboard'; ?>
        </a>

        <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
            <!-- User Info -->
            <div class="user-info me-3">
                <i class="bi bi-person-circle me-2"></i>
                <span id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                <span class="badge ms-2 <?php
                echo ($currentUser['role'] === 'admin') ? 'bg-danger' :
                    (($currentUser['role'] === 'employee') ? 'bg-primary' : 'bg-success');
                ?>" id="userRoleBadge">
                    <?php echo strtoupper($currentUser['role']); ?>
                </span>
            </div>

            <!-- Notifications -->
            <div class="notification-bell me-3" onclick="showNotifications()" role="button" tabindex="0"
                aria-label="Show notifications" title="View notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="notification-badge" id="notificationCount" aria-label="Number of notifications">0</span>
            </div>

            <!-- Settings Dropdown -->
            <div class="dropdown me-3">
                <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button"
                    id="settingsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true"
                    title="Account Settings" aria-label="Open account settings menu">
                    <i class="bi bi-gear me-2" aria-hidden="true"></i>Settings
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="settingsDropdown" role="menu">
                    <li role="none"><a class="dropdown-item" href="#" onclick="openProfileSettings()" title="Edit your profile information" role="menuitem">
                            <i class="bi bi-person-gear me-2" aria-hidden="true"></i>Profile Settings</a></li>
                    <li role="none"><a class="dropdown-item" href="#" onclick="openChangePassword()" title="Change your password" role="menuitem">
                            <i class="bi bi-key me-2" aria-hidden="true"></i>Change Password</a></li>
                    <li role="none"><a class="dropdown-item" href="#" onclick="openPreferences()" title="Customize your preferences" role="menuitem">
                            <i class="bi bi-sliders me-2" aria-hidden="true"></i>Preferences</a></li>
                    <li role="none"><a class="dropdown-item" href="#" onclick="showHelp()" title="Get help and support" role="menuitem">
                            <i class="bi bi-question-circle me-2" aria-hidden="true"></i>Help & Support</a></li>
                    <li role="none"><hr class="dropdown-divider"></li>
                    <?php if (!($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false)): ?>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=calendar" title="View university calendar" role="menuitem">
                                <i class="bi bi-calendar-event me-2" aria-hidden="true"></i>Calendar</a></li>
                    <?php endif; ?>
                    <?php if ($currentUser['role'] === 'student' || $currentUser['role'] === 'admin'): ?>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=notifications" title="Create a new document" role="menuitem">
                                <i class="bi bi-file-plus me-2" aria-hidden="true"></i>Create Document</a></li>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=track-document" title="Track your documents" role="menuitem">
                                <i class="bi bi-search me-2" aria-hidden="true"></i>Track Document</a></li>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=upload-publication" title="Upload publication materials" role="menuitem">
                                <i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>Upload Publication</a></li>
                    <?php endif; ?>
                    <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=notifications" title="View notifications" role="menuitem">
                            <i class="bi bi-bell me-2" aria-hidden="true"></i>Notifications</a></li>
                    <?php if ($currentUser['role'] === 'student' || $currentUser['role'] === 'admin'): ?>
                        <?php if (!($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false)): ?>
                            <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=saf" title="Student Allocated Funds" role="menuitem">
                                    <i class="bi bi-cash-coin me-2" aria-hidden="true"></i>SAF</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($currentUser['role'] === 'employee' && in_array($currentUser['position'] ?? '', ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)'])): ?>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=pubmat-approvals" title="Review public material approvals" role="menuitem">
                                <i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Pubmat Approvals</a></li>
                    <?php endif; ?>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>?page=dashboard" title="Access admin dashboard" role="menuitem">
                                <i class="bi bi-shield-check me-2" aria-hidden="true"></i>Admin Dashboard</a></li>
                    <?php endif; ?>
                    <li role="none"><hr class="dropdown-divider"></li>
                    <li role="none"><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>?page=logout" title="Sign out of your account" role="menuitem">
                            <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<script>
    // Global user data for JavaScript
    window.currentUser = <?php
    $jsUser = [
        'id' => $currentUser['id'],
        'firstName' => $currentUser['first_name'],
        'lastName' => $currentUser['last_name'],
        'role' => $currentUser['role'],
        'email' => $currentUser['email'],
        'department' => $currentUser['department'] ?? '',
        'position' => $currentUser['position'] ?? ''
    ];
    echo json_encode($jsUser);
    ?>;
    window.BASE_URL = "<?php echo BASE_URL; ?>";
</script>

<script src="<?php echo BASE_URL; ?>assets/js/navbar-settings.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/global-notifications.js"></script>