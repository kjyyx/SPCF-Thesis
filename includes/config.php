<?php
// includes/config.php - Application configuration

// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'spcf_thesis_db');

// Application settings
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/SPCF-Thesis/');
define('SITE_NAME', getenv('SITE_NAME') ?: 'Sign-um');

// Environment (change to 'production' when deploying)
define('ENVIRONMENT', getenv('ENVIRONMENT') ?: 'development');

// Error reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Timezone
date_default_timezone_set('Asia/Manila');
?>