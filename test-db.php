<?php
require_once 'includes/config.php';
require_once 'includes/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    echo "Database connection successful!<br>";
    
    // Test if tables exist
    $tables = ['administrators', 'employees', 'students'];
    foreach ($tables as $table) {
        $result = $conn->query("SELECT 1 FROM $table LIMIT 1");
        if ($result !== false) {
            echo "Table '$table' exists!<br>";
        } else {
            echo "Table '$table' does NOT exist!<br>";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>