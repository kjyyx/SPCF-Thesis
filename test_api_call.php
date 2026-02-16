<?php
// Simulate the API call that was failing
require_once 'includes/config.php';
require_once 'includes/session.php';
require_once 'includes/auth.php';
require_once 'includes/database.php';
require_once 'vendor/autoload.php';

// Simulate the POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// The exact JSON from the failing request
$jsonData = '{"action":"create","doc_type":"communication","student_id":"STU004","data":{"date":"2026-02-16","department":"College of Engineering","for":"a","notedList":[{"name":"Elena Mendoza","title":"College Student Council Adviser, College of Engineering"}],"approvedList":[{"name":"Elena Mendoza","title":"College Student Council Adviser, College of Engineering"}],"subject":"a","body":"a","from":"Sofia Reyes","from_title":"College Student Council President, College of Engineering"}}';

echo "Simulating API call with data: $jsonData\n\n";

// Start session and set user (simulate logged in user)
session_start();
$_SESSION['user_id'] = 'STU004'; // The student ID from the request
$_SESSION['user_role'] = 'student';

// Include the documents.php logic
require_once 'api/documents.php';

echo "\nSimulation complete.\n";
?>