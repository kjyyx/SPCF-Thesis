<?php
require_once 'vendor/autoload.php';
require_once 'includes/config.php';
require_once 'includes/mailer.php';

echo "Testing email function...\n";

$result = sendDocumentApprovedEmail('test@example.com', 'Test User', 'Test Document');

if ($result) {
    echo "Email sent successfully!\n";
} else {
    echo "Email failed to send.\n";
}
?>