<?php
// Front controller for pretty URLs
require_once __DIR__ . '/includes/config.php';
// var_dump(BASE_URL);
require_once __DIR__ . '/includes/session.php';

// Get the page from URL parameter or default to login
$page = $_GET['page'] ?? 'login';

// Map pretty URLs to view files
$pageMap = [
    'login' => 'user-login.php',
    'logout' => 'user-logout.php',
    'dashboard' => 'admin-dashboard.php',
    'calendar' => 'event-calendar.php',
    'saf' => 'saf.php',
    'create-document' => 'create-document.php',
    'track-document' => 'track-document.php',
    'upload-publication' => 'upload-publication.php',
    'notifications' => 'notifications.php',
    'pubmat-approvals' => 'pubmat-approvals.php',
    '404' => '404.php',
];

// Check if page exists
if (!isset($pageMap[$page])) {
    $page = '404';
}

$viewFile = __DIR__ . '/views/' . $pageMap[$page];

// Include the view if it exists
if (file_exists($viewFile)) {
    include $viewFile;
} else {
    include __DIR__ . '/views/404.php';
}
?>