<?php
// debug-login.php - Debug script for login issues
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

echo "<h1>Login Debug Information</h1>";
echo "<pre>";

// 1. Check database connection
echo "=== DATABASE CONNECTION ===\n";
try {
    $db = new Database();
    $conn = $db->getConnection();
    echo "✓ Database connection successful\n";
    echo "Host: " . DB_HOST . "\n";
    echo "Database: " . DB_NAME . "\n";
    echo "User: " . DB_USER . "\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
}

// 2. Check tables exist
echo "\n=== TABLES CHECK ===\n";
$tables = ['administrators', 'employees', 'students'];
foreach ($tables as $table) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) as count FROM $table");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "✓ Table '$table' exists with " . $result['count'] . " records\n";
    } catch (Exception $e) {
        echo "✗ Table '$table' error: " . $e->getMessage() . "\n";
    }
}

// 3. Show sample data (without passwords)
echo "\n=== SAMPLE DATA ===\n";
foreach ($tables as $table) {
    try {
        $stmt = $conn->query("SELECT id, first_name, last_name, email FROM $table LIMIT 3");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Table: $table\n";
        foreach ($rows as $row) {
            echo "  ID: {$row['id']}, Name: {$row['first_name']} {$row['last_name']}, Email: {$row['email']}\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "Error reading $table: " . $e->getMessage() . "\n\n";
    }
}

// 4. Test password verification
echo "=== PASSWORD VERIFICATION TEST ===\n";
$testCredentials = [
    ['id' => 'ADM001', 'password' => 'admin123', 'table' => 'administrators'],
    ['id' => 'EMP001', 'password' => 'admin123', 'table' => 'employees'],
    ['id' => 'STU001', 'password' => 'student123', 'table' => 'students'],
];

foreach ($testCredentials as $test) {
    try {
        $stmt = $conn->prepare("SELECT id, password FROM {$test['table']} WHERE id = ?");
        $stmt->execute([$test['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $verified = password_verify($test['password'], $user['password']);
            echo "✓ {$test['table']} {$test['id']}: Password verification " . ($verified ? 'SUCCESS' : 'FAILED') . "\n";
        } else {
            echo "✗ {$test['table']} {$test['id']}: User not found\n";
        }
    } catch (Exception $e) {
        echo "✗ Error testing {$test['table']} {$test['id']}: " . $e->getMessage() . "\n";
    }
}

// 5. Show PHP info
echo "\n=== PHP INFO ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "PDO MySQL Available: " . (extension_loaded('pdo_mysql') ? 'Yes' : 'No') . "\n";
echo "Session Auto Start: " . (ini_get('session.auto_start') ? 'Yes' : 'No') . "\n";

echo "</pre>";
?>