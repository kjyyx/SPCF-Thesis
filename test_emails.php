<?php
/**
 * Email Notification Test Script
 * Tests the document approval and assignment email functions
 *
 * Usage: Run this file directly in browser or via command line
 * Example: php test_emails.php
 */

// Simple test without full session initialization
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';

// Include mailer functions directly
require_once ROOT_PATH . 'includes/mailer.php';

// Test data - UPDATE THESE WITH REAL EMAIL ADDRESSES FOR TESTING
$testStudentEmail = 'kpjaculbia@gmail.com'; // Replace with actual test email
$testStudentName = 'John Lennon';
$testDocumentTitle = 'Test Project Proposal';

$testEmployeeEmail = 'signumsystem2025@gmail.com'; // Replace with actual test email
$testEmployeeName = 'Elena Mendoza';

echo "<h1>Email Notification Test</h1>";
echo "<p>Testing document approval and assignment email functions...</p>";
echo "<p><strong>⚠️ IMPORTANT:</strong> Update the email addresses in this file with real email addresses before testing!</p>";

// Test 1: Document Approved Email
echo "<h2>Test 1: Document Approved Email</h2>";
echo "<p>Sending approval email to: {$testStudentEmail}</p>";

try {
    $result1 = sendDocumentApprovedEmail($testStudentEmail, $testStudentName, $testDocumentTitle);

    if ($result1) {
        echo "<p style='color: green;'>✅ Document approved email sent successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to send document approved email. Check error logs.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception occurred: " . $e->getMessage() . "</p>";
}

// Test 2: Document Assigned Email
echo "<h2>Test 2: Document Assigned Email</h2>";
echo "<p>Sending assignment email to: {$testEmployeeEmail}</p>";

try {
    $result2 = sendDocumentAssignedEmail($testEmployeeEmail, $testEmployeeName, $testDocumentTitle);

    if ($result2) {
        echo "<p style='color: green;'>✅ Document assigned email sent successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to send document assigned email. Check error logs.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception occurred: " . $e->getMessage() . "</p>";
}

// Test 3: Document Progress Email
echo "<h2>Test 3: Document Progress Email</h2>";
echo "<p>Sending progress email to: {$testStudentEmail}</p>";

try {
    $result3 = sendDocumentProgressEmail($testStudentEmail, $testStudentName, $testDocumentTitle, 'College Student Council Adviser Approval', 'College Dean Approval');

    if ($result3) {
        echo "<p style='color: green;'>✅ Document progress email sent successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to send document progress email. Check error logs.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception occurred: " . $e->getMessage() . "</p>";
}

// Test 4: Document Rejection Email
echo "<h2>Test 4: Document Rejection Email</h2>";
echo "<p>Sending rejection email to: {$testStudentEmail}</p>";

try {
    $result4 = sendDocumentRejectedEmail($testStudentEmail, $testStudentName, $testDocumentTitle, 'Document does not meet submission requirements. Please review the guidelines and resubmit.');

    if ($result4) {
        echo "<p style='color: green;'>✅ Document rejection email sent successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to send document rejection email. Check error logs.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception occurred: " . $e->getMessage() . "</p>";
}

// Summary
echo "<h2>Test Summary</h2>";
echo "<ul>";
echo "<li>Document Approved Email: " . ($result1 ? "PASSED" : "FAILED") . "</li>";
echo "<li>Document Assigned Email: " . ($result2 ? "PASSED" : "FAILED") . "</li>";
echo "<li>Document Progress Email: " . ($result3 ? "PASSED" : "FAILED") . "</li>";
echo "<li>Document Rejection Email: " . ($result4 ? "PASSED" : "FAILED") . "</li>";
echo "</ul>";

if (isset($result1) && isset($result2) && isset($result3) && isset($result4) && $result1 && $result2 && $result3 && $result4) {
    echo "<p style='color: green; font-weight: bold;'>🎉 All tests passed! Email notifications are working correctly.</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>⚠️ Some tests failed. Please check your email configuration and error logs.</p>";
}

// Instructions
echo "<h2>Next Steps</h2>";
echo "<ol>";
echo "<li>Check your email inbox (and spam folder) for the test emails</li>";
echo "<li>Verify that the emails display correctly with proper formatting</li>";
echo "<li>Update the test email addresses in this file with real email addresses for testing</li>";
echo "<li>Check PHP error logs if emails fail to send</li>";
echo "</ol>";

echo "<h2>Configuration Check</h2>";
echo "<p>Current email configuration:</p>";
echo "<ul>";
echo "<li>SMTP Host: " . ($_ENV['MAIL_HOST'] ?? 'Not set') . "</li>";
echo "<li>SMTP Port: " . ($_ENV['MAIL_PORT'] ?? 'Not set') . "</li>";
echo "<li>From Address: " . ($_ENV['MAIL_FROM_ADDRESS'] ?? 'Not set') . "</li>";
echo "<li>From Name: " . ($_ENV['MAIL_FROM_NAME'] ?? 'Not set') . "</li>";
echo "</ul>";

if (!isset($_ENV['MAIL_HOST']) || !isset($_ENV['MAIL_PORT'])) {
    echo "<p style='color: orange;'>⚠️ Warning: Email environment variables may not be properly configured.</p>";
    echo "<p>Make sure your .env file contains the correct MAIL_* settings.</p>";
}
?></content>
<parameter name="filePath">c:\xampp\htdocs\SPCF-Thesis\test_emails.php