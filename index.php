<?php
// Front controller for pretty URLs
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session.php';

// Get the page from URL parameter, ensure it's a string, default to login
$page = isset($_GET['page']) ? (string)$_GET['page'] : 'login';
// Handle the case where someone just goes to the root domain /
if (empty($page)) {
    $page = 'login';
}

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
    'pubmat-display' => 'pubmat-display.php',
    '404' => '404.php',
];

// Check if page exists in our map
if (!array_key_exists($page, $pageMap)) {
    $page = '404';
}

$viewFile = __DIR__ . '/views/' . $pageMap[$page];

// Include the view if it exists physically
if (file_exists($viewFile)) {
    include $viewFile;
} else {
    // Failsafe in case the mapped file was deleted
    include __DIR__ . '/views/404.php';
}
?>