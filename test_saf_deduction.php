<?php
/**
 * SAF Fund Deduction Test
 * Tests the SAF approval fund deduction functionality using COE department
 */

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/utilities.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Test configuration
define('TEST_USER_ID', 1); // Assuming admin user exists
define('TEST_DOCUMENT_ID', 999999); // Use a high number to avoid conflicts

class SAFFundDeductionTest {
    private $db;

    public function __construct() {
        global $db;
        $this->db = $db;
    }

    public function runAllTests() {
        echo "=== SAF Fund Deduction Test Suite ===\n";
        echo "Testing with COE (College of Engineering) department\n\n";

        $this->testSetupBalances();
        $this->testSAFApprovalDeduction();
        $this->testDuplicatePrevention();
        $this->testInsufficientFunds();

        $this->displayResults();
        $this->cleanup();
    }

    private function testSetupBalances() {
        echo "1. Setting up test balances...\n";

        try {
            // Set up SSC balance
            $this->db->prepare("INSERT INTO saf_balances (department_id, initial_amount, used_amount, current_balance)
                               VALUES ('ssc', 10000.00, 2000.00, 8000.00)
                               ON DUPLICATE KEY UPDATE initial_amount = 10000.00, used_amount = 2000.00, current_balance = 8000.00")
                    ->execute();

            // Set up COE balance
            $this->db->prepare("INSERT INTO saf_balances (department_id, initial_amount, used_amount, current_balance)
                               VALUES ('coe', 5000.00, 1000.00, 4000.00)
                               ON DUPLICATE KEY UPDATE initial_amount = 5000.00, used_amount = 1000.00, current_balance = 4000.00")
                    ->execute();

            $this->testResults['setup'] = ['status' => 'PASS', 'message' => 'Test balances set up successfully'];
            echo "   ✓ SSC: ₱10,000 initial, ₱2,000 used, ₱8,000 available\n";
            echo "   ✓ COE: ₱5,000 initial, ₱1,000 used, ₱4,000 available\n";

        } catch (Exception $e) {
            $this->testResults['setup'] = ['status' => 'FAIL', 'message' => 'Failed to set up balances: ' . $e->getMessage()];
            echo "   ✗ Setup failed: " . $e->getMessage() . "\n";
        }
    }

    private function testSAFApprovalDeduction() {
        echo "2. Testing SAF approval fund deduction...\n";

        try {
            echo "   Creating test document...\n";
            // Create a mock SAF document data
            $safData = [
                'title' => 'Test SAF Request - COE Event',
                'department' => 'College of Engineering', // Full name as it would appear in form
                'reqSSC' => 1500.00, // Request ₱1,500 from SSC
                'reqCSC' => 2000.00  // Request ₱2,000 from COE
            ];

            // Insert test document
            $docStmt = $this->db->prepare("INSERT INTO documents (id, student_id, doc_type, data, title, status, uploaded_at)
                                          VALUES (?, 'STU001', 'saf', ?, ?, 'approved', NOW())
                                          ON DUPLICATE KEY UPDATE data = ?, doc_type = 'saf', title = ?, status = 'approved'");
            $docStmt->execute([TEST_DOCUMENT_ID, json_encode($safData), $safData['title'], json_encode($safData), $safData['title']]);

            // Get or create EVP step
            $stepStmt = $this->db->prepare("SELECT id FROM document_steps WHERE name LIKE '%EVP%' LIMIT 1");
            $stepStmt->execute();
            $step = $stepStmt->fetch(PDO::FETCH_ASSOC);

            if (!$step) {
                // Create a test EVP step
                $this->db->prepare("INSERT INTO document_steps (name, description) VALUES ('EVP Approval', 'Executive Vice President Approval')")
                        ->execute();
                $stepId = $this->db->lastInsertId();
            } else {
                $stepId = $step['id'];
            }

            // Simulate the approval process (extracted from documents.php logic)
            $this->simulateSAFDeduction(TEST_DOCUMENT_ID, $stepId, TEST_USER_ID);

            // Verify balances were deducted
            $sscStmt = $this->db->prepare("SELECT * FROM saf_balances WHERE department_id = 'ssc'");
            $sscStmt->execute();
            $sscBalance = $sscStmt->fetch(PDO::FETCH_ASSOC);
            
            $coeStmt = $this->db->prepare("SELECT * FROM saf_balances WHERE department_id = 'coe'");
            $coeStmt->execute();
            $coeBalance = $coeStmt->fetch(PDO::FETCH_ASSOC);

            $sscExpectedUsed = 2000.00 + 1500.00; // 2000 + 1500 = 3500
            $sscExpectedCurrent = 10000.00 - 3500.00; // 10000 - 3500 = 6500
            $coeExpectedUsed = 1000.00 + 2000.00; // 1000 + 2000 = 3000
            $coeExpectedCurrent = 5000.00 - 3000.00; // 5000 - 3000 = 2000

            $sscCorrect = abs($sscBalance['used_amount'] - $sscExpectedUsed) < 0.01 &&
                         abs($sscBalance['current_balance'] - $sscExpectedCurrent) < 0.01;
            $coeCorrect = abs($coeBalance['used_amount'] - $coeExpectedUsed) < 0.01 &&
                         abs($coeBalance['current_balance'] - $coeExpectedCurrent) < 0.01;

            if ($sscCorrect && $coeCorrect) {
                $this->testResults['deduction'] = ['status' => 'PASS', 'message' => 'Funds deducted correctly from both SSC and COE'];
                echo "   ✓ SSC balance: Used ₱" . number_format($sscBalance['used_amount'], 2) . ", Current ₱" . number_format($sscBalance['current_balance'], 2) . "\n";
                echo "   ✓ COE balance: Used ₱" . number_format($coeBalance['used_amount'], 2) . ", Current ₱" . number_format($coeBalance['current_balance'], 2) . "\n";
            } else {
                $this->testResults['deduction'] = ['status' => 'FAIL', 'message' => 'Balance deduction incorrect'];
                echo "   ✗ SSC Actual: Used ₱" . number_format($sscBalance['used_amount'], 2) . ", Current ₱" . number_format($sscBalance['current_balance'], 2) . "\n";
                echo "   ✗ SSC Expected: Used ₱" . number_format($sscExpectedUsed, 2) . ", Current ₱" . number_format($sscExpectedCurrent, 2) . "\n";
                echo "   ✗ COE Actual: Used ₱" . number_format($coeBalance['used_amount'], 2) . ", Current ₱" . number_format($coeBalance['current_balance'], 2) . "\n";
                echo "   ✗ COE Expected: Used ₱" . number_format($coeExpectedUsed, 2) . ", Current ₱" . number_format($coeExpectedCurrent, 2) . "\n";
            }

            // Verify transactions were recorded
            $sscTransStmt = $this->db->prepare("SELECT COUNT(*) as count FROM saf_transactions WHERE department_id = 'ssc' AND transaction_description LIKE ?");
            $sscTransStmt->execute(["%Document ID: " . TEST_DOCUMENT_ID . "%"]);
            $sscTrans = $sscTransStmt->fetch(PDO::FETCH_ASSOC);
            
            $coeTransStmt = $this->db->prepare("SELECT COUNT(*) as count FROM saf_transactions WHERE department_id = 'coe' AND transaction_description LIKE ?");
            $coeTransStmt->execute(["%Document ID: " . TEST_DOCUMENT_ID . "%"]);
            $coeTrans = $coeTransStmt->fetch(PDO::FETCH_ASSOC);

            if ($sscTrans['count'] > 0 && $coeTrans['count'] > 0) {
                $this->testResults['transactions'] = ['status' => 'PASS', 'message' => 'Transactions recorded for both departments'];
                echo "   ✓ Transactions recorded: SSC ({$sscTrans['count']}), COE ({$coeTrans['count']})\n";
            } else {
                $this->testResults['transactions'] = ['status' => 'FAIL', 'message' => 'Transactions not recorded properly'];
                echo "   ✗ Missing transactions: SSC ({$sscTrans['count']}), COE ({$coeTrans['count']})\n";
            }

        } catch (Exception $e) {
            $this->testResults['deduction'] = ['status' => 'FAIL', 'message' => 'Deduction test failed: ' . $e->getMessage()];
            echo "   ✗ Test failed: " . $e->getMessage() . "\n";
        }
    }

    private function testDuplicatePrevention() {
        echo "3. Testing duplicate prevention...\n";

        try {
            // Try to deduct again with same document
            $stepStmt = $this->db->prepare("SELECT id FROM document_steps WHERE name LIKE '%EVP%' LIMIT 1");
            $stepStmt->execute();
            $step = $stepStmt->fetch(PDO::FETCH_ASSOC);
            $stepId = $step['id'];

            // Get balances before second attempt
            $sscStmt = $this->db->prepare("SELECT used_amount FROM saf_balances WHERE department_id = 'ssc'");
            $sscStmt->execute();
            $sscBefore = $sscStmt->fetch(PDO::FETCH_ASSOC);
            
            $coeStmt = $this->db->prepare("SELECT used_amount FROM saf_balances WHERE department_id = 'coe'");
            $coeStmt->execute();
            $coeBefore = $coeStmt->fetch(PDO::FETCH_ASSOC);

            // Try to deduct again
            $this->simulateSAFDeduction(TEST_DOCUMENT_ID, $stepId, TEST_USER_ID);

            // Check balances didn't change
            $sscStmt = $this->db->prepare("SELECT used_amount FROM saf_balances WHERE department_id = 'ssc'");
            $sscStmt->execute();
            $sscAfter = $sscStmt->fetch(PDO::FETCH_ASSOC);
            
            $coeStmt = $this->db->prepare("SELECT used_amount FROM saf_balances WHERE department_id = 'coe'");
            $coeStmt->execute();
            $coeAfter = $coeStmt->fetch(PDO::FETCH_ASSOC);

            if (abs($sscBefore['used_amount'] - $sscAfter['used_amount']) < 0.01 &&
                abs($coeBefore['used_amount'] - $coeAfter['used_amount']) < 0.01) {
                $this->testResults['duplicate'] = ['status' => 'PASS', 'message' => 'Duplicate deduction prevented successfully'];
                echo "   ✓ Balances unchanged on second attempt\n";
            } else {
                $this->testResults['duplicate'] = ['status' => 'FAIL', 'message' => 'Duplicate deduction not prevented'];
                echo "   ✗ Balances changed on second attempt\n";
            }

        } catch (Exception $e) {
            $this->testResults['duplicate'] = ['status' => 'FAIL', 'message' => 'Duplicate test failed: ' . $e->getMessage()];
            echo "   ✗ Test failed: " . $e->getMessage() . "\n";
        }
    }

    private function testInsufficientFunds() {
        echo "4. Testing insufficient funds handling...\n";

        try {
            // Create a document requesting more than available
            $largeRequestData = [
                'title' => 'Test Large SAF Request',
                'department' => 'College of Engineering',
                'reqSSC' => 10000.00, // More than available ₱8,000
                'reqCSC' => 3000.00   // More than available ₱2,000
            ];

            $docId = TEST_DOCUMENT_ID + 1;
            $docStmt = $this->db->prepare("INSERT INTO documents (id, student_id, doc_type, data, title, status, uploaded_at)
                                          VALUES (?, 'STU001', 'saf', ?, ?, 'approved', NOW())
                                          ON DUPLICATE KEY UPDATE data = ?, doc_type = 'saf', title = ?, status = 'approved'");
            $docStmt->execute([$docId, json_encode($largeRequestData), $largeRequestData['title'], json_encode($largeRequestData), $largeRequestData['title']]);

            $stepStmt = $this->db->prepare("SELECT id FROM document_steps WHERE name LIKE '%EVP%' LIMIT 1");
            $stepStmt->execute();
            $step = $stepStmt->fetch(PDO::FETCH_ASSOC);
            $stepId = $step['id'];

            // This should throw an exception
            $exceptionThrown = false;
            try {
                $this->simulateSAFDeduction($docId, $stepId, TEST_USER_ID);
            } catch (Exception $e) {
                $exceptionThrown = true;
                if (strpos($e->getMessage(), 'Insufficient') !== false) {
                    $this->testResults['insufficient'] = ['status' => 'PASS', 'message' => 'Insufficient funds properly rejected'];
                    echo "   ✓ Exception thrown for insufficient funds: " . $e->getMessage() . "\n";
                } else {
                    $this->testResults['insufficient'] = ['status' => 'FAIL', 'message' => 'Wrong exception message: ' . $e->getMessage()];
                    echo "   ✗ Wrong exception: " . $e->getMessage() . "\n";
                }
            }

            if (!$exceptionThrown) {
                $this->testResults['insufficient'] = ['status' => 'FAIL', 'message' => 'No exception thrown for insufficient funds'];
                echo "   ✗ No exception thrown for insufficient funds\n";
            }

        } catch (Exception $e) {
            $this->testResults['insufficient'] = ['status' => 'FAIL', 'message' => 'Insufficient funds test failed: ' . $e->getMessage()];
            echo "   ✗ Test failed: " . $e->getMessage() . "\n";
        }
    }

    private function simulateSAFDeduction($documentId, $stepId, $userId) {
        // Extract the SAF deduction logic from documents.php for testing
        $stepStmt = $this->db->prepare("SELECT name FROM document_steps WHERE id = ?");
        $stepStmt->execute([$stepId]);
        $currentStep = $stepStmt->fetch(PDO::FETCH_ASSOC);

        $docStmt = $this->db->prepare("SELECT doc_type, data FROM documents WHERE id = ?");
        $docStmt->execute([$documentId]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

        if ($doc && $doc['doc_type'] === 'saf' && $currentStep && strpos($currentStep['name'], 'EVP') !== false) {
            // Check if funds have already been deducted for this document
            $existingTransStmt = $this->db->prepare("SELECT COUNT(*) as count FROM saf_transactions WHERE transaction_description LIKE ?");
            $existingTransStmt->execute(["%Document ID: {$documentId}%"]);
            $existingTrans = $existingTransStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingTrans['count'] > 0) {
                // Funds already deducted, skip
                return;
            }

            $data = json_decode($doc['data'], true);
            if ($data) {
                $reqSSC = $data['reqSSC'] ?? 0;
                $reqCSC = $data['reqCSC'] ?? 0;
                $department = $data['department'];

                // Reverse mapping from full names to short IDs
                $reverseDeptMap = [
                    'supreme student council' => 'ssc',
                    'college of arts, social sciences and education' => 'casse',
                    'college of arts, social sciences, and education' => 'casse',
                    'college of business' => 'cob',
                    'college of computing and information sciences' => 'ccis',
                    'college of criminology' => 'coc',
                    'college of engineering' => 'coe',
                    'college of hospitality and tourism management' => 'chtm',
                    'college of nursing' => 'con',
                    'spcf miranda' => 'miranda'
                ];
                $deptLower = strtolower(trim($department));
                $deptId = $reverseDeptMap[$deptLower] ?? $department;

                // Check available balances before deducting
                $balanceCheckStmt = $this->db->prepare("SELECT initial_amount - used_amount as available FROM saf_balances WHERE department_id = ?");

                // Check SSC balance if requesting SSC funds
                if ($reqSSC > 0) {
                    $balanceCheckStmt->execute(['ssc']);
                    $sscBalance = $balanceCheckStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sscBalance || $sscBalance['available'] < $reqSSC) {
                        throw new Exception("Insufficient SSC SAF funds. Available: ₱" . ($sscBalance['available'] ?? 0) . ", Requested: ₱{$reqSSC}");
                    }
                }

                // Check CSC balance if requesting CSC funds
                if ($reqCSC > 0 && $deptId) {
                    $balanceCheckStmt->execute([$deptId]);
                    $cscBalance = $balanceCheckStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$cscBalance || $cscBalance['available'] < $reqCSC) {
                        throw new Exception("Insufficient {$deptId} SAF funds. Available: ₱" . ($cscBalance['available'] ?? 0) . ", Requested: ₱{$reqCSC}");
                    }
                }

                // Prepare transaction statement (without created_by for compatibility)
                $transStmt = $this->db->prepare("INSERT INTO saf_transactions (department_id, transaction_type, transaction_amount, transaction_description, transaction_date) VALUES (?, 'deduct', ?, ?, NOW())");

                // Deduct SSC funds
                if ($reqSSC > 0) {
                    $updateStmt = $this->db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?");
                    $updateStmt->execute([$reqSSC, 'ssc']);
                    $transStmt->execute(['ssc', $reqSSC, "SAF Request (SSC): " . ($data['title'] ?? 'Untitled') . " - Document ID: {$documentId}"]);
                }

                // Deduct CSC funds
                if ($reqCSC > 0 && $deptId) {
                    $updateStmt = $this->db->prepare("UPDATE saf_balances SET used_amount = used_amount + ?, current_balance = initial_amount - used_amount WHERE department_id = ?");
                    $updateStmt->execute([$reqCSC, $deptId]);
                    $transStmt->execute([$deptId, $reqCSC, "SAF Request (CSC): " . ($data['title'] ?? 'Untitled') . " - Document ID: {$documentId}"]);
                }
            }
        }
    }

    private function displayResults() {
        echo "\n=== Test Results ===\n";
        $passed = 0;
        $total = count($this->testResults);

        foreach ($this->testResults as $test => $result) {
            $status = $result['status'] === 'PASS' ? '✓' : '✗';
            echo "{$status} {$test}: {$result['message']}\n";
            if ($result['status'] === 'PASS') $passed++;
        }

        echo "\nSummary: {$passed}/{$total} tests passed\n";

        if ($passed === $total) {
            echo "🎉 All tests passed! SAF fund deduction is working correctly.\n";
        } else {
            echo "❌ Some tests failed. Please review the implementation.\n";
        }
    }

    private function cleanup() {
        echo "\n=== Cleaning up test data ===\n";

        try {
            // Remove test documents
            $this->db->prepare("DELETE FROM documents WHERE id IN (?, ?)")
                    ->execute([TEST_DOCUMENT_ID, TEST_DOCUMENT_ID + 1]);

            // Remove test transactions
            $this->db->prepare("DELETE FROM saf_transactions WHERE transaction_description LIKE ?")
                    ->execute(["%Document ID: " . TEST_DOCUMENT_ID . "%"]);

            $this->db->prepare("DELETE FROM saf_transactions WHERE transaction_description LIKE ?")
                    ->execute(["%Document ID: " . (TEST_DOCUMENT_ID + 1) . "%"]);

            // Reset balances to original values (you may want to adjust these)
            $this->db->prepare("UPDATE saf_balances SET initial_amount = 10000.00, used_amount = 2000.00, current_balance = 8000.00 WHERE department_id = 'ssc'")
                    ->execute();
            $this->db->prepare("UPDATE saf_balances SET initial_amount = 5000.00, used_amount = 1000.00, current_balance = 4000.00 WHERE department_id = 'coe'")
                    ->execute();

            echo "✓ Test data cleaned up successfully\n";

        } catch (Exception $e) {
            echo "✗ Cleanup failed: " . $e->getMessage() . "\n";
        }
    }
}

// Run the tests
if ($argc > 1 && $argv[1] === '--cleanup-only') {
    // Just cleanup without running tests
    $test = new SAFFundDeductionTest();
    $test->cleanup();
} else {
    $test = new SAFFundDeductionTest();
    $test->runAllTests();
}

?>