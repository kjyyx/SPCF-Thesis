<?php
// generate-correct-hashes.php - Generate correct password hashes
echo "<h1>Correct Password Hashes</h1>";
echo "<pre>";

// Define the correct passwords for each user
$passwords = [
    'admin' => 'admin123',
    'employee' => 'admin123',
    'student' => 'student123'
];

echo "=== PASSWORD HASHES ===\n";
foreach ($passwords as $role => $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "$role password: '$password'\n";
    echo "Hash: $hash\n";
    echo "Verification: " . (password_verify($password, $hash) ? '✓ PASS' : '✗ FAIL') . "\n\n";

    // Store hashes for SQL update
    $hashes[$role] = $hash;
}

echo "=== SQL UPDATE STATEMENTS ===\n";
echo "-- Update administrators table\n";
echo "UPDATE administrators SET password = '{$hashes['admin']}' WHERE id = 'ADM001';\n\n";

echo "-- Update employees table\n";
echo "UPDATE employees SET password = '{$hashes['employee']}' WHERE id = 'EMP001';\n\n";

echo "-- Update students table\n";
echo "UPDATE students SET password = '{$hashes['student']}' WHERE id = 'STU001';\n\n";

echo "</pre>";
?>