<?php
// includes/config.php - Application configuration

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'spcf_thesis_db');

// Application settings
define('BASE_URL', 'http://localhost/SPCF-Thesis/');
define('SITE_NAME', 'Sign-um');

// Environment (change to 'production' when deploying)
define('ENVIRONMENT', 'development');

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