<?php
// Run this once to hash your passwords, then delete it
$password = 'admin123'; // Change this to your desired password
$hashed = password_hash($password, PASSWORD_DEFAULT);
echo "Hashed password: " . $hashed . "<br>";
echo "Verify: " . (password_verify($password, $hashed) ? 'Match' : 'No match');
?>