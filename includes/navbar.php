<?php
// navbar.php - Reusable navbar component for Sign-um application
// Requires $currentUser to be set in the including script
?>
<nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
                <a href="<?php echo BASE_URL; ?>" class="navbar-brand">
                        <img src="<?php echo BASE_URL; ?>assets/images/Sign-UM logo.png" alt="Sign-um Logo">
                        <span class="navbar-brand-divider" aria-hidden="true">|</span>
                        <span
                                class="navbar-brand-title text-muted fw-normal"><?php echo $pageTitle ?? 'Dashboard'; ?></span>
                </a>

                <div class="navbar-nav ms-auto d-flex flex-row align-items-center">
                        <div class="user-info me-3 hidden-mobile">
                                <i class="bi bi-person-circle me-2"></i>
                                <span
                                        id="userDisplayName"><?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></span>
                                <span class="badge ms-2 <?php
                                echo ($currentUser['role'] === 'admin') ? 'bg-danger' :
                                        (($currentUser['role'] === 'employee') ? 'bg-primary' : 'bg-success');
                                ?>" id="userRoleBadge" style="border-radius: 50px;">
                                        <?php echo strtoupper($currentUser['role']); ?>
                                </span>
                        </div>

                        <div class="notification-bell me-4 position-relative" onclick="showNotifications()"
                                style="cursor: pointer; transition: transform 0.2s ease;">
                                <i class="bi bi-bell fs-5 text-secondary hover-text-primary"></i>
                                <span id="notificationCount"
                                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm"
                                        style="display: none; font-size: 0.65rem; border: 2px solid white;">
                                        0
                                        <span class="visually-hidden">unread messages</span>
                                </span>
                        </div>

                        <div class="dropdown me-3">
                                <button class="btn btn-outline-light btn-sm dropdown-toggle rounded-pill" type="button"
                                        id="settingsDropdown" data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-haspopup="true" title="Account Settings"
                                        aria-label="Open account settings menu">
                                        <i class="bi bi-gear me-1" aria-hidden="true"></i>Settings
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="settingsDropdown"
                                        role="menu"
                                        style="border-radius: 24px; border: 1px solid var(--color-border-subtle); padding: 8px;">
                                        <li role="none"><a class="dropdown-item rounded-3" href="#"
                                                        onclick="openProfileSettings()"
                                                        title="Edit your profile information" role="menuitem">
                                                        <i class="bi bi-person-gear me-2" aria-hidden="true"></i>Profile
                                                        Settings</a></li>
                                        <li role="none"><a class="dropdown-item rounded-3" href="#"
                                                        onclick="openChangePassword()" title="Change your password"
                                                        role="menuitem">
                                                        <i class="bi bi-key me-2" aria-hidden="true"></i>Change
                                                        Password</a></li>
                                        <?php if ($currentUser['role'] === 'admin'): ?>
                                                <li role="none"><a class="dropdown-item rounded-3" href="#"
                                                                onclick="openPreferences()" title="Customize your preferences"
                                                                role="menuitem">
                                                                <i class="bi bi-sliders me-2"
                                                                        aria-hidden="true"></i>Preferences</a></li>
                                        <?php endif; ?>
                                        <li role="none"><a class="dropdown-item rounded-3" href="#" onclick="showHelp()"
                                                        title="Get help and support" role="menuitem">
                                                        <i class="bi bi-question-circle me-2"
                                                                aria-hidden="true"></i>Help & Support</a></li>
                                        <li role="none">
                                                <hr class="dropdown-divider">
                                        </li>

                                        <!-- Admin-only navigation -->
                                        <?php if ($currentUser['role'] === 'admin'): ?>
                                                <?php if ($currentPage !== 'calendar'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>calendar"
                                                                        title="View university calendar" role="menuitem">
                                                                        <i class="bi bi-calendar-event me-2"
                                                                                aria-hidden="true"></i>Calendar</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'dashboard'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>dashboard"
                                                                        title="Access admin dashboard" role="menuitem">
                                                                        <i class="bi bi-shield-check me-2" aria-hidden="true"></i>Admin
                                                                        Dashboard</a></li>
                                                <?php endif; ?>

                                                <!-- Student navigation -->
                                        <?php elseif ($currentUser['role'] === 'student'): ?>
                                                <?php if ($currentPage !== 'calendar'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>calendar"
                                                                        title="View university calendar" role="menuitem">
                                                                        <i class="bi bi-calendar-event me-2"
                                                                                aria-hidden="true"></i>Calendar</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'create-document'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>create-document"
                                                                        title="Create a new document" role="menuitem">
                                                                        <i class="bi bi-file-plus me-2" aria-hidden="true"></i>Create
                                                                        Document</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'track-document'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>track-document"
                                                                        title="Track your documents" role="menuitem">
                                                                        <i class="bi bi-search me-2" aria-hidden="true"></i>Track
                                                                        Document</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'upload-publication'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>upload-publication"
                                                                        title="Upload publication materials" role="menuitem">
                                                                        <i class="bi bi-cloud-upload me-2" aria-hidden="true"></i>Upload
                                                                        Publication</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'notifications'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>notifications"
                                                                        title="View notifications" role="menuitem">
                                                                        <i class="bi bi-bell me-2"
                                                                                aria-hidden="true"></i>Notifications</a></li>
                                                <?php endif; ?>
                                                <?php if (!($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) && $currentPage !== 'saf'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>saf"
                                                                        title="Student Allocated Funds" role="menuitem">
                                                                        <i class="bi bi-cash-coin me-2" aria-hidden="true"></i>SAF</a>
                                                        </li>
                                                <?php endif; ?>

                                                <!-- Employee navigation -->
                                        <?php elseif ($currentUser['role'] === 'employee'): ?>
                                                <?php if (!($currentUser['role'] === 'employee' && stripos($currentUser['position'] ?? '', 'Accounting') !== false) && $currentPage !== 'calendar'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>calendar"
                                                                        title="View university calendar" role="menuitem">
                                                                        <i class="bi bi-calendar-event me-2"
                                                                                aria-hidden="true"></i>Calendar</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentPage !== 'notifications'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>notifications"
                                                                        title="View notifications" role="menuitem">
                                                                        <i class="bi bi-bell me-2"
                                                                                aria-hidden="true"></i>Notifications</a></li>
                                                <?php endif; ?>
                                                <?php if (stripos($currentUser['position'] ?? '', 'Accounting') !== false && $currentPage !== 'saf'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>saf"
                                                                        title="Student Allocated Funds" role="menuitem">
                                                                        <i class="bi bi-cash-coin me-2" aria-hidden="true"></i>SAF</a>
                                                        </li>
                                                <?php endif; ?>
                                                <?php if (in_array($currentUser['position'] ?? '', ['College Student Council Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)']) && $currentPage !== 'pubmat-approvals'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>pubmat-approvals"
                                                                        title="Review public material approvals" role="menuitem">
                                                                        <i class="bi bi-file-earmark-text me-2"
                                                                                aria-hidden="true"></i>Pubmat Approvals</a></li>
                                                <?php endif; ?>
                                                <?php if ($currentUser['position'] === 'Physical Plant and Facilities Office (PPFO)' && $currentPage !== 'pubmat-display'): ?>
                                                        <li role="none"><a class="dropdown-item rounded-3"
                                                                        href="<?php echo BASE_URL; ?>pubmat-display"
                                                                        title="View approved pubmats slideshow" role="menuitem">
                                                                        <i class="bi bi-images me-2" aria-hidden="true"></i>Pubmat
                                                                        Display</a></li>
                                                <?php endif; ?>
                                        <?php endif; ?>
                                        <li role="none">
                                                <hr class="dropdown-divider">
                                        </li>
                                        <li role="none"><a class="dropdown-item rounded-3 text-danger"
                                                        href="<?php echo BASE_URL; ?>logout"
                                                        title="Sign out of your account" role="menuitem">
                                                        <i class="bi bi-box-arrow-right me-2"
                                                                aria-hidden="true"></i>Logout</a></li>
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