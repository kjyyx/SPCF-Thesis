<?php
// includes/config.php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Make sure BASE_URL exists
if (!isset($_ENV['BASE_URL']) || empty($_ENV['BASE_URL'])) {
    die('❌ BASE_URL is not defined in .env');
}

// Database configuration
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_NAME', $_ENV['DB_NAME']);

// Application settings
define('BASE_URL', rtrim($_ENV['BASE_URL'], '/') . '/');
// Use realpath for cleaner absolute paths without trailing dots/slashes
define('ROOT_PATH', realpath(__DIR__ . '/../') . '/');
define('SITE_NAME', $_ENV['SITE_NAME'] ?? 'Sign-um');

// Environment
define('ENVIRONMENT', $_ENV['ENVIRONMENT'] ?? 'production');

// Error reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Hide errors from the UI, but log them for debugging!
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', ROOT_PATH . 'php-error.log');
}

// Timezone (Updated to ensure accurate local time for timestamps)
date_default_timezone_set('Asia/Manila');