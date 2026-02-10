<?php
// navbar.php - Reusable navbar component for Sign-um application
// Requires $currentUser to be set in the including script
?>
<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container-fluid">
        <div class="navbar-brand">
            <i class="bi bi-building me-2"></i>
            Sign-um | <?php echo $pageTitle ?? 'Dashboard'; ?>
        </div>

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
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>calendar" title="View university calendar" role="menuitem">
                                <i class="bi bi-calendar-event me-2" aria-hidden="true"></i>Calendar</a></li>
                    <?php endif; ?>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <li role="none"><a class="dropdown-item" href="<?php echo BASE_URL; ?>dashboard" title="Access admin dashboard" role="menuitem">
                                <i class="bi bi-shield-check me-2" aria-hidden="true"></i>Admin Dashboard</a></li>
                    <?php endif; ?>
                    <li role="none"><hr class="dropdown-divider"></li>
                    <li role="none"><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>logout" title="Sign out of your account" role="menuitem">
                            <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>