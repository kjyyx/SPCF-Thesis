<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';

echo "Testing email configuration...\n";
echo "MAIL_HOST: " . ($_ENV['MAIL_HOST'] ?? 'NOT SET') . "\n";
echo "MAIL_PORT: " . ($_ENV['MAIL_PORT'] ?? 'NOT SET') . "\n";
echo "MAIL_USERNAME: " . ($_ENV['MAIL_USERNAME'] ?? 'NOT SET') . "\n";
echo "MAIL_FROM_ADDRESS: " . ($_ENV['MAIL_FROM_ADDRESS'] ?? 'NOT SET') . "\n";

echo "\nTesting sendDocumentProgressEmail function...\n";

try {
    $result = sendDocumentProgressEmail('kpjaculbia@gmail.com', 'John Lennon', 'Test Project Proposal', 'College Student Council Adviser Approval', 'College Dean Approval');
    echo 'Result: ' . ($result ? 'true' : 'false') . "\n";
} catch (Exception $e) {
    echo 'Exception: ' . $e->getMessage() . "\n";
}

echo "\nTesting sendDocumentRejectedEmail function...\n";

try {
    $result = sendDocumentRejectedEmail('kpjaculbia@gmail.com', 'John Lennon', 'Test Project Proposal', 'Document does not meet submission requirements.');
    echo 'Result: ' . ($result ? 'true' : 'false') . "\n";
} catch (Exception $e) {
    echo 'Exception: ' . $e->getMessage() . "\n";
}
?>